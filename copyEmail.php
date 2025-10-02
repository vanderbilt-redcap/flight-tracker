<?php

use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/charts/baseWeb.php");

$destPid = $pid;
$destRecords = Download::recordIds($token, $server);
if (isset($_GET['skip'])) {
	$email = Sanitizer::sanitize($_GET['skip']);
	if ($email && REDCapManagement::isEmail($email)) {
		$destRecordId = Sanitizer::getSanitizedRecord($_GET['destRecord'] ?? "", $destRecords);
		if ($destRecordId) {
			$omitted = Application::getSetting("omittedEmails", $destPid) ?: [];
			if (!isset($omitted[$destRecordId])) {
				$omitted[$destRecordId] = [];
			}
			if (!in_array($email, $omitted[$destRecordId])) {
				$omitted[$destRecordId][] = $email;
			}
			Application::saveSetting("omittedEmails", $omitted, $destPid);
			echo "<p class='centered'>Saved. $email will no longer be matched.</p>";
		} else {
			echo "<p class='centered'>Invalid record.</p>";
		}
	} else {
		echo "<p class='centered'>No valid email supplied.</p>";
	}
} elseif (isset($_GET['sourcePid'])) {
	$sourcePid = Sanitizer::sanitizePid($_GET['sourcePid'] ?? "");
	$allPids = $module->getPids();
	if ($sourcePid && in_array($sourcePid, $allPids)) {
		$sourceToken = Application::getSetting("token", $sourcePid);
		$sourceServer = Application::getSetting("server", $sourcePid);
		$sourceRecords = Download::recordIds($sourceToken, $sourceServer);
		$sourceRecordId = Sanitizer::getSanitizedRecord($_GET['sourceRecord'] ?? "", $sourceRecords);
		$destRecordId = Sanitizer::getSanitizedRecord($_GET['destRecord'] ?? "", $destRecords);
		if ($destRecordId && $sourceRecordId) {
			$sourceEmail = Download::oneFieldForRecordByPid($sourcePid, "identifier_email", $sourceRecordId);
			if ($sourceEmail && REDCapManagement::isEmailOrEmails($sourceEmail)) {
				$uploadRow = ["record_id" => $destRecordId, "identifier_email" => $sourceEmail];
				try {
					Upload::oneRow($uploadRow, $token, $server);
					echo "<p class='centered'>Upload Successful.</p>";
				} catch (\Exception $e) {
					echo "<p class='centered'>Error! ".$e->getMessage()."</p>";
				}
			} else {
				echo "<p class='centered'>Invalid email.</p>";
			}
		} else {
			echo "<p class='centered'>Invalid record-id.</p>";
		}
	} else {
		echo "<p class='centered'>Invalid project-id.</p>";
	}
} else {
	echo "<p class='centered'>Invalid request.</p>";
}
