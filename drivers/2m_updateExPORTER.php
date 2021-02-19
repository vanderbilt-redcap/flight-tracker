<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

define('NOAUTH', true);
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/NameMatcher.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

/**
 * Encode array from latin1 to utf8 recursively
 * @param $dat
 * @return array|string
 */
function convert_from_latin1_to_utf8_recursively($dat)
{
	if (is_string($dat)) {
		return utf8_encode($dat);
	} elseif (is_array($dat)) {
		$ret = [];
		foreach ($dat as $i => $d) $ret[ $i ] = convert_from_latin1_to_utf8_recursively($d);

		return $ret;
	} elseif (is_object($dat)) {
		foreach ($dat as $i => $d) $dat->$i = convert_from_latin1_to_utf8_recursively($d);

		return $dat;
	} else {
		return $dat;
	}
}

/**
  * gets the instance for the exporter form based on existing data
  * if none defined, returns [max + 1 or 1 (if there is no max), $new]
  * $new is boolean; TRUE if new instance; else FALSE
  * @param int $recordId
  * @param array $upload List of records to upload
  */
function getExPORTERInstance($recordId, $redcapData, $upload, $uploadLine) {
	$maxInstance = 0;

	foreach ($redcapData as $row) {
		if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "exporter") && ($maxInstance < $row['redcap_repeat_instance'])) {
			$maxInstance = $row['redcap_repeat_instance'];
			if ($uploadLine['exporter_application_id'] == $row['exporter_application_id']) {
				return array($row['redcap_repeat_instance'], FALSE);
			}
		}
	}
	foreach ($upload as $row) {
		if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "exporter") && ($maxInstance < $row['redcap_repeat_instance'])) {
			$maxInstance = $row['redcap_repeat_instance'];
		}
	}

	return array($maxInstance + 1, TRUE);
}

function downloadURLAndUnzip($file) {
    CareerDev::log("Downloading $file...");

    $url = "https://exporter.nih.gov/CSVs/final/".$file;
    list($resp, $zip) = REDCapManagement::downloadURL($url);

    if ($resp == 200) {
        CareerDev::log("Unzipping $file...");
        $fp = fopen(APP_PATH_TEMP.$file, "w");
        fwrite($fp, $zip);
        fclose($fp);

        $za = new ZipArchive;
        if ($za->open(APP_PATH_TEMP.$file) === TRUE) {
            $za->extractTo(APP_PATH_TEMP);
            if ($za->count() == 1) {
                $csvfile = $za->statIndex(0)['name'];
                CareerDev::log("Getting $csvfile");
            } else if ($za->count() > 1) {
                CareerDev::log("Warning: $file has ".$za->count()." files");
                for ($i = 0; $i < $za->count(); $i++) {
                    $candidateFile = $za->statIndex($i)['name'];
                    if (preg_match("/\.csv$/", $candidateFile)) {
                        $csvfile = $candidateFile;
                        break;
                    }
                }
            } else {
                CareerDev::log("$file has no files!");
            }
            $za->close();
            $newFilename = APP_PATH_TEMP.$csvfile;
            if (file_exists($newFilename)) {
                return $newFilename;
            } else {
                throw new \Exception("Could not find ExPORTER file in $newFilename");
            }
        } else {
            CareerDev::log("Warning: could not open $file");
        }
    } else {
        CareerDev::log("Cannot download $file from $url. Response: $resp");
        CareerDev::log("Downloaded data=".substr($zip, 0, (strlen($zip) < 200 ? strlen($zip) : 200)));
    }
    return "";
}

/**
 * Make an array of REDCap fields from a line read in from the CSV
 * @param $line array read from CSV that corresponds with the order in $headers
 * @param $headers array read from CSV that consist of the headers for each row
 * constraint: count($line) == count($headers)
 */
function makeUploadHoldingQueue($line, $headers, $abstracts, $hasAbstracts) {
	$j = 0;
	$dates = array("exporter_budget_start", "exporter_budget_end", "exporter_project_start", "exporter_project_end");
	$uploadLineHoldingQueue = array();
	foreach ($line as $item) {
		$field = "exporter_".strtolower($headers[$j]);
		if (in_array($field, $dates)) {
			$item = REDCapManagement::MDY2YMD($item);
		}
		$uploadLineHoldingQueue[$field] = convert_from_latin1_to_utf8_recursively($item);
		$j++;
	}
    if ($uploadLineHoldingQueue['exporter_application_id'] && $hasAbstracts) {
        $uploadLineHoldingQueue['exporter_abstract'] = $abstracts[$uploadLineHoldingQueue['exporter_application_id']];
    }
    $uploadLineHoldingQueue['exporter_last_update'] = date("Y-m-d");
	$uploadLineHoldingQueue['exporter_complete'] = '2';
	return $uploadLineHoldingQueue;
}

