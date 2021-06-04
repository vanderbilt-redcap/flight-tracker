<?php
  
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
$surveySettings = getAllEmailSettings($metadata);
$surveys = $surveySettings['surveys'];
if (!$surveys) { $surveys = getSurveys($metadata); }
if (!is_array($surveys)) { $surveys = json_decode($surveys, true); }
$decodedSettings = array();
foreach (getPrefixes() as $prefix => $pluralSuffix) {
	$setting = makeSettingName($pluralSuffix);
	$decodedSettings[$setting] = $surveySettings[$setting];
	if (!$decodedSettings[$setting]) { $decodedSettings[$setting] = makeNewArray($surveys); }
	if (!is_array($decodedSettings[$setting])) { $decodedSettings[$setting] = json_decode($decodedSettings[$setting], true); }
}

$mssgs = array();
foreach ($surveys as $surveyName => $dateField) {
	if (isValid($surveyName, $_POST)) {
		foreach (getPrefixes() as $prefix => $pluralSuffix) {
			$setting = makeSettingName($pluralSuffix);
			$postKey = makePOSTName($prefix, $surveyName);
			$decodedSettings[$setting][$surveyName] = $_POST[$postKey];
		}
		
	} else {
		array_push($mssgs, "ERROR: All of the variables are not available for Survey $surveyName!");
	}
}

if (empty($mssgs)) {
	echo "Success! All emails processed!";
} else {
	echo implode("<br>", $mssgs);
}

function makeNewArray($surveys) {
	$ary = array();
	foreach ($surveys as $name => $dateFiled) {
		$ary[$name] = "";
	}
	return $ary;
}

function isValid($name, $post) {
	$allFound = TRUE;
	foreach (getPrefixes() as $prefix => $pluralSuffix) {
		$key = makePOSTName($prefix, $name);
		if (!isset($post[$key]) && ($post[$key] !== "")) {
			$allFound = FALSE;
			break;
		}
	}
	return $allFound;
}
