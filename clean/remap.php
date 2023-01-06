<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(__DIR__ . '/../classes/ClassLoader.php');

$recordIds = Download::recordIds($token, $server);
$tmpRecordId = 1000;
$maxRecordId = max($recordIds);
while ($maxRecordId > $tmpRecordId) {
    $tmpRecordId *= 10;
}

if ($_POST) {
    $toDelete = [];
    $toChange = [];
    foreach ($_POST as $key => $value) {
        if (preg_match("/^record_\d+$/", $key) && $value) {
            $oldRecordId = preg_replace("/^record_/", "", $key);
            $newRecordId = $value;
            if ($oldRecordId != $newRecordId) {
                $newTmpRecordId = $tmpRecordId + $newRecordId;
                Upload::copyRecord($token, $server, $oldRecordId, $newTmpRecordId);
                $toDelete[] = $oldRecordId;
                $toChange[$newTmpRecordId] = $newRecordId;
            }
        }
    }
    if (empty($toDelete) && empty($toChange)) {
        echo "<p class='centered'>No records requested to change.</p>";
    } else {
        $records = Download::recordIds($token, $server);
        $numAffected = count($toChange);
        foreach ($toDelete as $recordId) {
            Upload::deleteRecords($token, $server, [$recordId]);
        }
        foreach ($toChange as $oldRecordId => $newRecordId) {
            if (in_array($newRecordId, $records)) {
                Upload::deleteRecords($token, $server, [$newRecordId]);
            }
            Upload::copyRecord($token, $server, $oldRecordId, $newRecordId);
            Upload::deleteRecords($token, $server, [$oldRecordId]);
        }
        echo "<h1>$numAffected records affected.</h1>";
    }
} else {
    $names = Download::names($token, $server);
    $link = Application::link("clean/remap.php");
    echo "<h1>Change Record IDs</h1>";
    echo "<p class='centered'>Please be patient...</p>";
    echo "<form action='$link' method='POST'>";
    echo Application::generateCSRFTokenHTML();
    echo "<table class='centered'>";
    echo "<tr><th>Existing Record ID</th><th>New Record ID</th></tr>";
    foreach ($recordIds as $recordId) {
        $id = "record_$recordId";
        echo "<tr>";
        echo "<td>Record $recordId: ".$names[$recordId]."</td>";
        echo "<td><input type='text' class='newRecordIds' style='width: 60px;' id='$id' name='$id' value='$recordId'></td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<script>
function checkRecords() {
    var existingRecords = {};
    var repeatRecords = {};
    var invalidRecords = [];
    $('.newRecordIds').each(function(ob) {
        let oldRecordId = $(ob).attr('id').replace(/^record_/, '');
        let newRecordId = $(ob).val();
        if ((newRecordId !== '') && (newRecordId > 0)) {
            if (existingRecords[newRecordId]) {
                if (repeatRecords[newRecordId]) {
                    repeatRecords[newRecordId].push(oldRecordId);
                } else {
                    repeatRecords[newRecordId] = [existingRecords[newRecordId], oldRecordId];
                }
            } else {
                existingRecords[newRecordId] = oldRecordId;
            }
        } else {
            invalidRecords.push(oldRecordId);
        }
    });
    var mssgLines = [];
    for (let newRecordId in repeatRecords) {
       let oldRecordIds = repeatRecords[newRecordId];
       mssgLines.push('New record '+newRecordId+' was assigned to multiple existing records: '+oldRecordIds.join(', '));
    }
    if (invalidRecords.length > 0) {
        mssgLines.push('These existing records have invalid new records: '+invalidRecords.join(', '));
    }
    if (mssgLines.length > 0) {
        let mssg = 'Invalid records\\n'+mssgLines.join('\\n');
        alert(mssg);
        return false;    // don't proceed
    } else {
        return true;     // valid to proceed
    }
}
</script>";
    echo "<p class='centered'><button onclick='return checkRecords();'>Submit</button></p>";
    echo "</form>";
}

