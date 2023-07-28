function updateAll(ob, pid, post) {
	var testField = 'test_to';
	var id = $(ob).attr('id');
	var name = $(ob).attr('name');
	let skip = ["survey_links"]
	console.log("updateAll with id "+id+" and name "+name);
	if ((id || name) && (skip.indexOf(name) < 0) && (skip.indexOf(id) < 0)) {
		if ($(ob).attr('type') != "checkbox") {
			// only use post variable if not a checkbox
			if ($('[name=recipient][value=filtered_group]').is(':checked')) {
				$('#filter').show();
				$('#checklist').hide();
				if (($(ob).hasClass('who_to')) && (id != testField)) {
					updateNames(pid, post);
				}
			} else {
				$('#filter').hide();
				$('#checklist').show();
				if (id != testField) {
					updateNames(pid, post);
				}
			}
		}

		if (id != testField) {
			$('#test').hide();
			$('#enableEmail').hide();
			$('#save').show();
		}
	}
}

function updateNames(pid, existingPost) {
	var post = {};
	var selector = "";
	if ($('#filter').is(':visible')) {
		selector = '#filter';
		if ($('[name=filter]:checked').val() == "some") {
			$('#filterItems').slideDown();
		} else {
			$('#filterItems').hide();
		}
		if ($('[name=survey_complete]:checked').val() == "yes") {
			$('#whenCompleted').slideDown();
		} else {
			$('#whenCompleted').hide();
		}
		if ($('[name=max][value=limited]').is(':checked')) {
			$('#numEmails').slideDown();
		} else {
			$('#numEmails').hide();
		}
		if ($('[name=newRecords][value=new]').is(':checked')) {
			$('#newRecordsSinceDisplay').slideDown();
		} else {
			$('#newRecordsSinceDisplay').hide();
		}

		post['filter'] = $('[name=filter]:checked').val();
		if ($('[name=survey_complete]').is(':visible')) {
			if ($('[name=survey_complete]:checked').val() == "yes") {
				post['last_complete'] = $('[name=last_complete_months]').val();
			} else {
				post['none_complete'] = true;
			}
		}
		if ($('[name=new_records_since]').is(':visible')) {
			if ($('[name=new_records_since]').val() !== '') {
				post['new_records_since'] = $('[name=new_records_since]').val();
			}
		}
		if ($('[name=r01_or_equiv]').is(':visible')) {
			post['converted'] = $('[name=r01_or_equiv]:checked').val();
		}

	} else if ($('#checklist').is(':visible')) {
		selector = '#checklist';
		post['recipient'] = 'individuals';
	}
	post['redcap_csrf_token'] = getCSRFToken();

	if (selector) {
		$(selector+' .namesCount').html("");
		$(selector+' .namesFiltered').html("Retrieving Names...");
		$.post(getPageUrl("/emailMgmt/getNames.php"), post, function(html) {
			if (html != "No names match your description.") {
				$(selector+' .namesCount').html(" ("+getHTMLLines(html)+")");
			} else {
				$(selector+' .namesCount').html("");
			}
			if (selector.match(/checklist/)) {
				html = transformIntoCheckboxes(html, existingPost);
			}
			$(selector+' .namesFiltered').html(html);
			addCheckboxHandlers(pid);
		});
	}
}

function makeEmailIntoID(email) {
	return email.replace(/\@/, "_at_");
} 

function transformIntoCheckboxes(html, post) {
	var ary = html.split(/<br>/);
	var ary2 = [];
	for (var i=0; i < ary.length; i++) {
		var item = ary[i];
		if (item.match(/;/)) {
			var a = item.split(/;/);
			var name = a[0];
			var email = makeEmailIntoID(a[1]);
			var check = "";
			if (post[email]) {
				check = " checked";
			}

			if (email) {
				item = "<span class='nobreak'><input type='checkbox' id='"+email+"' name='"+email+"'"+check+"> <label for='"+email+"'>"+name+"</label></span>";
			} else {
				item = "";
			}
		}
		if (item) {
			ary2.push(item);
		}
	}
	return "<div class='wrapTightly' style='text-align: left;'>"+ary2.join("<br>")+"</div>";
}

function getHTMLLines(html) {
	var ary = html.split(/<br>/);
	return ary.length;
}

function isEmail(email) {
	if (/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,3})+$/.test(email)) {
		return true;
	}
	return false;
}

function sendTestEmails(pid, selectName, selectValue) {
	var to = $('#test_to').val();
	if (isEmail(to)) {
		var post = {};
		post['to'] = to;
		post[selectName] = selectValue;
		presentScreen("Preparing Messages... (Please wait. May take some time.)");
		console.log(JSON.stringify(post));
		$.post(getPageUrl("/emailMgmt/makeMessages.php"), post, function(json) {
			console.log(json);
			try {
				let data = JSON.parse(json);
				for (var name in data) {
					for (var name2 in data[name]) {
						let mssgs = data[name][name2]['mssgs'];
						var count = 0;
						for (var recordId in mssgs) {
							count++;
						}
						console.log('makeMessages: '+name+' '+name2+' has '+count+' messages');
					}
				}
				presentScreen("Sending Messages... (Please wait. May take some time.)");
				$.post(getPageUrl("/emailMgmt/sendTest.php"), { messages: json }, function(str) {
					console.log(str);
					clearScreen();
					if (!$('#note').hasClass("green")) {
						$('#note').addClass("green");
					}
					$('#note').html("Test emails sent "+str);
					$("#enableEmail").slideDown();
				});
			} catch (e) {
				clearScreen();
				$.sweetModal({
					content: 'ERROR: '+json,
					icon: $.sweetModal.ICON_ERROR
				});
				console.error(JSON.stringify(e));
			}
		});
	} else {
		const mssg = "ERROR: "+to+" is not formatted properly for an email address. Emails not sent.";
		$.sweetModal({
			content: mssg,
			icon: $.sweetModal.ICON_ERROR
		});
		console.error(JSON.stringify(e));
	}
}

function insertLastName() {
	var name = "[last_name]";
	appendToMessage(name);
}

function insertFirstName() {
	var name = "[first_name]";
	appendToMessage(name);
}

function insertName() {
	var name = "[name]";
	appendToMessage(name);
}

function insertMentoringLink() {
	var name = "[mentoring_agreement]";
	appendToMessage(name);
}

function insertPortalLink() {
	var name = "[scholar_portal]";
	appendToMessage(name);
}

function insertSurveyLink(selectId) {
	const form = $('#'+selectId+' option:selected').val();
	if (form) {
		const surveyLink = "[survey_link_"+form+"]";
		appendToMessage(surveyLink);
	} else {
		alert("You must specify a survey in order to insert a link.");
	}
}

// append at cursor
function appendToMessage(str) {
	var range = quill.getSelection();
	if (range && range.index) {
		quill.insertText(range.index, str);
	} else {
		quill.insertText(0, str);
	}
}

function addCheckboxHandlers(pid) {
	// no post variables in updateAll - because not used
	$('input[type=checkbox]').on('change', function() { updateAll(this, pid); });
}

function disableEmailSetting() {
	$("[name=enabled]").val("false");
}

function enableEmailSetting() {
	$("[name=enabled]").val("true");
}
