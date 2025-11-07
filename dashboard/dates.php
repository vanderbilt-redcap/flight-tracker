<?php

use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\DateMeasurement;
use \Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Dashboard;
use \Vanderbilt\CareerDevLibrary\DateManagement;

##############################################
# Note: This page does not seem sufficiently useful.
# Therefore, it has not been linked to in Dashboard::displayDashboardHeader

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/base.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");


$headers = [];
$headers[] = "Important Dates";
if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
    $headers[] = "For Cohort " . $cohort;
} else {
    $cohort = "";
}

$settings = Application::getAllSettings($pid);

$measurements = [];
foreach ($settings as $setting => $value) {
	if ($value && DateManagement::isDate($value)) {
		$measurements[$setting] = new DateMeasurement($value);
	} else if (isset($_GET['test'])) {
        echo "Skipping $setting ($value)<br/>";
    }
}
echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
