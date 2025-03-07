<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(__DIR__ . '/ClassLoader.php');

class URLManagement {

    public static function isValidIP($str) {
        if (preg_match("/^\b(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\b$/", $str)) {
            # numeric
            return TRUE;
        }
        if (preg_match("/^\b\w\.\w+\b$/", $str)) {
            # word
            return TRUE;
        }
        return FALSE;
    }

    public static function applyProxyIfExists(&$ch, $pid) {
        $proxyIP = Application::getSetting("proxy-ip", $pid);
        $proxyPort = Application::getSetting("proxy-port", $pid);
        $proxyUsername = Application::getSetting("proxy-user", $pid);
        $proxyPassword = Application::getSetting("proxy-pass", $pid);
        if ($proxyIP && $proxyPort && is_numeric($proxyPort)) {
            $proxyOpts = [
                CURLOPT_HTTPPROXYTUNNEL => 1,
                CURLOPT_PROXY => $proxyIP,
                CURLOPT_PROXYPORT => $proxyPort,
            ];
            if ($proxyUsername && $proxyPassword) {
                $proxyOpts[CURLOPT_PROXYUSERPWD] = "$proxyUsername:$proxyPassword";
            }
            foreach ($proxyOpts as $opt => $value) {
                curl_setopt($ch, $opt, $value);
            }
        }
    }

    public static function resetUnsuccessfulCount() {
        self::$numUnsuccessfulDownloadInARow = 0;
    }

    public static function isCaughtInBadLoop() {
        return (self::$numUnsuccessfulDownloadInARow > 100);
    }

    public static function isCurrentServer($url) {
        $serverLower = strtolower(SERVER_NAME);
        $urlLower = strtolower($url);
        return (strpos($urlLower, $serverLower) !== FALSE);
    }

