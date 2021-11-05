<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Cohorts;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$thisLink = Application::link("this");
if (isset($_GET['record'])) {
    $record = REDCapManagement::sanitize($_GET['record']);
    $possibleRecords = Download::recordIds($token, $server);
    if (in_array($record, $possibleRecords)) {
        $records = [];
        foreach ($possibleRecords as $recordId) {
            if ($recordId == $_GET['record']) {
                $records[] = $recordId;
            }
        }
    }
    if (empty($records)) {
        die("Could not find record.");
    }
    $cohort = "";
} else if ($_GET['cohort']) {
    $cohort = REDCapManagement::sanitize($_GET['cohort']);
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $cohort = "all";
    $records = Download::recordIds($token, $server);
}
$cohorts = new Cohorts($token, $server, Application::getModule());
$cohortSelect = $cohort ? "<p class='centered'>".$cohorts->makeCohortSelect("all", "location.href = '$thisLink&cohort='+$(this).val();")."</p>" : "";
$itemsPerPage = isset($_GET['numPerPage']) ? REDCapManagement::sanitize($_GET['numPerPage']) : 10;
$lastPage = (int) ceil(count($records) / $itemsPerPage);
if (isset($_GET['pageNum'])) {
    $page = REDCapManagement::sanitize($_GET['pageNum']);
    if (count($records) / $itemsPerPage <= $page) {
        $page = 1;
    }
} else {
    $page = 1;
}
if ($page < 1) {
    die("Improper pageNum");
}
$nextPage = ($page + 1 <= $lastPage) ? $page + 1 : FALSE;
$prevPage = ($page - 1 >= 1) ? $page - 1 : FALSE;
$names = Download::names($token, $server);
$metadata = Download::metadata($token, $server);

$recordsForPage = [];
for ($i = ($page - 1) * $itemsPerPage; ($i < $page * $itemsPerPage) && ($i < count($records)); $i++) {
    $recordsForPage[] = $records[$i];
}

$numMonths = 3;
$startDate = "2008-04-01";
$startTs = strtotime($startDate);
$legend = [
    "green" => [
        "PMCIDs &amp; Dates" => "",
        "Grants" => "Grants are cited by this publication with associated funding sources.",
    ],
    "yellow" => [
        "PMCIDs &amp; Dates" => "Not in compliance but within the $numMonths-month window.",
        "Grants" => "No grants are cited by this publication -or- the grant has an unknown funding source. Therefore, compliance may or may not be an issue with this publication.",
    ],
    "red" => [
        "PMCIDs &amp; Dates" => "Not in compliance and out of the $numMonths-month window.",
        "Grants" => "",
    ],
];
$agencies = [
    "NIH" => "green",
    "AHRQ" => "green",
    "PCORI" => "green",
    "VA" => "green",
    "DOD" => "green",
    "HHS" => "green",
];
$fields = [
    "record_id",
    "citation_pmid",
    "citation_pmcid",
    "citation_month",
    "citation_year",
    "citation_day",
    "citation_grants",
    "citation_title",
    "citation_include",
];
$headers = [
    "Scholar Name",
    "PMID<br>PMCID<br>NIHMS",
    "Title &amp; Date",
    "Associated Grants",
    // "Contact (???)",
];

$pageMssg = "On Page ".$page." of ".$lastPage;
$prevPageLink = ($prevPage !== FALSE) ? "<a href='$thisLink&pageNum=$prevPage&numPerPage=$itemsPerPage'>Previous</a>" : "No Previous Page";
$nextPageLink = ($nextPage !== FALSE) ? "<a href='$thisLink&pageNum=$nextPage&numPerPage=$itemsPerPage'>Next</a>" : "No Next Page";
$spacing = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
if (isset($_GET['record'])) {
    $togglePage = "";
} else {
    $togglePage = "<p class='centered smaller'>".$prevPageLink.$spacing.$pageMssg.$spacing.$nextPageLink."</p>";
}


