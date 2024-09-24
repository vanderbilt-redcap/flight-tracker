<?php

namespace Vanderbilt\CareerDevLibrary;

# For X->Y conversion, if you have X grant, you are counted year-to-year whether you get Y grant.
# If you get Y grant, then you count as a success; if you don't you go on; if you are lost to follow-up,
# you're "censored." It uses Chart.js to turn it into a graph.

require_once(__DIR__ . '/ClassLoader.php');

class KaplanMeierCurve {
    const ALL_SCHOLARS_LABEL = "All Scholars";
    const CENSORED_DATA_LABEL = "Censored Data";
    const MAX_K_GRANTS = 4;
    const PLOTS = [
        "survival" => ["title" => "Kaplan-Meier Success Curve", "yAxisTitle" => "Percent Converting from "],
        "hazard" => ["title" => "Momentum Plot (Equivalent of Hazard Plot)", "yAxisTitle" => "Rate of Change in Conversion Percent Per Unit Time (dS/dT)"],
    ];
    const K2R = "K to R";
    const T2K = "T/F to K";
    const T2K2R = "T/F to R";
    const UNKNOWN_RESOURCE = "Unknown Resource";
    const ACTIVITY_FIELDS = [
        "summary_first_grant_activity",
        "summary_last_grant_activity",
        "summary_first_pub_activity",
        "summary_last_pub_activity",
    ];

    public static function getGraphTypes() {
        $grantClass = Application::getSetting("grant_class");
        if ($grantClass == "K") {
            return [
                REDCapManagement::makeHTMLId(self::K2R) => self::K2R,
            ];
        } else if ($grantClass == "T") {
            return [
                REDCapManagement::makeHTMLId(self::T2K) => self::T2K,
                REDCapManagement::makeHTMLId(self::K2R) => self::K2R,
                REDCapManagement::makeHTMLId(self::T2K2R) => self::T2K2R,
            ];
        } else {
            return [
                REDCapManagement::makeHTMLId(self::K2R) => self::K2R,
                REDCapManagement::makeHTMLId(self::T2K) => self::T2K,
                REDCapManagement::makeHTMLId(self::T2K2R) => self::T2K2R,
            ];
        }
    }

    public static function getMaxLife(array $graphSerialTimes): float {
        return !empty($graphSerialTimes) ? max(array_values($graphSerialTimes)) : 0.0;
    }

    public function __construct(array $data, array $serialTimes, array $graphsToDraw, string $cohort) {
        $this->data = $data;
        $this->cohortTitle = $cohort ? " (Cohort $cohort)" : "";
        $this->datasets = [];
        $this->labels = [];
        $this->colors = array_merge(["rgba(0, 0, 0, 1)"], Application::getApplicationColors(["1.0", "0.6", "0.2"]));
        $this->graphsToDraw = $graphsToDraw;
        $this->totalDataPoints = [];
        $this->serialTimes = $serialTimes;
        $this->hazardData = [];
        $this->precalculate();
    }

    public function getTotalDataPoints(string $graphType): int {
        return $this->totalDataPoints[$graphType] ?? 0;
    }

    private function getLabels(string $curveType, string $graphType): array {
        return $this->labels[$graphType][$curveType] ?? [];
    }

    private function getDataset(string $curveType, string $graphType): array {
        return $this->datasets[$graphType][$curveType] ?? [];
    }

    private static function generateHazardData(array $curveDataForGraph): array {
        $hazardData = [];
        $step = 1;
        foreach ($curveDataForGraph as $label => $rows) {
            if ($label == self::ALL_SCHOLARS_LABEL) {
                $hazardData[$label] = [];
                for ($start = 0; $start < count($rows) - 1; $start++) {
                    $end = $start + $step;
                    $idx = $start + $step / 2;
                    if (isset($rows[$end]['pretty_percent']) && isset($rows[$start]['pretty_percent'])) {
                        $dS = REDCapManagement::pretty($rows[$end]["pretty_percent"] - $rows[$start]["pretty_percent"], 1) / $step;
                        if (isset($_GET['test'])) {
                            echo "Index: $idx has $dS<br>";
                        }
                        $hazardData[$label]["$idx"] = $dS;
                    }
                }
            }
        }
        return $hazardData;
    }

