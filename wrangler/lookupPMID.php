<?php

require_once(dirname(__FILE__)."/../classes/Publications.php");

$pmid = $_POST['pmid'];
$pmid = preg_replace("/^PMID/i", "", $pmid);
if (is_numeric($pmid)) {
	$pubmed = Publications::downloadPMID($pmid);
	echo $pubmed->formDisplay();
}
