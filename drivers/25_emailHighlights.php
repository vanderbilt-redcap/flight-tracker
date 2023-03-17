<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

# TODO publicize https://redcap.link/social_media
# TODO weekly vs. monthly (include day # to send on)
# TODO filter email by grant number
define("NUM_DAYS_TO_HIGHLIGHT", 14);
define("NUM_DAYS_WITHOUT_WARNING", 7);

function sendEmailHighlights($token, $server, $pid, $records, $allPids = FALSE) {
    $to = Application::getSetting("email_highlights_to", $pid);
    if (!$to) {
        if (Application::isVanderbilt() && ($pid == NEWMAN_SOCIETY_PROJECT)) {
            $to = "scott.j.pearson@vumc.org,helen.bird@vumc.org,verna.n.wright@vumc.org";
        } else {
            $to = Application::getSetting("admin_email", $pid);
        }
        Application::saveSetting("email_highlights_to", $to, $pid);
    }
    if (Application::isVanderbilt()) {
        # TODO - get rid of when in production
        $to = "scott.j.pearson@vumc.org";
    }
    if (!$to) {
        return;
    }
    $allPossiblePids = Application::getPids();
    $activePids = [];
    foreach ($allPossiblePids as $pidCandidate) {
        if (REDCapManagement::isActiveProject($pidCandidate)) {
            $activePids[] = $pidCandidate;
        }
    }
    if ($allPids) {
        $pids = $activePids;
    } else {
        $pids = [$pid];
    }

    $validGrantStatuses = ["New", "Renewal", "Revision"];

    $oneDay = 24 * 3600;
    $thresholdTs = time() - NUM_DAYS_TO_HIGHLIGHT * $oneDay;
    $warningTs = time() - NUM_DAYS_WITHOUT_WARNING * $oneDay;
    $thresholdDate = date("m-d-Y", $thresholdTs);
    $allPMIDsIdentified = [];
    $pidsCitationRecordsAndInstances = [];
    $pidsGrantRecordsAndInstances = [];
    $fields = ["record_id", "citation_authors", "citation_pmid", "citation_ts", "citation_include", "citation_rcr", "citation_altmetric_score", "coeus_last_update", "nih_last_update", "vera_last_update"];
    $grantInstrumentsAndPrefices = [
        "nih_reporter" => "nih",
        "nsf" => "nsf",
        "coeus" => "coeus",
        "vera" => "vera",
        "custom_grant" => "custom",
    ];
    $names = [];
    $emails = [];
    $allMetadata = [];
    $twitterHandles = [];
    $linkedInHandles = [];
    $translate = Citation::getJournalTranslations();
    foreach ($pids as $currPid) {
        if (REDCapManagement::isActiveProject($currPid)) {
            $currToken = Application::getSetting("token", $currPid);
            $currServer = Application::getSetting("server", $currPid);
            if ($currToken && $currServer) {
                $metadata = Download::metadata($currToken, $currServer);
                $atInstitutionRecords = [];
                $institutionFields = [
                    "record_id",
                    "identifier_institution",
                    "identifier_left_date",
                ];
                $institutionData = Download::fields($currToken, $currServer, $institutionFields);
                foreach ($institutionData as $row) {
                    $recordId = $row['record_id'];
                    $scholar = new Scholar($currToken, $currServer, $metadata, $currPid);
                    $scholar->setRows([$row]);
                    if (!$scholar->hasLeftInstitution()) {
                        $atInstitutionRecords[] = $recordId;
                    }
                }

                $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
                $validFields = DataDictionaryManagement::filterOutInvalidFieldsFromFieldlist($metadataFields, $fields);
                $redcapData = Download::fields($currToken, $currServer, $validFields);
                $names[$currPid] = Download::names($currToken, $currServer);
                $emails[$currPid] = Download::emails($currToken, $currServer);

                foreach ($redcapData as $row) {
                    $recordId = $row['record_id'];
                    if (
                        in_array($recordId, $atInstitutionRecords)
                        && ($row['redcap_repeat_instrument'] == "citation")
                    ) {
                        $instance = $row['redcap_repeat_instance'];
                        $dateOfPublication = $row['citation_ts'] ?? "";
                        $pmid = $row['citation_pmid'];
                        if (
                            ($row['citation_include'] !== "0")
                            && DateManagement::isDate($dateOfPublication)
                            && (strtotime($dateOfPublication) > $thresholdTs)
                            && (
                                NameMatcher::isFirstAuthor($names[$currPid][$recordId], $row['citation_authors'])
                                || NameMatcher::isLastAuthor($names[$currPid][$recordId], $row['citation_authors'])
                            )
                        ) {
                            if (!isset($allPMIDsIdentified[$pmid])) {
                                $allPMIDsIdentified[$pmid] = [];
                            }
                            $allPMIDsIdentified[$pmid][] = $currPid.":".$recordId;
                            enrollNewInstance($pidsCitationRecordsAndInstances, $currPid, $recordId, $instance);
                        }
                    } else if (
                        isset($grantInstrumentsAndPrefices[$row['redcap_repeat_instrument']])
                        && in_array($recordId, $atInstitutionRecords)
                    ) {
                        $instrument = $row['redcap_repeat_instrument'];
                        $prefix = $grantInstrumentsAndPrefices[$instrument];
                        $instance = $row['redcap_repeat_instance'];
                        $lastUpdate = $row[$prefix.'_last_update'];
                        if (
                            $lastUpdate
                            && (strtotime($lastUpdate) > $warningTs)
                        ) {
                            enrollNewInstance($pidsGrantRecordsAndInstances, $currPid, $recordId, $instance, $instrument);
                        }
                    }
                }
            }
        }
    }
    $ftLogoBase64 = FileManagement::getBase64OfFile(__DIR__."/../img/flight_tracker_logo_medium_white_bg.png", "image/png");

    $html = "<style>
.redtext { color: #f0565d; }
h1 { background-color: #8dc63f; }
h2 { background-color: #d4d4eb; }
h3 { background-color: #e5f1d5; }
a { color: #5764ae; }
</style>";
    $html .= "<p><img src='$ftLogoBase64' alt='Flight Tracker for Scholars' /></p>";
    $html .= "<h1>Flight Tracker Impact Update</h1>";

    $statuses = array_merge($validGrantStatuses, ["Unknown"]);
    $appTypes = REDCapManagement::makeConjunction($statuses, "or");
    $html .= "<h2>All Grants Downloaded After $thresholdDate</h2>";
    if (!empty($pidsGrantRecordsAndInstances)) {
        $dataByName = [];
        foreach ($pidsGrantRecordsAndInstances as $currPid => $grantRecordsAndInstancesByInstrument) {
            if (!empty($grantRecordsAndInstancesByInstrument)) {
                $currToken = Application::getSetting("token", $currPid);
                $currServer = Application::getSetting("server", $currPid);
                $currProjectName = Download::projectTitle($currToken, $currServer);
                if (isset($allMetadata[$currPid])) {
                    $currMetadata = $allMetadata[$currPid];
                } else {
                    $currMetadata = Download::metadata($currToken, $currServer);
                    $allMetadata[$currPid] = $currMetadata;
                }
                $lexicalTranslator = new GrantLexicalTranslator($currToken, $currServer, Application::getModule(), $currPid);

                $grantFields = REDCapManagement::getAllGrantFields($currMetadata);
                $allRequestedRecords = [];
                foreach ($grantRecordsAndInstancesByInstrument as $instrument => $recordsAndInstances) {
                    $currRecords = array_keys($recordsAndInstances);
                    $allRequestedRecords = array_unique(array_merge($currRecords, $allRequestedRecords));
                }
                $redcapData = Download::fieldsForRecords($currToken, $currServer, $grantFields, $allRequestedRecords);
                $lastNames = Download::lastnames($currToken, $currServer);
                $firstNames = Download::firstnames($currToken, $currServer);
                $twitterHandles[$currPid] = Download::oneField($currToken, $currServer, "identifier_twitter");
                $linkedInHandles[$currPid] = Download::oneField($currToken, $currServer, "identifier_linkedin");
                foreach ($grantRecordsAndInstancesByInstrument as $instrument => $recordsAndInstances) {
                    foreach ($redcapData as $row) {
                        $recordId = $row['record_id'];
                        if (
                            isset($recordsAndInstances[$recordId])
                            && isset($lastNames[$recordId])
                            && ($lastNames[$recordId] !== "")
                            && in_array($row['redcap_repeat_instance'], $recordsAndInstances[$recordId])
                            && ($row['redcap_repeat_instrument'] == $instrument)
                        ) {
                            $lastName = $lastNames[$recordId];
                            $firstName = $firstNames[$recordId] ?? "";
                            $formattedName = NameMatcher::formatName($firstName, "", $lastName);
                            $nameToList = $lastName.($firstName ? ", ".$firstName : "");
                            $grantFactories = GrantFactory::createFactoriesForRow($row, $formattedName, $lexicalTranslator, $currMetadata, $currToken, $currServer, $redcapData, "Awarded");
                            $currTs = time();
                            foreach ($grantFactories as $gf) {
                                $gf->processRow($row, $redcapData);
                                $grants = $gf->getGrants();
                                foreach ($grants as $grant) {
                                    $awardNo = $grant->getNumber();
                                    $applicationType = Grant::getApplicationType($awardNo);
                                    $budgetDate = $grant->getVariable("start") ?: $grant->getVariable("end") ?: date("Y-m-d", 0);
                                    $projectDate = $grant->getVariable("project_start") ?: $grant->getVariable("project_end") ?: date("Y-m-d", 0);
                                    if (
                                        (
                                            (strtotime($budgetDate) > $currTs)
                                            || (strtotime($projectDate) > $currTs)
                                        )
                                        && in_array($applicationType, array_merge([""], $validGrantStatuses))
                                    ) {
                                        $dataRow = [];
                                        $dataRow['pid'] = $currPid;
                                        $dataRow['projectName'] = $currProjectName;
                                        $dataRow['recordId'] = $recordId;
                                        $dataRow['name'] = $formattedName;
                                        $dataRow['email'] = $emails[$currPid][$recordId] ?? "";
                                        $dataRow['awardNo'] = $awardNo;
                                        $dataRow['type'] = $grant->getVariable("type");
                                        $dataRow['budgetDates'] = DateManagement::YMD2MDY($grant->getVariable("start"))." - ".DateManagement::YMD2MDY($grant->getVariable("end"));
                                        $dataRow['projectDates'] = DateManagement::YMD2MDY($grant->getVariable("project_start"))." - ".DateManagement::YMD2MDY($grant->getVariable("project_end"));
                                        $dataRow['link'] = $grant->getVariable("link");
                                        $dataRow['title'] = $grant->getVariable("title");
                                        $dataRow['role'] = $grant->getVariable("role");
                                        $dataRow['totalBudget'] = REDCapManagement::prettyMoney($grant->getVariable("budget"));
                                        $dataRow['sponsor'] = $grant->getVariable("sponsor");
                                        $dataRow['instrument'] = $instrument;
                                        $dataRow['lastUpdate'] = $grant->getVariable("last_update");
                                        $dataRow['twitter'] = $twitterHandles[$currPid][$recordId] ?? "";
                                        $dataRow['linkedIn'] = $linkedInHandles[$currPid][$recordId] ?? "";
                                        $edocs = Download::nonBlankFileFieldsFromProjects($activePids, $formattedName, "identifier_picture");
                                        if (!empty($edocs)) {
                                            $row['pictures'] = [];
                                            foreach ($edocs as $source => $edocId) {
                                                if (is_numeric($edocId)) {
                                                    $row['pictures'][] = FileManagement::getEdocBase64($edocId);
                                                } else {
                                                    throw new \Exception("Invalid EDoc ID $edocId in project $source");
                                                }
                                            }
                                        }
                                        if (!isset($dataByName[$nameToList])) {
                                            $dataByName[$nameToList] = [];
                                        }
                                        $dataByName[$nameToList][] = $dataRow;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $html .= "<p>Application Types: $appTypes<br/><a href='https://www.era.nih.gov/files/Deciphering_NIH_Application.pdf'>Deciphering NIH Grant Numbers</a></p>";
        $html .= presentGrantDataInHTML($dataByName);
    } else {
        $html .= "<p>No new grants have been downloaded since $thresholdDate for the following Application Types: $appTypes</p>";
    }

    $html .= "<h2>Publications After $thresholdDate</h2>";
    if (!empty($pidsCitationRecordsAndInstances)) {
        foreach ($pidsCitationRecordsAndInstances as $currPid => $recordsAndInstances) {
            if (!empty($recordsAndInstances)) {
                $currToken = Application::getSetting("token", $currPid);
                $currServer = Application::getSetting("server", $currPid);
                $currMetadata = Download::metadata($currToken, $currServer);
                $allMetadata[$currPid] = $currMetadata;
                $citationFields = DataDictionaryManagement::getFieldsFromMetadata($currMetadata, "citation");
                $citationFields[] = "record_id";
                $redcapData = [];
                foreach ($recordsAndInstances as $recordId => $instances) {
                    $recordData = Download::fieldsForRecordAndInstances($currToken, $currServer, $citationFields, $recordId, "citation", $instances);
                    $redcapData = array_merge($redcapData, $recordData);
                }
                foreach ($redcapData as $row) {
                    if (
                        ($row['redcap_repeat_instrument'] == "citation")
                        && (isset($recordsAndInstances[$row['record_id']]))
                        && in_array($row['redcap_repeat_instance'], $recordsAndInstances[$row['record_id']])
                    ) {
                        $pmid = $row['citation_pmid'];
                        $altmetric = $row['citation_altmetric_details_url'] ? " <a href='{$row['citation_altmetric_details_url']}'>Altmetric</a>" : "";
                        $matchedNames = [];
                        $namesWithLink = [];
                        $handles = [];
                        $journalHTML = "<div><i>".$row['citation_journal']."</i>";
                        $journal = trim(strtolower($row['citation_journal']));
                        $journalFullName = $translate[$journal];
                        if ($journalFullName) {
                            $journalHTML .= " - $journalFullName";
                        }
                        if (Application::isVanderbilt() && !Application::isLocalhost()) {
                            $journalPid = 168378;
                            $journalData = \REDCap::getData($journalPid, "json-array");
                            $journalHandles = [];
                            foreach ($journalData as $journalRow) {
                                if (
                                    ($journal == trim(strtolower($journalRow['abbreviation'])))
                                    || ($journal == trim(strtolower($journalRow['name'])))
                                ) {
                                    $journalHandles[] = $journalRow['handle'];
                                }
                            }
                            # TODO prefill journal survey
                            $journalHTML = empty($journalHandles) ? $journalHTML." (<a href='https://redcap.vanderbilt.edu/surveys/?s=D94RMNA3AT94CXTP'>add new journal?</a>)</div>" : $journalHTML." (".implode(", ", $journalHandles).")</div>";
                        } else {
                            $journalHTML .= "</div>";
                        }
                        foreach ($allPMIDsIdentified[$pmid] as $match) {
                            list($matchPID, $matchRecordId) = explode(":", $match);
                            if (!isset($twitterHandles[$matchPID]) || !isset($linkedInHandles[$matchPID])) {
                                $matchToken = Application::getSetting("token", $matchPID);
                                $matchServer = Application::getSetting("server", $matchPID);
                                $twitterHandles[$matchPID] = Download::oneField($matchToken, $matchServer, "identifier_twitter");
                                $linkedInHandles[$matchPID] = Download::oneField($matchToken, $matchServer, "identifier_linkedin");
                            }
                            foreach ([$twitterHandles, $linkedInHandles] as $handleData) {
                                if ($handleData[$matchPID][$matchRecordId]) {
                                    $handles = array_unique(array_merge($handles, preg_split("/\s*,\s*/", $handleData[$matchPID][$matchRecordId])));
                                }
                            }
                            if (
                                isset($names[$matchPID][$matchRecordId])
                                && $names[$matchPID][$matchRecordId]
                                && !in_array($names[$matchPID][$matchRecordId], $matchedNames)
                            ) {
                                $name = $names[$matchPID][$matchRecordId];
                                if (isset($emails[$matchPID][$matchRecordId])) {
                                    $email = $emails[$matchPID][$matchRecordId];
                                    $nameWithLink = "<a href='mailto:$email'>$name</a>";
                                } else {
                                    $nameWithLink = $name;
                                }
                                $matchedNames[] = $name;
                                $namesWithLink[] = $nameWithLink;
                            }
                        }
                        $citation = new Citation($currToken, $currServer, $row['record_id'], $row['redcap_repeat_instance'], $row, $currMetadata);
                        $citationStr = $citation->getCitationWithLink().$altmetric;
                        $handleHTML = empty($handles) ? "" : "<div>Individual Handles: ".implode(", ", $handles)."</div>";

                        $warningHTML = "";
                        if (strtotime($row['citation_ts']) < $warningTs) {
                            $warningHTML = "<div class='redtext'><strong>This citation may have been included on last week's email!</strong></div>";
                        }

                        $pictureHTML = "";
                        foreach ($matchedNames as $matchedName) {
                            $edocs = Download::nonBlankFileFieldsFromProjects($activePids, $matchedName, "identifier_picture");
                            if (!empty($edocs)) {
                                foreach ($edocs as $source => $edocId) {
                                    $base64 = FileManagement::getEdocBase64($edocId);
                                    $pictureHTML .= "<div><img src='$base64' alt='$matchedName' /> $matchedName</div>";
                                }
                            }
                        }
                        # TODO department, rank, institution
                        # TODO filter by grant number(s) (optional) & display grant numbers
                        $html .= "<h3>".implode(", ", $namesWithLink)."</h3>$warningHTML<p>$citationStr</p>$handleHTML$journalHTML$pictureHTML<hr/>";
                    }
                }
            }
        }
    } else {
        $html .= "<p>No new publications have been downloaded that have been published since $thresholdDate</p>";
    }

    $defaultFrom = Application::getSetting("default_from", $pid);
    $subject = "Flight Tracker Social Media Update";
    \REDCap::email($to, $defaultFrom, $subject, $html);
}

function presentGrantDataInHTML($dataByName) {
    $html = "";
    foreach ($dataByName as $personName => $rows) {
        list($first, $last) = NameMatcher::splitName($personName);
        $formattedName = NameMatcher::formatName($first, "", $last);
        $numRows = count($rows);
        $handles = [];
        $email = "";
        foreach ($rows as $row) {
            if (!$email && $row['email']) {
                $email = $row['email'];
            }
            if ($row['twitter'] && !in_array($row['twitter'], $handles)) {
                $handles[] = $row['twitter'];
            }
            if ($row['linkedIn']) {
                $link = "<a href='{$row['linkedIn']}'>LinkedIn</a>";
                if (!in_array($link, $handles)) {
                    $handles[] = $link;
                }
            }
        }

        $formattedNameWithLink = $formattedName;
        if ($email) {
            $formattedNameWithLink = "<a href='mailto:$email'>$formattedName</a>";
        }

        $html .= "<h3>$formattedNameWithLink ($numRows) ".implode(", ", $handles)."</h3>";
        foreach ($rows as $row) {
            $budgetInfo = $row['totalBudget'] ? "<br/>For {$row['totalBudget']}" : "";
            $projectLink = Links::makeRecordHomeLink($row['pid'], $row['recordId'], $row['projectName']." Record ".$row['recordId']);
            $typeInfo = ($row['type'] != "N/A") ? " - ".$row['type'] : "";
            $lastUpdate = DateManagement::YMD2MDY($row['lastUpdate']);
            $pictures = "";
            foreach ($row['pictures'] ?? [] as $base64) {
                $pictures .= "<br/><img src='$base64' />";
            }
            $html .= "<p><strong>{$row['awardNo']} - {$row['role']}$typeInfo</strong><br/>From {$row['sponsor']}$budgetInfo<br/>Budget Period: {$row['budgetDates']}<br/>Project Period: {$row['projectDates']}<br/>Title: {$row['title']}<br/>{$row['link']}<br/>$projectLink<br/>Last Updated: $lastUpdate$pictures</p>";
        }
        $html .= "<hr/>";
    }
    return $html;
}

function enrollNewInstance(&$pidsRecordsAndInstances, $currPid, $recordId, $instance, $instrument = "") {
    if (!isset($pidsRecordsAndInstances[$currPid])) {
        $pidsRecordsAndInstances[$currPid] = [];
    }
    if ($instrument) {
        if (!isset($pidsRecordsAndInstances[$currPid][$instrument])) {
            $pidsRecordsAndInstances[$currPid][$instrument] = [];
        }
        if (!isset($pidsRecordsAndInstances[$currPid][$instrument][$recordId])) {
            $pidsRecordsAndInstances[$currPid][$instrument][$recordId] = [];
        }
        $pidsRecordsAndInstances[$currPid][$instrument][$recordId][] = $instance;
    } else {
        if (!isset($pidsRecordsAndInstances[$currPid][$recordId])) {
            $pidsRecordsAndInstances[$currPid][$recordId] = [];
        }
        $pidsRecordsAndInstances[$currPid][$recordId][] = $instance;
    }
}