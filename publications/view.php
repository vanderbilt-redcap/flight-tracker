<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Altmetric;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Altmetric.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../Application.php");

$names = Download::names($token, $server);

echo "<h1>View Publications</h1>\n";
echo makeCustomizeTable();

if ($_GET['record']) {
    if ($_GET['record'] == "all") {
        $records = Download::recordIds($token, $server);
    } else {
        $records = array($_GET['record']);
    }
    $trainingStarts = Download::oneField($token, $server, "summary_training_start");
    $trainingEnds = Download::oneField($token, $server, "summary_training_end");
    $confirmed = "Confirmed Publications";
    $notConfirmed = "Publications yet to be Confirmed";
    $citations = [$confirmed => [], $notConfirmed => []];
    $dates = [];
    $metadata = Download::metadata($token, $server);
    foreach ($records as $record) {
        $name = $names[$record];
        $redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), array($record));
        $pubs = new Publications($token, $server, $metadata);
        $pubs->setRows($redcapData);
        $included = $pubs->getCitationCollection("Included");
        $notDone = $pubs->getCitationCollection("Not Done");

        if ($_GET['trainingPeriodPlusDays']) {
            $trainingStart = $trainingStarts[$record];
            $trainingEnd = $trainingEnds[$record];
            if ($trainingStart) {
                $startTs = strtotime($trainingStart);
                if ($trainingEnd) {
                    $endTs = strtotime($trainingEnd);
                    $daysTimespan = $_GET['trainingPeriodPlusDays'] * 24 * 3600;
                    $endTs += $daysTimespan;
                } else {
                    # currently training or do not have end date?
                    $endTs = time();
                }
                $dates[$record] = date("m-d-Y", $startTs)." - ".date("m-d-Y", $endTs);

                $included->filterForTimespan($startTs, $endTs);
                $notDone->filterForTimespan($startTs, $endTs);
            } else {
                # do not filter
                $dates[$record] = "Training period not recorded";
            }
        }

        $citations[$confirmed][$record] = $included;
        $citations[$notConfirmed][$record] = $notDone;
    }

    echo makePublicationSearch($names);
    foreach ($citations as $header => $citColls) {
        $total = totalCitationColls($citColls);
        echo "<h2>$header (" . REDCapManagement::pretty($total) . ")</h2>\n";
        echo "<div class='centered' style='max-width: 800px;'>\n";
        if ($total == 0) {
            echo "<p class='centered'>No citations.</p>\n";
        } else {
            foreach ($citColls as $record => $citColl) {
                $name = $names[$record];
                $date = $dates[$record];
                $header = "<p class='centered'>$name (".$citColl->getCount().")";
                if ($date) {
                    $header .= "<br>$date";
                }
                $header .= "</p>\n";
                if ($citColl->getCount() > 0) {
                    echo $header;
                }

                $citations = $citColl->getCitations();
                foreach ($citations as $citation) {
                    echo "<p style='text-align: left;'>";
                    if (isset($_GET['altmetrics'])) {
                        echo $citation->getImage("left");
                    }
                    echo $citation->getCitationWithLink();
                    echo "</p>\n";
                }
            }
        }
        echo "</div>\n";
    }
	echo "<br><br><br>";
} else {
	echo makePublicationSearch($names);
}

function totalCitationColls($citColls) {
    $total = 0;
    foreach ($citColls as $citColl) {
        $total += $citColl->getCount();
    }
    return $total;
}

function makeExtraURLParams($exclude = []) {
    $additionalParams = "";
    $expected = ["record", "altmetrics", "trainingPeriodPlusDays"];
    foreach ($_GET as $key => $value) {
        if (isset($_GET[$key]) && in_array($key, $expected) && !in_array($key, $exclude)) {
            if ($value === "") {
                $additionalParams .= "&".$key;
            } else {
                $additionalParams .= "&$key=".urlencode($value);
            }
        }
    }
    return $additionalParams;
}

function makeCustomizeTable() {
    $html = "";
    $style = "style='width: 250px; padding: 15px; vertical-align: top;'";
    $defaultDays = "";
    if (isset($_GET['trainingPeriodPlusDays']) && is_numeric($_GET['trainingPeriodPlusDays'])) {
        $defaultDays = $_GET['trainingPeriodPlusDays'];
    }
    $fullURL = Application::link("publications/view.php").makeExtraURLParams(["trainingPeriodPlusDays"]);
    list($url, $trainingPeriodParams) = REDCapManagement::splitURL($fullURL);

    $html .= "<table class='centered'>\n";
    $html .= "<tr>\n";
    $html .= "<td colspan='2' $style><h2 class='nomargin'>Customize</h2></td>\n";
    $html .= "</tr>\n";
    $html .= "<tr>\n";
    $html .= "<td $style>".Altmetric::makeClickText()."</td>\n";
    $html .= "<td $style><form action='$url' method='GET'>";
    $html .= REDCapManagement::makeHiddenInputs($trainingPeriodParams);
    $html .= "<h4>Show Pubs During Training</h4>";
    $html .= "<p class='centered'>Additional Days: <input type='number' name='trainingPeriodPlusDays' style='width: 60px;' value='$defaultDays'><br><button>Re-Configure</button></p>";
    $html .= "</form></td>\n";
    $html .= "</tr>\n";
    $html .= "</table>\n";

    return $html;
}

function makePublicationSearch($names) {
    $html = "";
	$html .= "<h2>View a Scholar's Publications</h2>\n";
	$html .= "<p class='centered'><a href='".CareerDev::link("publications/view.php")."&record=all".makeExtraURLParams(["record"])."'>View All Scholars' Publications</a></p>\n";
    $html .= "<p class='centered'><select onchange='window.location.href = \"".CareerDev::link("publications/view.php").makeExtraURLParams(["record"])."&record=\" + $(this).val();'><option value=''>---SELECT---</option>\n";
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
