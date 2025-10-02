<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/ClassLoader.php");

class FTStats
{
	# 3 years is arbitrary, but our data is growing; started collecting data in 2020
	public const NUM_YEARS_OF_RECORDS = 5;
	public const SEPARATOR = "|";
	public const ONE_WEEK = 7 * 24 * 3600;

	public static function getItemsToBeTotaled() {
		return [
			"Number of Scholars Currently Tracked (Newman)",
			"Number of Scholars Currently Tracked (Other Vanderbilt)",
			"Number of Scholars Currently Tracked (Outside)",
			"Number of Scholars Currently Tracked (Total)",
		];
	}

	public static function getRecordIds($token, $server) {
		$data = [
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'csvDelimiter' => '',
			'fields' => ['record_id'],
			'rawOrLabel' => 'raw',
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		];
		return self::sendToServer($server, $data);
	}

	public static function sendToServer($server, $data) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $server);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$pid = isset($data['token']) ? Application::getPID($data['token']) : "";
		URLManagement::applyProxyIfExists($ch, $pid);
		$output = curl_exec($ch);
		curl_close($ch);
		$results = (is_string($output) && ($output !== "")) ? json_decode($output, true) : [];
		if (isset($results['error'])) {
			throw new \Exception($results['error']);
		}
		return $results;
	}

	public static function getRecentData($token, $server) {
		# since thousands of records, screen by date
		$initialRequest = [
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'csvDelimiter' => '',
			'fields' => ["record_id", "date"],
			'rawOrLabel' => 'raw',
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		];
		$initialREDCapData = self::sendToServer($server, $initialRequest);

		# should be sufficient - we really only need the last week, but this covers edge conditions
		$downloadThresholdTs = strtotime("-2 weeks");
		$deleteThresholdTs = strtotime("-".self::NUM_YEARS_OF_RECORDS." years");

		$recordsToDownload = [];
		$recordsToDelete = [];
		foreach ($initialREDCapData as $row) {
			if ($row['date']) {
				$rowTs = strtotime($row['date']);
				if ($rowTs >= $downloadThresholdTs) {
					$recordsToDownload[] = $row['record_id'];
				} elseif ($rowTs < $deleteThresholdTs) {
					$recordsToDelete[] = $row['record_id'];
				}
			}
		}

		if (!empty($recordsToDelete)) {
			$deleteRequest = [
				'token' => $token,
				'content' => 'record',
				'action' => 'delete',
				'records' => $recordsToDelete,
			];
			self::sendToServer($server, $deleteRequest);
		}

		if (empty($recordsToDownload)) {
			return [];
		}

		$dataRequest = [
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'csvDelimiter' => '',
			'records' => $recordsToDownload,
			'rawOrLabel' => 'raw',
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		];
		return self::sendToServer($server, $dataRequest);
	}

	public static function getAllData($token, $server) {
		$dataRequest = [
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'csvDelimiter' => '',
			'rawOrLabel' => 'raw',
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		];
		return self::sendToServer($server, $dataRequest);
	}

	public static function filterDataForWeek($redcapData, $ts) {
		$lastWeekDays = self::getLastWeekDates($ts);
		$newREDCapData = [];
		foreach ($redcapData as $row) {
			if (in_array($row['date'], $lastWeekDays)) {
				$newREDCapData[] = $row;
			}
		}
		return $newREDCapData;
	}

	public static function gatherAllStats($redcapData, $server, $startTs, $endTs, $includeNewman = true) {
		$uniqueCounts = [
			"Total Number of Reports" => ["record_id"],
			"Total Number of Projects Ever" => ["pid", "server"],
			"Total Number of Servers Ever" => ["server"],
			"Total Number of Domains Ever" => ["domain"],
			"Total Number of Weeks of Reporting" => ["date"],
		];
		$mostRecentCounts = [];
		if ($includeNewman) {
			$mostRecentCounts["Number of Scholars Currently Tracked (Newman)"] = ["newman"];
		}
		$mostRecentCounts["Number of Scholars Currently Tracked (Other Vanderbilt)"] = ["num_scholars", "server=vanderbilt.edu"];
		$mostRecentCounts["Number of Scholars Currently Tracked (Outside)"] = ["num_scholars"];
		$mostRecentCounts["Number of Projects Currently Active"] = ["pid", "server"];
		$mostRecentCounts["Number of Servers Currently Active"] = ["server"];
		$mostRecentCounts["Number of Domains Currently Active"] = ["domain"];
		//    $mostRecentCounts["Number of Institutions Currently Active"] = ["institution"];
		$totalLabels = self::getItemsToBeTotaled();

		$lastWeekDaysForTs = [];
		$valuesByTs = [];
		$allTimeValues = [];
		for ($ts = $startTs; $ts <= $endTs; $ts += self::ONE_WEEK) {
			$lastWeekDaysForTs[$ts] = self::getLastWeekDates($ts);
			$valuesByTs[$ts] = [];
		}
		foreach (array_keys($uniqueCounts) as $label) {
			$allTimeValues[$label] = [];
		}

		foreach ($redcapData as $i => $row) {
			foreach ($uniqueCounts as $label => $fields) {
				if (in_array($label, $totalLabels)) {
					$value = self::processValueForFields($fields, $row);
					if ($value) {
						$allTimeValues[$label][] = $value;
					}
				} else {
					$entryValues = [];
					foreach ($fields as $field) {
						if ($field) {
							if ($field == "domain") {
								$entryValues[] = self::getDomain($row["server"]);
							} else {
								$entryValues[] = $row[$field];
							}
						}
					}
					$entryValue = implode(self::SEPARATOR, $entryValues);
					if (!in_array($entryValue, $allTimeValues[$label])) {
						$allTimeValues[$label][] = $entryValue;
					}
				}
			}
		}
		$previousWeeksDays = self::getLastWeekDates(time());
		foreach ($mostRecentCounts as $label => $fields) {
			foreach (array_keys($lastWeekDaysForTs) as $ts) {
				$valuesByTs[$ts][$label] = [];
			}
			if (($fields[0] == "newman") && !Application::isLocalhost()) {
				foreach (array_keys($lastWeekDaysForTs) as $ts) {
					$date = date("Y-m-d", $ts);
					if (in_array($date, $previousWeeksDays)) {
						$newmanRecordIds = self::getRecordIds(NEWMAN_TOKEN, $server);
						$valuesByTs[$ts][$label][] = count($newmanRecordIds);
					}
				}
			}
		}
		foreach ($redcapData as $i => $row) {
			foreach ($mostRecentCounts as $label => $fields) {
				if ($fields[0] != "newman") {
					foreach (array_keys($lastWeekDaysForTs) as $ts) {
						if (in_array($row['date'], $lastWeekDaysForTs[$ts])) {
							if (in_array($label, $totalLabels)) {
								$value = self::processValueForFields($fields, $row);
								if ($value) {
									$valuesByTs[$ts][$label][] = $value;
								}
							} else {
								$entryValues = [];
								foreach ($fields as $field) {
									if ($field == "domain") {
										$entryValues[] = self::getDomain($row["server"]);
									} else {
										$entryValues[] = $row[$field];
									}
								}
								$entryValue = implode(self::SEPARATOR, $entryValues);
								if (!in_array($entryValue, $valuesByTs[$ts][$label])) {
									$valuesByTs[$ts][$label][] = $entryValue;
								}
							}
							break;
						}
					}
				}
			}
		}
		foreach (array_keys($valuesByTs) as $ts) {
			if (self::allArraysEmpty($valuesByTs[$ts])) {
				unset($valuesByTs[$ts]);
			} else {
				foreach ($allTimeValues as $label => $value) {
					$valuesByTs[$ts][$label] = $value;
				}
			}
		}

		$scholarLabelsToTotal = [
			"Number of Scholars Currently Tracked (Newman)",
			"Number of Scholars Currently Tracked (Other Vanderbilt)",
			"Number of Scholars Currently Tracked (Outside)",
		];
		$totalLabel = "Number of Scholars Currently Tracked (Total)";
		foreach (array_keys($valuesByTs) as $ts) {
			$valuesByTs[$ts][$totalLabel] = [];
			foreach ($scholarLabelsToTotal as $label) {
				foreach ($valuesByTs[$ts][$label] as $value) {
					if ($value > 0) {
						$valuesByTs[$ts][$totalLabel][] = $value;
					}
				}
			}
		}
		return $valuesByTs;
	}

	private static function allArraysEmpty($ary) {
		foreach ($ary as $key => $value) {
			if (!empty($value)) {
				return false;
			}
		}
		return true;
	}

	public static function gatherStats($redcapData, $server, $ts, $includeNewman = true) {
		$endTs = $ts + self::ONE_WEEK - 1;
		$allStats = self::gatherAllStats($redcapData, $server, $ts, $endTs, $includeNewman);
		return $allStats[$ts] ?? [];
	}

	public static function makeId($row) {
		$sep = ":";
		return $row['pid'].$sep.$row['server'].$sep.$row['date'];
	}

	public static function getLastWeekDates($ts = null) {
		if (!$ts) {
			$ts = time();
		}
		$dates = [];
		while (count($dates) < 7) {
			$dates[] = date("Y-m-d", $ts);
			$ts -= 24 * 3600;
		}
		return $dates;
	}

	public static function getLastSaturdayDate($ts = null) {
		if (!$ts) {
			$ts = time();
		}
		while (date("w", $ts) != 6) {
			$ts -= 24 * 3600;
		}
		return date("Y-m-d", $ts);
	}

	public static function processValueForFields($fields, $row) {
		$primaryField = $fields[0];
		$stipulationsValid = true;
		for ($i = 1; $i < count($fields); $i++) {
			if (preg_match("/=/", $fields[$i])) {
				list($stipulationField, $stipulationValue) = explode("=", $fields[$i]);
				if (!preg_match("/$stipulationValue/", $row[$stipulationField])) {
					$stipulationsValid = false;
				}
			}
		}
		if ($stipulationsValid) {
			if ($primaryField == "domain") {
				$domain = self::getDomain($row["server"]);
				if (isset($_GET['test'])) {
					echo $row['server']." translates to ".$domain."<br/>";
				}
			} else {
				return $row[$primaryField];
			}
		}
		return false;
	}

	public static function getDomain($server) {
		return URLManagement::getDomain($server);
	}

	public static function isLatestRowForProject($recordId, $pid, $server, $allREDCapData) {
		$latestRecordId = 0;
		foreach ($allREDCapData as $row) {
			if (($row['pid'] == $pid) &&
				($row['server'] == $server) &&
				($row['record_id'] > $latestRecordId)) {
				$latestRecordId = $row['record_id'];
			}
		}
		return ($recordId == $latestRecordId);
	}
}
