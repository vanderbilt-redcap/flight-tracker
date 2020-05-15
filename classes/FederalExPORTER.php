<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/REDCapManagement.php");

define("DATA_DIRECTORY", "filterData");
define("INTERMEDIATE_1_FED", "R01AndEquivsList_Fed.txt");
define("INTERMEDIATE_2_FED", "R01AndEquivsList2_Fed.txt");
define("INTERMEDIATE_3_FED", "R01AndEquivsList3_Fed.txt");
define("INTERMEDIATE_4_FED", "R01AndEquivsList4_Fed.txt");
define("PI_LIST_FED", "PIList_Fed.txt");

class FederalExPORTER {
	public function __construct() {
		$this->data = array();
	}

	private static function clearBlanks($ary) {
		$ary2 = array();
		foreach($ary as $item) {
			if ($item) {
				array_push($ary2, $item);
			}
		}
		return $ary2;
	}

	public function showDataSince($date) {
		$this->data = self::filterForActivityCodeSinceDate("/\d[Kk]\d\d/", $date);
		echo $this->display();
	}

	public function showR01DataSince($date, $names) {
		self::filterForActivityCodeSinceDateOrR01EquivalentAtVUMC("/\dR01/", $date);
		self::filterOut("/R56/", "PROJECT_NUMBER");
		self::filterOutNames($names);
		self::printPIs();
		// echo "<h2>".count($this->data)." Rows</h2>\n";
		// $pis = $this->getPIs();
		// echo "<h2>".count($pis)." PIs</h2>\n";
		// echo implode("\n", $pis)."\n";
		// $rows = self::filterLDAP($pis);
		// echo "<h2>".count($rows)." Emails</h2>\n";

		// echo "<table style='margin-left: auto; margin-right: auto;'>\n";
		// foreach ($rows as $row) {
			// echo "<tr><td>{$row['name']}</td><td>{$row['email']}</td></tr>\n";
		// }
		// echo "</table>\n";
		// echo $this->display();
	}

	public static function printPIs() {
		$cols = array("CONTACT_PI_PROJECT_LEADER", "OTHER_PIS");
		$allPIs = array();
		$fpin = fopen(DATA_DIRECTORY."/".INTERMEDIATE_4_FED, "r");
		$fpout = fopen(DATA_DIRECTORY."/".PI_LIST_FED, "w");
		$headers = fgetcsv($fpin);
		while ($line = fgetcsv($fpin)) {
			$title = "";
			$IC = "";

			$i = 0;
			foreach ($line as $item) {
				if ($headers[$i] == "IC_CENTER") {
					$IC = $item;
				} else if ($headers[$i] == "PROJECT_TITLE") {
					$title = $item;
				}
				$i++;
			}

			$i = 0;
			foreach ($line as $item) {
				if (in_array($headers[$i], $cols)) {
					$pis = preg_split("/; /", $line[$i]);
					foreach ($pis as $pi) {
						$pi = preg_replace("/ \(contact\)\s*;?$/", "", $pi);
						$pi = preg_replace("/\s*;$/", "", $pi);
						if ($pi && !in_array($pi, $allPIs)) {
							Application::log("Found PI: $pi");
							array_push($allPIs, array("PI" => $pi, "IC" => $IC, "Title" => $title));
						}
					}
				}
				$i++;
			}
		}

		foreach ($allPIs as $pi) {
			fputcsv($fpout, array($pi["PI"], $pi["IC"], $pi["Title"]));
		}

		fclose($fpin);
		fclose($fpout);
	}

	private static function filterLDAP($pis) {
		$outData = array();
		foreach ($pis as $pi) {
			$nameFields = array("sn", "givenname");
			$names = preg_split("/\s*,\s*/", $pi);
			if (count($names) > 2) {
				$names = array($names[0], $names[1]);
			}
			$res = LDAP::getLDAPByMultiple($nameFields, $names);
			if ($res) {
				$row = array("name" => $pi, "email" => $res[0]['mail'][0]);
				array_push($outData, $row);
			}
			sleep(1);
		}
		return $outData;
	}

	public function getPIs() {
		$cols = array("CONTACT_PI_PROJECT_LEADER", "OTHER_PIS");
		$allPIs = array();
		foreach ($this->data as $row) {
			$pis = array();
			foreach ($cols as $col) {
				$colPis = preg_split("/; /", $row[$col]);
				array_merge($pis, $colPis);
			}
			foreach ($pis as $pi) {
				if ($pi && !in_array($pi, $allPIs)) {
					array_push($allPIs, $pi);
				}
			}
		}
		return $allPIs;
	}

