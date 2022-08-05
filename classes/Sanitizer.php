<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Sanitizer {
    public static function sanitizeJSON($str) {
        /**
         * @psalm-taint-escape html
         * @psalm-taint-escape has_quotes
         */

        # unable to do so now
        return $str;
    }

    public static function sanitizeDate($date) {
        $date = self::sanitize($date);
        if (DateManagement::isDate($date)) {
            return $date;
        } else {
            throw new \Exception("Invalid date $date");
        }
    }

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

    public static function sanitizeArray($ary, $stripHTML = TRUE) {
        $encodeQuotes = FALSE;
        if (is_array($ary)) {
            /**
             * @psalm-taint-escape html
             * @psalm-taint-escape has_quotes
             */
            $newAry = [];
            foreach ($ary as $key => $value) {
                if ($stripHTML) {
                    $key = self::sanitize($key);
                } else {
                    $key = self::sanitizeWithoutStrippingHTML($key, $encodeQuotes);
                }
                if (is_array($value)) {
                    $value = self::sanitizeArray($value, $stripHTML);
                } else {
                    if ($stripHTML) {
                        $value = self::sanitize($value);
                    } else {
                        $value = self::sanitizeWithoutStrippingHTML($value, $encodeQuotes);
                    }
                }
                $newAry[$key] = $value;
            }
            return $newAry;
        } else {
            if ($stripHTML) {
                return self::sanitize($ary);
            } else {
                return self::sanitizeWithoutStrippingHTML($ary, $encodeQuotes);
            }
        }
    }

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

    public static function sanitizeCohort($cohortName) {
        return Cohorts::sanitize($cohortName);
    }

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
    public static function getSanitizedRecord($requestedRecord, $records) {
        foreach ($records as $r) {
            if ($r == $requestedRecord) {
                return $r;
            }
        }
        return "";
    }


}
