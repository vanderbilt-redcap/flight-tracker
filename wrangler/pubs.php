<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/Links.php");
require_once(dirname(__FILE__)."/../classes/NameMatcher.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../Application.php");

$url = Application::link("wrangler/pubs.php");
if ($_POST['request']) {
    $html = "";
    if ($_POST['request'] == "check") {
        $records = Download::recordIds($token, $server);
        $nextRecord = getNextRecordWithData($token, $server, 0);
        $html = checkForApprovals($token, $server, $pid, $event_id, $records, $nextRecord);
        echo $html;
    } else if ($_POST['request'] == "approve") {
        $records = Download::recordIds($token, $server);
        $upload = [];
        foreach ($_POST as $key => $value) {
            if ($value && preg_match("/^record_\d+:\d+$/", $key)) {
                $pair = preg_replace("/^record_/", "", $key);
                list($recordId, $instance) = preg_split("/:/", $pair);
                if (in_array($recordId, $records)) {
                    $upload[] = [
                        'record_id' => $recordId,
                        'redcap_repeat_instrument' => 'citation',
                        'redcap_repeat_instance' => $instance,
                        'citation_include' => '1',
                    ];
                }
            }
        }
        if (!empty($upload)) {
            $feedback = Upload::rows($upload, $token, $server);
        }
        $nextRecord = getNextRecordWithData($token, $server, 0);   // after upload
        header("Location: $url&record=".$nextRecord);
    } else {
        throw new \Exception("Improper request: ".$_POST['request']);
    }
    exit();
}

if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
    $url .= "&headers=false";
}

if ($_GET['record']) {
	$record = $_GET['record'];
} else {
	$nextRecord = getNextRecordWithData($token, $server, 0);
    $autoApproveHTML = getAutoApproveHTML($nextRecord);
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/baseSelect.php");

if (!$record) {
    if ($autoApproveHTML) {
        echo "<h1>Publications Wrangler</h1>";
        echo $autoApproveHTML;
    } else {
        echo "<h1>No Data Available</h1>\n";
    }
	exit();
}


$redcapData = Download::records($token, $server, array($record));
$nextRecord = getNextRecordWithData($token, $server, $record);

$pubs = new Publications($token, $server);
$pubs->setRows($redcapData);

if (count($_POST) >= 1) {
	$pubs->saveEditText($_POST);
    if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
        header("Location: $url&record=".$record);
    } else {
        header("Location: $url&record=".$nextRecord);
    }
} else if ($record != 0) {
	echo "<input type='hidden' id='nextRecord' value='".$nextRecord."'>\n";

	if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
		echo "<div class='subnav'>\n";
		echo Links::makeDataWranglingLink($pid, "Grant Wrangler", $record, FALSE, "green")."\n";
		echo Links::makeProfileLink($pid, "Scholar Profile", $record, FALSE, "green")."\n";
		echo "<a class='yellow'>".Publications::getSelectRecord()."</a>\n";
		echo "<a class='yellow'>".Publications::getSearch()."</a>\n";

		$nextPageLink = "$url&record=".$nextRecord;
		# next record is in the same window => don't use Links class
		echo "<a class='blue' href='$nextPageLink'>View Next Record With New Data</a>\n";
		echo Links::makeLink("https://www.ncbi.nlm.nih.gov/pubmed/advanced", "Access PubMed", TRUE, "purple")."\n";

		echo "</div>\n";   // .subnav
        echo "<div id='content'>\n";
        if (function_exists("makeHelpLink")) {
            echo makeHelpLink();
        } else {
            echo \Vanderbilt\FlightTrackerExternalModule\makeHelpLink();
        }
	}

	if (isset($_GET['mssg'])) {
        echo "<div class='green shadow centered note'>".$_GET['mssg']."</div>";
	}
	echo "<p class='green shadow' id='note' style='width: 600px; margin-left: auto; margin-right: auto; text-align: center; padding: 10px; border-radius: 10px; display: none; font-size: 16px;'></p>\n";

	$html = $pubs->getEditText();
	$html .= autoResetTimeHTML($pid);
	echo $html;
	if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
		echo "</div>\n";      // #content
	}
} else {
	# record == 0
	echo "<h1>No more new citations!</h1>\n";
}

