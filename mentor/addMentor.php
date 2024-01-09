<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");

$recordId = REDCapManagement::sanitize($_GET['menteeRecord']);

if(isset($_REQUEST['uid']) && MMAHelper::getMMADebug()){
    $username = REDCapManagement::sanitize($_REQUEST['uid']);
    $uidString = "&uid=$username";
} else {
    $username = Application::getUsername();
    $uidString = "";
}
$error = "";
$message = "";
if (!MMAHelper::isMentee($recordId, $username)) {
    $error = "Invalid username";
}


$names = MMAHelper::downloadAndMakeNames($token, $server);
$myName = $names[$recordId];
$allPrimaryMentors = Download::primaryMentors($token, $server);
$allPrimaryMentorUserids = Download::primaryMentorUserids($token, $server);
$myPrimaryMentors = $allPrimaryMentors[$recordId] ?? [];
$myPrimaryMentorUserids = $allPrimaryMentorUserids[$recordId] ?? [];

if ($_POST['newMentorName'] && $_POST['newMentorUserid']) {
    $newName = REDCapManagement::sanitize($_POST['newMentorName']);
    $newUserid = REDCapManagement::sanitize($_POST['newMentorUserid']);
    if (in_array($newUserid, $myPrimaryMentorUserids)) {
        $error = "Name already added";
    } else {
        $myPrimaryMentors[] = $newName;
        $myPrimaryMentorUserids[] = $newUserid;
        $uploadRow = [
            "record_id" => $recordId,
            "summary_mentor" => implode(", ", $myPrimaryMentors),
            "summary_mentor_userid" => implode(", ", $myPrimaryMentorUserids),
        ];
        try {
            $feedback = Upload::oneRow($uploadRow, $token, $server);
            if ($feedback['errors']) {
                $error = implode("<br>", $feedback['errors']);
            } else if ($feedback['error']) {
                $error = $feedback['error'];
            } else {
                $message = "Upload successful!";
            }
        } catch(\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

require_once dirname(__FILE__).'/_header.php';
?>
<section class="bg-light">
  <div class="container">
    <div class="row">
      <div class="col-lg-12">
<?php

$link = Application::link("mentor/index.php")."&menteeRecord=".$recordId.$uidString;
echo "<p><a href='$link'>Back to Mentoring Agreement Home</a></p>";
echo "<h2>Add a Mentor for $myName</h2>";

if (count($myPrimaryMentors) == 0) {
    echo "<p>$myName does not have any mentors listed.</p>";
} else if (count($myPrimaryMentors) == 1) {
    echo "<p>$myName's current mentor is:<br>".REDCapManagement::makeConjunction($myPrimaryMentors)."</p>";
} else if (count($myPrimaryMentors) > 1) {
    echo "<p>$myName's current mentors are:<br>".REDCapManagement::makeConjunction($myPrimaryMentors)."</p>";
}

if ($error) {
    echo "<div class='red'><h4>Error!</h4><p style='text-align: center;'>$error</p></div>";
}
if ($message) {
    echo "<p class='green' style='text-align: center;'>$message</p>";
}

$link = Application::link("mentor/addMentor.php")."&menteeRecord=$recordId".$uidString;
echo "<p>Would you like to add another mentor for $myName? If so, fill out the below form.</p>";
echo "<form action='$link' method='POST'>";
echo Application::generateCSRFTokenHTML();
echo "<p style='text-align: center;'><label for='newMentorName'>New Mentor Name:</label> <input type='text' name='newMentorName' id='newMentorName' onkeyup='nameKeyUp();'></p>";
echo "<p style='text-align: center; display: none;' id='searchButton'><button onclick='lookupName($(\"#newMentorName\").val()); return false;'>Search REDCap for Name</button></p>";
echo "<p style='text-align: center; display: none;' class='useridPrompt'><label for='newMentorUserid'>New Mentor User Id:</label> <select name='newMentorUserid' id='newMentorUserid'></select></p>";
echo "<p style='text-align: center; display: none;' class='useridPrompt'><button onclick='return checkIfUseridValid();'>Add New Mentor</button></p>";
echo "</form>";

?>
      </div>
    </div>
  </div>
</section>

<script>
function nameKeyUp() {
    if ($('#newMentorName').val() != "") {
        $('#searchButton').show();
    }
    $('.useridPrompt').hide();
}

function checkIfUseridValid() {
    if ($('#newMentorUserid option:selected').val() != '') {
        return true;
    }
    $.sweetModal("Please select a userid");
    return false;
}

function lookupName(name) {
    $.post('<?= Application::link("mentor/getREDCapUseridFromProject.php").$uidString."&menteeRecord=$recordId" ?>', { 'redcap_csrf_token': getCSRFToken(), name: name }, function(json) {
        let userids = JSON.parse(json);
        console.log(userids.length+" user ids matched");
        $('#newMentorUserid').find('option').remove();
        var optionHTML = "<option value=''>---SELECT---</option>";
        for (var i=0; i < userids.length; i++) {
            let userid = userids[i];
            optionHTML += "<option value='"+userid+"'>"+userid+"</option>";
        }
        $('#newMentorUserid').html(optionHTML);
        if (userids.length === 0) {
            $.sweetModal("No user ids were matched for "+name);
        } else {
            $('.useridPrompt').show();
        }
    });
}
</script>

<style>
    .red{ background-color: #af3017 }
    .green{ background-color: #17af30 }
    .bg-light { background-color: #ffffff!important; }
</style>

<?php
require_once dirname(__FILE__).'/_footer.php';