function updateAbstracts($token, $server, $pid) {
    $records = Download::recordIds($token, $server);
    $files = [];
    $abstractFiles = [];
    $lifespanOfFileInHours = 12;

    # find relevant zips
    # download relevent zips into APP_PATH_TEMP
    # unzip zip files
    for ($year = 1985; $year <= date("Y"); $year++) {
        $url = "RePORTER_PRJ_C_FY".$year.".zip";
        if ($file = searchForFileWithTimestamp($url)) {
            $files[$file] = $year;
        } else {
            $origFile = downloadURLAndUnzip($url);
            if ($origFile) {
                $file = REDCapManagement::copyTempFileToTimestamp($origFile, $lifespanOfFileInHours * 3600);
                $files[$file] = $year;
            }
        }

        $abstractURL = "RePORTER_PRJABS_C_FY".$year.".zip";
        if ($abstractFile = searchForFileWithTimestamp($abstractURL)) {
            $abstractFiles[$file] = $abstractFile;
        } else {
            $origAbstractFile = downloadURLAndUnzip($abstractURL);
            if ($origAbstractFile) {
                $abstractFile = REDCapManagement::copyTempFileToTimestamp($origAbstractFile, $lifespanOfFileInHours * 3600);
                $abstractFiles[$file] = $abstractFile;
            }
        }
   }
    for ($year = date("Y") - 1; $year <= date("Y") + 1; $year++) {
        for ($week = 1; $week <= 53; $week++) {
            $weekWithLeading0s = sprintf('%03d', $week);
            $url = "RePORTER_PRJ_C_FY".$year."_".$weekWithLeading0s.".zip";
            if ($file = searchForFileWithTimestamp($url)) {
                $files[$file] = $year;
            } else {
                $origFile = downloadURLAndUnzip($url);
                if ($origFile) {
                    $file = REDCapManagement::copyTempFileToTimestamp($origFile, $lifespanOfFileInHours * 3600);
                    $files[$file] = $year;
                }
            }

            $abstractURL = "RePORTER_PRJABS_C_FY".$year."_".$weekWithLeading0s.".zip";
            if ($abstractFile = searchForFileWithTimestamp($url)) {
                $abstractFiles[$file] = $abstractFile;
            } else {
                $origAbstractFile = downloadURLAndUnzip($abstractURL);
                if ($origAbstractFile) {
                    $abstractFile = REDCapManagement::copyTempFileToTimestamp($origAbstractFile, $lifespanOfFileInHours * 3600);
                    $abstractFiles[$file] = $abstractFile;
                }
            }
        }
    }

    uploadBlankAbstracts($token, $server, $records, $abstractFiles);
}

