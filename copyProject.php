<?php

namespace Vanderbilt\FlightTrackerExternalModule;

# The following line is necessary to allow for cross-project POSTing.
# It is turned off (for now) for security reasons.
// header("access-control-allow-origin: *");

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(__DIR__."/classes/Autoload.php");

$otherToken = $_POST['token'] ? Sanitizer::sanitize($_POST['token']) : "";
$otherServer = $_POST['server'] ? Sanitizer::sanitize($_POST['server']) : "";    // do not sanitizeURL to allow for escaping
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
                    if ($key != "continueNumbering") {
                        Application::saveSetting($key, $value, $pid);
                    }
                }

                # metadata should have been set up before this URL is called
                $metadata = Download::metadataByPid($pid);
                $formsAndLabels = DataDictionaryManagement::getRepeatingFormsAndLabels($metadata);
                DataDictionaryManagement::setupRepeatingForms($eventId, $formsAndLabels);
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
    $continueNumbering = TRUE;
    if (isset($_POST['$continueNumbering']) && ($_POST['$continueNumbering'] != "1")) {
        $continueNumbering = FALSE;
    }
    $otherPid = \Vanderbilt\FlightTrackerExternalModule\copyProjectToNewServer($token, $server, $otherToken, $otherServerAPI, !$continueNumbering);

    if (!$continueNumbering) {
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
    }
} else {
    require_once(dirname(__FILE__)."/charts/baseWeb.php");
    $continueNumberingChecked = "checked";
    if (isset($_POST['continueNumbering']) && ($_POST['continueNumbering'] != "1")) {
        $continueNumberingChecked = "";
    }
?>

<h1>Copy <?= Application::getProgramName() ?> Project to Another Project</h1>

<form action="<?= Application::link("this") ?>" method="POST">
    <?= Application::generateCSRFTokenHTML() ?>
    <p class="centered max-width"><label for="token">API Token for New Project:</label><br/><input type="text" style="width: 500px;" id="token" name="token" value="<?= $otherToken ?>"></p>
    <p class="centered max-width"><label for="server">Base Server URL (e.g., https://redcap.vanderbilt.edu/; note, <b>not</b> API URL):</label><br/><input type="text" id="server" name="server" value="<?= $otherServer ?>" style="width: 500px;"></p>
    <p class="centered max-width"><input type="checkbox" id="continueNumbering" name="continueNumbering" value="1" <?= $continueNumberingChecked ?> /> <label for="continueNumbering">Continue Record Numbering (Check to add new records to an existing project. If not checked, the Data Dictionary will be overwritten. If checked, Record IDs must be numeric in order to increment them automatically.)</label></p>
    <p class="centered max-width"><button onclick="copyProject($('#token').val(), $('#server').val()); return false;">Submit</button></p>
    <p class="centered max-width" id="results"></p>
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
