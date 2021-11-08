<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Patents;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\ExcludeList;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

if (isset($_GET['test'])) {
    ini_set('display_startup_errors',"1");
    ini_set('display_errors',1);
    error_reporting(-1);
}

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$wranglerType = "";
$wranglerTypeParam = "";
$records = Download::recordIds($token, $server);
$url = Application::link("wrangler/include.php");
$html = "";
try {
    $validWranglerTypes = ["Publications", "Patents", "Grants"];
    foreach ($validWranglerTypes as $wt) {
        if ($wt == REDCapManagement::sanitize($_GET['wranglerType'])) {
            $wranglerType = $wt;
            $wranglerTypeParam = "&wranglerType=".urlencode($wranglerType);
            break;
        }
    }
    if (!in_array($wranglerType, $validWranglerTypes)) {
        throw new \Exception("Invalid wrangler type!");
    }
} catch(\Exception $e) {
    Application::reportException($e);
}
if (isset($_POST['request'])) {
    if ($_POST['request'] == "check") {
        $nextRecord = getNextRecordWithData($token, $server, 0, $wranglerType, $records);
        $html = checkForApprovals($token, $server, $records, $nextRecord, $url.$wranglerTypeParam, $wranglerType);
        echo $html;
    } else if ($_POST['request'] == "approve") {
        $upload = [];
        foreach ($_POST as $key => $value) {
            $key = REDCapManagement::sanitize($key);
            $value = REDCapManagement::sanitize($value);
            if ($value && preg_match("/^record_\d+:\d+$/", $key)) {
                $pair = preg_replace("/^record_/", "", $key);
                list($recordId, $instance) = preg_split("/:/", $pair);
                if (in_array($recordId, $records)) {
                    if ($wranglerType == "Publications") {
                        $upload[] = [
                            'record_id' => $recordId,
                            'redcap_repeat_instrument' => 'citation',
                            'redcap_repeat_instance' => $instance,
                            'citation_include' => '1',
                        ];
                    } else if ($wranglerType == "Patents") {
                        $upload[] = [
                            'record_id' => $recordId,
                            'redcap_repeat_instrument' => 'citation',
                            'redcap_repeat_instance' => $instance,
                            'patent_include' => '1',
                        ];
                    } else {
                        throw new \Exception("Invalid wrangler type $wranglerType");
                    }
                }
            }
        }
        if (!empty($upload)) {
            Upload::rows($upload, $token, $server);
        }
        $nextRecord = getNextRecordWithData($token, $server, 0, $wranglerType, $records);   // after upload
        header("Location: $url$wranglerTypeParam&record=".$nextRecord);
    } else {
        $request = REDCapManagement::sanitize($_POST['request']);
        throw new \Exception("Improper request: ".$request);
    }
    exit();
}

