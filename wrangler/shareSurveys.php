<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\DateManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(__DIR__."/../classes/Autoload.php");
require_once(__DIR__."/../small_base.php");

$pids = Application::getActivePids();
if (isset($_POST['action']) && ($_POST['action'] == "copyInstruments")) {
    $data = [];
    try {
        $statementsToRun = [];
        $paramsToRun = [];
        $records = Download::recordIdsByPid($pid);
        $destRecord = Sanitizer::getSanitizedRecord($_POST['destRecord'] ?? "", $records);
        $sourcePid = Sanitizer::sanitizePid($_POST['sourcePid'] ?? "");
        if ($sourcePid && in_array($sourcePid, $pids)) {
            $sourceRecords = Download::recordIdsByPid($sourcePid);
            $sourceRecord = Sanitizer::getSanitizedRecord($_POST['sourceRecord'] ?? "", $sourceRecords);
            $requestedInstruments = Sanitizer::sanitizeArray($_POST['instruments'] ?? []);
            $destSurveys = DataDictionaryManagement::getSurveys($pid);
            $sourceSurveys = DataDictionaryManagement::getSurveys($sourcePid);
            $destRepeatingForms = DataDictionaryManagement::getRepeatingForms($pid);
            $sourceRepeatingForms = DataDictionaryManagement::getRepeatingForms($sourcePid);
            $mismatchedRepeating = FALSE;
            $missingInstrument = FALSE;
            foreach ($requestedInstruments as $instrument) {
                if (!isset($destSurveys[$instrument]) || !isset($sourceSurveys[$instrument])) {
                    $missingInstrument = TRUE;
                }
                if (
                    (
                        in_array($instrument, $destRepeatingForms)
                        && !in_array($instrument, $sourceRepeatingForms)
                    )
                    || (
                        !in_array($instrument, $destRepeatingForms)
                        && in_array($instrument, $sourceRepeatingForms)
                    )
                ) {
                    $mismatchedRepeating = TRUE;
                }
            }
            if (($destRecord === "") || ($sourceRecord === "")) {
                $data['error'] = "Invalid record(s).";
            } else if (empty($requestedInstruments)) {
                $data['error'] = "No instruments.";
            } else if ($missingInstrument) {
                $data['error'] = "One-or-more instruments is missing.";
            } else if ($mismatchedRepeating) {
                $data['error'] = "One-or-more instruments have their repeating setting mismatched.";
            } else {
                $destMetadata = Download::metadataByPid($pid);
                $sourceMetadata = Download::metadataByPid($sourcePid);
                $fieldsToDownload = ["record_id"];
                $fieldsToCopy = [];
                foreach ($requestedInstruments as $instrument) {
                    $sourceFields = DataDictionaryManagement::getFieldsFromMetadata($sourceMetadata, $instrument);
                    $completeIndex = array_search($instrument."_complete", $sourceFields);
                    if (
                        in_array($instrument, $destRepeatingForms)
                        && ($completeIndex !== FALSE)
                    ) {
                        array_splice($sourceFields, $completeIndex, 1);
                    }
                    $destFields = DataDictionaryManagement::getFieldsFromMetadata($destMetadata, $instrument);
                    $fieldsToCopy[$instrument] = array_intersect($sourceFields, $destFields);
                    $fieldsToDownload = array_merge($fieldsToDownload, $fieldsToCopy[$instrument]);
                }
                $fieldsToDownload = array_unique($fieldsToDownload);
                if (count($fieldsToDownload) == 1) {
                    $data['error'] = "No overlapping fields were found!";
                } else {
                    $destChoices = DataDictionaryManagement::getChoices($destMetadata);
                    $sourceChoices = DataDictionaryManagement::getChoices($sourceMetadata);
                    $destData = Download::fieldsForRecordsByPid($pid, $fieldsToDownload, [$destRecord]);
                    $sourceData = Download::fieldsForRecordsByPid($sourcePid, $fieldsToDownload, [$sourceRecord]);
                    $normativeRow = [
                        "record_id" => $destRecord,
                        "redcap_repeat_instrument" => "",
                        "redcap_repeat_instance" => "",
                    ];
                    $upload = [];
                    foreach ($fieldsToCopy as $instrument => $fields) {
                        if (in_array($instrument, $destRepeatingForms)) {
                            $maxInstance = REDCapManagement::getMaxInstance($destData, $instrument, $destRecord);
                            foreach ($sourceData as $sourceRow) {
                                if ($sourceRow["redcap_repeat_instrument"] == $instrument) {
                                    $foundMirror = FALSE;
                                    foreach ($destData as $destRow) {
                                        if (($destRow['redcap_repeat_instrument'] == $instrument) && areRowsMirrored($destRow, $sourceRow, $destChoices, $sourceChoices)) {
                                            $foundMirror = TRUE;
                                            break;
                                        }
                                    }
                                    if (!$foundMirror) {
                                        $maxInstance++;
                                        $newRow = [
                                            "record_id" => $destRecord,
                                            "redcap_repeat_instrument" => $instrument,
                                            "redcap_repeat_instance" => $maxInstance,
                                        ];
                                        $params = copyRowValues($newRow, [], $sourceRow, $fields, $destChoices, $sourceChoices, $instrument, $pid, $sourcePid);
                                        if (!empty($params)) {
                                            $paramsToRun = array_merge($paramsToRun, $params);
                                        }
                                        if (count($newRow) > 3) {
                                            $upload[] = $newRow;
                                        }
                                    }
                                }
                            }
                        } else {
                            $destNormativeRow = REDCapManagement::getNormativeRow($destData);
                            $sourceNormativeRow = REDCapManagement::getNormativeRow($sourceData);
                            $params = copyRowValues($normativeRow, $destNormativeRow, $sourceNormativeRow, $fields, $destChoices, $sourceChoices, $instrument, $pid, $sourcePid);
                            if (!empty($params)) {
                                $paramsToRun = array_merge($params, $paramsToRun);
                            }
                        }
                    }
                    if (count($normativeRow) > 3) {
                        # just in case REDCap requires an order, insert at beginning
                        array_unshift($upload, $normativeRow);
                    }
                    $successMessage = "";
                    if (!empty($upload)) {
                        $feedback = (array) Upload::rowsByPid($upload, $pid);
                        if ($feedback['error']) {
                            $data['error'] = $feedback['error'];
                        } else if ($feedback['item_count'] || $feedback['count']) {
                            # can use API or REDCap::getData() return format
                            $numItemsCopied = $feedback['item_count'] ?? $feedback['count'];
                            $successMessage = "$numItemsCopied item(s) successfully copied.";
                        } else {
                            $successMessage = count($upload)." row(s) successfully copied.";
                        }
                    } else {
                        $successMessage = "No new data to copy.";
                    }
                    if ($successMessage) {
                        $module = Application::getModule();
                        if (!empty($paramsToRun)) {
                            $sql = "INSERT INTO redcap_surveys_response
                    (participant_id, record, first_submit_time, completion_time, instance, start_time)
                    VALUES";
                            for ($i = 0; $i < count($paramsToRun); $i += 6) {
                                if ($i > 0) {
                                    $sql .= ",";
                                }
                                $sql .= " (?, ?, ?, ?, ?, ?)";
                            }
                            $module->query($sql, $paramsToRun);
                        }
                        # wait until last, after any Exceptions could be thrown, so that we won't improperly mark it as successful
                        $data['result'] = $successMessage;
                    }
                }
            }
        } else {
            $data['error'] = "Invalid source pid.";
        }
    } catch (\Exception $e) {
        $data['error'] = $e->getMessage();
    }
    echo json_encode($data);
    exit;
}

