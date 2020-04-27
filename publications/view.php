<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

$names = Download::names($token, $server);

echo "<h1>View Publications</h1>\n";
if ($_GET['record']) {
    if ($_GET['record'] == "all") {
        $records = Download::recordIds($token, $server);
    } else {
        $records = array($_GET['record']);
    }
    foreach ($records as $record) {
        $name = $names[$record];
        $metadata = Download::metadata($token, $server);
        $redcapData = Download::fieldsForRecords($token, $server, CareerDev::$citationFields, array($record));
        $pubs = new Publications($token, $server, $metadata);
        $pubs->setRows($redcapData);
        $citations = array();
        $citations["Confirmed Publications"] = $pubs->getCitationCollection("Included");
        $citations["Publications yet to be Confirmed"] = $pubs->getCitationCollection("Not Done");

        echo makePublicationSearch($names);
        foreach ($citations as $header => $citColl) {
            echo "<h2>$header (" . $citColl->getCount() . ")</h2>\n";
            $citations = explode("\n", $citColl->getCitationsAsString(TRUE));
            echo "<div class='centered' style='max-width: 800px;'>\n";
            if ($citColl->getCount() == 0) {
                echo "<p class='centered'>No citations.</p>\n";
            } else {
                foreach ($citations as $citation) {
                    echo "<p style='text-align: left;'>$citation</p>\n";
                }
            }
            echo "</div>\n";
        }
    }

	echo "<br><br><br>";
	
} else {
	echo makePublicationSearch($names);
}

function makePublicationSearch($names) {
	$html = "";
	$html .= "<h2>View a Scholar's Publications</h2>\n";
	$html .= "<p class='centered'><a href='".CareerDev::link("publications/view.php")."&record=all'>View All Scholars' Publications</a></p>\n";
	$html .= "<p class='centered'><select onchange='window.location.href = \"".CareerDev::link("publications/view.php")."&record=\" + $(this).val();'><option value=''>---SELECT---</option>\n";
	foreach ($names as $recordId => $name) {
		$html .= "<option value='$recordId'";
		if ($_GET['record'] && ($_GET['record'] == $recordId)) {
			$html .= " selected";
		}
		$html .= ">$name</option>\n";
	}
	$html .= "</select></p>\n";
	return $html;
}
