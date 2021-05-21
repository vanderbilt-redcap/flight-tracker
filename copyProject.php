<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/Application.php");
require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/classes/Download.php");

$otherToken = $_POST['token'];
$otherServer = $_POST['server'];
if ($otherServer && $otherToken && REDCapManagement::isValidToken($otherToken)) {
    if (!preg_match("/\/$/", $otherServer)) {
        $otherServer .= "/";
    }
    $otherServerAPI = $otherServer."api/";
    list($otherPid, $otherEventId) = \Vanderbilt\FlightTrackerExternalModule\copyProjectToNewServer($token, $server, $otherToken, $otherServerAPI);

    $otherREDCapVersion = Download::redcapVersion($otherToken, $otherServer);
    $url = $otherServer."redcap_v".$otherREDCapVersion."/Classes/ExternalModules/?prefix=".CareerDev::getModuleId()."&page=copyProject&project_id=".$otherPid."&NOAUTH&action=setupSettings";
    $allSettings = Application::getAllSettings($pid);
    $allSettings["token"] = $otherToken;
    $allSettings["server"] = $otherServerAPI;
    $allSettings["pid"] = $otherPid;
    $allSettings["supertoken"] = "";
    $allSettings["event_id"] = "";
    REDCapManagement::downloadURLWithPOST($url, $allSettings, $pid);
} else if ($_GET['project_id'] && in_array($_GET['action'], ["setupSettings"])) {
    $pid = $_GET['project_id'];
    $projectTitle = Download::projectTitle($_POST['token'], $_POST['server']);
    if (($_GET['action'] == "setupSettings") && $projectTitle && preg_match("/".SERVER_NAME."/i", $_POST['server'])) {
        $eventId = REDCapManagement::getEventIdForClassical($pid);
        $module = Application::getModule();
        $module->enableModule($pid, CareerDev::getModuleId());
        $enabledModules = $module->getEnabledModules($pid);
        if (in_array(CareerDev::getModuleId(), $enabledModules)) {
            foreach ($_POST as $key => $value) {
                if ($key == "pid") {
                    $value = $pid;
                } else if ($key == "event_id") {
                    $value = $eventId;
                }
                CareerDev::saveSetting($key, $value, $pid);
            }
            echo "Done.";
        } else {
            echo "Not enabled.";
        }
    } else {
        echo "Invalid request.";
    }
} else {
    require_once(dirname(__FILE__)."/charts/baseWeb.php");
?>

<h1>Copy <?= Application::getProgramName() ?> Project to Another Project</h1>

<form action="<?= Application::link("this") ?>" method="POST">
    <p class="centered">API Token for New Project: <input type="text" name="token" value="<?= $otherToken ?>"></p>
    <p class="centered">Base Server URL (e.g., https://redcap.vanderbilt.edu/; note, <b>not</b> API URL): <input type="text" name="server" value="<?= $otherServer ?>"></p>
    <button>Submit</button>
</form>

<?php

}