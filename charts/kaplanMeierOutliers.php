<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Scholar;
use Vanderbilt\CareerDevLibrary\Grants;
use Vanderbilt\CareerDevLibrary\Publications;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

$fields = [
	"record_id",
	"summary_first_grant_activity",
	"summary_first_pub_activity",
];

$maxYearGapInPublishing = 10;
$thresholdYear = 1990;
$thresholdTs = strtotime($thresholdYear."-01-01");
$yearSpan = $maxYearGapInPublishing * 365 * 24 * 3600;
$maxCluster = ["Grants" => 1, "Publications" => 3];

$possibleRecords = [];
$redcapData = Download::fields($token, $server, $fields);
foreach ($redcapData as $row) {
	$recordId = $row['record_id'];
	$fields = ['summary_first_grant_activity', 'summary_first_pub_activity'];
	foreach ($fields as $field) {
		$date = $row[$field];
		if ($date) {
			$ts = strtotime($date);
			if ($ts < $thresholdTs) {
				if (!isset($possibleRecords[$recordId])) {
					$possibleRecords[$recordId] = [];
				}
				$possibleRecords[$recordId][] = $field;
				$possibleRecords[$recordId][] = $date;
			}
		}
	}
}
$csslink = Application::link("css/career_dev.css");
if (strpos($csslink, "?") !== false) {
	$csslink .= "&".CareerDev::getVersion();
} else {
	$csslink .= "?".CareerDev::getVersion();
}
echo "<link rel='stylesheet' href='$csslink'>";

$jsLocs = ["js/base.js", "js/jquery.min.js"];
foreach ($jsLocs as $jsLoc) {
	$jslink = Application::link($jsLoc);
	if (strpos($jslink, "?") !== false) {
		$jslink .= "&".CareerDev::getVersion();
	} else {
		$jslink .= "?".CareerDev::getVersion();
	}
	echo "<script src='$jslink'></script>";
}

