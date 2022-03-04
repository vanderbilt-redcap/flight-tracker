<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

if (!Application::has("mentoring_agreement")) {
    throw new \Exception("The Mentoring Agreement is not set up in this project.");
}

$metadata = Download::metadata($token, $server);
$choices = REDCapManagement::getChoices($metadata);
$resourceField = "mentoring_local_resources";
$resourceField = adjustResourceField($resourceField, $choices);

$mssg = "";
$defaultList = implode("\n", array_values($choices[$resourceField] ?? []));
$rightWidth = 500;
$defaultLink = Application::getSetting("mentee_agreement_link", $pid);

if (isset($_POST['resourceList'])) {
    $continue = TRUE;
    if (isset($_POST['linkToSave'])) {
        $defaultLink = REDCapManagement::sanitize($_POST['linkToSave']);
        if ($defaultLink) {
            if (!preg_match("/^https?:\/\//i", $defaultLink)) {
                $defaultLink = "https://".$defaultLink;
            }
            if (REDCapManagement::isGoodURL($defaultLink)) {
                Application::saveSetting("mentee_agreement_link", $defaultLink, $pid);
            } else {
                $mssg = "<p class='red centered max-width'>Improper URL</p>";
                $continue = FALSE;
            }
        } else {
            Application::saveSetting("mentee_agreement_link", "", $pid);
        }
    } else {
        $mssg = "<p class='red centered max-width'>Invalid parameters</p>";
        $continue = FALSE;
    }
    if ($continue) {
        $resources = preg_split("/[\n\r]+/", $_POST['resourceList']);
        $reverseResourceChoices = [];
        foreach ($choices[$resourceField] as $idx => $label) {
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
            $resourceIndexes = array_keys($choices[$resourceField]);
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
            $nextIndex = $maxIndex + 1;
            foreach ($newResources as $resource) {
                $resourcesByIndex[$nextIndex] = $resource;
                $nextIndex++;
            }
            for ($i = 0; $i < count($metadata); $i++) {
                if ($metadata[$i]['field_name'] == $resourceField) {
                    $metadata[$i]['select_choices_or_calculations'] = REDCapManagement::makeChoiceStr($resourcesByIndex);
                }
            }
            Upload::metadata($metadata, $token, $server);
            $mssg = "<p class='max-width centered green'>Changes made.</p>";
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

?>
<h1>Configure Mentee-Mentor Agreements</h1>

<form action="<?= Application::link("this") ?>" method="POST">
    <table class="max-width centered padded">
        <tr>
            <td class="alignright bolded"><label for="resourceList">Institutional Resources for Mentoring<br>(One Per Line Please.)<br>These will be offered to the mentee.</label></td>
            <td><textarea name="resourceList" id="resourceList" style="width: <?= $rightWidth ?>px; height: 300px;"><?= $defaultList ?></textarea></td>
        </tr>
        <tr>
            <td class="alignright bolded"><label for="linkToSave">Link to Further Resources<?= $vanderbiltLinkText ?></label></td>
            <td><input type="text" style="width: <?= $rightWidth ?>px;" name="linkToSave" id="linkToSave" value="<?= $defaultLink ?>"></td>
        </tr>
    </table>
    <p class="centered"><button>Change Configuration</button></p>
</form>

<?php

function adjustResourceField($resourceField, $choices) {
    $newFieldName = $resourceField."s";
    if (isset($choices[$newFieldName])) {
        return $newFieldName;
    } else {
        return $resourceField;
    }
}
