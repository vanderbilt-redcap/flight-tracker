function stripFromHTML(str, html) {
	html = stripHTML(html);
	const lines = html.split(/\n/);
	const regex = new RegExp(str+':\\s+(.+)$', 'i');
	for (let i=0; i < lines.length; i++) {
		const line = lines[i];
		const matches = line.match(regex);
		if (matches && matches[1]) {
			return matches[1];
		}
	}
	return "";
}

function togglePubMedName(nameSelector, ob, checkedImg, uncheckedImg) {
	const isOn = $(ob).hasClass('clickableOn');
	const oldClickableClass = isOn ? 'clickableOn' : 'clickableOff';
	const newClickableClass = isOn ? 'clickableOff' : 'clickableOn';
	$(ob).removeClass(oldClickableClass).addClass(newClickableClass);
	$(nameSelector).each((idx, ob) => {
		const imgOb = $(ob).find('img');
		const hiddenOb = $(ob).find('input[type=hidden]');
		if (imgOb.attr('src').match(/unchecked/)) {
			hiddenOb.val('include');
			imgOb.attr('src', checkedImg);
		} else if (!imgOb.attr('src').match(/readonly/)) {
			hiddenOb.val('exclude');
			imgOb.attr('src', uncheckedImg);
		}
	})
}

function downloadCanvas(canvas, filename) {
	if (filename.match(/\.png$/i)) {
		const imageData = canvas2PNG(canvas);
		forceDownloadUrl(imageData, filename);
	} else if (filename.match(/\.jpg$/) || filename.match(/\.jpeg/)) {
		const imageData = canvas2JPEG(canvas);
		forceDownloadUrl(imageData, filename);
	} else {
		console.error("Invalid file type");
	}
}

function turnOffStatusCron() {
	$.post(getPageUrl("testConnectivity.php"), { 'redcap_csrf_token': getCSRFToken(), turn_off: 1 }, function(html) {
		console.log("Turned off "+html);
		$("#status").html("Off");
		$("#status_link").html("Turn on status cron");
		$("#status_link").attr("onclick", "turnOnStatusCron();");
	});
}

function turnOnStatusCron() {
	$.post(getPageUrl("testConnectivity.php"), { 'redcap_csrf_token': getCSRFToken(), turn_on: 1 }, function(html) {
		console.log("Turned on "+html);
		$("#status").html("On");
		$("#status_link").html("Turn off status cron");
		$("#status_link").attr("onclick", "turnOffStatusCron();");
	});
}

function trimPeriods(str) {
	return str.replace(/\.$/, "");
}

function submitPMC(pmc, textId, prefixHTML) {
	submitPMCs([pmc], textId, prefixHTML);
}

function resetCitationList(textId) {
	if (isContainer(textId)) {
		$(textId).html('');
	} else {
		$(textId).val('');
	}
	updateButtons(textId);
}

function submitPMCs(pmcs, textId, prefixHTML) {
	if (!Array.isArray(pmcs)) {
		pmcs = pmcs.split(/\n/);
	}
	pmcs = clearOutBlanks(pmcs);
	if (pmcs && Array.isArray(pmcs)) {
		resetCitationList(textId);
		if (pmcs.length > 0) {
			presentScreen("Downloading...");
			downloadOnePMC(0, pmcs, textId, prefixHTML);
		}
	}
}

function downloadOnePMC(i, pmcs, textId, prefixHTML) {
	let pmc = pmcs[i];
	if (pmc) {
		if (!pmc.match(/PMC/)) {
			pmc = 'PMC' + pmc;
		}
		const url = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pmc&retmode=xml&id=' + pmc;
		$.ajax({
			url: url,
			success: function (xml) {
				let pmid = '';
				let myPmc = '';
				const articleLocation = 'pmc-articleset>article>front>';
				const articleMetaLocation = articleLocation + 'article-meta>';
				$(xml).find(articleMetaLocation + 'article-id').each(function () {
					if ($(this).attr('pub-id-type') === 'pmid') {
						pmid = 'PubMed PMID: ' + $(this).text() + '. ';
					} else if ($(this).attr('pub-id-type') === 'pmc') {
						myPmc = 'PMC' + $(this).text() + '.';
					}
				});
				let journal = '';
				$(xml).find(articleLocation + 'journal-meta>journal-id').each(function () {
					if ($(this).attr('journal-id-type') === 'iso-abbrev') {
						journal = $(this).text();
					}
				});
				journal = journal.replace(/\.$/, '');

				let year = '';
				let month = '';
				let day = '';
				$(xml).find(articleMetaLocation + 'pub-date').each(function () {
					const pubType = $(this).attr('pub-type');
					if ((pubType === 'collection') || (pubType === 'ppub')) {
						if ($(this).find('month')) {
							month = $(this).find('month').text();
						}
						if ($(this).find('year')) {
							year = $(this).find('year').text();
						}
						if ($(this).find('day')) {
							day = ' ' + $(this).find('day').text();
						}
					}
				});
				const volume = $(xml).find(articleMetaLocation + 'volume').text();
				const issue = $(xml).find(articleMetaLocation + 'issue').text();

				const fpage = $(xml).find(articleMetaLocation + 'fpage').text();
				const lpage = $(xml).find(articleMetaLocation + 'lpage').text();
				let pages = '';
				if (fpage && lpage) {
					pages = fpage + '-' + lpage;
				}

				let title = $(xml).find(articleMetaLocation + 'title-group>article-title').text();
				title = title.replace(/\.$/, '');

				const namePrefix = 'name>';
				const names = [];
				$(xml).find(articleMetaLocation + 'contrib-group>contrib').each(function (index, elem) {
					if ($(elem).attr('contrib-type') === 'author') {
						const surname = $(elem).find(namePrefix + 'surname').text();
						const givenNames = $(elem).find(namePrefix + 'given-names').text();
						names.push(surname + ' ' + givenNames);
					}
				});

				if (title && (names.length > 0)) {
					const loc = getLocation(volume, issue, pages);
					const citation = names.join(',') + '. ' + title + '. ' + journal + '. ' + year + ' ' + month + day + ';' + loc + '. ' + pmid + myPmc;
					updateCitationList(textId, prefixHTML, citation);
				}
				const nextI = i + 1;
				if (nextI < pmcs.length) {
					setTimeout(function () {
						downloadOnePMC(nextI, pmcs, textId, prefixHTML);
					}, 1000);    // rate limiter
				} else {
					clearScreen();
				}
			},
			error: function (e) {
				updateCitationList(textId, prefixHTML, 'ERROR: ' + JSON.stringify(e));
				const nextI = i + 1;
				if (nextI < pmids.length) {
					setTimeout(function () {
						downloadOnePMC(nextI, pmcs, textId, prefixHTML);
					}, 1000);    // rate limiter
				} else {
					clearScreen();
				}
			}
		});
	} else {
		const nextI = i + 1;
		if (nextI < pmcs.length) {
			setTimeout(function () {
				downloadOnePMC(nextI, pmcs, textId, prefixHTML);
			}, 1000);    // rate limiter
		} else {
			clearScreen();
		}
	}
}

function updateCitationList(textId, prefixHTML, text) {
	var citations = getPreviousCitations(textId, prefixHTML);
	citations.push(prefixHTML+text);
	if (isContainer(textId)) {
		$(textId).html(citations.join('<br>\n'));
	} else {
		$(textId).val(citations.join('\n'));
	}
	updateButtons(textId);
}

function updateButtons(textId) {
	if ($(textId).val()) {
		$('.list button.includeButton').show();
		$('.oneAtATime button.includeButton').show();
	} else {
		$('.list button.includeButton').hide();
		$('.oneAtATime button.includeButton').hide();
	}
}

function getLocation(volume, issue, pages) {
	var loc = volume;
	if (issue) {
		loc += '('+issue+')';
	}
	if (pages) {
		loc += ':'+pages;
	}
	return loc;
}

