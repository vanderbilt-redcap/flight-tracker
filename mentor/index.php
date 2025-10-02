<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/../classes/Autoload.php";

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/base.php";
require_once dirname(__FILE__).'/_header.php';

if (isset($_REQUEST['uid']) && MMAHelper::getMMADebug()) {
	$username = REDCapManagement::sanitize($_REQUEST['uid']);
	$uidString = "&uid=$username";
	$spoofing = MMAHelper::makeSpoofingNotice($username);
} else {
	$username = (Application::getProgramName() == "Flight Tracker Mentee-Mentor Agreements") ? NEW_HASH_DESIGNATION : Application::getUsername();
	$uidString = "";
	$spoofing = "";
}

list($firstName, $lastName) = MMAHelper::getNameFromREDCap($username, $token, $server);
$menteeRecordIds = MMAHelper::getRecordsAssociatedWithUserid($username, $token, $server);

if ($hash && $hashRecordId || $isNewHash) {
	$html = MMAHelper::makePublicApplicationForm($token, $server, $isNewHash ? NEW_HASH_DESIGNATION : $hash, $hashRecordId);
} else {
	$metadata = MMAHelper::getMetadata($pid);
	$html = MMAHelper::makeMainTable($token, $server, $username, $metadata, $menteeRecordIds, $uidString);
}
echo $spoofing;
echo $html;
echo MMAHelper::getIndexHead($firstName, $lastName);

include dirname(__FILE__).'/_footer.php';
echo MMAHelper::makeEmailJS($username);
