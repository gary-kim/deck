<?php
/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Deck\Activity;


use cogpowered\FineDiff\Diff;
use OCA\Deck\Db\Acl;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\IUserManager;

class DeckProvider implements IProvider {

	/** @var string */
	private $userId;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var ActivityManager */
	private $activityManager;
	/** @var IUserManager */
	private $userManager;

	public function __construct(IURLGenerator $urlGenerator, ActivityManager $activityManager, IUserManager $userManager, $userId) {
		$this->userId = $userId;
		$this->urlGenerator = $urlGenerator;
		$this->activityManager = $activityManager;
		$this->userManager = $userManager;
	}

	/**
	 * @param string $language The language which should be used for translating, e.g. "en"
	 * @param IEvent $event The current event which should be parsed
	 * @param IEvent|null $previousEvent A potential previous event which you can combine with the current one.
	 *                                   To do so, simply use setChildEvent($previousEvent) after setting the
	 *                                   combined subject on the current event.
	 * @return IEvent
	 * @throws \InvalidArgumentException Should be thrown if your provider does not know this event
	 * @since 11.0.0
	 */
	public function parse($language, IEvent $event, IEvent $previousEvent = null) {
		if ($event->getApp() !== 'deck') {
			throw new \InvalidArgumentException();
		}

		$event = $this->getIcon($event);

		$subjectIdentifier = $event->getSubject();
		$subjectParams = $event->getSubjectParameters();
		$ownActivity = ($event->getAuthor() === $this->userId);

		/**
		 * Map stored parameter objects to rich string types
		 */
		$board = null;
		if ($event->getObjectType() === ActivityManager::DECK_OBJECT_BOARD) {
			$board = [
				'type' => 'highlight',
				'id' => $event->getObjectId(),
				'name' => $event->getObjectName(),
				'link' => $this->deckUrl('/board/' . $event->getObjectId()),
			];
		}

		$card = null;
		if ($event->getObjectType() === ActivityManager::DECK_OBJECT_CARD) {
			$card = [
				'type' => 'highlight',
				'id' => $event->getObjectId(),
				'name' => $event->getObjectName(),
			];

			if (array_key_exists('board', $subjectParams)) {
				$archivedParam = $subjectParams['card']['archived'] ? 'archived' : '';
				$card['link'] = $this->deckUrl('/board/' . $subjectParams['board']['id'] . '/' . $archivedParam . '/card/' . $event->getObjectId());
			}
		}

		$author = $event->getAuthor();
		$user = $this->userManager->get($author);
		$params = [
			'board' => $board,
			'card' => $card,
			'user' => [
				'type' => 'user',
				'id' => $author,
				'name' => $user !== null ? $user->getDisplayName() : $author
			]
		];

		$params = $this->parseParamForBoard('board', $subjectParams, $params);
		$params = $this->parseParamForStack('stack', $subjectParams, $params);
		$params = $this->parseParamForStack('stackBefore', $subjectParams, $params);
		$params = $this->parseParamForAttachment('attachment', $subjectParams, $params);
		$params = $this->parseParamForLabel($subjectParams, $params);
		$params = $this->parseParamForAssignedUser($subjectParams, $params);
		$params = $this->parseParamForAcl($subjectParams, $params);
		$params = $this->parseParamForChanges($subjectParams, $params, $event);

		try {
			$subject = $this->activityManager->getActivityFormat($subjectIdentifier, $subjectParams, $ownActivity);
			$event->setParsedSubject($subject);
			$event->setRichSubject($subject, $params);
		} catch (\Exception $e) {
		}
		return $event;
	}

	private function getIcon(IEvent $event) {
		$event->setIcon($this->urlGenerator->imagePath('deck', 'deck-dark.svg'));
		if (strpos($event->getSubject(), '_update') !== false) {
			$event->setIcon($this->urlGenerator->imagePath('files', 'change.svg'));
		}
		if (strpos($event->getSubject(), '_create') !== false) {
			$event->setIcon($this->urlGenerator->imagePath('files', 'add-color.svg'));
		}
		if (strpos($event->getSubject(), '_delete') !== false) {
			$event->setIcon($this->urlGenerator->imagePath('files', 'delete-color.svg'));
		}
		if (strpos($event->getSubject(), 'archive') !== false) {
			$event->setIcon($this->urlGenerator->imagePath('deck', 'archive.svg'));
		}
		if (strpos($event->getSubject(), '_restore') !== false) {
			$event->setIcon($this->urlGenerator->imagePath('core', 'actions/history.svg'));
		}
		if (strpos($event->getSubject(), 'attachment_') !== false) {
			$event->setIcon($this->urlGenerator->imagePath('core', 'places/files.svg'));
		}
		return $event;
	}