function submitPMID(pmid, textId, prefixHTML, cb) {
	submitPMIDs([pmid], textId, prefixHTML, cb);
}

function downloadOnePMID(i, pmids, textId, prefixHTML, doneCb) {
	const pmid = pmids[i];
	if (pmid) {
		const url = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id='+pmid;
		// AJAX call will return in uncertain order => append, not overwrite, results
		console.log('Calling '+url);
		$.ajax({
			url: url,
			success: function(xml) {
				// similar to publications/getPubMedByName.php
				// make all changes in two places in two languages!!!

				const citationLocation = 'PubmedArticleSet>PubmedArticle>MedlineCitation>';
				const articleLocation = citationLocation + 'Article>';
				const journalLocation = articleLocation + 'Journal>JournalIssue>';

				const myPmid = $(xml).find(citationLocation+'PMID').text();
				const year = $(xml).find(journalLocation+'PubDate>Year').text();
				const month = $(xml).find(journalLocation+'PubDate>Month').text();
				const volume = $(xml).find(journalLocation+'Volume').text();
				const issue = $(xml).find(journalLocation+'Issue').text();
				const pages = $(xml).find(articleLocation+'Pagination>MedlinePgn').text();
				let title = $(xml).find(articleLocation+'ArticleTitle').text();
				title = title.replace(/\.$/, '');

				let journal = trimPeriods($(xml).find(articleLocation + 'Journal>ISOAbbreviation').text());
				journal = journal.replace(/\.$/, '');

				const dayNode = $(xml).find(journalLocation+'PubDate>Day');
				let day = '';
				if (dayNode) {
					day = ' '+dayNode.text();
				}

				const names = [];
				$(xml).find(articleLocation+'AuthorList>Author').each(function(index, elem) {
					const lastName = $(elem).find('LastName');
					const initials = $(elem).find('Initials');
					const collective = $(elem).find('CollectiveName');
					if (lastName && initials) {
						names.push(lastName.text()+' '+initials.text());
					} else if (collective) {
						names.push(collective.text());
					}
				});

				if ((names.length > 0) && title) {
					const loc = getLocation(volume, issue, pages);
					const citation = names.join(',')+'. '+title+'. '+journal+'. '+year+' '+month+day+';'+loc+'. PubMed PMID: '+myPmid;
					console.log('citation: '+citation);

					updateCitationList(textId, prefixHTML, citation);
				}
				let nextI = i + 1;
				if (nextI < pmids.length) {
					setTimeout(function() {
						downloadOnePMID(nextI, pmids, textId, prefixHTML, doneCb);
					}, 1000);    // rate limiter
				} else if (nextI === pmids.length) {
					clearScreen();
					doneCb();
				}
			},
			error: function(e) {
				updateCitationList(textId, prefixHTML, 'ERROR: '+JSON.stringify(e));
				const nextI = i + 1;
				if (nextI < pmids.length) {
					setTimeout(function () {
						downloadOnePMID(nextI, pmids, textId, prefixHTML, doneCb);
					}, 1000);    // rate limiter
				} else {
					clearScreen();
				}
			}
		});
	} else {
		const nextI = i + 1;
		if (nextI < pmids.length) {
			setTimeout(function () {
				downloadOnePMID(nextI, pmids, textId, prefixHTML, doneCb);
			}, 1000);    // rate limiter
		} else {
			clearScreen();
		}
	}
}

function clearOutBlanks(ary) {
	var ary2 = [];
	for (var i = 0; i < ary.length; i++) {
		if (ary[i]) {
			ary2.push(ary[i]);
		}
	}
	return ary2;
}

// cb = callback
function submitPMIDs(pmids, textId, prefixHTML, cb) {
	if (!Array.isArray(pmids)) {
		pmids = pmids.split(/\n/);
	}
	if (!prefixHTML) {
		prefixHTML = '';
	}
	if (!cb) {
		cb = function() { };
	}
	pmids = clearOutBlanks(pmids);
	if (pmids && (Array.isArray(pmids))) {
		resetCitationList(textId);
		if (pmids.length > 0) {
			presentScreen("Downloading...");
			downloadOnePMID(0, pmids, textId, prefixHTML, cb);
		}
	}
}

function getPreviousCitations(textId, prefixHTML) {
	var citations = [];
	if (isContainer(textId)) {
		citations = $(textId).html().split(/<br>\n/);
		if ((citations.length === 0) && (prefixHTML !== "")) {
			citations.push(prefixHTML);
		}
	} else {
		citations = $(textId).val().split(/\n/);
	}

	var filteredCitations = [];
	for (var i = 0; i < citations.length; i++) {
		if (citations[i]) {
			filteredCitations.push(citations[i]);
		}
	}
	return filteredCitations;
}

function isContainer(id) {
	var containers = ["p", "div"];
	var idTag = $(id).prop("tagName");
	if (containers.indexOf(idTag.toLowerCase()) >= 0) {
		return true;
	} else {
		return false;
	}
}

// returns PHP timestamp: number of seconds (not milliseconds)
function getPubTimestamp(citation) {
	if (!citation) {
		return 0;
	}
	var nodes = citation.split(/[\.\?]\s+/);
	var date = "";
	var i = 0;
	var issue = "";
	while (!date && i < nodes.length) {
		if (nodes[i].match(/;/) && nodes[i].match(/\d\d\d\d.*;/)) {
			var a = nodes[i].split(/;/);
			date = a[0];
			issue = a[1];
		}       
		i++;   
	}       
	if (date) {
		var dateNodes = date.split(/\s+/);

		var year = dateNodes[0];
		var month = "";
		var day = "";

		var months = { "Jan": "01", "Feb": "02", "Mar": "03", "Apr": "04", "May": "05", "Jun": "06", "Jul": "07", "Aug": "08", "Sep": "09", "Oct": "10", "Nov": "11", "Dec": "12" };

		if (dateNodes.length == 1) {
			month = "01";
		} else if (!isNaN(dateNodes[1])) {
			month = dateNodes[1];
			if (month < 10) {
				month = "0"+parseInt(month);
			}       
		} else if (months[dateNodes[1]]) {
			month = months[dateNodes[1]];
		} else {
			month = "01";
		}       
		
		if (dateNodes.length <= 2) {
			day = "01";
		} else {
			day = dateNodes[2];
			if (day < 10) {
				day = "0"+parseInt(day);
			}       
		}       
		var datum = new Date(Date.UTC(year,month,day,'00','00','00'));
		return datum.getTime()/1000;
	} else {
		return 0;
	}
}

function stripOptions(html) {
	return html.replace(/<option[^>]*>[^<]*<\/option>/g, "");
}

function stripBolds(html) {
	return html.replace(/<b>.+<\/b>/g, "");
}

function stripButtons(html) {
	return html.replace(/<button.+<\/button>/g, "");
}

function refreshHeader() {
	var numCits = $("#center div.notDone").length;
	if (numCits == 1) {
		$(".newHeader").html(numCits + " New Citation");
	} else if (numCits === 0) {
		$(".newHeader").html("No New Citations");
	} else {
		$(".newHeader").html(numCits + " New Citations");
	}
}

function sortCitations(html) {
	var origCitations = html.split(/\n/);
	var timestamps = [];
	for (var i = 0; i < origCitations.length; i++) {
		timestamps[i] = getPubTimestamp(stripHTML(stripBolds(stripButtons(origCitations[i]))));
	}
	for (var i = 0; i < origCitations.length; i++) {
		var citationI = origCitations[i];
		var tsI = timestamps[i];
		for (j = i; j < origCitations.length; j++) {
			var citationJ = origCitations[j];
			var tsJ = timestamps[j];
			if (tsI < tsJ) {
				// switch
				origCitations[j] = citationI;
				origCitations[i] = citationJ;

				citationI = citationJ;
				tsI = tsJ;
				// Js will be reassigned with the next iteration of the j loop
			}
		}
	}
	return origCitations.join("\n")+"\n";
}

