<?php

class REDCapDataReplacer {
    const TARGET = "redcap_data";
    const QUOTE_ARRAY = ["'", "`", "\""];
    const STRING_REPLACEMENT_PREFIX = "STRINGSTRINGSTRING";
    const STRING_REPLACEMENT_SUFFIX = "CLOSECLOSECLOSE";

    public function __construct($sql, $params) {
        $this->sql = $sql;
        $this->params = $params;
    }

    private function getProjectId() {
        $insertRegexStr = "^\s*INSERT(LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+)?(IGNORE\s+)?(INTO\s+)?".self::TARGET."\s+(PARTITION\s+\([\w\s,]+\)\s+)?";
        $replaceRegex = "/^\s*REPLACE\s+(LOW_PRIORITY\s+|DELAYED\s+)?(INTO\s+)?".self::TARGET."\s+(PARTITION\s+\([\w\s,]+\)\s+)?/i";
        $updateRegex = "/^\s*UPDATE\s+(LOW_PRIORITY\s+)?(IGNORE\s+)?".self::TARGET."\s+/i";
        if (preg_match("/$insertRegexStr\(.*\bproject_id\b.*\)/i", $this->sql)) {
            $end = preg_replace("/$insertRegexStr/i", "", $this->sql);
            return $this->getProjectIdFromColValues($end);
        } else if (preg_match($replaceRegex, $this->sql)) {
            $end = preg_replace($replaceRegex, "", $this->sql);
            return $this->getProjectIdFromColValues($end);
        } else if (preg_match($updateRegex, $this->sql)) {
            $end = preg_replace($updateRegex, "", $this->sql);
            $setBlock = preg_replace("/(WHERE\s.+)?(ORDER\s+BY\s.+)?(LIMIT\s.+)?\s*$/i", "", $end);
            return $this->getProjectIdFromSetBlock($setBlock);
        } else if (preg_match("/['`\"]?\bproject_id['`\"]?\s*=\s*/", $this->sql, $matches)) {
            if ((count($matches) >= 2) && preg_match("/WHERE.+['`\"]project_id['`\"]?\s*=\s*/", $this->sql)) {
                # use first project_id ---> TODO is this a weakness?
                if (preg_match("/['`\"]?project_id['`\"]?\s*=\s*\?/", $this->sql)) {
                    $start = preg_split("/project_id['`\"]?\s*=/", $this->sql)[0];
                    $index = substr_count($start, "?");
                    return $this->params[$index] ?? FALSE;
                } else if (preg_match("/['`\"]?project_id['`\"]?\s*=\s*(['`\"])(.+?)\1/", $this->sql, $matches)) {
                    return $matches[2];
                }
            } else {
                # no project_id in WHERE clause
                return FALSE;
            }
        } else {
            return FALSE;
        }
    }

    private function getProjectIdFromColValues($tail) {
        if (preg_match("/^\((.*project_id.*)\)\s+VALUES?\s*(.+)$/", $tail, $matches)) {
            $colList = $matches[1];
            $fields = preg_split("/\s*,\s*/", preg_replace("/['`\"]/g", "", $colList));
            $projectIdIndex = array_search("project_id", $fields, TRUE);
            if ($projectIdIndex !== FALSE) {
                # TODO -- does this create a weakness by choosing the first project_id?
                # use first line --> don't need to parse multiple lines
                $allValuesString = $matches[2];
                $rowConstructorRemoved = preg_replace("/^ROW/", "", $allValuesString);
                $dataFirst = preg_replace("/^\s*\(/", "", $rowConstructorRemoved);
                $startIndex = 0;
                $endIndex = $startIndex + 1;
                $valuesIndexCount = 0;
                while (($startIndex < strlen($dataFirst)) && ($endIndex < strlen($dataFirst))) {
                    $found = FALSE;
                    $substr = substr($dataFirst, $startIndex, ($endIndex - $startIndex));
                    $value = "";
                    if (preg_match("/^(['`\"])(.+)\1$/", $substr, $valueMatchAry)) {
                        $value = $valueMatchAry[2];
                        $found = TRUE;
                    } else if ($substr == "?") {
                        $questionMarkIndex = substr_count($this->sql, "?");
                        $value = $this->params[$questionMarkIndex] ?? FALSE;
                        $found = TRUE;
                    }
                    if ($found && ($valuesIndexCount == $projectIdIndex)) {
                        return $value;
                    } else if ($found) {
                        # match but wrong index --> move forward
                        $valuesIndexCount++;
                        $startIndex = $endIndex + 1;
                        $endIndex = $startIndex + 1;
                    } else if (in_array($substr, [" ", "\r", "\n", "\t", "(", ")", ","])) {
                        # outside a value because no quotes --> move everything forward
                        $startIndex = $endIndex + 1;
                        $endIndex = $startIndex + 1;
                    } else {
                        $endIndex++;
                    }
                }
            } else {
                return FALSE;
            }
        } else {
            return $this->getProjectIdFromSetBlock($tail);
        }
    }

