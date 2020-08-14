<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\LDAP;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/LDAP.php");
require_once(dirname(__FILE__)."/../classes/NameMatcher.php");

function getLDAPs($token, $server, $pid) {
    $metadata = Download::metadata($token, $server);
    $repeatingForms = REDCapManagement::getRepeatingForms($pid);
    $firstNames = Download::firstnames($token, $server);
    $lastNames = Download::lastnames($token, $server);
    $userids = Download::userids($token, $server);
    foreach ($firstNames as $recordId => $firstName) {
        Upload::deleteForm($token, $server, $pid, "ldap_", $recordId);
        $userid = $userids[$recordId];
        $lastName = $lastNames[$recordId];
        if ($userid) {
            Application::log("$pid: Searching for userid $userid");
            $upload = LDAP::getREDCapRowsFromUid($userid, $metadata, $recordId, $repeatingForms);
        } else {
            Application::log("$pid: Searching for name $firstName $lastName");
            $upload = LDAP::getREDCapRowsFromName($firstName, $lastName, $metadata, $recordId, $repeatingForms);
        }
        if (!empty($upload)) {
            Upload::rows($upload, $token, $server);
        }
        sleep(2);
    }
    CareerDev::saveCurrentDate("Last LDAP Directory Pull", $pid);
}
