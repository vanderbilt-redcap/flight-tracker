<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DataTables;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

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

?>

<h1>Flight Tracker Log</h1>
<h2><?= $tokenName ?></h2>
<p class='centered'>(Refresh the page to see the latest.)</p>

<p class='centered'><button onclick='submitLogs("<?= Application::link("log/email-logs.php") ?>"); return false;'>Report Today's Logs to Developers</button></p>

<?= DataTables::makeMainHTML("log/get-logs.php", Application::getModule(), $columns, FALSE, TRUE) ?>
