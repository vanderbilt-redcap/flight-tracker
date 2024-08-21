<?php

namespace Vanderbilt\CareerDevLibrary;

# For Y->Z conversion, you're given X years to convert (based on user input) from Y grant to Z grant.
# If you get Z grant, you're a success (numerator). If you don't and exceed X years,
# you don't succeed (denominator). If you don't get Z grant and you're within X years from the start of Z grant,
# you're omitted from the calculation altogether.

require_once(__DIR__ . '/ClassLoader.php');

class Conversion {
    const R_TYPES = [5, 6, 8];
    const EXTERNALLY_GRANTED_KS = [3, 4];
    const INTERNALLY_GRANTED_KS = [1, 2];
    const BRIDGE_AWARD = 9;
    const CONVERSION_TYPES = [
        "K2R" => "K to R",
        "TF2K" => "T/F to K",
        "TF2R" => "T/F to R",
    ];
    const LEFT_FIELDS = [
        "record_id",
        "identifier_last_name",
        "identifier_middle",
        "identifier_first_name",
        "identifier_email",
        "identifier_institution",
        "identifier_left_date",
    ];
    const T_FIELDS = [
        "record_id",
        "custom_type",
        "custom_start",
        "custom_end",
        "custom_role",
        "custom_is_submission",
        "nih_project_num",
        "nih_project_start_date",
        "nih_project_end_date",
        "coeus_sponsor_award_number",
        "coeus_project_start_date",
        "coeus_project_end_date",
        "vera_direct_sponsor_award_id",
        "vera_project_start_date",
        "vera_project_end_date",
    ];
    const F_INSTRUMENTS = ["nih_reporter", "vera", "coeus"];
    const T_TYPE = 10;
    const PREDOC_INDEX = 6;
    const POSTDOC_INDEX = 7;
    const PI_ROLE_INDEX = 1;
    const T_ROLES = ["", 5, self::PREDOC_INDEX, self::POSTDOC_INDEX];
    const CONVERSION_EXPLANATION = "Conversion ratios vary significantly across different types of programs. Typically, predoctoral programs expect to have a wider variety of professional outcomes than programs deeper into a research career.";

    public function __construct(array $conversionTypes, int $pid, int $eventId) {
        $this->pid = $pid;
        $this->eventId = $eventId;
        $this->conversionTypes = $conversionTypes;
    }

