<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \ExternalModules\ExternalModules;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$data = [];
try {
    $cohort = Sanitizer::sanitizeCohort($_POST['cohort']);
    $newToken = Sanitizer::sanitizeToken($_POST['apiKey']);
    if (!$newToken || !REDCapManagement::isValidToken($newToken)) {
        $data['error'] = "Invalid API Token!";
    } else {
        $module = Application::getModule();
        $cohorts = new Cohorts($token, $server, $module);
        $cohortNames = $cohorts->getCohortNames();
        if ($pid && $cohort && in_array($cohort, $cohortNames)) {
            $newTitle = Application::getProgramName()." - $cohort";
            $projectSetup = [
                "is_longitudinal" => 0,
                "surveys_enabled" => 1,
                "purpose" => 4,
                "record_autonumbering_enabled" => 0,
                "project_title" => $newTitle,
            ];
            $newServer = $server;
            $projectSetup = [
                "custom_record_label" => "[identifier_first_name] [identifier_last_name]",
                "project_notes" => "This is a read-only project and is automatically updated on a weekly basis. Any changes you make will not be permanent.",
            ];
            Upload::projectSettings($projectSetup, $newToken, $newServer, TRUE);
            $newPid = REDCapManagement::getPIDFromToken($newToken, $newServer);
            $newEventId = REDCapManagement::getEventIdForClassical($newPid);
            if ($newPid && $newServer && $newEventId) {
                $metadata = Download::metadata($token, $server);
                $prefix = Application::getPrefix();
                $version = ExternalModules::getModuleVersionByPrefix($prefix);
                ExternalModules::enableForProject($prefix, $version, $newPid);

                $defaultSettings = [
                    "turn_off" => TRUE,
                    "tokenName" => $cohort,
                    "pid" => $newPid,
                    "event_id" => $newEventId,
                    "token" => $newToken,
                    "supertoken" => "",
                    "sourcePid" => $pid,
                ];
                CareerDev::duplicateAllSettings($pid, $newPid, $defaultSettings);
                Upload::metadata($metadata, $newToken, $newServer);
                $formsAndLabels = DataDictionaryManagement::getRepeatingFormsAndLabels($metadata);
                $projectFormsAndLabels = DataDictionaryManagement::getRepeatingFormsAndLabelsForProject($event_id);
                foreach($projectFormsAndLabels as $form => $label) {
                    if (!isset($formsAndLabels[$form])) {
                        $formsAndLabels[$form] = $label;
                    }
                }
                DataDictionaryManagement::setupRepeatingForms($newEventId, $formsAndLabels);
                $surveysAndLabels = DataDictionaryManagement::getSurveysAndLabels($metadata, $pid);
                DataDictionaryManagement::setupSurveys($newPid, $surveysAndLabels);

                $userRights = Download::userRights($newToken, $newServer);
                $currentUsers = [];
                foreach ($userRights as $row) {
                    $currentUsers[] = $row['username'];
                }
                $userid = $module->getUsername();
                if (!in_array($userid, $currentUsers)) {
                    $newUser = [
                        "username" => $userid,
                        "expiration" => "",
                        "data_access_group" => "",
                        "design" => "1",
                        "user_rights" => "1",
                        "data_access_groups" => "1",
                        "data_export" => "1",
                        "reports" => "1",
                        "stats_and_charts" => "1",
                        "manage_survey_participants" => "1",
                        "calendar" => "1",
                        "data_import_tool" => "1",
                        "data_comparison_tool" => "1",
                        "logging" => "1",
                        "file_repository" => "1",
                        "data_quality_create" => "1",
                        "data_quality_execute" => "1",
                        "api_export" => "1",
                        "api_import" => "1",
                        "mobile_app" => "1",
                        "mobile_app_download_data" => "0",
                        "record_create" => "1",
                        "record_rename" => "0",
                        "record_delete" => "0",
                        "lock_records_all_forms" => "0",
                        "lock_records" => "0",
                        "lock_records_customization" => "0",
                        "forms" => [],
                    ];
                    foreach (REDCapManagement::getFormsFromMetadata($metadata) as $formName) {
                        $newUser['forms'][$formName] = '1';
                    }
                    $userRights[] = $newUser;
                    Upload::userRights($userRights, $newToken, $newServer);
                }
                $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
                foreach ($records as $recordId) {
                    $redcapData = Download::records($token, $server, [$recordId]);
                    Upload::rows($redcapData, $newToken, $newServer);
                }

                $newPid = REDCapManagement::getPIDFromToken($newToken, $newServer);
                $cohorts->addReadonlyPid($cohort, $newPid, $newToken);
                $data['result'] = "Setup complete! You will need to set up User Rights on the new project. Project ID is in pid $newPid and is titled '$newTitle'.";
            } else {
                $data['error'] = "Could not get new project settings";
            }
        } else {
            $data['error'] = "Invalid cohort $cohort";
        }
    }
} catch (\Exception $e) {
    $data['error'] = $e->getMessage();
}
echo json_encode($data);
