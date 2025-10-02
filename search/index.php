<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Scholar;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

?>
<style>
.centered { text-align: center; }
h1,h2,h3,h4 { text-align: center; }
a.header { font-size: 18px; }
input[type=text] { font-size: 18px; width: 400px; }
input[type=submit] { font-size: 18px; }
.highlighted { background-color: yellow; }
.small { font-size: 13px; }
</style>
<?php

function getNumber($field) {
	return preg_replace("/^[a-zA-Z_]+/", "", $field);
}

echo "<h1>Search ".CareerDev::getProgramName()." Career-Defining Grants</h1>\n";

$postQuery = "";
if (isset($_POST['q']) && $_POST['q']) {
	$postQuery = Sanitizer::sanitize($_POST['q']);
	$recordIds = Download::recordIds($token, $server);
	$metadata = Download::metadata($token, $server);

	$fieldTypes = [];
	$choices = Scholar::getChoices($metadata);
	foreach ($metadata as $row) {
		$fieldTypes[$row['field_name']] = $row['field_type'];
	}

	$terms = preg_split("/\s+/", Sanitizer::sanitizeWithoutChangingQuotes($_POST['q']));
	$multis = ["checkbox", "dropdown", "radio"];
	$textEntry = ["text", "notes"];
	$matches = [];      // record_id:field_name as key, then equals point value
	$names = [];
	$matchValues = [];
	$matchAwards = [];
	$nameFields = ["identifier_first_name", "identifier_last_name"];
	foreach ($recordIds as $recordId) {
		$recordData = Download::fieldsForRecords($token, $server, array_unique(array_merge(["record_id"], Application::$summaryFields)), [$recordId]);
		foreach ($recordData as $row) {
			if ($row['redcap_repeat_instrument'] === "") {
				$names[$row['record_id']] = $row['identifier_first_name']." ".$row['identifier_last_name'];
				foreach ($row as $field => $value) {
					if (preg_match("/^summary_award_/", $field) || in_array($field, $nameFields)) {
						$fieldType = $fieldTypes[$field];
						if (in_array($fieldType, $multis)) {
							# make text string
							$value = $choices[$row['field_name']][$value];
							$fieldType = "text";
						}
						if (in_array($fieldType, $textEntry)) {
							# search $value
							$points = 0;
							$termsMatched = [];
							foreach ($terms as $term) {
								$term = preg_replace("/\//", "", $term);
								$weight = 1;
								if (in_array($term, $terms)) {
									$weight = 2;
								}
								if (preg_match("/^".strtolower($term)."$/", strtolower($value))) {
									# whole word match
									$points += 1000 / $weight;
									$termsMatched[] = $term;
								} elseif (preg_match("/".strtolower($term)."/", strtolower($value))) {
									# partial word match
									$points += 500 / $weight;
									$termsMatched[] = $term;
								} elseif (preg_match("/^summary_award_".strtolower($term)."$/", strtolower($field))) {
									# whole word match on field
									$points += 400 / $weight;
									$termsMatched[] = $term;
								} elseif (preg_match("/^summary_award_".strtolower($term)."/", strtolower($field))) {
									# partial word match on field
									$points += 200 / $weight;
									$termsMatched[] = $term;
								}
							}
							if ($points > 0) {
								$matches[$row['record_id'].":".$field] = $points;
								if (!isset($matchValues[$row['record_id']])) {
									$matchValues[$row['record_id']] = [];
									$matchAwards[$row['record_id']] = [];
								}
								$matchValues[$row['record_id']][$field] = $value;
								$n = getNumber($field);
								$matchAwards[$row['record_id']][$n] = [];
								foreach ($row as $field2 => $value2) {
									if (preg_match("/^summary_award_/", $field2)) {
										$nodes = preg_split("/_/", $field2);
										$n2 = $nodes[count($nodes) - 1];
										if ($n == $n2) {
											$group2 = preg_replace("/^summary_award_/", "", $field2);
											$group2 = preg_replace("/_\d+$/", "", $group2);
											if (in_array($fieldTypes[$field2], $multis)) {
												$value2 = $choices[$field2][$value2];
											}
											$matchAwards[$row['record_id']][$n][$group2] = $value2;
										}
									}
								}
							}
						}
					}
				}
			}
		}
	}
	?>
<form action='<?= CareerDev::link("search/index.php") ?>' method='POST'>
<?= Application::generateCSRFTokenHTML() ?>
<p class='centered'><input type='text' value='<?= preg_replace("/'/", "\'", $postQuery) ?>' name='q' id='q'> <input type='submit' value='Search'</p>
</form>
<?php
		$forms = [
				"identifier" => "identifiers",
				"summary" => "summary",
				];
	echo "<p class='centered'>".count($matches)." grants in ".count($matchAwards)." profiles matched.</p>";
	arsort($matches);
	$order = ["sponsorno", "title", "type", "date", "end_date", "total_budget", "direct_budget", "nih_mechanism", ];
	echo "<div class='centered' style='text-align: left; max-width: 800px;'>\n";
	foreach ($matches as $id => $points) {
		list($recordId, $field) = preg_split("/:/", $id);
		$nodes = preg_split("/_/", $field);
		$url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id=$recordId&event_id=$event_id&page=".$forms[$nodes[0]]."#".$field."-tr";
		echo "<p>";
		$name = $names[$recordId];
		echo "<a class='header' href='$url'>Record $recordId - $name <span class='small'>[$field]</span></a> - ".$matchValues[$recordId][$field];
		$n = getNumber($field);
		foreach ($order as $item) {
			$awardLine = $matchAwards[$recordId][$n][$item];
			if (preg_match("/_budget$/", $item)) {
				$awardLine = \Vanderbilt\FlightTrackerExternalModule\prettyMoney($awardLine);
			}
			$item = preg_replace("/_/", " ", $item);

			foreach ($terms as $term) {
				$awardLine = preg_replace("/".$term."/i", "<span class='highlighted'>$0</span>", $awardLine);
				$item = preg_replace("/$term/i", "<span class='highlighted'>$0</span>", $item);
			}

			echo "<br><b>".$item.":</b> ".$awardLine;
		}
		echo "</p>";
	}
	echo "</div>\n";
} else {
	?>
<form action='<?= CareerDev::link("search/index.php") ?>' method='POST'>
<?= Application::generateCSRFTokenHTML() ?>
<p class='centered'><input type='text' value='' name='q' id='q'> <input type='submit' value='Search'></p>
</form>
<?php
}
