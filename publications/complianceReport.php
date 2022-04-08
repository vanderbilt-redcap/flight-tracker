<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Cohorts;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (isset($_POST['message']) && isset($_POST['to']) && isset($_POST['subject'])) {
    $mssg = REDCapManagement::sanitizeWithoutStrippingHTML($_POST['message'], FALSE);
    $to = REDCapManagement::sanitize($_POST['to']);
    $subject = REDCapManagement::sanitize($_POST['subject']);
    $from = REDCapManagement::sanitize($_POST['from'] ?? $adminEmail);
    $cc = REDCapManagement::sanitize($_POST['cc'] ?? "");

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

require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$thisLink = Application::link("this");
$isTrainingOnly = isset($_GET['trainingFocused']);
if (isset($_GET['record'])) {
    $possibleRecords = Download::recordIds($token, $server);
    $recordId = REDCapManagement::getSanitizedRecord($_GET['record'], $possibleRecords);
    $records = [];
    if ($recordId) {
        $records[] = $recordId;
    } else {
        die("Could not find record.");
    }
    $cohort = "";
} else if (isset($_GET['cohort']) && ($_GET['cohort'] !== "all")) {
    $cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $cohort = "all";
    $records = Download::recordIds($token, $server);
}
$cohorts = new Cohorts($token, $server, Application::getModule());
$cohortSelect = $cohort ? "<p class='centered'>".$cohorts->makeCohortSelect("all", "location.href = '$thisLink&cohort='+$(this).val();")."</p>" : "";
$itemsPerPage = isset($_GET['numPerPage']) ? REDCapManagement::sanitize($_GET['numPerPage']) : 10;
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
$names = Download::names($token, $server);
$metadata = Download::metadata($token, $server);

$recordsForPage = [];
for ($i = ($page - 1) * $itemsPerPage; ($i < $page * $itemsPerPage) && ($i < count($records)); $i++) {
    $recordsForPage[] = $records[$i];
}

$trainingExtraMonths = 18;
$numMonths = 3;
$pmcStartDate = "2008-04-01";
$pmcStartTs = strtotime($pmcStartDate);
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
$agencies = [
    "NIH" => "green",
    "AHRQ" => "green",
    "PCORI" => "green",
    "VA" => "green",
    "DOD" => "green",
    "HHS" => "green",
];
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
$headers = [
    "Scholar Name",
    "PMID<br>PMCID<br>NIHMS",
    "Title &amp; Date",
    "Associated Grants",
    "Contact",
];

$configParams = "";
if ($cohort) {
    $configParams .= "&cohort=".urlencode($cohort);
}
if ($isTrainingOnly) {
    $configParams .= "&trainingFocused";
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

function focusForTrainingOnly(isTrainingFocused) {
    const cohort = '$cohort';
    const cohortParam = cohort ? '&cohort='+encodeURI(cohort) : '';
    const getParam = isTrainingFocused ? '&trainingFocused' : '';
    const thisUrl = '$thisLink';
    location.href = thisUrl + cohortParam + getParam;
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
            if ((json[0] == '{') || (json[0] == '[')) {
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

echo "<h1>Public Access Compliance Update</h1>";
$pubWranglerLink = Application::link("/wrangler/include.php")."&wranglerType=Publications";
$threeMonthsPriorDate = REDCapManagement::addMonths(date("Y-m-d"), (0 - $numMonths));
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
echo "<p class='centered'><input type='checkbox' onchange='focusForTrainingOnly($(\"#trainingFocused\").is(\":checked\"));' id='trainingFocused' name='trainingFocused' $trainingOnlyChecked> <label for='trainingFocused'>Show Publications Only During Training + $trainingExtraMonths Months</label></p>";
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
$threeMonthsPrior = strtotime($threeMonthsPriorDate);
foreach ($recordsForPage as $recordId) {
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
    $nameWithLink = Links::makeRecordHomeLink($pid, $recordId, $names[$recordId]);
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
                $isAllGoForCitation = TRUE;
                $pmidUrl = $citation->getURL();
                $pmcidUrl = $citation->getPMCURL();
                $instance = $citation->getInstance();
                $title = $citation->getVariable("title");
                $pmid = $citation->getPMID();
                $pubDate = $citation->getDate(TRUE);

                $pmcid = $citation->getPMCWithPrefix();
                $isAllGoForCitation = $isAllGoForCitation && ($pmcid != "");
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

                if ($printResults) {
                    $grants = $citation->getGrantBaseAwardNumbers();
                    $grantHTML = [];
                    foreach ($grants as $baseAwardNo) {
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
                        // $isAllGoForCitation = $isAllGoForCitation && ($grantShading != "red");
                    }
                    // $isAllGoForCitation = $isAllGoForCitation && !empty($grantHTML);
                }
                $isAllGoForCitation = $isAllGoForCitation && in_array($pubClass, ["green"]);
                $printRow = (!$isAllGoForCitation || isset($_GET['record']));

                if ($printResults && $printRow) {
                    $id = $recordId."___$instance";
                    echo "<tr>";
                    echo "<th>$nameWithLink</th>";
                    echo "<td>";
                    echo "<span class='nobreak'>$pmidWithLink</span><br>";
                    echo "<span class='nobreak $pmcidClass'>$pmcidWithLink</span><br>";
                    echo ($nihms !== "") ? $nihms : "<span class='nobreak'>No NIHMS</span>";
                    echo "</td>";
                    echo "<td><span class='nobreak $pubClass'>$pubDate</span><br>$titleWithLink</td>";
                    if (empty($grantHTML)) {
                        echo "<td><span class='yellow'>None Cited.</span></td>";
                    } else {
                        echo "<td>" . implode("<br>", $grantHTML) . "</td>";
                    }
                    echo "<td>";
                    echo "<input type='checkbox' id='check_$id' />";
                    echo "<input type='hidden' id='citation_$id' value='" . addslashes($citation->getCitation()) . "' />";
                    $citationDate = $citation->getTimestamp() ? date("Y-m-d", $citation->getTimestamp()) : "";
                    echo "<input type='hidden' id='date_$id' value='$citationDate' />";
                    echo "</td>";
                    if ($isFirst) {
                        echo "<td rowspan='$numProblemRowsForRecord' style='vertical-align: middle;'><button onclick='composeComplianceEmail(\"$recordId\"); return false;'>Compose Email</button></td>";
                        $isFirst = FALSE;
                    }
                    echo "</tr>";
                } else if (!$printRow) {
                    $numCitationsAllGo++;
                } else if (!$printResults) {
                    $numProblemRowsForRecord++;
                } else {
                    throw new \Exception("This should never happen");
                }
            }
        }
    }
    if ($numCitationsAllGo > 0) {
        echo "<tr>";
        echo "<th>$nameWithLink</th>";
        echo "<td class='bolded' colspan='".(count($headers))."'><span class='greentext' style='font-size: 24px;'>&check;</span> $numCitationsAllGo Citations Already Good to Go</td>";
        echo "</tr>";
    }
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