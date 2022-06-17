<?php

namespace Vanderbilt\CareerDevLibrary;

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
        if ($proxyIP && $proxyPort && is_numeric($proxyPort)&& $proxyPassword && $proxyUsername) {
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

    public static function downloadURLWithPOST($url, $postdata = [], $pid = NULL, $addlOpts = [], $autoRetriesLeft = 3, $longRetriesLeft = 2) {
        if (self::isCaughtInBadLoop()) {
            throw new \Exception("In bad loop with $url");
        }
        if (!Application::isLocalhost()) {
            Application::log("Contacting $url", $pid);
        }
        if (!empty($postdata)) {
            Application::log("Posting ".REDCapManagement::json_encode_with_spaces($postdata)." to $url", $pid);
        }
        $defaultOpts = self::getDefaultCURLOpts();
        $time1 = microtime();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        foreach ($defaultOpts as $opt => $value) {
            if (!isset($addlOpts[$opt])) {
                curl_setopt($ch, $opt, $value);
            }
        }
        foreach ($addlOpts as $opt => $value) {
            curl_setopt($ch, $opt, $value);
        }
        self::applyProxyIfExists($ch, $pid);
        if (!empty($postdata)) {
            if (is_string($postdata)) {
                $json = $postdata;
            } else {
                $json = json_encode($postdata);
            }
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
            ]);
        }

        $data = (string) curl_exec($ch);
        $resp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (self::isGoodResponse($resp)) {
            self::resetUnsuccessfulCount();
        } else {
            self::$numUnsuccessfulDownloadInARow++;
        }
        if(curl_errno($ch)){
            Application::log(curl_error($ch), $pid);
            if ($autoRetriesLeft > 0) {
                sleep(30);
                Application::log("Retrying ($autoRetriesLeft left)...", $pid);
                list($resp, $data) = self::downloadURLWithPOST($url, $postdata, $pid, $addlOpts, $autoRetriesLeft - 1, $longRetriesLeft);
            } else if ($longRetriesLeft > 0) {
                sleep(300);
                Application::log("Retrying ($longRetriesLeft long retries left)...", $pid);
                list($resp, $data) = self::downloadURLWithPOST($url, $postdata, $pid, $addlOpts, 0, $longRetriesLeft - 1);
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
        if (Application::isVanderbilt()) {
            Application::log("$url Response code $resp; ".strlen($data)." bytes".$timeStmt, $pid);
            if (strlen($data) < 500) {
                Application::log("Result: ".$data, $pid);
            }
        }
        return [$resp, $data];
    }

    private static function getDefaultCURLOpts() {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_FRESH_CONNECT => 1,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => Upload::isProductionServer(),
        ];
    }

    public static function isValidURL($url) {
        return self::isGoodURL($url);
    }

    public static function isGoodURL($url) {
        $ch = curl_init();
        $defaultOpts = self::getDefaultCURLOpts();
        curl_setopt($ch, CURLOPT_URL, $url);
        foreach ($defaultOpts as $opt => $value) {
            curl_setopt($ch, $opt, $value);
        }
        curl_exec($ch);
        $resp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return self::isGoodResponse($resp);
    }

    public static function isGoodResponse($resp) {
        return (($resp >= 200) && ($resp < 300));
    }

    public static function downloadURL($url, $pid = NULL, $addlOpts = [], $autoRetriesLeft = 3) {
        return self::downloadURLWithPOST($url, [], $pid, $addlOpts, $autoRetriesLeft);
    }

    public static function getParametersAsHiddenInputs($url) {
        $params = self::getParameters($url);
        $html = [];
        foreach ($params as $key => $value) {
            $value = urldecode($value);
            $html[] = "<input type='hidden' name='$key' value='$value'>";
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

    public static function makeHiddenInputs($params) {
        $items = [];
        foreach ($params as $key => $value) {
            $html = "<input type='hidden' id='$key' name='$key'";
            if ($value !== "") {
                $html .= " value='$value'";
            }
            $html .= ">";
            $items[] = $html;
        }
        return implode("", $items);
    }

    public static function splitURL($fullURL) {
        list($url, $paramList) = explode("?", $fullURL);
        $pairs = explode("&", $paramList);
        $params = [];
        foreach ($pairs as $pair) {
            $items = explode("=", $pair);
            if (count($items) == 2) {
                $params[$items[0]] = urldecode($items[1]);
            } else if (count($items) == 1) {
                $params[$items[0]] = "";
            } else {
                throw new \Exception("This should never happen. A GET parameter has ".count($items)." items.");
            }
        }
        return [$url, $params];
    }

    private static $numUnsuccessfulDownloadInARow = 0;

}