<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');


class PositionChange {
    public static function getSelectRecord($filterOutCopiedRecords = FALSE) {
        global $token, $server;

        $records = Download::recordIds($token, $server);
        if ($filterOutCopiedRecords && method_exists("Application", "filterOutCopiedRecords")) {
            $records = Application::filterOutCopiedRecords($records);
        }
        $names = Download::names($token, $server);
        $page = basename($_SERVER['PHP_SELF'] ?? "");

        $html = "Record: <select style='width: 100%;' id='refreshRecord' onchange='refreshForRecord(\"$page\");'><option value=''>---SELECT---</option>";
        foreach ($records as $record) {
            $name = $names[$record];
            $selected = "";
            if (isset($_GET['record']) && ($_GET['record'] == $record)) {
                $selected = " SELECTED";
            }
            $html .= "<option value='$record'$selected>$record: $name</option>";
        }
        $html .= "</select>";
        return $html;
    }

    public static function getSearch() {
        return "Last/Full Name:<br><input id='search' type='text' style='width: 100%;'><br><div style='width: 100%; color: #ff0000;' id='searchDiv'></div>";
    }
}