function updateExPORTER($token, $server, $pid, $records) {
	$files = [];
	$abstractFiles = [];

	# find relevant zips
	# download relevent zips into APP_PATH_TEMP
	# unzip zip files
    $lifespanOfFileInHours = 12;
    for ($year = 1985; $year <= date("Y"); $year++) {
		$url = "RePORTER_PRJ_C_FY".$year.".zip";
		if ($file = searchForFileWithTimestamp($url)) {
            $files[$file] = $year;
        } else {
            $file = downloadURLAndUnzip($url);
            if ($file) {
                $file = REDCapManagement::copyTempFileToTimestamp($file, $lifespanOfFileInHours * 3600);
                $files[$file] = $year;
            }
        }

		$abstractURL = "RePORTER_PRJABS_C_FY".$year.".zip";
        if ($abstractFile = searchForFileWithTimestamp($abstractURL)) {
            $abstractFiles[$file] = $abstractFile;
        } else {
            $abstractFile = downloadURLAndUnzip($abstractURL);
            if ($abstractFile) {
                $abstractFile = REDCapManagement::copyTempFileToTimestamp($abstractFile, $lifespanOfFileInHours * 3600);
                $abstractFiles[$file] = $abstractFile;
            }
        }
	}
	for ($year = date("Y") - 1; $year <= date("Y") + 1; $year++) {
		for ($week = 1; $week <= 53; $week++) {
			$weekWithLeading0s = sprintf('%03d', $week);
			$url = "RePORTER_PRJ_C_FY".$year."_".$weekWithLeading0s.".zip";
            if ($file = searchForFileWithTimestamp($url)) {
                $files[$file] = $year;
            } else {
                $file = downloadURLAndUnzip($url);
                if ($file) {
                    $file = REDCapManagement::copyTempFileToTimestamp($file, $lifespanOfFileInHours * 3600);
                    $files[$file] = $year;
                }
            }

            $abstractURL = "RePORTER_PRJABS_C_FY".$year."_".$weekWithLeading0s.".zip";
            if ($abstractFile = searchForFileWithTimestamp($abstractURL)) {
                $abstractFiles[$file] = $abstractFile;
            } else {
                $abstractFile = downloadURLAndUnzip($abstractURL);
                if ($abstractFile) {
                    $abstractFile = REDCapManagement::copyTempFileToTimestamp($abstractFile, $lifespanOfFileInHours * 3600);
                    $abstractFiles[$file] = $abstractFile;
                }
            }
		}
	}

	CareerDev::log("Downloading REDCap");
	echo "Downloading REDCap\n";

	$metadata = Download::metadata($token, $server);
	$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
	$hasAbstract = in_array("exporter_abstract", $metadataFields);

	$redcapData = array();
	foreach ($records as $recordId) {
		$redcapData[$recordId] = Download::fieldsForRecords($token, $server, array_unique(array_merge(Application::getCustomFields($metadata), Application::getExporterFields($metadata))), array($recordId));
	}
	echo "Downloaded ".count($redcapData)." records\n";

	# download names and ExPORTER instances from REDCap
	# parse CSVs with screen for institution names
	$institutions = CareerDev::getInstitutions();
	$cities = CareerDev::getCities();
	$institutionsRes = array();
	$citiesRes = array();
	foreach ($institutions as $inst) {
		array_push($institutionsRes, "/".$inst."/i");
	}
    foreach (Application::getHelperInstitutions() as $institution) {
        $regex = "/".$institution."/i";
        if (!in_array($regex, $institutionsRes)) {
            $institutionsRes[] = $regex;
        }
    }
	foreach ($cities as $city) {
		array_push($citiesRes, "/".$city."/i");
	}
	$matchingQueries = array("ORG_NAME" => $institutionsRes, "ORG_CITY" => $citiesRes);
	$upload = array();
	$newUploads = array();		// new records
	foreach ($files as $file => $year) {
	    $abstracts = readAbstracts($abstractFiles[$file]);

		CareerDev::log("Reading $file");
		echo "Reading $file\n";
        $fp = fopen($file, "r");
        $file2 = $file.".new";
        $fp2 = fopen($file2, "w");
        while ($line = fgets($fp)) {
            $line = preg_replace("/(\w)\\\\\",\"/", "$1\\\\\\\\\",\"", $line);
            fwrite($fp2, $line);
        }
        fclose($fp);
        fclose($fp2);

        $fp2 = fopen($file2, "r");
		$i = 0;
		$headers = array();
		while ($line = fgetcsv($fp2)) {
			if ($i === 0) {
				$j = 0;
				foreach ($line as $item) {
					$headers[$j] = $item;
					$j++;
				}
			} else {
				$j = 0;
				$possibleMatch = FALSE;
				$firstNames = array();
				$lastNames = array();
				foreach ($line as $item) {
					foreach ($matchingQueries as $column => $reAry) {
						if ($column == $headers[$j]) {
							foreach ($reAry as $re) {
								if (preg_match($re, $item)) {
									$possibleMatch = TRUE;
								}
							}
							break;
						}
					}
					if ("PI_NAMEs" == $headers[$j]) {
						$names = preg_split("/\s*;\s*/", $item);
						foreach ($names as $name) {
							if ($name && !preg_match("/MOSES, HAROLD L/i", $name)) {
								$name = preg_replace("/\s*\(contact\)/", "", $name);
								if (preg_match("/,/", $name)) {
									list($last, $firstWithMiddle) = preg_split("/\s*,\s*/", $name);
									if (preg_match("/\s+/", $firstWithMiddle)) {
										list($first, $middle) = preg_split("/\s+/", $firstWithMiddle);
									} else {
										$first = $firstWithMiddle;
										$middle = "";
									}
									$firstNames[] = $first;
									$lastNames[] = $last;
								} else {
									$last = "";
									$firstWithMiddle = "";
								}
							}
						}
					}
					$j++;
				}
				if ($possibleMatch) {
					for ($k = 0; $k < count($firstNames); $k++) {
						$recordId = NameMatcher::matchName($firstNames[$k], $lastNames[$k], $token, $server);
						if ($recordId && $firstNames[$k] && $lastNames[$k]) {
							# upload line
							$uploadLine = array("record_id" => $recordId, "redcap_repeat_instrument" => "exporter", "exporter_complete" => '2');
							$uploadLineHoldingQueue = makeUploadHoldingQueue($line, $headers, $abstracts, $hasAbstract);
							list($uploadLine["redcap_repeat_instance"], $isNew) = getExPORTERInstance($recordId, $redcapData[$recordId], $upload, $uploadLineHoldingQueue);
							if ($isNew) {
								$uploadLine = array_merge($uploadLine, $uploadLineHoldingQueue);
								CareerDev::log("Matched name {$recordId} {$firstNames[$k]} {$lastNames[$k]} = {$uploadLine['exporter_pi_names']}");
								echo "Matched name {$recordId} {$firstNames[$k]} {$lastNames[$k]} = {$uploadLine['exporter_pi_names']}\n";
								if ($hasAbstract && $uploadLine['exporter_application_id']) {
								    $uploadLine['exporter_abstract'] = $abstracts[$uploadLine['exporter_application_id']];
                                }
								$upload[] = $uploadLine;
							} else {
							    $currRow = REDCapManagement::getRow($redcapData[$recordId], $recordId, "exporter", $uploadLine['redcap_repeat_instance']);
							    if (!$currRow['exporter_abstract'] && $currRow['export_application_id'] && $hasAbstract) {
							        $upload[] = [
							            "record_id" => $recordId,
                                        "redcap_repeat_instrument" => "exporter",
                                        "redcap_repeat_instance" => $uploadLine["redcap_repeat_instance"],
							            "exporter_abstract" => $abstracts[$uploadLine['exporter_application_id']],
                                    ];
                                }
                            }
						} else if (!$recordId && $firstNames[$k] && $lastNames[$k] && $year >= date("Y")) {
							# new person?
							$j = 0;
							$isK = FALSE;
							$isSupportYear1 = FALSE;
							foreach ($line as $item) {
								if (strtolower($headers[$j]) == "full_project_num") {
									$awardNo = $item;
									if (preg_match("/^\d[Kk]\d\d/", $awardNo) || preg_match("/^[Kk]\d\d/", $awardNo)) {
										$addToNewUploads = TRUE;
									}
								}
								if (strtolower($headers[$j]) == "support_year") {
									if ($item == 1) {
										$isSupportYear1 = TRUE;
									}
								}
								$j++;
							}
							if ($isK && $isSupportYear1) {
								$uploadLineHoldingQueue = makeUploadHoldingQueue($line, $headers, $abstracts, $hasAbstract);
								$newUploads[$firstNames[$k]." ".$lastNames[$k]] = $uploadLineHoldingQueue;
							}
						}
					}
				}
			}
			$i++;
		}
		CareerDev::log("Inspected $i lines");
		echo "Inspected $i lines\n";
		fclose($fp2);
	}

	# add newUploads to uploads
	$maxRecordId = 0;
	foreach ($redcapData as $row) {
		if ($row['record_id'] > $maxRecordId) {
			$maxRecordId = $row['record_id'];
		}
	}
	foreach ($newUploads as $fullName => $uploadLineHoldingQueue) {
		$maxRecordId++;
	
		$uploadLine = array("record_id" => $maxRecordId, "redcap_repeat_instrument" => "exporter");
		list($uploadLine["redcap_repeat_instance"], $isNew) = getExPORTERInstance($recordId, $redcapData, $upload, $uploadLineHoldingQueue);
		$uploadLine = array_merge($uploadLine, $uploadLineHoldingQueue);
	
		CareerDev::log("Found new name {$maxRecordId} {$fullName} --> {$uploadLine['exporter_full_project_num']}");
		echo "Found new name {$maxRecordId} {$fullName} --> {$uploadLine['exporter_full_project_num']}\n";
	
		list($firstName, $lastName) = preg_split("/\s/", $fullName);
		$upload[] = array("record_id" => $maxRecordId, "redcap_repeat_instrument" => "", "redcap_repeat_instance" => "", "identifier_first_name" => ucfirst(strtolower($firstName)), "identifier_last_name" => ucfirst(strtolower($lastName)));
        if ($hasAbstract && $uploadLine['exporter_application_id']) {
            $uploadLine['exporter_abstract'] = $abstracts[$uploadLine['exporter_application_id']];
        }
		$upload[] = $uploadLine;
	}
	
	CareerDev::log(count($upload)." rows");
	echo count($upload)." rows\n";
	if (!empty($upload)) {
		$feedback = Upload::rows($upload, $token, $server);
		CareerDev::log(json_encode($feedback));
		echo json_encode($feedback)."\n";
	}

	if ($hasAbstract) {
        uploadBlankAbstracts($token, $server, $records, $abstractFiles);
    }

	// $mssg = "NIH Exporter run\n\n".count($upload)." rows\n".$output."\n\n";
	// \REDCap::email($adminEmail, "no-reply@vanderbilt.edu", "CareerDev NIH Exporter", $mssg);

    REDCapManagement::deduplicateByKey($token, $server, $pid, $records, "exporter_application_id", "exporter", "exporter");

	CareerDev::saveCurrentDate("Last NIH ExPORTER Download", $pid);
}

