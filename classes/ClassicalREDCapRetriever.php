<?php

namespace Vanderbilt\CareerDevLibrary;

# For classical REDCap projects only (no longitudinal projects), this class replaces REDCap::getData()
# It is quickest for queries that don't exceed 40,000 (MYSQL_CHUNK_SIZE) data points.
# It's actually slower than REDCap::getData() for queries that dramatically exceed that number (e.g., 320k data points).
# Flight Tracker typically streams data one record at a time, so this class adequately serves that purpose
# When MYSQL_CHUNK_SIZE data points are not exceeded, a 50-100% speedup is observed on redcap.vumc.org compared to REDCap::getData()
# The resulting data structures are equivalent

require_once(__DIR__ . '/ClassLoader.php');

class ClassicalREDCapRetriever {
    const FIELD_CHUNK_SIZE = 2000;
    const MYSQL_CHUNK_SIZE = 40000;

    public function __construct(\ExternalModules\AbstractExternalModule $module, int $pid)  {
        $this->module = $module;
        $this->pid = $pid;
        $this->pk = $this->getPrimaryKey();
        $this->redcapDataTable = method_exists("\REDCap", "getDataTable") ? \REDCap::getDataTable($this->pid) : "redcap_data";

    }

    private function getPidForSQL() {
        return "?";
    }

    private function getPrimaryKey(): string {
        $sql = "SELECT field_name FROM redcap_metadata WHERE field_order = 1 AND project_id = ".$this->getPidForSQL();
        $params = [$this->pid];
        $q = $this->module->query($sql, $params);
        if ($row = $q->fetch_assoc()) {
            return $row['field_name'];
        } else {
            throw new \Exception("No primary key!");
        }
    }

    private function downloadMetadataForms() : array {
        $sql = "SELECT DISTINCT(form_name) FROM redcap_metadata WHERE project_id = ".$this->getPidForSQL()." ORDER BY field_order";
        $params = [$this->pid];
        $q = $this->module->query($sql, $params);
        $forms = [];
        while ($row = $q->fetch_assoc()) {
            $forms[] = $row['form_name'];
        }
        return $forms;
    }

    # first field in $fields must be $pk
    public function getData(array $fields, array $records) : array {
        if (empty($fields)) {
            return [];
        }
        # in the database, record is a string value, not an integer
        # this affects indexing with queries
        $records = array_map("strval", $records);

        $allForms = $this->downloadMetadataForms();
        $repeatingForms = $this->getRepeatingForms();
        $nonRepeatingForms = array_diff($allForms, $repeatingForms);
        $fieldsInRepeatingFormsByForm = $this->downloadFieldsForForms($repeatingForms);
        $fieldsInRepeatingForms = [];
        foreach ($fieldsInRepeatingFormsByForm as $formFields) {
            $commonFields = array_intersect($fields, $formFields);
            $fieldsInRepeatingForms = array_merge($fieldsInRepeatingForms, $commonFields);
        }
        $checkboxChoices = $this->getCheckboxFieldsAndChoices();

        $fieldsInNormativeRow = array_diff($fields, $fieldsInRepeatingForms);
        $recordNormativeRows = $this->downloadNormativeRows($fieldsInNormativeRow, $records, $nonRepeatingForms, $checkboxChoices);
        $repeatingRows = $this->downloadRepeatingFields($fieldsInRepeatingForms, $fieldsInRepeatingFormsByForm, $records, $checkboxChoices);

        $hasRepeatingData = FALSE;
        foreach ($repeatingRows as $rows) {
            if (!empty($rows)) {
                $hasRepeatingData = TRUE;
                break;
            }
        }

        if (empty($recordNormativeRows) && !$hasRepeatingData) {
            $rows = [];
            foreach ($records as $recordId) {
                $row = [$this->pk => "$recordId"];
                foreach ($fields as $field) {
                    if ($field !== $this->pk) {
                        $row[$field] = "";
                    }
                }
                $rows[] = $row;
            }
        } else {
            $rows = [];
            foreach ($records as $recordId) {
                if (!empty($recordNormativeRows[$recordId])) {
                    $rows[] = $recordNormativeRows[$recordId];
                }
                if (!empty($repeatingRows[$recordId])) {
                    $rows = array_merge($rows, $repeatingRows[$recordId]);
                }
            }
        }
        return $rows;
    }

