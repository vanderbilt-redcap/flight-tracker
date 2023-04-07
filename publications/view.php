<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Altmetric;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\URLManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if ($_GET['record']) {
    if ($_GET['record'] == "all") {
        if ($_GET['cohort']) {
            $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
            $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
        } else {
            $records = Download::recordIds($token, $server);
        }
    } else {
        $recordIds = Download::recordIds($token, $server);
        $records = [Sanitizer::getSanitizedRecord($_GET['record'], $recordIds)];
    }
} else {
    $records = [];
}

$names = Download::names($token, $server);
if (isset($_GET['download']) && $records) {
    $metadata = Download::metadata($token, $server);
    list($citations, $dates) = getCitationsForRecords($records, $token, $server, $metadata);
    $html = makePublicationListHTML($citations, $names, $dates);
    Application::writeHTMLToDoc($html, "Publications ".date("Y-m-d").".docx");
    exit;
}
if (isset($_GET['grantCounts'])) {
    if (empty($records)) {
        $records = Download::records($token, $server);
    }
    $citationFields = ["record_id", "citation_pmid", "citation_include", "citation_grants"];
    $grantCounts = [];
    $redcapData = Download::fieldsForRecords($token, $server, $citationFields, $records);
    foreach ($redcapData as $row) {
        $grantStr = preg_replace("/\s+/", "", $row['citation_grants']);
        if ($row['citation_pmid'] && $grantStr && ($row['citation_include'] == "1")) {
            $awardNumbers = preg_split("/;/", $grantStr);
            foreach ($awardNumbers as $awardNo) {
                if ($awardNo) {
                    if (!isset($grantCounts[$awardNo])) {
                        $grantCounts[$awardNo] = 0;
                    }
                    $grantCounts[$awardNo]++;
                }
            }
        }
    }

        # slow
        // $pubs = new Publications($token, $server, $metadata);
        // $pubs->setRows($redcapData);
        // $recordGrantCounts = $pubs->getAllGrantCounts("Included");
        // foreach ($recordGrantCounts as $awardNo => $count) {
            // if (!isset($grantCounts[$awardNo])) {
                // $grantCounts[$awardNo] = 0;
            // }
            // $grantCounts[$awardNo] += $count;
        //}
    arsort($grantCounts);

    $fullURL = Application::link("publications/view.php").makeExtraURLParams(["trainingPeriodPlusDays", "begin", "end", "limitPubs"]);
    list($url, $trainingPeriodParams) = REDCapManagement::splitURL($fullURL);

    $html = "";
    $html .= "<form method='GET' action='$url'>";
    $html .= URLManagement::makeHiddenInputs($trainingPeriodParams, TRUE);
    $html .= "<h4>Pubs Associated With a Grant</h4>";
    $allSelected = "";
    $grants = Sanitizer::sanitizeArray($_GET['grants'] ?? []);
    if (empty($grants) || in_array("all", $grants)) {
        $allSelected = " selected";
    }
    $html .= "<p class='centered'>Hold down Shift or Control to select multiple:<br/><select id='grants' name='grants[]' multiple><option value='all'$allSelected>---ALL---</option>";
    foreach ($grantCounts as $awardNo => $count) {
        $selected = "";
        if (in_array($awardNo, $grants)) {
            $selected = " selected";
        }
        if ($_GET['record']) {
            $phrase = "citations";
            if ($count == 1) {
                $phrase = "citation";
            }
        } else {
            $phrase = "name-citation pairings";
            if ($count == 1) {
                $phrase = "name-citation pairing";
            }
        }
        $maxLen = 15;
        if (strlen($awardNo) > $maxLen) {
            $shownAwardNo = substr($awardNo, 0, $maxLen)."...";
        } else {
            $shownAwardNo = $awardNo;
        }
        $html .= "<option value='$awardNo'$selected>$shownAwardNo ($count $phrase)</option>";
    }
    $html .= "</select>";
    $html .= "<br><button>Re-Configure</button></p>";
    $html .= "</form>";
    echo $html;
    exit;
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$metadata = Download::metadata($token, $server);
$link = Application::link("publications/view.php")."&download".makeExtraURLParams();
echo "<h1>View Publications</h1>\n";
if (Application::hasComposer() && CareerDev::isVanderbilt()) {
    echo "<p class='centered'><a href='$link'>Download as MS Word doc</a></p>\n";
}
echo makeCustomizeTable($token, $server, $metadata);
if ($records) {
    list($citations, $dates) = getCitationsForRecords($records, $token, $server, $metadata);
    $record = (count($records) == 1) ? $records[0] : NULL;
    echo makePublicationSearch($names, $record);
    echo makePublicationListHTML($citations, $names, $dates);
} else {
	echo makePublicationSearch($names);
}

function getCitationsForRecords($records, $token, $server, $metadata) {
    $trainingStarts = Download::oneField($token, $server, "summary_training_start");
    $trainingEnds = Download::oneField($token, $server, "summary_training_end");
    $confirmed = "Confirmed Publications";
    $grants = Sanitizer::sanitizeArray($_GET['grants'] ?? []);
    if (!empty($grants) && !in_array("all", $grants)) {
        if (count($grants) == 1) {
            $confirmed .= " for Grant ".$grants[0];
        } else {
            $confirmed .= " for Grants ".REDCapManagement::makeConjunction($grants);
        }
    }
    $notConfirmed = "Publications Yet to be Confirmed";
    $citations = [$confirmed => [], $notConfirmed => []];
    $dates = [];
    $lastNames = Download::lastnames($token, $server);
    $firstNames = Download::firstnames($token, $server);
    foreach ($records as $record) {
        $redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), [$record]);
        $pubs = new Publications($token, $server, $metadata);
        $pubs->setRows($redcapData);
        if (isset($_GET['test'])) {
            Application::log($pubs->getCitationCount("Included")." citations included");
        }
        $notDone = $pubs->getCitationCollection("Not Done");

        if (!empty($grants) && !in_array("all", $grants)) {
            $included = new CitationCollection($record, $token, $server, "Filtered", [], $metadata, $lastNames, $firstNames);
            $recordCitations = $pubs->getCitationsForGrants($grants, "Included");
            foreach ($recordCitations as $citation) {
                $included->addCitation($citation);
            }
            if (isset($_GET['test'])) {
                Application::log("Record $record has filtered ".$included->getCount()." records");
            }
        } else {
            $included = $pubs->getCitationCollection("Included");
            if (isset($_GET['test'])) {
                Application::log("Record $record has downloaded ".$included->getCount()." records");
            }
        }

        if (isset($_GET['trainingPeriodPlusDays']) && is_numeric($_GET['trainingPeriodPlusDays'])) {
            $trainingDays = (int) $_GET['trainingPeriodPlusDays'];
            $trainingStart = $trainingStarts[$record];
            $trainingEnd = $trainingEnds[$record];
            if ($trainingStart) {
                $startTs = strtotime($trainingStart);
                if ($trainingEnd) {
                    $endTs = strtotime($trainingEnd);
                    $daysTimespan = $trainingDays * 24 * 3600;
                    $endTs += $daysTimespan;
                } else {
                    # currently training or do not have end date?
                    $endTs = time();
                }
                $included->filterForTimespan($startTs, $endTs);
                $notDone->filterForTimespan($startTs, $endTs);
                $dates[$record] = date("m-d-Y", $startTs)." - ".date("m-d-Y", $endTs);
            } else {
                # do not filter
                $dates[$record] = "Training period not recorded";
            }
        } else if (isset($_GET['begin']) && $_GET['begin']) {
            $startDate = Publications::adjudicateStartDate($_GET['limitPubs'] ?? "", $_GET['begin']);
            $startTs = $startDate ? strtotime($startDate) : 0;
            if (isset($_GET['end']) && $_GET['end']) {
                $endTs = strtotime(Sanitizer::sanitizeDate($_GET['end']));
            } else {
                $endTs = time();
            }
            if ($startTs && $endTs) {
                $included->filterForTimespan($startTs, $endTs);
                $notDone->filterForTimespan($startTs, $endTs);
                $dates[$record] = date("m-d-Y", $startTs)." - ".date("m-d-Y", $endTs);
            }
        }
        if (isset($_GET['author_first']) || isset($_GET['author_last']) || isset($_GET['author_middle'])) {
            $positions = [];
            foreach (["first", "middle", "last"] as $pos) {
                if ($_GET['author_'.$pos] == "on") {
                    $positions[] = $pos;
                }
            }
            $included->filterForAuthorPositions($positions, "{$firstNames[$record]} {$lastNames[$record]}");
            $notDone->filterForAuthorPositions($positions, "{$firstNames[$record]} {$lastNames[$record]}");
        }

        if (isset($_GET['test'])) {
            Application::log("Record $record has ".$included->getCount()." records");
        }

        $citations[$confirmed][$record] = $included;
        $citations[$notConfirmed][$record] = $notDone;
    }
    return [$citations, $dates];
}