echo "<h1>Public Access Compliance Update</h1>";
$pubWranglerLink = Application::link("/wrangler/include.php")."&wranglerType=Publications";
$threeMonthsPriorDate = REDCapManagement::addMonths(date("Y-m-d"), (0 - $numMonths));
echo "<h2>Compliance Threshold: ".REDCapManagement::YMD2MDY($threeMonthsPriorDate)."</h2>";
echo "<p class='centered'>This only affects citations already included in the <a href='$pubWranglerLink'>Publication Wrangler</a>.</p>";
echo $cohortSelect;
echo makeLegendForCompliance($legend);
echo $togglePage;
echo "<table class='centered max-width bordered'>";
echo "<thead>";
echo "<tr>";
foreach ($headers as $header) {
    echo "<th>$header</th>";
}
echo "</tr>";
echo "</thead>";
echo "<tbody>";
$i = 0;
$threeMonthsPrior = strtotime($threeMonthsPriorDate);
foreach ($recordsForPage as $recordId) {
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $nameWithLink = Links::makeRecordHomeLink($pid, $recordId, $names[$recordId]);
    $pubs = new Publications($token, $server, $metadata);
    $pubs->setRows($redcapData);
    $pmids = [];
    foreach ($pubs->getCitations() as $citation) {
        if ($pmid = $citation->getPMID()) {
            $pmids[] = $pmid;
        }
    }
    $translator = !empty($pmids) ? Publications::PMIDsToNIHMS($pmids, $pid) : [];
    $numCitationsAllGo = 0;
    foreach ($pubs->getCitations() as $citation) {
        $pubTs = $citation->getTimestamp();
        if ($pubTs >= $startTs) {
            $isAllGoForCitation = TRUE;
            $pmidUrl = $citation->getURL();
            $pmcidUrl = $citation->getPMCURL();
            $instance = $citation->getInstance();
            $title = $citation->getVariable("title");
            $pmid = $citation->getPMID();
            $pubDate = $citation->getDate(TRUE);

            $pmcid = $citation->getPMCWithPrefix();
            $isAllGoForCitation = $isAllGoForCitation && ($pmcid != "");
            $pmcidWithLink = $pmcid ? Links::makeLink($pmcidUrl, $pmcid, TRUE) : "No PMCID";
            $pmidWithLink = Links::makeLink($pmidUrl, "PMID ".$pmid, TRUE);
            $titleWithLink = Links::makePublicationsLink($pid, $recordId, $event_id, $title, $instance, TRUE);
            $nihms = $translator[$pmid] ?? "";
            if ($pmcid) {
                $pubClass = "green";
            } else {
                $pubClass = ($pubTs < $threeMonthsPrior) ? "red" : "yellow";
            }
            $pmcidClass = $pubClass;

            $grants = $citation->getGrantBaseAwardNumbers();
            $grantHTML = [];
            foreach ($grants as $baseAwardNo) {
                $parseAry = Grant::parseNumber($baseAwardNo);
                $membership = "Other";
                $grantShading = "yellow";
                foreach ($agencies as $agency => $shading) {
                    if ($agency == "HHS") {
                        if (Grant::isHHSGrant($baseAwardNo)) {
                            $membership = $agency;
                            $grantShading = $shading;
                            break;
                        }
                    } else if (Grant::isMember($parseAry['institute_code'], $agency)) {
                        $membership = $agency;
                        $grantShading = $shading;
                        break;
                    }
                }
                $grantHTML[] = "<span class='$grantShading nobreak'>$baseAwardNo ($membership)</span>";
                // $isAllGoForCitation = $isAllGoForCitation && ($grantShading != "red");
            }
            // $isAllGoForCitation = $isAllGoForCitation && !empty($grantHTML);
            $isAllGoForCitation = $isAllGoForCitation && in_array($pubClass, ["green"]);

            if (!$isAllGoForCitation || isset($_GET['record'])) {
                echo "<tr>";
                echo "<th>$nameWithLink</th>";
                echo "<td>";
                echo "<span class='nobreak'>$pmidWithLink</span><br>";
                echo "<span class='nobreak $pmcidClass'>$pmcidWithLink</span><br>";
                echo $nihms ? $nihms : "<span class='nobreak'>No NIHMS</span>";
                echo "</td>";
                echo "<td><span class='nobreak $pubClass'>$pubDate</span><br>$titleWithLink</td>";
                if (empty($grantHTML)) {
                    echo "<td><span class='yellow'>None Cited.</span></td>";
                } else {
                    echo "<td>".implode("<br>", $grantHTML)."</td>";
                }
                if (count($headers) >= 5) {
                    echo "<td></td>";
                }
                echo "</tr>";
            } else {
                $numCitationsAllGo++;
            }
        }
        $i++;
    }
    if ($numCitationsAllGo > 0) {
        echo "<tr>";
        echo "<th>$nameWithLink</th>";
        echo "<td class='bolded' colspan='".(count($headers) - 1)."'><span class='greentext' style='font-size: 24px;'>&check;</span> $numCitationsAllGo Citations Already Good to Go</td>";
        echo "</tr>";
    }
}
echo "</tbody></table>";
echo $togglePage;


function makeLegendForCompliance($legend) {
    $types = [];
    foreach (array_values($legend) as $descriptors) {
        foreach (array_keys($descriptors) as $type) {
            if (!in_array($type, $types)) {
                $types[] = $type;
            }
        }
    }
    $html = "<table class='bordered centered max-width'>";
    $html .= "<thead><tr>";
    $html .= "<th>Color</th>";
    foreach ($types as $type) {
        $html .= "<th>For $type</th>";
    }
    $html .= "</tr></thead>";
    $html .= "<tbody>";
    foreach ($legend as $color => $descriptors) {
        $descriptionTexts = [];
        foreach ($types as $type) {
            $description = $descriptors[$type];
            if ($description) {
                $descriptionTexts[$type] = "<td class='padded'>$description</td>";
            } else {
                $descriptionTexts[$type] = "<td>(Not used.)</td>";
            }
        }
        if (!empty($descriptionTexts)) {
            $html .= "<tr>";
            $html .= "<td class='$color'>&nbsp;&nbsp;&nbsp;&nbsp;</td>";
            foreach ($types as $type) {
                $html .= $descriptionTexts[$type];
            }
            $html .= "</tr>";
        }
    }
    $html .= "</tbody></table>";
    return $html;
}