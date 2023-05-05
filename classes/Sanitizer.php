<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Sanitizer {
    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeJSON($str) {
        $data = json_decode($str, TRUE);
        if ($data) {
            $data = self::sanitizeRecursive($data, FALSE, FALSE);
            return json_encode($data);
        }
        return "";
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeREDCapData($data) {
        $data = self::sanitizeArray($data, FALSE, FALSE);
        for ($i = 0; $i < count($data); $i++) {
            if (isset($data[$i]['record_id'])) {
                $data[$i]['record_id'] = self::sanitizeInteger($data[$i]['record_id']);
            }
        }
        return $data;
    }

    public static function repetitivelyDecodeHTML($entity, $depth = 1) {
        if (is_array($entity)) {
            foreach ($entity as $key => $value) {
                $entity[$key] = self::repetitivelyDecodeHTML($value);
            }
            return $entity;
        } else {
            $original = $entity;
            if (preg_match("/&[A-Za-z\d#]+,/", $entity)) {
                $semicolonEntity = preg_replace("/(&[A-Za-z\d#]+),/", '$1;', $entity);
                $decoded = self::decodeHTML($semicolonEntity);
            } else {
                $decoded = self::decodeHTML($entity);
            }
            $maxDepth = 50;
            if (($original == $decoded) || ($depth > $maxDepth)) {
                return $decoded;
            } else {
                return self::repetitivelyDecodeHTML($decoded, $depth + 1);
            }
        }
    }

    public static function sanitizeInteger($int) {
        if (filter_var($int, FILTER_VALIDATE_INT) !== FALSE) {
            $stringVersion = self::sanitize($int);
            return (int) $stringVersion;
        } else {
            return "";
        }
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeURL($url) {
        $url = filter_var($url, FILTER_SANITIZE_URL);
        if (!$url) {
            throw new \Exception("Invalid URL!");
        } else {
            return $url;
        }
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizePid($pid) {
        $pid = filter_var($pid, FILTER_VALIDATE_INT);
        $pid = self::sanitize($pid);
        return $pid;
    }

    /**
     * @psalm-taint-specialize
     */
    private static function sanitizeRecursive($datum, $encodeQuotes = TRUE, $stripHTML = TRUE) {
        if (is_array($datum)) {
            $newData = [];
            foreach ($datum as $key => $value) {
                if ($encodeQuotes && $stripHTML) {
                    $key = self::sanitize($key);
                } else if (!$stripHTML) {
                    $key = self::sanitizeWithoutStrippingHTML($key, $encodeQuotes);
                } else {
                    $key = self::sanitizeWithoutChangingQuotes($key);
                }
                $newData[$key] = self::sanitizeRecursive($value, $encodeQuotes, $stripHTML);
            }
            return $newData;
        } else if ($encodeQuotes && $stripHTML) {
            return self::sanitize($datum);
        } else if (!$stripHTML) {
            return self::sanitizeWithoutStrippingHTML($datum, $encodeQuotes);
        } else {
            return self::sanitizeWithoutChangingQuotes($datum);
        }
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeDate($date) {
        $date = self::sanitize($date);
        if (!$date) {
            return "";
        }
        if (DateManagement::isDate($date)) {
            return $date;
        } else {
            throw new \Exception("Invalid date $date");
        }
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeWithoutChangingQuotes($str) {
        if (is_numeric($str)) {
            $str = (string) $str;
        }
        if (!is_string($str)) {
            return "";
        }
        $str = htmlspecialchars($str, ENT_QUOTES);
        return htmlentities($str, ENT_NOQUOTES);
    }

    /**
     * @psalm-taint-specialize
     */
    public static function decodeHTML($entity) {
        if (is_array($entity)) {
            foreach ($entity as $key => $value) {
                $key = self::decodeHTML($key);
                $value = self::decodeHTML($value);
                $entity[$key] = $value;
            }
            return $entity;
        } else {
            return html_entity_decode($entity);
        }
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeArray($ary, $stripHTML = TRUE, $encodeQuotes = TRUE) {
        if (is_array($ary)) {
            $newAry = [];
            if (REDCapManagement::isAssoc($ary)) {
                foreach ($ary as $key => $value) {
                    if ($stripHTML && $encodeQuotes) {
                        $key = self::sanitize($key);
                    } else if ($stripHTML && !$encodeQuotes) {
                        $key = self::sanitizeWithoutChangingQuotes($key);
                    } else {
                        $key = self::sanitizeWithoutStrippingHTML($key, $encodeQuotes);
                    }
                    if (is_array($value)) {
                        $value = self::sanitizeArray($value, $stripHTML, $encodeQuotes);
                    } else if ($stripHTML && $encodeQuotes) {
                        $value = self::sanitize($value);
                    } else if ($stripHTML && !$encodeQuotes) {
                        $value = self::sanitizeWithoutChangingQuotes($value);
                    } else {
                        $value = self::sanitizeWithoutStrippingHTML($value, $encodeQuotes);
                    }
                    $newAry[$key] = $value;
                }
            } else {
                foreach ($ary as $value) {
                    if (is_array($value)) {
                        $value = self::sanitizeArray($value, $stripHTML, $encodeQuotes);
                    } else if ($stripHTML && $encodeQuotes) {
                        $value = self::sanitize($value);
                    } else if ($stripHTML && !$encodeQuotes) {
                        $value = self::sanitizeWithoutChangingQuotes($value);
                    } else {
                        $value = self::sanitizeWithoutStrippingHTML($value, $encodeQuotes);
                    }
                    $newAry[] = $value;
                }
            }
            return $newAry;
        } else if ($stripHTML && $encodeQuotes) {
            return self::sanitize($ary);
        } else if ($stripHTML && !$encodeQuotes) {
            return self::sanitizeWithoutChangingQuotes($ary);
        } else {
            return self::sanitizeWithoutStrippingHTML($ary, $encodeQuotes);
        }
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeWithoutStrippingHTML($str, $encodeQuotes = TRUE) {
        $str = preg_replace("/<script[^>]*>/i", '', $str);
        $str = preg_replace("/<\/script[^>]*>/i", '', $str);
        if ($encodeQuotes) {
            $str = htmlentities($str, ENT_QUOTES);
        }
        return $str;
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeCohort($cohortName, $pid = NULL) {
        if (!$pid) {
            $pid = self::sanitizePid($_GET['pid'] ?? "");
        }
        if ($pid) {
            return Cohorts::sanitize($cohortName, $pid);
        } else {
            return "";
        }
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitize($origStr) {
        if (REDCapManagement::isValidToken($origStr)) {
            $module = Application::getModule();
            if (method_exists($module, "sanitizeAPIToken")) {
                return $module->sanitizeAPIToken($origStr);
            }
        }
        if (is_numeric($origStr)) {
            $origStr = (string) $origStr;
        }
        if (!is_string($origStr)) {
            return "";
        }
        $str = htmlspecialchars($origStr, ENT_QUOTES);
        $str = htmlentities($str, ENT_QUOTES);
        return $str;
    }

    # requestedRecord is from GET/POST
    /**
     * @psalm-taint-specialize
     */
    public static function getSanitizedRecord($requestedRecord, $records) {
        foreach ($records as $r) {
            if ($r == $requestedRecord) {
                return self::sanitizeInteger($r);
            }
        }
        return "";
    }

    public static function sanitizeLDAP($str) {
        // ldap_escape()
        return self::sanitize($str);
    }


}
