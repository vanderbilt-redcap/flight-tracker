<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class FeatureSwitches
{
	public const ON_OFF = ["On" => "On", "Off" => "Off",];

	public function __construct(string $token, string $server, $pid) {
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		$this->settingName = "switches";
		$this->recordListName = "recordSwitches";
		$this->recordSwitches = ["ERIC (Education Publications)" ];     // all on/off
		$this->switchDefaults = [
			"ERIC (Education Publications)" => "Off",
			"Update Bibliometrics Monthly" => "On",
			"Full ORCID Profiles" => "Off",
		];
		$this->switches = [
			"Update Frequency" => [
				"Weekly" => "weekly",
				"Monthly" => "monthly",
				"Quarterly" => "quarterly",
				"Request" => "request",
			],
			"Days per Week to Build Summaries" => [
				"1 Day" => 1,
				"3 Days" => 3,
				"5 Days" => 5,
			],
			"Mentee-Mentor" => self::ON_OFF,
			"Patents" => self::ON_OFF,
			"Publications" => self::ON_OFF,
			"Grants" => self::ON_OFF,
			"ERIC (Education Publications)" => self::ON_OFF,
			"Update Bibliometrics Monthly" => self::ON_OFF,
			"Full ORCID Profiles" => self::ON_OFF,
		];
		$this->forms = [
			"Mentee-Mentor" => ["mentoring_agreement"],
			"Patents" => ["patent"],
			"Publications" => ["citation"],
			"ERIC (Education Publications)" => ["eric"],
			"Grants" => [
				"coeus",
				"custom_grant",
				"reporter",
				"exporter",
				"coeus2",
				"nih_reporter",
				"coeus_submission",
				"vera",
				"vera_submission",
				"ies_grant",
				"nsf",
			],
			"Full ORCID Profiles" => [
				# filled in below
			],
		];
		foreach (ORCID::ORCID_ENDPOINTS as $endpoint) {
			$this->forms["Full ORCID Profiles"][] = ORCID::PROFILE_PREFIX.ORCID::encodeKey($endpoint);
		}
	}

	public function getRecordsTurnedOn(string $setting): array {
		$settings = Application::getSetting($this->recordListName, $this->pid) ?: [];
		if (isset($settings[$setting])) {
			return $settings[$setting];
		}
		return [];
	}

	public function addRecordForSetting(string $setting, string $recordId): void {
		$settings = Application::getSetting($this->recordListName, $this->pid) ?: [];
		if (isset($settings[$setting])) {
			if (!in_array($recordId, $settings[$setting])) {
				$settings[$setting][] = $recordId;
			}
		} else {
			$settings[$setting] = [$recordId];
		}
		Application::saveSetting($this->recordListName, $settings, $this->pid);
	}

	public function removeRecordForSetting(string $setting, string $recordId): void {
		$settings = Application::getSetting($this->recordListName, $this->pid) ?: [];
		if (isset($settings[$setting]) && in_array($recordId, $settings[$setting])) {
			$idx = array_search($recordId, $settings[$setting]);
			array_splice($settings[$setting], $idx, 1);
			Application::saveSetting($this->recordListName, $settings, $this->pid);
		}
	}

	public function downloadRecordIdsToBeProcessed(array $records = []): array {
		if (empty($records)) {
			$records = Download::recordIds($this->token, $this->server);
		}
		$settings = $this->getSwitches();
		$freq = $settings['Update Frequency'] ?? "weekly";
		if ($freq == "weekly") {
			$numWeeksInCycle = 1;
			$weekNum = 1;
		} elseif ($freq == "monthly") {
			$numWeeksInCycle = 4;
			$weekNum = REDCapManagement::getWeekNumInMonth();
		} elseif ($freq == "quarterly") {
			$numWeeksInCycle = 13;
			$weekNum = REDCapManagement::getWeekNumInYear();
		} elseif ($freq == "request") {
			return [];
		} else {
			throw new \Exception("Invalid update frequency! $freq");
		}
		$recordsToProcess = [];
		for ($i = 0; $i < count($records); $i++) {
			if ($i % $numWeeksInCycle == ($weekNum - 1) % $numWeeksInCycle) {
				$recordsToProcess[] = $records[$i];
			}
		}
		return $recordsToProcess;
	}

	public function getValue(string $category, string $switchType = "project"): string {
		$allSwitches = $this->getSwitches($switchType);
		if (isset($allSwitches[$category])) {
			return strval($allSwitches[$category]);
		} elseif (isset($this->switches[$category])) {
			if (isset($this->switchDefaults[$category])) {
				return strval($this->switchDefaults[$category]);
			}
			foreach ($this->switches[$category] as $label => $idx) {
				return strval($idx);
			}
		}
		return "";
	}

	public function isOnForProject(string $category): bool {
		$allSwitches = $this->getSwitches("project");
		return (isset($allSwitches[$category]) && ($allSwitches[$category] == "On"));
	}

	public function getSwitches(string $switchType = "project"): array {
		if ($switchType == "project") {
			$settingName = $this->settingName;
		} elseif ($switchType == "record") {
			$settingName = $this->recordListName;
		} else {
			throw new \Exception("Invalid switch type $switchType");
		}
		$allSwitches = Application::getSetting($settingName, $this->pid);
		if ($allSwitches && ($switchType == "project")) {
			foreach ($this->switches as $item => $values) {
				if (!isset($allSwitches[$item])) {
					if (isset($this->switchDefaults[$item])) {
						$allSwitches[$item] = $this->switchDefaults[$item];
					} else {
						foreach ($values as $label => $idx) {
							$allSwitches[$item] = $idx;
							break;    // inner
						}
					}
				}
			}
		} elseif (!$allSwitches) {
			$allSwitches = [];

			if ($switchType == "project") {
				// Initialize with first item as default
				foreach ($this->switches as $item => $values) {
					if (isset($this->switchDefaults[$item])) {
						$allSwitches[$item] = $this->switchDefaults[$item];
					} else {
						foreach ($values as $label => $idx) {
							$allSwitches[$item] = $idx;
							break;    // inner
						}
					}
				}
			} elseif ($switchType == "record") {
				foreach ($this->recordSwitches as $item) {
					$allSwitches[$item] = [];
				}
			}
		}
		return $allSwitches;
	}

	public function savePost(array $post): array {
		$changed = false;
		$recordId = $post['record_id'] ?? null;
		if ($recordId) {
			$records = Download::recordIds($this->token, $this->server);
			$recordId = Sanitizer::getSanitizedRecord($recordId, $records);
			$allSwitches = $this->getSwitches("record");
			foreach ($this->recordSwitches as $title) {
				$id = REDCapManagement::makeHTMLId($title);
				if (isset($post[$id])) {
					if (($post[$id] == "On") && !in_array($recordId, $allSwitches[$title])) {
						$changed = true;
						$allSwitches[$title][] = $recordId;
					} elseif (($post[$id] == "Off") && in_array($recordId, $allSwitches[$title])) {
						$changed = true;
						$idx = array_search($recordId, $allSwitches[$title]);
						array_splice($allSwitches[$title], $idx, 1);
					}
				}
			}
			$switchType = "record";
		} else {
			$allSwitches = $this->getSwitches("project");
			foreach ($this->switches as $title => $switchOptions) {
				$id = REDCapManagement::makeHTMLId($title);
				if (isset($post[$id])) {
					foreach (array_values($switchOptions) as $value) {
						if ($value == $post[$id]) {
							$changed = true;
							$allSwitches[$title] = $value;
						}
					}
				}
			}
			$switchType = "project";
		}
		if ($changed) {
			$this->saveSwitches($allSwitches, $switchType);
			return ["message" => "Success."];
		} else {
			return ["error" => "Nothing changed."];
		}
	}

	public function getFormsToExclude(array $allSwitches = []): array {
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

	public function haveNewSwitchesChanged(array $newSwitches): bool {
		$oldSwitches = $this->getSwitches();
		$onOffOptions = array_keys(self::ON_OFF);
		foreach ($newSwitches as $title => $newValue) {
			if (isset($oldSwitches[$title])) {
				$oldValue = $oldSwitches[$title];
			} elseif (isset($this->switchDefaults[$title])) {
				$oldValue = $this->switchDefaults[$title];
			} elseif (in_array($newValue, $onOffOptions)) {
				# On by default
				$oldValue = "On";
			} else {
				$oldValue = "";
			}
			if ($newValue !== $oldValue) {
				return true;
			}
		}
		return false;
	}

	public function saveSwitches(array $allSwitches, string $switchType = "project"): void {
		$formsToExclude = $this->getFormsToExclude($allSwitches);

		if ($this->haveNewSwitchesChanged($allSwitches)) {
			$eventId = Application::getSetting("event_id", $this->pid);
			$deletionRegEx = DataDictionaryManagement::getDeletionRegEx();
			$files = [];
			if (method_exists("\Vanderbilt\CareerDevLibrary\Application", "getMetadataFiles")) {
				$files = Application::getMetadataFiles($this->pid);
			}
			if (!empty($files)) {
				DataDictionaryManagement::installMetadataFromFiles($files, $this->token, $this->server, $this->pid, $eventId, Application::getRelevantChoices(), $deletionRegEx, $formsToExclude);
			}
		}
		if ($switchType == "project") {
			$settingName = $this->settingName;
		} elseif ($switchType == "record") {
			$settingName = $this->recordListName;
		} else {
			throw new \Exception("Invalid Feature Switch type $switchType");
		}
		Application::saveSetting($settingName, $allSwitches, $this->pid);
	}

	public function makeHTML(string $switchType = "project", ?string $recordId = null): string {
		if ($switchType == "project") {
			$allSwitches = $this->getSwitches();
			return $this->makeAllSwitches($allSwitches, $this->switches);
		} elseif ($switchType == "record") {
			$allRecordSwitchList = $this->getSwitches("record");
			$allRecordSwitches = [];
			$switchConfigs = [];
			foreach ($allRecordSwitchList as $category => $records) {
				$switchConfigs[$category] = self::ON_OFF;
				$allRecordSwitches[$category] = in_array($recordId, $records) ? "On" : "Off";
			}
			return $this->makeAllSwitches($allRecordSwitches, $switchConfigs, $recordId);
		} else {
			throw new \Exception("Invalid Switch Type $switchType");
		}
	}

	public function makeAllSwitches(array $switches, array $switchConfigs, ?string $recordId = null): string {
		$thisUrl = Application::link("this", $this->pid);
		$origParams = explode("?", $thisUrl)[1];
		foreach ($_GET as $key => $value) {
			$key = Sanitizer::sanitizeWithoutChangingQuotes($key);
			$value = Sanitizer::sanitizeWithoutChangingQuotes($value);
			if (!preg_match("/$key=/", $origParams)) {
				$thisUrl .= "&".urlencode($key)."=".urlencode($value);
			}
		}
		$html = "<section id='switch-wrapper'>";
		if ($recordId) {
			$html .= "<p class='centered' style='font-size: 16px;'>Turn on a feature just for this record ($recordId).</p>";
		} else {
			$html .= "<p class='centered'>Saving a new configuration may reset any custom fields that you have added to your Data Dictionary. It will also stop data collection for affected data. <strong>Please proceed with care when making changes.</strong></p>";
		}
		foreach ($switchConfigs as $title => $options) {
			$size = "size".count($options);
			if (REDCapManagement::arraysEqual($options, self::ON_OFF)) {
				$short = "short";
				if (count($switchConfigs) == 1) {
					$width = "100%";
				} else {
					$width = "50%";
				}
			} else {
				$short = "";
				$width = "100%";
			}
			$titleId = REDCapManagement::makeHTMLId($title);
			$html .= "<div style='float: left; width: $width; padding: 0 20px;'><h4 style='margin-bottom: 0.5rem;'>$title (<span class='valueHolder' data-title='$titleId'></span>)</h4><div class='switch centered $size $short'>".$this->makeSwitchHTML($title, $switches)."<div class='switch__indicator'></div></div></div>";
		}
		$html .= "<p class='centered' style='padding-top: 25px; clear: left;'><button id='switchButton' class='biggerButton' onclick='presentScreen(\"Saving...\"); const postdata = makeSwitchPostData(); $.post(\"$thisUrl\", postdata, function(html) { console.log(html); location.href=\"$thisUrl\"; });'>Save Settings</button></p>";
		$html .= "<script>
$(document).ready(function() {
    $('.valueHolder').each(function(idx, ob) {
        const title = $(ob).data('title');
        const value = $('[name='+title+']:checked').val();
        $(ob).html(value);
    });
    ";
		foreach (array_keys($switchConfigs) as $title) {
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
            if (!$('#switchButton').hasClass('green')) {
                $('#switchButton').addClass('green');
            }
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
		if ($recordId) {
			$html .= "    hash['record_id'] = '$recordId';\n";
		}
		$html .= "
    console.log('postData: '+JSON.stringify(hash));
    hash['redcap_csrf_token'] = getCSRFToken();
    return hash;
}
</script>";
		$html .= "</section>";
		return $html;
	}

	public function makeSwitchHTML(string $title, array $allSwitches): string {
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

	protected $settingName;
	protected $recordListName;
	protected $token;
	protected $server;
	protected $pid;
	protected $switches;
	protected $recordSwitches;
	protected $switchDefaults;
	protected $forms;
}
