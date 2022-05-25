<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (isset($_POST['message']) && isset($_POST['to']) && isset($_POST['subject'])) {
    $mssg = Sanitizer::sanitizeWithoutStrippingHTML($_POST['message'], FALSE);
    $to = Sanitizer::sanitize($_POST['to']);
    $subject = Sanitizer::sanitize($_POST['subject']);
    $from = Sanitizer::sanitize($_POST['from'] ?? $adminEmail);
    $cc = Sanitizer::sanitize($_POST['cc'] ?? "");

    $returnData = ["error" => "No action taken."];
    if (
        $mssg
        && $to
        && $from
        && REDCapManagement::isEmail($from)
        && REDCapManagement::isEmail($to)
        && (
            !$cc
            || REDCapManagement::isEmail($cc)
        )
    ) {
        if (Application::isLocalhost()) {
            $subject = "$to: ".$subject;
            $to = "scott.j.pearson@vumc.org";
        }
        \REDCap::email($to, $from, $subject, $mssg, $cc);
        $returnData = ["status" => "Successfully sent."];
    } else if (!$mssg) {
        $returnData = ["error" => "No message."];
    } else if (!$adminEmail) {
        $returnData = ["error" => "No from email set up."];
    } else {
        $returnData = ["error" => "Invalid email."];
    }

    echo json_encode($returnData);
    exit;
}

$headers = [
    "Scholar Name",
    "PMID<br>PMCID<br>NIHMS",
    "Title &amp; Date",
    "Associated Grants",
    "Contact",
];

$isTrainingOnly = isset($_GET['trainingFocused']) && ($_GET['trainingFocused'] == "on");
$thisLink = Application::link("this");
if (isset($_GET['record'])) {
    $possibleRecords = Download::recordIds($token, $server);
    $recordId = Sanitizer::getSanitizedRecord($_GET['record'], $possibleRecords);
    $records = [];
    if ($recordId) {
        $records[] = $recordId;
    } else {
        die("Could not find record.");
    }
    $cohort = "";
} else if (isset($_GET['cohort']) && ($_GET['cohort'] !== "all")) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $cohort = "all";
    $records = Download::recordIds($token, $server);
}
$trainingParam = ($isTrainingOnly ? "&trainingFocused=on" : "");
$grantListParam = !empty($grantsSearchedFor) ? "&grantList=".implode(",", $grantsSearchedFor) : "";
$thisLongURL = $thisLink."&cohort=".urlencode($cohort).$trainingParam;
$cohorts = new Cohorts($token, $server, Application::getModule());
$cohortSelect = $cohort ? "<p class='centered'>".$cohorts->makeCohortSelect("all")."</p>" : "";
$names = Download::names($token, $server);
$metadata = Download::metadata($token, $server);

$trainingExtraMonths = 18;
$numMonths = 3;
$threeMonthsPriorDate = REDCapManagement::addMonths(date("Y-m-d"), (0 - $numMonths));
$threeMonthsPrior = strtotime($threeMonthsPriorDate);

$grantsSearchedFor = isset($_GET['grantList']) ? processGrantList(Sanitizer::sanitize($_GET['grantList'])) : [];