    # PHP 7 does not support union return types, so leaving blank for now
    # return value should be int|bool|string|array, but unions are only in PHP 8
    public static function getParam(string $parameter)  {
        if ($parameter == "showRealGraphs") {
            return ($_GET['measType'] && $_GET['measurement']);
        } else if ($parameter == "firstTime") {
            return !isset($_GET['measType']);
        } else if ($parameter == "measType") {
            return Sanitizer::sanitize($_GET['measType'] ?? "both");
        } else if ($parameter == "startDateSource") {
            return Sanitizer::sanitize($_GET['startDateSource'] ?? "end_last_training_grant");
        } else if ($parameter == "meas") {
            return Sanitizer::sanitize($_GET['measurement'] ?? "years");
        } else if ($parameter == "measUnit") {
            if (isset($_GET['measurement'])) {
                $meas = self::getParam("meas");
                if ($meas == "years") {
                    return "y";
                } else {
                    return "M";
                }
            } else {
                return "y";
            }
        } else if ($parameter == "showAllResources") {
            $firstTime = self::getParam("firstTime");
            return ((isset($_GET['showAllResources']) && ($_GET['showAllResources'] == "on")) || $firstTime);
        } else if ($parameter == "showAllResourcesText") {
            if (self::getParam("showAllResources")) {
                return " checked";
            } else {
                return "";
            }
        } else if ($parameter == "graphTypes") {
            $ids = Sanitizer::sanitizeArray($_GET['graphTypes'] ?? []);
            $conversions = self::getGraphTypes();
            $texts = [];
            foreach ($ids as $id) {
                $texts[] = $conversions[$id];
            }
            return $texts;
        } else {
            return "";
        }
    }

    public static function makeData(int $pid, string $graphType, array $records, array $resourceChoices): array {
        if ($graphType == self::K2R) {
            return self::makeK2RData($pid, $records, $resourceChoices);
        } else if ($graphType == self::T2K) {
            return self::makeTFData($pid, $records, $resourceChoices, "summary_first_any_k");
        } else if ($graphType == self::T2K2R) {
            return self::makeTFData($pid, $records, $resourceChoices, "summary_first_r01_or_equiv");
        }
        return [[], [], [], [], []];
    }

    private static function makeTFData(int $pid, array $records, array $resourceChoices, string $conversionDateField): array
    {
        $measType = self::getParam("measType");
        $measUnit = self::getParam("measUnit");
        $startDateSource = KaplanMeierCurve::getParam("startDateSource");

        $fields = array_unique(array_merge(["record_id", $conversionDateField, "resources_resource"],
            Conversion::T_FIELDS,
            self::ACTIVITY_FIELDS));
        $metadataFields = Download::metadataFieldsByPid($pid);
        $fields = DataDictionaryManagement::filterOutInvalidFieldsFromFieldlist($metadataFields, $fields);
        $startDates = [];
        $endDates = [];
        $serialTimes = [];
        $resourcesUsedIdx = [];
        $statusAtSerialTime = [];
        foreach ($records as $record) {
            $redcapData = Download::fieldsForRecordsByPid($pid, $fields, [$record]);
            $recordStartDate = self::findEarliestStartDate($redcapData, $record, $startDateSource, "getDateOfLastT");
            if ($recordStartDate) {
                # signed up for a training grant
                $startDates[$record] = $recordStartDate;
                $conversionDate = REDCapManagement::findField($redcapData, $record, $conversionDateField);
                if ($conversionDate) {
                    $endDates[$record] = $conversionDate;
                    $statusAtSerialTime[$record] = "event";
                } else {
                    $endDates[$record] = self::findLatestEndDate($redcapData, $record, $measType) ?: $startDates[$record];
                    $statusAtSerialTime[$record] = "censored";
                }
                $serialTimes[$record] = DateManagement::datediff($startDates[$record], $endDates[$record], $measUnit);
                $resourcesUsedIdx[$record] = self::collectResources($redcapData, $record);
            }
        }

        list($curveData, $groups) = self::makeCurveData($resourceChoices, $resourcesUsedIdx, $serialTimes, $statusAtSerialTime);
        return [$curveData, $serialTimes, $statusAtSerialTime, $resourcesUsedIdx, $groups];
    }

