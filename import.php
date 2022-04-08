<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/Application.php");

if (isset($_GET['import']) && isset($_FILES['csv'])) {
	$html = \Vanderbilt\FlightTrackerExternalModule\importCustomFields($_FILES['csv']['tmp_name'], $token, $server, $pid);
	echo $html;
}
?>

<h1>Import Custom Fields</h1>

<div style='max-width: 800px; margin: 0 auto;'>
	<p class='centered'>This feature allows you to update your forms from CSVs. Please review the following rules before uploading:</p>
	<ol>
		<li>Format the CSV (Comma-Separated Value) spreadsheet with the field names on the top row (e.g., record_id, identifier_first_name, identifier_last_name - from the <a href='<?= CareerDev::getREDCapDir()."/Design/data_dictionary_upload.php?pid=".$pid ?>'>REDCap Data Dictionary</a>).</li>
		<li>The first column must be record_id -or- the first two columns must contain identifier_first_name and identifier_last_name.<br>
			<img src='<?= Application::link("img/importByRecord.png") ?>' alt='Import by record_id'> or <img src='<?= Application::link("img/importByName.png") ?>' alt='Import by Name'>
		</li>
		<li>If a name or record_id is not matched to the database, a new record will be created.</li>
		<li>If you desire to update fields on a repeating form, specify those in a separate CSV and put each instance of the repeating form on its own line. The script will automatically append them to list of repeating instances.</li>
		<li>If you wish to modify a repeating form, please do not use this feature. Use REDCap's Data Forms (accessible via Add/Edit Records) instead.</li>
	</ol>

	<form method='POST' action='<?= Application::link("import.php")."&import" ?>' enctype='multipart/form-data'>
        <?= Application::generateCSRFTokenHTML() ?>
		<p class='centered'>CSV File: <input type='file' name='csv'></p>
		<p class='centered'><button>Upload File</button></p>
	</form>
</div>