require_once(__DIR__."/../charts/baseWeb.php");

$additionalSurveys = CareerDev::getAdditionalSurveys($pid);
echo "<h1>Share/Pull Data For Additional Surveys</h1>";
echo "<p class='centered max-width'>Please note that the survey/instrument names, field names, and settings for repeating instruments much match in order for copying to work. In the case of repeating instruments, only the latest survey will be copied.</p>";
if (!empty($additionalSurveys)) {
    $matchedPids = [];
    foreach ($pids as $currPid) {
        if ($currPid != $pid) {
            $currAdditionalSurveys = CareerDev::getAdditionalSurveys($currPid);
            foreach ($currAdditionalSurveys as $instrument => $label) {
                if (isset($additionalSurveys[$instrument])) {
                    if (!isset($matchedPids[$currPid])) {
                        $matchedPids[$currPid] = [];
                    }
                    $matchedPids[$currPid][] = $instrument;
                }
            }
        }
    }
    if (empty($matchedPids)) {
        echo "<p class='centered max-width'>No surveys on other projects match the name of your surveys! All existing Flight Tracker surveys are already shared weekly.</p>";
    } else {
        $firstNames = [ $pid => Download::firstnames($token, $server) ];
        $lastNames = [ $pid => Download::lastnames($token, $server) ];
        $projectTitles = [];
        $candidatesToCopy = [];
        foreach ($matchedPids as $currPid => $instrumentsToShare) {
            $currToken = Application::getSetting("token", $currPid);
            $currServer = Application::getSetting("server", $currPid);
            $firstNames[$currPid] = Download::firstnames($currToken, $currServer);
            $lastNames[$currPid] = Download::lastnames($currToken, $currServer);
            $projectTitles[$currPid] = Download::projectTitle($currToken, $currServer);
            $projectTitles[$currPid] = preg_replace("/Flight Tracker\s*-?\s*/", "", $projectTitles[$currPid]);
            foreach ($firstNames[$pid] as $recordId => $thisFN) {
                $thisLN = $lastNames[$pid][$recordId] ?? "";
                foreach ($firstNames[$currPid] as $currRecordId => $currFN) {
                    $currLN = $lastNames[$currPid][$currRecordId] ?? "";
                    if (NameMatcher::matchName($thisFN, $thisLN, $currFN, $currLN)) {
                        if (!isset($candidatesToCopy[$currPid])) {
                            $candidatesToCopy[$currPid] = ["instruments" => $instrumentsToShare, "matches" => []];
                        }
                        $candidatesToCopy[$currPid]["matches"][] = "$recordId:$currRecordId";
                    }
                }
            }
        }
        if (empty($candidatesToCopy)) {
            echo "<p class='centered max-width'>No names match on the other ".count($matchedPids)." projects with matching surveys! All existing Flight Tracker surveys are already shared weekly.</p>";
        } else {
            echo "<table class='centered max-width'>";
            echo "<thead><tr class='stickyGrey'><th>Destination Record</th><th>Source Project</th><th>Source Record</th><th>Instrument(s)</th><th>Act</th></tr></thead>";
            echo "<tbody>";
            $i = 0;
            foreach ($candidatesToCopy as $currPid => $info) {
                $instrumentsToShare = $info['instruments'];
                $matches = $info['matches'];
                $projectTitle = $projectTitles[$currPid];
                $currRepeatingForms = DataDictionaryManagement::getRepeatingForms($currPid);
                foreach ($matches as $match) {
                    list($recordId, $currRecordId) = explode(":", $match);
                    $name = NameMatcher::formatName($firstNames[$pid][$recordId] ?? "", "", $lastNames[$pid][$recordId] ?? "");
                    $currName = NameMatcher::formatName($firstNames[$currPid][$currRecordId] ?? "", "", $lastNames[$currPid][$currRecordId] ?? "");
                    $instrumentLabels = [];
                    foreach ($instrumentsToShare as $instrument) {
                        $currInstance = REDCapManagement::getSurveyMaxInstance($currPid, $currRecordId, $instrument);
                        $currLastDateHTML = "";
                        if ($currInstance) {
                            $currResponseId = REDCapManagement::getSurveyResponseId($currPid, $currRecordId, $currInstance, $instrument);
                            if ($currResponseId) {
                                $currLastDate = DateManagement::getDateFromTimestamp(REDCapManagement::getSurveyResponseField($currResponseId, "completion_time"));
                                $currLastDateHTML = $currLastDate ? "<div class='smaller'>[Latest: ".DateManagement::YMD2MDY($currLastDate)."]</div>" : "";
                            }
                        }
                        $instrumentLabels[] = $additionalSurveys[$instrument].$currLastDateHTML;
                    }
                    $instrumentJSON = json_encode($instrumentsToShare);
                    $rowClass = ($i % 2 == 0) ? "even" : "odd";
                    echo "<tr class='$rowClass paddedRow'><td>$recordId: $name</td><td>$projectTitle<div class='smaller'>[pid $currPid]</div></td><td>$currRecordId: $currName</td><td>".implode("<br/>", $instrumentLabels)."</td><td><button onclick='copyInstruments(\"$recordId\", \"$currPid\", \"$currRecordId\", $instrumentJSON); return false;'>Refresh Latest Now</button></td></tr>";
                    $i++;
                }
            }
            echo "</tbody></table>";

            $csrfToken = Application::generateCSRFToken();
            $thisUrl = Application::link("this");
            echo "<script>
function copyInstruments(destRecord, sourcePid, sourceRecord, instruments) {
    const postdata = {
        redcap_csrf_token: '$csrfToken',
        destRecord: destRecord,
        sourcePid: sourcePid,
        sourceRecord: sourceRecord,
        instruments: instruments,
        action: 'copyInstruments',
    };
    console.log(postdata);
    presentScreen('Copying '+instruments.length+' instruments...');
    $.post('$thisUrl', postdata, (json) => {
        console.log(json);
        clearScreen();
        try {
            const data = JSON.parse(json);
            if (data.error) {
                console.error(data.error);
                $.sweetModal({
                    content: 'ERROR: '+data.error,
                    icon: $.sweetModal.ICON_ERROR
                });
            } else if (data.result) {
                $.sweetModal({
                    content: data.result,
                    icon: $.sweetModal.ICON_SUCCESS
                });
            } else {
                $.sweetModal({
                    content: 'Unknown Error: '+json,
                    icon: $.sweetModal.ICON_ERROR
                });
            }
        } catch(e) {
            console.error(e);
            $.sweetModal({
                content: 'ERROR: '+e,
                icon: $.sweetModal.ICON_ERROR
            });
        }
    });
}
</script>";
        }
    }
} else {
    echo "<p class='centered max-width'>No additional surveys found on this project! All existing Flight Tracker surveys are already shared weekly.</p>";
}