    private static function collectResources(array $redcapData, string $record): array {
        $resourcesUsedIdx = ["all"];
        foreach (REDCapManagement::findAllFields($redcapData, $record, "resources_resource") as $idx) {
            if (!in_array($idx, $resourcesUsedIdx)) {
                $resourcesUsedIdx[] = $idx;
            }
        }
        return $resourcesUsedIdx;
    }

    private static function makeK2RData(int $pid, array $records, array $resourceChoices): array {
        $measUnit = self::getParam("measUnit");
        $measType = self::getParam("measType");
        $startDateSource = self::getParam("startDateSource");

        $fields = array_merge([
            "record_id",
            "summary_ever_last_any_k_to_r01_equiv",
            "summary_first_r01_or_equiv",
            "summary_last_any_k",
            "resources_resource",
        ], self::ACTIVITY_FIELDS);
        for ($i = 1; $i <= self::getNumberofSummaryGrantsToPull(); $i++) {
            $fields[] = "summary_award_type_".$i;
        }
        $startDates = [];
        $endDates = [];
        $serialTimes = [];
        $statusAtSerialTime = [];
        $resourcesUsedIdx = [];
        foreach ($records as $record) {
            $redcapData = Download::fieldsForRecordsByPid($pid, $fields, [$record]);
            $earliestStartDate = self::findEarliestStartDate($redcapData, $record, $startDateSource, "getDateOfLastK");
            if ($earliestStartDate) {
                $startDates[$record] = $earliestStartDate;
            } else {
                continue;    // skip record
            }
            $conversionStatusIdx = REDCapManagement::findField($redcapData, $record, "summary_ever_last_any_k_to_r01_equiv");
            if ($conversionStatusIdx == 7) {
                # Bridge Award
                continue;
            }

            $conversionDate = REDCapManagement::findField($redcapData, $record, "summary_first_r01_or_equiv");

            if (in_array($conversionStatusIdx, [1, 2])) {
                $endDates[$record] = $conversionDate;
                $statusAtSerialTime[$record] = "event";
            } else {
                $endDates[$record] = self::findLatestEndDate($redcapData, $record, $measType) ?: $startDates[$record];
                $statusAtSerialTime[$record] = "censored";
            }
            $serialTimes[$record] = DateManagement::datediff($startDates[$record], $endDates[$record], $measUnit);
            $resourcesUsedIdx[$record] = self::collectResources($redcapData, $record);
        }

        list($curveData, $groups) = self::makeCurveData($resourceChoices, $resourcesUsedIdx, $serialTimes, $statusAtSerialTime);
        return [$curveData, $serialTimes, $statusAtSerialTime, $resourcesUsedIdx, $groups];
    }

    private static function makeCurveData(array $resourceChoices, array $resourcesUsedIdx, array $serialTimes, array $statusAtSerialTime): array {
        $showAllResources = self::getParam("showAllResources");
        $groups = ["all" => self::ALL_SCHOLARS_LABEL];
        if ($showAllResources) {
            foreach ($resourceChoices as $idx => $label) {
                $groups[$idx] = $label;
            }
        }
        if (isset($_GET['test'])) {
            echo "groups: ".json_encode($groups)."<br>";
        }

        $curveData = [];
        foreach ($groups as $idx => $label) {
            $curveData[$label] = [];
            if (isset($_GET['test'])) {
                echo "Examining $idx $label<br>";
            }
            $groupRecords = self::getResourceRecords($idx, $resourcesUsedIdx);
            $curveData[$label][0] = [
                "numer" => count($groupRecords),
                "denom" => count($groupRecords),
                "percent" => 100.0,
                "pretty_percent" => 0.0,
                "censored" => 0,
                "events" => 0,
                "this_fraction" => 1.0,
            ];
            for ($i = 1; $i <= self::getMaxLife($serialTimes); $i++) {
                $numCensoredInTimespan = 0;
                $numEventsInTimespan = 0;
                $startI = $i - 1;
                foreach ($groupRecords as $record) {
                    if (($serialTimes[$record] >= $startI) && ($serialTimes[$record] < $i)) {
                        if ($statusAtSerialTime[$record] == "event") {
                            $numEventsInTimespan++;
                        } else if ($statusAtSerialTime[$record] == "censored") {
                            $numCensoredInTimespan++;
                        } else {
                            throw new \Exception("Record $record has an invalid status (".$statusAtSerialTime[self::K2R][$record].") with a serial time of ".$serialTimes[self::K2R][$record]);
                        }
                    }
                }
                $startNumer = $curveData[$label][$startI]["numer"] ?? 0;
                $startPerc = $curveData[$label][$startI]["percent"] ?? 0;
                $curveData[$label][$i] = [];
                $curveData[$label][$i]["censored"] = $numCensoredInTimespan;
                $curveData[$label][$i]["events"] = $numEventsInTimespan;
                $curveData[$label][$i]["denom"] = $startNumer - $numCensoredInTimespan;
                $curveData[$label][$i]["numer"] = $curveData[$label][$i]["denom"] - $numEventsInTimespan;
                $curveData[$label][$i]["this_fraction"] = ($curveData[$label][$i]["denom"] > 0) ? $curveData[$label][$i]["numer"] / $curveData[$label][$i]["denom"] : 0;
                $curveData[$label][$i]["percent"] = $startPerc * $curveData[$label][$i]["this_fraction"];
                $curveData[$label][$i]["pretty_percent"] = REDCapManagement::pretty(100.0 - $curveData[$label][$i]["percent"], 1);
            }
        }
        return [$curveData, $groups];
    }

