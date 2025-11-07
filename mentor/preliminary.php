<?php

# for NOAUTH to work, must be listed before any redcap_connect inclusion

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

$pid = $_GET['pid'] ?? $_GET['project_id'] ?? FALSE;
$credentialsFile = Application::getCredentialsDir()."/career_dev/credentials.php";
if (file_exists($credentialsFile)) {
    // for plugin projects at Vanderbilt - to get the token and server data included
    require_once($credentialsFile);
    foreach ($info as $type => $ary) {
        if ($ary['pid'] == $pid) {
            $infoType = $type;
        }
    }
}

MMAHelper::setMMADebug(FALSE);
if ($pid) {
    $token = Application::getSetting("token", $pid);
    $server = Application::getSetting("server", $pid);
    if ($token && $server) {
        $userRights = Download::userRights($token, $server);
        $username = Application::getUsername();
        $validProjectUsers = [];
        foreach ($userRights as $row) {
            $validProjectUsers[] = $row['username'];
        }
        if (in_array($username, $validProjectUsers) || Application::isSuperUser()) {
            // valid REDCap user --> can use $_GET['uid']
            MMAHelper::setMMADebug(TRUE);
        }
    }
}
