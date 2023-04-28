<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

function sendEmailHighlights($token, $server, $pid, $records, $allPids = FALSE) {
    $to = Application::getSetting("email_highlights_to", $pid);
    $frequency = Application::getSetting("highlights_frequency", $pid);
    $grantList = Application::getSetting("requested_grants", $pid);

    if (Application::isVanderbilt() && ($pid == NEWMAN_SOCIETY_PROJECT)) {
        $to = "scott.j.pearson@vumc.org,helen.bird@vumc.org,verna.n.wright@vumc.org";
        Application::saveSetting("email_highlights_to", $to, $pid);
        $frequency = "weekly";
        Application::saveSetting("highlights_frequency", $frequency, $pid);
    }
    if (!$to) {
        resetSettings($pid);
        return;
    }
    if ($frequency == "weekly") {
        $numDaysToHighlight = 14;
        $numDaysWithoutWarning = 7;
    } else if ($frequency == "monthly") {
        $numDaysToHighlight = 60;
        $numDaysWithoutWarning = 31;
    } else {
        resetSettings($pid);
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
    $thresholdTs = time() - $numDaysToHighlight * $oneDay;
    $warningTs = time() - $numDaysWithoutWarning * $oneDay;
    $thresholdDate = date("m-d-Y", $thresholdTs);
    $allPMIDsIdentified = [];
    $performancePMIDsIdentified = [];
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


    $highPerformers = [];
    foreach ($pids as $currPid) {
        if (REDCapManagement::isActiveProject($currPid)) {
            $allHighPerformingPMIDs = Application::getSetting("high_performing_pmids", $currPid) ?: [];
            $highPerformingPMIDS = [];
            foreach ($allHighPerformingPMIDs as $date => $pmids) {
                $ts = strtotime($date);
                if ($ts >= $thresholdTs) {
                    $highPerformingPMIDS = array_unique(array_merge($highPerformingPMIDS, $pmids));
                }
            }
            resetSettings($currPid);

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
                            $allPMIDsIdentified[$pmid][] = $currPid . ":" . $recordId;
                            enrollNewInstance($pidsCitationRecordsAndInstances, $currPid, $recordId, $instance);
                        }

                        if (
                            ($row['citation_include'] !== "0")
                            && in_array($pmid, $highPerformingPMIDS)
                        ) {
                            if (!isset($highPerformers[$currPid])) {
                                $highPerformers[$currPid] = [];
                            }
                            if (!isset($highPerformers[$currPid][$recordId])) {
                                $highPerformers[$currPid][$recordId] = [];
                            }
                            $highPerformers[$currPid][$recordId][] = $instance;
                            if (!isset($performancePMIDsIdentified[$pmid])) {
                                $performancePMIDsIdentified[$pmid] = [];
                            }
                            $performancePMIDsIdentified[$pmid][] = $currPid . ":" . $recordId;
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
    $projectInfo = Links::makeProjectHomeLink($pid, Download::projectTitle($token, $server));

    $html = "<style>
.redtext { color: #f0565d; }
h1 { background-color: #8dc63f; }
h2 { background-color: #d4d4eb; }
h3 { background-color: #e5f1d5; }
a { color: #5764ae; }
</style>";
    $html .= "<p><img src='$ftLogoBase64' alt='Flight Tracker for Scholars' /></p>";
    $html .= "<h1>Flight Tracker ".ucfirst($frequency)." Celebrations Email</h1>";
    $html .= "<p>$projectInfo</p>";

    $statuses = array_merge($validGrantStatuses, ["Unknown"]);
    $appTypes = REDCapManagement::makeConjunction($statuses, "or");
    $html .= "<h2>New Grant Awards After $thresholdDate</h2>";
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
                $currChoices = DataDictionaryManagement::getChoices($currMetadata);
                $currDepartments = Download::oneField($currToken, $currServer, "summary_primary_dept");
                $currRanks = Download::oneField($currToken, $currServer, "summary_current_rank");
                $currUserids = Download::userids($currToken, $currServer);
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
                                    $awardNo = $grant->getVariable("original_award_number") ?: $grant->getNumber();
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
                                        $dataRow['institution'] = $grant->getVariable("institution");
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
                                        $dataRow['bio'] = makeBio($recordId, $currUserids[$recordId] ?? "", $currDepartments, $currRanks, $alumniAssociations[$recordId] ?? [], $currChoices);
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
                                        if (in_array($dataRow['role'], ["PI", "Co-PI"])) {
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
        }
        $html .= "<p>Application Types: $appTypes<br/><a href='https://www.era.nih.gov/files/Deciphering_NIH_Application.pdf'>Deciphering NIH Grant Numbers</a></p>";
        $html .= presentGrantDataInHTML($dataByName);
    } else {
        $html .= "<p>No new grants have been downloaded since $thresholdDate for the following Application Types: $appTypes</p>";
    }

    $requestedGrants = $grantList ? preg_split("/\s*[,;]\s*/", $grantList) : [];
    for ($i = 0; $i < count($requestedGrants); $i++) {
        $requestedGrants[$i] = Grant::translateToBaseAwardNumber($requestedGrants[$i]);
    }
    $htmlRows = ["<h2>Publications After $thresholdDate</h2>"];
    $performanceRows = ["<h2>Publications With Newly Altmetric &gt; " . Altmetric::THRESHOLD_SCORE . " or RCR &gt; " . iCite::THRESHOLD_SCORE . "</h2>"];
    $publicationPids = array_unique(array_merge(array_keys($highPerformers), array_keys($pidsCitationRecordsAndInstances)));
    if (!empty($publicationPids)) {
        foreach ($publicationPids as $currPid) {
            $recordsAndInstances = $pidsCitationRecordsAndInstances[$currPid] ?? [];
            $highPerformingRecordInstances = $highPerformers[$currPid] ?? [];
            if (!empty($highPerformingRecordInstances) || !empty($recordsAndInstances)) {
                $currToken = Application::getSetting("token", $currPid);
                $currServer = Application::getSetting("server", $currPid);
                if (isset($allMetadata[$currPid])) {
                    $currMetadata = $allMetadata[$currPid];
                } else {
                    $currMetadata = Download::metadata($currToken, $currServer);
                    $allMetadata[$currPid] = $currMetadata;
                }
                $alumniAssociations = Download::alumniAssociations($currToken, $currServer);
                $currChoices = DataDictionaryManagement::getChoices($currMetadata);
                $currDepartments = Download::oneField($currToken, $currServer, "summary_primary_dept");
                $currRanks = Download::oneField($currToken, $currServer, "summary_current_rank");
                $currUserids = Download::userids($currToken, $currServer);
                $citationFields = DataDictionaryManagement::getFieldsFromMetadata($currMetadata, "citation");
                $citationFields[] = "record_id";
                $redcapData = [];
                $bios = [];
                foreach ($recordsAndInstances as $recordId => $instances) {
                    $recordData = Download::fieldsForRecordAndInstances($currToken, $currServer, $citationFields, $recordId, "citation", $instances);
                    $redcapData = array_merge($redcapData, $recordData);
                    $bios[$recordId] = makeBio($recordId, $currUserids[$recordId] ?? "", $currDepartments, $currRanks, $alumniAssociations[$recordId] ?? [], $currChoices);
                }
                $performanceREDCapData = [];
                foreach ($highPerformingRecordInstances as $recordId => $instances) {
                    $recordData = Download::fieldsForRecordAndInstances($currToken, $currServer, $citationFields, $recordId, "citation", $instances);
                    $performanceREDCapData = array_merge($performanceREDCapData, $recordData);
                    if (!isset($bios[$recordId])) {
                        $bios[$recordId] = makeBio($recordId, $currUserids[$recordId] ?? "", $currDepartments, $currRanks, $alumniAssociations[$recordId] ?? [], $currChoices);
                    }
                }
                $performanceRows = array_merge($performanceRows, processCitations($performanceREDCapData, $currToken, $currServer, $currPid, $warningTs, $bios, $requestedGrants, $activePids, $performancePMIDsIdentified));
                $htmlRows = array_merge($htmlRows, processCitations($redcapData, $currToken, $currServer, $currPid, $warningTs, $bios, $requestedGrants, $activePids, $allPMIDsIdentified));
            }
        }
        $caveat = !empty($requestedGrants) ? " associated with your requested grants (".REDCapManagement::makeConjunction($requestedGrants).")" : "";
        if (count($htmlRows) == 1) {
            $htmlRows[] = "<p>No new publications$caveat have been published since $thresholdDate</p>";
        }
        if (count($performanceRows) == 1) {
            $performanceRows[] = "<p>No new publications$caveat have been designated high-performing since $thresholdDate</p>";
        }
        $html .= implode("", $performanceRows);
        $html .= implode("", $htmlRows);
    }

    $defaultFrom = Application::getSetting("default_from", $pid) ?: "noreply.flighttracker@vumc.org";
    $subject = "Flight Tracker Scholar Impact Update";
    \REDCap::email($to, $defaultFrom, $subject, $html);
}

function processCitations($redcapData, $currToken, $currServer, $currPid, $warningTs, $currBios, $requestedGrants, $activePids, $allPMIDsIdentified) {
    $htmlRows = [];
    $translate = Citation::getJournalTranslations();
    foreach ($redcapData as $row) {
        $recordId = $row['record_id'];
        if (
            ($row['redcap_repeat_instrument'] == "citation")
            && isset($recordsAndInstances[$recordId])
            && in_array($row['redcap_repeat_instance'], $recordsAndInstances[$recordId])
        ) {
            $pmid = $row['citation_pmid'];
            $altmetric = $row['citation_altmetric_details_url'] ? " <a href='{$row['citation_altmetric_details_url']}'>Altmetric</a>" : "";
            $matchedNames = [];
            $namesWithLink = [];
            $handles = [];
            $journalHTML = "<div><i>".$row['citation_journal']."</i>";
            $journal = $row['citation_journal'];
            $journalFullName = $translate[$journal] ?? "";
            if ($journalFullName) {
                $journalHTML .= " - $journalFullName";
            }
            if (Application::isVanderbilt() && !Application::isLocalhost()) {
                $journalPid = 168378;
                $journalData = \REDCap::getData($journalPid, "json-array");
                $journalHandles = [];
                $journalInLC = trim(strtolower($journal));
                $journalFullNameInLC = trim(strtolower($journalFullName));
                foreach ($journalData as $journalRow) {
                    if (
                        ($journalInLC == trim(strtolower($journalRow['abbreviation'])))
                        || ($journalInLC == trim(strtolower($journalRow['name'])))
                        || ($journalFullNameInLC == trim(strtolower($journalRow['abbreviation'])))
                        || ($journalFullNameInLC == trim(strtolower($journalRow['name'])))
                    ) {
                        $journalHandles[] = $journalRow['handle'];
                    }
                }
                $journalHTML = empty($journalHandles) ? $journalHTML." (<a href='https://redcap.vanderbilt.edu/surveys/?s=D94RMNA3AT94CXTP'>add new journal handle?</a>)" : $journalHTML." (".implode(", ", $journalHandles).")";
            }
            $journalHTML .= "</div>";
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
                        $nameWithLink = "$name <a href='mailto:$email'>$email</a>";
                    } else {
                        $nameWithLink = $name;
                    }
                    $matchedNames[] = $name;
                    $namesWithLink[] = $nameWithLink;
                }
            }
            $scholarProfile = " ".Links::makeProfileLink($currPid, "Scholar Profile", $recordId);
            $citation = new Citation($currToken, $currServer, $recordId, $row['redcap_repeat_instance'], $row, $currMetadata);
            $citationStr = $citation->getCitationWithLink().$altmetric.$scholarProfile;
            $handleHTML = empty($handles) ? "" : "<div>Individual Handles: ".implode(", ", $handles)."</div>";

            $warningHTML = "";
            if (strtotime($row['citation_ts']) < $warningTs) {
                $warningHTML = "<div class='redtext'><strong>This citation may have been included on the last email!</strong></div>";
            }

            $pictureHTML = "";
            foreach ($matchedNames as $matchedName) {
                $edocs = Download::nonBlankFileFieldsFromProjects($activePids, $matchedName, "identifier_picture");
                if (!empty($edocs)) {
                    foreach ($edocs as $source => $edocId) {
                        $base64 = FileManagement::getEdocBase64($edocId);
                        $pictureHTML .= "<div><img src='$base64' alt='$matchedName' style='max-width: 300px; max-height: 300px; width: auto; height: auto;' /> $matchedName</div>";
                    }
                }
            }
            if ($pictureHTML === "") {
                $pictureHTML = "<div>".Links::makeUploadPictureLink($currPid, "Upload Picture", $recordId)."</div>";
            }

            $bio = $currBios[$recordId] ? $currBios[$recordId]."<br/>" : "";
            $citedGrants = $citation->getGrantBaseAwardNumbers();

            $include = empty($requestedGrants);
            foreach ($requestedGrants as $grant) {
                if (in_array($grant, $citedGrants)) {
                    $include = TRUE;
                }
            }
            if ($include) {
                $htmlRows[] = "<h3>".implode(", ", $namesWithLink)."</h3>$bio$warningHTML<p>$citationStr</p>$handleHTML$journalHTML$pictureHTML<hr/>";
            }
        }
    }
    return $htmlRows;
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
            $formattedNameWithLink = "$formattedName <a href='mailto:$email'>$email</a>";
        }

        $html .= "<h3>$formattedNameWithLink ($numRows) ".implode(", ", $handles)."</h3>";
        if ((count($rows) > 0) && isset($rows[0]['bio']) && $rows[0]['bio']) {
            $html .= "<p>".$rows[0]['bio']."</p>";
        }
        foreach ($rows as $row) {
            $budgetInfo = $row['totalBudget'] ? "<br/>For {$row['totalBudget']} total budget" : "";
            $institution = $row['institution'] ? "<br/>Awarded to {$row['institution']}" : "";
            $projectLink = Links::makeRecordHomeLink($row['pid'], $row['recordId'], $row['projectName']." Record ".$row['recordId']);
            $typeInfo = ($row['type'] != "N/A") ? " - ".$row['type'] : "";
            $lastUpdate = DateManagement::YMD2MDY($row['lastUpdate']);
            $pictures = "";
            foreach ($row['pictures'] ?? [] as $base64) {
                $pictures .= "<br/><img src='$base64' alt='$formattedName' />";
            }
            $html .= "<p><strong>{$row['awardNo']} - {$row['role']}$typeInfo</strong><br/>From {$row['sponsor']}$institution$budgetInfo<br/>Budget Period: {$row['budgetDates']}<br/>Project Period: {$row['projectDates']}<br/>Title: {$row['title']}<br/>{$row['link']}<br/>$projectLink<br/>Last Updated: $lastUpdate$pictures</p>";
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

function prefillJournals() {
    $pid = 168378;
    $redcapData = \REDCap::getData($pid, "json-array");
    $existingHandles = [];
    $maxRecord = 0;
    foreach ($redcapData as $row) {
        if ($row['handle']) {
            $existingHandles[] = $row['handle'];
        }
        if ($maxRecord < $row['record_id']) {
            $maxRecord = $row['record_id'];
        }
    }

    $filename = __DIR__."/../journals-on-twitter/twitter_accounts_of_journals.csv";
    if (file_exists($filename)) {
        $fp = fopen($filename, "r");
        $headers = [];
        $data = [];
        while ($line = fgetcsv($fp)) {
            if (empty($headers)) {
                $headers = $line;
            } else {
                $row = [];
                foreach ($line as $i => $val) {
                    $row[$headers[$i]] = $val;
                }
                $data[] = $row;
            }
        }
        fclose($fp);

        $upload = [];
        $issnsForAbbreviations = Citation::getISSNsForAbbreviations();
        foreach ($data as $row) {
            if (($row['has_twitter'] === "1") && ($row['twitter'] !== "NA")) {
                $handle = $row['twitter'];
                $journalTitle = $row['journal_title'];
                $issnPrint = $row['issn'];
                $issnOnline = $row['e_issn'];
                foreach ([$issnPrint, $issnOnline] as $issn) {
                    if (($issn !== "NA") && isset($issnsForAbbreviations[$issn])) {
                        if (!preg_match("/^@/", $handle)) {
                            $handle = "@$handle";
                        }
                        foreach ($issnsForAbbreviations[$issn] as $abbv) {
                            if (!in_array($handle, $existingHandles) && $journalTitle && $handle) {
                                $maxRecord++;
                                $upload[] = [
                                    "record_id" => $maxRecord,
                                    "name" => $journalTitle,
                                    "abbreviation" => $abbv,
                                    "handle" => $handle,
                                    "journal_twitter_handles_complete" => "2",
                                ];
                                $existingHandles[] = $handle;
                            }
                        }
                    }
                }
            }
        }
        if (!empty($upload)) {
            $params = [
                "project_id" => $pid,
                "dataFormat" => "json-array",
                "data" => $upload,
                "commitData" => TRUE,
            ];
            echo count($upload)." items<br/>";
            return \REDCap::saveData($params);
        }
    }
    return [];
}

function makeBio($recordId, $userid, $currDepartments, $currRanks, $alumniAssocLinks, $currChoices) {
    $bioData = [];
    $foundLDAP = FALSE;
    if (Application::isVanderbilt() && $userid) {
        list($department, $rank) = LDAP::getDepartmentAndRank($userid);
        if ($department && $rank) {
            $bioData[] = "Department: ".$department;
            $bioData[] = "Academic Rank: ".$rank;
            $foundLDAP = TRUE;
        }
    }
    if (!empty($alumniAssocLinks)) {
        $links = [];
        foreach ($alumniAssocLinks as $url) {
            $domain = URLManagement::getDomain($url);
            $links[] = Links::makeLink($url, $domain);
        }
        $bioData[] = "Alumni Associations: ".implode(", ", $links);
    }
    if (!$foundLDAP) {
        $departmentValue = $currDepartments[$recordId] ?? "";
        $department = $currChoices["summary_primary_dept"][$departmentValue] ?? "";
        if ($department) {
            $bioData[] = "Department: ".$department;
        }
        $rankValue = $currRanks[$recordId] ?? "";
        $rank = $currChoices["summary_current_rank"][$rankValue] ?? "";
        if ($rank) {
            $bioData[] = "Academic Rank: ".$rank;
        }
    }
    return implode("; ", $bioData);
}

function resetSettings($pid) {
    Application::saveSetting("high_performing_pmids", [], $pid);
}