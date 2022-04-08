<?php

require_once("../charts/baseWeb.php");

?>
<script>
function expand(address, type) {
	$('#links').hide();
	$('#textarea').show();

	if (!type) {
		type = $('#type').val();
	}

	$.POST("backup.php?type="+type, {'redcap_csrf_token': getCSRFToken()}, function(data) {
		if (data == 'success') {
			var ts = Date.now();
			$.ajax("runCommand.php?log="+ts+".log", {
				data: { 'type': type, 'command': address, 'redcap_csrf_token': getCSRFToken() },
				type: 'POST',
				success: function(data) {
					$('#ta').html(data);
					function readLog(ts) {
						$.ajax("readLogs.php?log="+ts+".log", {
						    data: { 'redcap_csrf_token': getCSRFToken() },
                            type: 'POST',
							success: function(data) {
								$('#ta').html(data);
								if (!data.match(/Done\./)) {
									setTimeout(function() {
										readLog(ts);
									}, 5000);
								}
							}
						});
					}
					readLog(ts);
				}
			});
		} else {
			alert("Failed: "+data);
		}
	});
}
</script>
<h1>CareerDev Dashboard</h1>


<p style='display: none;' id='textarea'><textarea id='ta'></textarea></p>
<div id='links'>
<p><select id='type'>
<option value='prod'>Production</option>
<option value='prodtest'>ProdTest</option>
<option value='test'>REDCapTest</option>
</select></p>
<p><a href='javascript:;' onclick='expand("../drivers/6_makeSummary.php");'>Make Summaries</a></p>
<p><a href='javascript:;' onclick='expand("../coeusPullCron.php");'>Update COEUS</a></p>
<p><a href='javascript:;' onclick='expand("../drivers/7_copyToTest.php", "prodtest");'>Copy Main Project to ProdTest</a></p>
<p><a href='javascript:;' onclick='expand("../drivers/9_clearInstances.php");'>Clean out repeatable instances</a></p>
</div>
