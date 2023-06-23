<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Altmetric;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Links;

if (!isset($_GET['showHeaders'])) {
    define("NOAUTH", TRUE);
}

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

# https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: X-PINGOTHER, Content-Type, Access-Control-Allow-Headers, X-Requested-With");

$metadata = Download::metadata($token, $server);
$metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
if ($_GET['cohort']) {
    $cohort = REDCapManagement::sanitize($_GET['cohort']);
} else {
    $cohort = "";
}
if ($cohort && ($cohort != 'all')) {
    $recordIds = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $recordIds = Download::recordIds($token, $server);
}
$currRecord = FALSE;
$currName = "";
$names = Download::names($token, $server);
if (isset($_GET['record']) && ($_GET['record'] !== "")) {
    $currRecord = REDCapManagement::getSanitizedRecord($_GET['record'], $recordIds);
    if ($currRecord && ($currRecord != 'all')) {
        $recordIds = [$currRecord];
        $currName = $names[$currRecord];
    }
}
if (isset($_GET['test'])) {
    echo "cohort: $cohort<br>";
    echo "records: ".json_encode($recordIds)."<br>";
}

$units = ['days', 'months', 'years'];
$prior = [];
$afterTraining = [];
$hasPrior = FALSE;
$hasAfterTraining = FALSE;
foreach ($units as $unit) {
    if (isset($_GET[$unit.'Prior']) && is_numeric($_GET[$unit.'Prior']) && ($_GET[$unit.'Prior'] >= 0)) {
        $val = (int) REDCapManagement::sanitize($_GET[$unit.'Prior']);
        $prior[$unit] = $val;
        $hasPrior = TRUE;
    } else {
        $prior[$unit] = "";
    }
    if (isset($_GET[$unit.'AfterTraining']) && is_numeric($_GET[$unit.'AfterTraining']) && ($_GET[$unit.'AfterTraining'] >= 0)) {
        $val = (int) REDCapManagement::sanitize($_GET[$unit.'AfterTraining']);
        $afterTraining[$unit] = $val;
        $hasAfterTraining = TRUE;
    } else {
        $afterTraining[$unit] = "";
    }
}

$noCitationsMessage = "None.";
if (isset($_GET['showHeaders'])) {
    require_once(dirname(__FILE__)."/charts/baseWeb.php");

    if (!$hasPrior && !$hasAfterTraining) {
        if (isset($_GET['test'])) {
            echo "Changing recordIds from ".count($recordIds)." to empty.<br>";
        }
        $recordIds = [];
        $noCitationsMessage = "The widget has not yet been configured.";
    }

    $url = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
    $url = preg_replace("/\&showHeaders[^\&]*/", "", $url);
    $url = preg_replace("/showHeaders[^\&]*\&/", "", $url);
    $url .= "&NOAUTH";
    foreach ($units as $unit) {
        foreach (['AfterTraining', 'Prior'] as $suffix) {
            $url = str_replace($unit.$suffix."=&", "", $url);
        }
    }
    $url = str_replace("record=&", "", $url);
    $url = str_replace("cohort=&", "", $url);
    ?>
    <h1>Brag on Your Scholars' Publications!</h1>
    <?= Altmetric::makeClickText(); ?>
    <h2>Include this Page as a Widget in Another Page</h2>
    <div class="max-width centered">
        <p class="centered">Copy the following HTML and place into another HTML webpage to display your scholars' publications. Your website must likely have cross-origin framing turned on (which is the default).</p>
        <div class="max-width centered"><code id="htmlCode">&lt;iframe src="<?= $url ?>" title="Recent Publications" style="width: 100%; height: 400px;"&gt;&lt;/iframe&gt;</code></div>
        <div class="max-width alignright smaller"><a href="javascript:;" onclick="copyToClipboard($('#htmlCode'));">Copy</a></a></div>

        <h3>Further Configurations</h3>
        <form method="GET" action="<?= REDCapManagement::getPage(Application::link("brag.php")) ?>">
            <?= REDCapManagement::getParametersAsHiddenInputs(Application::link("brag.php")) ?>
            <?php
            $cohorts = new Cohorts($token, $server, Application::getModule());
            echo "<h4>Step 1: Select a Group</h4>";
            echo "<p class='centered max-width padded' style='background-color: #eeeeee;'>Select a Cohort:<br/>".$cohorts->makeCohortSelect($cohort)."</p>";
            echo "<p class='centered max-width padded' style='background-color: #dddddd;'><strong>-OR-</strong> Select Which Scholar:<br/><select name='record' id='record'><option value='all'>---ALL---</option>";
            foreach ($names as $recordId => $name) {
                $sel = ($currRecord && ($currRecord == $recordId)) ? " selected" : "";
                echo "<option value='$recordId'$sel>$name</option>";
            }
            echo "</select></p>";
            echo "<h4>Step 2: Select a Time Period</h4>";
            ?>
            <?= isset($_GET['showHeaders']) ? "<input type='hidden' name='showHeaders' value='' />" : "" ?>
            <p class="centered max-width padded" style="background-color: #eeeeee;">Show a Certain Time Period Prior to Today:<br/>
                <?php
                    echo makeOrList($units, "Prior", $prior);
                ?>
            </p>
            <p class="centered max-width padded" style="background-color: #dddddd;"><strong>-OR-</strong> Show Period During Training Period and a Certain Amount of Time After the End of Training Period:<br/>
                <?php
                echo makeOrList($units, "AfterTraining", $afterTraining);
                ?>
            </p>
            <h4>Step 3: Re-Configure</h4>
            <p class="centered max-width padded" style="background-color: white;"><button>Re-Configure</button></p>
        </form>
    </div>
    <hr>
    <?php
} else {
    echo "<link rel='stylesheet' href='".Application::link("/css/career_dev.css")."'>\n";
}

