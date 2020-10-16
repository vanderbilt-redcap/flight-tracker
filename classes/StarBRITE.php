<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/REDCapManagement.php");

class StarBRITE {
    static function getServer() {
        $server = "starbrite.app.vumc.org";
        if (SERVER_NAME == "redcaptest.vanderbilt.edu") {
            $server = "starbritetest.app.vumc.org";
        }
        return $server;
    }

    static function accessSRI($resourcePath, $getParams) {
        include "/app001/credentials/con_redcap_ldap_user.php";
        $resourcePath = preg_replace("/^\//", "", $resourcePath);
        $resourcePath = preg_replace("/\/$/", "", $resourcePath);
        $server = self::getServer();
        $url = "https://$server/s/sri/api/$resourcePath";
        $url .= '/' . implode('/', array_map('urlencode', $getParams));
        $opts = [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTPHEADER => [ 'Content-Type: application/json' ],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $ldapuser . ':' . $ldappass,
            CURLOPT_CUSTOMREQUEST => "GET",
        ];
        list($resp, $output) = REDCapManagement::downloadURL($url, $opts);
        return json_decode($output, TRUE);
    }

    static function dataForUserid($userid) {
        return self::accessSRI("project/vunet/", ["coeus", $userid]);
    }
}
