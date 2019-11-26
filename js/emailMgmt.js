function enqueue(ob, record) {
	var date = $(ob).val();
	var returnDiv = $(ob).parent().find(".saved");
	if (date.match(/^\d\d\d\d-\d+-\d+$/)) {
		$.post("saveSetting.php?pid=<?= $pid ?>", { surveys_next_date: date, records: [record] }, function(str) {
			setObjectText(returnDiv, str);
		});
	} else {
		var str = "ERROR: Improper Format (yyyy-mm-dd for '"+date+"')!";
		setObjectText(returnDiv, str);
		$(ob).parent().parent().find(".unqueueButton").show();
	}
}

function clear(ob, record) {
	var returnDiv = $(ob).parent().find(".saved");
	$.post("saveSetting.php?pid=<?= $pid ?>", { surveys_next_date: date, records: [record] }, function(str) {
		setObjectText(returnDiv, str);
		$(ob).hide();
	});
}

function enqueueAll(ob) {
	var records = allRecords;
	var returnDiv = $("#note");
	var date = $(ob).val();
	if (date.match(/^\d\d\d\d-\d+-\d+$/)) {
		$.post("saveSetting.php?pid=<?= $pid ?>", { surveys_next_date: date, records: records }, function(str) {
			setObjectText(returnDiv, str);
			$(".unqueueButton").show();
		});
	} else {
		var str = "ERROR: Improper Format (yyyy-mm-dd for '"+date+"')!";
		setObjectText(returnDiv, str);
	}
}

function clearAll() {
	var records = allRecords;
	var returnDiv = $("#note");
	$.post("saveSetting.php?pid=<?= $pid ?>", { surveys_next_date: "", records: records }, function(str) {
		setObjectText(returnDiv, str);
		$(".unqueueButton").hide();
	});
}

function setObjectText(ob, str) {
	var classes = { "default":"green", "error":"red" };

	var searchStr;
	var currClass;

	for (searchStr in classes) {
		currClass = classes[searchStr];
		if (div.hasClass(currClass)) {
			div.removeClass(currClass);
		}
	}

	var defaultClass = "";
	for (searchStr in classes) {
		currClass = classes[searchStr];
		if (searchStr == "default") {
			defaultClass = currClass;
		} else {
			var regex = new RegExp(searchStr, "i");
			if (regex.test(str)) {
				div.addClass(currClass);
				div.html(str);
				div.show();
				return;
			}
		}
	}
	if (defaultClass) {
		if (Array.isArray(defaultClass)) {
			for (var i=0; i < defaultClass.length; i++) {
				currClass = defaultClass[i];
				div.addClass(currClass);
			}
		} else {
			div.addClass(defaultClass);
		}
	}
	div.html(str);
	div.show();
}