function copyRowValues(&$newDestRow, $oldDestRow, $sourceRow, $fieldsToCopy, $destChoices, $sourceChoices, $instrument, $destPid, $sourcePid) {
    $sourceRecord = $sourceRow['record_id'] ?? "";
    $destRecord = $newDestRow["record_id"] ?? "";
    if (($sourceRecord === "") || ($destRecord === '')) {
        return ["", []];
    }
    $sourceInstance = $sourceRow['redcap_repeat_instance'] ?: "1";
    $destInstance = $newDestRow['redcap_repeat_instance'] ?: "1";
    $sourceResponseId = REDCapManagement::getSurveyResponseId($sourcePid, $sourceRecord, $sourceInstance, $instrument);
    $sourceCompletionTime = REDCapManagement::getSurveyResponseField($sourceResponseId, "completion_time");
    $destResponseId = REDCapManagement::getSurveyResponseId($destPid, $destRecord, $destInstance, $instrument);
    $destCompletionTime = REDCapManagement::getSurveyResponseField($destResponseId, "completion_time");
    foreach ($fieldsToCopy as $field) {
        $destValue = $oldDestRow[$field] ?? "";
        $sourceValue = $sourceRow[$field] ?? "";
        if (($destValue === "") && ($sourceValue !== "")) {
            if (isset($sourceChoices[$field])) {
                $sourceLabel = $sourceChoices[$field][$sourceValue] ?? "";
                if ($sourceLabel) {
                    if (isset($destChoices[$field])) {
                        foreach ($destChoices[$field] as $destIndex => $destLabel) {
                            if ($sourceLabel == $destLabel) {
                                $newDestRow[$field] = $destIndex;
                                break;
                            }
                        }
                    } else {
                        $newDestRow[$field] = $sourceLabel;
                    }
                }
            } else {
                $newDestRow[$field] = $sourceValue;
            }
        } else if (($destValue !== "") && ($sourceValue !== "") && ($destValue !== $sourceValue)) {
            # both dest and source have values ==> conflict ==> check date of survey
            if ($destCompletionTime && $sourceCompletionTime) {
                $sourceCompletionTs = strtotime($sourceCompletionTime);
                $destCompletionTs = strtotime($destCompletionTime);
                if ($sourceCompletionTs > $destCompletionTs) {
                    $newDestRow[$field] = $sourceValue;
                }
            } else if ($sourceCompletionTime) {
                $newDestRow[$field] = $sourceValue;
            }
            # if no source completion time and values conflict, then keep destination data point
        } else if ($sourceValue === "") {
            # if it's a checkbox, both values would be blank because no value exists by default
            $isCheckbox = FALSE;
            foreach (array_keys($sourceRow) as $sourceField) {
                if (preg_match("/^$field"."___/", $sourceField)) {
                    $isCheckbox = TRUE;
                    break;
                }
            }
            if ($isCheckbox && isset($destChoices[$field]) && isset($sourceChoices[$field])) {
                foreach ($sourceChoices[$field] as $sourceIndex => $sourceLabel) {
                    if ($sourceRow[$field."___".$sourceIndex]) {
                        $foundInDest = FALSE;
                        foreach ($destChoices[$field] as $destIndex => $destLabel) {
                            if ($destLabel == $sourceLabel) {
                                $newDestRow[$field."___".$destIndex] = "1";
                                $foundInDest = TRUE;
                            }
                        }
                        if (!$foundInDest && isset($destChoices[$field][$sourceIndex])) {
                            # assume minor label change
                            $newDestRow[$field."___".$sourceIndex] = "1";
                        }
                    }
                }
            }
        }
    }
    $params = [];
    error_log("Source completion time: $sourceCompletionTime");
    if ($sourceCompletionTime) {
        $newCompletionTime = $sourceCompletionTime;
        if ($destCompletionTime) {
            $sourceCompletionTs = strtotime($sourceCompletionTime);
            $destCompletionTs = strtotime($destCompletionTime);
            if ($destCompletionTs > $sourceCompletionTs) {
                # user later time
                $newCompletionTime = $destCompletionTime;
            }
        }
        $sourceStartTime = REDCapManagement::getSurveyResponseField($sourceResponseId, "start_time" ?: $newCompletionTime);
        if ($destResponseId) {
            REDCapManagement::updateSurveyResponseField($destResponseId, "completion_time", $newCompletionTime);
            REDCapManagement::updateSurveyResponseField($destResponseId, "start_time", $sourceStartTime);
            REDCapManagement::updateSurveyResponseField($destResponseId, "first_submit_time", $newCompletionTime);
        } else if ($sourceResponseId) {
            $participantId = REDCapManagement::copyParticipantRow($sourceResponseId, $destPid, $instrument);
            if ($participantId) {
                # wait until after data are uploaded
                $params = [$participantId, $destRecord, $newCompletionTime, $newCompletionTime, $destInstance, $sourceStartTime];
            }
        }
    }
    return $params;
}