    private function downloadRepeatingFields(array $fieldsInRepeatingForms, array $fieldsInRepeatingFormsByForm, array $records, array $checkboxChoices): array {
        if (empty($fieldsInRepeatingForms) || empty($records)) {
            return [];
        }
        $formsFromField = [];
        foreach ($fieldsInRepeatingFormsByForm as $instrument => $fields) {
            foreach ($fields as $field) {
                if (in_array($field, $fieldsInRepeatingForms)) {
                    $formsFromField[$field] = $instrument;
                }
            }
        }

        # Chunk to make sure the text of the SQL query doesn't get too long
        $chunkedRecords = array_chunk($records, $this->getRecordChunkSize(count($fieldsInRepeatingForms)));
        $chunkedFields = array_chunk($fieldsInRepeatingForms, self::FIELD_CHUNK_SIZE);
        $rowsByRecordAndForm = [];

        foreach ($chunkedRecords as $recordChunk) {
            $recordQuestionMarks = array_fill(0, count($recordChunk), "?");
            foreach ($chunkedFields as $fieldChunk) {
                $totalNumRows = 0;
                $offset = 0;
                $startTs = time();
                $fieldQuestionMarks = array_fill(0, count($fieldChunk), "?");
                $recordSQLArray = "(".implode(",", $recordQuestionMarks).")";
                $fieldSQLArray = "(".implode(",", $fieldQuestionMarks).")";
                do {
                    $queryStartTs = time();
                    $sql = "SELECT record, field_name, `value`, instance
                    FROM {$this->redcapDataTable}
                    WHERE
                        project_id = ".$this->getPidForSQL()."
                        AND record IN $recordSQLArray
                        AND field_name IN $fieldSQLArray
                    ORDER BY record, field_name
                        LIMIT ".self::MYSQL_CHUNK_SIZE."
                        OFFSET $offset";
                    $params = array_merge([$this->pid], $recordChunk, $fieldChunk);
                    $result = $this->module->query($sql, $params);
                    $numRows = $result->num_rows;
                    $queryEndTs = time();
                    if (isset($_GET['test'])) {
                        echo json_encode($recordChunk)." / ".count($fieldChunk)." fields; Repeating query took ".($queryEndTs - $queryStartTs)." seconds for $numRows MySQL rows with offset $offset<br/>";
                    }

                    while ($row = $result->fetch_assoc()) {
                        $instance = $row['instance'] ?? 1;
                        $record = $row['record'];
                        $field = $row['field_name'];
                        $value = html_entity_decode($row['value']);
                        if (!isset($formsFromField[$field])) {
                            throw new \Exception("Could not find the appropriate form for $field!");
                        }
                        $instrument = $formsFromField[$field];
                        if (!isset($rowsByRecordAndForm[$record])) {
                            $rowsByRecordAndForm[$record] = [];
                        }
                        if (!isset($rowsByRecordAndForm[$record][$instrument])) {
                            $rowsByRecordAndForm[$record][$instrument] = [];
                        }
                        if (!isset($rowsByRecordAndForm[$record][$instrument][$instance])) {
                            $rowsByRecordAndForm[$record][$instrument][$instance] = [];
                        }
                        if (isset($checkboxChoices[$field])) {
                            $rowsByRecordAndForm[$record][$instrument][$instance][$field . "___" . $value] = "1";
                        } else {
                            $rowsByRecordAndForm[$record][$instrument][$instance][$field] = $value;
                        }
                    }

                    $offset += self::MYSQL_CHUNK_SIZE;
                    $totalNumRows += $numRows;
                } while ($numRows == self::MYSQL_CHUNK_SIZE);
                $endTs = time();
                if (isset($_GET['test'])) {
                    echo "Repeating ".($endTs - $startTs)." seconds with $totalNumRows MySQL rows<br/>";
                }
            }
        }

        $recordRows = [];
        foreach ($records as $record) {
            $recordRows[$record] = [];
            foreach ($rowsByRecordAndForm[$record] ?? [] as $instrument => $rowsByInstance) {
                foreach ($rowsByInstance as $instance => $values) {
                    $row = [
                        $this->pk => "$record",
                        "redcap_repeat_instrument" => $instrument,
                        "redcap_repeat_instance" => "$instance",
                    ];
                    foreach ($fieldsInRepeatingFormsByForm[$instrument] as $field) {
                        if (isset($checkboxChoices[$field])) {
                            $defaultValue = "0";
                            foreach (array_keys($checkboxChoices[$field]) as $index) {
                                $row[$field."___".$index] = $values[$field."___".$index] ?? $defaultValue;
                            }
                        } else {
                            $defaultValue = "";
                            if (!isset($values[$field]) && preg_match("/_complete$/", $field)) {
                                $instrument = preg_replace("/_complete$/", "", $field);
                                if (in_array($instrument, array_keys($fieldsInRepeatingFormsByForm))) {
                                    $defaultValue = "0";
                                }
                            }
                            $row[$field] = $values[$field] ?? $defaultValue;
                        }
                    }
                    $recordRows[$record][] = $row;
                }
            }
        }
        return $recordRows;
    }

