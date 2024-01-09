function commitOrder() {
	// make configuration
	const config = {};
	const order = {};
	$('.sortable').each(function(idx, ul) {
		const fieldName  = $(ul).attr("id");
		config[fieldName] = {};
		order[fieldName] = [];
		$(ul).find("li").each(function(liIdx, li) {
			const sourceAndField = $(li).html();
			let source = "";
			if (sourceAndField.match(/^.+ \[.+\]$/)) {
				source = sourceAndField.replace(/ \[.+\]$/, "");
			}
			const sourceField = $(li).attr("id");
			if ($(li).attr("type") === "custom") {
				config[fieldName][sourceField] = source;
				order[fieldName].push(sourceField);
			} else if ($(li).attr("type") === "original") {
				config[fieldName][source] = source;
				order[fieldName].push(source);
			}
		});
	});

	// upload
	const h = { config: JSON.stringify(config), order: JSON.stringify(order) };
	presentScreen("Saving...");
	$.post(getPageUrl("config.php")+"&uploadOrder", h, function(data) {
		alert("Settings saved!");
		clearScreen();
		if (typeof(data) == "string") {
			console.log(data);
		} else {
			console.log(JSON.stringify(data));
		}
	});
}

function makeLI(fieldID, sourceTypeForField, sourceName, fieldText) {
	return "<li class='ui-state-default centered nobullets' id='"+fieldID+"' type='"+sourceTypeForField+"'>"+sourceName+" ["+fieldText+"]</li>";
}

function addCustomField(ob) {
	const ul = $(ob).parent().parent().find("ul");
	const p = $(ob).parent();
	const source = p.find(".newSortableSource :selected");
	const fields = p.find(".newSortableField");
	if ($(fields).length === 1) {
		if (($(fields).val() !== "") && ($(source).val() !== "")) {
			// the following line is also replicated in config.php; please change it in both places
			ul.append(makeLI($(fields).val(), "custom", $(source).text(), $(fields).val()));
		}
	} else {
		// multiple
		const selectedFields = [];
		// maxDegrees must be declared in the JS
		for (let i=1; i <= maxDegrees; i++) {
			const field = p.find(".newSortableField[index="+i+"]");
			if (($(field).val() !== "") && ($(source).val() !== "")) {
				selectedFields.push(field.val());
			}
		}
		const fieldID = selectedFields.join(getDelim());
		const fieldText = selectedFields.join(", ");
		ul.append(makeLI(fieldID, "custom", $(source).text(), fieldText));
	}
}

function checkButtonVisibility(ob) {
	const ul = $(ob).parent();
	const source = ul.find(".newSortableSource");
	const field = ul.find(".newSortableField");
	const button = ul.find("button");
	if (($(field).val() !== "") && ($(source).val() !== "")) {
		$(button).show();
	} else {
		$(button).hide();
	}
}

// coordinated with config.php's getDelim function
function getDelim() {
	return "|";
}

// coordinated with config.php's makeAssociativeArray function
function checkForNextField(variable, i) {
	const numRows = $('.'+variable+'___row').length;
	if (parseInt(i) === numRows) {
		const code = $('#'+variable+'___'+i+'___code').val();
		const text = $('#'+variable+'___'+i+'___text').val();
		if (code && text) {
			const nextI = parseInt(i) + 1;
			const id = variable+'___'+nextI+'___';
			const newCodeInput = "<input type='text' id='"+id+"code' name='"+id+"code' value='' onBlur='checkForNextField(\""+variable+"\", \""+nextI+"\");'/>";
			const newTextInput = "<input type='text' id='"+id+"text' name='"+id+"text' value='' onBlur='checkForNextField(\""+variable+"\", \""+nextI+"\");'/>";
			$('#'+variable+'___'+i+'___tr').after("<tr id='"+id+"tr' class='"+variable+"___row'><td>"+newCodeInput+"</td><td>"+newTextInput+"</td></tr>");
		}
	}
}