	private function parseParamForBoard($paramName, $subjectParams, $params) {
		if (array_key_exists($paramName, $subjectParams)) {
			$params[$paramName] = [
				'type' => 'highlight',
				'id' => $subjectParams[$paramName]['id'],
				'name' => $subjectParams[$paramName]['title'],
				'link' => $this->deckUrl('/board/' . $subjectParams[$paramName]['id'] . '/'),
			];
		}
		return $params;
	}
	private function parseParamForStack($paramName, $subjectParams, $params) {
		if (array_key_exists($paramName, $subjectParams)) {
			$params[$paramName] = [
				'type' => 'highlight',
				'id' => $subjectParams[$paramName]['id'],
				'name' => $subjectParams[$paramName]['title'],
			];
		}
		return $params;
	}

	private function parseParamForAttachment($paramName, $subjectParams, $params) {
		if (array_key_exists($paramName, $subjectParams)) {
			$params[$paramName] = [
				'type' => 'highlight',
				'id' => $subjectParams[$paramName]['id'],
				'name' => $subjectParams[$paramName]['data'],
				'link' => $this->urlGenerator->linkToRoute('deck.attachment.display', ['cardId' => $subjectParams['card']['id'], 'attachmentId' => $subjectParams['attachment']['id']]),
			];
		}
		return $params;
	}

	private function parseParamForAssignedUser($subjectParams, $params) {
		if (array_key_exists('assigneduser', $subjectParams)) {
			$user = $this->userManager->get($subjectParams['assigneduser']);
			$params['assigneduser'] = [
				'type' => 'user',
				'id' => $subjectParams['assigneduser'],
				'name' => $user !== null ? $user->getDisplayName() : $subjectParams['assigneduser']
			];
		}
		return $params;
	}

	private function parseParamForLabel($subjectParams, $params) {
		if (array_key_exists('label', $subjectParams)) {
			$params['label'] = [
				'type' => 'highlight',
				'id' => $subjectParams['label']['id'],
				'name' => $subjectParams['label']['title']
			];
		}
		return $params;
	}

	private function parseParamForAcl($subjectParams, $params) {
		if (array_key_exists('acl', $subjectParams)) {
			if ($subjectParams['acl']['type'] === Acl::PERMISSION_TYPE_USER) {
				$user = $this->userManager->get($subjectParams['acl']['participant']);
				$params['acl'] = [
					'type' => 'user',
					'id' => $subjectParams['acl']['participant'],
					'name' => $user !== null ? $user->getDisplayName() : $subjectParams['acl']['participant']
				];
			} else {
				$params['acl'] = [
					'type' => 'highlight',
					'id' => $subjectParams['acl']['participant'],
					'name' => $subjectParams['acl']['participant']
				];
			}
		}
		return $params;
	}

	/**
	 * Add diff to message if the subject parameter 'diff' is set, otherwise
	 * the changed values are added to before/after
	 *
	 * @param $subjectParams
	 * @param $params
	 * @return mixed
	 */
	private function parseParamForChanges($subjectParams, $params, $event) {
		if (array_key_exists('diff', $subjectParams) && $subjectParams['diff']) {
			$diff = new Diff();
			$event->setMessage($subjectParams['after']);
			$event->setParsedMessage('<pre class="visualdiff">' . $diff->render($subjectParams['before'], $subjectParams['after']) . '</pre>');
			return $params;
		}
		if (array_key_exists('before', $subjectParams)) {
			$params['before'] = [
				'type' => 'highlight',
				'id' => $subjectParams['before'],
				'name' => $subjectParams['before']
			];
		}
		if (array_key_exists('after', $subjectParams)) {
			$params['after'] = [
				'type' => 'highlight',
				'id' => $subjectParams['after'],
				'name' => $subjectParams['after']
			];
		}
		return $params;
	}

	public function deckUrl($endpoint) {
		return $this->urlGenerator->linkToRoute('deck.page.index') . '#!' . $endpoint;
	}
}