$forName = "";
if ($currName) {
    $forName = " for $currName";
}
foreach ($units as $unit) {
    $ucUnit = ucfirst($unit);
    if ($afterTraining[$unit] !== "") {
        if ($afterTraining[$unit] == 1) {
            $ucUnit = preg_replace("/s$/", "", $ucUnit);
        }
        echo "<h4>All Publications$forName During Training and {$afterTraining[$unit]} $ucUnit After</h4>";
    } else if ($prior[$unit] !== "") {
        if ($prior[$unit] == 1) {
            $ucUnit = preg_replace("/s$/", "", $ucUnit);
        }
        echo "<h4>All Publications$forName in Previous {$prior[$unit]} $ucUnit</h4>";
    }
}
if (!$hasAfterTraining && !$hasPrior) {
    if (isset($_GET['showHeaders'])) {
        echo "<h4>Publications Will Be Displayed Here</h4>";
    } else {
        # historical legacy
        $defaultDays = 180;
        echo "<h4>All Publications$forName in Previous $defaultDays Days</h4>";
        $prior['days'] = $defaultDays;
        $hasPrior = TRUE;
    }
}

$oneDay = 24 * 3600;
if ($prior['days'] !== "") {
    $startTs = time() - $prior['days'] * $oneDay;
} else if ($prior['months'] !== "") {
    if ($prior['months'] === 0) {
        $startTs = time();
    } else {
        $startTs = strtotime("first day of -{$prior['months']} months");
    }
} else if ($prior['years'] !== '') {
    if ($prior['years'] === 0) {
        $startTs = time();
    } else {
        $startTs = strtotime("-{$prior['years']} years");
    }
} else {
    $startTs = FALSE;
}
$endTs = FALSE;  // use only $startTs

