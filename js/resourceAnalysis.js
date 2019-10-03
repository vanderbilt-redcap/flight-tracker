function displayResource() {
	var val = $('#resource').val();
	var days = $('#days').val();
	var html = "<hr>";
	if (val && days && !isNaN(days) && (typeof(dateTimes[val]) != "undefined")) {
		// calculating in PHP time even though in JS
		var span = days * 24 * 3600;
		var i, j;
		var rec, foundInRec;
		var begin, end, currTime;
		var cnt = {};
		var total = {};
		var group, outputType;
		for (group in records) {
			cnt[group] = {};
			total[group] = {};
			for (outputType in times) {
				cnt[group][outputType] = new Array();
				total[group][outputType] = new Array();
				for (var j = 0; j < dateTimes[val].length; j++) {
					cnt[group][outputType].push(0);
					total[group][outputType].push(0);
				}
				if (typeof records[group][val] != "undefined") {
					for (i = 0; i < records[group][val].length; i++) {
						rec = records[group][val][i];
						for (var j = 0; j < dateTimes[val].length; j++) {
							begin = dateTimes[val][j];
							end = dateTimes[val][j] + span;

							foundInRec = false;
							if (typeof times[outputType][rec] != "undefined") {
								for (k = 0; j < times[outputType][rec].length; j++) {
									currTime = times[outputType][rec][k];
									if (currTime && (currTime >= begin) && (currTime < end)) {
										foundInRec = true;
										break;
									}
								}
							}
							total[group][outputType][j]++;
							if (foundInRec) {
								cnt[group][outputType][j]++;
							}
						}
					}
				}
			}
		}
		var definition = {};
		definition["Control"] = "Scholars in the population who <b>did not attend</b> the given resource.";
		definition["Experimental"] = "Scholars in the population who <b>did attend</b> the given resource.";
		html += "<h2>Probability Squares</h2>";
		html += "<table style='border: 1px solid #888888; border-radius: 10px; padding: 4px; margin-left: auto; margin-right: auto;'>";
		html += "<tr>";
		html += "<td></td>";
		html += "<th>Control<br><span class='small'>Did <b>not</b> Attend</span></th>";
		html += "<th>Experimental<br><span class='small'>Attended</span></th>";
		html += "<th>Odds Ratio<br><a href='https://www.ncbi.nlm.nih.gov/pmc/articles/PMC2938757/' target='_NEW'><span class='small'>Explained</span></a></th>";
		html += "</tr>";
		html += "<tr>";
		html += "<th>Got a Grant<br><span class='small'>Within "+days+" Days</th>";
		html += "<td style='border: 1px dotted black; vertical-align: middle; text-align: center;'>"+cnt['Control']['Grant']+" / "+total['Control']['Grant']
		if (total['Control']['Grant'] > 0) {
			html += "<br><span style='font-size: 20px; font-weight: bold;'>"+(Math.floor(cnt['Control']['Grant'] * 1000 / total['Control']['Grant']) / 10)+"%</span>";
		}
		html += "</td>"
		html += "<td style='border: 1px dotted black; vertical-align: middle; text-align: center;'>"+cnt['Experimental']['Grant']+" / "+total['Experimental']['Grant']
		if (total['Experimental']['Grant'] > 0) {
			html += "<br><span style='font-size: 20px; font-weight: bold;'>"+(Math.floor(cnt['Experimental']['Grant'] * 1000 / total['Experimental']['Grant']) / 10)+"%</span>";
		}
		html += "</td>"
		html += "<th style='font-size: 20px;'>"+(Math.floor(100 * cnt['Experimental']['Grant'] * (total['Control']['Grant'] - cnt['Control']['Grant']) / ((total['Experimental']['Grant'] - cnt['Experimental']['Grant']) * cnt['Control']['Grant'])) / 100)+"</th>";
		html += "</tr>";
		html += "<tr>";
		html += "<th>Did not Get a Grant<br><span class='small'>Within "+days+" Days</th>";
		html += "<td style='border: 1px dotted black; vertical-align: middle; text-align: center;'>"+(total['Control']['Grant'] - cnt['Control']['Grant'])+" / "+total['Control']['Grant']
		if (total['Control']['Grant'] > 0) {
			html += "<br><span style='font-size: 20px; font-weight: bold;'>"+(Math.floor((total['Control']['Grant'] - cnt['Control']['Grant']) * 1000 / total['Control']['Grant']) / 10)+"%</span>";
		}
		html += "</td>"
		html += "<td style='border: 1px dotted black; vertical-align: middle; text-align: center;'>"+(total['Experimental']['Grant'] - cnt['Experimental']['Grant'])+" / "+total['Experimental']['Grant']
		if (total['Experimental']['Grant'] > 0) {
			html += "<br><span style='font-size: 20px; font-weight: bold;'>"+(Math.floor((total['Experimental']['Grant'] - cnt['Experimental']['Grant']) * 1000 / total['Experimental']['Grant']) / 10)+"%</span>";
		}
		html += "</td>"
		html += "</tr>";
		html += "<tr><td colspan='4'>&nbsp;</td></tr>";
		html += "<tr>";
		html += "<th>Published a Paper<br><span class='small'>Within "+days+" Days</span></th>";
		html += "<td style='border: 1px dotted black; vertical-align: middle; text-align: center;'>"+cnt['Control']['Publication']+" / "+total['Control']['Publication']
		if (total['Control']['Publication'] > 0) {
			html += "<br><span style='font-size: 20px; font-weight: bold;'>"+(Math.floor(cnt['Control']['Publication'] * 1000 / total['Control']['Publication']) / 10)+"%</span>";
		}
		html += "</td>"
		html += "<td style='border: 1px dotted black; vertical-align: middle; text-align: center;'>"+cnt['Experimental']['Publication']+" / "+total['Experimental']['Publication']
		if (total['Experimental']['Publication'] > 0) {
			html += "<br><span style='font-size: 20px; font-weight: bold;'>"+(Math.floor(cnt['Experimental']['Publication'] * 1000 / total['Experimental']['Publication']) / 10)+"%</span>";
		}
		html += "</td>"
		html += "<th style='font-size: 20px;'>"+(Math.floor(100 * cnt['Experimental']['Publication'] * (total['Control']['Publication'] - cnt['Control']['Publication']) / ((total['Experimental']['Publication'] - cnt['Experimental']['Publication']) * cnt['Control']['Publication'])) / 100)+"</th>";
		html += "</tr>";
		html += "<tr>";
		html += "<th>Did not Publish a Paper<br><span class='small'>Within "+days+" Days</span></th>";
		html += "<td style='border: 1px dotted black; vertical-align: middle; text-align: center;'>"+(total['Control']['Publication'] - cnt['Control']['Publication'])+" / "+total['Control']['Publication']
		if (total['Control']['Publication'] > 0) {
			html += "<br><span style='font-size: 20px; font-weight: bold;'>"+(Math.floor((total['Control']['Publication'] - cnt['Control']['Publication']) * 1000 / total['Control']['Publication']) / 10)+"%</span>";
		}
		html += "</td>"
		html += "<td style='border: 1px dotted black; vertical-align: middle; text-align: center;'>"+(total['Experimental']['Publication'] - cnt['Experimental']['Publication'])+" / "+total['Experimental']['Publication']
		if (total['Experimental']['Publication'] > 0) {
			html += "<br><span style='font-size: 20px; font-weight: bold;'>"+(Math.floor((total['Experimental']['Publication'] - cnt['Experimental']['Publication']) * 1000 / total['Experimental']['Publication']) / 10)+"%</span>";
		}
		html += "</td>"
		html += "</tr>";
		html += "</table>";
		for (group in cnt) {
			html += "<hr>";
			html += "<h2 style='margin-bottom: 0px;'>The "+group+" Group</h2>";
			html += "<p style='font-style: italic; margin-top: 0px;'>"+definition[group]+"</p>";
			for (outputType in cnt[group]) {
				html += "<h4 style='margin-bottom: 0px;'>The "+group+" Group: "+outputType+"</h4>";
				for (var j = 0; j < total[group][outputType].length; j++) {
					if (total[group][outputType][j] > 0) {
						html += "<p style='margin-top: 0px;'>The "+group+" Group has <b>"+cnt[group][outputType]+" / "+total[group][outputType][j]+" ("+(Math.floor(cnt[group][outputType][j] * 1000 / total[group][outputType][j]) / 10)+"%)</b> with at least one "+outputType+" in "+days+" days after the resource on "+dates[val][j]+".</p>";
					} else {
						html += "<p style='margin-top: 0px;'>The "+group+" Group on "+dates[val][j]+" has <b>no members</b>.<br>(In other words, no one in the population of scholars attended the resource on that day.)";
					}
				}
			}
		}
	} else {
		if (!val) {
			html += "<p>Please select a resource.</p>";
		}
		if (!days || isNaN(days)) {
			html += "<p>Please specify the number of days after the resource to count for.</p>";
		}
		if (typeof(dates) != "undefined") {
			html += "<p>The selected resource did not have a date specified for it at its creation. Therefore, statistics cannot be calculated.</p>"; 
		}
	}
	$("#results").html(html);
}
