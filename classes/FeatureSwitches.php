<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class FeatureSwitches {
    public function __construct($token, $server, $pid) {
        $this->token = $token;
        $this->server = $server;
        $this->pid = $pid;
        $this->settingName = "switches";
        $this->switches = [
            "Update Frequency" => [
                "Weekly" => "weekly",
                "Monthly" => "monthly",
                "Quarterly" => "quarterly",
                "Request" => "request",
            ],
            "Mentee-Mentor" => $this->onOff,
            "Patents" => $this->onOff,
            "Publications" => $this->onOff,
            "Grants" => $this->onOff,
        ];
        $this->forms = [
            "Mentee-Mentor" => ["mentoring_agreement"],
            "Patents" => ["patent"],
            "Publications" => ["citation"],
            "Grants" => ["coeus", "custom_grant", "reporter", "exporter", "coeus2", "nih_reporter", "coeus_submission"],
        ];
    }

    public function downloadRecordIdsToBeProcessed() {
        $records = Download::recordIds($this->token, $this->server);
        $settings = $this->getSwitches();
        $freq = $settings['Update Frequency'] ?? "weekly";
        if ($freq == "weekly") {
            $numWeeksInCycle = 1;
            $weekNum = 1;
        } else if ($freq == "monthly") {
            $numWeeksInCycle = 4;
            $weekNum = REDCapManagement::getWeekNumInMonth();
        } else if ($freq == "quarterly") {
            $numWeeksInCycle = 13;
            $weekNum = REDCapManagement::getWeekNumInYear();
        } else if ($freq == "request") {
            return [];
        } else {
            throw new \Exception("Invalid update frequency! $freq");
        }
        $recordsToProcess = [];
        for ($i=0; $i < count($records); $i++) {
            if ($i % $numWeeksInCycle == ($weekNum - 1) % $numWeeksInCycle) {
                $recordsToProcess[] = $records[$i];
            }
        }
        return $recordsToProcess;
    }

    public function getSwitches() {
        $allSwitches = Application::getSetting($this->settingName, $this->pid);
        if (!$allSwitches) {
            $allSwitches = [];
        }
        return $allSwitches;
    }

    public function savePost($post) {
        $changed = FALSE;
        $allSwitches = $this->getSwitches();
        foreach ($this->switches as $title => $switchOptions) {
            $id = REDCapManagement::makeHTMLId($title);
            if (isset($post[$id])) {
                foreach (array_values($switchOptions) as $value) {
                    if ($value === $post[$id]) {
                        $changed = TRUE;
                        if (!isset($allSwitches[$title])) {
                            $allSwitches[$title] = [];
                        }
                        $allSwitches[$title] = $value;
                    }
                }
            }
        }
        if ($changed) {
            $this->saveSwitches($allSwitches);
            return ["message" => "Success."];
        } else {
            return ["error" => "Nothing changed."];
        }
    }

    public function getFormsToExclude($allSwitches = []) {
        if (empty($allSwitches)) {
            $allSwitches = $this->getSwitches();
        }
        $formsToExclude = [];
        foreach ($allSwitches as $title => $newValue) {
            if (isset($this->forms[$title])) {
                if ($newValue == "Off") {
                    $formsToExclude = array_unique(array_merge($formsToExclude, $this->forms[$title]));
                }
            }
        }
        return $formsToExclude;
    }

    public function haveNewSwitchesChanged($newSwitches) {
        $oldSwitches = $this->getSwitches();
        $onOffOptions = array_keys($this->onOff);
        foreach ($newSwitches as $title => $newValue) {
            $isOnOff = in_array($newValue, $onOffOptions);
            # On by defaultdownloadRecordIdsToBeProcessed
            $oldValue = $oldSwitches[$title] ?? ($isOnOff ? "On" : "");
            if ($newValue !== $oldValue) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public function saveSwitches($allSwitches) {
        $formsToExclude = $this->getFormsToExclude($allSwitches);

        if ($this->haveNewSwitchesChanged($allSwitches)) {
            $eventId = Application::getSetting("event_id", $this->pid);
            $grantClass = Application::getSetting("grant_class", $this->pid);
            $deletionRegEx = DataDictionaryManagement::getDeletionRegEx();
            $files = [];
            if (method_exists("\Vanderbilt\CareerDevLibrary\Application", "getMetadataFiles")) {
                $files = Application::getMetadataFiles();
            }
            if (!empty($files)) {
                DataDictionaryManagement::installMetadataFromFiles($files, $this->token, $this->server, $this->pid, $eventId, $grantClass, Application::getRelevantChoices(), $deletionRegEx, $formsToExclude);
            }
        }
        Application::saveSetting($this->settingName, $allSwitches, $this->pid);
    }

    public function makeHTML() {
        return $this->makeAllSwitches();
    }

    public function makeAllSwitches() {
        $allSwitches = $this->getSwitches();
        $thisUrl = Application::link("this", $this->pid);
        $html = "<section id='switch-wrapper'>";
        $html .= "<p class='centered'>Saving a new configuration may reset any custom fields that you have added to your Data Dictionary. It will also stop data collection for affected data. <strong>Please proceed with care when making changes.</strong></p>";
        foreach ($this->switches as $title => $options) {
            $size = "size".count($options);
            if (REDCapManagement::arraysEqual($options, $this->onOff)) {
                $short = "short";
                $width = "50%";
            } else {
                $short = "";
                $width = "100%";
            }
            $titleId = REDCapManagement::makeHTMLId($title);
            $html .= "<div style='float: left; width: $width; padding: 0 20px;'><h4 style='margin-bottom: 0.5rem;'>$title (<span class='valueHolder' data-title='$titleId'></span>)</h4><div class='switch centered $size $short'>".$this->makeSwitchHTML($title, $allSwitches)."<div class='switch__indicator'></div></div></div>";
        }
        $html .= "<p class='centered' style='padding-top: 25px; clear: left;'><button onclick='presentScreen(\"Saving...\"); const postdata = makeSwitchPostData(); $.post(\"$thisUrl\", postdata, function(html) { console.log(html); clearScreen(); });'>Save Settings</button></p>";
        $html .= "<script>
$(document).ready(function() {
    $('.valueHolder').each(function(idx, ob) {
        const title = $(ob).data('title');
        const value = $('[name='+title+']:checked').val();
        $(ob).html(value);
    });
    ";
        foreach (array_keys($this->switches) as $title) {
            $id = REDCapManagement::makeHTMLId($title);
            $html .= "    $('[name=$id]').change(function() { if ($(this).is(':checked')) { updateValueHolder($(this).attr('name'), $(this).attr('id')); } });\n";
        }
        $html .= "
});

function updateValueHolder(titleId, valueId) {
    const value = $('label[for='+valueId+']').html();
    $('.valueHolder').each(function(idx, ob) {
        if ($(ob).data('title') == titleId) {
            $(ob).html(value);
        }
    });
}

function makeSwitchPostData() {
    const hash = {};
";
        foreach (array_keys($this->switches) as $title) {
            $id = REDCapManagement::makeHTMLId($title);
            $html .= "    hash['$id'] = $('[name=$id]:checked').val();\n";
        }
        $html .= "
    console.log('postData: '+JSON.stringify(hash));
    return hash;
}
</script>";
        $html .= "</section>";
        return $html;
    }

    public function makeSwitchHTML($title, $allSwitches) {
        if (!isset($this->switches[$title])) {
            throw new \Exception("Invalid switch $title.");
        }
        $selectedSwitch = $allSwitches[$title] ?? "";
        $checked = "";
        if (!$selectedSwitch) {
            $checked = " checked";       // first one
        }
        $html = "";
        $i = 1;
        foreach ($this->switches[$title] as $label => $value) {
            $id = REDCapManagement::makeHTMLId($title." ".$label);
            $name = REDCapManagement::makeHTMLId($title);
            if ($selectedSwitch === $value) {
                $checked = " checked";
            }
            $html .= "<label for='$id'>$label</label> <input class='switch$i' type='radio' name='$name' id='$id' value='$value' $checked />";
            $checked = "";
            $i++;
        }
        return $html;
    }

    protected $onOff = ["On" => "On", "Off" => "Off",];
    protected $token;
    protected $server;
    protected $pid;
    protected $switches;
    protected $forms;
}