    public function getTypeOfLastK(array $data, string $recordId): string {
        $ks = array(
            1 => "Internal K",
            2 => "K12/KL2",
            3 => "Individual K",
            4 => "K Equivalent",
        );
        foreach ($data as $row) {
            if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "")) {
                for ($i = Grants::$MAX_GRANTS; $i >= 1; $i--) {
                    if (in_array($row['summary_award_type_'.$i], array_keys($ks))) {
                        return $ks[$row['summary_award_type_'.$i]];
                    }
                }
            }
        }
        return "";
    }

    public function getKAwardees(array $data, int $intKLength, int $indKLength): array {
        $qualifiers = array();
        $today = date("Y-m-d");

        foreach ($data as $row) {
            if ($row['redcap_repeat_instrument'] === "") {
                $person = $row['identifier_first_name']." ".$row['identifier_last_name'];
                $first_r = "";
                for ($i = 1; $i <= 15; $i++) {
                    if (in_array($row['summary_award_type_'.$i], self::R_TYPES)) {
                        $first_r = $row['summary_award_date_'.$i];
                        break;
                    }
                }

                $first_k = "";
                if (!$first_r) {
                    for ($i = 1; $i <= 15; $i++) {
                        if (in_array($row['summary_award_type_'.$i], self::EXTERNALLY_GRANTED_KS)) {
                            $first_k = $row['summary_award_date_'.$i];
                            if (REDCapManagement::datediff($row['summary_award_date_'.$i], $today, "y") <= $indKLength) {
                                $qualifiers[$row['record_id']] = $person;
                            }
                            break;
                        }
                    }
                }

                if (!$first_k && !$first_r) {
                    for ($i = 1; $i < 15; $i++) {
                        if (in_array($row['summary_award_type_'.$i], self::INTERNALLY_GRANTED_KS)) {
                            if (REDCapManagement::datediff($row['summary_award_date_'.$i], $today, "y") <= $intKLength) {
                                $qualifiers[$row['record_id']] = $person;
                            }
                            break;
                        }
                    }
                }
            }
        }
        return $qualifiers;
    }

    private static function breakUpKs(string $kType): array {
        $kPre = preg_split("//", $kType);
        $ks = [];
        foreach ($kPre as $k) {
            if ($k !== "") {
                $ks[] = $k;
            }
        }
        return $ks;
    }

    public static function isConvertedFromK2R(array $row, int $kLength, string $orderK, string $kType, bool $searchIfLeft): string
    {
        $ks = self::breakUpKs($kType);

        $k = "";
        $first_r = "";
        $last_k = "";
        for ($i = 1; $i <= 15; $i++) {
            if (in_array($row['summary_award_type_' . $i], $ks)) {
                $last_k = $row['summary_award_date_' . $i];
            }
            if (in_array($row['summary_award_type_' . $i], $ks)) {
                if (!$k) {
                    $k = $row['summary_award_date_' . $i];
                } else if ($orderK == "last") {
                    $k = $row['summary_award_date_' . $i];
                }
            } else if (!$first_r && in_array($row['summary_award_type_' . $i], self::R_TYPES)) {
                $first_r = $row['summary_award_date_' . $i];
            } else if ($row['summary_award_type_' . $i] == self::BRIDGE_AWARD) {
                // omit
                return "";
            }
        }
        if ($orderK == "last") {
            $kToUse = $last_k;
        } else {
            $kToUse = $k;
        }
        return self::hasConverted($row, $kToUse, $first_r, $kLength, $searchIfLeft);
    }

    private static function hasConverted(array $row, string $trainingStart, string $conversionStart, int $lengthOnTraining, bool $searchIfLeft): string {
        $today = date("Y-m-d");
        if (!$trainingStart) {
            // error_log("A ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']);
            return "";
        }
        if (!$conversionStart) {
            if ($lengthOnTraining && (REDCapManagement::datediff($trainingStart, $today, "y") <= $lengthOnTraining)) {
                # Training < X years old
                // error_log("B ".REDCapManagement::datediff($trainingStart, $today, "y")." ".$trainingStart." ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']);
                return "";
            }
            # did not convert
            if ($searchIfLeft && $row['identifier_left_date']) {
                // error_log("D ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']);
                return "";
            }
            $hasNonVanderbiltEmail = isset($row['identifier_email']) && $row['identifier_email'] &&
                !preg_match("/vanderbilt\.edu/i", $row['identifier_email']) &&
                !preg_match("/vumc\.org/i", $row['identifier_email']);
            if (Application::isVanderbilt() && $searchIfLeft && $hasNonVanderbiltEmail) {
                # lost to follow up because has non-Vanderbilt email
                # will not implement for other domains because we don't know their setups
                # for other domains, rely on identifier_left_date
                // error_log("Non-Vanderbilt email");
                return "";
            }
            # no target and no reason to throw out => not converted
            return "denom";
        }
        # leftovers have an R => converted
        return "numer";
    }

    private function isRowInKRange(array $row, int $kLength, string $orderK, string $kType, string $kStartDate, string $kEndDate, bool $excludeUnconvertedKsBefore, bool $searchIfLeft): bool {
        if (!$kStartDate && !$kEndDate && !$excludeUnconvertedKsBefore) {
            return TRUE;
        }
        $ks = self::breakUpKs($kType);
        $kDate = "";
        $cStatus = self::isConvertedFromK2R($row, $kLength, $orderK, $kType, $searchIfLeft);
        for ($i = 0; $i < Grants::$MAX_GRANTS; $i++) {
            if (in_array($row['summary_award_type_'.$i], $ks)) {
                $rowField = "summary_award_date_".$i;
                if ($orderK == "first") {
                    if (!$kDate && $row[$rowField]) {
                        $kDate = $row[$rowField];
                    }
                } else if ($orderK == "last") {
                    if ($row[$rowField]) {
                        $kDate = $row[$rowField];
                    }
                }
            }
        }
        if ($kStartDate && !DateManagement::dateCompare($kDate, ">=", $kStartDate)) {
            return FALSE;
        }
        if ($kEndDate && !DateManagement::dateCompare($kDate, "<=", $kEndDate)) {
            return FALSE;
        }
        if (($cStatus == "denom") && DateManagement::dateCompare($kDate, "<=", $excludeUnconvertedKsBefore)) {
            return FALSE;
        }
        return TRUE;
    }

    public static function isAwardFAppointment(string $awardNo): bool {
        $activityCode = Grant::getActivityCode($awardNo);
        return ($activityCode !== "") && in_array(substr($activityCode, 0, 1), ["F", "f"]);
    }
    public static function isRowTAppointment(array $row): bool {
        if (
            ($row['redcap_repeat_instrument'] == "custom_grant")
            && ($row['custom_type'] == self::T_TYPE)
            && in_array($row['custom_role'], self::T_ROLES)
            && (($row['custom_is_submission'] ?? "") != 1)
            && $row['custom_start']
        ) {
            return TRUE;
        }
        return FALSE;
    }

    private static function getTFRows(array $redcapData, string $recordId, string $startDate = "", string $endDate = ""): array {
        $rows = [];
        foreach ($redcapData as $row) {
            if (
                ($row['record_id'] == $recordId)
                && in_array($row['redcap_repeat_instrument'], array_merge(self::F_INSTRUMENTS, ["custom_grant"]))
            ) {
                list($awardNo, $awardStartDate, $awardEndDate) = self::getAwardNumberAndDates($row);
                if ($awardStartDate) {
                    if (
                        (
                            self::isRowTAppointment($row)
                            || self::isAwardFAppointment($awardNo)
                        ) && (
                            !$startDate
                            || DateManagement::dateCompare($awardStartDate, ">=", $startDate)
                        ) && (
                            !$endDate
                            || !$awardEndDate
                            || DateManagement::dateCompare($awardEndDate, "<=", $endDate)
                        )

                    ) {
                        $rows[] = $row;
                    }
                }
            }
        }
        return $rows;
    }

    public static function getAwardNumberAndDates(array $row): array {
        $awardNo = "";
        $startDate = "";
        $endDate = "";
        if ($row['redcap_repeat_instrument'] == "nih_reporter") {
            $awardNo = $row["nih_project_num"] ?? $awardNo;
            $startDate = $row["nih_project_start_date"] ?? $startDate;
            $endDate = $row["nih_project_end_date"] ?? $endDate;
        } else if ($row['redcap_repeat_instrument'] == "coeus") {
            $awardNo = $row['coeus_sponsor_award_number'] ?? $awardNo;
            $startDate = $row['coeus_project_start_date'] ?? $startDate;
            $endDate = $row['coeus_project_end_date'] ?? $endDate;
        } else if ($row['redcap_repeat_instrument'] == "vera") {
            $awardNo = $row['vera_direct_sponsor_award_id'] ?? $awardNo;
            $startDate = $row['vera_project_start_date'] ?? $startDate;
            $endDate = $row['vera_project_end_date'] ?? $endDate;
        } else if ($row['redcap_repeat_instrument'] == "custom_grant") {
            $awardNo = $row['custom_number'] ?? $awardNo;
            $startDate = $row['custom_start'] ?? $startDate;
            $endDate = $row['custom_end'] ?? $endDate;
        }
        return [$awardNo, $startDate, $endDate];
    }

    public static function getSummaryFields(): array {
        $fields = ["record_id", "summary_dob"];
        for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
            $fields[] = "summary_award_date_".$i;
            $fields[] = "summary_award_type_".$i;
        }
        return $fields;
    }

    public function getTF2RAverages(array $records, int $trainingLength, string $order, string $startDate, string $endDate, bool $searchIfLeft): array {
        if (!in_array("TF2R", $this->conversionTypes)) {
            throw new \Exception("Conversion type is not T2R!");
        }
        return $this->getTFAverages($records, $trainingLength, $order, $startDate, $endDate, $searchIfLeft, "R");
    }

    public function getTF2KAverages(array $records, int $tLength, string $order, string $startDate, string $endDate, bool $searchIfLeft): array
    {
        if (!in_array("TF2K", $this->conversionTypes)) {
            throw new \Exception("Conversion type is not T2K!");
        }
        return $this->getTFAverages($records, $tLength, $order, $startDate, $endDate, $searchIfLeft, "K");
    }

    private function getTFAverages(array $records, int $tLength, string $order, string $startDate, string $endDate, bool $searchIfLeft, string $target): array {
        list($avgs, $sums) = self::setupAveragesAndSums();
        $fields = array_unique(array_merge(self::T_FIELDS, self::getSummaryFields(), self::LEFT_FIELDS));
        $metadataFields = Download::metadataFieldsByPid($this->pid);
        $fields = DataDictionaryManagement::filterOutInvalidFieldsFromFieldlist($metadataFields, $fields);
        foreach ($records as $recordId) {
            $redcapData = Download::fieldsForRecordsByPid($this->pid, $fields, [$recordId]);
            $tfStartDate = self::getTFStart($redcapData, $recordId, $order, $startDate, $endDate);
            if ($tfStartDate) {
                $cStatus = self::isConvertedFromTF($redcapData, $recordId, $tLength, $tfStartDate, $searchIfLeft, $target);
            } else {
                $cStatus = "";
            }
            $normativeRow = REDCapManagement::getNormativeRow($redcapData);
            $this->addToAverages($sums, $avgs, $cStatus, $normativeRow, $tfStartDate);
        }
        return self::finalizeAverages($avgs, $sums);
    }

    private static function isConvertedFromTF(array $redcapData, string $recordId, int $tLength, string $tfStartDate, string $searchIfLeft, string $convertedGrantType): string {
        if ($convertedGrantType == "K") {
            $targetGrants = array_merge(self::INTERNALLY_GRANTED_KS, self::EXTERNALLY_GRANTED_KS);
        } else if ($convertedGrantType == "R") {
            $targetGrants = self::R_TYPES;
        } else {
            throw new \Exception("Invalid type to convert from!");
        }

        $targetDates = [];
        foreach ($redcapData as $row) {
            if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "")) {
                for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
                    if (
                        in_array($row['summary_award_type_'.$i], $targetGrants)
                        && $row['summary_award_date_'.$i]
                        && DateManagement::dateCompare($row['summary_award_date_'.$i], ">=", $tfStartDate)
                    ) {
                        $targetDates[$row['summary_award_date_'.$i]] = $row;
                    } else if ($row['summary_award_type_' . $i] == self::BRIDGE_AWARD) {
                        return "";   // omit all those who have bridge awards ever
                    }

                }
            }
        }

        if (empty($targetDates)) {
            $convertedDate = "";
            $row = [];
        } else {
            $convertedDate = DateManagement::getEarliestDate(array_keys($targetDates));
            $row = $targetDates[$convertedDate];
        }
        $hasConverted = self::hasConverted($row, $tfStartDate, $convertedDate, $tLength, $searchIfLeft);
        // error_log("$recordId with T-length $tLength: start:$tfStartDate converted:$convertedDate status:$hasConverted");
        return $hasConverted;
    }

    private static function getTFStart(array $redcapData, string $recordId, string $startOrder, string $startDate = "", string $endDate = ""): string {
        $rows = self::getTFRows($redcapData, $recordId, $startDate, $endDate);
        $starts = [];
        foreach ($rows as $row) {
            $awardStartDate = self::getAwardNumberAndDates($row)[1];
            if ($awardStartDate) {
                $starts[] = $awardStartDate;
            }
        }
        if (empty($starts)) {
            return "";
        } else if ($startOrder == "first") {
            return DateManagement::getEarliestDate($starts);
        } else {
            # last
            return DateManagement::getLatestDate($starts);
        }
    }

    private function addToAverages(array &$sums, array &$avgs, string $cStatus, array $normativeRow, string $firstTFDate = ""): void {
        $formattedName = NameMatcher::formatName($normativeRow["identifier_first_name"], $normativeRow["identifier_middle"] ?? "", $normativeRow["identifier_last_name"]);
        if ($cStatus == "numer") {
            // echo "Numer ".$normativeRow['record_id']." ".$formattedName."<br>";
            $sums["conversion"][] = 100;   // percent
            $avgs["converted"][] = Links::makeSummaryLink($this->pid, $normativeRow['record_id'], $this->eventId, $formattedName);
        } else if ($cStatus == "denom") {
            // echo "Denom ".$normativeRow['record_id']." ".$formattedName."<br>";
            $sums["conversion"][] = 0;
            $avgs["not_converted"][] = Links::makeSummaryLink($this->pid, $normativeRow['record_id'], $this->eventId, $formattedName);
        } else {
            $avgs["omitted"][] = Links::makeSummaryLink($this->pid, $normativeRow['record_id'], $this->eventId, $formattedName);
        }
        if ($normativeRow['summary_dob']) {
            if ($firstTFDate) {
                $sums["age_at_first_t"][] = DateManagement::datediff($normativeRow['summary_dob'], $firstTFDate, "y");
            }
            $today = date("Y-m-d");
            $sums["age"][] = DateManagement::datediff($normativeRow['summary_dob'], $today, "y");
            for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
                if ($normativeRow['summary_award_date_'.$i] && in_array($normativeRow['summary_award_type_'.$i], array_merge(self::INTERNALLY_GRANTED_KS, self::EXTERNALLY_GRANTED_KS))) {
                    $sums["age_at_first_k"][] = DateManagement::datediff($normativeRow['summary_dob'], $normativeRow['summary_award_date_'.$i], "y");
                    break;
                }
            }
            for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
                if ($normativeRow['summary_award_date_'.$i] && in_array($normativeRow['summary_award_type_'.$i], self::R_TYPES)) {
                    $sums["age_at_first_r"][] = DateManagement::datediff($normativeRow['summary_dob'], $normativeRow['summary_award_date_'.$i], "y");
                    break;
                }
            }
        }
    }

    private static function setupAveragesAndSums(): array {
        $avgs = array(
            "conversion" => 0,
            "age" => 0,
            "age_at_first_t" => 0,
            "age_at_first_k" => 0,
            "age_at_first_r" => 0,
            "converted" => [],
            "not_converted" => [],
            "omitted" => [],
        );
        $sums = array();
        foreach ($avgs as $key => $value) {
            if (!is_array($value)) {
                $sums[$key] = array();
            }
        }
        return [$avgs, $sums];
    }

    public function getK2RAverages(array $records, int $kLength, string $orderK, string $kType, string $kStartDate, string $kEndDate, bool $excludeUnconvertedKsBefore, bool $searchIfLeft): array {
        if (!in_array("K2R", $this->conversionTypes)) {
            throw new \Exception("Conversion type is not K2R!");
        }

        $data = Download::fieldsForRecordsByPid($this->pid, array_unique(array_merge(self::getSummaryFields(), self::LEFT_FIELDS)), $records);
        list($avgs, $sums) = self::setupAveragesAndSums();
        foreach ($data as $row) {
            if (($row['redcap_repeat_instrument'] === "") && $this->isRowInKRange($row, $kLength, $orderK, $kType, $kStartDate, $kEndDate, $excludeUnconvertedKsBefore, $searchIfLeft)) {
                $cStatus = self::isConvertedFromK2R($row, $kLength, $orderK, $kType, $searchIfLeft);
                $this->addToAverages($sums, $avgs, $cStatus, $row);
            }
        }
        return self::finalizeAverages($avgs, $sums);
    }

    private static function finalizeAverages(array $avgs, array $sums): array {
        foreach ($sums as $key => $ary) {
            # one decimal place
            $perc = "";
            if ($key == "conversion") {
                $perc = "%";
            }
            if (count($ary) > 0) {
                $avgs[$key] = (floor(10 * array_sum($ary) / count($ary)) / 10)."$perc<br><span class='small'>(n=".count($ary).")</span>";
            } else {
                $avgs[$key] = "Incalculable<br><span class='small'>(n=".count($ary).")</span>";
            }
        }

        $avgs["num_omitted"] = count($avgs["omitted"]);
        $avgs["num_converted"] = count($avgs["converted"]);
        $avgs["num_not_converted"] = count($avgs["not_converted"]);

        return $avgs;
    }


    protected $pid;
    protected $eventId;
    protected $conversionTypes;
}
