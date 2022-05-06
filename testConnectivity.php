<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/CareerDev.php");

if ($_POST['turn_on'] || $_POST['turn_off']) {
    if ($_POST['turn_on']) {
        $value = "1";
    } else if ($_POST['turn_off']) {
        $value = "";
    } else {
        throw new \Exception("This should never happen: ".implode(", ", array_keys($_POST)));
    }
    CareerDev::setSetting("send_cron_status", $value, $pid);
    echo "send_cron_status = $value";
    exit;
}

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/drivers/14_connectivity.php");

$cronStatus = CareerDev::getSetting("send_cron_status", $pid);
if ($cronStatus) {
    $status = "On";
    $link = "<button id='status_link' onclick='turnOffStatusCron(); return false;'>Turn off status cron</button>";
} else {
    $status = "Off";
    $link = "<button id='status_link' onclick='turnOnStatusCron(); return false;'>Turn on status cron</button>";
}
$statusMssg = "<p class='centered max-width padded' style='background-color: rgba(128,128,128,0.3); margin: auto;'>Current Cron Connectivity-Checker Status: <span class='bolded' id='status'>$status</span>. $link<br>If enabled, status alerts every minute are sent to $adminEmail.</p>"

?>

<h1>Connectivity Checker</h1>
<p class='centered max-width' style='margin: 1em auto;'>Flight Tracker relies on many external websites for its information. The following sites need to be accessible (added to the allow-list). Your attention is needed only on those portions that are <span class='red'>&nbsp;red&nbsp;</span>. Please contact the <a href='mailto:scott.j.pearson@vumc.org'>Flight Tracker Home Office</a> for further help.</p>
<?php

echo $statusMssg;
echo testConnectivity($token, $server, $pid,"HTML");