function totalCitationColls($citColls) {
    $total = 0;
    foreach ($citColls as $citColl) {
        $total += $citColl->getCount();
    }
    return $total;
}

function makePublicationListHTML($citations, $names, $dates) {
    $html = "";
    foreach ($citations as $header => $citColls) {
        $total = totalCitationColls($citColls);
        $html .= "<h2>$header (" . REDCapManagement::pretty($total) . ")</h2>\n";
        $html .= "<div class='centered' style='max-width: 800px;'>\n";
        if ($total == 0) {
            $html .= "<p class='centered'>No citations.</p>\n";
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
                    $html .= $header;
                }

                $citations = $citColl->getCitations();
                foreach ($citations as $citation) {
                    $html .= "<p style='text-align: left; padding: 2px 0;'>";
                    if (isset($_GET['altmetrics'])) {
                        $html .= $citation->getImage("left");
                    }
                    $html .= $citation->getCitationWithLink(TRUE, TRUE);
                    $html .= "</p>\n";
                }
            }
        }
        $html .= "</div>\n";
    }
    $html .= "<br><br><br>";
    return $html;
}

function makeExtraURLParams($exclude = []) {
    $additionalParams = "";
    $expected = ["record", "altmetrics", "trainingPeriodPlusDays", "grants", "begin", "end", "cohort", "limitPubs", "author_first", "author_middle", "author_last"];
    foreach ($_GET as $key => $value) {
        if (isset($_GET[$key]) && in_array($key, $expected) && !in_array($key, $exclude)) {
            $key = Sanitizer::sanitize($key);
            if ($value === "") {
                $additionalParams .= "&".$key;
            } else {
                $additionalParams .= "&$key=".urlencode(Sanitizer::sanitize($value));
            }
        }
    }
    return $additionalParams;
}

