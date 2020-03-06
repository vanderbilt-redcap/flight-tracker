<style>
tr.measurement,tr.dateMeasurement { border: 1px solid #888888; }
.measurementHeader,.measurementNumber,.measurementDenominator,.measurementDate { text-align: center; }
.measurementHeader { font-size: 16px; }
.measurementHeaderSmall { font-size: 14px; }
.measurementNumber { font-weight: bold; font-size: 24px; }
.measurementDate { font-weight: bold; font-size: 24px; }
.measurementDenominator { font-size: 16px; }
.animationProgress { height: 8px; -webkit-appearance: none; appearance: none; }
progress[value]::-webkit-progress-value { background-image: -webkit-linear-gradient(-45deg, transparent 66%, rgba(0, 0, 0, .1) 66%, rgba(0, 0, 0, .1) 66%, transparent 66%), -webkit-linear-gradient(top, rgba(255, 255, 255, .25), rgba(0, 0, 0, .25)), -webkit-linear-gradient(left, #998800, #5f0000); background-size: 35px 20px, 100% 100%, 100% 100%; borrder-radius: 4px; }
progress[value]::-webkit-progress-bar { background-color: #cccccc; border-radius: 4px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.25) inset; }
h1 { font-size: 40px; margin-top: 20px; margin-bottom: 20px; }
td.header { padding: 5px; }
.odd td { background-color: #eeeeee; }
.even td { background-color: white; }
</style>

<?php

use \Vanderbilt\FlightTrackerExternalModule\Measurement;
use \Vanderbilt\FlightTrackerExternalModule\DateMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\ObservedMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\MoneyMeasurement;

require_once(dirname(__FILE__)."/base.php");

function makeHTML($headers, $measurements, $lines = array(), $cohort = "", $metadata = array()) {
	global $pid;

	if (empty($measurements) && empty($headers)) {
		return "<h1>Under Construction!</h1>\n";
	}

	if (!isset($headers)) {
		throw new \Exception("headers must be specified!");
	}
	if (!isset($measurements)) {
		throw new \Exception("measurements must be specified!");
	}

	$target = \Vanderbilt\FlightTrackerExternalModule\getTarget();
	if ($target == "display") {
		$otherTarget = "table";
	} else {
		$otherTarget = "display";
	} 

	$html = "";
	$html .= \Vanderbilt\FlightTrackerExternalModule\displayDashboardHeader($target, $otherTarget, $pid, $cohort, $metadata);
	$html .= "<div id='content'>\n";

	$i = 1;
	foreach ($headers as $header) {
		$html .= "<h$i>".$header."</h$i>\n";
		$i++;
	}

	if (empty($measurements)) {
		$html .= "<p class='centered'>No measurements have been made!</p>\n";
	}

	$i = 1;
	$html .= "<table style='margin-left: auto; margin-right: auto;'>\n";
	foreach ($measurements as $header => $count) {
		if (($i % 2) == 1) {
			$rowClass = "odd";
		} else {
			$rowClass = "even";
		}
		$htmlHeader = \Vanderbilt\FlightTrackerExternalModule\addHTMLForParens($header);
		if (get_class($count) == "Vanderbilt\\FlightTrackerExternalModule\\DateMeasurement") {
			$date = $count->getMDY();
			$wDay = $count->getWeekDay();
			$html .= "<tr class='dateMeasurement $rowClass'>\n";
			$html .= "<td class='measurementHeader'>".$htmlHeader."</td>\n";
			$html .= "<td class='measurementDenominator'>$wDay</td>\n";
			$html .= "<td class='measurementDate'>".$date."</td>\n";
			$html .= "</tr>\n";
		} else if (get_class($count) == "Vanderbilt\\FlightTrackerExternalModule\\ObservedMeasurement") {
			$value = $count->getValue();
			$n = $count->getN();
			$html .= "<tr class='dateMeasurement $rowClass'>\n";
			$html .= "<td class='measurementHeader'>".$htmlHeader."</td>\n";
			$html .= "<td class='measurementNumerator'>".\Vanderbilt\FlightTrackerExternalModule\pretty($value)."</td>\n";
			$html .= "<td class='measurementDenominator'>(n = ".\Vanderbilt\FlightTrackerExternalModule\pretty($n).")</td>\n";
			$html .= "</tr>\n";
		} else if (get_class($count) == "Vanderbilt\\FlightTrackerExternalModule\\MoneyMeasurement") {
			$amt = $count->getAmount();
			$total = $count->getTotal();
			$html .= "<tr class='measurement $rowClass'>\n";
			$html .= "<td class='measurementHeader'>".$htmlHeader."</div>\n";
			$html .= "<td class='measurementMoney'>".\Vanderbilt\FlightTrackerExternalModule\prettyMoney($amt, FALSE)."</div>\n";
			if ($total) {
				$html .= "<td><progress class='animationProgress' max='1.0' value='".($amt / $total)."'></progress></td>\n";
				$html .= "<td class='measurementDenominator'>out of ".\Vanderbilt\FlightTrackerExternalModule\prettyMoney($total, FALSE)."</div>\n";
			} else {
				$html .= "<td></td><td></td>\n";
			}
			$html .= "</td>\n";
			$html .= "</tr>\n";
		} else {
			$numer = $count->getNumerator();
			$html .= "<tr class='measurement $rowClass'>\n";
			$html .= "<td class='measurementHeader'>".$htmlHeader."</td>\n";
			$html .= "<td class='measurementNumber'>".\Vanderbilt\FlightTrackerExternalModule\pretty($numer)."</td>\n";
			$denom = $count->getDenominator();
			if ($denom) {
				$html .= "<td><progress class='animationProgress' max='1.0' value='".($numer / $denom)."'></progress></td>\n";
				$html .= "<td class='measurementDenominator'>out of <b>".\Vanderbilt\FlightTrackerExternalModule\pretty($denom)."</b> (".\Vanderbilt\FlightTrackerExternalModule\fractionToPercent($numer / $denom).")</td>\n";
			}
			$html .= "</tr>\n";
		}
		$i++;
	}
	$html .= "</table>\n";

	if (!empty($lines)) {
		$html .= \Vanderbilt\FlightTrackerExternalModule\makeLineGraph($lines);
	}
	$html .= "</div>\n";

	return $html;
}
