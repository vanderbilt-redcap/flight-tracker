<?php

use \Vanderbilt\CareerDevLibrary\Portal;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Wrangler;

require_once(__DIR__."/../classes/Autoload.php");

$action = Sanitizer::sanitize($_POST['action'] ?? "");
$currPid = Sanitizer::sanitizePid($_POST['pid'] ?? "");
$recordId = Sanitizer::sanitize($_POST['record'] ?? $_POST['record_id'] ?? "");
$allPids = Application::getPids();
if (count($allPids) > 0) {
    Application::keepAlive($allPids[0]);
}

if ($action == "save") {
    try {
        if (!Portal::authenticatePost($currPid, $recordId, $allPids)) {
            $data = ['error' => "Could not authenticate user!"];
        } else {
            $_GET['pid'] = $currPid;
            $currToken = Application::getSetting("token", $currPid);
            $currServer = Application::getSetting("server", $currPid);
            $data = Wrangler::uploadCitations($_POST, $currToken, $currServer, $currPid);
        }
    } catch(\Exception $e) {
        $data = ['error' => $e->getMessage()];
        if (Application::isLocalhost()) {
            $data['error'] .= " ".Sanitizer::sanitizeWithoutChangingQuotes($e->getTraceAsString());
        }
    }
    echo json_encode($data);
    exit;
}

$projectTitle = Sanitizer::sanitizeArray($_POST['projectTitle'] ?? "Unknown Project");
$name = Sanitizer::sanitizeWithoutChangingQuotes($_POST['name'] ?? "");
Application::increaseProcessingMax(1);

$inDevelopment = [
    "resource_map",
];