    private function getProjectIdFromSetBlock($tail) {
        if (preg_match("/SET\s+.+['`\"]?project_id['`\"]?\s*=\s*/i", $tail)) {
            if (preg_match("/['`\"]?project_id['`\"]?\s*=\s*['`\"]?(\d+)['`\"]?/", $tail, $matches)) {
                return $matches[1];
            } else if (preg_match("/['`\"]?project_id['`\"]?\s*=\s*\?/", $tail)) {
                $start = preg_split("/project_id['`\"]?\s*=/", $tail)[0];
                $index = substr_count($start, "?");
                return $this->params[$index] ?? FALSE;
            } else {
                return FALSE;
            }
        } else {
            return FALSE;
        }
    }

    private function getDataTable($project_id) {
        if (method_exists("\Records", "getDataTable")) {
            return \Records::getDataTable($project_id);
        } else {
            return self::TARGET;
        }
    }

    private function replaceAllREDCapData($sql) {
        $project_id = $this->getProjectId();
        if ($project_id) {
            return preg_replace("/\b".self::TARGET."\b/g", $this->getDataTable($project_id), $sql);
        } else {
            return $this->sql;
        }
    }

    # https://dev.mysql.com/doc/refman/8.0/en/sql-data-manipulation-statements.html
    public function adjustREDCapData() {
        if (preg_match("/\b".self::TARGET."\b/", $this->sql) && $this->getProjectId()) {
            $sql = $this->sql;
            $strings = self::getStrings($sql);
            $sql = self::replaceStringsWithCode($sql, $strings);
            $sql = $this->replaceAllREDCapData($sql);
            return self::replaceCodeWithStrings($sql, $strings);
        } else {
            return $this->sql;
        }
    }

    private static function replaceCodeWithStrings($sql, $strings) {
        foreach ($strings as $stringIndex => $string) {
            $sql = str_replace(self::STRING_REPLACEMENT_PREFIX.$stringIndex.self::STRING_REPLACEMENT_SUFFIX, $string, $sql);
        }
        return $sql;
    }

    private static function replaceStringsWithCode($sql, $strings) {
        $i = 0;
        $isInString = FALSE;
        $stringStart = "";
        while ($i < strlen($sql)) {
            $restart = FALSE;
            if (!$isInString && in_array($sql, self::QUOTE_ARRAY)) {
                $stringStart = $sql[$i];
                $isInString = TRUE;
            } else if ($isInString && ($stringStart == $sql[$i])) {
                $isInString = FALSE;
                $stringStart = "";
            }
            if ($isInString) {
                $substr = substr($sql, $i);
                foreach ($strings as $stringIndex => $string) {
                    $stringIndexMatch = strpos($string, $substr, TRUE);
                    if ($stringIndexMatch === 0) {
                        $sql = substr_replace($sql, self::STRING_REPLACEMENT_PREFIX . $stringIndex . self::STRING_REPLACEMENT_SUFFIX, $i, strlen($substr));
                        $restart = TRUE;
                    }
                }
            }
            if ($restart) {
                $isInString = FALSE;
                $stringStart = "";
                $i = 0;
            } else {
                $i++;
            }
        }
        return $sql;
    }

    private static function getStrings($sql) {
        $isInString = FALSE;
        $stringStart = "";
        $startI = 0;
        $strings = [];
        $isPriorEscaped = FALSE;
        for ($i = 0; $i < strlen($sql); $i++) {
            if (!$isInString && in_array($sql, self::QUOTE_ARRAY)) {
                $stringStart = $sql[$i];
                $isInString = TRUE;
                $startI = $i;
            } else if ($isInString && !$isPriorEscaped && ($stringStart == $sql[$i])) {
                $isInString = FALSE;
                $stringStart = "";
                $strings[] = substr($sql, $startI, ($i - $startI));
                $startI = 0;
            }
            if ($sql[$i] == "\\") {
                $isPriorEscaped = TRUE;
            } else {
                $isPriorEscaped = FALSE;
            }
        }
        return $strings;
    }

    protected $sql;
    protected $params;
}