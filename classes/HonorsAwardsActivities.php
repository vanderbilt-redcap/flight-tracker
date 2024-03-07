<?php

namespace Vanderbilt\CareerDevLibrary;

# This file compiles all of the grants from various data sources and compiles them into an ordered list of grants.
# It should remove duplicate grants as well.
# Unit-testable.

require_once(__DIR__ . '/ClassLoader.php');

class HonorsAwardsActivities {
    public const NUM_SURVEY_ACTIVITIES = 5;
    public const INSTRUMENTS_AND_PREFICES = [
        "honors_awards_and_activities" => "activityhonor",
        "honors_awards_and_activities_survey" => "surveyactivityhonor",
    ];
    public const OLD_HONORS_AND_AWARDS = [
        "old_honors_and_awards" => "honor",
        "followup" => "followup",
        "" => "check",
    ];
    public const OLD_HONOR_FIELDS = [
        "record_id",
        "check_honors_awards",
        "check_abstracts",
        "check_presentations",
        "check_internal_committees",
        "check_external_committees",
        "check_internal_leadership",
        "check_honor_imported",
        "check_date",
        "followup_honors_awards",
        "followup_abstracts",
        "followup_presentations",
        "followup_internal_committees",
        "followup_external_committees",
        "followup_internal_leadership",
        "followup_honor_imported",
        "followup_date",
        "honor_name",
        "honor_org",
        "honor_type",
        "honor_exclusivity",
        "honor_date",
        "honor_notes",
        "honor_created",
        "honor_imported",
        "honor_last_update",
    ];
    public const OTHER_VALUE = DataDictionaryManagement::HONORACTIVITY_OTHER_VALUE;

    public function __construct($redcapData, $pid, $recordId) {
        $this->pid = $pid;
        $this->recordId = $recordId;
        $this->rows = [];
        $this->oldRows = [];
        foreach ($redcapData as $row) {
            if (
                ($row['record_id'] == $recordId)
                && in_array($row['redcap_repeat_instrument'], array_keys(self::INSTRUMENTS_AND_PREFICES))
            ) {
                $this->rows[] = $row;
            } else if (
                ($row['record_id'] == $recordId)
                && in_array($row['redcap_repeat_instrument'], array_keys(self::OLD_HONORS_AND_AWARDS))
            ) {
                $this->oldRows[] = $row;
            }
        }
        $fields = [];
        foreach (self::INSTRUMENTS_AND_PREFICES as $instrument => $prefix) {
            $fields = array_merge($fields, Download::metadataFieldsByPidWithPrefix($this->pid, $prefix));
        }
        $this->relevantMetadata = Download::metadataByPid($this->pid, $fields);
        $this->relevantChoices = DataDictionaryManagement::getChoices($this->relevantMetadata);
    }

    public function getCount() {
        return count($this->rows);
    }

    private function makeNoDataHTML() {
        $eventId = REDCapManagement::getEventIdForClassical($this->pid);
        if (Portal::isPortalPage()) {
            $form = "honors_awards_and_activities_survey";
            $newLink = \REDCap::getSurveyLink($this->recordId, $form, $eventId, 1, $this->pid);
            $linkHTML = Links::makeLink($newLink, "Add the first!");
        } else {
            $form = "honors_awards_and_activities";
            $linkHTML = Links::makeFormLink($this->pid, $this->recordId, $eventId, "Add one now!", $form);
        }
        return "<p class='centered max-width'>No honors or activities have been added. $linkHTML</p>";
    }

    public function getHTML() {
        if (empty($this->rows)) {
            return $this->makeNoDataHTML();
        }
        $rowsByYear = [];
        foreach ($this->rows as $row) {
            foreach (self::INSTRUMENTS_AND_PREFICES as $instrument => $prefix) {
                if ($row['redcap_repeat_instrument'] == $instrument) {
                    $year = (int) $row[$prefix."_award_year"] ?: 1900;   // arbitrary year in the far past
                    if (!isset($rowsByYear[$year])) {
                        $rowsByYear[$year] = [];
                    }
                    $rowsByYear[$year][] = $row;
                }
            }
        }
        krsort($rowsByYear);   // descending order, like a CV

        $htmlRows = [];
        foreach ($rowsByYear as $year => $rows) {
            foreach ($rows as $row) {
                foreach (self::INSTRUMENTS_AND_PREFICES as $instrument => $prefix) {
                    if ($row['redcap_repeat_instrument'] == $instrument) {
                        $htmlRows[] = $this->makeHonorHTML($row, $prefix);
                    }
                }
            }
        }
        if (empty($htmlRows)) {
            return $this->makeNoDataHTML();
        }
        return "<div class='max-width-600 centered'>".implode("<br/>", $htmlRows)."</div>";
    }

