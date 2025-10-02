<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\CronManager;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/small_base.php");

$headers = [
	"Project Name",
	"method",
	"records",
	"status",
	"enqueueTs",
	"firstParameter",
	"startTs",
	"endTs",
];
$queues = [
	"",
	FlightTrackerExternalModule::LOCAL_BATCH_SUFFIX,
	FlightTrackerExternalModule::INTENSE_BATCH_SUFFIX,
	FlightTrackerExternalModule::LONG_RUNNING_BATCH_SUFFIX,
];

if (isset($_POST['setting']) && isset($_POST['count'])) {
	$setting = Sanitizer::sanitize($_POST['setting']);
	$count = Sanitizer::sanitizeInteger($_POST['count']) ?: "Unknown";
	$titles = [];
	echo makeJobRowHTML($setting, $headers, $count, $titles);
	exit;
}

require_once(dirname(__FILE__)."/charts/baseWeb.php");

echo "<style>
th {  position: sticky; top: 0; background-color: #8dc63f; }
</style>";

echo "<h1>Batch Queues</h1>";
$datetime = date("m-d-Y H:i:s");
$queueLinks = [];
foreach ($queues as $suffix) {
	$mgr = new CronManager($token, $server, $pid, Application::getModule(), $suffix);
	$count = count($mgr->getBatchQueue());
	$suffixTitle = CronManager::getTitle($suffix);
	$id = $suffix;
	if (!$id) {
		$id = "main";
	}
	$queueLinks[] = "<a href='#$id' class='orange roundedBorder smallShadow small nounderline nobreak' style='margin-right: 8px; margin-left: 8px; padding: 4px 6px;'>".ucfirst($suffixTitle)." Queue ($count)</a>";
}
echo "<p class='centered max-width'>This page shows the current state of Flight Tracker's batch queues. These jobs are set up daily, usually at midnight, and run until the queue has completed.<br/><strong>Last Updated</strong>: $datetime</p>";
echo "<p class='centered max-width'>".implode("", $queueLinks)."</p>";
foreach ($queues as $suffix) {
	$mgr = new CronManager($token, $server, $pid, Application::getModule(), $suffix);
	$queue = $mgr->getBatchQueue();
	$suffixTitle = CronManager::getTitle($suffix);
	$id = $suffix;
	if (!$id) {
		$id = "main";
	}
	echo "<h2 id='$id'>$suffixTitle Queue (".count($queue)." Jobs)</h2>";
	$restrictionStart = CronManager::getRestrictedTime($suffix, "start");
	$restrictionEnd = CronManager::getRestrictedTime($suffix, "end");
	if (
		(
			($restrictionStart != CronManager::UNRESTRICTED_START)
			|| ($restrictionEnd != CronManager::UNRESTRICTED_START)
		)
		&& !Application::isLocalhost()
	) {
		echo "<p class='centered'>Processing does not occur on this queue from $restrictionStart until $restrictionEnd on weekdays.</p>";
	}
	echo "<table class='centered bordered' style='width: 100%;'>";
	echo "<thead>";
	echo "<tr>";
	echo "<th>Pos</th>";
	foreach ($headers as $header) {
		if ($header == "firstParameter") {
			echo "<th>First Parameter</th>";
		} elseif (preg_match("/Ts/", $header)) {
			$header = str_replace("Ts", " Time", $header);
			echo "<th>".ucfirst($header)."</th>";
		} else {
			echo "<th>".ucfirst($header)."</th>";
		}
	}
	echo "</tr>";
	echo "</thead>";
	echo "<tbody>";
	$thisUrl = Application::link("this");
	$numCols = count($headers);
	if (empty($queue)) {
		$myNumCols = $numCols + 1;
		echo "<tr><td colspan='$myNumCols' class='centered padded even'>Currently, this job queue is empty. Typically, this fills up overnight before each job is run.</td></tr>";
	}
	$titles = [];
	for ($i = 0; $i < count($queue); $i++) {
		$count = $i + 1;
		$setting = $queue[$i];
		$rowClass = ($count % 2 === 0) ? "even" : "odd";
		if ($count <= 2) {
			echo "<tr class='$rowClass'>".makeJobRowHTML($setting, $headers, $count, $titles)."</tr>";
		} else {
			echo "<tr class='$rowClass'><td>$count</td><td colspan='$numCols' class='centered padded'><a href='javascript:;' onclick='fetchSetting(\"$thisUrl\", \"$setting\", this, $count); return false;'>Fetch Row</a></td></tr>";
		}
	}
	echo "</tbody>";
	echo "</table>";
	echo "<br/><br/><br/>";
}

function makeJobRowHTML($setting, $headers, $count, &$cachedTitles) {
	$row = Application::getSystemSetting($setting) ?: [];
	if (empty($row)) {
		return "";
	}
	$html = "<td>$count</td>";
	foreach ($headers as $header) {
		if ($header == "Project Name") {
			if ($row['token'] && $row['server']) {
				$title = $cachedTitles[$row['pid']] ?? Download::projectTitle($row['pid']);
				$cachedTitles[$row['pid']] = $title;
				$title = str_replace("Flight Tracker - ", "", $title);
				$title = str_replace(" - Flight Tracker", "", $title);
				$title = preg_replace("/\s*Flight Tracker\s*/", "", $title);
				if (isset($row['pid']) && $row['pid']) {
					$link = Links::makeProjectHomeLink($row['pid'], $title);
					$html .= "<td style='max-width: 200px;'>$link <span class='smaller nobreak'>[pid " . $row['pid'] . "]</span></td>";
				} else {
					$html .= "<td style='max-width: 200px;'>$title</td>";
				}
			} else {
				$html .= "<td></td>";
			}
		} elseif (isset($row[$header]) && ($row[$header] !== false)) {
			if ($header == "method") {
				$shortFilename = basename($row["file"]);
				$html .= "<td>{$row['method']}<br/><span class='smaller'>$shortFilename</span></td>";
			} elseif (preg_match("/Ts$/", $header) && is_integer($row[$header])) {
				$html .= "<td>" . date("Y-m-d H:i:s", $row[$header]) . "</td>";
			} elseif (is_array($row[$header]) && REDCapManagement::isAssoc($row[$header])) {
				$values = [];
				foreach ($row[$header] as $key => $value) {
					if (is_array($value)) {
						$values[] = "<strong>$key</strong>: ".implode(", ", $value);
					} else {
						$values[] = "<strong>$key</strong>: $value";
					}
				}
				$html .= "<td style='max-width: 200px;'><div class='scrollable'>" . implode("<br/>", $values) . "</div></td>";
			} elseif (is_array($row[$header]) && !REDCapManagement::isAssoc($row[$header])) {
				$html .= "<td style='max-width: 200px;'><div class='scrollable'>" . implode(", ", $row[$header]) . "</div></td>";
			} else {
				$html .= "<td>" . $row[$header] . "</td>";
			}
		} elseif (($header == "records") && isset($row['pids'])) {
			$html .= "<td class='bolded'>" . count($row['pids']) . " pids</td>";
		} elseif (is_array($row[$header])) {
			$html .= "<td style='max-width: 200px;'><div class='scrollable'>" . implode(", ", $row[$header]) . "</div></td>";
		} else {
			$html .= "<td></td>";
		}
	}
	return $html;
}