function areRowsMirrored($row1, $row2, $choices1, $choices2) {
    $skip = ["record_id", "redcap_repeat_instrument", "redcap_repeat_instance"];
    foreach ($row1 as $field => $value1) {
        if (preg_match("/___/", $field)) {
            list($checkboxField, $index1) = explode("___", $field);
            if ($row2[$field] ?? "" !== $value1) {
                $foundMatch = FALSE;
                if (isset($choices1[$checkboxField]) && isset($choices2[$checkboxField])) {
                    $label1 = $choices1[$checkboxField][$index1] ?? "";
                    foreach ($choices2[$checkboxField] as $index2 => $label2) {
                        if ($label1 === $label2) {
                            $foundMatch = TRUE;
                            if ($value1 !== $row2[$checkboxField . "___" . $index2] ?? "") {
                                # reassigned label with different check values
                                return FALSE;
                            }
                        }
                    }
                }
                if (!$foundMatch) {
                    # no match found via labels, indicating a reassigned label
                    # ==> different check values for index in row1 and row2
                    return FALSE;
                }
            } else if (isset($choices1[$checkboxField]) && isset($choices2[$checkboxField])) {
                $label1 = $choices1[$checkboxField][$index1] ?? "";
                foreach ($choices2[$checkboxField] as $index2 => $label2) {
                    if (($label1 === $label2) && ($value1 !== $row2[$checkboxField . "___" . $index2] ?? "")) {
                        # reassigned label with different check values
                        return FALSE;
                    }
                }
                # we know that $value1 === $row2[$field] ?? "" ==> we don't need to return FALSE
            } else {
                return FALSE;
            }
        } else if (!in_array($field, $skip)) {
            # non-checkbox field
            $value2 = $row2[$field] ?? "";
            if (isset($choices1[$field]) && isset($choices2[$field])) {
                # dropdown/radio/yesno/truefalse
                $label1 = $choices1[$field][$value1];
                $label2 = $choices2[$field][$value2];
                if (($label1 !== $label2) && ($value1 !== $value2)) {
                    return FALSE;
                }
            } else if (isset($choices1[$field])) {
                # row2 does not have choices --> treat value2 as text for label
                $label1 = $choices1[$field][$value1];
                $label2 = $value2;
                if (($label1 !== $label2) && ($value1 !== $value2)) {
                    return FALSE;
                }
            } else {
                # no choices ==> traditional field
                if ($value1 !== $value2) {
                    return FALSE;
                }
            }
        }
    }
    return TRUE;
}