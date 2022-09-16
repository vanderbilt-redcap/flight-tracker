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
        $singularWranglerType = strtolower(substr($this->wranglerType, 0, strlen($this->wranglerType) - 1));
        $lcWranglerType = strtolower($this->wranglerType);
        $institutionFieldValues = Download::oneField($this->token, $this->server, "identifier_institution");
        $myInstitutions = $institutionFieldValues[$recordId] ? preg_split("/\s*[,;]\s*/", $institutionFieldValues[$recordId]) : [];
        $institutions = array_unique(array_merge($myInstitutions, Application::getInstitutions($this->pid), Application::getHelperInstitutions()));

        $html = "";
        $html .= "<h1>".ucfirst($singularWranglerType)." Wrangler</h1>\n";
        $html .= "<p class='centered'>This page is meant to confirm the association of $lcWranglerType with $people.</p>\n";
        if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
            $html .= "<h2>".$recordId.": ".$name."</h2>";
            $html .= "<p class='centered max-width'><strong>Institutions Searched For</strong>: ".REDCapManagement::makeConjunction($institutions)."</p>";
        }

        if (!NameMatcher::isCommonLastName($lastName) && ($notDoneCount > 0)) {
            $html .= "<p class='centered bolded'>";
            $html .= $lastName." is an ".self::makeUncommonDefinition()." last name in the United States.<br>";
            $html .= "You likely can approve these ".strtolower($this->wranglerType)." without close review.<br>";
            $html .= "<a href='javascript:;' onclick='submitChanges($(\"#nextRecord\").val()); return false;'><span class='green bolded'>Click here to approve all the $lcWranglerType for this record automatically.</span></a>";
            $html .= "</p>";
        }

        if ($includedCount == 1) {
            $html .= "<h3 class='newHeader'>$includedCount Existing ".ucfirst($singularWranglerType)." | ";
        } else if ($includedCount == 0) {
            $html .= "<h3 class='newHeader'>No Existing $this->wranglerType | ";
        } else {
            $html .= "<h3 class='newHeader'>$includedCount Existing $this->wranglerType | ";
        }

        if ($notDoneCount == 1) {
            $html .= "$notDoneCount New ".ucfirst($singularWranglerType)."</h3>\n";
        } else if ($notDoneCount == 0) {
            $html .= "No New $this->wranglerType</h3>\n";
        } else {
            $html .= "$notDoneCount New $this->wranglerType</h3>\n";
        }
        return $html;
    }

    public static function makeUncommonDefinition() {
        return NameMatcher::makeUncommonDefinition();
    }

    public static function makeLongDefinition() {
        return NameMatcher::makeLongDefinition();
    }

    public function rightColumnText() {
        $html = "<button class='sticky biggerButton green' id='finalize' style='display: none; font-weight: bold;' onclick='submitChanges($(\"#nextRecord\").val()); return false;'>Finalize $this->wranglerType</button><br>\n";
        $html .= "<div class='sticky red shadow' style='height: 180px; padding: 5px; vertical-align: middle; text-align: center; display: none;' id='uploading'>\n";
        $html .= "<p>Uploading Changes...</p>\n";
        if ($this->wranglerType == "Publications") {
            $html .= "<p style='font-size: 12px;'>Redownloading citations from PubMed to ensure accuracy. May take up to one minute.</p>\n";
        }
        $html .= "</div>\n";

        # make button show/hide at various pixelations
        $html .= "<script>\n";

        $html .= "\tfunction adjustFinalizeButton() {\n";
        $html .= "\t\tvar mainTable = $('#main').position();\n";
        $html .= "\t\tvar scrollTop = $(window).scrollTop();\n";
        $html .= "\t\tvar finalizeTop = mainTable.top - scrollTop;\n";
        # 100px is fixed position of the sticky class
        $html .= "\t\tvar finalLoc = 100;\n";
        $html .= "\t\tvar spacing = 20;\n";
        $html .= "\t\tvar buttonSize = 40;\n";
        $html .= "\t\tif (finalizeTop > finalLoc) { $('#finalize').css({ top: (finalizeTop+spacing)+'px' }); $('#uploading').css({ top: (finalizeTop+spacing+buttonSize)+'px' }); }\n";
        $html .= "\t\telse { $('#finalize').css({ top: finalLoc+'px' }); $('#uploading').css({ top: (finalLoc+buttonSize)+'px' }); }\n";
        $html .= "\t}\n";

        $html .= "$(document).ready(function() {\n";
        $html .= "\tadjustFinalizeButton();\n";
        # timeout to overcome API rate limit; 1.5 seconds seems adeqate; 1.0 seconds fails with immediate click
        $html .= "\tsetTimeout(function() {\n";
        $html .= "\t\t$('#finalize').show();\n";
        $html .= "\t}, 1500)\n";
        $html .= "\t$(document).scroll(function() { adjustFinalizeButton(); });\n";
        $html .= "});\n";
        $html .= "</script>\n";
        return $html;
    }

    public static function getImageSize() {
        return 26;
    }

    public static function getImageLocation($img) {
        $validImages = ["unchecked", "checked", "readonly"];
        if (!in_array($img, $validImages)) {
            throw new \Exception("Image ($img) must be in: ".implode(", ", $validImages));
        }
        $imgFile = "wrangler/".$img.".png";
        return Application::link($imgFile);
    }

    # img is unchecked, checked, or readonly
    public static function makeCheckbox($id, $img) {
        $imgFile = self::getImageLocation($img);
        $checkedImg = self::getImageLocation("checked");
        $uncheckedImg = self::getImageLocation("unchecked");
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
            return "<img src='$imgFile' id='image_$id' onclick='$js' style='width: $size; height: $size;' align='left'>".$input;
        }
        if ($img == "readonly") {
            return "<img src='$imgFile' id='image_$id' style='width: $size; height: $size;' align='left'>".$input;
        }
        return "";
    }

    protected $wranglerType;
    protected $pid;
    protected $token;
    protected $server;
}