    private function downloadNormativeRows(array $fields, array $records, array $forms, array $checkboxChoices): array {
        if (empty($fields) || empty($records)) {
            return [];
        }
        if ((count($fields) == 1) && ($this->pk == $fields[0])) {
            return [];
        }

        # Chunk to make sure the text of the SQL query doesn't get too long
        $chunkedRecords = array_chunk($records, $this->getRecordChunkSize(count($fields)));
        $chunkedFields = array_chunk($fields, self::FIELD_CHUNK_SIZE);

        $recordNormativeRows = [];
        $normativeRowsByRecord = [];
        foreach ($chunkedRecords as $recordChunk) {
            $recordQuestionMarks = array_fill(0, count($recordChunk), "?");
            foreach ($chunkedFields as $fieldChunk) {
                $totalNumRows = 0;
                $startTs = time();
                $offset = 0;
                $fieldQuestionMarks = array_fill(0, count($fieldChunk), "?");
                $recordSQLArray = "(".implode(",", $recordQuestionMarks).")";
                $fieldSQLArray = "(".implode(",", $fieldQuestionMarks).")";
                do {
                    $queryStartTs = time();
                    $sql = "SELECT record, field_name, `value`
                                FROM {$this->redcapDataTable}
                                WHERE project_id = ".$this->getPidForSQL()."
                                    AND record IN $recordSQLArray
                                    AND field_name IN $fieldSQLArray
                                    ORDER BY record, field_name
                                        LIMIT ".self::MYSQL_CHUNK_SIZE."
                                        OFFSET $offset";
                    $params = array_merge([$this->pid], $recordChunk, $fieldChunk);
                    $result = $this->module->query($sql, $params);
                    $numRows = $result->num_rows;
                    $queryEndTs = time();
                    if (isset($_GET['test'])) {
                        echo json_encode($recordChunk)." / ".count($fieldChunk)." fields; Normative query took ".($queryEndTs - $queryStartTs)." seconds for $numRows MySQL rows with offset $offset<br/>";
                    }

                    while ($row = $result->fetch_assoc()) {
                        $recordId = $row['record'];
                        $field = $row['field_name'];
                        $value = html_entity_decode($row['value']);
                        if (!isset($normativeRowsByRecord[$recordId])) {
                            $normativeRowsByRecord[$recordId] = [];
                        }
                        if (isset($checkboxChoices[$field])) {
                            $normativeRowsByRecord[$recordId][$field."___".$value] = "1";
                        } else {
                            $normativeRowsByRecord[$recordId][$field] = $value;
                        }
                    }
                    $offset += self::MYSQL_CHUNK_SIZE;
                    $totalNumRows += $numRows;
                } while ($numRows == self::MYSQL_CHUNK_SIZE);
                $endTs = time();
                if (isset($_GET['test'])) {
                    echo "Normative ".($endTs - $startTs)." seconds with $totalNumRows MySQL rows<br/>";
                }
            }
        }
        foreach ($records as $recordId) {
            $normativeRow = [
                $this->pk => "$recordId",
                "redcap_repeat_instrument" => "",
                "redcap_repeat_instance" => "",
            ];
            foreach ($fields as $field) {
                if (isset($checkboxChoices[$field])) {
                    $defaultValue = "0";
                    foreach (array_keys($checkboxChoices[$field]) as $index) {
                        $normativeRow[$field . "___" . $index] = $normativeRowsByRecord[$recordId][$field . "___" . $index] ?? $defaultValue;
                    }
                } else {
                    $defaultValue = "";
                    if (!isset($normativeRowsByRecord[$recordId][$field]) && preg_match("/_complete$/", $field)) {
                        $instrument = preg_replace("/_complete$/", "", $field);
                        if (in_array($instrument, $forms)) {
                            $defaultValue = "0";
                        }
                    }
                    $normativeRow[$field] = $normativeRowsByRecord[$recordId][$field] ?? $defaultValue;
                }
            }
            $recordNormativeRows[$recordId] = $normativeRow;
        }
        return $recordNormativeRows;
    }

