<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

if (!Application::has("mentoring_agreement")) {
    throw new \Exception("The Mentoring Agreement is not set up in this project.");
}

$metadata = Download::metadata($token, $server);
$metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
$choices = DataDictionaryManagement::getChoices($metadata);
$resourceField = DataDictionaryManagement::getMentoringResourceField($metadataFields);

$mssg = "";
if (DataDictionaryManagement::isInitialSetupForResources($choices[$resourceField])) {
    $defaultResourceField = "resources_resource";
    if (isset($choices[$defaultResourceField])) {
        $resourceChoices = $choices[$defaultResourceField];
    } else {
        $resourceChoices = [];
    }
} else {
    $resourceChoices = $choices[$resourceField] ?? [];
}
$savedList = Application::getSetting("mentoring_local_resources", $pid);
$defaultList = implode("\n", array_values($resourceChoices));
if (!$defaultList) {
    $defaultList = $savedList;
}
$rightWidth = 500;
$defaultLink = Application::getSetting("mentee_agreement_link", $pid);

if (isset($_POST['action']) && ($_POST['action'] == "save")) {
    $continue = TRUE;
    if (isset($_POST['linkForResources']) && isset($_POST['linkForIDP'])) {
        $linksToSet = [
            "mentee_agreement_link" => [
                "link" => $_POST['linkForResources'],
                "description" => "Link for Further Resources",
            ],
            "idp_link" => [
                "link" => $_POST['linkForIDP'],
                "description" => "Link for IDP",
            ],
        ];
        foreach ($linksToSet as $settingName => $ary) {
            if (isset($ary['link']) && is_string($ary['link'])) {
                $link = $ary['link'];
            } else {
                $link = "";
            }
            $descript = $ary['description'] ?? "";
            if ($link) {
                if (!preg_match("/^https?:\/\//i", $link)) {
                    $link = "https://".$link;
                }
                $sanitizedLink = Sanitizer::sanitizeURL($link);
                if (REDCapManagement::isValidURL($sanitizedLink)) {
                    Application::saveSetting($settingName, $sanitizedLink, $pid);
                } else {
                    $mssg = "<p class='red centered max-width'>Improper URL $descript</p>";
                }
            } else {
                Application::saveSetting($settingName, "", $pid);
            }
        }
    } else {
        $mssg = "<p class='red centered max-width'>Invalid parameters</p>";
        $continue = FALSE;
    }
    if ($continue) {
        $sanitizedList = Sanitizer::sanitize($_POST['resourceList'] ?? "");
        $resources = preg_split("/[\n\r]+/", $sanitizedList);
        $resourceList = implode("\n", $resources);
        Application::saveSetting("mentoring_resources", $resourceList);

        $reverseResourceChoices = [];
        foreach ($choices[$resourceField] ?? [] as $idx => $label) {
            $reverseResourceChoices[$label] = $idx;
        }

        $newResources = [];
        $existingResources = [];
        foreach ($resources as $resource) {
            if ($resource !== "") {
                if (!isset($reverseResourceChoices[$resource])) {
                    $newResources[] = $resource;
                } else {
                    $existingResources[] = $resource;
                }
            }
        }
        $deletedResourceIndexes = [];
        foreach ($choices[$resourceField] as $idx => $label) {
            if (!in_array($label, $existingResources)) {
                $deletedResourceIndexes[] = $idx;
            }
        }
        if (!empty($newResources) || !empty($deletedResourceIndexes)) {
            $resourceIndexes = array_keys($choices[$resourceField] ?? []);
            if (REDCapManagement::isArrayNumeric($resourceIndexes)) {
                $maxIndex = !empty($resourceIndexes) ? max($resourceIndexes) : 0;
            } else {
                $numericResourceIndexes = [];
                foreach ($resourceIndexes as $idx) {
                    if (is_numeric($idx)) {
                        $numericResourceIndexes[] = $idx;
                    }
                }
                $maxIndex = !empty($numericResourceIndexes) ? max($numericResourceIndexes) : 0;
            }
            $resourcesByIndex = $choices[$resourceField];
            foreach ($deletedResourceIndexes as $idx) {
                unset($resourcesByIndex[$idx]);
            }
            $nextIndex = ((int) $maxIndex) + 1;
            foreach ($newResources as $resource) {
                $resourcesByIndex[$nextIndex] = $resource;
                $nextIndex++;
            }
            if (empty($resourcesByIndex) && Application::isVanderbilt()) {
                $resourcesByIndex = DataDictionaryManagement::getMenteeAgreementVanderbiltResources();
                $resourceStr = DataDictionaryManagement::makeChoiceStr($resourcesByIndex);
            } else if (empty($resourcesByIndex) && $savedList) {
                $savedLabels = preg_split("/[\n\r]+/", $savedList);
                $pairs = [];
                foreach ($savedLabels as $i => $label) {
                    $pairs[] = ($i+1).", $label";
                }
                $resourceStr = implode(" | ", $pairs);
            } else if (empty($resourcesByIndex) && !empty($choices["resources_resource"])) {
                $resourceStr = REDCapManagement::makeChoiceStr($choices['resources_resource']);
            } else if (empty($resourcesByIndex) && !isset($choices['resources_resource'])) {
                $resourceStr = "1, Institutional Resources Here";
            } else {
                $resourceStr = REDCapManagement::makeChoiceStr($resourcesByIndex);
            }
            for ($i = 0; $i < count($metadata); $i++) {
                if ($metadata[$i]['field_name'] == $resourceField) {
                    $metadata[$i]['select_choices_or_calculations'] = $resourceStr;
                }
            }
            $feedback = Upload::metadata($metadata, $token, $server);
            if (!is_array($feedback) || (!$feedback['errors'] && !$feedback['error'])) {
                $mssg = "<p class='max-width centered green'>Changes made.</p>";
            } else {
                if ($feedback['errors']) {
                    $error = implode("<br/>", $feedback['errors']);
                } else if ($feedback['error']) {
                    $error = $feedback['error'];
                } else {
                    $error = implode("<br/>", $feedback);
                }
                $mssg = "<p class='max-width centered red'>Error $error</p>";
            }
            $resourceLabels = array_values($resourcesByIndex);
            for ($i = 0; $i < count($resourceLabels); $i++) {
                $resourceLabels[$i] = (string) $resourceLabels[$i];
            }
            $defaultList = implode("\n", $resourceLabels);
        } else {
            $mssg = "<p class='max-width centered green'>No changes needed.</p>";
        }
    }
}
$vanderbiltLinkText = "";
if (Application::isVanderbilt()) {
    $vumcLink = Application::getDefaultVanderbiltMenteeAgreementLink();
    $vanderbiltLinkText = "<br><a href='$vumcLink'>Default for Vanderbilt Medical Center</a>";
}

