<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

if (!defined("DATA_DIRECTORY")) {
    define("DATA_DIRECTORY", "filterData/");
}
if (!defined("INTERMEDIATE_1")) {
    define("INTERMEDIATE_1", "R01AndEquivsList.txt");
}
if (!defined("INTERMEDIATE_2")) {
    define("INTERMEDIATE_2", "R01AndEquivsList2.txt");
}
if (!defined("INTERMEDIATE_3")) {
    define("INTERMEDIATE_3", "R01AndEquivsList3.txt");
}
if (!defined("INTERMEDIATE_4")) {
    define("INTERMEDIATE_4", "R01AndEquivsList4.txt");
}
if (!defined("PI_LIST")) {
    define("PI_LIST", "PIList.txt");
}

class NIHExPORTER {
	public function __construct($pid) {
		$this->data = [];
		$this->pid = $pid;
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
		$this->data = self::filterForActivityCodeSinceDate("/\d[Kk]\d\d/", $date, $this->pid);
		echo $this->display();
	}

	public function showR01DataSince($date, $names) {
		self::filterForActivityCodeSinceDateOrR01EquivalentAtVUMC("/\dR01/", $date, $this->pid);
		self::grabAllGrantsForPIs($date, $this->pid);
		self::filterOut("/R56/", "FULL_PROJECT_NUM");
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
		$col = "PI_NAMEs";
		$allPIs = array();
		$fpin = fopen(DATA_DIRECTORY.INTERMEDIATE_4, "r");
		$fpout = fopen(DATA_DIRECTORY.PI_LIST, "w");
		$headers = fgetcsv($fpin);
		while ($line = fgetcsv($fpin)) {
			$title = "";
			$IC = "";

			$i = 0;
			foreach ($line as $item) {
				if ($headers[$i] == "ADMINISTERING_IC") {
					$IC = $item;
				} else if ($headers[$i] == "PROJECT_TITLE") {
					$title = $item;
				}
				$i++;
			}

			$i = 0;
			foreach ($line as $item) {
				if ($headers[$i] == $col) {
					$pis = preg_split("/;/", $line[$i]);
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
		$col = "PI_NAMEs";
		$allPIs = array();
		foreach ($this->data as $row) {
			$pis = preg_split("/; /", $row['PI_NAMEs']);
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
			$piIds = array();
			if ($row['PI_IDS']) {
				$piIds = self::clearBlanks(preg_split("/;\s*/", $row['PI_IDS']));
			}

			$piNames = array();
			if ($row['PI_NAMEs']) {
				$piNames = self::clearBlanks(preg_split("/;\s*/", $row['PI_NAMEs']));
			}

			$namesWithLinks = array();
			if (count($piIds) == count($piNames)) {
				$i = 0;
				foreach ($piNames as $pi) {
					$id = $piIds[$i];
					array_push($namesWithLinks, "<a href='https://projectreporter.nih.gov/VEmailReq.cfm?pid=$id' target='_NEW'>$pi</a>");
					$i++;
				}
			} else {
				foreach ($piNames as $pi) {
					array_push($namesWithLinks, $pi);
				}
			}

			$projectId = $row['FULL_PROJECT_NUM'];
			$institution = $row['ORG_NAME'];
			$startDate = $row['BUDGET_START'];

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

	public static function filterForR01EquivalentSinceDate($date, $pid) {
		$files = self::getDataSince2009($pid);
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

	public static function grabAllGrantsForPIs($date, $pid) {
		$files = self::getDataSince2009($pid);
		$pis = array();
		$ts = strtotime($date);
		$tsYear = date("Y", $ts);
		$col = "PI_IDS";
		$fp = fopen(DATA_DIRECTORY.INTERMEDIATE_1, "r");
		$headers = fgetcsv($fp);
		while ($line = fgetcsv($fp)) {
			for ($i = 0; $i < count($line); $i++) {
				if ($headers[$i] == $col) {
					$ids = preg_split("/; /", $line[$i]);
					foreach ($ids as $id) {
						if ($id && !in_array($id, $pis)) {
							array_push($pis, $id);
						}
					}
				}
			}
		}
		fclose($fp);
		
		$fp = fopen(DATA_DIRECTORY.INTERMEDIATE_2, "w");
		fputcsv($fp, $headers);
		foreach ($files as $file => $fiscalYear) {
			if ($fiscalYear >= $tsYear - 1) {
				$fileData = self::parseFile($file);
				$filtered = self::filterForPIs($fileData, $pis);
				unset($fileData);
				self::writeData($filtered, $headers, $fp);
				unset($filtered);
			}
		}
		fclose($fp);
	}

	public static function filterForActivityCodeSinceDateOrR01EquivalentAtVUMC($regexActivityCode, $date, $pid) {
		$files = self::getDataSince2009($pid);
		$ts = strtotime($date);
		$tsYear = date("Y", $ts);
		$fp = fopen(DATA_DIRECTORY.INTERMEDIATE_1, "w");
		$first = TRUE;
		foreach ($files as $file => $fiscalYear) {
			if ($fiscalYear >= $tsYear - 1) {
				$data = self::parseFile($file);
				$headers = [];
				if ($first) {
					$headers = array_keys($data[0]);
					fputcsv($fp, $headers);
					$first = FALSE;
				}
				$filtered1 = self::filter($data, "/VANDERBILT/", "ORG_NAME");
				unset($data);
				$filtered2 = self::filter($filtered1, $regexActivityCode, "FULL_PROJECT_NUM");
				$filtered3 = self::filterR01Equivalents($filtered1);
				unset($filtered1);
				$filtered = self::filterTime(array_merge($filtered2, $filtered3), $ts, "AWARD_NOTICE_DATE");
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

	public static function filterForActivityCodeSinceDate($regexActivityCode, $date, $pid) {
		$files = self::getDataSince2009($pid);
		$outData = array();
		$ts = strtotime($date);
		$tsYear = date("Y", $ts);
		foreach ($files as $file => $fiscalYear) {
			if ($fiscalYear >= $tsYear - 1) {
				# memory intensive!!!

				$data = self::parseFile($file);
				$filtered = self::filter($data, $regexActivityCode, "FULL_PROJECT_NUM");
				unset($data);
				$filtered = self::filterTime($filtered, $ts, "AWARD_NOTICE_DATE");
				$outData = array_merge($outData, $filtered);
				unset($filtered);
			}
		}
		return $outData;
	}

	private static function filterR01Equivalents($data) {
		$inData = array();
		$budgetCol = "DIRECT_COST_AMT";
		$budgetColBackup = "TOTAL_COST";
		$projectStartCol = "PROJECT_START";
		$projectEndCol = "PROJECT_END";
		$projectNumCol = "FULL_PROJECT_NUM";

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
				$currTs = strtotime($row[$col]);
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
		$col = "PI_NAMEs";
		$fpin = fopen(DATA_DIRECTORY.INTERMEDIATE_3, "r");
		$fpout = fopen(DATA_DIRECTORY.INTERMEDIATE_4, "w");
		$headers = fgetcsv($fpin);
		fputcsv($fpout, $headers);
		while ($row = fgetcsv($fpin)) {
			$matched = FALSE;
			foreach ($names as $nameRow) {
				if ($nameRow['first_name'] && $nameRow['last_name']) {
					for ($i = 0; $i < count($row); $i++) {
						if ($headers[$i] == $col) {
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
		$fpin = fopen(DATA_DIRECTORY.INTERMEDIATE_2, "r");
		$headers = fgetcsv($fpin);
		$fpout = fopen(DATA_DIRECTORY.INTERMEDIATE_3, "w");
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
					$headers[$i] = $item;
					$i++;
				}
			} else if ($lineCount > 0) {
				$row = array();
				$i = 0;
				foreach ($line as $item) {
					if ($headers[$i]) {
                        $header = $headers[$i];
                        $row[$header] = $item;
                    }
					$i++;
				}
				array_push($data, $row);
			}
			$lineCount++;
		}
		fclose($fp);
		return $data;
	}

	# full data kept since 2009; pre-2009 data does not contain much meaningful information
	private static function getDataSince2009($pid) {
		# find relevant zips
		# download relevent zips into APP_PATH_TEMP
		# unzip zip files

        $startYear = 2009;
		$lastYear = $startYear;
		$files = array();
		for ($fiscalYear = $startYear; $fiscalYear <= date("Y"); $fiscalYear++) {
			$url = "RePORTER_PRJ_C_FY".$fiscalYear.".zip";
			$file = self::downloadURL($url, $pid);
			if ($file) {
				$files[$file] = $fiscalYear;
				$lastYear = $fiscalYear;
			}
		}
		for ($fiscalYear = $lastYear + 1; $fiscalYear <= date("Y") + 1; $fiscalYear++) {
			for ($week = 1; $week <= 53; $week++) {
				$weekWithLeading0s = sprintf('%03d', $week);
				$url = "RePORTER_PRJ_C_FY".$fiscalYear."_".$weekWithLeading0s.".zip";
				$file = self::downloadURL($url, $pid);
				if ($file) {
					$files[$file] = $fiscalYear;
				}
			}
		}
		return $files;
	}

	private static function getDataSince2018($pid) {
		# find relevant zips
		# download relevent zips into APP_PATH_TEMP
		# unzip zip files

        $startYear = 2018;
		$lastYear = $startYear;
		$files = array();
		for ($fiscalYear = $startYear; $fiscalYear <= date("Y"); $fiscalYear++) {
			$url = "RePORTER_PRJ_C_FY".$fiscalYear.".zip";
			$file = self::downloadURL($url, $pid);
			if ($file) {
				$files[$file] = $fiscalYear;
				$lastYear = $fiscalYear;
			}
		}
		for ($fiscalYear = $lastYear + 1; $fiscalYear <= date("Y") + 1; $fiscalYear++) {
			for ($week = 1; $week <= 53; $week++) {
				$weekWithLeading0s = sprintf('%03d', $week);
				$url = "RePORTER_PRJ_C_FY".$fiscalYear."_".$weekWithLeading0s.".zip";
				$file = self::downloadURL($url, $pid);
				if ($file) {
					$files[$file] = $fiscalYear;
				}
			}
		}
		return $files;
	}

	private static function getDataSince2014($pid) {
		# find relevant zips
		# download relevent zips into APP_PATH_TEMP
		# unzip zip files

        $startYear = 2014;
		$lastYear = $startYear;
		$files = array();
		for ($fiscalYear = $startYear; $fiscalYear <= date("Y"); $fiscalYear++) {
			$url = "RePORTER_PRJ_C_FY".$fiscalYear.".zip";
			$file = self::downloadURL($url, $pid);
			if ($file) {
				$files[$file] = $fiscalYear;
				$lastYear = $fiscalYear;
			}
		}
		for ($fiscalYear = $lastYear + 1; $fiscalYear <= date("Y") + 1; $fiscalYear++) {
			for ($week = 1; $week <= 53; $week++) {
				$weekWithLeading0s = sprintf('%03d', $week);
				$url = "RePORTER_PRJ_C_FY".$fiscalYear."_".$weekWithLeading0s.".zip";
				$file = self::downloadURL($url, $pid);
				if ($file) {
					$files[$file] = $fiscalYear;
				}
			}
		}
		return $files;
	}

	/**
	 * download a file from NIH ExPORTER, unzip, returns absolute filename
	 * returns empty string if $file not found
	 * @param string $file Filename without leading URL
	 */
	private static function downloadURL($file, $pid) {
		$csvfile = preg_replace("/.zip/", ".csv", $file);
		if (!file_exists(APP_PATH_TEMP.$csvfile)) {
			Application::log("Downloading $file...");
	
			$url = "https://exporter.nih.gov/CSVs/final/".$file;
			list($resp, $zip) = REDCapManagement::downloadURL($url, $pid);

			if ($resp == 200) {
				Application::log("Unzipping $file...");
				$fp = fopen(APP_PATH_TEMP.$file, "w");
				fwrite($fp, $zip);
				fclose($fp);
				unset($zip);

				$za = new \ZipArchive;
				if ($za->open(APP_PATH_TEMP.$file) === TRUE) {
					$za->extractTo(APP_PATH_TEMP);
					$za->close();
					unset($za);
					return APP_PATH_TEMP.$csvfile;
				}
			}
			return "";
		} else {
			return APP_PATH_TEMP.$csvfile;
		}
	}

	private $data = [];
	private $pid;
}
