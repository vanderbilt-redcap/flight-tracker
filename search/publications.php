<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

?>
<style>
.centered { text-align: center; }
h1,h2,h3,h4 { text-align: center; }
.header { font-size: 18px; }
input[type=text] { font-size: 18px; width: 400px; }
input[type=submit] { font-size: 18px; }
.highlighted { background-color: yellow; }
.small { font-size: 13px; }
</style>
<?php

function splitTerms($regex, $str) {
	$ary = preg_split($regex, $str);
	$ary2 = array();
	$i = 0;
	while ($i < count($ary)) {
		$item = $ary[$i];
		$i++;
		if (($i < count($ary)) && (strlen($ary[$i]) <= 2 && strlen($ary[$i]) > 0)) {
			# assume one or two letters after a string are initials
			$ary2[] = $item." ".$ary[$i];
			$i++;
		} else {
			$ary2[] = $item;
		}
	}
	return $ary2;
}

echo "<h1>Search <?= CareerDev::getProgramName() ?> Publications</h1>\n";
echo "<h4>Specify initial(s) <u>after</u> a name</h4>";

$postQuery = "";
if (isset($_POST['q']) && $_POST['q']) {
    $postQuery = Sanitizer::sanitizeWithoutChangingQuotes($_POST['q']);
	$metadata = Download::metadata($token, $server);

	$terms = splitTerms("/\s+/", $postQuery);
	$scores = array();    // record_id:citation_num as key
	$matchedCitations = array();    // record_idi, citation_num as keys
	$names = \Vanderbilt\FlightTrackerExternalModule\getAlphabetizedNames($token, $server);
	$citationCount = 0;
	foreach ($names as $recordId => $name) {
		$citationData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), array($recordId));
		$pubs = new Publications($token, $server, $metadata);
		$pubs->setRows($citationData);
		$citations = $pubs->getCitations("Included");
		$citationCount += $pubs->getCount();
		foreach ($citations as $citation) {
			if ($citation) {
				$citationText = $citation->getCitation();
				$instance = $citation->getInstance();
				$score = 0;
				foreach ($terms as $term) {
					$term = preg_replace("/\//", "", $term);
					if (preg_match("/".strtolower($term)."/", strtolower($citationText))) {
						$score += count(preg_split("/".strtolower($term)."/", strtolower($citationText))) - 1;
					}
				}
				if (is_numeric($instance)) {
					if ($score > 0) {
						$scores[$recordId.":".$citation->getInstance()] = $score;
						if (!isset($matchedCitations[$recordId])) {
							$matchedCitations[$recordId] = [];
						}
						$matchedCitations[$recordId][(string) $instance] = $citation;
					}
				} else {
					throw new \Exception("$instance is not numeric! Record $recordId. ".$citationText."<br>".json_encode($citationData));
				}
			} else {
				throw new \Exception("Record $recordId has a NULL citation!");
			}
		}
	}
	arsort($scores);
	$scoreOrder = array();
	foreach ($scores as $id => $score) {
		if (!isset($scoreOrder[$score])) {
			$scoreOrder[$score] = array();
		}
		$scoreOrder[$score][] = $id;
	}
?>

<form action='<?= CareerDev::link("search/publications.php") ?>' method='POST'>
    <?= Application::generateCSRFTokenHTML() ?>
<p class='centered'><input type='text' value='<?= Sanitizer::sanitizeOutput($postQuery) ?>' name='q' id='q'> <input type='submit' value='Search'</p>
</form>
<?php
	echo "<p class='centered header'>".\Vanderbilt\FlightTrackerExternalModule\pretty(count($scores))." citations in ".\Vanderbilt\FlightTrackerExternalModule\pretty(count($matchedCitations))." profiles matched.</p>";
	echo "<p class='centered'>The database has ".\Vanderbilt\FlightTrackerExternalModule\pretty($citationCount)." citations in ".\Vanderbilt\FlightTrackerExternalModule\pretty(count($names))." profiles.</p>";
	echo "<div class='centered' style='text-align: left; max-width: 800px;'>\n";
	foreach ($scoreOrder as $score => $idAry) {
		foreach ($names as $recordId => $name) {
			foreach ($idAry as $id) {
				list($record_id, $instance) = preg_split("/:/", $id);
				$record_id = (int) $record_id;
				if (($record_id == $recordId) && isset($matchedCitations[$record_id])) {
					$citation = $matchedCitations[$record_id][$instance] ?? FALSE;
					if ($citation) {
						$pmid = $citation->getPMID();
						$citationText = $citation->getCitationWithLink();
						foreach ($terms as $term) {
							$citationText = preg_replace("/".$term."/i", "<span class='highlighted'>$0</span>", $citationText);
						}
						echo "<p>".Links::makePublicationsLink($pid, $record_id, $event_id, "Record $record_id Instance $instance ({$names[$record_id]})", $instance)."<br><span class='small'>$citationText</span></p>";
					} else {
						throw new \Exception("Missing citation for $id!");
					}
				}
			}
		}
	}
	echo "</div>\n";
} else {
?>
<form action='<?= CareerDev::link("search/publications.php") ?>' method='POST'>
    <?= Application::generateCSRFTokenHTML() ?>
<p class='centered'><input type='text' value='' name='q' id='q'> <input type='submit' value='Search'></p>
</form>
<?php
}