    private function makeHonorHTML($row, $prefix) {
        $type = $this->relevantChoices[$prefix."_type"][$row[$prefix."_type"]];
        $name = $row[$prefix."_name"] ?: "Unnamed";
        if (
            ($type == "Award")
            && ($row[$prefix . "_local_name"] !== "")
            && ($row[$prefix . "_local_name"] != self::OTHER_VALUE)
        ) {
            $name = $this->relevantChoices[$prefix . "_local_name"][$row[$prefix . "_local_name"]];
        }
        $organization = $row[$prefix."_org"] ?? "";
        $year = $row[$prefix."_award_year"] ?? "";
        $level = $this->relevantChoices[$prefix."_activity_realm"][$row[$prefix."_activity_realm"] ?? ""] ?? "";
        $exclusivity = $row[$prefix."_exclusivity"] ?? "";
        $notes = $row[$prefix."_notes"] ?? "";

        $htmlLines = [];
        if (in_array($type, ["Abstract or Paper", "Oral Presentation", "Poster Presentation"])) {
            $coauthors = $row[$prefix."_coauthors"] ?? "";
            $title = $row[$prefix."_pres_title"] ?? "";
            $start = $row[$prefix."_activity_start"] ?? "";
            $end = $row[$prefix."_activity_end"] ?? "";
            $location = $row[$prefix."_activity_location"];
            $activityName = $row[$prefix."_activity"];
            $nodes = [];
            if ($coauthors) {
                $nodes[] = $coauthors;
            }
            if ($title) {
                $nodes[] = $title;
            }
            $locationNodes = [];
            if ($activityName) {
                $place = "";
                if ($type == "Abstract or Paper") {
                    $place = "Text presented at: ";
                } else if ($type == "Oral Presentation") {
                    $place = "Orally presented at: ";
                } else if ($type == "Poster Presentation") {
                    $place = "Poster presented at: ";
                }
                $locationNodes[] = $place."<i>$activityName</i>";
            }
            if ($start) {
                $date = DateManagement::YMD2LongDate($start);
                if ($end && ($end != $start)) {
                    $date .= " - ".DateManagement::YMD2LongDate($end);
                }
                $locationNodes[] = $date;
            }
            if ($location) {
                $locationNodes[] = $location;
            }
            if (!empty($locationNodes)) {
                $nodes[] = implode("; ", $locationNodes);
            }
            if (!empty($nodes)) {
                $htmlLines[] = "<strong>$type</strong>: ".implode(". ", $nodes).".";
            }
        } else if ($type == "Committee (Including Journals)") {
            if ($row[$prefix."_committee_name"] == self::OTHER_VALUE) {
                $committeeName = $row[$prefix."_committee_name_other"];
            } else {
                $committeeName = $this->relevantChoices[$prefix."_committee_name"][$row[$prefix."_committee_name"]];
            }
            $nature = $this->relevantChoices[$prefix."_committee_nature"][$row[$prefix."_committee_nature"]];
            $role = $this->relevantChoices[$prefix."_committee_nature"][$row[$prefix."_committee_role"]];
            if ($committeeName) {
                $title = "<strong>Committee</strong>: $committeeName";
                if ($nature) {
                    $title .= " ($nature)";
                }
                $htmlLines[] = $title;
            }

            if ($role) {
                $htmlLines[] = "<strong>Role in Committee</strong>: $role";
            }
        } else if ($type == "Leadership Position") {
            $leadershipTitle = $row[$prefix."_leadership_role"];
            if ($leadershipTitle) {
                $htmlLines[] = "<strong>Title</strong>: $leadershipTitle";
            }
        }
        if ($organization) {
            $htmlLines[] = "<strong>Organization</strong>: $organization";
        }
        if ($level) {
            $htmlLines[] = "<strong>Level</strong>: $level";
        }
        if ($exclusivity) {
            $htmlLines[] = "<strong>Exclusivity</strong>: $exclusivity";
        }

        $yearText = $year ? " ($year)" : "";
        $html = "<h4>$type: $name$yearText</h4>";
        if (!empty($htmlLines)) {
            $html .= "<p>".implode("<br/>", $htmlLines)."</p>";
        } else {
            $html .= "<p>No information specified.</p>";
        }
        if ($notes) {
            $notes = preg_replace("/[\n\r]+/", "<br/>", $notes);
            $html .= "<p>Notes:<br/>$notes</p>";
        }
        return $html;
    }

    protected $rows;
    protected $oldRows;
    protected $pid;
    protected $recordId;
    protected $relevantMetadata;
    protected $relevantChoices;
}