echo $mssg;

$stepsLink = Application::link("mentor/dashboard.php");

?>

<p class="centered">Just getting started? <a href="<?= $stepsLink ?>">Follow this checklist</a> to set up.</p>
<h1>Configure Mentee-Mentor Agreements</h1>

<form action="<?= Application::link("this") ?>" method="POST">
    <?= Application::generateCSRFTokenHTML() ?>
    <input type="hidden" name="action" id="action" value="save" />
    <table class="max-width centered padded">
        <tr>
            <td class="alignright bolded"><label for="resourceList">Institutional Resources for Mentoring<br/>(One Per Line Please.)<br/>These will be offered to the mentee.</label></td>
            <td><textarea name="resourceList" id="resourceList" style="width: <?= $rightWidth ?>px; height: 300px;"><?= $defaultList ?></textarea></td>
        </tr>
        <tr>
            <td class="alignright bolded"><label for="linkForResources">Link to Further Resources<?= $vanderbiltLinkText ?></label></td>
            <td><input type="text" style="width: <?= $rightWidth ?>px;" name="linkForResources" id="linkForResources" value="<?= $defaultLink ?>"></td>
        </tr>
        <tr>
            <td class="alignright bolded"><label for="linkForIDP">Link for Further Questions for the Individual Development Plan (IDP) - optional<br/>If you have some program-specific questions, you can turn them into a REDCap Survey in a separate project and add the Public Survey Link here.</label></td>
            <td><input type="text" style="width: <?= $rightWidth ?>px;" name="linkForIDP" id="linkForIDP" value="<?= $defaultLink ?>"></td>
        </tr>
    </table>
    <p class="centered"><button>Change Configuration</button></p>
</form>
