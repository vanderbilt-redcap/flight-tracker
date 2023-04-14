<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Wrangler {
    public function __construct($wranglerType, $pid) {
        $this->wranglerType = $wranglerType;
        $this->pid = $pid;
        $this->token = Application::getSetting("token", $this->pid);
        $this->server = Application::getSetting("server", $this->pid);
    }

    public function getEditText($notDoneCount, $includedCount, $recordId, $name, $lastName) {
        $person = "person";
        if ($this->wranglerType == "Publications") {
            $person = "author";
        } else if ($this->wranglerType == "Patents") {
            $person = "inventor";
        }
        $people = $person."s";
        if ($people == "persons") {
            $people = "people";
        }
        $singularWranglerType = ($this->wranglerType == "FlagPublications") ? "publication" : strtolower(substr($this->wranglerType, 0, strlen($this->wranglerType) - 1));
        $pluralWranglerType = $singularWranglerType."s";
        $lcWranglerType = ($this->wranglerType == "FlagPublications") ? "publications" : strtolower($this->wranglerType);
        $institutionFieldValues = Download::oneField($this->token, $this->server, "identifier_institution");
        $myInstitutions = $institutionFieldValues[$recordId] ? preg_split("/\s*[,;]\s*/", $institutionFieldValues[$recordId]) : [];
        $institutions = array_unique(array_merge($myInstitutions, Application::getInstitutions($this->pid), Application::getHelperInstitutions()));

        $html = "";
        if ($this->wranglerType == "FlagPublications") {
            $html .= "<h1>Publication Flagger</h1>\n";
        } else {
            $html .= "<h1>".ucfirst($singularWranglerType)." Wrangler</h1>\n";
        }
        $html .= "<p class='centered'>This page is meant to confirm the association of $lcWranglerType with $people.</p>\n";
        if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
            $html .= "<h2>".$recordId.": ".$name."</h2>";
            $html .= "<p class='centered max-width'><strong>Institutions Searched For</strong>: ".REDCapManagement::makeConjunction($institutions)."</p>";
        }

        if (!NameMatcher::isCommonLastName($lastName) && ($notDoneCount > 0)) {
            $html .= "<p class='centered bolded'>";
            $html .= $lastName." is ".self::makeUncommonDefinition()." last name in the United States.<br>";
            $html .= "You likely can approve these $lcWranglerType without close review.<br>";
            $html .= "<a href='javascript:;' onclick='submitChanges($(\"#nextRecord\").val()); return false;'><span class='green bolded'>Click here to approve all the $lcWranglerType for this record automatically.</span></a>";
            $html .= "</p>";
        }

        $existingLabel = self::getLabel($this->wranglerType, "Existing");
        if ($includedCount == 1) {
            $html .= "<h3 class='newHeader'>$includedCount $existingLabel ".ucfirst($singularWranglerType)." | ";
        } else if ($includedCount == 0) {
            $html .= "<h3 class='newHeader'>No $existingLabel ".ucfirst($pluralWranglerType)." | ";
        } else {
            $html .= "<h3 class='newHeader'>$includedCount $existingLabel ".ucfirst($pluralWranglerType)." | ";
        }

        $newLabel = self::getLabel($this->wranglerType, "New");
        if ($notDoneCount == 1) {
            $html .= "$notDoneCount $newLabel ".ucfirst($singularWranglerType)."</h3>\n";
        } else if ($notDoneCount == 0) {
            $html .= "No $newLabel ".ucfirst($pluralWranglerType)."</h3>\n";
        } else {
            $html .= "$notDoneCount $newLabel ".ucfirst($pluralWranglerType)."</h3>\n";
        }
        return $html;
    }

    public static function getLabel($wranglerType, $suggestedLabel) {
        if (($wranglerType == "FlagPublications") && ($suggestedLabel == "Existing")) {
            return "Flagged";
        } else if (($wranglerType == "FlagPublications") && ($suggestedLabel == "New")) {
            return "Unflagged";
        }
        return $suggestedLabel;
    }

    public static function makeUncommonDefinition() {
        return NameMatcher::makeUncommonDefinition();
    }

    public static function makeLongDefinition() {
        return NameMatcher::makeLongDefinition();
    }

    public function rightColumnText() {
        $prettyWranglerType = ($this->wranglerType == "FlagPublications") ? "Flagged Publications" : $this->wranglerType;
        $html = "<button class='biggerButton green bolded' id='finalize' style='display: none; position: fixed; top: 200px;' onclick='submitChanges($(\"#nextRecord\").val()); return false;'>Finalize $prettyWranglerType</button><br>\n";
        $html .= "<div class='red shadow' style='height: 180px; padding: 5px; vertical-align: middle; position: fixed; top: 250px; text-align: center; display: none;' id='uploading'>\n";
        $html .= "<p>Uploading Changes...</p>\n";
        if ($this->wranglerType == "Publications") {
            $html .= "<p style='font-size: 12px;'>Redownloading citations from PubMed to ensure accuracy. May take up to one minute.</p>\n";
        }
        $html .= "</div>\n";

        # make button show/hide at various pixelations
        $html .= "
<script>
    $(document).ready(function() {
        // timeout to overcome API rate limit; 1.5 seconds seems adeqate; 1.0 seconds fails with immediate click
        setTimeout(function() {
            $('#finalize').show();
        }, 1500)
    });
</script>";
        return $html;
    }

    public static function getImageSize() {
        return 26;
    }

    public static function getImageLocation($img, $pid = "", $wranglerType = "") {
        $validImages = ["unchecked", "checked", "readonly"];
        if (!in_array($img, $validImages)) {
            throw new \Exception("Image ($img) must be in: ".implode(", ", $validImages));
        }
        if ($pid && Publications::areFlagsOn($pid) && ($wranglerType == "FlagPublications")) {
            $imgFile = "wrangler/flagged_".$img.".png";
        } else {
            $imgFile = "wrangler/".$img.".png";
        }
        return Application::link($imgFile, $pid);
    }

    # img is unchecked, checked, or readonly
    public static function makeCheckbox($id, $img, $pid = "", $wranglerType = "") {
        $imgFile = self::getImageLocation($img, $pid,$wranglerType);
        $checkedImg = self::getImageLocation("checked", $pid, $wranglerType);
        $uncheckedImg = self::getImageLocation("unchecked", $pid, $wranglerType);
        $size = self::getImageSize()."px";
        $js = "if ($(this).attr(\"src\").match(/unchecked/)) { $(\"#$id\").val(\"include\"); $(this).attr(\"src\", \"$checkedImg\"); } else { $(\"#$id\").val(\"exclude\"); $(this).attr(\"src\", \"$uncheckedImg\"); }";
        if ($img == "unchecked") {
            $value = "exclude";
        } else if ($img == "checked") {
            $value = "include";
        } else {
            $value = "";
        }
        $input = "<input type='hidden' id='$id' value='$value'>";
        if (($img == "unchecked") || ($img == "checked")) {
            return "<img src='$imgFile' id='image_$id' onclick='$js' style='width: $size; height: $size; float: left;'>".$input;
        }
        if ($img == "readonly") {
            return "<img src='$imgFile' id='image_$id' style='width: $size; height: $size; float: left;'>".$input;
        }
        return "";
    }

    protected $wranglerType;
    protected $pid;
    protected $token;
    protected $server;
}