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

