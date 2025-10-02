<?php

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\CelebrationsEmail;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(__DIR__."/small_base.php");
require_once(__DIR__."/classes/Autoload.php");


$handler = new CelebrationsEmail($token, $server, $pid, []);
$action = Sanitizer::sanitize($_POST['action']);
if ($action == "delete") {
	$settingName = Sanitizer::sanitizeWithoutChangingQuotes($_POST['name']);
	$handler->deleteConfiguration($settingName);
	echo "Done.";
} elseif ($action == "add") {
	$settingName = Sanitizer::sanitizeWithoutChangingQuotes($_POST['name']);
	$content = Sanitizer::sanitize($_POST['content']);
	$scope = "";
	$what = "";
	if ($content == "new_grants") {
		$scope = "New";
		$what = "Grants";
	} elseif ($content == "all_pubs") {
		$scope = "All";
		$what = "Publications";
	} elseif ($content == "first_last_author_pubs") {
		$scope = "First/Last Author";
		$what = "Publications";
	} elseif ($content == "high_impact_pubs") {
		$scope = "High-Impact";
		$what = "Publications";
	} elseif ($content == "new_honors") {
		$scope = "New";
		$what = "Honors";
	}
	if ($scope && $what) {
		$setting = [
			"who" => Sanitizer::sanitize($_POST['who']),
			"when" => Sanitizer::sanitize($_POST['when']),
			"what" => $what,
			"scope" => $scope,
			"grants" => Sanitizer::sanitize($_POST['grants']),
		];
		$handler->addOrModifyConfiguration($settingName, $setting);
		echo "Done.";
	} else {
		echo "ERROR: Wrong request.";
	}
} elseif ($action == "changeEmail") {
	$email = Sanitizer::sanitize($_POST['email']);
	$handler->saveEmail($email);
	echo "Done.";
} else {
	echo "ERROR: Improper action.";
}
