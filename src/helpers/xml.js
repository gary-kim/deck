const xmlToJson = (xml) => {
	let obj = {}
	if (xml.nodeType === 1) {
		if (xml.attributes.length > 0) {
			obj['@attributes'] = {}
			for (let j = 0; j < xml.attributes.length; j++) {
				const attribute = xml.attributes.item(j)
				obj['@attributes'][attribute.nodeName] = attribute.nodeValue
			}
		}
	} else if (xml.nodeType === 3) {
		obj = xml.nodeValue
	}
	if (xml.hasChildNodes()) {
		for (let i = 0; i < xml.childNodes.length; i++) {
			const item = xml.childNodes.item(i)
			const nodeName = item.nodeName
			if (typeof (obj[nodeName]) === 'undefined') {
				obj[nodeName] = xmlToJson(item)
			} else {
				if (typeof obj[nodeName].push === 'undefined') {
					var old = obj[nodeName]
					obj[nodeName] = []
					obj[nodeName].push(old)
				}
				obj[nodeName].push(xmlToJson(item))
			}
		}
	}
	return obj
}
const parseXml = (xml) => {
	let dom = null
	try {
		dom = (new DOMParser()).parseFromString(xml, 'text/xml')
	} catch (e) {
		console.error('Failed to parse xml document', e)
	}
	return dom
}
const xmlToTagList = (xml) => {
	let json = xmlToJson(parseXml(xml))
	let list = json['d:multistatus']['d:response']
	let result = []
	for (let index in list) {
		let tag = list[index]['d:propstat']
		if (tag['d:status']['#text'] !== 'HTTP/1.1 200 OK') {
			continue
		}
		result.push({
			cardId: tag['d:prop']['oc:objectId']['#text'],
			id: tag['d:prop']['oc:id']['#text'],
			uId: tag['d:prop']['oc:actorId']['#text'],
			creationDateTime: tag['d:prop']['oc:creationDateTime']['#text'],
			message: tag['d:prop']['oc:message']['#text']
		})
	}
	return result
}

export default xmlToTagList