if (empty($possibleRecords)) {
	echo "<p>All grants and publications are dated on-or-after $thresholdYear.</p>";
} else {
	$grantNumbers = [];
	$pmids = [];
	$module = Application::getModule();
	$metadata = Download::metadata($token, $server);
	$fieldsToDownload = [
		"summary_first_grant_activity" => REDCapManagement::getFieldsFromMetadata($metadata),
		"summary_first_pub_activity" => Application::getCitationFields($metadata),
	];
	$outliers = [];
	foreach ($possibleRecords as $recordId => $impactedFields) {
		$fields = [];
		foreach ($impactedFields as $impactedField) {
			if ($fieldsToDownload[$impactedField]) {
				$fields = array_unique(array_merge($fields, $fieldsToDownload[$impactedField]));
			}
		}
		if (!empty($fields)) {
			$redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
			$scholar = new Scholar($token, $server, $metadata, $pid);
			$scholar->setRows($redcapData);
			$dates = [];
			if (in_array("summary_first_grant_activity", $impactedFields)) {
				$dates["Grants"] = $scholar->getGrantDates($redcapData, true);
			}
			if (in_array("summary_first_pub_activity", $impactedFields)) {
				$dates["Publications"] = $scholar->getPubDates($redcapData, true);
			}
			foreach ($dates as $type => $linksWithDates) {
				$timestamps = [];
				foreach ($linksWithDates as $link => $date) {
					$timestamps[] = strtotime($date);
				}
				sort($timestamps);
				$foundOutliers = [];
				for ($i = 1; ($i <= $maxCluster[$type]) && ($i < count($timestamps)); $i++) {
					$lastTimestamp = $timestamps[$i];
					$clusterTimestamp = $timestamps[$i - 1] ?? 0;
					if (
						$clusterTimestamp
						&& ($clusterTimestamp < $thresholdTs)
						&& ($clusterTimestamp + $yearSpan < $lastTimestamp)
					) {
						for ($j = 0; $j < $i; $j++) {
							$foundOutliers[] = $timestamps[$j];
						}
					}
				}
				if (!empty($foundOutliers)) {
					foreach ($foundOutliers as $outlierTs) {
						$outlierDate = $outlierTs ? date("Y-m-d", $outlierTs) : "Unknown";
						if (isset($_GET['test'])) {
							echo "<p>Record $recordId: Looking for $outlierDate among " . count(array_values($linksWithDates)) . " items</p>";
						}
						foreach ($linksWithDates as $link => $date) {
							if ($date == $outlierDate) {
								if (!isset($outliers[$recordId])) {
									$outliers[$recordId] = ["Grants" => [], "Publications" => []];
								}
								$sourceInstrument = getParamFromLink($link, "page");
								$summary = "";
								if ($type == "Grants") {
									$summary = "from $sourceInstrument";
									$grants = new Grants($token, $server, $metadata);
									$grants->setRows($redcapData);
									$grants->compileGrants();
									foreach ($grants->getGrants("all") as $grant) {
										if ((($grant->getVariable("start") == $date) || ($grant->getVariable("end") == $date)) && ($sourceInstrument == $grant->getVariable("source"))) {
											$summary = $grant->getNumber()." from ".$sourceInstrument;
											if (!isset($grantNumbers[$recordId])) {
												$grantNumbers[$recordId] = [];
											}
											$grantNumbers[$recordId][] = $grant->getNumber();
											break;
										}
									}
								} elseif ($type == "Publications") {
									$pubs = new Publications($token, $server, $metadata);
									$pubs->setRows($redcapData);
									foreach ($pubs->getCitations("Included") as $citation) {
										$pubTs = $citation->getTimestamp();
										if ($date == date("Y-m-d", $pubTs)) {
											$summary = $citation->getCitationWithLink(false, true);
											if (!isset($pmids[$recordId])) {
												$pmids[$recordId] = [];
											}
											$pmids[$recordId][] = $citation->getPMID();
											break;
										}
									}
								}
								if ($summary) {
									$summary = "<br>".$summary;
								}
								$outliers[$recordId][$type][] = $date . " (" . $link . ")".$summary;
							}
						}
					}
				} else {
					$examinedDates = [];
					for ($i = 0; $i < $maxCluster[$type]; $i++) {
						$examinedDates[] = $timestamps[$i] ? date("Y-m-d", $timestamps[$i]) : "Unknown";
					}
					if (isset($_GET['test'])) {
						echo "<p>Record $recordId has no outliers, but examined the dates: ".implode(", ", $examinedDates)."</p>";
					}
				}
			}
		}
	}
	$definition = "<p class='centered max-width'>A <b>group of outliers</b> is defined as 1 grant or 1-{$maxCluster["Publications"]} publications before $thresholdYear with a &gt; $maxYearGapInPublishing-year gap before the next grant/publication.</p>";
	if (empty($outliers)) {
		echo "<p>No outliers have been found.</p>";
		echo $definition;
	} else {
		$cnt = count($outliers);
		echo "<h1>Found $cnt Records with Outliers</h1>";
		echo $definition;
		$types = ["Grants", "Publications"];
		$names = Download::names($token, $server);
		foreach ($outliers as $recordId => $ary) {
			$link = Links::makeRecordHomeLink($pid, $recordId, "Record $recordId: ".$names[$recordId]);
			echo "<h2>$link</h2>";
			foreach ($types as $type) {
				if ($ary[$type] && !empty($ary[$type])) {
					$list = [];
					$i = 0;
					foreach ($ary[$type] as $item) {
						if ($type == "Publications") {
							$instance = getParamFromLink($item, "instance");
							$pmid = $pmids[$recordId][$i];
							$action = "<button onclick='omitPublication(\"$recordId\", \"$instance\", \"$pmid\"); return false;'>Omit Publication</button>";
						} elseif ($type == "Grants") {
							$sourceInstrument = getParamFromLink($item, "page");
							$grantNumber = $grantNumbers[$recordId][$i];
							$action = "<button onclick='omitGrant(\"$recordId\", \"$grantNumber\", \"$sourceInstrument\"); return false;'>Avoid Grant</button>";
						} else {
							$action = "";
						}
						$list[] = $item." ".$action;
						$i++;
					}

					echo "<h4>$type</h4>";
					echo "<p class='centered'>".implode("<br>", $list)."</p>";
				}
			}
		}
	}
}


function getParamFromLink($link, $param) {
	if (preg_match("/<a[^>]+href\s*=\s*([\"\'][^\"^\']+[\"\'])/", $link, $matches)) {
		if (isset($_GET['test'])) {
			echo "Got matches[1]: ".$matches[1]."<br>";
		}
		$url = preg_replace("/[\"\']/", "", $matches[1]);
		if (isset($_GET['test'])) {
			echo "Got $url to parse for $param<br>";
		}
		$params = REDCapManagement::getParameters($url);
		if (isset($params[$param])) {
			return $params[$param];
		}
	}
	return "";
}
