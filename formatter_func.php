<?php

namespace Vanderbilt\FlightTrackerExternalModule;

function processFiles($token, $server, $pid) {
	$files = array(
			"/app001/www/redcap/plugins/career_dev/coeus_award.json",
			"/app001/www/redcap/plugins/career_dev/coeus_investigator.json",
			);
	foreach ($files as $file) {
		processFile($file);
	}
}

function processFile($file) {
	$fp = fopen($file, "r");
	$line = fgets($fp);
	fclose($fp);
	$line = preg_replace("/^\[\{/", "", $line);
	$line = preg_replace("/\}\]$/", "", $line);
	$lines = preg_split("/\},\{/", $line);
	$i = 0;
	foreach ($lines as $line) {
    	$lines[$i] = "{".$line."}";
    	$i++;
	}

	$newFilename = preg_replace("/\.json$/", ".format.json", $file);
	$fp = fopen($newFilename, "w");
	fwrite($fp, implode("\n", $lines));
	fclose($fp);

	error_log("$file written to $newFilename");
}