if (isset($_GET['csv'])) {
        Application::increaseProcessingMax(8);
        $csvData = [];
        $csvData[] = [
            "Scholar Name",
            "PMID",
            "PMCID",
            "NIHMS",
            "Title",
            "Date",
            "Associated Grants",
        ];
        foreach ($records as $recordId) {
        $rows = processRecord(
            $token,
            $server,
            $pid,
            $event_id,
            $metadata,
            $names,
            $recordId,
            $trainingExtraMonths,
            $isTrainingOnly,
            $threeMonthsPrior,
            $headers,
            $grantsSearchedFor,
            FALSE
        );
        $csvData = array_merge($csvData, $rows);
    }

    $date = date("Y_m_d");
    REDCapManagement::outputAsCSV($csvData, "publicAccessCompliance_$date.csv");
    exit;
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$itemsPerPage = isset($_GET['numPerPage']) ? REDCapManagement::sanitize($_GET['numPerPage']) : 50;
$lastPage = (int) ceil(count($records) / $itemsPerPage);
if (isset($_GET['pageNum'])) {
    $page = REDCapManagement::sanitize($_GET['pageNum']);
    if (count($records) / $itemsPerPage <= $page) {
        $page = 1;
    }
} else {
    $page = 1;
}
if ($page < 1) {
    die("Improper pageNum");
}
$nextPage = ($page + 1 <= $lastPage) ? $page + 1 : FALSE;
$prevPage = ($page - 1 >= 1) ? $page - 1 : FALSE;

$recordsForPage = [];
for ($i = ($page - 1) * $itemsPerPage; ($i < $page * $itemsPerPage) && ($i < count($records)); $i++) {
    $recordsForPage[] = $records[$i];
}

$legend = [
    "green" => [
        "PMCIDs &amp; Dates" => "",
        "Grants" => "Grants are cited by this publication with associated funding sources.",
    ],
    "yellow" => [
        "PMCIDs &amp; Dates" => "Not in compliance but within the $numMonths-month window.",
        "Grants" => "No grants are cited by this publication -or- the grant has an unknown funding source. Therefore, compliance may or may not be an issue with this publication.",
    ],
    "red" => [
        "PMCIDs &amp; Dates" => "Not in compliance and out of the $numMonths-month window.",
        "Grants" => "",
    ],
];

$configParams = "";
if ($cohort) {
    $configParams .= "&cohort=".urlencode($cohort);
}
if ($isTrainingOnly) {
    $configParams .= $trainingParam;
}
$pageMssg = "On Page ".$page." of ".$lastPage;
$prevPageLink = ($prevPage !== FALSE) ? "<a href='$thisLink$configParams&pageNum=$prevPage&numPerPage=$itemsPerPage'>Previous</a>" : "No Previous Page";
$nextPageLink = ($nextPage !== FALSE) ? "<a href='$thisLink$configParams&pageNum=$nextPage&numPerPage=$itemsPerPage'>Next</a>" : "No Next Page";
$spacing = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
if (isset($_GET['record'])) {
    $togglePage = "";
} else {
    $togglePage = "<p class='centered smaller'>".$prevPageLink.$spacing.$pageMssg.$spacing.$nextPageLink."</p>";
}

$programName = Download::projectTitle($token, $server);
$emails = Download::emails($token, $server);
$grants = Download::oneFieldWithInstances($token, $server, "citation_grants");
$includes = Download::oneFieldWithInstances($token, $server, "citation_include");
$grantFrequency = [];
foreach ($grants as $recordId => $instances) {
    foreach ($instances as $instance => $grantStr) {
        if ($includes[$recordId][$instance] == "1") {
            $grantStr = preg_replace("/\s+/", "", $grantStr);
            $awardNos = preg_split("/;/", $grantStr);
            foreach ($awardNos as $awardNo) {
                if ($awardNo) {
                    if (!isset($grantFrequency[$awardNo])) {
                        $grantFrequency[$awardNo] = 0;
                    }
                    $grantFrequency[$awardNo]++;
                }
            }
        }
    }
}
arsort($grantFrequency);
$emailsJSON = json_encode($emails);
$namesJSON = json_encode($names);
$pastDue = "Past Due";
echo "<link href='".Application::link("/css/quill.snow.css")."' rel='stylesheet'>";
echo "<script src=".Application::link("js/jquery.sweet-modal.min.js")."></script>";
echo "<script src=".Application::link("js/quill.js")."></script>";
echo "<script>
const emails = $emailsJSON;
const names = $namesJSON;
let quill = null;
let emailDialog = null;

function makeComplianceUrl(thisUrl) {
    const cohort = '$cohort';
    const cohortParam = cohort ? '&cohort='+encodeURI(cohort) : '';
    const trainingParam = $('#trainingFocused').is(':checked') ? '&trainingFocused=on' : '';
    
    const grantsInTextarea = $('#grantList').val();
    const grantsInAry = grantsInTextarea ? grantsInTextarea.split(/[\\n\\r]+/) : [];
    const trimmedGrants = [];
    for (let i=0; i < grantsInAry.length; i++) {
        trimmedGrants.push(grantsInAry[i].trim());
    }
    const grantParam = (trimmedGrants.length > 0) ? '&grantList='+trimmedGrants.join(',') : '';
    
    return thisUrl + cohortParam + trainingParam + grantParam;
}

function getDefaultEmailText(citations, recordId) {
    const programName = '$programName';
    const nihmsUrl = 'https://nihms.nih.gov/';
    const publicAccessUrl = 'https://publicaccess.nih.gov/';
    const salutation = '<p>Dear '+names[recordId]+',</p>';
    const intro = '<p>As a condition of federal funding for the '+programName+' program, AHRQ requires that all publications supported by the grant must comply with the <a href=\"'+publicAccessUrl+'\">Public Access Policy</a>. We know a publication is compliant when it is associated with a PMCID.</p>';
    const explanation = '<p>It appears that one or more of your publications, listed below, needs you to take steps to comply with the Public Access Policy. Please use the link to the <a href=\"'+nihmsUrl+'\">NIH Manuscript Submission System</a> to initiate or complete the process for publication compliance.</p>';
    const header = (citations.length > 1) ? 'Publications' : 'Publication';
    const citationText = '<h4>'+header+'</h4><p>'+citations.join('</p><p>')+'</p>';
    return salutation+intro+explanation+citationText;
}

function getAdjustedDate(date) {
    let adjustedDate = new Date(date);
    const today = new Date();
    const oneWeekFromToday = new Date(today.getTime() + 7 * 24 * 3600 * 1000);
    if ((date === '$pastDue') || (adjustedDate.getTime() < oneWeekFromToday.getTime())) {
        adjustedDate = oneWeekFromToday;
    }
    return (adjustedDate.getMonth() + 1) + '-' + adjustedDate.getDate() + '-' + adjustedDate.getFullYear();
}

function getDefaultSubjectText() {
    const programName = '$programName';
    return 'Non-compliant Publication for '+programName;
}

function composeComplianceEmail(recordId) {
    const regex = new RegExp('^check_'+recordId+'___');
    const citations = [];
    const dates = [];
    $('input[type=checkbox]').each(function(idx, ob) {
        const id = $(ob).attr('id');
        if (id.match(regex) && $(ob).is(':checked')) {
            const citationId = id.replace(/^check_/, 'citation_');
            const dateId = id.replace(/^check_/, 'date_');
            const citation = $('#'+citationId).val();
            const date = $('#'+dateId).val();
            citations.push(citation);
            dates.push(date);
        }
    });
    if (dates.length > 0) {
        const subject = getDefaultSubjectText();
        const message = getDefaultEmailText(citations, recordId);
        $('#emailRecord').val(recordId);
        $('#emailSubject').val(subject);
        $('#emailCC').val('');
        $('#emailFrom').val('$adminEmail');
        if (!quill) {
            $('#emailMessage').html(message);
            quill = new Quill('#emailMessage', { theme: 'snow' });
        } else {
            $('#emailMessage .ql-editor').html(message);
        }
        $('#emailTo').html(names[recordId]+' &lt;'+emails[recordId]+'&gt;');
        emailDialog.dialog('open');
    } else {
        $.sweetModal({
            content: 'You need to check off some publications to include in your email.',
            icon: $.sweetModal.ICON_ERROR
        });
    }
}

function getEarliestDate(dates) {
    const pastDue = '$pastDue';
    const timestamps = [];
    let i;
    for (i=0; i < dates.length; i++) {
        if (dates[i] !== pastDue) {
            const nodes = dates[i].split(/[\-\/]/);
            if (nodes.length >= 2) {
                const ts = new Date(nodes[0], nodes[1] - 1, nodes[2]).getTime();
                if (ts) {
                    timestamps.push(ts);
                }
            }
        }
    }
    if (timestamps.length > 0) {
        timestamps.sort();
        timestamps.reverse();
        const date = new Date(timestamps[0]);
        return date.getFullYear() + '-' + (date.getMonth() + 1) + '-' + date.getDate();
    }
    return pastDue;
}

function sendComplianceEmail(recordId, message, subject, cc, from) {
    const email = emails[recordId];    

    if (email && message) {
        const postdata = {
            to: email,
            from: from,
            cc: cc ?? '',
            message: message,
            subject: subject,
            'redcap_csrf_token': getCSRFToken(),
        };
        $.post('$thisLink', postdata, function(json) {
            console.log(json);
            if ((json[0] === '{') || (json[0] === '[')) {
                const returnData = JSON.parse(json);
                if (returnData['error']) {
                    $.sweetModal({
                        content: returnData['error'],
                        icon: $.sweetModal.ICON_ERROR
                    });
                } else {
                    $.sweetModal({
                        content: 'One email has been sent.',
                        icon: $.sweetModal.ICON_SUCCESS
                    });
                }
            } else {
                $.sweetModal({
                    content: 'Could not send email!',
                    icon: $.sweetModal.ICON_ERROR
                });
            }
        });
    } else if (!message) {
        $.sweetModal({
            content: 'No message is specified.',
            icon: $.sweetModal.ICON_ERROR
        });
    } else {
        $.sweetModal({
            content: 'You do not have an email set up for this scholar.',
            icon: $.sweetModal.ICON_ERROR
        });
    }
}
</script>";

echo "<div id='emailDialog' title='Compose Email' style='overflow: scroll; display: none;'>";
echo "<input type='hidden' id='emailRecord' value='' />";
echo "<p class='centered'>To: <span id='emailTo'></span></p>";
echo "<p class='centered'><label for='emailFrom'>From:</label> <input type='email' style='width: 400px;' id='emailFrom' /></p>";
echo "<p class='centered'><label for='emailSubject'>Subject:</label> <input type='text' style='width: 400px;' id='emailSubject' /></p>";
echo "<p class='centered'><label for='emailCC'>CC (optional):</label> <input type='email' style='width: 400px;' id='emailCC' /></p>";
echo "<div id='emailMessage'></div>";
echo "<p class='centered'><button onclick='sendComplianceEmail($(\"#emailRecord\").val(), $(\"#emailMessage .ql-editor\").html(), $(\"#emailSubject\").val(), $(\"#emailCC\").val(), $(\"emailFrom\").val()); emailDialog.dialog(\"close\"); return false;'>Send Email</button> <button onclick='emailDialog.dialog(\"close\"); return false;'>Cancel</button></p>";
echo "</div>";

$pmcStartDate = date("Y-m-d", getPMCStartTs());
echo "<h1>Public Access Compliance Update</h1>";
$pubWranglerLink = Application::link("/wrangler/include.php")."&wranglerType=Publications";
echo "<h2>Compliance Threshold: ".REDCapManagement::YMD2MDY($threeMonthsPriorDate)."</h2>";
echo "<p class='centered max-width'>This only affects publications already included in the <a href='$pubWranglerLink'>Publication Wrangler</a>.</p>";
if ($isTrainingOnly) {
    echo "<p class='centered max-width'>If a scholar has a value for Start of Training (and End of Training) on the Identifiers form, then publications only during the training period <strong>plus $trainingExtraMonths months</strong> will be included; if the start of training does not exist, then a start date of ".REDCapManagement::YMD2MDY($pmcStartDate)." (the start date of the NIH Manuscript System) will be used for the record.</p>";
} else {
    echo "<p class='centered max-width'>Publications earlier than ".REDCapManagement::YMD2MDY($pmcStartDate)." will be disregarded since the NIH Manuscript System only started on this date.</p>";
}
echo $cohortSelect;
$trainingOnlyChecked = "";
if ($isTrainingOnly) {
    $trainingOnlyChecked = "checked";
}
$noneCitedChecked = "";
echo "<p class='centered'><input type='checkbox' id='trainingFocused' name='trainingFocused' $trainingOnlyChecked> <label for='trainingFocused'>Show Publications Only During Training + $trainingExtraMonths Months</label></p>";
echo "<p class='centered'><label for='grantList'>Grants to Search for (one per line; leave blank if to search for all):</label><br/><textarea id='grantList' name='grantList' style='height: 150px; width: 200px;'>".implode("\n", $grantsSearchedFor)."</textarea></p>";
echo "<p class='centered'><button onclick='location.href = makeComplianceUrl(\"$thisLink\");'>Reset!</button></p>";
echo "<p class='centered'><a href='javascript:;' onclick='location.href = makeComplianceUrl(\"$thisLink&csv\");'>Download All Records in CSV</a> (This might take some time.)</p>";
echo makeLegendForCompliance($legend);

echo "<h3>Results</h3>";
echo $togglePage;
echo "<table class='centered max-width bordered'>";
echo "<thead>";
echo "<tr>";
foreach ($headers as $header) {
    if ($header == "Contact") {
        echo "<th colspan='2'>$header</th>";
    } else {
        echo "<th>$header</th>";
    }
}
echo "</tr>";
echo "</thead>";
echo "<tbody>";
foreach ($recordsForPage as $recordId) {
    echo processRecord(
        $token,
        $server,
        $pid,
        $event_id,
        $metadata,
        $names,
        $recordId,
        $trainingExtraMonths,
        $isTrainingOnly,
        $threeMonthsPrior,
        $headers,
        $grantsSearchedFor,
        TRUE
    );
}
echo "</tbody></table>";
echo $togglePage;

echo "<script>
$(document).ready(function() {
    emailDialog = $('#emailDialog').dialog({
        autoOpen: false,
        height: 500,
        width: 600,
        modal: true
    });
});

</script>";

function makeLegendForCompliance($legend) {
    $types = [];
    foreach (array_values($legend) as $descriptors) {
        foreach (array_keys($descriptors) as $type) {
            if (!in_array($type, $types)) {
                $types[] = $type;
            }
        }
    }
    $html = "<table class='bordered centered max-width'>";
    $html .= "<thead><tr>";
    $html .= "<th>Color</th>";
    foreach ($types as $type) {
        $html .= "<th>For $type</th>";
    }
    $html .= "</tr></thead>";
    $html .= "<tbody>";
    foreach ($legend as $color => $descriptors) {
        $descriptionTexts = [];
        foreach ($types as $type) {
            $description = $descriptors[$type];
            if ($description) {
                $descriptionTexts[$type] = "<td class='padded'>$description</td>";
            } else {
                $descriptionTexts[$type] = "<td>(Not used.)</td>";
            }
        }
        if (!empty($descriptionTexts)) {
            $html .= "<tr>";
            $html .= "<td class='$color'>&nbsp;&nbsp;&nbsp;&nbsp;</td>";
            foreach ($types as $type) {
                $html .= $descriptionTexts[$type];
            }
            $html .= "</tr>";
        }
    }
    $html .= "</tbody></table>";
    return $html;
}

function processRecord($token, $server, $pid, $event_id, $metadata, $names, $recordId, $trainingExtraMonths, $isTrainingOnly, $threeMonthsPrior, $headers, $grantsSearchedFor, $returnHTML) {
    $fields = [
        "record_id",
        "identifier_start_of_training",
        "summary_training_start",
        "summary_training_end",
        "citation_pmid",
        "citation_pmcid",
        "citation_authors",
        "citation_journal",
        "citation_volume",
        "citation_issue",
        "citation_pages",
        "citation_doi",
        "citation_month",
        "citation_year",
        "citation_day",
        "citation_grants",
        "citation_title",
        "citation_include",
    ];
    $pmcStartTs = getPMCStartTs();
    $agencies = [
        "NIH" => "green",
        "AHRQ" => "green",
        "PCORI" => "green",
        "VA" => "green",
        "DOD" => "green",
        "HHS" => "green",
    ];

    $html = "";
    $ary = [];
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $recordStartDate = REDCapManagement::findField($redcapData, $recordId, "identifier_start_of_training");
    if (!$recordStartDate) {
        $recordStartDate = REDCapManagement::findField($redcapData, $recordId, "summary_training_start");
    }
    $recordEndDate = REDCapManagement::findField($redcapData, $recordId, "summary_training_end");
    $recordStartTs = $recordStartDate ? strtotime($recordStartDate): FALSE;
    $recordEndTs = $recordEndDate ? strtotime($recordEndDate): FALSE;
    $timeframeStartTs = $recordStartTs;
    $timeframeEndTs = $recordEndTs ? strtotime("+$trainingExtraMonths months", $recordEndTs) : FALSE;
    $name = $names[$recordId] ?? "";
    $nameWithLink = Links::makeRecordHomeLink($pid, $recordId, $name);
    $pubs = new Publications($token, $server, $metadata);
    $pubs->setRows($redcapData);
    $pmids = [];
    foreach ($pubs->getCitations() as $citation) {
        if ($pmid = $citation->getPMID()) {
            $pmids[] = $pmid;
        }
    }
    $translator = (!isset($_GET['noNIHMS']) && !empty($pmids)) ? Publications::PMIDsToNIHMS($pmids, $pid) : [];
    $numCitationsAllGo = 0;
    $isFirst = TRUE;
    $numProblemRowsForRecord = 0;
    foreach ([FALSE, TRUE] as $printResults) {
        foreach ($pubs->getCitations() as $citation) {
            $pubTs = $citation->getTimestamp();
            if ($isTrainingOnly && $timeframeStartTs) {
                if ($timeframeEndTs) {
                    $isPubEligible = ($pubTs >= $timeframeStartTs) && ($pubTs <= $timeframeEndTs);
                } else {
                    $isPubEligible = ($pubTs >= $timeframeStartTs);
                }
            } else {
                $isPubEligible = ($pubTs >= $pmcStartTs);
            }

            if ($isPubEligible) {
                $pmidUrl = $citation->getURL();
                $pmcidUrl = $citation->getPMCURL();
                $instance = $citation->getInstance();
                $title = $citation->getVariable("title");
                $pmid = $citation->getPMID();
                $pubDate = $citation->getDate(TRUE);

                $pmcid = $citation->getPMCWithPrefix();
                $isAllGoForCitation = ($pmcid != "");
                if ($printResults) {
                    $pmcidWithLink = $pmcid ? Links::makeLink($pmcidUrl, $pmcid, TRUE) : "No PMCID";
                    $pmidWithLink = Links::makeLink($pmidUrl, "PMID " . $pmid, TRUE);
                    $titleWithLink = Links::makePublicationsLink($pid, $recordId, $event_id, $title, $instance, TRUE);
                    $nihms = $translator[$pmid] ?? "";
                } else {
                    $pmcidWithLink = "";
                    $pmidWithLink = "";
                    $nihms = "";
                    $titleWithLink = "";
                }
                if ($pmcid) {
                    $pubClass = "green";
                } else {
                    $pubClass = ($pubTs < $threeMonthsPrior) ? "red" : "yellow";
                }
                $pmcidClass = $pubClass;

                $hasMatchedGrant = empty($grantsSearchedFor);
                $grantsWithoutHTML = [];
                if ($printResults) {
                    $grants = $citation->getGrantBaseAwardNumbers();
                    $grantHTML = [];
                    foreach ($grants as $baseAwardNo) {
                        $baseAwardNo = strtoupper($baseAwardNo);
                        if (in_array($baseAwardNo, $grantsSearchedFor)) {
                            $hasMatchedGrant = TRUE;
                        }
                        $parseAry = Grant::parseNumber($baseAwardNo);
                        $membership = "Other";
                        $grantShading = "yellow";
                        foreach ($agencies as $agency => $shading) {
                            if ($agency == "HHS") {
                                if (Grant::isHHSGrant($baseAwardNo)) {
                                    $membership = $agency;
                                    $grantShading = $shading;
                                    break;
                                }
                            } else if (Grant::isMember($parseAry['institute_code'], $agency)) {
                                $membership = $agency;
                                $grantShading = $shading;
                                break;
                            }
                        }
                        $grantHTML[] = "<span class='$grantShading nobreak'>$baseAwardNo ($membership)</span>";
                        $grantsWithoutHTML[] = "$baseAwardNo ($membership)";
                        // $isAllGoForCitation = $isAllGoForCitation && ($grantShading != "red");
                    }
                    // $isAllGoForCitation = $isAllGoForCitation && !empty($grantHTML);
                }
                $isAllGoForCitation = $isAllGoForCitation && ($pubClass == "green");

                $showRow = (
                    !$isAllGoForCitation && $hasMatchedGrant
                    || isset($_GET['record'])
                );

                if ($showRow && $printResults) {
                    $row = [];
                    $id = $recordId."___$instance";
                    $html .= "<tr>";
                    $html .= "<th>$nameWithLink</th>";
                    $row[] = $name;
                    $html .= "<td>";
                    $html .= "<span class='nobreak'>$pmidWithLink</span><br>";
                    $html .= "<span class='nobreak $pmcidClass'>$pmcidWithLink</span><br>";
                    $html .= ($nihms !== "") ? $nihms : "<span class='nobreak'>No NIHMS</span>";
                    $html .= "</td>";
                    $row[] = $pmid;
                    $row[] = $pmcid ? $pmcid : "Missing";
                    $row[] = ($nihms !== "") ? $nihms : "No NIHMS";
                    $html .= "<td><span class='nobreak $pubClass'>$pubDate</span><br>$titleWithLink</td>";
                    $row[] = $pubDate;
                    $row[] = $title;
                    if (empty($grantHTML)) {
                        $html .= "<td><span class='yellow'>None Cited.</span></td>";
                        $row[] = "None Cited.";
                    } else {
                        $html .= "<td>" . implode("<br>", $grantHTML) . "</td>";
                        $row[] = implode(", ", $grantsWithoutHTML);
                    }
                    $html .= "<td>";
                    $html .= "<input type='checkbox' id='check_$id' />";
                    $html .= "<input type='hidden' id='citation_$id' value='" . str_replace("'", "", $citation->getCitation()) . "' />";
                    $citationDate = $citation->getTimestamp() ? date("Y-m-d", $citation->getTimestamp()) : "";
                    $html .= "<input type='hidden' id='date_$id' value='$citationDate' />";
                    $html .= "</td>";
                    if ($isFirst) {
                        $html .= "<td rowspan='$numProblemRowsForRecord' style='vertical-align: middle;'><button onclick='composeComplianceEmail(\"$recordId\"); return false;'>Compose Email</button></td>";
                        $isFirst = FALSE;
                    }
                    $html .= "</tr>";

                    $ary[] = $row;
                } else if (!$showRow && $printResults) {
                    $numCitationsAllGo++;
                } else if (!$printResults && $showRow) {
                    $numProblemRowsForRecord++;
                }
            }
        }
    }
    if ($numCitationsAllGo > 0) {
        $html .= "<tr>";
        $html .= "<th>$nameWithLink</th>";
        $html .= "<td class='bolded' colspan='".(count($headers))."'><span class='greentext' style='font-size: 24px;'>&check;</span> $numCitationsAllGo Citations Already Good to Go</td>";
        $html .= "</tr>";
        $ary[] = [
            $name,
            "$numCitationsAllGo Citations Already Good to Go",
        ];
    }

    if ($returnHTML) {
        return $html;
    } else {
        return $ary;
    }
}

function getPMCStartTs() {
    $pmcStartDate = "2008-04-01";
    return strtotime($pmcStartDate);
}

function processGrantList($grantList) {
    if (!$grantList) {
        return [];
    }
    $ary = preg_split("/\s*[,;]\s*/", $grantList);
    return Grant::makeAryOfBaseAwardNumbers($ary);
}