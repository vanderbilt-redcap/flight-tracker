<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Patents;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\ExcludeList;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

if (isset($_GET['test'])) {
    ini_set('display_startup_errors',"1");
    ini_set('display_errors',"1");
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
    $validWranglerTypes = ["Publications", "Patents", "Grants", "FlagPublications"];
    foreach ($validWranglerTypes as $wt) {
        if ($wt == Sanitizer::sanitize($_GET['wranglerType'])) {
            $wranglerType = $wt;
            $wranglerTypeParam = "&wranglerType=".urlencode($wranglerType);
            break;
        }
    }
    if (!in_array($wranglerType, $validWranglerTypes)) {
        throw new \Exception("Invalid wrangler type!");
    }
} catch(\Exception $e) {
    echo Application::reportException($e);
}
if (isset($_POST['request'])) {
    if ($_POST['request'] == "check") {
        $records = Download::recordIds($token, $server);
        $nextRecord = getNextRecordWithData($token, $server, 0, $wranglerType, $records);
        $html = checkForApprovals($token, $server, $records, $nextRecord, $url.$wranglerTypeParam, $wranglerType);
        echo $html;
    } else if ($_POST['request'] == "approve") {
        $records = Download::recordIds($token, $server);
        $upload = [];
        foreach ($_POST as $key => $value) {
            if ($value && preg_match("/^record_\d+:[^:]+:\d+$/", (string) $key)) {
                $location = preg_replace("/^record_/", "", (string) $key);
                list($recordId, $instrument, $instance) = explode(":", $location);
                if (in_array($recordId, $records) && in_array($instrument, ["citation", "eric", "patent"])) {
                    $uploadRow = [
                        'record_id' => $recordId,
                        'redcap_repeat_instrument' => $instrument,
                        'redcap_repeat_instance' => $instance,
                    ];
                    if (($wranglerType == "FlagPublications") && ($instrument == "citation")) {
                        $uploadRow["citation_flagged"] = "1";
                    } else {
                        $uploadRow[$instrument."_include"] = "1";
                    }
                    $upload[] = $uploadRow;
                }
            }
        }
        if (!empty($upload)) {
            Upload::rows($upload, $token, $server);
        }
        $nextRecord = getNextRecordWithData($token, $server, 0, $wranglerType, $records);   // after upload
        $url2 = Sanitizer::sanitizeURL("$url$wranglerTypeParam&record=".$nextRecord);
        if ($nextRecord == $records[0]) {
            $url2 .= "&mssg=restart";
        }
        header("Location: $url2");
    } else {
        throw new \Exception("Improper request: ".Sanitizer::sanitize($_POST['request']));
    }
    exit();
}

$autoApproveHTML = "";
$record = FALSE;
try {
    if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
        $url .= "&headers=false";
    }

    if ($_GET['record'] == "restart") {
        $record = $records[0] ?? "";
    } else if ($_GET['record']) {
        $record = Sanitizer::getSanitizedRecord($_GET['record'], $records);
    } else {
        $nextRecord = getNextRecordWithData($token, $server, 0, $wranglerType, $records);
        $autoApproveHTML = getAutoApproveHTML($nextRecord, $url.$wranglerTypeParam);
    }
} catch(\Exception $e) {
    echo Application::reportException($e);
}

