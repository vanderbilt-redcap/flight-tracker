<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Crons;

require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/Crons.php");
require_once(dirname(__FILE__)."/CareerDev.php");

# supply day of the week or YYYY-MM-DD
function loadCrons(&$manager, $specialOnly = FALSE, $token = "", $server = "") {
	if (!$token) { global $token; }
	if (!$server) { global $server; }

	if ($specialOnly) {
		$manager->addCron("drivers/11_vfrs.php", "updateVFRS", date("Y-m-d"));
	} else if ($token && $server) {
		$has = checkMetadataForFields($token, $server);

		$manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", "Monday");
		$manager->addCron("drivers/2n_updateReporters.php", "updateReporter", "Tuesday");
		if ($has['coeus']) {
			$manager->addCron("drivers/2o_updateCoeus.php", "processCoeus", "Thursday");
		}
		if ($has['vfrs']) {
			$manager->addCron("drivers/11_vfrs.php", "updateVFRS", "Thursday");
		}
		if ($has['news']) {
			$manager->addCron("news/getNewsItems_func.php", "getNewsItems", "Friday");
		}
		$manager->addCron("publications/getAllPubs_func.php", "getPubs", "Saturday");

		$manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Monday");
		$manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Tuesday");
		$manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Wednesday");
		$manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Thursday");
		$manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Friday");
		$manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Saturday");
	}
	echo $manager->getNumberOfCrons()." crons loaded\n";
}

function loadInitialCrons(&$manager, $specialOnly = FALSE, $token = "", $server = "") {
	if (!$token) { global $token; }
	if (!$server) { global $server; }

	$date = date("Y-m-d");

	if ($token && $server) {
		$has = checkMetadataForFields($token, $server);
		if ($has['vfrs']) {
			$manager->addCron("drivers/11_vfrs.php", "updateVFRS", $date);
		}
		if ($has['news']) {
			$manager->addCron("news/getNewsItems_func.php", "getNewsItems", $date);
		}

		$manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", $date);
		$manager->addCron("drivers/2n_updateReporters.php", "updateReporter", $date);
		$manager->addCron("publications/getAllPubs_func.php", "getPubs", $date);
		$manager->addCron("drivers/6d_makeSummary.php", "makeSummary", $date);

		# last because may not have setup. Will fail last
		if ($has['coeus']) {
			$manager->addCron("drivers/2o_updateCoeus.php", "processCoeus", $date);
		}
	}
	echo $manager->getNumberOfCrons()." crons loaded\n";
}

function checkMetadataForFields($token, $server) {
	$metadata = Download::metadata($token, $server);

	$vars = array();
	$vars['coeus'] = FALSE;
	$vars['vfrs'] = FALSE;
	$vars['news'] = FALSE;

	foreach ($metadata as $row) {
		$field = $row['field_name'];
		if (preg_match("/^coeus_/", $field)) {
			$vars['coeus'] = TRUE;
		}
		if (preg_match("/^vfrs_/", $field)) {
			$vars['vfrs'] = TRUE;
		}
		if ($field == "summary_news") {
			$vars['news'] = TRUE;
		}
	}
	return $vars;
}

