function showSignIn() {
	if ($("#resource").val() && $("#date").val()) {
		$("#attendance").show();
		$("#note").hide();
	} else {
		$("#attendance").hide();
		$("#note").show();
	}
}

function showResource() {
	var resource = $("#resource").val();
	var date = $("#date").val();
	if (resource && date) {
		$("#prior_attendance_title").html("Already Signed In");
		$("#prior_attendance").html(resources[resource][date]);
	} else {
		$("#prior_attendance").html("");
		$("#prior_attendance_title").html("&nbsp;");
	}
}

function recalculateNames(lines) {
	lines = lines.split(/\n/);
	var txtOut = "";
	for (var i = 0; i < lines.length; i++) {
		if (lines[i]) {
			var line = lines[i].toLowerCase();
			var matchedName = "";

			for (var rec in names) {
				var name = {};
				name['f'] = names[rec]['first'].split(/[\s\-\(\)]/);
				name['l'] = names[rec]['last'].split(/[\s\-\(\)]/);

				// filter out empty places
				for (var place in name) {
					var ary = new Array();
					for (var k = 0; k < name[place].length; k++) {
						if (name[place][k]) {
							ary.push(name[place][k]);
						}
					}
					name[place] = ary;
				}

				var matches = new Array();
				for (var place in name) {
					for (var k = 0; k < name[place].length; k++) {
						var re = new RegExp(name[place][k].toLowerCase());
						if (line.match(re)) {
							matches.push(place);
							break;
						}
					}
				}

				// at least 2 name matches on first or last
				if (matches.length >= 2) {	
					matchedName = names[rec]['first'] + " " + names[rec]['last'];
				}
			}
			txtOut += matchedName + "\n";
		} else {
			txtOut += "\n";
		} 
	}
	return txtOut;
}