function autoResetTimeHTML($pid) {
    $url = APP_PATH_WEBROOT."ProjectGeneral/keep_alive.php?pid=".$pid;
    $minsToDelay = 10;

    $html = "
    <script>
    $(document).ready(function() {
        setTimeout(function() {
            $('#lookupTable,#newCitations,#finalCitations').bind('keyup mousemove click', function(){
                $(this).unbind('keyup mousemove click');
                $.post('$url', {}, function(data) {
                });
            });
        }, $minsToDelay * 60000);
    });
    </script>";
    return $html;
}

function getNextRecordWithData($token, $server, $currRecord) {
	$records = Download::recordIds($token, $server);
	if (method_exists("CareerDev", "filterOutCopiedRecords")) {
        $records = CareerDev::filterOutCopiedRecords($records);
    }
	$pos = 0;
	for ($i = 0; $i < count($records); $i++) {
		if ($currRecord == $records[$i]) {
			$pos = $i+1;
			break;
		}
	}

	if ($pos == count($records)) {
		return $records[0];
	}

	$pullSize = 3;
	while ($pos < count($records)) {
		$pullRecords = array();
		for ($i = $pos; ($i < count($records)) && ($i < $pos + $pullSize); $i++) {
			array_push($pullRecords, $records[$i]);
		}
		$redcapData = Download::fieldsForRecords($token, $server, array("record_id", "citation_pmid", "citation_include"), $pullRecords);
		foreach ($redcapData as $row) {
			if (($row['record_id'] > $currRecord) && $row['citation_pmid'] && ($row['citation_include'] === "")) {
				return $row['record_id'];
			}
		}
		$pos += $pullSize;
	}
	if (count($records) >= 1) {
		return $records[0];
	}
	return "";
}

function getAutoApproveHTML($defaultRecord) {
    $url = Application::link("wrangler/pubs.php");
    $imgSrc = Application::link("img/loading.gif");
    $html = "";
    $html .= "
<div id='autoApprove' class='centered'><img src='$imgSrc' alt='Loading...'></div>
<script>
$(document).ready(function() {
    $.ajax({
        url: '$url',
        type: 'POST',
        data: {
            request: 'check'
        },
        success: function(html) {
            $('#autoApprove').html(html);
        },
        error: function() {
            location.href = '$url&record=$defaultRecord';
        }
    });
});
</script>";
    return $html;
}

function getNewPMIDS($token, $server, $recordId) {
    $redcapData = Download::fieldsForRecords($token, $server, array("record_id", "citation_pmid", "citation_include"), [$recordId]);
    $pmids = [];
    foreach ($redcapData as $row) {
        if ($row['redcap_repeat_instrument'] == "citation") {
            if ($row['citation_include'] === '') {
                $pmids[$row['redcap_repeat_instance']] = $row['citation_pmid'];
            }
        }
    }
    return $pmids;
}

