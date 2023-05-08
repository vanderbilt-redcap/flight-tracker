<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../vendor/autoload.php");

define("MIN_INSTITUTION_LENGTH", 6);

function getIES($token, $server, $pid, $records, $homeInstitutionsOnly = FALSE) {
    $availableInstitutions = getAvailableInstitutions($pid);
    $matchedInstitutions = getMatchingInstitutions($availableInstitutions, $token, $server, $pid, $records, $homeInstitutionsOnly);
    $excelData = downloadExcelData($matchedInstitutions, $pid);
    $upload = matchNamesWithExcel($excelData, $token, $server, $pid);
    if (!empty($upload)) {
        Application::log("Found ".count($upload)." rows of IES matches", $pid);
        Upload::rows($upload, $token, $server);
    }
    Application::saveCurrentDate("Dept. of Ed. Grants", $pid);
}

function matchNamesWithExcel($excelData, $token, $server, $pid) {
    $firstNames = Download::firstnames($token, $server);
    $lastNames = Download::lastnames($token, $server);
    $records = Download::recordIds($token, $server);
    $institutions = Download::institutionsAsArray($token, $server);
    $metadataFields = Download::metadataFields($token, $server);
    $iesFields = ["record_id"];
    $prefix = "ies";
    foreach ($metadataFields as $field) {
        if (preg_match("/^$prefix"."_/", $field)) {
            $iesFields[] = $field;
        }
    }

    # For newer versions of PHPOffice; passed as third argument in IOFactory::load()
    // $formats = [
        // \PhpOffice\PhpSpreadsheet\IOFactory::READER_XLS,
        // \PhpOffice\PhpSpreadsheet\IOFactory::READER_XLSX,
        // \PhpOffice\PhpSpreadsheet\IOFactory::READER_HTML,
    // ];
    $iesREDCapData = [];
    $maxInstance = [];
    $upload = [];
    $projectInstitutions = Application::getInstitutions($pid);
    foreach ($excelData as $fileInstitution => $fileData) {
        $hash = $fileData['hash'];
        $filename = APP_PATH_TEMP."IESData_$hash.xls";
        Application::log("Writing to $filename", $pid);
        $fp = fopen($filename, "w");
        $output = REDCapManagement::clearUnicode($fileData['data']);
        # these spreadsheets' headers are longer than 31 characters,
        # which is the maximum amount allowed by PHPSpreadsheet => hack
        $output = preg_replace("/<title>[^>]+<\/title>/", "<title>IESData</title>", $output);
        $output = preg_replace("/<img [^>]+>/", "", $output);
        fwrite($fp, $output);
        fclose($fp);

        // $inputFileType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filename);
        // $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
        // $reader->setLoadSheetsOnly("iesdata_".$hash);
        // $spreadsheet = $reader->load($filename);
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filename, 0);


        $piCol = "E";
        $institutionCol = "F";
        $highestRow = $spreadsheet->getActiveSheet()->getHighestDataRow();
        $headerRow = 5;
        $startRow = $headerRow + 1;
        for ($rowNum = $startRow; $rowNum < $highestRow; $rowNum++) {
            $pi = $spreadsheet->getActiveSheet()->getCell($piCol.$rowNum)->getValue();
            $piInstitution = $spreadsheet->getActiveSheet()->getCell($institutionCol.$rowNum)->getValue();
            list($piFirst, $piLast) = NameMatcher::splitName($pi, 2);
            foreach ($records as $recordId) {
                $firstName = $firstNames[$recordId] ?? "";
                $lastName = $lastNames[$recordId] ?? "";
                $personInstitutions = array_unique(array_merge($institutions[$recordId] ?? [], $projectInstitutions));
                if (
                    NameMatcher::matchName($firstName, $lastName, $piFirst, $piLast)
                    && (
                        NameMatcher::matchInstitution($fileInstitution, $personInstitutions)
                        || NameMatcher::matchInstitution($piInstitution, $personInstitutions)
                    )
                ) {
                    $iesREDCapData[$recordId] = $iesREDCapData[$recordId] ?? Download::fieldsForRecords($token, $server, $iesFields, [$recordId]);
                    $maxInstance[$recordId] = $maxInstance[$recordId] ?? REDCapManagement::getMaxInstance($iesREDCapData[$recordId], "ies_grant", $recordId);

                    $uploadRow = [
                        "record_id" => $recordId,
                        "redcap_repeat_instrument" => "ies_grant",
                        "redcap_repeat_instance" => $maxInstance[$recordId] + 1,
                        "ies_grant_complete" => "2",
                    ];
                    $originalCount = count($uploadRow);
                    makeREDCapForIES($uploadRow, $spreadsheet->getActiveSheet(), $headerRow, $rowNum, $prefix, $metadataFields);
                    if (
                        ($originalCount < count($uploadRow))
                        && !isIESDuplicate($uploadRow, $recordId, $upload, $iesREDCapData[$recordId])
                    ) {
                        # commit change
                        $maxInstance[$recordId]++;
                        $upload[] = $uploadRow;
                    }
                }
            }
        }
        unlink($filename);
    }
    return $upload;
}

