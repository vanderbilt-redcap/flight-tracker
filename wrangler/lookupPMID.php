<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

$pmid = REDCapManagement::sanitize($_POST['pmid']);
$pmid = preg_replace("/^PMID/i", "", $pmid);
if (is_numeric($pmid)) {
	$pubmed = Publications::downloadPMID($pmid);
	echo $pubmed->formDisplay();
}