	public function display() {
		$html = "";
		$html .= "<table style='margin-left: auto; margin-right: auto;'>\n";
		$html .= "<tr><th>Names</th><th>Project ID</th><th>Institution</th><th>Start Date</th></tr>\n";
		foreach ($this->data as $row) {
			$piNames = array();
			$cols = array("CONTACT_PI_PROJECT_LEADER", "OTHER_PIS");
			foreach ($cols as $col) {
				if ($row[$col]) {
					$colPis = self::clearBlanks(preg_split("/;\s*/", $row[$col]));
					foreach ($colPis as $colPi) {
						if (!in_array($colPi, $piNames)) {
							array_push($piNames, $colPi);
						}
					}
				}
			}

			$namesWithLinks = array();
			foreach ($piNames as $pi) {
				array_push($namesWithLinks, $pi);
			}

			$projectId = $row['PROJECT_NUMBER'];
			$institution = $row['PROJECT_NUMBER'];
			$startDate = $row['BUDGET_START_DATE'];

			$html .= "<tr>\n";
			$html .= "\t<td>".implode("<br>", $namesWithLinks)."</td>\n";
			$html .= "\t<td>$projectId</td>\n";
			$html .= "\t<td>$institution</td>\n";
			$html .= "\t<td>$startDate</td>\n";
			$html .= "</tr>\n";
		}
		$html .= "</table>\n";
		return $html;
	}

	public static function filterForR01EquivalentSinceDate($date) {
		$files = self::getDataSince2008();
		$outData = array();
		$ts = strtotime($date);
		$tsYear = date("Y", $ts);
		foreach ($files as $file => $fiscalYear) {
			if ($fiscalYear >= $tsYear - 1) {
				$data = self::parseFile($file);
				$filtered = self::filterR01Equivalents($data);
				unset($data);
				$outData = array_merge($outData, $filtered);
				unset($filtered);
			}
		}
		return $outData;
	}

	public static function filterForActivityCodeSinceDateOrR01EquivalentAtVUMC($regexActivityCode, $date) {
		$files = self::getDataSince2008();
		$ts = strtotime($date);
		$tsYear = date("Y", $ts);
		$fp = fopen(DATA_DIRECTORY."/".INTERMEDIATE_1_FED, "w");
		$first = TRUE;
		foreach ($files as $file => $fiscalYear) {
			if ($fiscalYear >= $tsYear - 1) {
				$data = self::parseFile($file);
				if ($first) {
					$headers = array_keys($data[0]);
					fputcsv($fp, $headers);
					$first = FALSE;
				}
				$filtered1 = self::filter($data, "/VANDERBILT/", "ORGANIZATION_NAME");
				unset($data);
				$filtered2 = self::filter($filtered1, $regexActivityCode, "PROJECT_NUMBER");
				$filtered3 = self::filterR01Equivalents($filtered1);
				unset($filtered1);
				$filtered = self::filterTime(array_merge($filtered2, $filtered3), $ts, "FY");
				unset($filtered1);
				unset($filtered2);
				unset($filtered3);
				self::writeData($filtered, $headers, $fp);
				unset($filtered);
			}
		}
		fclose($fp);
	}

	public static function writeData($data, $keys, $fp) {
		if (count($data) > 0) {
			for ($i = 0; $i < count($data); $i++) {
				$ary = array();
				foreach ($keys as $key) {
					$datum = $data[$i][$key];
					$datum = str_replace("\\", "", $datum);
					array_push($ary, $datum);
				}
				fputcsv($fp, $ary);
			}
		}
	}

	public static function filterForActivityCodeSinceDate($regexActivityCode, $date) {
		$files = self::getDataSince2008();
		$outData = array();
		$ts = strtotime($date);
		$tsYear = date("Y", $ts);
		foreach ($files as $file => $fiscalYear) {
			if ($fiscalYear >= $tsYear - 1) {
				# memory intensive!!!

				$data = self::parseFile($file);
				$filtered = self::filter($data, $regexActivityCode, "PROJECT_NUMBER");
				unset($data);
				$filtered = self::filterTime($filtered, $ts, "FY");
				$outData = array_merge($outData, $filtered);
				unset($filtered);
			}
		}
		return $outData;
	}