function checkForApprovals($token, $server, $pid, $eventId, $records, $nextRecord) {
    $html = "";
    $names = Download::names($token, $server);
    $lastNames = Download::lastnames($token, $server);
    $url = Application::link("wrangler/pubs.php");
    if (method_exists("CareerDev", "filterOutCopiedRecords")) {
        $records = CareerDev::filterOutCopiedRecords($records);
    }

    $usedRecords = [];
    $uncommonRecords = [];
    $html .= "<div class='max-width centered'>";
    $html .= "<p class='centered'>The following records have been automatically categorized as auto-approved (<span class='bolded greentext'>&check;</span>) or manual (&#9997; ) according to whether they have ".Publications::makeUncommonDefinition()." and/or ".Publications::makeLongDefinition()." last names. Please review to check if you concur with the recommendations. After you do so, you will be given the option to handle the remaining issues for each scholar (via the traditional process). To directly handle one scholar, click on their name to take you to their list of publications to wrangle.</p>";
    $html .= "<form action='$url' method='POST'><input type='hidden' name='request' value='approve'>";
    foreach ($records as $recordId) {
        $lastName = $lastNames[$recordId];
        if (NameMatcher::isCommonLastName($lastName)) {
            $isNotCommon = 0;
        } else if (NameMatcher::isShortLastName($lastName)) {
            $isNotCommon = 0;
        } else {
            $isNotCommon = 1;
        }
        $pmids = getNewPMIDs($token, $server, $recordId);
        if (count($pmids) > 0) {
            $usedRecords[] = $recordId;
            $links = [];
            $i = 0;
            foreach ($pmids as $instance => $pmid) {
                $fieldName = "record_".$recordId.":".$instance;
                $html .= "<input type='hidden' id='$fieldName' name='$fieldName' class='record_$recordId' value='$isNotCommon'>";
                $spanId = "record_$recordId"."_pmid_$pmid";
                $link = "<span id='$spanId'>";
                $link .= Links::makeLink(Citation::getURLForPMID($pmid), "PMID".$pmid, TRUE)."<sup><a href='javascript:;' class='nounderline' onclick='removePMIDFromAutoApprove(\"$recordId\", \"$instance\", \"$pmid\");'>[X]</a></sup>";
                if ($i + 1 != count($pmids)) {
                    $link .= ", ";
                }
                $link .= "</span>";
                $links[] = $link;
                $i++;
            }
            $name = $names[$recordId];
            if (count($pmids) == 1) {
                $publications = "publication";
            } else {
                $publications = "publications";
            }
            $html .= "<div style='margin: 28px 0;'><p class='centered smallmargin'><a href='$url&record=$recordId' target='_NEW'>Record $recordId: <span class='bolded bigger'>$name</span></a> (".count($pmids)." new $publications)</p>";
            if ($isNotCommon) {
                $linkStyle = "";
                $noteStyle = "style='display: none;'";
                $excludeBecause = "Your specific request: Therefore,";
                $excludeQuestionStyle = "";
                $includeBecause = "Uncommon last name: Therefore, recommend";
                $includeQuestionStyle = "style='display: none;'";
            } else {
                $linkStyle = "style='display: none;'";
                $noteStyle = "";
                if (NameMatcher::isShortLastName($lastName)) {
                    $excludeBecause = "Short last name: Therefore, recommend";
                } else {
                    $excludeBecause = "Common last name: Therefore, recommend";
                }
                $excludeQuestionStyle = "style='display: none;'";
                $includeBecause = "Your specific request: Therefore,";
                $includeQuestionStyle = "";
            }
            $html .= "<p class='centered smallmargin'><span id='include_$recordId' $includeQuestionStyle>$excludeBecause handling manually &#9997; . <a href='javascript:;' onclick='includeWholeRecord(\"$recordId\");'>Override &amp; Auto-Approve?</a></span>&nbsp;&nbsp;&nbsp;<span id='exclude_$recordId' $excludeQuestionStyle>$includeBecause approving <span class='bolded greentext'>&check;</span>. <a href='javascript:;' onclick='excludeWholeRecord(\"$recordId\");'>Override &amp; Handle Manually?</a></p>";
            $html .= "<p class='centered smallmargin' id='links_$recordId' $linkStyle>".implode("", $links)."</p>";
            $html .= "<p class='centered smallmargin' id='note_$recordId' $noteStyle>(You will wrangle new publications individually.)</p>";
            $html .= "</div>";
        }
    }
    $html .= "<p class='centered'><button>Auto-Approve Publications</button></p></form>";
    $html .= "<p class='centered smaller'><a href='$url&record=$nextRecord'>Click here to manually review all records.</a></p>";
    $html .= "</div>";

    if (count($usedRecords) == 0) {
        return "<p class='centered'>No new data to automatically approve. <a href='$url&record=$nextRecord'>Click here to review other records.</a></p>";
    }
    return $html;
}
