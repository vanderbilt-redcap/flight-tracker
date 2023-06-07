<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DataTables;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$numDays = 10;
if (isset($_POST['delete'])) {
    $ts = time() - $numDays * 24 * 3600;
    $thresholdDate = date("Y-m-d", $ts);
    $moreToDelete = $module->deleteLogs(CareerDev::getModuleId(), $thresholdDate, $pid, 1000);
    if ($moreToDelete) {
        echo 1;
    } else {
        echo 0;
    }
    exit;
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");

echo DataTables::makeIncludeHTML();

$columns = [
    [
        "data" => 'timestamp',
        "title" => 'Date/Time',
        "searchable" => false
    ],
    [
        "data" => 'message',
        "title" => 'Message',
        "searchable" => true
    ],
];

$url = Application::link("this");

?>

<script>
    function deleteLogs(url, numSteps) {
        if (typeof numSteps === "undefined") {
            numSteps = 0;
        }
        if (numSteps % 10 === 0) {
            presentScreen("Deleting... (Step "+numSteps+")");
        }
        $.post(url, { delete: 1 }, (result) => {
            if (result === '1') {
                setTimeout(() => {
                    deleteLogs(url, numSteps + 1);
                }, 300);
            } else {
                clearScreen();
            }
        });
    }
</script>

<h1>Flight Tracker Log</h1>
<h2><?= $tokenName ?></h2>
<p class='centered'>(Refresh the page to see the latest.)</p>

<p class='centered'><button onclick="deleteLogs('<?= $url ?>'); return false;">Delete Logs Over <?= $numDays ?> Days Old</button> <button onclick='submitLogs("<?= Application::link("log/email-logs.php") ?>"); return false;'>Report Today's Logs to Developers</button></p>

<?= DataTables::makeMainHTML("log/get-logs.php", Application::getModule(), $columns, FALSE, TRUE) ?>