	private static function filterR01Equivalents($data) {
		$inData = array();
		$budgetCol = "FY_TOTAL_COST";
		$budgetColBackup = "FY_TOTAL_COST_SUB_PROJECTS";
		$projectStartCol = "PROJECT_START_DATE";
		$projectEndCol = "PROJECT_END_DATE";
		$projectNumCol = "PROJECT_NUMBER";

		$projectNumRegEx = "/\dR01/";

		$yearsForR01Equiv = 3;
		$twoDayFudgeFactor = 2 * 24 * 3600;  // since project_end - project_start is often one day shy of three years
		$minTimespan = $yearsForR01Equiv * 365 * 24 * 3600 - $twoDayFudgeFactor;

		foreach ($data as $row) {
			$projectStart = strtotime($row[$projectStartCol]);
			$projectEnd = strtotime($row[$projectEndCol]);
			if ($projectStart && $projectEnd) {
				$budget = $row[$budgetCol];
				if (!$budget) {
					$budget = $row[$budgetColBackup];
				}
				if (($budget >= 250000) && ($projectEnd - $projectStart > $minTimespan)) {
					if (!preg_match($projectNumRegEx, $row[$projectNumCol])) {
						array_push($inData, $row);
					}
				}
			}
		}
		return $inData;
	}

	# on or after $ts
	private static function filterTime($data, $ts, $col) {
		$inData = array();
		foreach ($data as $row) {
			if ($row[$col]) {
				if (!strtotime($row[$col]) && is_numeric($row[$col])) {
					$currTs = strtotime($row[$col]."-01-01");
				} else {
					$currTs = strtotime($row[$col]);
				}
				if ($currTs && ($ts <= $currTs)) {
					array_push($inData, $row);
				}
			}
		}
		return $inData;
	}

	private static function filterForPIs($data, $piIds) {
		$inData = array();
		$col = "PI_IDS";
		foreach ($data as $row) {
			if ($row[$col]) {
				$currPis = preg_split("/; /", $row[$col]);
				foreach ($currPis as $currPiId) {
					if ($currPiId && in_array($currPiId, $piIds)) {
						array_push($inData, $row);
						break;
					}
				}
			}
		}
		return $inData;
	}

	private static function filterOutNames($names) {
		$inData = array();
		$cols = array("CONTACT_PI_PROJECT_LEADER", "OTHER_PIS");
		$fpin = fopen(DATA_DIRECTORY."/".INTERMEDIATE_3_FED, "r");
		$fpout = fopen(DATA_DIRECTORY."/".INTERMEDIATE_4_FED, "w");
		$headers = fgetcsv($fpin);
		fputcsv($fpout, $headers);
		while ($row = fgetcsv($fpin)) {
			$matched = FALSE;
			foreach ($names as $nameRow) {
				if ($nameRow['first_name'] && $nameRow['last_name']) {
					for ($i = 0; $i < count($row); $i++) {
						if (in_array($headers[$i], $cols)) {
							if ($row[$i] && preg_match("/".strtoupper($nameRow['first_name'])."/", $row[$i]) && preg_match("/".strtoupper($nameRow['last_name'])."/", $row[$i])) {
								// Application::log("Matched ".json_encode($nameRow));
								$matched = TRUE;
							}
						}
					} 
				}
			}
			if (!$matched) {
				fputcsv($fpout, $row);
			}
		}
		fclose($fpin);
		fclose($fpout);
	}

	private static function filterOut($regex, $col) {
		$fpin = fopen(DATA_DIRECTORY."/".INTERMEDIATE_1_FED, "r");
		$headers = fgetcsv($fpin);
		$fpout = fopen(DATA_DIRECTORY."/".INTERMEDIATE_3_FED, "w");
		fputcsv($fpout, $headers);
		while ($row = fgetcsv($fpin)) {
			for ($i = 0; $i < count($row); $i++) {
				if ($headers[$i] == $col) {
					if ($row[$i] && !preg_match($regex, $row[$i])) {
						$assocAry = array();
						$j = 0;
						foreach ($row as $item) {
							$assocAry[$headers[$j]] = $item;
							$j++;
						}
			     			self::writeData(array($assocAry), $headers, $fpout);
					}
				}
			}
		}
		fclose($fpin);
		fclose($fpout);
	}

	private static function filter($data, $regex, $col) {
		$inData = array();
		foreach ($data as $row) {
			if ($row[$col] && preg_match($regex, $row[$col])) {
				array_push($inData, $row);
			}
		}
		return $inData;
	}

	private static function parseFile($file) {
		$data = array();
		$fp = fopen($file, "r");
		Application::log("Parsing ".$file);
		$lineCount = 0;
        $headers = array();
        while ($str = fgets($fp)) {
			$str = str_replace('\\","', '","', $str);
			$line = str_getcsv($str);
			if ($lineCount == 0) {
				$i = 0;
				foreach ($line as $item) {
					$item = trim($item);
					$headers[$i] = $item;
					$i++;
				}
			} else if ($lineCount > 0) {
				$row = array();
				$i = 0;
				foreach ($line as $item) {
					$header = $headers[$i];
					$item = trim($item);
					$row[$header] = $item;
					$i++;
				}
				array_push($data, $row);
			}
			$lineCount++;
		}
		fclose($fp);
		return $data;
	}