    public static function getJSHTML(): string {
        $link = Application::link("js/Chart.min.js");
        return "<script src='$link'></script>";
    }

    public static function makeHTMLId(string $graphType, string $curveType): string {
        return REDCapManagement::makeHTMLId($graphType)."___".REDCapManagement::makeHTMLId($curveType);
    }

    private function precalculate() {
        $meas = self::getParam("meas");
        $censored = [];
        $n = [];
        $linePoints = [];
        $this->labels = [];
        $this->datasets = [];
        foreach ($this->graphsToDraw as $graphType) {
            $this->hazardData[$graphType] = self::generateHazardData($this->data[$graphType] ?? []);
            $n[$graphType] = [];
            $linePoints[$graphType] = ["survival" => [], "hazard" => []];
            $censored[$graphType] = [];
            $this->labels[$graphType]["survival"] = [];
            $this->totalDataPoints[$graphType] = 0;
            $maxLife = self::getMaxLife($this->serialTimes[$graphType] ?? []);
            foreach ($this->data[$graphType] ?? [] as $label => $curvePoints) {
                $label = (string)$label;
                $n[$graphType][$label] = $curvePoints[0]["numer"];
                $linePoints[$graphType]["survival"][$label] = [];
                $censored[$graphType][$label] = [];
                foreach ($curvePoints as $cnt => $ary) {
                    if (count($this->labels[$graphType]["survival"]) < $maxLife) {
                        $this->labels[$graphType]["survival"][] = self::makeXAxisLabel($cnt, $meas);
                    }
                    if (isset($ary['pretty_percent']) && isset($ary['censored'])) {
                        $linePoints[$graphType]["survival"][$label][] = $ary['pretty_percent'];
                        if ($ary['censored'] > 0) {
                            $censored[$graphType][$label][] = $ary['pretty_percent'];
                        } else {
                            $censored[$graphType][$label][] = 0;
                        }
                        $this->totalDataPoints[$graphType]++;
                    }
                }
            }
        }

        $blankColor = "rgba(0, 0, 0, 0.0)";
        $colorByLabel = [];
        foreach ($this->graphsToDraw as $graphType) {
            $i = 0;
            $colorByLabel[$graphType] = [];
            $this->datasets[$graphType] = ["survival" => [], "hazard" => []];
            $maxLife = self::getMaxLife($this->serialTimes[$graphType] ?? []);
            foreach ($linePoints[$graphType]["survival"] as $label => $linePointValues) {
                if ($n[$graphType][$label] > 0) {
                    $colorByLabel[$graphType][$label] = $this->colors[$i % count($this->colors)];
                    $this->datasets[$graphType]["survival"][] = [
                        "label" => self::makeDatasetLabel($label, $n[$graphType][$label]),
                        "data" => $linePointValues,
                        "fill" => false,
                        "borderColor" => $colorByLabel[$graphType][$label],
                        "backgroundColor" => $colorByLabel[$graphType][$label],
                        "stepped" => true,
                    ];

                    $censoredColors = [];
                    foreach ($censored[$graphType][$label] as $percentCensored) {
                        if ($percentCensored) {
                            $censoredColors[] = $colorByLabel[$graphType][$label];
                        } else {
                            $censoredColors[] = $blankColor;
                        }
                    }

                    $this->datasets[$graphType]["survival"][] = [
                        "label" => self::CENSORED_DATA_LABEL,
                        "data" => $censored[$graphType][$label],
                        "fill" => false,
                        "borderColor" => $blankColor,
                        "backgroundColor" => $censoredColors,
                        "pointRadius" => 4,
                    ];
                    $i++;
                }
            }

            $this->labels[$graphType]["hazard"] = [];
            foreach ($this->hazardData[$graphType] as $label => $points) {
                if ($n[$graphType][$label] > 0) {
                    foreach (array_keys($points) as $x) {
                        if (count($this->labels[$graphType]["hazard"]) < $maxLife) {
                            $this->labels[$graphType]["hazard"][] = self::makeXAxisLabel($x, $meas);
                        }
                    }
                    $this->datasets[$graphType]["hazard"][] = [
                        "label" => self::makeDatasetLabel($label, $n[$graphType][$label]),
                        "data" => array_values($points),
                        "fill" => false,
                        "borderColor" => $colorByLabel[$label],
                        "tension" => 0.1,
                    ];
                }
            }
        }
    }

