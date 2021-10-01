<?php

use \Vanderbilt\CareerDevLibrary\CronManager;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/charts/baseWeb.php");

$mgr = new CronManager($token, $server, $pid, Application::getModule());
$queue = $mgr->getBatchQueue();

$specialFields = ["Project Name"];
$headers = $specialFields;
foreach ($queue as $job) {
    foreach (array_keys($job) as $header) {
        if (!in_array($header, $headers)) {
            $headers[] = $header;
        }
    }
}

$datetime = date("m-d-Y H:i:s");
$skip = ["token", "server"];

echo "<h1>Current Batch Queue (".count($queue).")</h1>";
echo "<p class='centered'>Last Updated: $datetime</p>";
echo "<div class='horizontal-scroll'>";
echo "<table class='centered bordered'>";
echo "<thead>";
echo "<tr>";
echo "<th>Pos</th>";
foreach ($headers as $header) {
    if (!in_array($header, $skip)) {
        echo "<th>".ucfirst($header)."</th>";
    }
}
echo "</tr>";
echo "</thead>";
echo "<tbody>";
foreach ($queue as $i => $row) {
    echo "<tr>";
    echo "<td>$i</td>";
    foreach ($headers as $header) {
        if (!in_array($header, $skip)) {
            if ($row[$header] || in_array($header, $specialFields)) {
                if ($header == "file") {
                    $shortFilename = basename($row[$header]);
                    echo "<td>$shortFilename</td>";
                } else if ($header == "Project Name") {
                    if ($row['token'] && $row['server']) {
                        $title = Download::projectTitle($row['token'], $row['server']);
                        echo "<td style='max-width: 300px; overflow: auto;'>$title</td>";
                    } else {
                        echo "<td></td>";
                    }
                } else if (preg_match("/Ts$/", $header) && $row[$header] && is_numeric($row[$header])) {
                    echo "<td>".date("Y-m-d H:i:s", $row[$header])."</td>";
                } else if (is_array($row[$header])) {
                    echo "<td style='max-width: 300px; overflow: auto;'>".implode(", ", $row[$header])."</td>";
                } else {
                    echo "<td>".$row[$header]."</td>";
                }
            } else {
                echo "<td></td>";
            }
        }
    }
    echo "</tr>";
}
echo "</tbody>";
echo "</table>";
echo "</div>";