<?php

namespace Vanderbilt\FlightTrackerExternalModule;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../wrangler/baseSelect.php");

function fractionToPercent($frac) {
	return (floor($frac * 1000) / 10)."%";
}

function addHTMLForParens($header) {
	$str = str_replace("(", "<br><span class='measurementHeaderSmall'>(", $header);
	return str_replace(")", ")</span>", $str);
}

function getPossibleLayouts() {
    return [
        "table",
        "display",
    ];
}

function getTarget() {
	if (!$_GET['layout']) {
		return "display";
	}
	$possibleLayouts = getPossibleLayouts();
	$layout = REDCapManagement::sanitize($_GET['layout']);
	if (in_array($layout, $possibleLayouts)) {
	    foreach ($possibleLayouts as $possibleLayout) {
	        if ($possibleLayout == $layout) {
	            return $possibleLayout;
            }
        }
        return "";
    }
	throw new \Exception("Invalid layout ".$layout);
}

function makeLineGraph($lines) {
	$html = "";

	$html .= "<script src='Chart.bundle.js'></script>\n";

	$html .= "<canvas id='canvas' class='chartjs-render-monitor' style='display: block; width: 700px; height: 350px;'></canvas>\n";

	$html .= "<script>\n";
	$xs = array();
	$minX = 10000;
	$maxX = 0;
	foreach ($lines as $title => $line) {
		foreach ($line as $year => $count) {
			if ($maxX < $year) {
				$maxX = $year;
			}
			if ($minX > $year) {
				$minX = $year;
			}
		}
	}
	for($year = $minX; $year <= $maxX; $year++) {
		array_push($xs, $year);
	}

	$data = array(
			"labels" => $xs,
			"datasets" => array()
			);
	foreach ($lines as $title => $line) {
		$dataset = array(
					"label" => $title,
					"data" => array(),
					"fill" => FALSE,
					"borderColor" => "rgb(0, 192, 192)",
					"lineTension" => 0.1
				);
		foreach ($xs as $year) {
			if (!isset($line[$year])) {
				$count = 0;
			} else {
				$count = $line[$year];
			}
			array_push($dataset['data'], $count);
		}
		array_push($data['datasets'], $dataset);
	}

	$html .= "var config = {\n";
	$html .= "\ttype: 'line',\n";
	$html .= "\tdata: ".json_encode($data).",\n";
	$html .= "\toptions: { responsive: true, title: { display: true, text: 'Number of Publications' }, tooltips: { mode: 'index', intersect: false, }, hover: { mode: 'nearest', intersect: true }, scales: { xAxes: [{ display: true, scaleLabel: { display: true, labelString: 'Year' } }], yAxes: [{ display: true, scaleLabel: { display: true, labelString: 'Publication Count' } }] } }\n";
	$html .= "\t};\n";

	$html .= "window.onload = function() { var ctx = document.getElementById('canvas').getContext('2d'); window.myLine = new Chart(ctx, config); };\n";

	$html .= "</script>\n";

	return $html;
}

function displayDashboardHeader($target, $otherTarget, $pid, $cohort = "", $metadata = array()) {
    global $token, $server;
	$html = "";

	$cohortUrl = "";
	if ($cohort || ($cohort == "all")) {
		$cohortUrl = "&cohort=".$cohort;
	}
	# page specified below in $pubChoices
	$prefix = "?prefix=".$_GET['prefix']."&pid=".$_GET['pid']."&layout=$target$cohortUrl";
	$pubChoices = array(
				"$prefix&page=dashboard%2FpublicationsByCategory" => "By Category",
				"$prefix&page=dashboard%2FpublicationsByYear" => "By Year",
				"$prefix&page=dashboard%2FpublicationsByJournal" => "By Journal",
				"$prefix&page=dashboard%2FpublicationsByPublicationType" => "By PubMed Publication Type",
				"$prefix&page=dashboard%2FpublicationsByMESHTerms" => "By MESH Terms",
				"$prefix&page=dashboard%2FpublicationsByMetrics" => "Miscellaneous Metrics",
				);

	$html .= "<div class='subnav'>\n";
	$html .= "<a class='yellow' href='".CareerDev::link("dashboard/overall.php")."&layout=$target$cohortUrl'>Overall Summary</a>\n";
	$html .= "<a class='yellow' href='".CareerDev::link(CareerDev::getCurrPage())."&layout=$otherTarget$cohortUrl'>Switch Layouts</a>\n";

	$html .= "<a class='green' href='".CareerDev::link("dashboard/grants.php")."&layout=$target$cohortUrl'>Grants</a>\n";
	$html .= "<a class='green' href='".CareerDev::link("dashboard/grantBudgets.php")."&layout=$target$cohortUrl'>Grant Budgets</a>\n";
	$html .= "<a class='green' href='".CareerDev::link("dashboard/grantBudgetsByYear.php")."&layout=$target$cohortUrl'>Grant Budgets by Year</a>\n";

	$html .= "<a class='orange' href='javascript:;' onclick='return false;'>Publications <select onchange='changePub(this);'>\n";
	$html .= "<option value=''>---SELECT---</option>\n";
	foreach ($pubChoices as $page => $label) {
		$sel = "";
		if (preg_match("/".$_GET['page']."/", $page)) {
			$sel = " selected";
		}
		$html .= "<option value='$page'$sel>$label</option>\n";
	}
	$html .= "</select></a>\n";

	$html .= "<a class='blue' href='".CareerDev::link("dashboard/emails.php")."&layout=$target$cohortUrl'>Emails</a>\n";
	$html .= "<a class='blue' href='".CareerDev::link("dashboard/demographics.php")."&layout=$target$cohortUrl'>Demographics</a>\n";
	$html .= "<a class='blue' href='".CareerDev::link("dashboard/dates.php")."&layout=$target$cohortUrl'>Dates</a>\n";
	$html .= "<a class='blue' href='".CareerDev::link("dashboard/resources.php")."&layout=$target$cohortUrl'>Resources</a>\n";


	$html .= "<script>\n";
	$html .= "function changePub(ob) {\n";
	$html .= "\tvar sel = $(ob).children('option:selected').val();\n";
	$html .= "\tif (sel) { window.location.href = sel; }\n";
	$html .= "}\n";
	$html .= "</script>\n";

	$cohorts = new Cohorts($token, $server, CareerDev::getModule());
	$cohortTitles = $cohorts->getCohortTitles();
	$html .= "<a href='javascript:;' onclick='return false;' class='purple'>Select Cohort: <select onchange='if ($(this).val()) { window.location.href = \"?layout=$target&pid=$pid&page=".$_GET['page']."&prefix=".$_GET['prefix']."&cohort=\" + $(this).val(); } else { window.location.href = \"?layout=$target&pid=$pid&page=".$_GET['page']."&prefix=".$_GET['prefix']."\"; }'>\n";
	$html .= "<option value=''>---ALL---</option>\n";
	foreach ($cohortTitles as $title) {
		$html .= "<option value='$title'";
		if ($title == $cohort) {
			$html .= " selected";
		}
		$html .= ">$title</option>\n";
	}
	$html .= "</select></a>\n";
	$html .= "<a class='purple' href='".CareerDev::link("/cohorts/viewCohorts.php")."'>View Cohorts</a>\n";

	$html .= "</div>\n";

	return $html;
}
