<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \ExternalModules\ExternalModules;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$cohort = REDCapManagement::sanitizeCohort($_POST['cohort']);
$module = Application::getModule();
$cohorts = new Cohorts($token, $server, $module);
$cohortNames = $cohorts->getCohortNames();
$supertoken = Application::getSetting("supertoken", $pid);
if ($pid && $cohort && in_array($cohort, $cohortNames) && $cohorts->hasReadonlyProjectsEnabled()) {
    if ($supertoken && REDCapManagement::isValidSupertoken($supertoken)) {
        $newTitle = Application::getProgramName()." - $cohort";
        $projectSetup = [
            "is_longitudinal" => 0,
            "surveys_enabled" => 1,
            "purpose" => 4,
            "record_autonumbering_enabled" => 0,
            "project_title" => $newTitle,
        ];
        $newToken = Upload::createProject($supertoken, $server, $projectSetup);
        if ($newToken && REDCapManagement::isValidToken($newToken)) {
            $newServer = $server;
            $projectSetup = [
                "custom_record_label" => "[identifier_first_name] [identifier_last_name]",
                "project_notes" => "This is a read-only project and is automatically updated on a weekly basis. Any changes you make will not be permanent.",
            ];
            Upload::projectSettings($projectSetup, $newToken, $newServer);
            $newPid = REDCapManagement::getPIDFromToken($newToken, $newServer);
            $newEventId = REDCapManagement::getEventIdForClassical($newPid);
            if ($newPid && $newServer && $newEventId) {
                $metadata = Download::metadata($token, $server);
                $prefix = $module->getDirectoryPrefix();
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
                DataDictionaryManagement::setupRepeatingForms($newEventId, $formsAndLabels);
                $surveysAndLabels = DataDictionaryManagement::getSurveysAndLabels($metadata);
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
                echo "Setup complete! You will need to set up User Rights on the new project. Project ID is in pid $newPid and is titled '$newTitle'.";
            } else {
                echo "Could not get new project settings";
            }
        } else {
            echo "Could not create project";
        }
    } else {
        echo "Invalid supertoken";
    }
} else {
    echo "Invalid cohort $cohort";
}