function isIESDuplicate($uploadRow, $recordId, $upload, $recordData) {
    $fieldsForDuplicate = ["ies_id"];
    $sep = "______";
    $rowIdentifiers = [];
    foreach ($fieldsForDuplicate as $field) {
        $rowIdentifiers[] = $uploadRow[$field] ?? "";
    }
    $rowIdentifier = implode($sep, $rowIdentifiers);

    foreach ([$upload, $recordData] as $redcapData) {
        foreach ($redcapData as $row) {
            if (
                ($row['record_id'] == $recordId)
                && ($row['redcap_repeat_instrument'] == "ies_grant")
            ) {
                $identifiers = [];
                foreach ($fieldsForDuplicate as $field) {
                    $identifiers[] = $row[$field] ?? "";
                }
                $identifier = implode($sep, $identifiers);
                if ($rowIdentifier == $identifier) {
                    return TRUE;
                }
            }
        }
    }
    return FALSE;
}

function makeREDCapForIES(&$row, $sheet, $headerRowNum, $dataRowNum, $prefix, $metadataFields) {
    $numCols = 13;
    for ($colNum = 1; $colNum <= $numCols; $colNum++) {
        $field = $prefix."_".strtolower($sheet->getCellByColumnAndRow($colNum, $headerRowNum)->getValue());
        $value = $sheet->getCellByColumnAndRow($colNum, $dataRowNum)->getValue();
        if ($field == "ies_awardamt") {
            $row[$field] = REDCapManagement::removeMoneyFormatting($value);
        } else if (in_array($field, $metadataFields)) {
            $row[$field] = $value;
            $row[$prefix."_last_update"] = date("Y-m-d");
        } else {
            Application::log("Warning! $field is not available.");
        }
    }
    if ($row['ies_awardper']) {
        $term = $row['ies_awardper'];
        if (preg_match("/^\s*(\d+) years\s*$/", $term, $matches)) {
            $duration = (int) $matches[1];
            $year = (int) $row['ies_year'];
            if ($year) {
                $row['ies_start'] = $year."-01-01";
                $row['ies_end'] = ($year+$duration-1)."-12-31";
            }
        } else {
            $separators = ['\s*[–-]\s*', '\sto\s'];
            $yearFormats = ["\d{4}", "\d{2}"];
            foreach ($separators as $sep) {
                foreach ($yearFormats as $yearFormat) {
                    if (preg_match("/(\d+\/\d+\/$yearFormat)$sep(\d+\/\d+\/$yearFormat)/", $term, $matches)) {
                        if (DateManagement::isDate($matches[1]) && DateManagement::isDate($matches[2])) {
                            $row['ies_start'] = DateManagement::MDY2YMD($matches[1]);
                            $row['ies_end'] = DateManagement::MDY2YMD($matches[2]);
                        } else {
                            Application::log("Warning! Improper formatting ".$matches[1]." -or- ".$matches[2]);
                        }
                    }
                }
            }
        }
    }
}

function downloadExcelData($matchedInstitutions, $pid) {
    $excelData = [];
    $totalBytes = 0;
    foreach ($matchedInstitutions as $institutionIndex => $institutionName) {
        $url = "https://ies.ed.gov/funding/grantsearch/index.asp?mode=1&sort=1&order=1&searchvals=&SearchType=or&checktitle=on&checkaffiliation=on&checkprincipal=on&checkquestion=on&checkprogram=on&checkawardnumber=on&slctAffiliation=$institutionIndex&slctPrincipal=0&slctYear=0&slctProgram=0&slctGoal=0&slctCenter=0&FundType=1&FundType=2";
        list($resp, $output) = URLManagement::emulateBrowser($url, [], $pid);
        if (
            URLManagement::isGoodResponse($resp)
            && preg_match("/<form [^>]*name\s*=\s*['\"]ExcelForm['\"][^>]*action=['\"]([^'^\"]+)['\"][^>]*>.+?<\/form>/i", $output, $matches)
        ) {
            $formHTML = $matches[0];
            $directoryAndFile = $matches[1];
            $postURL = "https://ies.ed.gov".$directoryAndFile;
            $post = parseHiddenValues($formHTML);
            sleep(1);
            list($processingResp, $processingHTML) = URLManagement::emulateBrowser($postURL, $post, $pid);
            if (URLManagement::isGoodResponse($processingResp) && isset($post['filename'])) {
                sleep(1);
                $windowURL = $postURL."?download=loading3&filename=".urlencode($post['filename']);
                list($windowResp, $windowHTML) = URLManagement::emulateBrowser($windowURL, [], $pid);
                if (URLManagement::isGoodResponse($windowResp)) {
                    $filename = "/tempfiles/excelcreator/iesdata_".$post['filename'].".xls";
                    if (strpos($windowHTML, $filename) !== FALSE) {
                        $fileURL = "https://ies.ed.gov".$filename;
                        list($xlsResp, $xlsData) = URLManagement::emulateBrowser($fileURL, [], $pid);
                        if (URLManagement::isGoodResponse($xlsResp)) {
                            $excelData[$institutionName] = [
                                "hash" => $post['filename'],
                                "data" => $xlsData,
                            ];
                            $totalBytes += strlen($xlsData);
                        }
                    } else {
                        throw new \Exception("Could not find filename in HTML! ".$windowHTML);
                    }
                } else {
                    throw new \Exception("Invalid Window URL response $windowResp from $windowURL");
                }
            } else if (URLManagement::isGoodResponse($processingResp)) {
                throw new \Exception("Could not find filename in POST data");
            } else {
                throw new \Exception("Invalid URL response $processingResp");
            }
        } else if (URLManagement::isGoodResponse($resp)) {
            if (!preg_match("/There are no grants that meet your required search criteria/i", $output)) {
                throw new \Exception("Could not find form!");
            }
        } else {
            throw new \Exception("Bad response ($resp) to $url");
        }
    }
    Application::log("Returning $totalBytes bytes in ".count($excelData)." files");
    return $excelData;
}

