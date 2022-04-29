<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class StarBRITE {
    static function getServer() {
        $server = "starbrite.app.vumc.org";
        if (SERVER_NAME == "redcaptest.vanderbilt.edu") {
            $server = "starbritetest.app.vumc.org";
        }
        return $server;
    }

    static function getCOEUSNodeValue($order, $item) {
        foreach ($order as $awardField) {
            if ($item[$awardField]) {
                return $item[$awardField];
            }
        }
        return "";
    }

    static function makeLabelIntoField($label) {
        return REDCapManagement::makeHTMLId(strtolower($label));
    }

    static function getCOEUSCollabs($userid, $awardUsers) {
        $collabs = [];
        foreach ($awardUsers as $user) {
            if ($user['vunet'] != $userid) {
                try {
                    $name = LDAP::getName($user['vunet']);
                } catch (\Exception $e) {
                    Application::log("ERROR: ".$e->getMessage());
                    $name = "";
                }
                if ($name) {
                    $collabs[] = $name." (".$user['vunet']."; ".$user['role'].")";
                } else {
                    $collabs[] = $user['vunet']." (".$user['role'].")";
                }
            }
        }
        return implode(", ", $collabs);
    }

    static function getCOEUSUser($userid, $awardUsers, $roleChoices) {
        foreach ($awardUsers as $user) {
            if ($user['vunet'] == $userid) {
                foreach ($roleChoices as $idx => $label) {
                    if ($label == $user['role']) {
                        return $idx;
                    }
                }
            }
        }
        return "";
    }

    static function formatForUpload($award, $userid, $instance, $recordId, $choices) {
        $prefix = "coeus2_";
        $instrument = "coeus2";

        $id = $award['id'];
        $altId = $award['altId'];
        $title = $award['title'];
        $role = self::getCOEUSUser($userid, $award["users"], $choices[$prefix . 'role']);
        $collabs = self::getCOEUSCollabs($userid, $award["users"]);
        $uploadRow = [
            "record_id" => $recordId,
            "redcap_repeat_instrument" => $instrument,
            "redcap_repeat_instance" => $instance,
            "coeus2_last_update" => date("Y-m-d"),
            "coeus2_complete" => "2",
            $prefix . "id" => $id,
            $prefix . "altid" => $altId,
            $prefix . "role" => $role,
            $prefix . "collaborators" => $collabs,
            $prefix . "title" => $title,
        ];
        foreach ($award['blocks'] as $item) {
            $field = $prefix . self::makeLabelIntoField($item['label']);
            $order = ["date", "content", "description"];
            $uploadRow[$field] = self::getCOEUSNodeValue($order, $item);
        }
        foreach ($award['details'] as $item) {
            $field = $prefix . self::makeLabelIntoField($item['label']);
            $order = ["content", "description"];
            $uploadRow[$field] = REDCapManagement::convertDollarsToNumber(self::getCOEUSNodeValue($order, $item));
        }
        return $uploadRow;
    }

    static function accessSRI($resourcePath, $getParams, $pid) {
        $filename = Application::getCredentialsDir()."/con_redcap_ldap_user.php";
        $ldapuser = "";
        $ldappass = "";
        if (file_exists($filename)) {
            include $filename;
            $resourcePath = preg_replace("/^\//", "", $resourcePath);
            $resourcePath = preg_replace("/\/$/", "", $resourcePath);
            $server = self::getServer();
            $url = "https://$server/s/sri/api/$resourcePath";
            $url .= '/' . implode('/', array_map('urlencode', $getParams));
            $opts = [
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_SSL_VERIFYPEER => Upload::isProductionServer(),
                CURLOPT_HTTPHEADER => [ 'Content-Type: application/json' ],
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $ldapuser . ':' . $ldappass,
                CURLOPT_CUSTOMREQUEST => "GET",
            ];
            list($resp, $output) = REDCapManagement::downloadURL($url, $pid, $opts);
            return json_decode($output, TRUE);
        }
        return [];
    }

    static function dataForUserid($userid, $pid) {
        return self::accessSRI("project/vunet/", ["coeus", $userid], $pid);
    }
}
