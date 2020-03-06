<style>
td.measurement,td.dateMeasurement,td.moneyMeasurement { vertical-align: bottom; border-radius: 10px; background-color: #dddddd; padding: 10px; width: 225px; box-shadow: 2px 2px 5px #444444; }
td.spacer { width: 20px; background-color: transparent; }
td.verticalSpacer { height: 20px; background-color: white; }
.measurementHeader,.measurementNumber,.measurementDenominator,.measurementDate,.measurementMoney { text-align: center; }
.measurementHeader { font-size: 24px; color: #888888; padding-bottom: 8px; }
.measurementHeaderSmall { font-size: 18px; }
.measurementNumber { font-size: 80px; }
.measurementDate { font-size: 40px; }
.measurementMoney { font-size: 30px; }
.measurementNumber,.measurementDate,.measurementMoney { font-weight: bold; color: #5f0000; text-shadow: 2px 2px 5px #444444; }
.measurementDenominator { font-size: 16px; color: #888888; padding-top: 8px; }
.animationProgress { text-align: center; width: 100%; height: 8px; -webkit-appearance: none; appearance: none; }
progress[value]::-webkit-progress-value { background-image: -webkit-linear-gradient(-45deg, transparent 66%, rgba(0, 0, 0, .1) 66%, rgba(0, 0, 0, .1) 66%, transparent 66%), -webkit-linear-gradient(top, rgba(255, 255, 255, .25), rgba(0, 0, 0, .25)), -webkit-linear-gradient(left, #998800, #5f0000); background-size: 35px 20px, 100% 100%, 100% 100%; borrder-radius: 4px; }
progress[value]::-webkit-progress-bar { background-color: #cccccc; border-radius: 4px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.25) inset; }
h1 { font-size: 60px; margin-top: 20px; margin-bottom: 20px; }
td.header { padding: 5px; }
</style>

<?php

use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

function makeHTML($headers, $measurements, $lines = array(), $cohort = "", $metadata = array(), $numInRow = 4) {
	global $pid;

	if (empty($measurements) && empty($headers)) {
		return "<h1>Under Construction!</h1>\n";
	}

	if (!isset($headers)) {
		throw new Exception("headers must be specified!");
	}
	if (!isset($measurements)) {
		throw new Exception("measurements must be specified!");
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

	$i = 0;
	$html .= "<table style='margin-left: auto; margin-right: auto;'><tr>\n";
	foreach ($measurements as $header => $count) {
		$htmlHeader = \Vanderbilt\FlightTrackerExternalModule\addHTMLForParens($header);
		if (get_class($count) == "Vanderbilt\\FlightTrackerExternalModule\\ObservedMeasurement") {
			$value = $count->getValue();
			$n = $count->getN();
			$html .= "<td class='measurement'>\n";
			$html .= "<div class='measurementHeader'>".$htmlHeader."</div>\n";
			$html .= "<div class='measurementNumber'>".\Vanderbilt\FlightTrackerExternalModule\pretty($value)."</div>\n";
			$html .= "<div class='measurementDenominator'>(n = <b>".\Vanderbilt\FlightTrackerExternalModule\pretty($n)."</b>)</div>\n";
			$html .= "</td>\n";
		} else if (get_class($count) == "Vanderbilt\\FlightTrackerExternalModule\\DateMeasurement") {
			$date = $count->getMDY();
			$wDay = $count->getWeekDay();
			$html .= "<td class='dateMeasurement'>\n";
			$html .= "<div class='measurementHeader'>".$htmlHeader."</div>\n";
			$html .= "<div class='measurementDenominator'>$wDay</div>\n";
			$html .= "<div class='measurementDate'>".$date."</div>\n";
			$html .= "<div class='animationProgress'>&nbsp;</div>\n";
			$html .= "</td>\n";
		} else if (get_class($count) == "Vanderbilt\\FlightTrackerExternalModule\\MoneyMeasurement") {
			$amt = $count->getAmount();
			$total = $count->getTotal();
			$html .= "<td class='moneyMeasurement'>\n";
			$html .= "<div class='measurementHeader'>".$htmlHeader."</div>\n";
			$html .= "<div class='measurementMoney'>".\Vanderbilt\FlightTrackerExternalModule\prettyMoney($amt, FALSE)."</div>\n";
			if ($total) {
				$html .= "<progress class='animationProgress' max='1.0' value='".($amt / $total)."'></progress>\n";
				$html .= "<div class='measurementDenominator'>out of ".\Vanderbilt\FlightTrackerExternalModule\prettyMoney($total, FALSE)."</div>\n";
			}
			$html .= "<div class='animationProgress'>&nbsp;</div>\n";
			$html .= "</td>\n";
		} else {
			$numer = $count->getNumerator();
			$html .= "<td class='measurement'>\n";
			$html .= "<div class='measurementHeader'>".$htmlHeader."</div>\n";
			$html .= "<div class='measurementNumber'>".\Vanderbilt\FlightTrackerExternalModule\pretty($numer)."</div>\n";
			$denom = $count->getDenominator();
			if ($denom) {
				$html .= "<progress class='animationProgress' max='1.0' value='".($numer / $denom)."'></progress>\n";
				$html .= "<div class='measurementDenominator'>out of <b>".\Vanderbilt\FlightTrackerExternalModule\pretty($denom)."</b> (".\Vanderbilt\FlightTrackerExternalModule\fractionToPercent($numer / $denom).")</div>\n";
			} else {
				$html .= "<div class='measurementDenominator'>&nbsp;</div>\n";
				$html .= "<div class='animationProgress'>&nbsp;</div>\n";
			}
			$html .= "</td>\n";
		}
		$i++;
		if ($i % $numInRow == 0) {
			$html .= "</tr></table>\n";
			$html .= "<div class='verticalSpacer'>&nbsp;</div>\n";
			$html .= "<table style='margin-left: auto; margin-right: auto;'><tr>\n";
		} else if ($i < count($measurements)) {
			$html .= "<td class='spacer'>&nbsp;</td>\n";
		}
	}
	$html .= "</tr></table>\n";

	if (!empty($lines)) {
		$html .= \Vanderbilt\FlightTrackerExternalModule\makeLineGraph($lines);
	}
	$html .= "</div>\n";

	return $html;
}

