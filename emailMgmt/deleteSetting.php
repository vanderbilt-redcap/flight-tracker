<?php

use Vanderbilt\CareerDevLibrary\EmailManager;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__) . "/../small_base.php");
require_once(dirname(__FILE__) . "/../classes/Autoload.php");

if (isset($_POST["settingName"])) {
	$metadata = Download::metadata($token, $server);
	$mgr = new EmailManager($token, $server, $pid, Application::getModule(), $metadata);
	$settingName = Sanitizer::sanitize($_POST['settingName']);
	if ($mgr->hasItem($settingName)) {
		$mgr->deleteEmail($settingName);
		$data = ["result" => "Success!"];
	} else {
		$data = ["error" => "Wrong name."];
	}
} else {
	$data = ["error" => "Improper data."];
}
echo json_encode($data);
