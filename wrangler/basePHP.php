<?php

namespace Vanderbilt\FlightTrackerExternalModule;

define("BLANK_VALUE", "---");
define("JS_UNDEFINED", "undefined");
define("ADVANCED_MODE", 'endGrantWranglerTraining');

function careerprogression($ary, $i) {
	$startdate = BLANK_VALUE;
	$enddate = BLANK_VALUE;
	$redcaptype = BLANK_VALUE;
	$d_base_award_no = BLANK_VALUE;
	foreach ($ary as $key => $value) {
		if ($key == "redcap_type") {
			if ($value == '') {
				$redcaptype = "";
			} else {
				$redcaptype = $value;
			}
		} elseif ($key == "start_date") {
			if ($value == '') {
				$startdate = "";
			} else {
				$startdate = $value;
			}
		} elseif ($key == "end_date") {
			if ($value == '') {
				$enddate = "";
			} else {
				$enddate = $value;
			}
		} elseif ($key == "base_award_no") {
			if ($value == '') {
				$d_base_award_no = '';
			} else {
				$d_base_award_no = $value;
			}
		}
	}
	return [
		"id" => $i,
		"content" => $d_base_award_no." ($redcaptype)",
		"group" => "Grant",
		"start" => $startdate,
		"type" => "range",
		"end" => $enddate,
	];
}
