<?php

use Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (is_numeric($pid)) {
	$dataTable = Application::getDataTable($pid);
	$sql = "DELETE FROM $dataTable WHERE project_id = '$pid' AND (field_name LIKE 'promotion_%' OR field_name = 'position_change_complete')";
	db_query($sql);
	if ($error = db_error()) {
		echo "ERROR: ".$error;
	} else {
		echo "Done.";
	}
} else {
	echo "No pid specified.";
}
