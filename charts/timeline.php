<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\DateManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

?>
<script src="<?= CareerDev::link("/charts/vis.min.js") ?>"></script>
<link href="<?= CareerDev::link("charts/vis.min.css") ?>" rel="stylesheet" type="text/css" />
<link href='<?= CareerDev::link("/css/career_dev.css") ?>' type='text/css' />
<?php

require_once(dirname(__FILE__)."/../small_base.php");

$classes = ["CDAs", "All"];
$submissionClasses = ["Unfunded", "Pending", "Awarded"];   // correlated with CSS below

?>

<style>
body { font-size: 12px; }
.visualization { background-color: white; margin-bottom: 32px; margin-top: 32px; }
.Unfunded { border-color: #888888; }
.Pending { border-color: #8dc63f; }
.Awarded { border-color: #f4c3ff; }
</style>

<?php

	$records = Download::recordIds($token, $server);
    $metadataFields = Download::metadataFields($token, $server);
    $validPrefixes = [
        "coeus_",
        "coeussubmission_",
        "vera_",
        "verasubmission_",
        "summary_",
        "citation_",
        "nih_",
        "reporter_",
        "custom_",
    ];
    $fieldsToDownload = ["record_id", "identifier_first_name", "identifier_middle", "identifier_last_name"];
    foreach ($metadataFields as $field) {
        $matched = FALSE;
        foreach ($validPrefixes as $prefix) {
            if (preg_match("/^".$prefix."/", $field)) {
                $matched = TRUE;
            }
        }
        if ($matched) {
            $fieldsToDownload[] = $field;
        }
    }

	$recordId = isset($_GET['record']) ? REDCapManagement::getSanitizedRecord($_GET['record'], $records) : $records[0];
    $nextRecord = $records[0];
	for ($i = 0; $i < count($records); $i++) {
		if (($records[$i] == $recordId) && ($i + 1 < count($records))) {
		    $nextRecord = $records[$i + 1];
		    break;
		}
	}
	$rows = Download::fieldsForRecords($token, $server, $fieldsToDownload, [$recordId]);

    $name = "";
    foreach ($rows as $row) {
        if ($row['redcap_repeat_instrument'] == "") {
            $name = $row['identifier_first_name']." ".$row['identifier_last_name'];
            break;
        }
    }

    $hasSubmissions = FALSE;
    $grantsAndPubs = [];
    $maxTs = [];
	$minTs = [];

    $grants = new Grants($token, $server, "empty");
    $grants->setRows($rows);
    $grants->compileGrants();
    $grants->compileGrantSubmissions();

    foreach ($classes as $c) {
        $id = 1;
        $maxTs[$c] = 0;
        $minTs[$c] = time();
        $grantsAndPubs[$c] = [];

        if ($c == "All") {
            $allTimestamps = [];
            list($submissions, $submissionTimestamps) = makeSubmissionDots($grants->getGrants("submissions"), $id);
            list($awards, $awardTimestamps) = makeAwardDots($grants->getGrants("submission_dates"), $id);
            $grantsAndPubs[$c] = array_merge($grantsAndPubs[$c], $submissions, $awards);
            $allTimestamps = array_merge($submissionTimestamps, $awardTimestamps);
            $hasSubmissions = !empty($allTimestamps);

            foreach ($allTimestamps as $submissionTs) {
                if ($submissionTs) {
                    if ($maxTs[$c] < $submissionTs) {
                        $maxTs[$c] = $submissionTs;
                    }
                    if ($minTs[$c] > $submissionTs) {
                        $minTs[$c] = $submissionTs;
                    }
                }
            }
        }

        $grantClass = CareerDev::getSetting("grant_class", $pid);
        $grantAry = makeTrainingDatesBar($rows, $id, $minTs[$c], $maxTs[$c], ($grantClass == "T"));
        if ($grantAry) {
            $grantsAndPubs[$c][] = $grantAry;
        }
        if ($c == "All") {
            $grantType = "all";
        } else if ($c == "CDAs") {
            $grantType = "prior";
        } else {
            throw new \Exception("Class is not set up $c");
        }
        if ($grants->getCount($grantType) > 0) {
            if (isset($_GET['test'])) {
                echo "grants->$grantType has ".$grants->getCount($grantType)." items<br>";
            }
            $grantBars = makeGrantBars($grants->getGrants($grantType), $id, $minTs[$c], $maxTs[$c]);
            $grantsAndPubs[$c] = array_merge($grantsAndPubs[$c], $grantBars);
        }

        if ($c == "CDAs") {
            $pubDots = makePubDots($rows, $token, $server, $id, $minTs[$c], $maxTs[$c]);
            $grantsAndPubs[$c] = array_merge($grantsAndPubs[$c], $pubDots);
        }

        $currTs = time();
        if ($maxTs[$c] < $currTs) {
            $maxTs[$c] = $currTs;
        }

        $spacing = ($maxTs[$c] + 90 * 24 * 3600 - $minTs[$c]) / 6;
        $maxTs[$c] += $spacing;
        $minTs[$c] -= $spacing;
    }

if (isset($_GET['next'])) {
	echo "<h1>$recordId: $name</h1>\n";
    $timelineLink = Application::link("charts/timeline.php");
	echo "<p style='text-align: center;'><a href='$timelineLink&record=$nextRecord&next'>Next Record</a></p>\n";
}

foreach ($classes as $c) {
    if ($c == "All") {
        $vizTitle = "All Grants (Including Submissions)";
    } else if ($c == "CDAs") {
        $vizTitle = "Career Defining Awards &amp; Publications";
    } else {
        $vizTitle = "This should never happen.";
    }
    echo "<h3>$vizTitle</h3>";
    if ($hasSubmissions && ($c == "All")) {
        echo "<table class='centered max-width'><tbody><tr>";
        $cells = [];
        foreach ($submissionClasses as $submissionClass) {
            $cells[] = "<td class='$submissionClass' style='border-width: 20px; border-style: solid;'>$submissionClass</td>";
        }
        echo implode("<td>&nbsp;</td>", $cells);
        echo "</tr></tbody></table>";
    }
    echo "<div id='visualization$c' class='visualization'></div>";
}

?>

<script type="text/javascript">
window.onload = function() {
    const container = [];
    const items = [];
    const options = [];
    const timeline = [];
    <?php
    foreach ($classes as $c) {
        $dataset = json_encode($grantsAndPubs[$c]);
        $startDate = json_encode(date("Y-m-d", $minTs[$c]));
        $endDate = json_encode(date("Y-m-d", $maxTs[$c]));

        echo "
        container['$c'] = document.getElementById('visualization$c');
        items['$c'] = new vis.DataSet($dataset);
        options['$c'] = { start: $startDate, end: $endDate };
        timeline['$c'] = new vis.Timeline(container['$c'], items['$c'], options['$c']);
        ";
    }
    ?>
};
</script>


<?php

function makeAwardDots($grantAry, &$id) {
    $grantsAndPubs = [];
    $submissionTimestamps = [];
    foreach ($grantAry as $grant) {
        addIfValid($grant, $grantsAndPubs, $submissionTimestamps, $id, "Awarded");
    }
    return [$grantsAndPubs, $submissionTimestamps];
}

function makeSubmissionDots($grantAry, &$id) {
    $grantsAndPubs = [];
    $submissionTimestamps = [];
    foreach ($grantAry as $grant) {
        $awardStatus = $grant->getVariable("status");
        addIfValid($grant, $grantsAndPubs, $submissionTimestamps, $id, $awardStatus);
    }
    return [$grantsAndPubs, $submissionTimestamps];
}

function addIfValid($grant, &$grantsAndPubs, &$submissionTimestamps, &$id, $awardStatus) {
    $skipNumbers = [];
    $validStatuses = ["Awarded", "Unfunded", "Pending"];
    $awardNo = $grant->getNumber();
    $title = $grant->getVariable("title");
    $submissionDate = $grant->getVariable("submission_date");
    if (isset($_GET['test'])) {
        echo "Looking at $awardNo with $awardStatus and $submissionDate<br/>";
    }

    if (
        DateManagement::isDate($submissionDate)
        && !in_array($awardNo, $skipNumbers)
        && in_array($awardStatus, $validStatuses)
    ) {
        if (isset($_GET['test'])) {
            echo "Adding $awardNo with $awardStatus and $submissionDate with $id<br/>";
        }
        if (DateManagement::isMDY($submissionDate)) {
            $submissionTs = strtotime(DateManagement::MDY2YMD($submissionDate));
        } else if (DateManagement::isDMY($submissionDate)) {
            $submissionTs = strtotime(DateManagement::DMY2YMD($submissionDate));
        } else {
            $submissionTs = strtotime($submissionDate);
        }
        if (!$submissionTs) {
            return;
        }
        $submissionTimestamps[] = $submissionTs;

        $grantAry = [];
        $grantAry['id'] = $id;
        $grantAry['start'] = date("Y-m-d", $submissionTs);
        $grantAry['className'] = $awardStatus;
        $grantAry['type'] = "point";
        $grantNumber = $awardNo;
        $titleLength = 25;
        if (strlen($title) > $titleLength) {
            $truncatedTitle = substr($title, 0, $titleLength)."...";
        } else {
            $truncatedTitle = $title;
        }
        if ($grantNumber) {
            $grantNumber .= " Application";
        } else {
            $grantNumber = $awardStatus.": ".$truncatedTitle;
        }
        $url = $grant->getVariable("url");
        $grantAry['content'] = Links::makeLink($url, $grantNumber, TRUE);
        if (isset($_GET['test'])) {
            echo "Adding ".REDCapManagement::json_encode_with_spaces($grantAry)."<br/>";
        }
        $grantsAndPubs[] = $grantAry;
        $id++;
    }
}

function makeTrainingDatesBar($rows, &$id, &$minTs, &$maxTs, $isCurrentTrainee) {
    foreach ($rows as $row) {
        if ($row['redcap_repeat_instrument'] == "") {
            if ($row['summary_training_start']) {
                $startTs = strtotime($row['summary_training_start']);
                if ($row['summary_training_end']) {
                    $endTs = strtotime($row['summary_training_end']);
                } else {
                    if ($isCurrentTrainee) {
                        $endTs = time();
                    } else {
                        $endTs = FALSE;
                    }
                }

                $grantAry = [
                    "id" => $id,
                    "content" => "(Start of Training)",
                    "group" => "Grant",
                ];
                $grantAry['start'] = date("Y-m-d", $startTs);

                if ($endTs) {
                    $grantAry['type'] = "range";
                    $grantAry['content'] = "(Training Period)";
                    $grantAry['end'] = date("Y-m-d", $endTs);
                } else {
                    $grantAry['type'] = "box";
                }

                if ($minTs > $startTs){
                    $minTs = $startTs;
                }
                if ($endTs && ($maxTs < $endTs)) {
                    $maxTs = $endTs;
                }
                $id++;
                return $grantAry;
            }
        }
    }
    return [];
}

function makeGrantBars($grantAry, &$id, &$minTs, &$maxTs) {
    $grantsAndPubs = [];
    foreach ($grantAry as $grant) {
        $typeInfo = "";
        $grantType = $grant->getVariable("type");
        if ($grantType !== "N/A") {
            $typeInfo = " ($grantType)";
        }
        $grantAry = array(
            "id" => $id,
            "content" => $grant->getBaseNumber().$typeInfo,
            "group" => "Grant",
        );
        $start = $grant->getVariable("start");
        $grantAry['start'] = $start;
        $startTs = strtotime($start);

        $endTs = 0;
        if ($end = $grant->getVariable("end")) {
            $grantAry['type'] = "range";
            $grantAry['end'] = $end;
            $endTs = strtotime($end);
        } else {
            $grantAry['type'] = "box";
        }

        if ($endTs) {
            if ($maxTs < $endTs) {
                $maxTs = $endTs;
            }
        } else {
            if ($maxTs < $startTs) {
                $maxTs = $startTs;
            }
        }
        if ($minTs > $startTs) {
            $minTs = $startTs;
        }

        $grantsAndPubs[] = $grantAry;
        $id++;
    }
    return $grantsAndPubs;
}

function makePubDots($rows, $token, $server, &$id, &$minTs, &$maxTs) {
    $pubs = new Publications($token, $server);
    $pubs->setRows($rows);
    $citations = $pubs->getCitations("Included");

    $grantsAndPubs = [];

    foreach ($citations as $citation) {
        $ts = $citation->getTimestamp();
        $pmid = $citation->getPMID();
        if ($pmid) {
            $link = Links::makeLink($citation->getURL(), "PMID: ".$pmid);
        } else {
            $link = "Pub";
        }
        if ($ts) {
            $pubAry = array(
                "id" => $id,
                "content" => $link,
                "start" => date("Y-m-d", $ts),
                "group" => "Publications",
                "type" => "point",
            );
            if ($ts > $maxTs) {
                $maxTs = $ts;
            }
            if ($ts < $minTs) {
                $minTs = $ts;
            }

            $grantsAndPubs[] = $pubAry;
        }

        $id++;
    }
    return $grantsAndPubs;
}
