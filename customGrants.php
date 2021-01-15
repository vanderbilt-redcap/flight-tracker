<?php

use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Links.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/CareerDev.php");

if ($_GET['record']) {
	$record = $_GET['record'];
	$pid = $_GET['pid'];

	$redcapData = Download::fieldsForRecords($token, $server, CareerDev::$customFields, array($record));
	$max = 0;
	foreach ($redcapData as $row) {
		if (($row['redcap_repeat_instrument'] == "custom_grant") && ($row['redcap_repeat_instance'] > $max)) {
			$max = $row['redcap_repeat_instance'];
		}
	}

	header("Location: ".Links::makeFormUrl($pid, $record, $event_id, "custom_grant", $max + 1));
} else {
	$names = Download::names($token, $server);

	echo "<h1>Custom Grants</h1>\n";
	echo "<h2>Please Select a Record</h2>\n";

	echo "<p class='centered'><select id='record'>\n";
	echo "<option value=''>---SELECT---</option>\n";
	foreach ($names as $recordId => $name) {
		echo "<option value='$recordId'>$name</option>\n";
	}
	echo "</select></p>\n";
?>
<script>
	$(document).ready(function() {
		$('#record').change(function() {
			var record = $(this).val();
			window.location.href = '<?= CareerDev::link("customGrants.php") ?>&record='+record;
		});
	});
</script>
<?php
}
