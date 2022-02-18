<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

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

	$recordId = isset($_GET['record']) ? REDCapManagement::getSanitizedRecord($_GET['record'], $records) : $records[0];
    $nextRecord = $records[0];
	for ($i = 0; $i < count($records); $i++) {
		if (($records[$i] == $recordId) && ($i + 1 < count($records))) {
		    $nextRecord = $records[$i + 1];
		    break;
		}
	}
	$rows = Download::records($token, $server, [$recordId]);

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
	foreach ($classes as $c) {
        $id = 1;
        $maxTs[$c] = 0;
        $minTs[$c] = time();
        $grantsAndPubs[$c] = [];

        if ($c == "All") {
            if (CareerDev::isVanderbilt()) {
                $fieldsWithData = REDCapManagement::getFieldsWithData($rows, $recordId);
                $submissionTimestamps = [];
                if (in_array("coeussubmission_ip_number", $fieldsWithData)) {
                    # first priority
                    list($coeusSubmissions, $coeusSubmissionTimestamps) = makeCoeusSubmissions($rows, $recordId, $pid, $event_id, $id);
                    list($coeusAwards, $coeusAwardTimestamps) = makeCoeusAwards($rows, $recordId, $pid, $event_id, $id);
                    $grantsAndPubs[$c] = array_merge($grantsAndPubs[$c], $coeusSubmissions, $coeusAwards);
                    $submissionTimestamps = array_merge($coeusSubmissionTimestamps, $coeusAwardTimestamps);
                } else if (in_array("coeus2_id", $fieldsWithData)) {
                    # second priority
                    list($coeus2Submissions, $submissionTimestamps) = makeCoeus2Submissions($rows, $recordId, $pid, $event_id, $id);
                    $grantsAndPubs[$c] = array_merge($grantsAndPubs[$c], $coeus2Submissions);
                }
            }
            if (!empty($submissionTimestamps)) {
                $hasSubmissions = TRUE;
            }

            list($customSubmissions, $customSubmissionTimestamps) = makeCustomSubmissions($rows, $recordId, $pid, $event_id, $id);
            $grantsAndPubs = array_merge($grantsAndPubs, $customSubmissions);
            $submissionTimestamps = array_merge($submissionTimestamps, $customSubmissionTimestamps);
            if (!empty($customSubmissionTimestamps)) {
                $hasSubmissions = TRUE;
            }

            foreach ($submissionTimestamps as $submissionTs) {
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

        $grants = new Grants($token, $server);
        $grants->setRows($rows);
        $grants->compileGrants();
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
            $pubDots = makePubDots($rows, $token, $server, $id);
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
	echo "<p style='text-align: center;'><a href='timeline.php?pid=$pid&record=$nextRecord&next'>Next Record</a></p>\n";
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

function makeCoeusAwards($rows, $recordId, $pid, $event_id, &$id) {
    $grantsAndPubs = [];
    $submissionTimestamps = [];
    foreach ($rows as $row) {
        $awardStatus = "Awarded";
        $awardNo = $row['coeus_award_no'];
        $title = $row['coeus_title'];
        $instrument = "coeus";
        $instance = $row['redcap_repeat_instance'];
        $submissionDate = $row['coeus_award_create_date'];
        checkRowForValidity($row, $pid, $recordId, $event_id, $grantsAndPubs, $submissionTimestamps, $id, $instrument, $instance, $submissionDate, $awardNo, $awardStatus, $title);
    }
    return [$grantsAndPubs, $submissionTimestamps];
}

function makeCoeusSubmissions($rows, $recordId, $pid, $event_id, &$id) {
    $grantsAndPubs = [];
    $submissionTimestamps = [];
    foreach ($rows as $row) {
        $awardStatus = $row['coeussubmission_proposal_status'];
        $awardNo = $row['coeussubmission_sponsor_proposal_number'];
        $title = $row['coeussubmission_title'];
        $instrument = "coeus_submission";
        $instance = $row['redcap_repeat_instance'];
        $submissionDate = $row['coeussubmission_proposal_create_date'];
        checkRowForValidity($row, $pid, $recordId, $event_id, $grantsAndPubs, $submissionTimestamps, $id, $instrument, $instance, $submissionDate, $awardNo, $awardStatus, $title);
    }
    return [$grantsAndPubs, $submissionTimestamps];
}

function makeCoeus2Submissions($rows, $recordId, $pid, $event_id, &$id) {
    $grantsAndPubs = [];
    $submissionTimestamps = [];
    foreach ($rows as $row) {
        $awardStatus = $row['coeus2_award_status'];
        $awardNo = $row['coeus2_agency_grant_number'];
        $title = $row['coeus2_title'];
        $instrument = "coeus2";
        $instance = $row['redcap_repeat_instance'];
        $submissionDate = $row['coeus2_submitted_to_agency'];
        checkRowForValidity($row, $pid, $recordId, $event_id, $grantsAndPubs, $submissionTimestamps, $id, $instrument, $instance, $submissionDate, $awardNo, $awardStatus, $title);
    }
    return [$grantsAndPubs, $submissionTimestamps];
}

function checkRowForValidity($row, $pid, $recordId, $event_id, &$grantsAndPubs, &$submissionTimestamps, &$id, $instrument, $instance, $submissionDate, $awardNo, $awardStatus, $title) {
    $skipNumbers = [];
    $validStatuses = ["Awarded", "Unfunded"];
    if (($row['redcap_repeat_instrument'] == $instrument)
        && !in_array($awardNo, $skipNumbers)
        && in_array($awardStatus, $validStatuses)) {

        $submissionTs = strtotime($submissionDate);
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
        $grantAry['content'] = Links::makeFormLink($pid, $recordId, $event_id, $grantNumber, $instrument, $instance);
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

        array_push($grantsAndPubs, $grantAry);
        $id++;
    }
    return $grantsAndPubs;
}

function makePubDots($rows, $token, $server, &$id) {
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

            array_push($grantsAndPubs, $pubAry);
        }

        $id++;
    }
    return $grantsAndPubs;
}

function makeCustomSubmissions($rows, $recordId, $pid, $event_id, &$id) {
    $grantsAndPubs = [];
    $submissionTimestamps = [];
    foreach ($rows as $row) {
        $awardType = $row['custom_type'];
        if ($awardType == 98) {   // Submission
            $awardStatusIdx = $row['custom_submission_status'] ?? "";
            if ($awardStatusIdx == 1) {
                $awardStatus = "Awarded";
            } else if ($awardStatusIdx == 2) {
                $awardStatus = "Pending";
            } else if ($awardStatusIdx == 3) {
                $awardStatus = "Unfunded";
            } else {
                $awardStatus = "";
            }
            $awardNo = $row['custom_number'];
            $title = $row['custom_title'];
            $instrument = "custom_grant";
            $instance = $row['redcap_repeat_instance'];
            $submissionDate = $row['custom_submission_date'] ?? "";
            checkRowForValidity($row, $pid, $recordId, $event_id, $grantsAndPubs, $submissionTimestamps, $id, $instrument, $instance, $submissionDate, $awardNo, $awardStatus, $title);
        }
    }
    return [$grantsAndPubs, $submissionTimestamps];
}

