function commitOrder() {
	// make configuration
	var config = {};
	var order = {}; 
	$('.sortable').each(function(idx, ul) {
		var fieldName  = $(ul).id();
		config[fieldName] = {};
		order[fieldName] = [];
		$(ul).find("li").each(function(liIdx, li) {
			var sourceAndField = $(li).html();
			var source = "";
			if (sourceAndField.match(/^.+ \[.+\]$/)) {
				source = sourceAndField.replace(/ \[.+\]$/, "");
			}
			var sourceField = $(li).id();
			if ($(li).attr("type") == "custom") {
				config[fieldName][sourceField] = source;
				order[fieldName].push(sourceField);
			} else if ($(li).attr("type") == "original") {
				config[fieldName][source] = source;
				order[fieldName].push(source);
			}
		});
	});

	// upload
	var h = { config: JSON.stringify(config), order: JSON.stringify(order) };
	$.post("config.php?pid="+getPid()+"&uploadOrder", h, function(data) {
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
	var ul = $(ob).parent().parent().find("ul");
	var p = $(ob).parent();
	var source = p.find(".newSortableSource :selected");
	var fields = p.find(".newSortableField");
	if ($(fields).length == 1) {
		if (($(fields).val() !== "") && ($(source).val() !== "")) {
			// the following line is also replicated in config.php; please change it in both places
			ul.append(makeLI($(fields).val(), "custom", $(source).text(), $(fields).val()));
		}
	} else {
		// multiple
		var selectedFields = [];
		// maxDegrees must be declared in the JS
		for (var i=1; i <= maxDegrees; i++) {
			var field = p.find(".newSortableField[index="+i+"]");
			if (($(field).val() !== "") && ($(source).val() !== "")) {
				selectedFields.push(field.val());
			}
		}
		var fieldID = selectedFields.join(getDelim());
		var fieldText = selectedFields.join(", ");
		ul.append(makeLI(fieldID, "custom", $(source).text(), fieldText));
	}
}

function checkButtonVisibility(ob) {
	var ul = $(ob).parent();
	var source = ul.find(".newSortableSource");
	var field = ul.find(".newSortableField");
	var button = ul.find("button");
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
