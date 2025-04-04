<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\ERIC;
use \Vanderbilt\CareerDevLibrary\FeatureSwitches;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$records = Download::recordIds($token, $server);
$recordId = Sanitizer::getSanitizedRecord($_POST['record'], $records);
$fetchType = Sanitizer::sanitize($_POST['fetchType']);
$action = Sanitizer::sanitize($_POST['action']);
if (!$recordId) {
    die("Error: Invalid Record-ID");
}

$switches = new FeatureSwitches($token, $server, $pid);

try {
    if ($action == "fetch") {
        if ($fetchType == "summary") {
            require_once(dirname(__FILE__) . "/drivers/6d_makeSummary.php");
            $metadata = Download::metadata($token, $server);
            \Vanderbilt\CareerDevLibrary\summarizeRecord($token, $server, $pid, $recordId, $metadata);
        } else if ($fetchType == "publications") {
            require_once(dirname(__FILE__) . "/publications/getAllPubs_func.php");
            \Vanderbilt\CareerDevLibrary\getPubs($token, $server, $pid, [$recordId]);
            if ($switches->getValue("Update Bibliometrics Monthly") == "On") {
                require_once(dirname(__FILE__) . "/publications/updateBibliometrics.php");
                \Vanderbilt\CareerDevLibrary\updateBibliometrics($token, $server, $pid, [$recordId]);
            }
            if (ERIC::isRecordEnabled($recordId, $token, $server, $pid)) {
                require_once(dirname(__FILE__) . "/drivers/23_getERIC.php");
                \Vanderbilt\CareerDevLibrary\getERIC($token, $server, $pid, [$recordId]);
            }
        } else if ($fetchType == "publications_name") {
            require_once(dirname(__FILE__) . "/publications/getAllPubs_func.php");
            \Vanderbilt\CareerDevLibrary\getNamePubs($token, $server, $pid, [$recordId]);
            require_once(dirname(__FILE__) . "/publications/updateBibliometrics.php");
            \Vanderbilt\CareerDevLibrary\updateBibliometrics($token, $server, $pid, [$recordId]);
            if (ERIC::isRecordEnabled($recordId, $token, $server, $pid)) {
                require_once(dirname(__FILE__) . "/drivers/23_getERIC.php");
                \Vanderbilt\CareerDevLibrary\getERIC($token, $server, $pid, [$recordId]);
            }
        } else if ($fetchType == "grants") {
            $forms = Download::metadataForms($token, $server);

            if (in_array("nsf", $forms)) {
                require_once(dirname(__FILE__) . "/drivers/20_nsf.php");
                \Vanderbilt\CareerDevLibrary\getNSFGrants($token, $server, $pid, [$recordId]);
            }

            # The IES webpage is incompatible with a bug in cURL
            # The bug seems to occur between cuRL versions 7.60.0 and 7.88.0
            # OpenSSL SSL_read: error:0A000126:SSL routines::unexpected eof while reading, errno 0
            # Hard to find something concrete, but it appears fixed after cURL 7.80.x
            # Note: We're using PHP's cURL, not the CLI's. Also PHP 8.2 distributes with cURL 7.76.x
            $curlVersion = curl_version()["version"];
            if (
                in_array("ies_grant", $forms)
                && (
                    REDCapManagement::versionGreaterThanOrEqualTo($curlVersion, "7.88.0")
                    || REDCapManagement::versionGreaterThanOrEqualTo("7.60.0", $curlVersion)
                )
            ) {
                require_once(dirname(__FILE__) . "/drivers/24_getIES.php");
                \Vanderbilt\CareerDevLibrary\getIES($token, $server, $pid, [$recordId]);
            }

            require_once(dirname(__FILE__) . "/drivers/2s_updateRePORTER.php");
            \Vanderbilt\CareerDevLibrary\updateNIHRePORTER($token, $server, $pid, [$recordId]);

            if (Application::isVanderbilt() && !Application::isLocalhost()) {
                require_once(dirname(__FILE__)."/drivers/19_updateNewCoeus.php");
                require_once(dirname(__FILE__)."/drivers/22_getVERA.php");
                \Vanderbilt\CareerDevLibrary\updateCoeusGrants($token, $server, $pid, [$recordId]);
                \Vanderbilt\CareerDevLibrary\updateCoeusSubmissions($token, $server, $pid, [$recordId]);
                \Vanderbilt\CareerDevLibrary\getVERA($token, $server, $pid, [$recordId]);
            }
        } else if ($fetchType == "grants_name") {
            $forms = Download::metadataForms($token, $server);

            if (in_array("nsf", $forms)) {
                require_once(dirname(__FILE__) . "/drivers/20_nsf.php");
                \Vanderbilt\CareerDevLibrary\getNSFGrantsByName($token, $server, $pid, [$recordId]);
            }

            if (in_array("ies_grant", $forms)) {
                require_once(dirname(__FILE__) . "/drivers/24_getIES.php");
                \Vanderbilt\CareerDevLibrary\getIES($token, $server, $pid, [$recordId]);
            }

            require_once(dirname(__FILE__) . "/drivers/2s_updateRePORTER.php");
            \Vanderbilt\CareerDevLibrary\updateNIHRePORTERByName($token, $server, $pid, [$recordId]);

            if (Application::isVanderbilt() && !Application::isLocalhost()) {
                require_once(dirname(__FILE__)."/drivers/19_updateNewCoeus.php");
                require_once(dirname(__FILE__)."/drivers/22_getVERA.php");
                \Vanderbilt\CareerDevLibrary\updateCoeusGrants($token, $server, $pid, [$recordId]);
                \Vanderbilt\CareerDevLibrary\updateCoeusSubmissions($token, $server, $pid, [$recordId]);
                \Vanderbilt\CareerDevLibrary\getVERA($token, $server, $pid, [$recordId]);
            }
        } else if ($fetchType == "patents") {
            $securityTestMode = Application::getSetting("security_test_mode", $pid);
            if (!$securityTestMode) {
                require_once(dirname(__FILE__) . "/drivers/18_getPatents.php");
                \Vanderbilt\CareerDevLibrary\getPatents($token, $server, $pid, [$recordId]);
            }
        } else {
            throw new \Exception("Invalid fetchType $fetchType");
        }
    } else if ($action == "delete") {
        $prefixes = [];
        if ($fetchType == "publications") {
            $prefixes[] = "citation_";
        } else if ($fetchType == "grants") {
            $prefixes[] = "nih_";
            $prefixes[] = "reporter_";
            $prefixes[] = "nsf_";
            $prefixes[] = "ies_";
            if (Application::isVanderbilt()) {
                $prefixes[] = "coeus_";
                $prefixes[] = "coeus2)";
                $prefixes[] = "coeussubmission_";
            }
        } else if ($fetchType == "patents") {
            $prefixes[] = "patent_";
        } else {
            throw new \Exception("Invalid fetchType $fetchType");
        }
        foreach ($prefixes as $prefix) {
            Upload::deleteForm($token, $server, $pid, $prefix, $recordId);
        }
    } else {
        throw new \Exception("Invalid action $action");
    }
    echo "Success.";
} catch (\Exception $e) {
    echo "Error: ".$e->getMessage();
}