function getSuffix($file) {
    $nodes = preg_split("/\./", $file);
    return $nodes[count($nodes) - 1];
}

function searchForFileWithTimestamp($filename) {
    $suffix = getSuffix($filename);
    $fileWithoutSuffix = preg_replace("/\.$suffix$/", "", $filename);
    $fileRegex = "/$fileWithoutSuffix/";
    $dir = APP_PATH_TEMP;
    $files = REDCapManagement::regexInDirectory($fileRegex, $dir);
    $foundFiles = [];
    foreach ($files as $file) {
        $fileSuffix = getSuffix($file);
        if ($suffix == $fileSuffix) {
            $foundFiles[] = $file;
        }
    }
    $oneHour = 3600;
    $minTimestamp = date("YmdHis", time() + $oneHour);
    if (count($foundFiles) == 1) {
        $file = $foundFiles[0];
        $ts = REDCapManagement::getTimestampOfFile($file);
        if ($ts > $minTimestamp) {
            return $dir.$foundFiles[0];
        }
    } else if (count($foundFiles) > 1) {
        $maxTimestamp = 0;
        $maxFile = "";
        foreach ($foundFiles as $file) {
            $ts = REDCapManagement::getTimestampOfFile($file);
            if (($ts >= $maxTimestamp) && ($ts > $minTimestamp)) {
                $maxTimestamp = $ts;
                $maxFile = $file;
            }
        }
        if ($maxFile) {
            return $dir.$maxFile;
        }
    }
    return FALSE;
}