    public static function downloadURLWithPOST($url, $postdata = [], $pid = NULL, $addlOpts = [], $autoRetriesLeft = 3, $longRetriesLeft = 2, $defaultFormat = "json") {
        if (self::isCaughtInBadLoop()) {
            throw new \Exception("In bad loop with $url and POST ".REDCapManagement::json_encode_with_spaces($postdata));
        }
        if (!Application::isLocalhost()) {
            Application::log("Contacting $url", $pid);
        }
        if (!empty($postdata) && !isset($postdata['redcap_csrf_token']) && self::isCurrentServer($url)) {
            Application::log("Adding CSRF Token to POST", $pid);
            $postdata['redcap_csrf_token'] = Application::generateCSRFToken();
        }
        if (!empty($postdata)) {
            Application::log("Posting ".REDCapManagement::json_encode_with_spaces($postdata)." to $url", $pid);
        }
        $url = Sanitizer::sanitizeURL($url);
        $url = REDCapManagement::changeSlantedQuotes($url);
        if (!$url) {
            throw new \Exception("Invalid URL!");
        }
        $defaultOpts = self::getDefaultCURLOpts($pid);
        $time1 = microtime();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        foreach ($defaultOpts as $opt => $value) {
            if (!isset($addlOpts[$opt])) {
                curl_setopt($ch, $opt, $value);
            }
        }
        foreach ($addlOpts as $opt => $value) {
            if ($opt != CURLOPT_HTTPHEADER) {
                curl_setopt($ch, $opt, $value);
            }
        }
        self::applyProxyIfExists($ch, $pid);
        if (!empty($postdata)) {
            $postdata = REDCapManagement::changeSlantedQuotesInArray($postdata);
            if ($defaultFormat == "json") {
                if (is_string($postdata)) {
                    $json = $postdata;
                } else {
                    $json = json_encode($postdata);
                }
                $json = Sanitizer::sanitizeJSON($json);
                if (!$json) {
                    throw new \Exception("Invalid POST parameters!");
                }
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($json),
                    "Expect:",
                ], $addlOpts[CURLOPT_HTTPHEADER] ?? []));
            } else if ($defaultFormat == "urlencoded") {
                if (!is_array($postdata)) {
                    throw new \Exception("Your POST data must be passed as an array!");
                } else {
                    $postPairs = [];
                    foreach ($postdata as $key => $val) {
                        $postPairs[] = urlencode($key)."=".urlencode($val);
                    }
                    $postStr = implode("&", $postPairs);
                }
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postStr);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
                    'Content-Type: application/x-www-form-urlencoded',
                    'Content-Length: ' . strlen($postStr),
                    "Expect:",
                ], $addlOpts[CURLOPT_HTTPHEADER] ?? []));
            } else {
                throw new \Exception("Unknown format $defaultFormat");
            }
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(["Expect:"], $addlOpts[CURLOPT_HTTPHEADER] ?? []));
        }

        $data = (string) curl_exec($ch);
        $resp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (self::isGoodResponse($resp)) {
            self::resetUnsuccessfulCount();
        } else {
            self::$numUnsuccessfulDownloadInARow++;
        }
        if(curl_errno($ch)){
            Application::log("Error number ".curl_errno($ch)." cURL Error: ".curl_error($ch), $pid);
            if ($autoRetriesLeft > 0) {
                sleep(30);
                Application::log("Retrying ($autoRetriesLeft left)...", $pid);
                list($resp, $data) = self::downloadURLWithPOST($url, $postdata, $pid, $addlOpts, $autoRetriesLeft - 1, $longRetriesLeft, $defaultFormat);
            } else if ($longRetriesLeft > 0) {
                sleep(300);
                Application::log("Retrying ($longRetriesLeft long retries left)...", $pid);
                list($resp, $data) = self::downloadURLWithPOST($url, $postdata, $pid, $addlOpts, 0, $longRetriesLeft - 1, $defaultFormat);
            } else {
                Application::log("Error: ".curl_error($ch), $pid);
                throw new \Exception(curl_error($ch));
            }
        }
        curl_close($ch);
        $time2 = microtime();
        $timeStmt = "";
        if (is_numeric($time1) && is_numeric($time2)) {
            $timeStmt = " in ".(($time2 - $time1) / 1000)." seconds";
        }
        Application::log("$url Response code $resp; ".strlen($data)." bytes".$timeStmt, $pid);
        if (Application::isVanderbilt()) {
            if (strlen($data) < 1000) {
                Application::log("Result: ".$data, $pid);
            }
        }
        return [$resp, $data];
    }

    private static function getDefaultCURLOpts($pid) {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_FRESH_CONNECT => 1,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => FALSE,
        ];
    }

    public static function isValidURL($url) {
        return self::isGoodURL($url);
    }

    public static function makeURL($url) {
        try {
            $sanitizedUrl = Sanitizer::sanitizeURL($url);
            return $sanitizedUrl;
        } catch (\Exception $e) {
            if (!preg_match("/^http/i", $url)) {
                $newUrl = "https://".$url;
                return self::makeURL($newUrl);
            } else {
                return "";
            }
        }
    }

    public static function getDomain($server) {
        if (preg_match("/\.([A-Za-z]+\.[A-Za-z]+)\//", $server, $matches) && (count($matches) >= 2)) {
            return $matches[1];
        } else {
            $withoutProtocol = preg_replace("/^https?:\/\//i", "", $server);
            $nodes = preg_split("/\//", $withoutProtocol);
            return $nodes[0];
        }
    }

    public static function isGoodURL($url) {
        $url = Sanitizer::sanitizeURL($url);
        if (!$url) {
            throw new \Exception("Invalid URL");
        }
        $headers = get_headers($url);
        return isset($headers[0]) && (strpos($headers[0],'200')!==false);
    }

    public static function isGoodResponse($resp) {
        return (($resp >= 200) && ($resp < 300));
    }

    public static function downloadURL($url, $pid = NULL, $addlOpts = [], $autoRetriesLeft = 3) {
        return self::downloadURLWithPOST($url, [], $pid, $addlOpts, $autoRetriesLeft);
    }

    public static function getParametersAsHiddenInputs($url, $excludeParams = []) {
        $params = self::getParameters($url);
        $html = [];
        foreach ($params as $key => $value) {
            if (!in_array($key, $excludeParams)) {
                $value = urldecode($value);
                $html[] = "<input type='hidden' name='$key' value='$value'>";
            }
        }
        return implode("\n", $html);
    }

    public static function getParameters($url) {
        $nodes = explode("?", $url);
        $params = [];
        if (count($nodes) > 0) {
            $pairs = explode("&", $nodes[1]);
            foreach ($pairs as $pair) {
                if ($pair) {
                    $pairNodes = explode("=", $pair);
                    if (count($pairNodes) >= 2) {
                        $params[$pairNodes[0]] = $pairNodes[1];
                    } else {
                        $params[$pairNodes[0]] = "";
                    }
                }
            }
        }
        return $params;
    }

    public static function getPage($url) {
        $nodes = explode("?", $url);
        return $nodes[0];
    }

    public static function fillInLinks($text) {
        return preg_replace(
            '/((https?|ftp):\/\/(\S*?\.\S*?))([\s)\[\]{},;"\':<]|\.\s|$)/i',
            "<a href=\"$1\" target=\"_blank\">$3</a>$4",
            $text
        );
    }

    public static function makeHiddenInputs($params, $noID = FALSE) {
        $items = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $key = $key."[]";
                $html = "";
                foreach ($value as $v) {
                    $html .= "<input type='hidden' name='$key'";
                    if ($v !== "") {
                        $html .= " value='$v'";
                    }
                    $html .= " />";
                }
            } else {
                $key = preg_replace("/\[\d+\]/", "[]", $key);
                $html = "<input type='hidden' name='$key'";
                if (!$noID) {
                    $html .= "id='$key' ";
                }
                if ($value !== "") {
                    $html .= " value='$value'";
                }
                $html .= " />";
            }
            $items[] = $html;
        }
        return implode("", $items);
    }

    public static function splitURL($fullURL) {
        list($url, $paramList) = explode("?", $fullURL);
        $params = [];
        parse_str($paramList, $params);
        return [$url, $params];
    }

    public static function emulateBrowser($url, $post = [], $pid = NULL) {
        # updated in 2020
        $agent= 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.198 Safari/537.36';
        $options = [
            CURLOPT_SSL_VERIFYPEER => FALSE,
            CURLOPT_USERAGENT => $agent,
        ];
        return self::downloadURLWithPOST($url, $post, $pid, $options, 3, 2, "urlencoded");
    }

    private static $numUnsuccessfulDownloadInARow = 0;

}