$data = [];
try {
    $portal = new Portal($currPid, $recordId, $name, $projectTitle, $allPids);
    $coeusDisclaimer = Application::isVanderbilt() ? "This data source includes data from primarily COEUS." : "";
    if ($action == "getMenu") {
        $data['menu'] = $portal->getMenu();
    } else if ($action == "getPids") {
        $data['pids'] = $allPids;
    } else if ($action == "getMatchesFromCache") {
        $data = $portal->getStoredData();
        if (!empty($data['matches'] ?? []) && ($data['photo'] === "")) {
            $data['photo'] = $portal->getPhoto();
        }
    } else if ($action == "pubs_topics") {
        $data['html'] = $portal->getPage("charts/publicationSubjects.php", ["record" => $recordId]);
    } else if ($action == "getMatches") {
        $requestedPids = Sanitizer::sanitizeArray($_POST['pids'] ?? []);
        list($data['matches'], $data['projectTitles'], $data['photo']) = $portal->getMatchesManually($requestedPids);
    } else if (in_array($action, $inDevelopment)) {
        $data['html'] = "<h3>Under Construction</h3>";
    } else if ($action == "resources") {
        $data['html'] = $portal->viewResources();
    } else if ($action == "view") {
        $data['html'] = $portal->viewProfile();
    } else if ($action == "honors") {
        $data['html'] = $portal->getHonorsSurvey();
    } else if ($action == "reopenSurvey") {
        $instrument = Sanitizer::sanitize($_POST['instrument'] ?? "");
        $validInstruments = ["initial_survey"];
        if (in_array($instrument, $validInstruments)) {
            $html = $portal->reopenSurvey($instrument);
            if (preg_match("/error/i", $html)) {
                $data['error'] = $html;
            } else {
                $data['link'] = $html;
            }
        } else {
            $data['error'] = "Invalid instrument.";
        }
    } else if ($action == "survey") {
        $data['html'] = $portal->getFlightTrackerSurveys();
    } else if ($action == "wrangle_pubs") {
        $data['html'] = $portal->getPublicationWrangler();
    } else if ($action == "board") {
        $data['html'] = $portal->getInstitutionBulletinBoard();
    } else if ($action == "submit_post") {
        $text = Sanitizer::sanitize($_POST['text'] ?? "");
        if ($text) {
            $data['html'] = $portal->addPost($text);
        } else {
            $data['error'] = "No text provided.";
        }
    } else if ($action == "delete_post") {
        $datetime = Sanitizer::sanitize($_POST['date'] ?? "");   // datetime, not date
        $postUser = Sanitizer::sanitize($_POST['postuser'] ?? "");
        if ($portal->deletePost($postUser, $datetime)) {
            $data['html'] = $portal->getInstitutionBulletinBoard();
        } else {
            $data['error'] = "<p>Post Not Deleted!</p>";
        }
    } else if ($action == "find_collaborator") {
        $data['html'] = $portal->findCollaboratorPage();
    } else if ($action == "search_projects_for_collaborator") {
        $topics = Sanitizer::sanitizeArray($_POST['topics'] ?? []);
        if (!empty($topics)) {
            $alternativeTopics = Sanitizer::sanitizeArray($_POST['alternativeTopics'] ?? $portal->getAlternativeTopics($topics));
            $requestedPids = Sanitizer::sanitizeArray($_POST['pids'] ?? $allPids);
            $priorNames = Sanitizer::sanitizeArray($_POST['priorNames'] ?? []);
            $field = Sanitizer::sanitize($_POST['field'] ?? "");
            $data['matches'] = $portal->searchForCollaborators($topics, $field, $requestedPids, $priorNames, $alternativeTopics);
            $data['alternativeTopics'] = $alternativeTopics;
        } else {
            $data['error'] = "<p>No topics provided!</p>";
        }
    } else if ($action == "connect") {
        $url = Application::getFlightConnectorURL();
        $data['html'] = "<iframe src='$url' height='1100' width='1300' title='Flight Connector'></iframe>";
    } else if ($action == "grant_funding") {
        $filename = "charts/scholarGrantFunding.php";
        $pageHTML = $portal->getPage($filename, ["record" => $recordId, 'noCDA' => '1']);
        $headerHTML = "<h3>Your Reported Grant Funding (Total Dollars; PI/Co-PI Only) in $projectTitle</h3>";
        $descriptionHTML = "<p class='portalDescription'>This shows the total dollars (direct and indirect) of your grants divided by year. If a grant spans multiple years, it is divided through all years by the proportionate number of days. If you find inaccuracies in your data, please update them under Your Info &rarr; Update. $coeusDisclaimer</p>";
        $data['html'] = $headerHTML . $descriptionHTML . $pageHTML;
    } else if ($action == "photo") {
        $storedData = $portal->getStoredData();
        $data['html'] = $portal->getModifyPhotoPage();
    } else if ($action == "upload_photo") {
        $filename = (string) $_FILES['photoFile']['tmp_name'];
        $mimeType = (string) $_FILES['photoFile']['type'];
        if ($filename && file_exists($filename) && $mimeType) {
            $base64 = $portal->uploadPhoto($filename, $mimeType);
            if ($base64) {
                $data['photo'] = $base64;
            } else if (file_exists($filename)) {
                $data['error'] = "No data saved.";
            } else {
                $data['error'] = "No data uploaded.";
            }
        } else {
            $data['error'] = "No file found.";
        }
    } else if ($action == "stats") {
        if (Application::isServer("redcap.vanderbilt.edu") || Application::isServer("redcap.vumc.org")) {
            $url = "https://redcap.vumc.org/plugins/career_dev/login/figuresBehindREDCap.php";
        } else if (Application::isVanderbilt()) {
            $url = "https://redcap.vumc.org/plugins/career_dev/newmanFigures";
        } else {
            $url = "";
        }
        if ($url) {
            $data['html'] = "<iframe src='$url' height='600' width='900' title=\"Statistics on Vanderbilt's Newman Society\"></iframe>";
        } else {
            $data['html'] = "<h3>No Access</h3>";
        }
    } else if ($action == "pubs_impact") {
        $filename = "publications/scoreDistribution.php";
        $pageHTML = $portal->getPage($filename, ["record" => $recordId]);
        $headerHTML = "<h3>Your Distribution of Publication Impact Factors in $projectTitle</h3>";
        $descriptionHTML = "<p class='portalDescription'>Each Flight Tracker project stores metrics related to your publications. The <a href='https://icite.od.nih.gov/' target='_blank'>Relative Citation Ratio</a> is field-normalized; a value of 1.0 means that your paper is cited as often as the average paper in its field, a value of 2.0 means it's cited twice as often, a value of 0.5 means half as often, and so on. The <a href='https://altmetric.com/' target='_blank'>Altmetric Score</a> looks at newer media such as Twitter to assess a paper's impact.</p>";
        $data['html'] = $headerHTML.$descriptionHTML.$pageHTML;
    } else if ($action == "timelines") {
        $filename = "charts/timeline.php";
        $pageHTML = $portal->getPage($filename, ["record" => $recordId]);
        $headerHTML = "<h3>Your Grant &amp; Publishing Timelines in $projectTitle</h3>";
        $descriptionHTML = "<p class='portalDescription'>Each Flight Tracker project produces two timelines, one for publications and one for grants. If you find inaccuracies in your data, please update them under Your Info &rarr; Update. $coeusDisclaimer</p>";
        $timelineFooterHTML = Application::isVanderbilt() ? "<p class='centered max-width'>Note: This information includes data from COEUS (for the last 5 years) and VERA.</p>" : "";
        $data['html'] = $headerHTML.$descriptionHTML.$pageHTML.$timelineFooterHTML;
    } else if (in_array($action, ["scholar_collaborations", "group_collaborations"])) {
        $filename = "socialNetwork/collaboration.php";
        if ($action == "scholar_collaborations") {
            $params = ["record" => $recordId];
            $descriptionHTML = "<p class='portalDescription'>This graph of Publishing Collaborations shows your co-authorships with other scholars in the project. Each node on the circle stands for a scholar in the Flight Tracker project who has collaborated with you. Each line between scholars stands for one or more papers with that scholar. You can hover over a line or a node to see the exact number of co-authored papers between you and that person.</p>";
            $headerHTML = "<h3>Your Collaborations in $projectTitle</h3>";
        } else {
            $params = ["cohort" => "all"];
            $descriptionHTML = "<p class='portalDescription'>This graph of Publishing Collaborations shows all scholars' papers with other scholars in a project. Each node on the circle stands for a scholar in this Flight Tracker project who has collaborated with another scholar who is also in the project. Each line between scholars stands for one or more papers with that scholar. You can hover over a line or a node to see more detail.</p>";
            $headerHTML = "<h3>Scholar Collaborations in $projectTitle</h3>";
        }
        $pageHTML = $portal->getPage($filename, $params);
        $data['html'] = $headerHTML . $descriptionHTML . $pageHTML;
    } else if ($action == "disassociate") {
        $portal->deleteMatch($currPid, $recordId);
        $data['html'] = "Success";
    } else if ($action == "mentoring") {
        $data['html'] = $portal->makeMentoringPortal();
    } else if ($action == "orcid_profile") {
        $data['html'] = $portal->getORCIDLink();
    } else if (in_array($action, ["addORCID", "removeORCID"])) {
        $orcid = Sanitizer::sanitize($_POST['orcid']);
        if (!$orcid) {
            $data['error'] = "No ORCID specified.";
        } else if ($action == "addORCID") {
            $portal->addORCID($orcid);
        } else {
            $portal->removeORCID($orcid);
        }
    } else {
        $data['error'] = "Illegal action.";
    }
} catch (\Exception $e) {
    $data['error'] = $e->getMessage();
    if (Application::isLocalhost()) {
        $data['error'] .= " ".Sanitizer::sanitizeWithoutChangingQuotes($e->getTraceAsString());
    }
}
echo json_encode($data);