if (!$record && (count($records) > 0) && ($wranglerType == "FlagPublications")) {
    $firstRecord = $records[0];
    $_GET['record'] = $firstRecord;
    $record = $firstRecord;
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
    if (in_array($wranglerType, ["Publications", "FlagPublications"])) {
        $fields = Application::getCitationFields($metadata);
    } else if ($wranglerType == "Patents") {
        $fields = Application::getPatentFields($metadata);
    } else {
        throw new \Exception("Invalid wrangler type $wranglerType");
    }

    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$record]);
    $nextRecord = getNextRecordWithData($token, $server, $record, $wranglerType, $records);

    $thisUrl = Application::link("this")."&wranglerType=$wranglerType&record=$record";
    if (in_array($wranglerType, ["Publications", "FlagPublications"])) {
        $pubs = new Publications($token, $server);
        $pubs->setRows($redcapData);
        if ($wranglerType == "Publications") {
            $excludeList = new ExcludeList("Publications", $pid, [], $metadata);
            $html = $excludeList->makeEditForm($record).$pubs->getEditText($thisUrl);
        } else {
            $html = $pubs->getEditText($thisUrl);
        }
    } else if ($wranglerType == "Patents") {
        $lastNames = Download::lastnames($token, $server);
        $firstNames = Download::firstnames($token, $server);
        $patents = new Patents($record, $pid, $firstNames[$record], $lastNames[$record], $institutions[$record]);
        $patents->setRows($redcapData);
        $html = $patents->getEditText($thisUrl);
    }

    if (count($_POST) >= 1) {
        if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
            $url2 = Sanitizer::sanitizeURL("$url$wranglerTypeParam&record=".$record);
        } else {
            $url2 = Sanitizer::sanitizeURL("$url$wranglerTypeParam&record=".$nextRecord);
            if ($nextRecord == $records[0]) {
                $url2 .= "&mssg=restart";
            }
        }
        header("Location: $url2");
    } else if ($record != 0) {
        $nextRecordText = ($nextRecord == $records[0] ?? "") ? "restart" : $nextRecord;
        echo "<input type='hidden' id='nextRecord' value='$nextRecordText'>\n";

        if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
            echo "<div class='subnav'>\n";
            echo Links::makeDataWranglingLink($pid, "Grant Wrangler", $record, FALSE, "green")."\n";
            echo Links::makePubWranglingLink($pid, "Publication Wrangler", $record, FALSE, "green")."\n";
            echo Links::makePositionChangeWranglingLink($pid, "Position Wrangler", $record, FALSE, "green")."\n";
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
            $doneMessage = "All done! You're back at the beginning.";
            if ($_GET['mssg'] == "restart") {
                $mssg = $doneMessage;
            } else if (preg_match("/^(\d+) upload$/", $_GET['mssg'], $matches)) {
                $cnt = Sanitizer::sanitizeInteger($matches[1]);
                $item = ($cnt == 1) ? "item" : "items";
                $mssg = "$cnt $item successfully uploaded!";
                if ($_GET['record'] == "restart") {
                    $mssg .= " ".$doneMessage;
                }
            } else {
                $mssg = "This should never happen!";
            }
            echo "<div class='green shadow centered note'>$mssg</div>";
        }
        echo "<p class='green shadow' id='note' style='width: 600px; margin-left: auto; margin-right: auto; text-align: center; padding: 10px; border-radius: 10px; display: none; font-size: 16px;'></p>\n";
        echo "<p class='centered max-width'>To undo any actions made here, open the Citation form in the given REDCap record and change the answer for the <b>Include?</b> question. Yes means accepted; no means omitted; blank means yet-to-be wrangled.</p>";

        $html .= REDCapManagement::autoResetTimeHTML($pid, ["#lookupTable","#newCitations","#finalCitations"]);
        echo $html;
        if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
            echo "</div>\n";      // #content
        }
    } else {
        # record == 0
        echo "<h1>Nothing more to wrangle!</h1>\n";
    }
} catch(\Exception $e) {
    echo Application::reportException($e);
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
		return Sanitizer::sanitizeInteger($records[0]);
	}

	list($instruments, $indexFields, $includeFields) = getFieldsForWrangler($wranglerType);
	$pullSize = 3;
	while ($pos < count($records)) {
		$pullRecords = array();
		for ($i = $pos; ($i < count($records)) && ($i < $pos + $pullSize); $i++) {
			$pullRecords[] = $records[$i];
		}
		$redcapData = Download::fieldsForRecords($token, $server, array_unique(array_merge(["record_id"], $indexFields, $includeFields)), $pullRecords);
		foreach ($redcapData as $row) {
			if (in_array($row['redcap_repeat_instrument'], $instruments) && ($row['record_id'] > $currRecord)) {
                foreach ($indexFields as $i => $indexField) {
                    $includeField = $includeFields[$i] ?? "";
                    if ($row[$indexField] && ($row[$includeField] === "")) {
                        return Sanitizer::getSanitizedRecord($row['record_id'], $records);
                    }
                }
            }
		}
		$pos += $pullSize;
	}
	if (count($records) >= 1) {
        return Sanitizer::sanitizeInteger($records[0]);
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
            request: 'check',
            'redcap_csrf_token': getCSRFToken()
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
    list($instruments, $indexFields, $includeFields) = getFieldsForWrangler($wranglerType);
    $fields = array_unique(array_merge(["record_id"], $indexFields, $includeFields));
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $numbers = [];
    foreach ($redcapData as $row) {
        if (in_array($row['redcap_repeat_instrument'], $instruments)) {
            $instrument = $row['redcap_repeat_instrument'];
            foreach ($indexFields as $i => $indexField) {
                $includeField = $includeFields[$i] ?? "";
                if ($row[$indexField] && ($row[$includeField] === '')) {
                    $numbers[$instrument.":".$row['redcap_repeat_instance']] = $row[$indexField];
                }
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
    $itemPlural = ($wranglerType == "FlagPublications") ? "publications" : strtolower($wranglerType);

    $usedRecords = [];
    $html .= "<div class='max-width centered'>";
    $html .= "<p class='centered'>The following records have been automatically categorized as auto-approved (<span class='bolded greentext'>&check;</span>) or manual (&#9997; ) according to whether they have ".Publications::makeUncommonDefinition()." and/or ".Publications::makeLongDefinition()." last names. Please review to check if you concur with the recommendations. After you do so, you will be given the option to handle the remaining issues for each scholar (via the traditional process). To directly handle one scholar, click on their name to take you to their list of $itemPlural to wrangle.</p>";
    $html .= "<form action='$url' method='POST'><input type='hidden' name='request' value='approve'>";
    $html .= Application::generateCSRFTokenHTML();
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
            foreach ($numbers as $formAndInstance => $number) {
                list($instrument, $instance) = explode(":", $formAndInstance);
                $fieldName = "record_".$recordId.":".$formAndInstance;
                $html .= "<input type='hidden' id='$fieldName' name='$fieldName' class='record_$recordId' value='$isNotCommon'>";
                $spanId = "record_$recordId"."_idx_$number";
                $link = "<span id='$spanId'>";
                if (in_array($wranglerType, ["Publications", "FlagPublications"]) && ($instrument == "citation")) {
                    $linkURL = Citation::getURLForPMID($number);
                    $linkTitle = "PMID".$number;
                } else if (($wranglerType == "Publications") && ($instrument == "eric")) {
                    $linkURL = Citation::getURLForERIC($number);
                    $linkTitle = $number;
                } else if ($wranglerType == "Patents") {
                    $linkURL = Patents::getURLForPatent($number);
                    $linkTitle = $number;
                } else {
                    throw new \Exception("Invalid wrangler type $wranglerType");
                }
                $link .= Links::makeLink($linkURL, $linkTitle, TRUE)."<sup><a href='javascript:;' class='nounderline' onclick='removePMIDFromAutoApprove(\"$recordId\", \"$formAndInstance\", \"$number\");'>[X]</a></sup>";
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
    $startingRecord = $nextRecord ?: $records[0];
    $html .= "<p class='centered smaller'><a href='$url&record=$startingRecord'>Click here to manually review all records.</a></p>";
    $html .= "</div>";

    if (count($usedRecords) == 0) {
        return "<p class='centered'>No new data to automatically approve. <a href='$url&record=$startingRecord'>Click here to review other records.</a></p>";
    }
    return $html;
}

function getFieldsForWrangler($wranglerType) {
    if ($wranglerType == "Publications") {
        $indexFields = ["citation_pmid", "eric_id"];
        $includeFields = ["citation_include", "eric_include"];
        $instruments = ["citation", "eric"];
    } else if ($wranglerType == "FlagPublications") {
        $indexFields = ["citation_pmid"];
        $includeFields = ["citation_flagged"];
        $instruments = ["citation"];
    } else if ($wranglerType == "Patents") {
        $indexFields = ["patent_number"];
        $includeFields = ["patent_include"];
        $instruments = ["patent"];
    } else {
        throw new \Exception("Invalid wrangler type $wranglerType");
    }
    return [$instruments, $indexFields, $includeFields];
}
