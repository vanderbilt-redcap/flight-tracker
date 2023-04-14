<?php

use \Vanderbilt\CareerDevLibrary\CronManager;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Links;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/charts/baseWeb.php");

$mgr = new CronManager($token, $server, $pid, Application::getModule());
$queue = $mgr->getBatchQueue();

$specialFields = ["Project Name"];
$headers = $specialFields;
foreach ($queue as $job) {
    foreach (array_keys($job) as $header) {
        if (!in_array($header, $headers) && isset($job['records'])) {
            $headers[] = $header;
        }
    }
}

$datetime = date("m-d-Y H:i:s");
$skip = ["token", "server", "file", "pid"];

echo "<style>
th {  position: sticky; top: 0; background-color: #d4d4eb; }
</style>";

echo "<h1>Current Batch Queue (".count($queue).")</h1>";
echo "<p class='centered'>Last Updated: $datetime</p>";
echo "<table class='centered bordered' style='width: 100%;'>";
echo "<thead>";
echo "<tr>";
echo "<th>Pos</th>";
foreach ($headers as $header) {
    if (!in_array($header, $skip)) {
        if ($header == "firstParameter") {
            echo "<th>First Parameter</th>";
        } else if (preg_match("/Ts/", $header)) {
            $header = str_replace("Ts", " Time", $header);
            echo "<th>".ucfirst($header)."</th>";
        } else {
            echo "<th>".ucfirst($header)."</th>";
        }
    }
}
echo "</tr>";
echo "</thead>";
echo "<tbody>";
foreach ($queue as $i => $row) {
    $count = $i + 1;
    echo "<tr>";
    echo "<td>$count</td>";
    foreach ($headers as $header) {
        if (!in_array($header, $skip)) {
            if ($row[$header] || in_array($header, $specialFields)) {
                if ($header == "method") {
                    $shortFilename = basename($row["file"]);
                    echo "<td>{$row['method']}<br/><span class='smaller'>$shortFilename</span></td>";
                } else if ($header == "Project Name") {
                    if ($row['token'] && $row['server']) {
                        $title = Download::projectTitle($row['token'], $row['server']);
                        $title = str_replace("Flight Tracker - ", "", $title);
                        $title = str_replace(" - Flight Tracker", "", $title);
                        $title = preg_replace("/\s*Flight Tracker\s*/", "", $title);
                        if (isset($row['pid']) && $row['pid']) {
                            $link = Links::makeProjectHomeLink($row['pid'], $title);
                            echo "<td style='max-width: 200px;'>$link <span class='smaller'>[pid".$row['pid']."]</span></td>";
                        } else {
                            echo "<td style='max-width: 200px;'>$title</td>";
                        }
                    } else {
                        echo "<td></td>";
                    }
                } else if (preg_match("/Ts$/", $header) && $row[$header] && is_numeric($row[$header])) {
                    echo "<td>" . date("Y-m-d H:i:s", $row[$header]) . "</td>";
                } else if (is_array($row[$header]) && REDCapManagement::isAssoc($row[$header])) {
                    $values = [];
                    foreach ($row[$header] as $key => $value) {
                        if (is_array($value)) {
                            $values[] = "<strong>$key</strong>: ".implode(", ", $value);
                        } else {
                            $values[] = "<strong>$key</strong>: $value";
                        }
                    }
                    echo "<td style='max-width: 200px;'><div class='scrollable'>" . implode("<br/>", $values) . "</div></td>";
                } else if (is_array($row[$header]) && !REDCapManagement::isAssoc($row[$header])) {
                    echo "<td style='max-width: 200px;'><div class='scrollable'>" . implode(", ", $row[$header]) . "</div></td>";
                } else {
                    echo "<td>" . $row[$header] . "</td>";
                }
            } else if (($header == "records") && isset($row['pids'])) {
                echo "<td class='bolded'>" . count($row['pids']) . " pids</td>";
            } else {
                echo "<td></td>";
            }
        }
    }
    echo "</tr>";
}
echo "</tbody>";
echo "</table>";