    public function getSuccessCurves(): string {
        $projectTitle = Application::getProjectTitle();

        $chartLineJS = "";
        $initJS = "";
        foreach (array_keys(self::PLOTS) as $curveType) {
            foreach ($this->graphsToDraw as $graph) {
                $id = self::makeHTMLId($graph, $curveType);
                $labelsJSON = json_encode($this->getLabels($curveType, $graph));
                $datasetsJSON = json_encode($this->getDataset($curveType, $graph));
                $reverseBool = "false";
                if ($curveType == "survival") {
                    $yAxisTitle = self::PLOTS[$curveType]['yAxisTitle'].$graph;
                } else {
                    $yAxisTitle = self::PLOTS[$curveType]['yAxisTitle'];
                }
                $chartLineJS .= "ctx['$id'] = document.getElementById('$id').getContext('2d');\n";
                $chartLineJS .= "data['$id'] = { labels: $labelsJSON, datasets: $datasetsJSON };\n";
                $initJS .= "    redrawChart(ctx['$id'], data['$id'], '$id', '$yAxisTitle', $reverseBool);\n";
            }
        }

        return "<script>
        let ctx = {};
let data = {};
$chartLineJS
let lineCharts = {};
$(document).ready(function() {
    $initJS
});

function redrawChart(ctx, data, id, yAxisTitle, reverseBool) {
    const config = {
        type: 'line',
        data: data,
        options: {
            radius: 0,
            interaction: {
                intersect: false,
                axis: 'x'
            },
            plugins: {
                legend: {
                    labels: {
                        generateLabels: function(chart) {
                            const labels = Chart.defaults.plugins.legend.labels.generateLabels(chart);
                            const newLabels = [];
                            for (const key in labels) {
                                if (labels[key].text != '".self::CENSORED_DATA_LABEL."') {
                                    newLabels.push(labels[key]);
                                }
                            }
                            return newLabels;
                        }
                    }
                },
                title: {
                    display: true,
                    text: (ctx) => '$projectTitle{$this->cohortTitle}',
                    text: (ctx) => '$projectTitle{$this->cohortTitle}',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.dataset.label == '".self::CENSORED_DATA_LABEL."') {
                                return '';
                            }
                            return context.dataset.label+': '+context.parsed.y;
                        }
                    }
                }
            },
            scales: {
                y: {
                    title: {
                        text: yAxisTitle,
                        display: true
                    },
                    reverse: reverseBool,
                    suggestedMin: 0.0,
                    beginAtZero: true
                }
            }
        }
    };
    if (yAxisTitle.match(/Percent Converting/)) {
        config.options.scales.y.suggestedMax = 100.0;
    }
    lineCharts[id] = new Chart(ctx, config);
}
</script>";
    }

    private static function getResourceRecords($idx, $resources) {
        if ($idx == "all") {
            return array_keys($resources);
        }
        $records = [];
        foreach ($resources as $recordId => $listOfResources) {
            if (in_array($idx, $listOfResources)) {
                $records[] = $recordId;
            }
        }
        return $records;
    }

    private static function findLastKType($data, $recordId) {
        $kTypes = [1, 2, 3, 4];
        for ($i = 1; $i <= self::getNumberofSummaryGrantsToPull(); $i++) {
            $grantType = REDCapManagement::findField($data, $recordId, "summary_award_type_".$i);
            if (in_array($grantType, $kTypes)) {
                return $grantType;
            }
        }
        return FALSE;
    }

    private static function getDateOfLastT(array $data, string $recordId): string {
        $tfStarts = [];
        foreach ($data as $row) {
            list($awardNo, $startDate, $endDate) = Conversion::getAwardNumberAndDates($row);
            if (Conversion::isRowTAppointment($row) || Conversion::isAwardFAppointment($awardNo)) {
                $tfStarts[] = $startDate;
            }
        }
        if (!empty($tfStarts)) {
            return DateManagement::getLatestDate($tfStarts);
        }
        return "";
    }

    private static function getDateOfLastK(array $data, string $recordId): string {
        $lastAnyKStart = REDCapManagement::findField($data, $recordId, "summary_last_any_k");
        $lastKType = self::findLastKType($data, $recordId);
        if ($lastKType && $lastAnyKStart && DateManagement::isDate($lastAnyKStart)) {
            if ($lastKType == 1) {
                $years = Application::getInternalKLength();
            } else if ($lastKType == 2) {
                $years = Application::getK12KL2Length();
            } else if (in_array($lastKType, [3, 4])) {
                $years = Application::getIndividualKLength();
            } else {
                throw new \Exception("Invalid K Type $lastKType!");
            }
            return DateManagement::addYears($lastAnyKStart, $years);
        }
        return "";
    }

    private static function findEarliestStartDate($data, $recordId, $startDateSource, $function) {
        $grantDate = REDCapManagement::findField($data, $recordId, "summary_first_grant_activity");
        $pubDate = REDCapManagement::findField($data, $recordId, "summary_first_pub_activity");
        if ($startDateSource == "first_grant") {
            return $grantDate;
        } else if ($startDateSource == "first_publication") {
            return $pubDate;
        } else if ($startDateSource == "end_last_training_grant") {
            # The two options for this function returns the LAST date, and thus the name "Earliest Start Date" does not apply here.
            return self::$function($data, $recordId);
        } else if ($startDateSource == "first_any") {
            if (!$grantDate) {
                return $pubDate;
            }
            if (!$pubDate) {
                return $grantDate;
            }
            if (DateManagement::dateCompare($grantDate, "<", $pubDate)) {
                return $grantDate;
            } else {
                return $pubDate;
            }
        }
        return "";
    }

    private static function findLatestEndDate($data, $recordId, $measType) {
        $grantDate = REDCapManagement::findField($data, $recordId, "summary_last_grant_activity");
        $pubDate = REDCapManagement::findField($data, $recordId, "summary_last_pub_activity");
        if ($measType == "Grants") {
            return $grantDate;
        } else if ($measType == "Publications") {
            return $pubDate;
        } else if ($measType == "Both") {
            if (!$grantDate) {
                return $pubDate;
            }
            if (!$pubDate) {
                return $grantDate;
            }
            if (DateManagement::dateCompare($grantDate, ">", $pubDate)) {
                return $grantDate;
            } else {
                return $pubDate;
            }
        }
        return "";
    }

    private static function makeXAxisLabel($cnt, $meas) {
        if (is_float($cnt)) {
            $cnt = floor($cnt)."-".ceil($cnt);
        }
        if ($meas == "days") {
            return "Day $cnt";
        } else if ($meas == "months") {
            return "Month $cnt";
        } else if ($meas == "years") {
            return "Year $cnt";
        } else {
            throw new \Exception("Improper measurement $meas");
        }
    }

    private static function makeDatasetLabel($label, $n) {
        return REDCapManagement::stripHTML($label)." (n=".($n ?: 0).")";
    }

    public static function getNumberofSummaryGrantsToPull() {
        return (self::MAX_K_GRANTS < Grants::$MAX_GRANTS) ? self::MAX_K_GRANTS : Grants::$MAX_GRANTS;
    }

    protected $data;
    protected $hazardData;
    protected $cohortTitle;
    protected $labels;
    protected $datasets;
    protected $colors;
    protected $graphsToDraw;
    protected $totalDataPoints;
    protected $serialTimes;
}
