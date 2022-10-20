<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Sanitizer {
    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeJSON($str) {
        /**
         * @psalm-taint-escape html
         * @psalm-taint-escape has_quotes
         */

        $data = json_decode($str, TRUE);
        if ($data) {
            $data = self::sanitizeRecursive($data);
            return json_encode($data);
        }
        return "";
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeREDCapData($data) {
        $data = self::sanitizeArray($data, FALSE);
        for ($i = 0; $i < count($data); $i++) {
            if (isset($data[$i]['record_id'])) {
                $data[$i]['record_id'] = self::sanitizeInteger($data[$i]['record_id']);
            }
        }
        return $data;
    }

    public static function sanitizeInteger($int) {
        /**
         * @psalm-taint-escape header
         */
        if (filter_var($int, FILTER_VALIDATE_INT) !== FALSE) {
            return self::sanitize($int);
        } else {
            return "";
        }
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeURL($url) {
        /**
         * @psalm-taint-escape html
         * @psalm-taint-escape has_quotes
         * @psalm-taint-escape ssrf
         * @psalm-taint-escape header
         */
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
    private static function sanitizeRecursive($datum) {
        if (is_array($datum)) {
            $newData = [];
            foreach ($datum as $key => $value) {
                $key = self::sanitize($key);
                $newData[$key] = self::sanitizeRecursive($value);
            }
            return $newData;
        } else {
            return self::sanitize($datum);
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
        /**
         * @psalm-taint-escape html
         * @psalm-taint-escape has_quotes
         */
        $str = preg_replace("/<[^>]+>/", '', $str);
        return htmlentities($str);
    }

    /**
     * @psalm-taint-specialize
     */
    public static function sanitizeArray($ary, $stripHTML = TRUE, $encodeQuotes = TRUE) {
        if (is_array($ary)) {
            /**
             * @psalm-taint-escape html
             * @psalm-taint-escape has_quotes
             */
            $newAry = [];
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
        /**
         * @psalm-taint-escape html
         * @psalm-taint-escape has_quotes
         */
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
    public static function sanitizeCohort($cohortName) {
        return Cohorts::sanitize($cohortName);
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
        /**
         * @psalm-taint-escape html
         */
        $str = preg_replace("/<[^>]+>/", '', $origStr);
        /**
         * @psalm-taint-escape has_quotes
         */
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


}
