<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\MeSHTerms;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Altmetric;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\URLManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

define("NUM_MESH_TERMS", 3);

if ($_GET['record']) {
    if ($_GET['record'] == "all") {
        if ($_GET['cohort']) {
            $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
            $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
        } else {
            $records = Download::recordIdsByPid($pid);
        }
    } else {
        $recordIds = Download::recordIdsByPid($pid);
        $records = [Sanitizer::getSanitizedRecord($_GET['record'], $recordIds)];
    }
} else {
    $records = [];
}

$names = Download::namesByPid($pid);
if (isset($_GET['download']) && $records) {
    $metadata = Download::metadataByPid($pid);
    list($citations, $dates) = getCitationsForRecords($records, $token, $server, $pid, $metadata);
    $html = makePublicationListHTML($citations, $names, $dates);
    Application::writeHTMLToDoc($html, "Publications ".date("Y-m-d").".docx");
    exit;
}
if (isset($_GET['grantCounts'])) {
    if (empty($records)) {
        $records = Download::recordIdsByPid($pid);
    }
    $citationFields = ["record_id", "citation_pmid", "citation_include", "citation_flagged", "citation_grants"];
    $grantCounts = [];
    $areFlagsOn = Publications::areFlagsOn($pid);
    $redcapData = Download::fieldsForRecords($token, $server, $citationFields, $records);
    foreach ($redcapData as $row) {
        $grantStr = preg_replace("/\s+/", "", $row['citation_grants']);
        if (
            $row['citation_pmid']
            && $grantStr
            && ($row['citation_include'] == "1")
            && (
                !$areFlagsOn
                || ($row['citation_flagged'] == "1")
            )
        ) {
            $awardNumbers = explode(";", $grantStr);
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
    arsort($grantCounts);

    $url = URLManagement::splitURL(Application::link("this"))[0];
    $paramsWithoutGrants = [];
    parse_str(explode("?", $_SERVER['REQUEST_URI'])[1], $paramsWithoutGrants);
    unset($paramsWithoutGrants["grantCounts"]);
    unset($paramsWithoutGrants["grants"]);

    $html = "";
    $html .= "<form method='GET' action='$url'>";
    $html .= URLManagement::makeHiddenInputs($paramsWithoutGrants, TRUE);
    $html .= "<h4 class='noBottomMargin'>Pubs Associated With a Grant</h4>";
    $allSelected = "";
    $grants = Sanitizer::sanitizeArray($_GET['grants'] ?? []);
    if (empty($grants) || in_array("all", $grants)) {
        $allSelected = " selected";
    }
    $html .= "<div class='centered'>Hold down Shift or Control to select multiple:<br/><select id='grants' name='grants[]' multiple><option value='all'$allSelected>---ALL---</option>";
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
    $html .= "<br><button>Re-Configure for Grants Cited</button></div>";
    $html .= "</form>";
    echo $html;
    exit;
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");

Application::increaseProcessingMax(1); // some people run long/large queries
$metadata = Download::metadataByPid($pid);
$link = Application::link("publications/view.php")."&download".makeExtraURLParams();
echo "<h1>View Publications</h1>";
if (Application::hasComposer() && CareerDev::isVanderbilt()) {
    echo "<p class='centered'><a href='$link'>Download as MS Word doc</a></p>";
}
echo makeCustomizeTable($token, $server, $pid);
if ($records) {
    list($citations, $dates) = getCitationsForRecords($records, $token, $server, $pid, $metadata);
    $record = (count($records) == 1) ? $records[0] : NULL;
    echo makePublicationSearch($names, $record);
    echo makePublicationListHTML($citations, $names, $dates);
} else {
	echo makePublicationSearch($names);
}

function getCitationsForRecords(array $records, string $token, string $server, $pid, array $metadata): array {
    $trainingStarts = Download::oneFieldByPid($pid, "summary_training_start");
    $trainingEnds = Download::oneFieldByPid($pid, "summary_training_end");
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
    $focusedFields = [
        "record_id",
        "citation_pmid",
        "citation_include",
        "citation_flagged",
    ];
    if (!empty($grants) && !in_array("all", $grants)) {
        $focusedFields[] = "citation_grants";
    }
    if (
        (
            isset($_GET['trainingPeriodPlusDays'])
            && is_numeric($_GET['trainingPeriodPlusDays'])
        )
        || (
            $_GET['begin'] ?? FALSE
        )
    ){
        $focusedFields[] = "citation_date";
    }
    if (isset($_GET['author_first']) || isset($_GET['author_middle']) || isset($_GET['author_last'])) {
        $focusedFields[] = "citation_authors";
    }
    if (!empty(getMeSHTerms())) {
        $focusedFields[] = "citation_mesh_terms";
    }
    $allFields = [
        "record_id",
        "citation_pmid",
        "citation_include",
        "citation_flagged",
        "citation_ts",
        "citation_pmcid",
        "citation_doi",
        "citation_authors",
        "citation_title",
        "citation_journal",
        "citation_volume",
        "citation_issue",
        "citation_year",
        "citation_month",
        "citation_day",
        "citation_date",
        "citation_pages",
        "citation_pilot_grants",
        "citation_altmetric_score",
        "citation_altmetric_image",
    ];
    foreach ($records as $record) {
        $redcapData = Download::fieldsForRecordsByPid($pid, $focusedFields, [$record]);
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
        }
        if ($_GET['begin'] ?? FALSE) {
            $startDate = Publications::adjudicateStartDate($_GET['limitPubs'] ?? "", $_GET['begin']);
            $startTs = $startDate ? strtotime($startDate) : 0;
            if ($_GET['end'] ?? FALSE) {
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
        $meshTerms = getMeSHTerms();
        if (!empty($meshTerms) && isset($_GET['mesh_combiner'])) {
            $combiner = Sanitizer::sanitize($_GET['mesh_combiner']);
            $included->filterForMeSHTerms($meshTerms, $combiner);
            $notDone->filterForMeSHTerms($meshTerms, $combiner);
        }

        if (isset($_GET['test'])) {
            Application::log("Record $record has ".$included->getCount()." records");
        }


        $instancesToDownload = array_unique(array_merge($included->getInstances(), $notDone->getInstances()));
        if (!empty($instancesToDownload)) {
            $deepData = Download::fieldsForRecordAndInstances($token, $server, $allFields, [$record], "citation", $instancesToDownload);
            $deepPubs = new Publications($token, $server, $metadata);
            $deepPubs->setRows($deepData);
            $citations[$notConfirmed][$record] = $deepPubs->getCitationCollection("Not Done");
            $citations[$confirmed][$record] = $deepPubs->getCitationCollection("Included");
        } else {
            $citations[$confirmed][$record] = $included;
            $citations[$notConfirmed][$record] = $notDone;
        }
    }
    return [$citations, $dates];
}

function totalCitationColls(array $citColls): int {
    $total = 0;
    foreach ($citColls as $citColl) {
        $total += $citColl->getCount();
    }
    return $total;
}

function getMeSHTerms(): array {
    $terms = [];
    for ($i = 1; $i <= NUM_MESH_TERMS; $i++) {
        if ($_GET['mesh_term_'.$i] ?? FALSE) {
            $terms[] = Sanitizer::sanitize($_GET['mesh_term_'.$i]);
        }
    }
    return $terms;
}

function makePublicationListHTML(array $citations, array $names, array $dates): string {
    $html = "";
    $filterWordString = Sanitizer::sanitize($_GET['title_filter'] ?? "");
    # split by newlines and accompanying spaces; also remove any empty array elements
    $filterWords = preg_split("/\s*[\n\r]+\s*/", $filterWordString, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($citations as $header => $citColls) {
        $total = totalCitationColls($citColls);
        $html .= "<h2>$header (" . REDCapManagement::pretty($total) . ")</h2>";
        $html .= "<div class='centered' style='max-width: 800px;'>";
        if ($total == 0) {
            $html .= "<p class='centered'>No citations.</p>";
        } else {
            foreach ($citColls as $record => $citColl) {
                if (!empty($filterWords)) {
                    $citColl->filterByTitleWords($filterWords);
                }
                $name = $names[$record];
                $date = $dates[$record];
                $header = "<p class='centered'>$name (".$citColl->getCount().")";
                if ($date) {
                    $header .= "<br>$date";
                }
                $header .= "</p>";
                if ($citColl->getCount() > 0) {
                    $html .= $header;
                }

                $citations = $citColl->getCitations($_GET['sort'] ?? "date");
                foreach ($citations as $citation) {
                    $html .= "<p style='text-align: left; padding: 2px 0;'>";
                    if (isset($_GET['altmetrics']) && Altmetric::isActive()) {
                        $html .= $citation->getImage("left");
                    }
                    $html .= $citation->getCitationWithLink(TRUE, TRUE);
                    $rcr = $citation->getVariable("rcr");
                    if ($rcr) {
                        $html .= " RCR: ".REDCapManagement::pretty($rcr, 2);
                    }
                    $html .= "</p>";
                }
            }
        }
        $html .= "</div>";
    }
    $html .= "<br><br><br>";
    return $html;
}

function makeMeSHTermsToUse(): array {
    $terms = ["mesh_combiner"];
    for ($i = 1; $i <= NUM_MESH_TERMS; $i++) {
        $terms[] = "mesh_term_$i";
    }
    return $terms;
}

function makeExtraURLParams(array $exclude = []): string {
    $additionalParams = "";
    $expected = ["record", "altmetrics", "trainingPeriodPlusDays", "grants", "begin", "end", "cohort", "limitPubs", "author_first", "author_middle", "author_last", "sort", "title_filter"];
    $expected = array_merge($expected, makeMeSHTermsToUse());
    foreach ($expected as $key) {
        if (isset($_GET[$key]) && !in_array($key, $exclude)) {
            $key = Sanitizer::sanitize($key);
            if (is_array($_GET[$key])) {
                foreach (Sanitizer::sanitizeArray(array_unique($_GET[$key])) as $value) {
                    $additionalParams .= "&".urlencode($key."[]")."=".urlencode($value);
                }
            } else {
                $value = Sanitizer::sanitize($_GET[$key] ?? "");
                if ($value === "") {
                    $additionalParams .= "&".$key;
                } else {
                    $additionalParams .= "&$key=".urlencode($value);
                }
            }
        }
    }
    return $additionalParams;
}

function makeCustomizeTable(string $token, string $server, $pid): string {
    if (isset($_GET['author_first']) || isset($_GET['author_middle']) || isset($_GET['author_last'])) {
        $authorFirstChecked = ($_GET['author_first'] == "on") ? "checked" : "";
        $authorMiddleChecked = ($_GET['author_middle'] == "on") ? "checked" : "";
        $authorLastChecked = ($_GET['author_last'] == "on") ? "checked" : "";
    } else {
        $authorFirstChecked = "checked";
        $authorMiddleChecked = "checked";
        $authorLastChecked = "checked";
    }

    $dateChecked = "";
    $rcrChecked = "";
    $altmetricsChecked = "";
    if (!isset($_GET['sort']) || ($_GET['sort'] == "date")) {
        $dateChecked = "checked";
    } else if ($_GET['sort'] == "rcr") {
        $rcrChecked = "checked";
    } else if ($_GET['sort'] == "altmetrics") {
        $altmetricsChecked = "checked";
    }
    $filterWords = Sanitizer::sanitize($_GET['title_filter'] ?? "");

    $cohort = isset($_GET['cohort']) ? Sanitizer::sanitizeCohort($_GET['cohort']) : "";
    $cohorts = new Cohorts($token, $server, Application::getModule());
    $html = "";
    $style = "style='width: 450px; padding: 15px; vertical-align: middle;'";
    $defaultDays = "";
    if (isset($_GET['trainingPeriodPlusDays']) && is_numeric($_GET['trainingPeriodPlusDays'])) {
        $defaultDays = Sanitizer::sanitize($_GET['trainingPeriodPlusDays']);
    }
    $meshExcludes = makeMeSHTermsToUse();
    $fullURL = Application::link("publications/view.php").makeExtraURLParams();
    $fullURLMinusMesh = Application::link("publications/view.php").makeExtraURLParams($meshExcludes);
    $fullURLMinusCohort = Application::link("publications/view.php").makeExtraURLParams(["cohort"]);
    $url = URLManagement::splitURL($fullURL)[0];
    $begin = Publications::adjudicateStartDate($_GET['limitPubs'] ?? "", $_GET['begin'] ?? "");
    $end = Sanitizer::sanitizeDate($_GET['end']);

    # $paramsToInclude is modified for each <form>
    $html .= "<table class='centered'>";
    $html .= "<tr>";
    $paramsToInclude = URLManagement::splitURL($fullURLMinusMesh)[1];
    $html .= "<td colspan='2' $style><h2 class='nomargin'>Step 1: Customize</h2></td>";
    $html .= "</tr>";
    $html .= "<tr>";
    $html .= "<td $style class='yellow'>";
    $html .= "<h4 class='noBottomMargin'>MeSH Terms to Match</h4>";
    $html .= "<form action='$url' method='GET'>";
    $html .= URLManagement::makeHiddenInputs($paramsToInclude, TRUE);
    $html .= "<div style='font-size: 0.9em;'>";
    $html .= MeSHTerms::makeHTMLTable(NUM_MESH_TERMS, $pid);
    $andSelected = (!isset($_GET['mesh_combiner']) || ($_GET['mesh_combiner'] == "and")) ? "selected" : "";
    $orSelected = ($andSelected == "selected") ? "" : "selected";
    $html .= "<div class='centered'><select name='mesh_combiner'><option value='and' $andSelected>AND</option><option value='or' $orSelected>OR</option></select></div>";
    $html .= "</div>";
    $html .= "<p class='centered'><button>Re-Configure</button></p>";
    $html .= "</form>";
    $html .= "</td>";

    $paramsToInclude = URLManagement::splitURL(Application::link("publications/view.php").makeExtraURLParams(["trainingPeriodPlusDays"]))[1];
    $html .= "<td $style class='green'>";
    $html .= Altmetric::isActive() ? Altmetric::makeClickText(Application::link("publications/view.php").makeExtraURLParams(["altmetrics"])) : '';
    $html .= "<h4 class='noBottomMargin'>Show Pubs During Training</h4>";
    $html .= "<form action='$url' method='GET'>";
    $html .= URLManagement::makeHiddenInputs($paramsToInclude, TRUE);
    $html .= "<div class='centered'>Additional Days After Training: <input type='number' name='trainingPeriodPlusDays' style='width: 60px;' value='$defaultDays'><br/><button>Re-Configure</button></div>";
    $html .= "</form>";
    $html .= "</td>";
    $html .= "</tr>";
    $html .= "<tr>";
    $html .= "</tr>";

    $paramsToInclude = URLManagement::splitURL(Application::link("publications/view.php").makeExtraURLParams(["begin", "end", "sort", "author_first", "author_middle", "author_last"]))[1];
    $html .= "<tr>";
    $html .= "<td class='orange'>";
    $html .= Publications::makeLimitButton();
    $html .= "<form action='$url' method='GET'>";
    $html .= "<h4 class='noBottomMargin'>Filter for Timespan</h4>";
    $html .= URLManagement::makeHiddenInputs($paramsToInclude, TRUE);
    $html .= "<p class='centered' style='margin-top: 0;'>Start Date: <input type='date' name='begin' style='width: 150px;' value='$begin'><br>";
    $html .= "End Date: <input type='date' name='end' value='$end' style='width: 150px;'><br>";
    $html .= "<h4 class='noBottomMargin'>Sorting</h4>";
    $html .= "<p class='centered' style='margin-top: 0;'>";
    $html .= "<input type='radio' name='sort' id='sort_date' value='date' $dateChecked /> <label for='sort_date'>By Date</label>";
    $html .= "&nbsp;&nbsp;&nbsp;";
    $html .= "<input type='radio' name='sort' id='sort_rcr' value='rcr' $rcrChecked /> <label for='sort_rcr'>By RCR</label>";
    $html .= "&nbsp;&nbsp;&nbsp;";
    $html .= Altmetric::isActive() ? "<input type='radio' name='sort' id='sort_altmetrics' value='altmetrics' $altmetricsChecked /> <label for='sort_altmetrics'>By Altmetric Score</label>" : '';
    $html .= "</p>";
    $html .= "<h4 class='noBottomMargin'>Filter for Author Position</h4>";
    $html .= "<p class='centered' style='margin-top: 0;'>";
    $html .= "<input type='checkbox' name='author_first' id='author_first' $authorFirstChecked /> <label for='author_first'>First Author</label>";
    $html .= "&nbsp;&nbsp;&nbsp;";
    $html .= "<input type='checkbox' name='author_middle' id='author_middle' $authorMiddleChecked /> <label for='author_middle'>Middle Author</label>";
    $html .= "&nbsp;&nbsp;&nbsp;";
    $html .= "<input type='checkbox' name='author_last' id='author_last' $authorLastChecked /> <label for='author_last'>Last Author</label>";
    $html .= "</p>";
    $html .= "<button>Re-Configure</button></p>";
    $html .= "</form>";
    $html .= "</td>";
    
    # note: not using <form> - use JS instead
    $html .= "<td class='blue'>";
    $html .= $cohorts->makeCohortSelect($cohort, "location.href=\"$fullURLMinusCohort\"+\"&cohort=\"+encodeURIComponent($(this).val());");
    $html .= "<div id='grantCounts'>";
    # exclude the dates because we want a list of all grants
    $grantCountsFetchUrl = Application::link("publications/view.php").makeExtraURLParams()."&grantCounts";
    $grants = Sanitizer::sanitizeArray($_GET['grants'] ?? []);
    if (!empty($grants) && !in_array("all", $grants)) {
        $html .= "<script>$(document).ready(function() { downloadUrlIntoPage(\"$grantCountsFetchUrl\", \"#grantCounts\"); });</script>";
    } else {
        $html .= "<p class='centered'><a href='javascript:;' onclick='downloadUrlIntoPage(\"$grantCountsFetchUrl\", \"#grantCounts\");'>Filter by Grants Cited</a></p>";
    }
    $html .= "</div>";
    $filterUrl = Application::link("publications/view.php").makeExtraURLParams(["title_filter"]);
    $html .= "<h4 style='margin-bottom: 0;'><label for='title_filter'>Filter by Word(s) in Title</label></h4>";
    $html .= "<div class='centered smaller'>(one per line; case-insensitive):<br/><textarea id='title_filter' name='title_filter'>$filterWords</textarea></div><p style='margin-top: 0;'><button onclick='window.location.href = \"$filterUrl&title_filter=\"+encodeURIComponent($(\"#title_filter\").val());'>Re-Configure for Title Words</button></p>";
    $html .= "</td>";
    $html .= "</tr>";
    $html .= "</table>";

    return $html;
}

function makePublicationSearch(array $names, $record = NULL): string {
	$html = "";
	$html .= "<h2>Step 2: Select Scholar's Publications</h2>";
    $html .= "<p class='centered max-width'>If you have made changes, you must first press the appropriate Re-Configure button above in order for this step to work.</p>";
	$html .= "<p class='centered'><a href='".Application::link("publications/view.php")."&record=all".makeExtraURLParams(["record"])."'>View All Scholars' Publications</a></p>";
	$html .= "<p class='centered'><select onchange='window.location.href = \"".Application::link("publications/view.php").makeExtraURLParams(["record"])."&record=\" + $(this).val();'><option value=''>---SELECT---</option>";
	foreach ($names as $recordId => $name) {
		$html .= "<option value='$recordId'";
		if ($_GET['record'] && ($_GET['record'] == $recordId)) {
			$html .= " selected";
		}
		$html .= ">$name</option>";
	}
	$html .= "</select></p>";
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
