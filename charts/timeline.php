<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Links;

require_once(dirname(__FILE__)."/../CareerDev.php");

?>
<script src="<?= CareerDev::link("/charts/vis.min.js") ?>"></script>
<link href="<?= CareerDev::link("charts/vis.min.css") ?>" rel="stylesheet" type="text/css" />
<link href='<?= CareerDev::link("/css/career_dev.css") ?>' type='text/css' />
<?php

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Grants.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Links.php");

?>

<style>
body { font-size: 12px; }
#visualization { background-color: white; }
.Unfunded { border-color: #888888; }
.Awarded { border-color: red; }
</style>

<?php

	$records = Download::recordIds($token, $server);
	$grantsAndPubs = array();

	$recordId = $records[0];
	for ($i = 0; $i < count($records); $i++) {
		if ($records[$i] == $_GET['record']) {
			$recordId = $records[$i];
			if ($i + 1 < count($records)) {
				$nextRecord = $records[$i + 1];
			} else {
				$nextRecord = $records[0];
			}
		}
	}
	$rows = Download::records($token, $server, array($recordId));

	$id = 1;
	$maxTs = 0;
	$minTs = time();

	if (CareerDev::isVanderbilt()) {
	    list($coeus2Submissions, $submissionTimestamps) = makeCoeus2Submissions($rows, $recordId, $pid, $event_id, $id);
        $grantsAndPubs = array_merge($grantsAndPubs, $coeus2Submissions);
        foreach ($submissionTimestamps as $submissionTs) {
            if ($submissionTs) {
                if ($maxTs < $submissionTs) {
                    $maxTs = $submissionTs;
                }
                if ($minTs > $submissionTs) {
                    $minTs = $submissionTs;
                }
            }
        }
    }

    $grants = new Grants($token, $server);
    $grants->setRows($rows);
    $grantClass = CareerDev::getSetting("grant_class", $pid);
    $grantAry = makeTrainingDatesBar($rows, $id, $minTs, $maxTs, ($grantClass == "T"));
    if ($grantAry) {
        $grantsAndPubs[] = $grantAry;
    }
    if ($grants->getCount("prior") > 0) {
        $grantBars = makeGrantBars($grants, $id, $minTs, $maxTs);
        $grantsAndPubs = array_merge($grantsAndPubs, $grantBars);
    }

    $pubDots = makePubDots($rows, $token, $server, $id);
    $grantsAndPubs = array_merge($grantsAndPubs, $pubDots);

	$currTs = time();
	if ($maxTs < $currTs) {
		$maxTs = $currTs;
	}

	$spacing = ($maxTs + 90 * 24 * 3600 - $minTs) / 6;
	$maxTs += $spacing;
	$minTs -= $spacing;

	$name = "";
	foreach ($rows as $row) {
		if ($row['redcap_repeat_instrument'] == "") {
			$name = $row['identifier_first_name']." ".$row['identifier_last_name'];
		}
	}

if (isset($_GET['next'])) {
	echo "<h1>$recordId: $name</h1>\n";
	echo "<p style='text-align: center;'><a href='timeline.php?pid=$pid&record=$nextRecord&next'>Next Record</a></p>\n";
}
?>

<div id="visualization"></div>

<script type="text/javascript">
window.onload = function() {
	// DOM element where the Timeline will be attached
	var container = document.getElementById('visualization');

	// Create a DataSet (allows two way data-binding)
	var items = new vis.DataSet(<?= json_encode($grantsAndPubs) ?>);

	// Configuration for the Timeline
	var options = { start: <?= json_encode(date("Y-m-d", $minTs)) ?>, end: <?= json_encode(date("Y-m-d", $maxTs)) ?> };

	// Create a Timeline
	var timeline = new vis.Timeline(container, items, options);
};
</script>


<?php

function makeCoeus2Submissions($rows, $recordId, $pid, $event_id, &$id) {
    $skipNumbers = ["000"];
    $validStatuses = ["Awarded", "Unfunded"];
    $grantsAndPubs = [];
    $submissionTimestamps = [];
    foreach ($rows as $row) {
        if (($row['redcap_repeat_instrument'] == "coeus2")
            && !in_array($row['coeus2_agency_grant_number'], $skipNumbers)
            && in_array($row['coeus2_award_status'], $validStatuses)) {

            $submissionTs = strtotime($row['coeus2_submitted_to_agency']);
            $submissionTimestamps[] = $submissionTs;

            $grantAry = [];
            $grantAry['id'] = $id;
            $grantAry['start'] = date("Y-m-d", $submissionTs);
            $grantAry['className'] = $row['coeus2_award_status'];
            $grantAry['type'] = "point";
            $grantNumber = $row['coeus2_agency_grant_number'];
            $titleLength = 25;
            if (strlen($row['coeus2_title']) > $titleLength) {
                $truncatedTitle = substr($row['coeus2_title'], 0, $titleLength)."...";
            } else {
                $truncatedTitle = $row['coeus2_title'];
            }
            if ($grantNumber) {
                $grantNumber .= " Application";
            } else {
                $grantNumber = $row['coeus2_award_status'].": ".$truncatedTitle;
            }
            $grantAry['content'] = Links::makeFormLink($pid, $recordId, $event_id, $grantNumber, "coeus2", $row['redcap_repeat_instance']);
            $grantsAndPubs[] = $grantAry;
            $id++;
        }
    }
    return [$grantsAndPubs, $id, $submissionTimestamps];
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

function makeGrantBars($grants, &$id, &$minTs, &$maxTs) {
    $grantsAndPubs = [];
    foreach ($grants->getGrants("prior") as $grant) {
        $grantAry = array(
            "id" => $id,
            "content" => $grant->getBaseNumber() . " (" . $grant->getVariable("type") . ")",
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