if (isset($_GET['asc'])) {
    $asc = TRUE;
} else {
    $asc = FALSE;
}
$allPMIDs = [];
$multipleScholarPMIDs = [];
$firstNames = Download::firstnames($token, $server);
$lastNames = Download::lastnames($token, $server);
$trainingFields = [
    "record_id",
    "identifier_start_of_training",
    "identifier_left_date",
];
for ($i = 1; $i <= 5; $i++) {
    $trainingFields[] = "summary_award_type_".$i;
    $trainingFields[] = "summary_award_date_".$i;
    $trainingFields[] = "summary_award_end_date_".$i;
}
$trainingFields = REDCapManagement::filterOutInvalidFields($metadata, $trainingFields);
$citationFields = [
    "record_id",
    "citation_pmid",
    "citation_include",
    "citation_flagged",
    "citation_ts",
    "citation_pmcid",
    "citation_doi",
    "citation_authors",
    "citation_title",
    "citation_pub_types",
    "citation_mesh_terms",
    "citation_journal",
    "citation_volume",
    "citation_issue",
    "citation_year",
    "citation_month",
    "citation_day",
    "citation_pages",
];
if (isset($_GET['test'])) {
    echo "Records: ".json_encode($recordIds)."<br>";
}

$timestampFieldData = Download::oneFieldWithInstances($token, $server, "citation_ts");
$hasTimestampData = FALSE;
foreach ($timestampFieldData as $recordId => $timestampInstanceData) {
    foreach ($timestampInstanceData as $instance => $value) {
        if ($value !== "") {
            $hasTimestampData = TRUE;
            break;
        }
    }
    if ($hasTimestampData) {
        break;
    }
}

$instancesToDownload = [];
foreach ($recordIds as $recordId) {
    if ($hasAfterTraining) {
        $trainingData = Download::fieldsForRecords($token, $server, $trainingFields, [$recordId]);
        $trainingStartDate = getTrainingStartDate($trainingData, $recordId);
        $trainingEndDate = getTrainingEndDate($trainingData, $recordId);
        if (isset($_GET['test'])) {
            echo "Record $recordId: start $trainingStartDate and end $trainingEndDate<br>";
        }
        if ($trainingStartDate) {
            $startTs = strtotime($trainingStartDate);
        } else {
            continue;
        }
        if ($trainingEndDate) {
            if ($afterTraining['days'] !== "") {
                $endTs = strtotime($trainingEndDate) + $afterTraining['days'] * $oneDay;
            } else if ($afterTraining['months'] === 0) {
                $endTs = time();
            } else if ($afterTraining['months'] !== "") {
                $endTs = strtotime("first day of +{$afterTraining['months']} months", strtotime($trainingEndDate));
            } else if ($afterTraining['years'] === 0) {
                $endTs = time();
            } else if ($afterTraining['years'] !== "") {
                $endTs = strtotime("+{$afterTraining['years']} years", strtotime($trainingEndDate));
            } else {
                throw new \Exception("You must specify a number of days or months after training!");
            }
        } else {
            $endTs = FALSE;
        }
        if (isset($_GET['test'])) {
            $startDate = date("Y-m-d", $startTs);
            $endDate = date("Y-m-d", $endTs);
            echo "Record $recordId from $startDate to $endDate<br>";
        }
    } else {
        if (isset($_GET['test'])) {
            echo "No days after training for Record $recordId<br>";
        }
    }
    if (isset($_GET['test'])) {
        echo "Downloading for Record $recordId<br>";
    }

    if (in_array("citation_ts", $metadataFields)) {
        if ($hasTimestampData) {
            $includeData = Download::fieldsForRecords($token, $server, ["record_id", "citation_include"], [$recordId]);
            if (isset($_GET['test'])) {
                Application::log("Record $recordId has includeData " . REDCapManagement::json_encode_with_spaces($includeData), $pid);
            }

            $includes = [];
            $timestamps = $timestampFieldData[$recordId] ?? [];
            foreach ($includeData as $row) {
                if ($row['redcap_repeat_instrument'] == "citation") {
                    $includes[$row['redcap_repeat_instance']] = $row['citation_include'];
                }
            }
            if (isset($_GET['test'])) {
                Application::log("Record $recordId has includes " . REDCapManagement::json_encode_with_spaces($includes), $pid);
            }

            $instancesToDownload[$recordId] = [];
            foreach ($includes as $instance => $value) {
                $ts = REDCapManagement::isDate($timestamps[$instance] ?? "") ? strtotime($timestamps[$instance]) : FALSE;
                if (isset($_GET['test'])) {
                    Application::log("Record $recordId Instance $instance comparing " . date("Y-m-d", $ts) . " and " . date("Y-m-d", $startTs) . " - " . date("Y-m-d", $endTs), $pid);
                }
                if ($ts && ($value == 1) && ($ts >= $startTs) && (($endTs === FALSE) || ($ts <= $endTs))) {
                    $instancesToDownload[$recordId][] = $instance;
                }
            }
        }
    }
}