	# full data kept since 2008; pre-2008 data does not contain much meaningful information
	private static function getDataSince2008() {
		# find relevant zips
		# download relevent zips into APP_PATH_TEMP
		# unzip zip files

		$lastYear = "";
		$files = array();
		for ($fiscalYear = 2008; $fiscalYear <= date("Y"); $fiscalYear++) {
			$url = "FedRePORTER_PRJ_C_FY".$fiscalYear.".zip";
			$file = self::downloadURL($url);
			echo "Returning ".$file."\n";
			if ($file) {
				$files[$file] = $fiscalYear;
				$lastYear = $fiscalYear;
			}
		}
		return $files;
	}

	private static function getDataSince2018() {
		# find relevant zips
		# download relevent zips into APP_PATH_TEMP
		# unzip zip files

		$lastYear = "";
		$files = array();
		for ($fiscalYear = 2018; $fiscalYear <= date("Y"); $fiscalYear++) {
			$url = "FedRePORTER_PRJ_C_FY".$fiscalYear.".zip";
			$file = self::downloadURL($url);
			if ($file) {
				$files[$file] = $fiscalYear;
				$lastYear = $fiscalYear;
			}
		}
		for ($fiscalYear = $lastYear + 1; $fiscalYear <= date("Y") + 1; $fiscalYear++) {
			for ($week = 1; $week <= 53; $week++) {
				$weekWithLeading0s = sprintf('%03d', $week);
				$url = "FedRePORTER_PRJ_C_FY".$fiscalYear."_".$weekWithLeading0s.".zip";
				$file = self::downloadURL($url);
				if ($file) {
					$files[$file] = $fiscalYear;
				}
			}
		}
		return $files;
	}

	private static function getDataSince2014() {
		# find relevant zips
		# download relevent zips into APP_PATH_TEMP
		# unzip zip files

		$lastYear = "";
		$files = array();
		for ($fiscalYear = 2014; $fiscalYear <= date("Y"); $fiscalYear++) {
			$url = "FedRePORTER_PRJ_C_FY".$fiscalYear.".zip";
			$file = self::downloadURL($url);
			if ($file) {
				$files[$file] = $fiscalYear;
				$lastYear = $fiscalYear;
			}
		}
		for ($fiscalYear = $lastYear + 1; $fiscalYear <= date("Y") + 1; $fiscalYear++) {
			for ($week = 1; $week <= 53; $week++) {
				$weekWithLeading0s = sprintf('%03d', $week);
				$url = "FedRePORTER_PRJ_C_FY".$fiscalYear."_".$weekWithLeading0s.".zip";
				$file = self::downloadURL($url);
				if ($file) {
					$files[$file] = $fiscalYear;
				}
			}
		}
		return $files;
	}

	/**
	 * download a file from Federal ExPORTER, unzip, returns absolute filename
	 * returns empty string if $file not found
	 * @param string $file Filename without leading URL
	 */
	private static function downloadURL($file) {
		$downloadedCSVFile = preg_replace("/.zip/", ".csv", $file);
		$csvfile = preg_replace("/.zip/", ".federal.csv", $file);
		$federalFile = preg_replace("/.zip/", ".federal.zip", $file);
		if (!file_exists(APP_PATH_TEMP.$csvfile)) {
			Application::log("Downloading $file...");

			$url = "https://federalreporter.nih.gov/FileDownload/DownloadFile?fileToDownload=".$file;
			list($resp, $zip) = REDCapManagement::downloadURL($url);

			if (($resp == 200) && (!preg_match("/Not found/", $zip))) {
				Application::log("Unzipping $file to ".APP_PATH_TEMP.$federalFile."...");
				$fp = fopen(APP_PATH_TEMP.$federalFile, "w");
				fwrite($fp, $zip);
				fclose($fp);
				unset($zip);

				$za = new ZipArchive;
				if ($za->open(APP_PATH_TEMP.$federalFile)) {
					Application::log("Opened ".APP_PATH_TEMP.$federalFile);
					$za->extractTo(APP_PATH_TEMP);
					$za->close();
					unset($za);

					rename(APP_PATH_TEMP.$downloadedCSVFile, APP_PATH_TEMP.$csvfile);
					return APP_PATH_TEMP.$csvfile;
				}
			}
			return "";
		} else {
			return APP_PATH_TEMP.$csvfile;
		}
	}
}