    private function getRepeatingForms(): array {
        $sql = "SELECT DISTINCT(r.form_name) AS form_name FROM redcap_events_metadata AS m INNER JOIN redcap_events_arms AS a ON (a.arm_id = m.arm_id) INNER JOIN redcap_events_repeat AS r ON (m.event_id = r.event_id) WHERE a.project_id = ".$this->getPidForSQL();
        $params = [$this->pid];
        $q = $this->module->query($sql, $params);
        $repeatingForms = array();
        while ($row = $q->fetch_assoc()) {
            $repeatingForms[] = $row['form_name'];
        }
        return $repeatingForms;
    }

    private function downloadFieldsForForms(array $forms): array {
        if (empty($forms)) {
            return [];
        }
        $formQuestionMarks = array_fill(0, count($forms), "?");
        $formArraySQL = "(".implode(",", $formQuestionMarks).")";
        $sql = "SELECT field_name, form_name FROM redcap_metadata WHERE project_id = ".$this->getPidForSQL()." AND form_name IN $formArraySQL ORDER BY field_order";
        $params = array_merge([$this->pid], $forms);
        $results = $this->module->query($sql, $params);
        $fields = [];
        while ($row = $results->fetch_assoc()) {
            $form = $row['form_name'];
            if (!isset($fields[$form])) {
                $fields[$form] = [];
            }
            $fields[$form][] = $row['field_name'];
        }
        return $fields;
    }

    private function getCheckboxFieldsAndChoices(): array {
        $sql = "SELECT field_name, element_enum FROM redcap_metadata WHERE project_id = ".$this->getPidForSQL()." AND element_type = 'checkbox' ORDER BY field_order";
        $params = [$this->pid];
        $result = $this->module->query($sql, $params);

        $fieldsAndChoices = [];
        while ($row = $result->fetch_assoc()) {
            $field = $row['field_name'];
            $choiceStr = $row['element_enum'];
            $fieldsAndChoices[$field] = self::getRowChoices($choiceStr);
        }
        return $fieldsAndChoices;
    }

    private static function getRowChoices(string $choicesStr): array {
        $choicePairs = preg_split("/\s*\\\\n\s*/", $choicesStr);
        $choices = [];
        foreach ($choicePairs as $pair) {
            $a = preg_split("/\s*,\s*/", $pair);
            if (count($a) == 2) {
                $choices[$a[0]] = $a[1];
            } else if (count($a) > 2) {
                $a = explode(",", $pair);
                $b = [];
                for ($i = 1; $i < count($a); $i++) {
                    $b[] = $a[$i];
                }
                $choices[trim($a[0])] = trim(implode(",", $b));
            }
        }
        return $choices;
    }

    private function getRecordChunkSize(int $numFields): int {
        if ($numFields >= self::FIELD_CHUNK_SIZE) {
            return 1;
        } else if ($numFields > 5) {
            return (int) floor(self::FIELD_CHUNK_SIZE / $numFields);
        } else {
            return self::FIELD_CHUNK_SIZE;
        }
    }

    protected $module;
    protected $pid;
    protected $pk;
    protected $redcapDataTable;
}