$totalToDownload = 0;
foreach ($instancesToDownload as $recordId => $instancesForRecord) {
    $totalToDownload += count($instancesForRecord);
}

$doQuickWay = ($totalToDownload > 75);
if ($doQuickWay) {
    $citationFields = ["record_id", "citation_pmid", "citation_include", "citation_ts", "citation_full_citation", "citation_doi"];
    if (isset($_GET['test'])) {
        Application::log("DOING QUICK WAY", $pid);
    }
}

$citationsWithTs = [];
foreach ($recordIds as $recordId) {
    if (isset($instancesToDownload[$recordId])) {
        $redcapData = Download::fieldsForRecordAndInstances($token, $server, $citationFields, $recordId, "citation", $instancesToDownload[$recordId]);
    } else {
        $redcapData = Download::fieldsForRecords($token, $server, $citationFields, [$recordId]);
    }
    if (isset($_GET['test'])) {
        Application::log("Record $recordId has ".count($redcapData)." rows of REDCap data", $pid);
    }

    if ($doQuickWay && !empty($redcapData)) {
        foreach ($redcapData as $row) {
            if (isset($_GET['test'])) {
                Application::log("Record $recordId row: ".json_encode($row));
            }
            $ts = $row['citation_ts'] ? strtotime($row['citation_ts']) : FALSE;
            if (
                $row['citation_include']
                && $ts
                && ($ts >= $startTs)
                && (
                    ($endTs === FALSE)
                    || ($ts <= $endTs)
                )
            ) {
                $pmid = $row['citation_pmid'];
                $doi = $row['citation_doi'];
                $citationStr = $row['citation_full_citation']." ".Links::makeLink(Citation::getURLForPMID($pmid), "PubMed PMID: $pmid", TRUE);
                $citationStr = str_replace("doi:$doi", Links::makeLink("https://www.doi.org/".$doi, "doi:$doi", TRUE), $citationStr);
                $citationsWithTs[$citationStr] = $ts;
            }
        }
    } else if (!empty($redcapData)) {
        $allCitations = [];
        $pubs = new Publications($token, $server, $metadata);
        $pubs->setRows($redcapData);
        $recordCitations = $pubs->getSortedCitationsInTimespan($startTs, $endTs, "Included", FALSE);
        if (isset($_GET['test'])) {
            Application::log("Record $recordId has ".count($recordCitations)." citations", $pid);
        }
        foreach ($recordCitations as $citation) {
            $pmid = $citation->getPMID();
            if (isset($allPMIDs[$pmid])) {
                if (!isset($multipleScholarPMIDs[$pmid])) {
                    $multipleScholarPMIDs[$pmid] = [];
                    $multipleScholarPMIDs[$pmid][] = $allPMIDs[$pmid];
                }
                $multipleScholarPMIDs[$pmid][] = ["lastName" => $lastNames[$recordId], "firstName" => $firstNames[$recordId]];
            } else {
                $allCitations[] = $citation;
                $allPMIDs[$pmid] = ["lastName" => $lastNames[$recordId], "firstName" => $firstNames[$recordId]];
            }
        }
        if (isset($_GET['test'])) {
            Application::log("All Citations ".count($allCitations), $pid);
        }
        foreach ($allCitations as $citation) {
            if (isset($_GET['altmetrics'])) {
                $citationStr = $citation->getImage("left");
            } else {
                $citationStr = "";
            }
            $pmid = $citation->getPMID();
            if (isset($multipleScholarPMIDs[$pmid])) {
                // Application::log("Calling getCitation $pmid with multiple: ".REDCapManagement::json_encode_with_spaces($multipleScholarPMIDs[$pmid]));
                $citationStr .= $citation->getCitation($multipleScholarPMIDs[$pmid]);
            } else {
                $citationStr .= $citation->getCitationWithLink(FALSE, TRUE);
            }
            $citationsWithTs[$citationStr] = $citation->getTimestamp();
        }
    }
}

