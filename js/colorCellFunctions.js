function transformColumn() {
	if ($('table').length > 3) {
		$('table').each(function(i, ob) {
			if ($(ob).attr('id') == "report_table") {
				transformTable(ob);
			}
		});
	} else {
		setTimeout(function() { transformColumn(); }, 500);
	}
}

function transformTable(ob) {
	$(ob).find("thead tr th").each(function(i2, ob2) {
		if ($(ob2).attr("aria-label").match(/summary_first_k_to_first_r01/)) {
			findChildrenForConversion(ob, i2+1);
		}
	});
}

function getAge() {
	return 5;
}

function getRed() {
	return "ffb7b7";
}

function getGreen() {
	return "c3ffc3";
}

function findChildrenForConversion(ob, i) {
	$(ob).find("tbody tr td:nth-child("+i+")").each(function(i3, ob3) {
		var val3 = $(ob3).html();
		if (val3 != "") {
			colorCell(ob3, val3);
		} else {
			$(ob).find("thead tr th").each(function(i4, ob4) {
				if ($(ob4).attr("aria-label").match(/summary_first_external_k/)) {
					findChildrenForExtK(ob3, i4+1);
				}
			});
		}
	});
}

function findChildrenForExtK(ob, i) {
	$(ob).parent().find("td:nth-child("+i+")").each(function(i5, ob5) {
		var val5 = $(ob5).html();
		if (val5 != "") {
			colorCellByDate(ob5, val5);
		}
	});
}

function colorCellByDate(ob, val) {
	var threshold = $.now() - 3600 * 1000 * 24 * 365 * getAge() + 24 * 3600 * 1000;
	var nodes = val.split(/\-/);
	var d = new Date(nodes[0], nodes[1], nodes[2], 12, 0, 0, 0);
	var timestamp = d.getTime();
	if (timestamp < threshold) {
		$(ob).css({'background-color': getRed()});
	}
}

function colorCell(ob, val) {
	if (val > getAge) {
		$(ob).css({'background-color': getRed()});
	} else {
		$(ob).css({'background-color': getGreen()});
	}
}