$autoApproveHTML = "";
$record = FALSE;
try {
    if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
        $url .= "&headers=false";
    }

    if ($_GET['record']) {
        $record = REDCapManagement::getSanitizedRecord($_GET['record'], $records);
    } else {
        $nextRecord = getNextRecordWithData($token, $server, 0, $wranglerType, $records);
        $autoApproveHTML = getAutoApproveHTML($nextRecord, $url.$wranglerTypeParam);
    }
} catch(\Exception $e) {
    Application::reportException($e);
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/baseSelect.php");

try {
    if (!$record) {
        if ($autoApproveHTML) {
            echo "<h1>".ucfirst($wranglerType)." Wrangler</h1>";
            echo $autoApproveHTML;
        } else {
            echo "<h1>No Data Available</h1>\n";
        }
        exit();
    }

    $metadata = Download::metadata($token, $server);
    $institutions = Download::institutionsAsArray($token, $server);
    if ($wranglerType == "Publications") {
        $fields = Application::getCitationFields($metadata);
    } else if ($wranglerType == "Patents") {
        $fields = Application::getPatentFields($metadata);
    } else {
        throw new \Exception("Invalid wrangler type $wranglerType");
    }

    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$record]);
    $nextRecord = getNextRecordWithData($token, $server, $record, $wranglerType, $records);

    if ($wranglerType == "Publications") {
        $excludeList = new ExcludeList("Publications", $pid, [], $metadata);
        $pubs = new Publications($token, $server);
        $pubs->setRows($redcapData);
        $html = $excludeList->makeEditForm($record).$pubs->getEditText();
    } else if ($wranglerType == "Patents") {
        # no exclude list (yet)
        $lastNames = Download::lastnames($token, $server);
        $firstNames = Download::firstnames($token, $server);
        $patents = new Patents($record, $pid, $firstNames[$record], $lastNames[$record], $institutions[$record]);
        $patents->setRows($redcapData);
        $html = $patents->getEditText();
    }

    if (count($_POST) >= 1) {
        if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
            header("Location: $url$wranglerTypeParam&record=".$record);
        } else {
            header("Location: $url$wranglerTypeParam&record=".$nextRecord);
        }
    } else if ($record != 0) {
        echo "<input type='hidden' id='nextRecord' value='".$nextRecord."'>\n";

        if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
            echo "<div class='subnav'>\n";
            echo Links::makeDataWranglingLink($pid, "Grant Wrangler", $record, FALSE, "green")."\n";
            echo Links::makePubWranglingLink($pid, "Publication Wrangler", $record, FALSE, "green")."\n";
            echo Links::makePatentWranglingLink($pid, "Patent Wrangler", $record, FALSE, "green")."\n";
            echo Links::makeProfileLink($pid, "Scholar Profile", $record, FALSE, "green")."\n";
            echo "<a class='yellow'>".Publications::getSelectRecord()."</a>\n";
            echo "<a class='yellow'>".Publications::getSearch()."</a>\n";

            $nextPageLink = "$url$wranglerTypeParam&record=".$nextRecord;
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
            $mssg = REDCapManagement::sanitize($_GET['mssg']);
            echo "<div class='green shadow centered note'>$mssg</div>";
        }
        echo "<p class='green shadow' id='note' style='width: 600px; margin-left: auto; margin-right: auto; text-align: center; padding: 10px; border-radius: 10px; display: none; font-size: 16px;'></p>\n";
        echo "<p class='centered'>To undo any actions made here, open the Citation form in the given REDCap record and change the answer for the <b>Include?</b> question. Yes means accepted; no means omitted; blank means yet-to-be wrangled.</p>";

        $html .= autoResetTimeHTML($pid);
        echo $html;
        if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
            echo "</div>\n";      // #content
        }
    } else {
        # record == 0
        echo "<h1>Nothing more to wrangle!</h1>\n";
    }
} catch(\Exception $e) {
    Application::reportException($e);
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

function getNextRecordWithData($token, $server, $currRecord, $wranglerType, $records) {
	if (method_exists("\\Vanderbilt\\FlightTrackerExternalModule\\CareerDev", "filterOutCopiedRecords")) {
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

	list($instrument, $indexField, $includeField) = getFieldsForWrangler($wranglerType);
	$pullSize = 3;
	while ($pos < count($records)) {
		$pullRecords = array();
		for ($i = $pos; ($i < count($records)) && ($i < $pos + $pullSize); $i++) {
			array_push($pullRecords, $records[$i]);
		}
		$redcapData = Download::fieldsForRecords($token, $server, ["record_id", $indexField, $includeField], $pullRecords);
		foreach ($redcapData as $row) {
			if (($row['redcap_repeat_instrument'] == $instrument)
                && ($row['record_id'] > $currRecord)
                && $row[$indexField]
                && ($row[$includeField] === "")) {
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

function getAutoApproveHTML($defaultRecord, $url) {
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

function getNewNumbers($token, $server, $recordId, $wranglerType) {
    list($instrument, $indexField, $includeField) = getFieldsForWrangler($wranglerType);
    $fields = ["record_id", $indexField, $includeField];
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $numbers = [];
    foreach ($redcapData as $row) {
        if ($row['redcap_repeat_instrument'] == $instrument) {
            if ($row[$includeField] === '') {
                $numbers[$row['redcap_repeat_instance']] = $row[$indexField];
            }
        }
    }
    return $numbers;
}

function checkForApprovals($token, $server, $records, $nextRecord, $url, $wranglerType) {
    $html = "";
    $names = Download::names($token, $server);
    if (method_exists("\\Vanderbilt\\FlightTrackerExternalModule\\CareerDev", "filterOutCopiedRecords")) {
        $records = CareerDev::filterOutCopiedRecords($records);
    }
    $lastNames = Download::lastnames($token, $server);

    $usedRecords = [];
    $html .= "<div class='max-width centered'>";
    $html .= "<p class='centered'>The following records have been automatically categorized as auto-approved (<span class='bolded greentext'>&check;</span>) or manual (&#9997; ) according to whether they have ".Publications::makeUncommonDefinition()." and/or ".Publications::makeLongDefinition()." last names. Please review to check if you concur with the recommendations. After you do so, you will be given the option to handle the remaining issues for each scholar (via the traditional process). To directly handle one scholar, click on their name to take you to their list of ".strtolower($wranglerType)." to wrangle.</p>";
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
        $numbers = getNewNumbers($token, $server, $recordId, $wranglerType);
        if (count($numbers) > 0) {
            $usedRecords[] = $recordId;
            $links = [];
            $i = 0;
            foreach ($numbers as $instance => $number) {
                $fieldName = "record_".$recordId.":".$instance;
                $html .= "<input type='hidden' id='$fieldName' name='$fieldName' class='record_$recordId' value='$isNotCommon'>";
                $spanId = "record_$recordId"."_idx_$number";
                $link = "<span id='$spanId'>";
                if ($wranglerType == "Publications") {
                    $linkURL = Citation::getURLForPMID($number);
                    $linkTitle = "PMID".$number;
                } else if ($wranglerType == "Patents") {
                    $linkURL = Patents::getURLForPatent($number);
                    $linkTitle = $number;
                } else {
                    throw new \Exception("Invalid wrangler type $wranglerType");
                }
                $link .= Links::makeLink($linkURL, $linkTitle, TRUE)."<sup><a href='javascript:;' class='nounderline' onclick='removePMIDFromAutoApprove(\"$recordId\", \"$instance\", \"$number\");'>[X]</a></sup>";
                if ($i + 1 != count($numbers)) {
                    $link .= ", ";
                }
                $link .= "</span>";
                $links[] = $link;
                $i++;
            }
            $name = $names[$recordId];
            $title = substr(strtolower($wranglerType), 0, strlen($wranglerType) - 1);   // remove s
            if (count($numbers) > 1) {
                $title .= "s";
            }
            $html .= "<div style='margin: 28px 0;'><p class='centered smallmargin'><a href='$url&record=$recordId' target='_NEW'>Record $recordId: <span class='bolded bigger'>$name</span></a> (".count($numbers)." new $title)</p>";
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
            $html .= "<p class='centered smallmargin' id='note_$recordId' $noteStyle>(You will wrangle new ".strtolower($wranglerType)." individually.)</p>";
            $html .= "</div>";
        }
    }
    $html .= "<p class='centered'><button>Auto-Approve $wranglerType</button></p></form>";
    $html .= "<p class='centered smaller'><a href='$url&record=$nextRecord'>Click here to manually review all records.</a></p>";
    $html .= "</div>";

    if (count($usedRecords) == 0) {
        return "<p class='centered'>No new data to automatically approve. <a href='$url&record=$nextRecord'>Click here to review other records.</a></p>";
    }
    return $html;
}

function getFieldsForWrangler($wranglerType) {
    if ($wranglerType == "Publications") {
        $indexField = "citation_pmid";
        $includeField = "citation_include";
        $instrument = "citation";
    } else if ($wranglerType == "Patents") {
        $indexField = "patent_number";
        $includeField = "patent_include";
        $instrument = "patent";
    } else {
        throw new \Exception("Invalid wrangler type $wranglerType");
    }
    return [$instrument, $indexField, $includeField];
}
