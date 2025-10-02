<?php

namespace Vanderbilt\FlightTrackerExternalModule;

# returns associative array of pairs (prefix for POST => suffix for setting)
function getPrefixes() {
	return ["from" => "froms", "subject" => "subjects", "text" => "texts"];
}

function makeSettingName($pluralSuffix) {
	return "survey_".$pluralSuffix;
}

function makePOSTName($prefix, $name) {
	return $prefix."_".$name;
}