function makeCustomizeTable($token, $server, $metadata) {
    if (isset($_GET['author_first']) || isset($_GET['author_middle']) || isset($_GET['author_last'])) {
        $authorFirstChecked = ($_GET['author_first'] == "on") ? "checked" : "";
        $authorMiddleChecked = ($_GET['author_middle'] == "on") ? "checked" : "";
        $authorLastChecked = ($_GET['author_last'] == "on") ? "checked" : "";
    } else {
        $authorFirstChecked = "checked";
        $authorMiddleChecked = "checked";
        $authorLastChecked = "checked";
    }
    $cohort = isset($_GET['cohort']) ? Sanitizer::sanitizeCohort($_GET['cohort']) : "";
    $cohorts = new Cohorts($token, $server, Application::getModule());
    $html = "";
    $style = "style='width: 450px; padding: 15px; vertical-align: top;'";
    $defaultDays = "";
    if (isset($_GET['trainingPeriodPlusDays']) && is_numeric($_GET['trainingPeriodPlusDays'])) {
        $defaultDays = Sanitizer::sanitize($_GET['trainingPeriodPlusDays']);
    }
    $fullURL = Application::link("publications/view.php").makeExtraURLParams(["trainingPeriodPlusDays", "begin", "end", "limitPubs"]);
    list($url, $trainingPeriodParams) = REDCapManagement::splitURL($fullURL);
    $fullURLMinusCohort = preg_replace("/&cohort=[^\&]+/", "", $fullURL);
    $begin = Publications::adjudicateStartDate($_GET['limitPubs'] ?? "", $_GET['begin'] ?? "");
    $end = Sanitizer::sanitizeDate($_GET['end']);

    $html .= "<table class='centered'>\n";
    $html .= "<tr>\n";
    $html .= "<td colspan='2' $style><h2 class='nomargin'>Customize</h2></td>\n";
    $html .= "</tr>\n";
    $html .= "<tr>\n";
    $html .= "<td $style class='yellow'>".Altmetric::makeClickText()."</td>\n";
    $html .= "<td $style class='green'>";
    $html .= "<h4>Show Pubs During Training</h4>";
    $html .= "<form action='$url' method='GET'>";
    $html .= URLManagement::makeHiddenInputs($trainingPeriodParams, TRUE);
    $html .= "<p class='centered'>Additional Days After Training: <input type='number' name='trainingPeriodPlusDays' style='width: 60px;' value='$defaultDays'><br><button>Re-Configure</button></p>";
    $html .= "</form>";
    $html .= "</td>";
    $html .= "</tr>";
    $html .= "<tr>";
    $html .= "</tr>";
    $html .= "<tr>";
    $html .= "<td class='blue'>";
    $html .= "<form action='$url' method='GET'>";
    $html .= "<h4>Filter for Timespan</h4>";
    $trainingPeriodParams = REDCapManagement::splitURL(Application::link("publications/view.php").makeExtraURLParams(["trainingPeriodPlusDays", "begin", "end", "limitPubs", "author_first", "author_middle", "author_last"]))[1];
    $html .= URLManagement::makeHiddenInputs($trainingPeriodParams, TRUE);
    if (isset($_GET['limitPubs'])) {
        $limitYear = Sanitizer::sanitizeInteger($_GET['limitPubs']);
        $html .= "<input type='hidden' name='limitPubs' value='$limitYear' />";
    }
    $html .= "<p class='centered'>Start Date: <input type='date' name='begin' style='width: 150px;' value='$begin'><br>";
    $html .= "End Date: <input type='date' name='end' value='$end' style='width: 150px;'><br>";
    $html .= "<h4>Filter for Author Position</h4>";
    $html .= "<p class='centered'>";
    $html .= "<input type='checkbox' name='author_first' id='author_first' $authorFirstChecked /> <label for='author_first'>First Author</label>";
    $html .= "&nbsp;&nbsp;&nbsp;";
    $html .= "<input type='checkbox' name='author_middle' id='author_middle' $authorMiddleChecked /> <label for='author_middle'>Middle Author</label>";
    $html .= "&nbsp;&nbsp;&nbsp;";
    $html .= "<input type='checkbox' name='author_last' id='author_last' $authorLastChecked /> <label for='author_last'>Last Author</label>";
    $html .= "</p>";
    $html .= "<button>Re-Configure</button></p>";
    $html .= "</form>";
    $html .= "</td>";
    $html .= "<td class='orange'>";
    $html .= $cohorts->makeCohortSelect($cohort, "location.href=\"$fullURLMinusCohort\"+\"&cohort=\"+encodeURIComponent($(this).val());");
    $html .= Publications::makeLimitButton();
    $html .= "<div id='grantCounts'>";
    $grantCountsFetchUrl = Application::link("publications/view.php").makeExtraURLParams(["trainingPeriodPlusDays", "begin", "end", "limitPubs"])."&grantCounts";
    $grants = Sanitizer::sanitizeArray($_GET['grants'] ?? []);
    if (!empty($grants) && !in_array("all", $grants)) {
        $html .= "<script>$(document).ready(function() { downloadUrlIntoPage(\"$grantCountsFetchUrl\", \"#grantCounts\"); });</script>";
    } else {
        $html .= "<p class='centered'><a href='javascript:;' onclick='downloadUrlIntoPage(\"$grantCountsFetchUrl\", \"#grantCounts\");'>Filter by Grants Cited</a></p>";
    }
    $html .= "</div>";
    $html .= "</td>";
    $html .= "</tr>";
    $html .= "</table>";

    return $html;
}

