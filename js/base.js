function stripFromHTML(str, html) {
	html = stripHTML(html);
	var lines = html.split(/\n/);
	var regex = new RegExp(str+":\\s+(.+)$", "i");
	var matches;
	for (var i=0; i < lines.length; i++) {
		var line = lines[i];
		if (matches = line.match(regex)) {
			if (matches[1]) {
				return matches[1];
			}
		}
	}
	return "";
}

function turnOffStatusCron() {
	$.post(getPageUrl("testConnectivity.php"), { turn_off: 1 }, function(html) {
		console.log("Turned off "+html);
		$("#status").html("Off");
		$("#status_link").html("Turn on status cron");
		$("#status_link").attr("onclick", "turnOnStatusCron();");
	});
}

function turnOnStatusCron() {
	$.post(getPageUrl("testConnectivity.php"), { turn_on: 1 }, function(html) {
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
}

function submitPMCs(pmcs, textId, prefixHTML) {
	if (!Array.isArray(pmcs)) {
		pmcs = pmcs.split(/\n/);
	}
	if (pmcs && Array.isArray(pmcs)) {
		resetCitationList(textId);
		presentScreen("Downloading...");
		downloadOnePMC(0, pmcs, textId, prefixHTML);
	}
}

function downloadOnePMC(i, pmcs, textId, prefixHTML) {
	var pmc = pmcs[i];
	if (pmc) {
		if (!pmc.match(/PMC/)) {
			pmc = 'PMC' + pmc;
		}
		var url = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pmc&retmode=xml&id=' + pmc;
		$.ajax({
			url: url,
			success: function (xml) {
				var pmid = '';
				var myPmc = '';
				var articleLocation = 'pmc-articleset>article>front>';
				var articleMetaLocation = articleLocation + 'article-meta>';
				$(xml).find(articleMetaLocation + 'article-id').each(function () {
					if ($(this).attr('pub-id-type') === 'pmid') {
						pmid = 'PubMed PMID: ' + $(this).text() + '. ';
					} else if ($(this).attr('pub-id-type') === 'pmc') {
						myPmc = 'PMC' + $(this).text() + '.';
					}
				});
				var journal = '';
				$(xml).find(articleLocation + 'journal-meta>journal-id').each(function () {
					if ($(this).attr('journal-id-type') === 'iso-abbrev') {
						journal = $(this).text();
					}
				});
				journal = journal.replace(/\.$/, '');

				var year = '';
				var month = '';
				var day = '';
				$(xml).find(articleMetaLocation + 'pub-date').each(function () {
					var pubType = $(this).attr('pub-type');
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
				var volume = $(xml).find(articleMetaLocation + 'volume').text();
				var issue = $(xml).find(articleMetaLocation + 'issue').text();

				var fpage = $(xml).find(articleMetaLocation + 'fpage').text();
				var lpage = $(xml).find(articleMetaLocation + 'lpage').text();
				var pages = '';
				if (fpage && lpage) {
					pages = fpage + '-' + lpage;
				}

				var title = $(xml).find(articleMetaLocation + 'title-group>article-title').text();
				title = title.replace(/\.$/, '');

				var namePrefix = 'name>';
				var names = [];
				$(xml).find(articleMetaLocation + 'contrib-group>contrib').each(function (index, elem) {
					if ($(elem).attr('contrib-type') === 'author') {
						var surname = $(elem).find(namePrefix + 'surname').text();
						var givenNames = $(elem).find(namePrefix + 'given-names').text();
						names.push(surname + ' ' + givenNames);
					}
				});

				var loc = getLocation(volume, issue, pages);
				var citation = names.join(',') + '. ' + title + '. ' + journal + '. ' + year + ' ' + month + day + ';' + loc + '. ' + pmid + myPmc;
				updateCitationList(textId, prefixHTML, citation);
				let nextI = i + 1;
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
				let nextI = i + 1;
				if (nextI < pmids.length) {
					setTimeout(function () {
						downloadOnePMC(nextI, pmcs, textId, prefixHTML);
					}, 1000);    // rate limiter
				} else {
					clearScreen();
				}
			}
		});
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
	var pmid = pmids[i];
	if (pmid) {
		var url = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id='+pmid;
		// AJAX call will return in uncertain order => append, not overwrite, results
		$.ajax({
			url: url,
			success: function(xml) {
				// similar to publications/getPubMedByName.php
				// make all changes in two places in two languages!!!

				var citationLocation = 'PubmedArticleSet>PubmedArticle>MedlineCitation>';
				var articleLocation = citationLocation + 'Article>';
				var journalLocation = articleLocation + 'Journal>JournalIssue>';

				var myPmid = $(xml).find(citationLocation+'PMID').text();
				var year = $(xml).find(journalLocation+'PubDate>Year').text();
				var month = $(xml).find(journalLocation+'PubDate>Month').text();
				var volume = $(xml).find(journalLocation+'Volume').text();
				var issue = $(xml).find(journalLocation+'Issue').text();
				var pages = $(xml).find(articleLocation+'Pagination>MedlinePgn').text();
				var title = $(xml).find(articleLocation+'ArticleTitle').text();
				title = title.replace(/\.$/, '');

				var journal = trimPeriods($(xml).find(articleLocation + 'Journal>ISOAbbreviation').text());
				journal = journal.replace(/\.$/, '');

				var dayNode = $(xml).find(journalLocation+'PubDate>Day');
				var day = '';
				if (dayNode) {
					day = ' '+dayNode.text();
				}

				var names = [];
				$(xml).find(articleLocation+'AuthorList>Author').each(function(index, elem) {
					var lastName = $(elem).find('LastName');
					var initials = $(elem).find('Initials');
					var collective = $(elem).find('CollectiveName');
					if (lastName && initials) {
						names.push(lastName.text()+' '+initials.text());
					} else if (collective) {
						names.push(collective.text());
					}
				});

				var loc = getLocation(volume, issue, pages);
				var citation = names.join(',')+'. '+title+'. '+journal+'. '+year+' '+month+day+';'+loc+'. PubMed PMID: '+myPmid;
				console.log('citation: '+citation);

				updateCitationList(textId, prefixHTML, citation);
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
				let nextI = i + 1;
				if (nextI < pmids.length) {
					setTimeout(function () {
						downloadOnePMID(nextI, pmids, textId, prefixHTML, doneCb);
					}, 1000);    // rate limiter
				} else {
					clearScreen();
				}
			}
		});
	}
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
	if (pmids && (Array.isArray(pmids))) {
		resetCitationList(textId);
		presentScreen("Downloading...");
		downloadOnePMID(0, pmids, textId, prefixHTML, cb);
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
	var vars = {};
	var parts = window.location.href.replace(/[?&]+([^=&]+)=([^&]*)/gi, function(m,key,value) {
		vars[key] = value;
	});
	return vars;
}

function refresh() {
	location.reload();
}

// page is blank if current page is requested
function getPageUrl(page) {
	var params = getUrlVars();
	if (params['page']) {
		var url = "?pid="+params['pid'];
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
	var recordId = getRecord();
	var newFinalized = [];
	var newOmits = [];
	var resets = [];
	$('#finalize').hide();
	$('#uploading').show();
	$('[type=hidden]').each(function(idx, elem) {
		var id = $(elem).attr("id");
		if ((typeof id != "undefined") && id.match(/^PMID/)) {
			var value = $(elem).val();
			var pmid = id.replace(/^PMID/, "");
			if (!isNaN(pmid)) {
				if (value == "include") {
					// checked => put in finalized
					newFinalized.push(pmid);
				} else if (value == "exclude") {
					// unchecked => put in omits
					newOmits.push(pmid);
				} else if (value == "reset") {
					resets.push(pmid);
				}
			}
		}
	});
	var url = getPageUrl("wrangler/savePubs.php");
	let postdata = {
		record_id: recordId,
		omissions: JSON.stringify(newOmits),
		resets: JSON.stringify(resets),
		finalized: JSON.stringify(newFinalized)
	};
	console.log('Posting '+JSON.stringify(postdata));
	$.ajax({
		url: url,
		method: 'POST',
		data: postdata,
		dataType: 'json',
		success: function(data) {
			if (data['count'] && (data['count'] > 0)) {
				var str = "items";
				if (data['item_count'] == 1) {
					str = "item";
				}
				var mssg = data['count']+" "+str+" uploaded";
				window.location.href = getPageUrl("wrangler/pubs.php")+getHeaders()+"&mssg="+encodeURI(mssg)+"&record="+nextRecord;
			} else if (data['item_count'] && (data['item_count'] > 0)) {
				var str = "items";
				if (data['item_count'] == 1) {
					str = "item";
				}
				var mssg = data['item_count']+" "+str+" uploaded";
				window.location.href = getPageUrl("wrangler/pubs.php")+getHeaders()+"&mssg="+encodeURI(mssg)+"&record="+nextRecord;
			} else if (data['error']) {
				$('#uploading').hide();
				$('#finalize').show();
				alert('ERROR: '+data['error']);
			} else {
				$('#uploading').hide();
				$('#finalize').show();
				console.log("Unexplained return value. "+JSON.stringify(data));
			}
		},
		error: function(e) {
			if (!e.status || (e.status != 200)) {
				$('#uploading').hide();
				$('#finalize').show();
				alert("ERROR: "+JSON.stringify(e));
			} else {
				console.log(JSON.stringify(e));
			}
		}
	});
	console.log("Done");
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
		$.post(getPageUrl("lexicallyReorder.php"), { keys: JSON.stringify(keys) }, function(data) {
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

function presentScreen(mssg) {
	if ($('#overlay').length > 0) {
		$('#overlay').html('<br><br><br><br><h1 class=\"warning\">'+mssg+'</h1>');
		$('#overlay').show();
	}
}

function clearScreen() {
	if ($('#overlay').length > 0) {
		$('#overlay').html('');
		$('#overlay').hide();
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
	$.post(helpUrl, { fullPage: currPage }, function(html) {
		if (html) {
			$('#help').html(html);
		} else {
			$('#help').html("<h4 class='nomargin'>No Help Resources are Available for This Page</h4>");
		}
		// coordinate with .subnav
		if ($('.subnav').length == 1) {
			var right = $('.subnav').position().left + $('.subnav').width(); 
			var offset = 10;
			var helpLeft = right + offset;
			var rightOffset = helpLeft + 40;
			var helpWidth = "calc(100% - "+rightOffset+"px)";
			$('#help').css({ left: helpLeft+"px", position: "relative", width: helpWidth });
		} 
		$('#help').slideDown();
	});
}

function hideHelp(helpHiderUrl) {
	$('#help').hide();
	$.post(helpHiderUrl, { }, function() {
	});
}

function startTonight() {
	var url = getPageUrl("downloadTonight.php");
	console.log(url);
	$.ajax({
		url:url,
		success: function(data) {
			console.log("result: "+data);
			alert("Downloads will start tonight!");
		},
		error: function(e) {
			console.log("ERROR! "+JSON.stringify(e));
		}
	});
}

function installMetadata(fields) {
	var url = getPageUrl("metadata.php");
	$("#metadataWarning").removeClass("install-metadata-box-danger");
	$("#metadataWarning").addClass("install-metadata-box-warning");
	$("#metadataWarning").html("<em class='fa fa-spinner fa-spin'></em> Installing...");
	$.post(url, { process: "install", fields: fields }, function(data) {
		console.log(JSON.stringify(data));
		$("#metadataWarning").removeClass("install-metadata-box-warning");
		if (!data.match(/Exception/)) {
			$("#metadataWarning").addClass("install-metadata-box-success");
			$("#metadataWarning").html("<i class='fa fa-check' aria-hidden='true'></i> Installation Complete");
			setTimeout(function() {
				$("#metadataWarning").fadeOut(500);
			}, 3000);
		} else {
			$("#metadataWarning").addClass("install-metadata-box-danger");
			$("#metadataWarning").html("Error in installation! Metadata not updated. "+JSON.stringify(data));
		}
	});
}

function checkMetadata(phpTs) {
	var url = getPageUrl("metadata.php");
	$.post(url, { process: "check", timestamp: phpTs }, function(html) {
		if (html) {
			$('#metadataWarning').addClass("red");
			$('#metadataWarning').html(html);
		}
	});
}

function submitLogs(url) {
	$.post(url, {}, function(data) {
		console.log(data);
		alert("Emailed logs to Developers");
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

function getPubImgHTML(newState) {
	var newImg = getNewWranglerImg(newState);
	return "<img align='left' style='margin: 2px; width: 26px; height: 26px;' src='"+newImg+"' alt='"+newState+"' onclick='changeCheckboxValue(this);'>";
}

function addPMID(pmid) {
	if (!isNaN(pmid) && notAlreadyUsed(pmid)) {
		var newState = 'checked';
		var newDiv = 'notDone';
		var newId = 'PMID'+pmid;
		$('#'+newDiv).append('<div id="'+newId+'" style="margin: 8px 0; min-height: 26px;"></div>');
		submitPMID(pmid, '#'+newId, getPubImgHTML(newState), function() { if (enqueue()) { $('#'+newDiv+'Count').html(parseInt($('#'+newDiv+'Count').html(), 10) + 1); } });
	} else if (isNaN(pmid)) {
		alert('PMID '+pmid+' is not a number!');
	} else {
		// not already used
		var names = {};
		names['finalized'] = 'Citations Already Accepted and Finalized';
		names['notDone'] = 'Citations to Review';
		names['omitted'] = 'Citations to Omit';
		alert('PMID '+pmid+' has already been entered in '+names[bin]+'!');
	}
}

function changeCheckboxValue(ob) {
	let divId = $(ob).parent().attr("id")
	let state = $(ob).attr('alt')
	let pmid = $(ob).parent().attr('id').replace(/^PMID/, "")
	let recordId = $("#record_id").val()

	let params = getUrlVars()
	let hash = params['s']

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
	let newImg = getNewWranglerImg(newState);
	if (newState) {
		$(ob).attr('alt', newState)
		if (extmod_base_url) {
			// on survey page
			$.post(extmod_base_url+"?prefix=flightTracker&page="+encodeURI("wrangler/certifyPub")+"&pid="+pid, { hash: hash, record: recordId, pmid: pmid, state: newState }, function(html) {
				console.log(html);
			})
		} else {
			console.log("No External Module base URL")
		}
	}
	if (newImg) {
		$(ob).attr('src', newImg)
	}
	if (newDiv) {
		let obDiv = $("#"+divId).detach()
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
	if (($('[name="'+name+'"]').val() == "") && (value != "")) {
		$('[name="'+name+'"]').val(value);
		if ($('[name='+name+'___radio]').length > 0) {
			$('[name='+name+'___radio][value='+value+']').attr('checked', true);
		}
	}
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
	$('#record_'+record+'_pmid_'+pmid).hide();
}