function readAbstracts($file) {
    $data = [];
    if (file_exists($file)) {
        $fp = fopen($file, "r");
        $headers = fgetcsv($fp);
        while ($line = fgetcsv($fp)) {
            $appId = $line[0];
            $abstract = $line[1];
            if ($appId && $abstract) {
                $data[$appId] = $abstract;
            }
        }
        fclose($fp);
    }
    return $data;
}

function uploadBlankAbstracts($token, $server, $records, $abstractFiles) {
    foreach ($records as $recordId) {
        $fields = ["record_id", "exporter_application_id", "exporter_abstract"];
        $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
        $blankApplicationIds = [];
        foreach ($redcapData as $row) {
            if ($row['redcap_repeat_instrument'] == "exporter") {
                if ($row['exporter_abstract'] == "") {
                    $blankApplicationIds[$row['redcap_repeat_instance']] = $row['exporter_application_id'];
                }
            }
        }

        $upload = [];
        foreach ($blankApplicationIds as $instance => $applicationId) {
            $found = FALSE;
            foreach ($abstractFiles as $dataFile => $abstractFile) {
                $fp = fopen($abstractFile, "r");
                while (!$found && ($line = fgetcsv($fp))) {
                    if ($line[1] && ($line[0] == $applicationId)) {
                        $upload[] = [
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => "exporter",
                            "redcap_repeat_instance" => $instance,
                            "exporter_abstract" => $line[1],
                        ];
                        $found = TRUE;
                    }
                }
                fclose($fp);
                if ($found) {
                    break;   // inner
                }
            }
        }
        if (!empty($upload)) {
            Upload::rows($upload, $token, $server);
        }
    }
}