function makePublicationSearch($names, $record = NULL) {
	$html = "";
	$html .= "<h2>View a Scholar's Publications</h2>\n";
	$html .= "<p class='centered'><a href='".Application::link("publications/view.php")."&record=all".makeExtraURLParams(["record"])."'>View All Scholars' Publications</a></p>\n";
	$html .= "<p class='centered'><select onchange='window.location.href = \"".Application::link("publications/view.php").makeExtraURLParams(["record"])."&record=\" + $(this).val();'><option value=''>---SELECT---</option>\n";
	foreach ($names as $recordId => $name) {
		$html .= "<option value='$recordId'";
		if ($_GET['record'] && ($_GET['record'] == $recordId)) {
			$html .= " selected";
		}
		$html .= ">$name</option>\n";
	}
	$html .= "</select></p>\n";
    if (!isset($_GET['record'])) {
        $grants = Sanitizer::sanitizeArray($_GET['grants'] ?? []);
        $grantText = !empty($grants) ? " all publications associated with ".REDCapManagement::makeConjunction($grants) : "";
        $html .= "<p class='smaller centered max-width'>Select a scholar or click the \"View All\" link to view$grantText.</p>";
    }
    if (isset($_GET['record']) && $record && ($record !== "all")) {
        $link = Application::link("wrangler/include.php")."&wranglerType=Publications&record=".$record;
        $html .= "<p class='centered'><a href='javascript:;' onclick='window.location.href=\"$link\";'>Wrangle This Scholar's Publications</a></p>";
    }
	return $html;
}
