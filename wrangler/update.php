<?php 


use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Grant;

# provides a means to reassign categories, start/end dates, etc. for outside grants
# to be run on web

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/basePHP.php");

if (isset($_GET['viz']) && isset($_POST['record'])) {
    try {
        $records = Download::recordIds($token, $server);
        $recordId = Sanitizer::getSanitizedRecord($_POST['record'], $records);
        $redcapData = Download::fieldsForRecords($token, $server, ["record_id", "summary_calculate_order"], [$recordId]);
        $json = REDCapManagement::findField($redcapData, $recordId, "summary_calculate_order");
        $order = json_decode($json, TRUE);
        $careerProgressionAry = [];
        if (!empty($order)) {
            $ai = 0;
            foreach ($order as $award) {
                $careerProgressionAry[] = \Vanderbilt\FlightTrackerExternalModule\careerprogression($award, $ai++);
            }
        }
        echo json_encode($careerProgressionAry);
    } catch (\Exception $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
} else if (isset($_GET['flags']) && isset($_POST['newState'])) {
    if ($_POST['newState'] == "on") {
        Grants::turnFlagsOn($pid);
        $returnData = ["status" => "Successfully turned on in $pid."];
    } else if ($_POST['newState'] == "off") {
        Grants::turnFlagsOff($pid);
        $returnData = ["status" => "Successfully turned off in $pid."];
    } else {
        $returnData = ["error" => "Invalid new state."];
    }
    echo json_encode($returnData);
} else if (
    isset($_GET['flag'])
    && isset($_POST['record'])
    && isset($_POST['grant'])
    && isset($_POST['value'])
) {
    try {
        $records = Download::recordIds($token, $server);
        $recordId = Sanitizer::getSanitizedRecord($_POST['record'], $records);
        $grant = Grant::trimApplicationType(Sanitizer::sanitizeWithoutChangingQuotes($_POST['grant']));
        $value = Sanitizer::sanitize($_POST['value']);
        $redcapData = Download::fieldsForRecords($token, $server, ["record_id", "summary_calculate_flagged_grants"], [$recordId]);
        $json = REDCapManagement::findField($redcapData, $recordId, "summary_calculate_flagged_grants") ?: "[]";
        $flaggedGrants = json_decode($json, TRUE);
        $toImportJSON = Sanitizer::sanitizeJSON($_POST['toImport'] ?? []);

        $canFlagRepeatingGrant = FALSE;
        $fieldsToFlag = [];
        $flagField = "";
        $instance = FALSE;
        $metadataFields = Download::metadataFields($token, $server);
        list($awardNo, $instrument) = explode("___", $grant);
        $instrument = str_replace(" ", "_", $instrument);
        $prefix = REDCapManagement::getPrefixFromInstrument($instrument);
        if (in_array($instrument, ["followup", "initial_survey"])) {
            $prefix = "";
            $fieldsToDownload = ["record_id"];
            for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
                $fieldsToDownload[] = $prefix."_grant".$i."_number";
            }
            $normativeData = Download::fieldsForRecords($token, $server, $fieldsToDownload, [$recordId]);
            $normativeRow = REDCapManagement::getNormativeRow($normativeData);
            for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
                $field = $prefix."_grant".$i."_flagged";
                $awardField = $prefix."_grant".$i."_number";
                if (in_array($field, $metadataFields) && ($normativeRow[$awardField] == $awardNo)) {
                    $fieldsToFlag[] = $field;
                }
            }
        } else {
            $flagField = $prefix ? $prefix."_flagged" : "";

            if ($prefix && $instrument && $flagField && in_array($flagField, $metadataFields)) {
                foreach (Grants::getAwardFieldsForInstrument($instrument, $token, $server) as $awardField) {
                    $awardNumbers = Download::oneFieldWithInstances($token, $server, $awardField);
                    $instances = $awardNumbers[$recordId];
                    foreach ($instances as $inst => $instanceGrant) {
                        if (Grant::trimApplicationType($instanceGrant) == $awardNo) {
                            $instance = $inst;
                            break;
                        }
                    }
                }
            }
            $canFlagRepeatingGrant = $instance && $prefix && $flagField && in_array($flagField, $metadataFields);
        }

        $upload = [];
        if ($value == "on") {
            if (!in_array($grant, $flaggedGrants)) {
                $flaggedGrants[] = $grant;
            }
            $normativeRow = [
                "record_id" => $recordId,
                "summary_calculate_flagged_grants" => json_encode($flaggedGrants),
                "summary_calculate_to_import" => $toImportJSON,
            ];
            foreach ($fieldsToFlag as $field) {
                $normativeRow[$field] = "1";
            }
            $upload[] = $normativeRow;
            if ($canFlagRepeatingGrant) {
                $upload[] = [
                    "record_id" => $recordId,
                    "redcap_repeat_instrument" => $instrument,
                    "redcap_repeat_instance" => $instance,
                    $flagField => "1",
                ];
            }
        } else if ($value == "off") {
            if (in_array($grant, $flaggedGrants)) {
                $pos = array_search($grant, $flaggedGrants);
                array_splice($flaggedGrants, $pos, 1);
            }
            $normativeRow = [
                "record_id" => $recordId,
                "summary_calculate_flagged_grants" => json_encode($flaggedGrants),
                "summary_calculate_to_import" => $toImportJSON,
            ];
            foreach ($fieldsToFlag as $field) {
                $normativeRow[$field] = "0";
            }
            $upload[] = $normativeRow;
            if ($canFlagRepeatingGrant) {
                $upload[] = [
                    "record_id" => $recordId,
                    "redcap_repeat_instrument" => $instrument,
                    "redcap_repeat_instance" => $instance,
                    $flagField => "0",
                ];
            }
        }

        if (!empty($upload)) {
            $output = Upload::rows($upload, $token, $server);
            try {
                if (isset($output['count']) || isset($output['item_count'])) {
                    Application::refreshRecordSummary($token, $server, $pid, $recordId, TRUE);
                }
                echo json_encode($output);
            } catch (\Exception $e) {
                echo json_encode(["error_summary" => $e->getMessage()]);
            }
        } else {
            echo json_encode(["error" => "No data to upload!"]);
        }
    } catch (\Exception $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
} else if (isset($_POST['toImport']) && isset($_POST['record'])) {
    $records = Download::recordIds($token, $server);
    $recordId = Sanitizer::getSanitizedRecord($_POST['record'], $records);
    $toImport = $_POST['toImport'] ? Sanitizer::sanitizeJSON($_POST['toImport']) : "{}";
	if ($toImport == "[]") {
		$toImport = "{}";
	}

	$data = array();
	$data['record_id'] = $recordId;
	$data['summary_calculate_to_import'] = $toImport;
	$record = $recordId;

    try {
        $outputData = Upload::oneRow($data, $token, $server);
        try {
            if (isset($outputData['count']) || isset($outputData['item_count'])) {
                Application::refreshRecordSummary($token, $server, $pid, $record, TRUE);
            }
            echo json_encode($outputData);
        } catch (\Exception $e) {
            echo json_encode(["error_summary" => $e->getMessage()]);
        }
    } catch (\Exception $e) {
        echo json_encode(["error_save" => $e->getMessage()]);
    }
} else {
    echo json_encode(["error" => "Improper parameters"]);
}