function parseHiddenValues($html) {
    $values = [];
    if (preg_match_all("/<input [^>]*type\s*=\s*[\"']hidden[\"'][^>]*>/i", $html, $tagMatches)) {
        foreach ($tagMatches[0] as $tagHTML) {
            $name = "";
            $value = "";
            if (preg_match("/<input [^>]*name\s*=\s*[\"']([^'^\"]+)[\"'][^>]*>/i", $tagHTML, $nameMatch)) {
                $name = $nameMatch[1];
            }
            if (preg_match("/<input [^>]*value\s*=\s*[\"']([^'^\"]+)[\"'][^>]*>/i", $tagHTML, $valueMatch)) {
                $value = $valueMatch[1];
            }
            if ($name) {
                $values[$name] = $value;
            } else {
                Application::log("No name for tag ".Sanitizer::sanitize($html));
            }
        }
    } else {
        throw new \Exception("HTML has no hidden elements!");
    }
    return $values;
}

function getMatchingInstitutions($availableInstitutions, $token, $server, $pid, $records, $homeInstitutionsOnly = FALSE)
{
    $scholarInstitutionLists = $homeInstitutionsOnly ? [] : Download::oneField($token, $server, "identifier_institution");
    $homeInsitutions = Application::getInstitutions($pid);
    $allInstitutionsLowerCase = [];
    foreach ($homeInsitutions as $inst) {
        $allInstitutionsLowerCase[] = strtolower($inst);
    }
    foreach ($scholarInstitutionLists as $recordId => $institutionList) {
        if (in_array($recordId, $records)) {
            $scholarInstitutions = Scholar::explodeInstitutions($institutionList);
            foreach ($scholarInstitutions as $inst) {
                if (
                    $inst
                    && (strlen($inst) >= MIN_INSTITUTION_LENGTH)
                    && !in_array(strtolower($inst), $allInstitutionsLowerCase)
                ) {
                    $allInstitutionsLowerCase[] = strtolower($inst);
                }
            }
        }
    }

    $matchedInstitutions = [];
    foreach ($availableInstitutions as $index => $availableInstitution) {
        if (NameMatcher::matchInstitution($availableInstitution, $allInstitutionsLowerCase)) {
            $matchedInstitutions[$index] = $availableInstitution;
        }
    }
    return $matchedInstitutions;
}

function getAvailableInstitutions($pid≈) {
    $url = "https://ies.ed.gov/funding/grantsearch/index.asp";
    list($resp, $html) = URLManagement::downloadURLWithPOST($url, [], $pid);

    $availableInstitutions = [];
    if (URLManagement::isGoodResponse($resp)) {
        $selectOfInterestRegEx = "/<select [^>]*name\s*=\s*[\"']slctAffiliation[\"'][^>]*>[\S\s]+?<\/select>/i";
        if (preg_match($selectOfInterestRegEx, $html, $selectMatches)) {
            $selectHTML = $selectMatches[0];
            if (preg_match_all("/<option [^>]*value=['\"](\w+)['\"][^>]*>([^<]+)<\/option>/i", $selectHTML, $optionMatches)) {
                foreach ($optionMatches[1] as $i => $value) {
                    $value = utf8_encode($value);
                    $label = utf8_encode($optionMatches[2][$i]);
                    if ($label != "Search All") {
                        $availableInstitutions[$value] = $label;
                    }
                }
            }
        } else {
            throw new \Exception("Could not find select element from $url!");
        }
    } else {
        throw new \Exception("Invalid response ($resp) to $url");
    }
    return $availableInstitutions;
}