if (isset($_GET['test'])) {
    Application::log("Citations with TS ".count($citationsWithTs), $pid);
}

if ($asc) {
    asort($citationsWithTs);
} else {
    arsort($citationsWithTs);
}

$classInfo = "";
if (!defined("NOAUTH")) {
    $classInfo = "class='max-width'";
}

echo "<div $classInfo style='padding: 8px;'>\n";
if (empty($citationsWithTs)) {
    echo "<p class='centered'>$noCitationsMessage</p>";
} else {
    foreach ($citationsWithTs as $citationStr => $ts) {
        echo "<p class='smaller' style='padding: 2px 0;'>".$citationStr."</p>\n";
    }
}
echo "</div>\n";
echo "<br><br><br>";
echo "<p class='smallest centered'>Publications from ".Application::getProgramName()." <img src='".Application::link("img/flight_tracker_icon_cropped.png")."' style='height: 52px; width: 33px;'></p>";


function getTrainingStartDate($redcapData, $recordId) {
    $earliestDate = "";
    $startAtInstitution = REDCapManagement::findField($redcapData, $recordId, "identifier_start_of_training");
    if ($startAtInstitution) {
        $earliestDate = $startAtInstitution;
    }
    $kTypes = [1, 2, 3, 4, 9];
    for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
        $awardType = REDCapManagement::findField($redcapData, $recordId, "summary_award_type_".$i);
        $awardStartDate = REDCapManagement::findField($redcapData, $recordId, "summary_award_date_".$i);
        if (
                $awardType
                && $awardStartDate
                && in_array($awardType, $kTypes)
                && (
                    !$earliestDate
                    || REDCapManagement::dateCompare($awardStartDate, "<", $earliestDate)
                )
        ) {
                $earliestDate = $awardStartDate;
        }
    }
    return $earliestDate;
}

function getTrainingEndDate($redcapData, $recordId) {
    $latestDate = "";
    $kTypes = [1, 2, 3, 4, 9];
    for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
        $awardType = REDCapManagement::findField($redcapData, $recordId, "summary_award_type_".$i);
        $awardEndDate = REDCapManagement::findField($redcapData, $recordId, "summary_award_end_date_".$i);
        if (
            $awardType
            && $awardEndDate
            && in_array($awardType, $kTypes)
            && (
                !$latestDate
                || REDCapManagement::dateCompare($awardEndDate, ">", $latestDate)
            )
        ) {
            $latestDate = $awardEndDate;
        }
    }
    if (!$latestDate) {
        # only best guess if no other data exist
        $latestDate = REDCapManagement::findField($redcapData, $recordId, "identifier_left_date");
    }
    return $latestDate;
}

function makeOrList($units, $suffix, $ary) {
    $html = [];
    foreach ($units as $unit) {
        $ucUnit = ucfirst($unit);
        $priorities = ["this"];
        foreach ($units as $unit2) {
            if ($unit2 !== $unit) {
                $priorities[] = "'#$unit2$suffix'";
            }
        }
        $html[] = "<label for='$unit$suffix'>$ucUnit:</label> <input onchange=\"enforceOneNumber(".implode(", ", $priorities).");\" type=\"number\" min=\"0\" id=\"$unit$suffix\" name=\"$unit$suffix\" style=\"width: 75px;\" value=\"{$ary[$unit]}\" />";
    }
    return implode("&nbsp;<strong>-OR-</strong>&nbsp;", $html);
}