function stripHTML(str) {
	return str.replace(/<[^>]+>/g, "");
}

function removeThisElem(elem) {
	$(elem).remove();
	refreshHeader();
}

function omitCitation(citation) {
	var html = $("#omitCitations").html();
	var citationHTML = "<div class='finalCitation'>"+citation+"</div>";
	html += citationHTML;

	$("#omitCitations").html(sortCitations(html));
}

function getPMID(citation) {
	var matches = citation.match(/PubMed PMID: \d+/);
	if (matches && (matches.length >= 1)) {
		var pmidStr = matches[0];
		var pmid = pmidStr.replace(/PubMed PMID: /, "");
		return pmid;
	}
	return "";
}

function getMaxID() {
	return $("#newCitations .notDone").length;
}

function addCitationLink(citation) {
	return citation.replace(/PubMed PMID: (\d+)/, "<a href='https://www.ncbi.nlm.nih.gov/pubmed/?term=$1'>PubMed PMID: $1</a>");
}

function getUrlVars() {
	const vars = {};
	const loc = window.location.href.replace(/#.+$/, '');
	loc.replace(/[?&]+([^=&]+)=([^&]*)/gi, function(m,key,value) {
		vars[key] = value;
	});
	return vars;
}

function refresh() {
	location.reload();
}

// page is blank if current page is requested
function getPageUrl(page) {
	page = page.replace(/^\//, "");
	const params = getUrlVars();
	if (params['page']) {
		let url = "?pid="+params['pid'];
		if (page) {
			page = page.replace(/\.php$/, "");
			url += "&page="+encodeURIComponent(page);
		} else if (params['page']) {
			url += "&page="+encodeURIComponent(params['page']);
		}
		if (params['prefix']) {
			url += "&prefix="+encodeURIComponent(params['prefix']);
		}
		return url;
	}
	return page;
}

function getHeaders() {
	var params = getUrlVars();
	if (typeof params['headers'] != "undefined") {
		return "&headers="+params['headers'];
	}
	return "";
}

function getPid() {
	var params = getUrlVars();
	if (typeof params['pid'] != "undefined") {
		return params['pid'];
	}
	return "";
}

function makeNote(note) {
	if (typeof note != "undefined") {
		$("#note").html(note);
		if ($("#note").hasClass("green")) {
			$("#note").removeClass("green");
		}
		if (!$("#note").hasClass("red")) {
			$("#note").addClass("red");
		}
	} else {
		if ($("#note").hasClass("red")) {
			$("#note").removeClass("red");
		}
		if (!$("#note").hasClass("green")) {
			$("#note").addClass("green");
		}
		$("#note").html("Save complete! Please <a href='javascript:;' onclick='refresh();'>refresh</a> to see the latest list after you have completed your additions.");
	}
	$("#note").show();
}

// coordinated with Citation::getID in class/Publications.php
function getID(citation) {
	var matches;
	if (matches = citation.match(/PMID: \d+/)) {
		var pmidStr = matches[0];
		return pmidStr.replace(/^PMID: /, "");
	} else {
		return citation;
	}
}

function isCitation(id) {
	if (id) {
		if (id.match(/^PMID/)) {
			return true;
		}
		if (id.match(/^ID/)) {
			return true;
		}
	}
	return false;
}

function isOriginal(id) {
	if (id) {
		if (id.match(/^ORIG/)) {
			return true;
		}
	}
	return false;
}

function getRecord() {
	var params = getUrlVars();
	var recordId = params['record'];
	if (typeof recordId == "undefined") {
		return 1;
	}
	return recordId;
}

function submitChanges(nextRecord) {
	const recordId = getRecord();
	const newFinalized = [];
	const newOmits = [];
	const resets = [];
	$('#finalize').hide();
	$('#uploading').show();
	const params = getUrlVars();
	const type = params['wranglerType'];
	$('[type=hidden]').each(function(idx, elem) {
		const elemId = $(elem).attr('id');
		const value = $(elem).val();
		let id = "";
		if ((typeof elemId != 'undefined') && elemId.match(/^PMID/)) {
			id = elemId.replace(/^PMID/, '');
		} else if ((typeof elemId != 'undefined') && elemId.match(/^E[DJ]/)) {
			id = elemId;
		} else	if ((typeof elemId != 'undefined') && elemId.match(/^USPO/)) {
			id = elemId.replace(/^USPO/, '');
		}

		if (id && (value === 'include')) {
			// checked => put in finalized
			newFinalized.push(id);
		} else if (id && (value === 'exclude')) {
			// unchecked => put in omits
			newOmits.push(id);
		} else if (id && (value === 'reset')) {
			resets.push(id);
		}
	});

	let url = '';
	if (type === 'Patents') {
		url = getPageUrl('wrangler/savePatents.php');
	} else if ((type === 'Publications') || (type === 'FlagPublications')) {
		url = getPageUrl('wrangler/savePubs.php');
	}
	if (url) {
		const postdata = {
			record_id: recordId,
			wranglerType: type,
			omissions: JSON.stringify(newOmits),
			resets: JSON.stringify(resets),
			finalized: JSON.stringify(newFinalized),
			redcap_csrf_token: getCSRFToken()
		};
		console.log('Posting to '+url+' '+JSON.stringify(postdata));
		presentScreen('Saving...');
		$.ajax({
			url: url,
			method: 'POST',
			data: postdata,
			dataType: 'json',
			success: function(data) {
				clearScreen();
				processWranglingResult(data, nextRecord);
			},
			error: function(e) {
				clearScreen();
				if (!e.status || (e.status !== 200)) {
					$('#uploading').hide();
					$('#finalize').show();
					$.sweetModal({
						content: 'ERROR: '+JSON.stringify(e),
						icon: $.sweetModal.ICON_ERROR
					});
				} else if (e.responseText && (e.status === 200)) {
					const json = e.responseText;
					console.log(json);
					try {
						const data = JSON.parse(json);
						processWranglingResult(data, nextRecord);
					} catch(exception) {
						console.error(exception);
						$.sweetModal({
							content: exception,
							icon: $.sweetModal.ICON_ERROR
						});
					}
				} else {
					console.log(JSON.stringify(e));
				}
			}
		});
	}
	console.log('Done');
}

function processWranglingResult(data, nextRecord) {
	const params = getUrlVars();
	let wranglerType = '';
	if (params['wranglerType']) {
		wranglerType = '&wranglerType='+params['wranglerType'];
	}
	if (data['count'] && (data['count'] > 0)) {
		const mssg = data['count']+' upload';
		window.location.href = getPageUrl('wrangler/include.php')+getHeaders()+'&mssg='+encodeURI(mssg)+'&record='+nextRecord+wranglerType;
	} else if (data['item_count'] && (data['item_count'] > 0)) {
		const mssg = data['item_count']+' upload';
		window.location.href = getPageUrl('wrangler/include.php')+getHeaders()+'&mssg='+encodeURI(mssg)+'&record='+nextRecord+wranglerType;
	} else if (data['error']) {
		$('#uploading').hide();
		$('#finalize').show();
		$.sweetModal({
			content: 'ERROR: '+data['error'],
			icon: $.sweetModal.ICON_ERROR
		});
	} else {
		$('#uploading').hide();
		$('#finalize').show();
		$.sweetModal({
			content: 'Unexplained return value. '+JSON.stringify(data),
			icon: $.sweetModal.ICON_ERROR
		});
		console.log('Unexplained return value. '+JSON.stringify(data));
	}
}

function makeWranglingMessage(cnt) {
	let str = "items";
	if (cnt === 1) {
		str = "item";
	}
	return cnt+" "+str+" uploaded";
}

function checkSticky() {
	var normalOffset = $('#normalHeader').offset();
	if (window.pageYOffset > normalOffset.top) {
		if (!$('#stickyHeader').is(':visible')) {
			$('#stickyHeader').show();
			$('#stickyHeader').width($('maintable').width()+"px");
			$('#stickyHeader').css({ "left": $('#maintable').offset().left+"px" });
		}
	} else {
		if ($('#stickyHeader').is(':visible')) {
			$('#stickyHeader').hide();
		}
	}
}

function submitOrder(selector, resultsSelector) {
	if ($(resultsSelector).hasClass("green")) {
		$(resultsSelector).removeClass("green");
	}
	if ($(resultsSelector).hasClass("red")) {
		$(resultsSelector).removeClass("red");
	}
	$(resultsSelector).addClass("yellow");
	$(resultsSelector).html("Processing...");
	$(resultsSelector).show();

	var keys = new Array();
	$(selector+" li").each(function(idx, ob) {
		var id = $(ob).attr("id");
		keys.push(id);
	});
	if (keys.length > 0) {
		$.post(getPageUrl("lexicallyReorder.php"), { 'redcap_csrf_token': getCSRFToken(), keys: JSON.stringify(keys) }, function(data) {
			console.log("Done");
			console.log(data);
			$(resultsSelector).html(data);
			if ($(resultsSelector).hasClass("yellow")) {
				$(resultsSelector).removeClass("yellow");
			}
			if (data.match(/ERROR/)) {
				$(resultsSelector).addClass("red");
			} else {
				$(resultsSelector).addClass("green");
			}
			setTimeout(function() {
				$(resultsSelector).fadeOut();
			}, 5000);
		});
	}
}

function presentScreen(mssg, imageUrl) {
	if ($('#overlayFT').length == 0) {
		$('body').prepend('<div id="overlayFT"></div>');
	}
	if ($('#overlayFT').length > 0) {
		if (!imageUrl) {
			imageUrl = getLoadingImageUrl();
		}
		$('#overlayFT').html('<br><br><br><br><h1 class=\"warning\">'+mssg+'</h1><p class=\"centered\"><img src=\"'+imageUrl+'\" alt=\"Waiting\"></p>');
		$('#overlayFT').show();
	}
}

function getSmallLoadingMessage(mssg) {
	if (!mssg) {
		mssg = 'Loading...';
	}
	const imageUrl = getLoadingImageUrl();
	return '<p class=\"centered\"><strong>'+mssg+'</strong><br/><img src=\"'+imageUrl+'\" alt=\"Loading\" style=\"width: 64px; height: 64px;\" /></p>';
}

function clearScreen() {
	if ($('#overlayFT').length > 0) {
		$('#overlayFT').html('');
		$('#overlayFT').hide();
	}
}

function toggleHelp(helpUrl, helpHiderUrl, currPage) {
	if ($('#help').is(':visible')) {
		hideHelp(helpHiderUrl);
	} else {
		showHelp(helpUrl, currPage);
	}
}

function showHelp(helpUrl, currPage) {
	const params = { 'redcap_csrf_token': getCSRFToken(), fullPage: currPage };
	if (currPage === "wrangler/include.php") {
		const urlVars = getUrlVars();
		params['wranglerType'] = urlVars['wranglerType'];
	}
	$.post(helpUrl, params, function(html) {
		// coordinate with .subnav
		if ($('.subnav').length === 1) {
			const right = $('.subnav').position().left + $('.subnav').width();
			const offset = 10;
			const helpLeft = right + offset;
			const rightOffset = helpLeft + 40;
			const helpWidth = "calc(100% - "+rightOffset+"px)";
			$('#help').css({ left: helpLeft+"px", position: "relative", width: helpWidth });
		}
		if (html) {
			$('#help').html(html)
				.slideDown();
		} else if (!isREDCapPage()) {
			$('#help').html("<h4 class='nomargin'>No Help Resources are Available for This Page</h4>")
				.slideDown();
		}
	});
}

function hideHelp(helpHiderUrl) {
	$('#help').hide();
	$.post(helpHiderUrl, { 'redcap_csrf_token': getCSRFToken() }, function() {
	});
}

function startTonight() {
	var url = getPageUrl("downloadTonight.php");
	console.log(url);
	$.ajax({
		data: { 'redcap_csrf_token': getCSRFToken() },
		type: 'POST',
		url:url,
		success: function(data) {
			console.log("result: "+data);
			$.sweetModal({
				content: 'Downloads will start tonight.',
				icon: $.sweetModal.ICON_SUCCESS
			});
		},
		error: function(e) {
			console.log("ERROR! "+JSON.stringify(e));
		}
	});
}

// Should only be called by REDCap SuperUser or else will throw errors
// if a pid does not have FlightTracker enabled, it will also cause an error
function installMetadataForProjects(pids) {
	presentScreen("Updating Data Dictionaries...<br>(may take some time)")
	const url = getPageUrl("metadata.php");
	$.post(url, { 'redcap_csrf_token': getCSRFToken(), process: "install_all", pids: pids }, function(json) {
		console.log(json);
		if (json.charAt(0) === '<') {
			$.sweetModal({
				content: 'The process did not complete because REDCap requested a login.',
				icon: $.sweetModal.ICON_ERROR
			});

			clearScreen();
		} else {
			try {
				const data = JSON.parse(json);
				const numProjects = Object.keys(data).length;
				const exceptions = [];
				for (const pid in data) {
					if (typeof data[pid]['Exception'] !== "undefined") {
						exceptions.push("Project "+pid+": "+data[pid]['Exception']);
					}
				}
				if (exceptions.length === 0) {
					$.sweetModal({
						content: numProjects+' projects were successfully updated.',
						icon: $.sweetModal.ICON_SUCCESS
					});
					$("#metadataWarning").addClass("install-metadata-box-success");
					$("#metadataWarning").html("<i class='fa fa-check' aria-hidden='true'></i> "+getInstallationCompleteMessage());
				} else {
					$.sweetModal({
						content: exceptions.length+' error(s) occurred:<br/>'+exceptions.join('<br/>'),
						icon: $.sweetModal.ICON_ERROR
					});
					$("#metadataWarning").addClass("install-metadata-box-danger");
					$("#metadataWarning").html("<i class='fa fa-xmark' aria-hidden='true'></i> Installation Errors Occurred");
				}
				setTimeout(function() {
					$("#metadataWarning").fadeOut(500);
				}, 3000);
				clearScreen();
			} catch(exception) {
				console.error(exception);
				clearScreen();
				$.sweetModal({
					content: exception,
					icon: $.sweetModal.ICON_ERROR
				});
			}
		}
	});
}

function getInstallationCompleteMessage() {
	return "Installation Complete! Note: Any custom forms may have been moved to the bottom of your form list.";
}

function installMetadata(fields) {
	const url = getPageUrl("metadata.php");
	$("#metadataWarning").removeClass("install-metadata-box-danger");
	$("#metadataWarning").addClass("install-metadata-box-warning");
	$("#metadataWarning").html("<em class='fa fa-spinner fa-spin'></em> Installing...");
	$.post(url, { 'redcap_csrf_token': getCSRFToken(), process: "install", fields: fields }, function(json) {
		console.log(json);
		if (!json.match(/Exception/)) {
			$("#metadataWarning").removeClass("install-metadata-box-warning");
			$("#metadataWarning").addClass("install-metadata-box-success");
			$("#metadataWarning").html("<i class='fa fa-check' aria-hidden='true'></i> "+getInstallationCompleteMessage());
			setTimeout(function() {
				$("#metadataWarning").fadeOut(500);
			}, 3000);
		} else {
			try {
				const pid = getPid();
				const data = JSON.parse(json);
				const errorMssg = data[pid]['Exception'] ?? "";
				if (errorMssg === "First field is , not record_id!") {
					$.post(url, { 'redcap_csrf_token': getCSRFToken(), process: "install_from_scratch", fields: [] }, function(json2) {
						console.log(json2);
						if (!json2.match(/Exception/)) {
							$("#metadataWarning").removeClass("install-metadata-box-warning");
							$("#metadataWarning").addClass("install-metadata-box-success");
							$("#metadataWarning").html("<i class='fa fa-check' aria-hidden='true'></i> "+getInstallationCompleteMessage());
							setTimeout(function () {
								$("#metadataWarning").fadeOut(500);
							}, 3000);
						} else {
							try {
								const data2 = JSON.parse(json2);
								const errorMssg2 = data2[pid]['Exception'] ?? "";
								$("#metadataWarning").removeClass("install-metadata-box-warning");
								$("#metadataWarning").addClass("install-metadata-box-danger");
								if (errorMssg2) {
									$("#metadataWarning").html("Error in installation! Metadata not updated. "+errorMssg2);
								} else {
									$("#metadataWarning").html("Error in installation! Metadata not updated. "+json2);
								}
							} catch(exception2) {
								console.error(exception2);
								$.sweetModal({
									content: exception2,
									icon: $.sweetModal.ICON_ERROR
								});
							}
						}
					});
				} else if (errorMssg) {
					$("#metadataWarning").removeClass("install-metadata-box-warning");
					$("#metadataWarning").addClass("install-metadata-box-danger");
					$("#metadataWarning").html("Error in installation! Metadata not updated. "+errorMssg);
				} else {
					$("#metadataWarning").removeClass("install-metadata-box-warning");
					$("#metadataWarning").addClass("install-metadata-box-danger");
					$("#metadataWarning").html("Error in installation! Metadata not updated. "+json);
				}
			} catch(exception) {
				console.error(exception);
				$.sweetModal({
					content: exception,
					icon: $.sweetModal.ICON_ERROR
				});
			}
		}
	});
}

function checkMetadata(phpTs) {
	const url = getPageUrl("metadata.php");
	$.post(url, { 'redcap_csrf_token': getCSRFToken(), process: "check", timestamp: phpTs }, function(html) {
		if (html) {
			$('#metadataWarning').addClass("red");
			$('#metadataWarning').html(html);
		}
	});
}

function submitLogs(url) {
	$.post(url, { 'redcap_csrf_token': getCSRFToken() }, function(data) {
		console.log(data);
		$.sweetModal({
			content: 'Logs emailed to developers.',
			icon: $.sweetModal.ICON_SUCCESS
		});

	});
}

function getNewWranglerImg(state) {
	var validStates = [ "checked", "unchecked", "omitted" ];
	if (state && in_array(state, validStates)) {
		var url = "";
		switch(state) {
			case "checked":
				url = checkedImg;
				break;
			case "unchecked":
				url = uncheckedImg;
				break;
			case "omitted":
				url = omittedImg;
				break;
			default:
				break;
		}
		return url;
	}
	return "";
}

function getPubImgHTML(newState, url) {
	var newImg = getNewWranglerImg(newState);
	return "<img align='left' style='margin: 2px; width: 26px; height: 26px;' src='"+newImg+"' alt='"+newState+"' onclick='changeCheckboxValue(this, \""+url+"\");'>";
}

function getBin(pmid) {
	if (!notAlreadyUsed(pmid)) {
		return $('#PMID'+pmid).parent().attr('id');
	}
	return '';
}

function addPMID(pmid, certifyPubURL) {
	if (!isNaN(pmid) && pmid && notAlreadyUsed(pmid)) {
		const newState = 'checked';
		const newDiv = 'notDone';
		const newId = 'PMID'+pmid;
		$('#'+newDiv).append('<div id="'+newId+'" style="margin: 8px 0; min-height: 26px;"></div>');
		submitPMID(pmid, '#'+newId, getPubImgHTML(newState, certifyPubURL), function() {
			if (enqueue()) {
				$('#'+newDiv+'Count').html(parseInt($('#'+newDiv+'Count').html(), 10) + 1);
			}
			const recordId = $("[name=record_id]").val()
			const params = getUrlVars()
			const hash = params['s']
			presentScreen("Saving...");
			$.post(certifyPubURL, { 'redcap_csrf_token': getCSRFToken(), hash: hash, record: recordId, pmid: pmid, state: 'checked' }, function(html) {
				clearScreen();
				console.log(html);
			});
		});
	} else if (isNaN(pmid)) {
		$.sweetModal({
			content: 'PMID '+pmid+' is not a number!',
			icon: $.sweetModal.ICON_ERROR
		});
	} else if (pmid) {
		// already used
		const names = {};
		names['finalized'] = 'Citations Already Accepted and Finalized';
		names['notDone'] = 'Citations to Review';
		names['omitted'] = 'Citations to Omit';
		const bin = getBin(pmid);
		alert('PMID '+pmid+' has already been entered in '+names[bin]+'!');
		// $.sweetModal({
		// 	content: 'PMID '+pmid+' has already been entered in '+names[bin]+'!',
		//	icon: $.sweetModal.ICON_SUCCESS
		// });
	}
}

function changeCheckboxValue(ob, url) {
	const divId = $(ob).parent().attr("id")
	const state = $(ob).attr('alt')
	const pmid = $(ob).parent().attr('id').replace(/^PMID/, "")
	const recordId = $("[name=record_id]").val()

	const params = getUrlVars()
	const hash = params['s']

	let newState = ""
	let newDiv = ""
	let oldDiv = ""
	switch(state) {
		case "omitted":
			newState = "checked"
			newDiv = "notDone"
			oldDiv = "omitted"
			break
		case "unchecked":
			newState = "checked"
			break
		case "checked":
			newState = "omitted"
			newDiv = "omitted"
			oldDiv = "notDone"
			break
		default:
			break
	}
	const newImg = getNewWranglerImg(newState);
	if (newState) {
		$(ob).attr('alt', newState);
		presentScreen("Saving...");
		$.post(url, { 'redcap_csrf_token': getCSRFToken(), hash: hash, record: recordId, pmid: pmid, state: newState }, function(html) {
			clearScreen();
			console.log(html);
		});
	}
	if (newImg) {
		$(ob).attr('src', newImg)
	}
	if (newDiv) {
		const obDiv = $("#"+divId).detach()
		$(obDiv).appendTo("#"+newDiv)
		$(obDiv).show()
		$('#'+newDiv+'Count').html(parseInt($('#'+newDiv+'Count').html(), 10) + 1)
	}
	if (oldDiv) {
		$("#"+oldDiv+"Count").html(parseInt($('#'+oldDiv+'Count').html(), 10) - 1)
	}
	// enqueue();
}

function notAlreadyUsed(pmid) {
	return ($('#PMID'+pmid).length === 0);
}

function enqueue() {
}

function presetValue(name, value) {
	if (($('[name="'+name+'"]').length > 0) && ($('[name="'+name+'"]').val() === "") && (value !== "")) {
		$('[name="'+name+'"]').val(value);
		if ($('[name='+name+'___radio]').length > 0) {
			$('[name='+name+'___radio][value='+value+']').attr('checked', true);
		}
		if ($('#rc-ac-input_'+name).length > 0) {
			// Combobox
			const text = $('[name='+name+'] option:selected').text();
			$('#rc-ac-input_'+name).val(text);
		}
	}
}

function getFlightTrackerColorSetForAM4(numTerms) {
	const beginColorList = [
		{r:87, g:100, b:174},
		{r:240, g:86, b:93},
		{r:141, g:198, b:63},
		{r:247, g:151, b:33},
	];
	const endColorList = [
		{r:212, g:212, b:235},
		{r:252, g:218, b:210},
		{r:229, g:241, b:213},
		{r:241, g:246, b:214},
	];
	const repeatingColorList = [];
	const steps = Math.ceil(numTerms / beginColorList.length);
	for (let i=0; i < steps; i++) {
		for (let j=0; j < beginColorList.length; j++) {
			const rgb = {};
			const beginColor = beginColorList[j];
			const endColor = endColorList[j];
			for (let key in beginColor) {
				rgb[key] = Math.round(beginColor[key] + (endColor[key] - beginColor[key]) * i / (steps - 1));
			}
			repeatingColorList.push(new am4core.Color(rgb));
		}
	}
	const colorSet = new am4core.ColorSet();
	colorSet.list = repeatingColorList;
	colorSet.step = 1;
	colorSet.passOptions = {};
	return colorSet;
}

function clearValue(name) {
	$('[name=\''+name+'\']').val('');
	if ($('[name='+name+'___radio]').length > 0) {
		$('[name='+name+'___radio]').attr('checked', false);
	}
}

function includeWholeRecord(record) {
	$('#include_'+record).hide();
	$('#exclude_'+record).show();
	$('#links_'+record).show();
	$('#note_'+record).hide();
	$('.record_'+record).val(1);
}

function excludeWholeRecord(record) {
	$('#include_'+record).show();
	$('#exclude_'+record).hide();
	$('#links_'+record).hide();
	$('#note_'+record).show();
	$('.record_'+record).val(0);
}

function removePMIDFromAutoApprove(record, instance, pmid) {
	$('#record_'+record+':'+instance).val(0);
	$('#record_'+record+'_idx_'+pmid).hide();
}

function getLoadingImageUrl(baseUrl) {
	const imageLoc = "img/loading.gif";
	if ((typeof baseUrl !== "undefined") && baseUrl.match(/page=/)) {
		return baseUrl.replace(/page=[^&]+/, "page="+encodeURIComponent(imageLoc));
	}
	if (typeof getLoadingImageUrlOverride !== "undefined") {
		return getLoadingImageUrlOverride();
	}
	return getPageUrl(imageLoc);
}

function downloadUrlIntoPage(url, selector) {
	let spinnerUrl = getLoadingImageUrl();
	$(selector).html("<p class='centered'><img src='"+spinnerUrl+"' style='width: 25%;'></p>");
	let startTs = Date.now();
	$.ajax(url, {
		data: { 'redcap_csrf_token': getCSRFToken() },
		type: 'POST',
		success: function(html) {
			let endTs = Date.now();
			console.log("Success: "+((endTs - startTs) / 1000)+" seconds");
			$(selector).html(html);
		},
		error: function (e) {
			console.log("ERROR: "+JSON.stringify(e));
		}
	});
}

function submitEmailAddresses() {
	let selector = 'input[type=checkbox].who_to:checked';
	var checkedEmails = [];
	let post = {};
	post['recipient'] = 'individuals';
	post['name'] = 'Email composed at '+new Date().toUTCString();
	post['noalert'] = '1';
	$(selector).each( function() {
		let name = $(this).attr('name');
		post[name] = 'checked';
		checkedEmails.push(name);
	});
	if (checkedEmails.length > 0) {
		postValues(getPageUrl("emailMgmt/configure.php"), post);
	}
}

function createCohortProject(cohort, src) {
	if (src) {
		$(src).dialog("close");
	}
	presentScreen("Creating project...<br>May take some time to set up project");
	$.post(getPageUrl("cohorts/createCohortProject.php"), { 'redcap_csrf_token': getCSRFToken(), "cohort": cohort }, function(mssg) {
		clearScreen();
		console.log(mssg);
		$.sweetModal({
			content: mssg,
			icon: $.sweetModal.ICON_SUCCESS
		});
	});
}

// https://stackoverflow.com/questions/133925/javascript-post-request-like-a-form-submit
function postValues(path, parameters) {
	var form = $('<form></form>');
	form.attr("method", "post");
	form.attr("action", path);

	$.each(parameters, function(key, value) {
		var field = $('<input></input>');
		field.attr("type", "hidden");
		field.attr("name", key);
		field.attr("value", value);
		form.append(field);
	});

	// The form needs to be a part of the document in
	// order for us to be able to submit it.
	$(document.body).append(form);
	form.submit();
}

function lookupUser(url, firstName, lastName, resultsOb) {
	const postdata = {
		redcap_csrf_token: getCSRFToken(),
		firstName: firstName,
		lastName: lastName
	}
	console.log(JSON.stringify(postdata));
	$.post(url, postdata, (json) => {
		console.log(json);
		try {
			const data = JSON.parse(json);
			if (typeof data.error !== 'undefined') {
				console.error(data.error);
				$.sweetModal({
					content: data.error,
					icon: $.sweetModal.ICON_ERROR
				});
			} else {
				if (Object.keys(data).length > 0) {
					const rows = [];
					for (const userid in data) {
						const nameAndEmail = data[userid];
						rows.push(nameAndEmail+": <strong>"+userid+"</strong>");
					}
					resultsOb.html(rows.join("<br/>"));
				} else {
					resultsOb.html("No matches to "+firstName+" "+lastName+". Is there a nickname or a name change involved?");
				}
			}
		} catch (e) {
			console.error(e);
			$.sweetModal({
				content: e,
				icon: $.sweetModal.ICON_ERROR
			});
		}
	})
}

function setupHorizontalScroll(tableWidth) {
	$('.top-horizontal-scroll').scroll(function(){
		$('.horizontal-scroll').scrollLeft($('.top-horizontal-scroll').scrollLeft());
	});
	$('.horizontal-scroll').scroll(function(){
		$('.top-horizontal-scroll').scrollLeft($('.horizontal-scroll').scrollLeft());
	});
	let horScrollWidth = $('.horizontal-scroll').width();
	$('.top-horizontal-scroll').css({ 'width': horScrollWidth });
	$('.top-horizontal-scroll div').css({ 'width': tableWidth });
}

function submitPatent(patent, textId, prefixHTML, cb) {
	submitPatents([patent], textId, prefixHTML, cb);
}

function submitPatents(patents, textId, prefixHTML, cb) {
	if (!Array.isArray(patents)) {
		patents = pmids.split(/\n/);
	}
	if (!prefixHTML) {
		prefixHTML = '';
	}
	if (!cb) {
		cb = function() { };
	}
	if (patents && (Array.isArray(patents))) {
		resetCitationList(textId);
		presentScreen("Downloading...");
		downloadOnePatent(0, patents, textId, prefixHTML, cb);
	}
}

function downloadOnePatent(i, patents, textId, prefixHTML, doneCb) {
	const patentNumber = patents[i].replace(/^US/i, '');
	if (patentNumber) {
		const o = {"page": 1, "per_page": 50};
		const q = {"patent_number": patentNumber };
		const f = ["patent_number", "patent_date", "patent_title"];

		const url = "https://api.patentsview.org/patents/query?q="+JSON.stringify(q)+"&f="+JSON.stringify(f)+"&o="+JSON.stringify(o);
		// AJAX call will return in uncertain order => append, not overwrite, results
		$.ajax({
			url: url,
			success: function(data) {
				console.log(JSON.stringify(data));
				const listings = [];
				for (let i=0; i < data.patents.length; i++) {
					const entry = data.patents[i];
					if (entry['patent_number']) {
						const listing = "Patent "+entry['patent_number']+' '+entry['patent_title']+' ('+entry['patent_date']+')';
						listings.push(listing);
					}
				}

				updatePatentList(textId, prefixHTML, listings.join('\n'));
				const nextI = i + 1;
				if (nextI < patents.length) {
					setTimeout(function() {
						downloadOnePatent(nextI, patents, textId, prefixHTML, doneCb);
					}, 500);    // rate limiter
				} else if (nextI === patents.length) {
					clearScreen();
					doneCb();
				}
			},
			error: function(e) {
				updatePatentList(textId, prefixHTML, 'ERROR: '+JSON.stringify(e));
				const nextI = i + 1;
				if (nextI < patents.length) {
					setTimeout(function () {
						downloadOnePatent(nextI, patents, textId, prefixHTML, doneCb);
					}, 500);    // rate limiter
				} else {
					clearScreen();
				}
			}
		});
	}
}

function getPatentNumber(patent) {
	const matches = patent.match(/Patent \d+/);
	if (matches && (matches.length >= 1)) {
		const str = matches[0];
		return str.replace(/Patent /, '').replace(/^US/i, '');
	}
	return '';
}

function updatePatentList(textId, prefixHTML, text) {
	updateCitationList(textId, prefixHTML, text);
}

function omitPublication(recordId, instance, pmid) {
	presentScreen('Omitting');
	$.post(getPageUrl('publications/omit.php'), { 'redcap_csrf_token': getCSRFToken(), record: recordId, instance: instance, pmid: pmid }, function(html) {
		clearScreen();
		console.log(html);
		$.sweetModal({
			content: 'Publication successfully omitted!',
			icon: $.sweetModal.ICON_SUCCESS
		});
	});
}

function omitGrant(recordId, grantNumber, source) {
	presentScreen('Omitting');
	$.post(getPageUrl('wrangler/omitGrant.php'), { 'redcap_csrf_token': getCSRFToken(), record: recordId, grantNumber: grantNumber, source: source }, function(html) {
		clearScreen();
		console.log(html);
		$.sweetModal({
			content: 'Grant successfully omitted!',
			icon: $.sweetModal.ICON_SUCCESS
		});
	});
}

function copyProject(token, server) {
	if (token && server && (token.length == 32)) {
		presentScreen('Copying project...<br>May take some time depending on size');
		$.post(getPageUrl('copyProject.php'), { 'redcap_csrf_token': getCSRFToken(), token: token, server: server }, function(html) {
			clearScreen();
			console.log(html);
			if (html.match(/error:/i) || html.match(/ERROR/)) {
				$.sweetModal({
					content: 'ERROR: '+html,
					icon: $.sweetModal.ICON_ERROR
				});
			} else {
				$.sweetModal({
					content: 'Successfully copied.',
					icon: $.sweetModal.ICON_SUCCESS
				});
			}
		});
	} else {
		$.sweetModal({
			content: 'Invalid Settings.',
			icon: $.sweetModal.ICON_ERROR
		});
	}
}

function enforceOneNumber(ob1, ob2, ob3) {
	if ($(ob1).val() !== '') {
		$(ob2).val('');
		$(ob3).val('');
	} else if ($(ob2).val() !== '') {
		$(ob1).val('');
		$(ob3).val('');
	} else if ($(ob3).val() !== '') {
		$(ob1).val('');
		$(ob2).val('');
	}
}

function copyToClipboard(element) {
	const text = $(element).text() ? $(element).text() : $(element).val();
	navigator.clipboard.writeText(text);
}

function confirmRestartData(url, recordId, csrfToken, fetchType) {
	const mssg = "Are you certain that you want to DELETE and refresh all "+fetchType+"?";
	const buttons = {};
	if (isREDCapPage()) {
		buttons["Delete and Refresh "+fetchType] = function() { restartDataNow(url, recordId, csrfToken, fetchType); };
		buttons["Cancel"] = function() { };
	} else {
		buttons["Delete and Refresh "+fetchType] = function() { restartDataNow(url, recordId, csrfToken, fetchType); $(this).dialog("close"); };
		buttons["Cancel"] = function() { $(this).dialog("close"); };
	}

	showButtonDialog("Confirm Delete", mssg, buttons, 400);
}

function isREDCapPage() {
	const myURL = window.location.href;
	return myURL.match(/redcap_v\d/) && !myURL.match(/ExternalModules/);
}

function showButtonDialog(title, mssg, buttonConfigs, width) {
	if (isREDCapPage()) {
		// Use REDCap function
		const buttonLabels = Object.keys(buttonConfigs);
		simpleDialog(mssg, title, 'dialog-confirm', width, function() { buttonConfigs[buttonLabels[1]](); }, buttonLabels[1], function() { buttonConfigs[buttonLabels[0]](); }, buttonLabels[0], true);
	} else {
		// Use jQuery UI if available
		const mssgHTML = '<p>'+mssg+'</p>';
		$('body').append('<div id="dialog-confirm" title="'+title+'">'+mssgHTML+'</div>');
		$( "#dialog-confirm" ).dialog({
			resizable: false,
			height: "auto",
			width: width,
			modal: true,
			buttons: buttonConfigs
		});
	}
}

function restartDataNow(url, recordId, csrfToken, fetchType) {
	const postdata = {
		record: recordId,
		redcap_csrf_token: csrfToken,
		fetchType: fetchType,
		action: 'delete',
	}
	if (!url.match(/fetchDataNow/)) {
		$.sweetModal({
			content: 'Invalid URL.',
			icon: $.sweetModal.ICON_ERROR
		});
		return;
	}
	const imageUrl = getLoadingImageUrl(url);
	presentScreen("Deleting "+fetchType+" for Record "+recordId, imageUrl);
	$.post(url, postdata, function(html) {
		console.log(html);
		clearScreen();
		if (html.match(/error/i)) {
			$.sweetModal({
				content: 'ERROR: ' + html,
				icon: $.sweetModal.ICON_ERROR
			});
		} else {
			fetchDataNow(url, recordId, csrfToken, fetchType);
		}
	});
}

function fetchDataNow(url, recordId, csrfToken, fetchType) {
	const postdata = {
		record: recordId,
		redcap_csrf_token: csrfToken,
		fetchType: fetchType,
		action: 'fetch',
	}
	if (!url.match(/fetchDataNow/)) {
		$.sweetModal({
			content: 'Invalid URL.',
			icon: $.sweetModal.ICON_ERROR
		});
		return;
	}
	const imageUrl = getLoadingImageUrl(url);
	let presentFetchType = fetchType;
	if (presentFetchType.match(/_name$/)) {
		presentFetchType = presentFetchType.replace(/_name$/, "") + " for any institution";
	}
	let shortFetchType = fetchType.replace(/_name$/, "");
	presentScreen("Refreshing "+presentFetchType+" for Record "+recordId, imageUrl);
	$.post(url, postdata, function(html) {
		console.log(html);
		clearScreen();
		if (html.match(/error/i)) {
			$.sweetModal({
				content: 'ERROR: '+html,
				icon: $.sweetModal.ICON_ERROR
			});
		} else {
			$.sweetModal({
				content: 'Record '+recordId+' has had its '+shortFetchType+' refreshed. You need to refresh your browser to see the latest results.',
				icon: $.sweetModal.ICON_SUCCESS
			});
		}
	});
}


/**
 * Adapted from https://ramblings.mcpher.com/gassnippets2/converting-svg-to-png-with-javascript/
 * converts an svg string to base64 png using the domUrl and forces download
 * @param {string} svgText the svgtext
 * @param {number} [margin=0] the width of the border - the image size will be height+margin by width+margin
 * @param {string} [fill] optionally background canvas fill
 * @param {string} canvasFunction
 * @param {string} fontName name of font used
 * @param {string} woffFontBase64 the base-64 for a data url for the font
 * @return {Promise} a promise to the base64 png image
 */
function downloadSVG(svgText, margin,fill, canvasFunction, fontName, woffFontBase64) {
	// convert an svg text to png using the browser
	return new Promise(function (resolve, reject) {
		try {
			// can use the domUrl function from the browser
			let domUrl = window.URL || window.webkitURL || window;
			if (!domUrl) {
				throw new Error("(browser doesnt support this)")
			}

			// figure out the height and width from svg text
			let match = svgText.match(/height=\"(\d+)/m);
			let height = match && match[1] ? parseInt(match[1], 10) : 200;
			match = svgText.match(/width=\"(\d+)/m);
			let width = match && match[1] ? parseInt(match[1], 10) : 200;
			margin = margin || 0;

			// it needs a namespace
			if (!svgText.match(/xmlns=\"/mi)) {
				svgText = svgText.replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ');
			}

			// include font if applicable
			if (fontName && woffFontBase64) {
				let matches = svgText.match(/<svg [^>]+?>/);
				if (matches) {
					const css = '@font-face { font-family: \''+fontName+'\'; src: url(data:font/woff2;base64,'+woffFontBase64+') format(\'woff2\'); }\n';
					if (svgText.match(/<defs>/i)) {
						svgText = svgText.replace(/<defs>/i, '<defs><style>\n'+css+'</style>')
					} else {
						svgText = svgText.replace(/<svg [^>]+?>/, matches[0]+'<defs><style>\n'+css+'</style></defs>')
					}
				}
			}

			// create a canvas element to pass through
			let canvas = document.createElement("canvas");
			canvas.width = width + margin * 2;
			canvas.height = height + margin * 2;
			let ctx = canvas.getContext("2d");

			// make a blob from the svg
			let svg = new Blob([svgText], {
				type: "image/svg+xml;charset=utf-8"
			});

			// create a dom object for that image
			let url = domUrl.createObjectURL(svg);

			// create a new image to hold it the converted type
			let img = new Image;

			img.onerror = function(ev) {
				console.error('image load error');
				console.error(ev);
			}

			// when the image is loaded we can get it as base64 url
			img.onload = function () {
				// draw it to the canvas
				ctx.drawImage(this, margin, margin);

				// if it needs some styling, we need a new canvas
				if (fill) {
					var styled = document.createElement("canvas");
					styled.width = canvas.width;
					styled.height = canvas.height;
					var styledCtx = styled.getContext("2d");
					styledCtx.save();
					styledCtx.fillStyle = fill;
					styledCtx.fillRect(0, 0, canvas.width, canvas.height);
					styledCtx.strokeRect(0, 0, canvas.width, canvas.height);
					styledCtx.restore();
					styledCtx.drawImage(canvas, 0, 0);
					canvas = styled;
				}
				// we don't need the original any more
				domUrl.revokeObjectURL(url);
				// now we can resolve the promise, passing the base64 url
				const downloadUrl = canvasFunction(canvas);
				forceDownloadUrl(downloadUrl, 'chart.png');

				resolve(downloadUrl);
			};

			// load the image
			img.src = url;
		} catch (err) {
			reject('failed to convert svg to png ' + err);
		}
	});
}

/**
 * Adapted from https://ramblings.mcpher.com/gassnippets2/converting-svg-to-png-with-javascript/
 * converts an svg string to base64 png using the domUrl and forces download
 * @param {string} svgText the svgtext
 * @param {number} [margin=0] the width of the border - the image size will be height+margin by width+margin
 * @param {string} [fill] optionally background canvas fill
 * @param {string} canvasFunction
 * @param {string} fontName name of font used
 * @param {string} woffFontLocation location of WOFF2 font file to import
 * @return {Promise} a promise to the base64 png image
 */
function svg2Image(svgText, margin,fill, canvasFunction, fontName, woffFontLocation) {
	try {
		if (fontName && woffFontLocation) {
			return new Promise(function(resolve, reject) {
				return fetch(woffFontLocation)
					.then((resp) => {
						return resp.blob();
					})
					.then((blob) => {
						let f = new FileReader();
						f.addEventListener('load', () => {
							const base64 = f.result.replace(/^data:application\/octet-stream;base64,/, '');
							resolve(downloadSVG(svgText, margin, fill, canvasFunction, fontName, base64));
						});
						f.readAsDataURL(blob);
					})
					.catch(err => {
						reject('failed to read font ' + err);
					});
			});
		} else {
			return downloadSVG(svgText, margin, fill, canvasFunction);
		}
	} catch (err) {
		reject('failed to set up reading a font' + err);
	}
}

function canvas2PNG(canvasOb) {
	return canvasOb.toDataURL("image/png");
}

function canvas2JPEG(canvasOb) {
	return canvasOb.toDataURL("image/jpeg");
}

function forceDownloadUrl(source, fileName){
	var el = document.createElement("a");
	el.setAttribute("href", source);
	el.setAttribute("download", fileName);
	document.body.appendChild(el);
	el.click();
	el.remove();
}

function lookupREDCapUserid(link, resultsOb) {
	const firstName = $('#first_name').val();
	const lastName = $('#last_name').val();
	if (!lastName && !firstName) {
		$.sweetModal({icon: $.sweetModal.ICON_ERROR, content: 'You must supply a name'});
	} else {
		const postParams = { 'redcap_csrf_token': getCSRFToken(), firstName: firstName, lastName: lastName };
		$.post(link, postParams, function(json) {
			console.log(json);
			try {
				const dataHash = JSON.parse(json);
				if (Object.keys(dataHash).length > 0) {
					const userids = [];
					for (const uid in dataHash) {
						const name = dataHash[uid];
						userids.push(uid+': '+name);
					}
					let header = '';
					if (userids.length > 1) {
						header = '<h4>' + userids.length + ' Matches</h4>';
					}
					resultsOb.html(header + userids.join('<br/>'));
				} else {
					resultsOb.html('No names found in REDCap.');
				}
			} catch(exception) {
				console.error(exception);
				$.sweetModal({
					content: exception,
					icon: $.sweetModal.ICON_ERROR
				});
			}
		});
	}
}

function makeHTMLId(id) {
	return id.replace(/[\s\-]+/, "_")
		.replace(/<[^>]+>/, "")
		.replace(/[\:\+\"\/\[\]\'\#\<\>\~\`\!\@\#\$\%\^\&\*\(\)\=\;\?\.\,]/, "");
}

function addCelebrationsSetting(url, settingName, when, who, content, grants) {
	const postData = {
		action: 'add',
		name: settingName,
		when: when,
		content: content,
		who: who,
		grants: grants,
		redcap_csrf_token: getCSRFToken()
	}
	postToCelebrationsEmail(url, postData);
}

function deleteCelebrationsSetting(url, settingName) {
	const postData = {
		action: 'delete',
		name: settingName,
		redcap_csrf_token: getCSRFToken()
	}
	postToCelebrationsEmail(url, postData);
}

function postToCelebrationsEmail(url, postData) {
	if (postData['name'] === "") {
		$.sweetModal({
			content: "No setting name provided!",
			icon: $.sweetModal.ICON_ERROR
		});
	} else if (url.match(/configEmail/)) {
		$.post(url, postData, (html) => {
			if (html.match(/error/i)) {
				console.error(html);
				$.sweetModal({
					content: html,
					icon: $.sweetModal.ICON_ERROR
				});
			} else {
				console.log(html);
				$.sweetModal({
					content: "Updated!",
					icon: $.sweetModal.ICON_SUCCESS
				});
				location.reload();
			}
		});
	} else {
		$.sweetModal({
			content: "Something has gone wrong.",
			icon: $.sweetModal.ICON_ERROR
		});
	}
}

function changeCelebrationsEmail(url, email) {
	const postData = {
		action: 'changeEmail',
		email: email,
		redcap_csrf_token: getCSRFToken()
	};
	if (url.match(/configEmail/)) {
		$.post(url, postData, (html) => {
			if (html.match(/error/i)) {
				console.error(html);
				$.sweetModal({
					content: html,
					icon: $.sweetModal.ICON_ERROR
				});
			} else {
				console.log(html);
				$.sweetModal({
					content: "Updated!",
					icon: $.sweetModal.ICON_SUCCESS
				});
				$('#celebration_config').show();
			}
		});
	} else {
		$.sweetModal({
			content: "Something has gone wrong.",
			icon: $.sweetModal.ICON_ERROR
		});
	}}