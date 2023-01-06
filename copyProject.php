<?php

# The following line is necessary to allow for cross-project POSTing.
# It is turned off (for now) for security reasons.
// header("access-control-allow-origin: *");

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/classes/Autoload.php");

$otherToken = $_POST['token'] ? Sanitizer::sanitize($_POST['token']) : "";
$otherServer = $_POST['server'] ? Sanitizer::sanitizeURL($_POST['server']) : "";
if ($_GET['project_id'] && ($_GET['action'] == "setupSettings")) {
    $pid = is_numeric($_GET['project_id']) ? Sanitizer::sanitize($_GET['project_id']) : FALSE;
    if (empty($_POST)) {
        die("Error: No data are posted.");
    }
    if (!$pid) {
        die("Error: No project id.");
    }
    if (verifyToken($otherToken, $pid)) {
        try {
            $projectTitle = Download::projectTitle($otherToken, $otherServer);
            $eventId = REDCapManagement::getEventIdForClassical($pid);
            $module = Application::getModule();
            $module->enableModule($pid, CareerDev::getPrefix());
            $enabledModules = $module->getEnabledModules($pid);
            if (in_array(CareerDev::getPrefix(), array_keys($enabledModules))) {
                foreach ($_POST as $key => $value) {
                    if ($key == "pid") {
                        $value = $pid;
                    } else if ($key == "event_id") {
                        $value = $eventId;
                    }
                    CareerDev::saveSetting($key, $value, $pid);
                }
                echo "Project $pid successfully set up on server.";
            } else {
                echo "Error: Module not enabled.";
            }
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    } else {
        echo "Error: Invalid token.";
    }
} else if ($otherServer && $otherToken && REDCapManagement::isValidToken($otherToken)) {
    require_once(dirname(__FILE__)."/small_base.php");
    if (!preg_match("/\/$/", $otherServer)) {
        $otherServer .= "/";
    }
    $otherServerAPI = $otherServer."api/";
    $otherPid = \Vanderbilt\FlightTrackerExternalModule\copyProjectToNewServer($token, $server, $otherToken, $otherServerAPI);

    $otherREDCapVersion = Download::redcapVersion($otherToken, $otherServerAPI);
    $urlParams = "?prefix=".CareerDev::getPrefix()."&page=copyProject&NOAUTH&project_id=".$otherPid."&action=setupSettings";
    $url1 = $otherServer."redcap_v".$otherREDCapVersion."/Classes/ExternalModules/".$urlParams;
    $url2 = $otherServer."external_modules/".$urlParams;

    $allSettings = Application::getAllSettings($pid);
    $allSettings["token"] = $otherToken;
    $allSettings["server"] = $otherServerAPI;
    $allSettings["pid"] = $otherPid;
    $allSettings["tokenName"] = "Copy of ".$tokenName;
    $allSettings["supertoken"] = "";
    $allSettings["event_id"] = "";
    unset($allSettings["enabled"]);

    if (REDCapManagement::isGoodURL($url1)) {
        $url = $url1;
    } else {
        $url = $url2;
    }
    list($resp, $output) = REDCapManagement::downloadURLWithPOST($url, $allSettings, $pid);
    // echo "allSettings: ".REDCapManagement::json_encode_with_spaces($allSettings)."<br>\n";
    // echo $url."<br>\n";
    echo json_encode($output);
} else {
    require_once(dirname(__FILE__)."/charts/baseWeb.php");
?>

<h1>Copy <?= Application::getProgramName() ?> Project to Another Project</h1>

<form action="<?= Application::link("this") ?>" method="POST">
    <?= Application::generateCSRFTokenHTML() ?>
    <p class="centered">API Token for New Project:<br><input type="text" style="width: 500px;" id="token" name="token" value="<?= $otherToken ?>"></p>
    <p class="centered">Base Server URL (e.g., https://redcap.vanderbilt.edu/; note, <b>not</b> API URL):<br><input type="text" id="server" name="server" value="<?= $otherServer ?>" style="width: 500px;"></p>
    <p class="centered"><button onclick="copyProject($('#token').val(), $('#server').val()); return false;">Submit</button></p>
    <p class="centered" id="results"></p>
</form>

<?php

}

function verifyToken($token, $pid) {
    if (!is_numeric($pid)) {
        echo "ERROR Invalid pid $pid";
        return FALSE;
    }
    if (!REDCapManagement::isValidToken($token)) {
        echo "ERROR Invalid token $token";
        return FALSE;
    }

    # does NOT have to be present user
    $module = Application::getModule();
    $sql = "SELECT username FROM redcap_user_rights WHERE project_id = ? AND api_token = ?";
    $q = $module->query($sql, [$pid, $token]);
    return ($q->num_rows() > 0);
}
