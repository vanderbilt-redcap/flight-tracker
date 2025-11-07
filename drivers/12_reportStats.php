<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

# Reports *de-identified, generic stats* back to home office on a weekly basis.
# Reports figures like number of scholars for each project.

function reportStats($token, $server, $pid, $records) {
	$urls = [
        "https://redcap.vumc.org/plugins/career_dev/receiveStats.php",
        "https://redcap.vanderbilt.edu/plugins/career_dev/receiveStats.php"
    ];

	# do NOT report details of records; just report: number of records/scholars
	$recordIds = Download::recordIds($token, $server);
	// $numGrants = getTotalNumberOfGrantsAfterCombination($token, $server);
	// $numPubs = getTotalNumberOfConfirmedPublications($token, $server);

    # prevent sending duplicates
    if (in_array($recordIds[0], $records)) {
        $post = [
            "pid" => $pid,
            "server" => $server,
            "scholars" => count($recordIds),
            "date" => date("Y-m-d"),
            "version" => CareerDev::getVersion(),
            "grant_class" => CareerDev::getSetting("grant_class", $pid),
        ];
        // "grants" => $numGrants,
        // "publications" => $numPubs,

        $url = "";
        foreach ($urls as $u) {
            try {
                if (URLManagement::isGoodURL($u)) {
                    $url = $u;
                    break;
                }
            } catch (\Exception $e) {
                # do nothing
            }
        }
        if ($url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_VERBOSE, 0);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post, '', '&'));
            URLManagement::applyProxyIfExists($ch, $pid);
            $output = curl_exec($ch);
            Application::log($output, $pid);
            curl_close($ch);
        } else {
            Application::log("Cannot report stats!", $pid);
        }
    }
}

function getTotalNumberOfConfirmedPublications($token, $server) {
	$fields = array("citation_include");
	$redcapData = Download::fields($token, $server, $fields);

	$numPubs = 0;
	foreach ($redcapData as $row) {
		if ($row['citation_include'] == "1") {
			$numPubs++;
		}
	}
	return $numPubs;
}

function getTotalNumberOfGrantsAfterCombination($token, $server) {
	$fields = array();
	$prefix = "summary_award_sponsorno";
	for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
		array_push($fields, $prefix."_".$i);
	}
	$redcapData = Download::fields($token, $server, $fields);

	$numGrants = 0;
	foreach ($redcapData as $row) {
		for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
			if ($row[$prefix."_".$i]) {
				$numGrants++;
			}
		}
	}
	return $numGrants;
}
