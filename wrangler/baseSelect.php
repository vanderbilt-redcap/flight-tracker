<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");

define("IMG_SIZE", Citation::getImageSize());

require_once(dirname(__FILE__)."/css.php");
?>
<script>
$(document).ready(function() {
	$('#search').keydown(function(e) {
		if ((e.keyCode == 13) || (e.keyCode == 9)) {
			var url = window.location.href;
			var pageWithGet = url.replace(/^.+\//, "");
			var page = "<?= $_GET['page'] ?>.php";
			var name = $('#search').val();
			search(page, '#searchDiv', name);
		}
	});
});

function includeCitation(citation) {
	if (citation) {
		var pmid = getPMID(citation);
		if (pmid) {
			presentScreen("Saving...");
			$.post("savePubs.php?pid="+getPid(), { record_id: '<?= $_GET['record'] ?>', pmid: pmid }, function(data) {
				console.log("saveComplete "+JSON.stringify(data));
				clearScreen();
				if (data['error']) {
					makeNote(data['error']);
				} else if (data['errors']) {
					makeNote(data['errors']);
				} else {
					makeNote();
				}
			});
		}
		if (pmid) {
			id = "PMID" + pmid;
		} else {
			id = "ID" + maxid;
		}

		var js = "if ($(this).attr(\"src\") == \"checked.png\") { $(\"#"+id+"\").val(\"exclude\"); $(this).attr(\"src\", \"unchecked.png\"); } else { $(\"#"+id+"\").val(\"include\"); $(this).attr(\"src\", \"checked.png\"); }";

		$("#manualCitation").val("");
	} else {
		alert("Please specify a citation!");
	}
}
</script>
<?php

function getSearch() {
	return Publications::getSearch();
}

function getSelectRecord() {
	return Publications::getSelectRecord();
}
