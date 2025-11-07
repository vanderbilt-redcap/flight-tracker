<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Links;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
$records = Download::records($token, $server);
$names = Download::names($token, $server);
$choices = REDCapManagement::getChoices($metadata);
$possibleRoles = [];
foreach ([5, 6, 7] as $roleIndex) {
    $roleName = $choices['custom_role'][$roleIndex];
    $possibleRoles[$roleIndex] = $roleName;
}
$roles = getRoles($token, $server, array_keys($possibleRoles), $records);

echo "<script>var possibleRoles = ".json_encode($possibleRoles)."; var allRecords = ".json_encode($records)."</script>";
echo "<table class='centered bordered'>";
echo "<tr><th>Name</th><th>Roles</th><th style='min-width: 350px;'></th></tr>";
foreach ($roles as $recordId => $byGrant) {
    $name = $names[$recordId];
    echo "<tr>";
    echo "<th>".Links::makeRecordHomeLink($pid, $recordId, "$recordId: $name")."</th>";
    $roleList = [];
    foreach ($possibleRoles as $roleIndex => $roleName) {
        if (isset($byGrant[$roleIndex])) {
            $links = [];
            foreach ($byGrant[$roleIndex] as $item => $instance) {
                $links[] = Links::makeCustomGrantLink($pid, $recordId, $event_id, $item, $instance);
            }
            $grantNumberList = REDCapManagement::makeConjunction($links);
            $roleList[] = "<b>".$roleName."</b>: ".$grantNumberList;
        }
    }
    if (empty($roleList)) {
        $roleList[] = "No Existing Appointments";
    }
    echo "<td id='existingGrants_$recordId'>".implode("<br>", $roleList)."</td>";
    echo "<td id='addGrant_$recordId'><button class='smaller' onclick='fillNewGrantInterface(\"$recordId\", possibleRoles, \"existingGrants_$recordId\", \"addGrant_$recordId\", allRecords); return false;'>Add Appointment</button></td>";
    echo "</tr>\n";
}
echo "</table>\n";




# indexed by recordId, role index, list of grant appointments
function getRoles($token, $server, $possibleRows, $records) {
    $pullSize = 50;
    $roles = [];
    for ($i = 0; $i < count($records); $i += $pullSize) {
        $recordsToPull = [];
        for ($j = $i; ($j < count($records)) && ($j < $i + $pullSize); $j++) {
            $recordsToPull[] = $records[$j];
        }
        $trainingGrantsData = REDCapManagement::indexREDCapData(Download::trainingGrants($token, $server, ["record_id", "custom_role", "custom_number", "custom_start", "custom_end"], $possibleRows, $recordsToPull));
        foreach ($recordsToPull as $recordId) {
            $roles[$recordId] = [];
            $redcapData = $trainingGrantsData[$recordId];
            foreach ($redcapData as $row) {
                if ($row['custom_role'] && !isset($roles[$recordId][$row['custom_role']])) {
                    $roles[$recordId][$row['custom_role']] = [];
                }
                $dates = "";
                if ($row['custom_start']) {
                    $start = REDCapManagement::YMD2MDY($row['custom_start']);
                    if ($row['custom_end']) {
                        $end = REDCapManagement::YMD2MDY($row['custom_end']);
                        $dates = " ($start - $end)";
                    } else {
                        $dates = " (started $start)";
                    }
                } else if ($row['custom_end']) {
                    $end = REDCapManagement::YMD2MDY($row['custom_end']);
                    $dates = " (ended $end)";
                }
                $roles[$recordId][$row['custom_role']][$row['custom_number'].$dates] = $row['redcap_repeat_instance'];
            }
        }
    }
    return $roles;
}

