<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

?>
<style>
a.button { color: black; text-decoration: none; padding: 0px 4px 0px 4px; border: 2px solid #888888; background-color: white; border-radius: 6px; }
</style>
<?php

$metadata = Download::metadata($token, $server);
$fields = Application::$summaryFields;
foreach ($metadata as $row) {
	if (preg_match("/mentor/", $row['field_name'])) {
		$fields[] = $row['field_name'];
	}
}

$redcapData = \Vanderbilt\FlightTrackerExternalModule\alphabetizeREDCapData(Download::fields($token, $server, $fields));

?>
<style>
h1,h2,td { text-align: center; }
tr.odd td,th { background-color: #dddddd; }
tr.even td,th { background-color: #eeeeee; }
.small { font-size: 12px; }
</style>

<?php

function addToMentors($value, $listOfMentors) {
	# split into individual names
	$smallValues = preg_split("/\s*[\s;,\.]\s*/", $value);
	$values = array();
	if (count($smallValues) == 3) {
		$values[] = $smallValues[0]." ".$smallValues[1]." ".$smallValues[2];
	} else {
		for ($i=1; $i<count($smallValues); $i+=2) {
			$values[] = $smallValues[$i-1]." ".$smallValues[$i];
		}
	}

	# try to match individual names
	foreach ($values as $value) {
		$names = preg_split("/\s*[,\s]\s*/", $value);
		$cnt = 0;
		foreach ($listOfMentors as $mentor) {
			foreach ($names as $name) {
				if (preg_match("/".$name."/i", $mentor)) {
					$cnt++;
				}
			}
		}
		if ($cnt < 2) {
			# no match
			if ($value == "Ikizler T") {
				if (!in_array("Ikizler Alp", $listOfMentors)) {
                    $listOfMentors[] = "Ikizler Alp";
				}
			} else {
                $listOfMentors[] = $value;
			}
		}
	}
	return $listOfMentors;
}

echo "<h1>Current Scholars and Their Mentors</h1>";
echo "<table style='display: none; margin-left: auto; margin-right: auto;'><tr class='even'><th>Record</th><th>Scholar</th><th>Mentor(s)</th><th>Qualifying Award</th><th>Converted On</th></tr>";
$cnt = 1;
$revAwardTypes = \Vanderbilt\FlightTrackerExternalModule\getReverseAwardTypes();
$menteesByMentor = array();
$numMentors = array();
$mentorRows = array();
$skip = array("vfrs_mentor2", "vfrs_mentor3", "vfrs_mentor4", "vfrs_mentor5");
foreach ($redcapData as $row) {
    if (!$row['identifier_last_name'] || !$row['identifier_first_name']) {
        continue;
    }
	$i = \Vanderbilt\FlightTrackerExternalModule\findEligibleAward($row);
	if ($cnt % 2 == 1) {
		$rowClass = "odd";
	} else {
		$rowClass = "even";
	}

	$myMentors = [];

	foreach ($row as $field => $value) {
		if (preg_match("/mentor/", $field) && !preg_match("/vunet/", $field) && ($value != '') && !in_array($field, $skip)) {
			if (!in_array($value, $myMentors)) {
				$myMentors = addToMentors($value, $myMentors);
			}
		}
	}
	if (!empty($myMentors)) {
		echo "<tr class='$rowClass'><td>{$row['record_id']}</td><td>{$row['identifier_first_name']} {$row['identifier_last_name']}</td>";
		echo "<td class='small'>".implode("<br>", $myMentors)."</td>";
		echo "<td class='small'>{$row['summary_award_sponsorno_'.$i]}<br>{$revAwardTypes[$row['summary_award_type_'.$i]]}<br>{$row['summary_award_date_'.$i]}</td>";
		echo "<td class='small'>".$row['summary_first_r01']."</td>";
		echo "</tr>";
		$cnt++;
		foreach ($myMentors as $myMentor) {
			$myNames = preg_split("/\s+/", $myMentor);
			$found = false;
			foreach ($menteesByMentor as $currMentor => $menteesByRecordId) {
				$matches = 0;
				foreach ($myNames as $myNamePart) {
					if (preg_match("/$myNamePart/i", $currMentor)) {
						$matches++;
					}
				}
				if ($matches >= 2) {
					$found = true;
                    $name = $row['identifier_first_name']." ".$row['identifier_last_name'];
                    if (!in_array($name, array_values($menteesByRecordId))) {
                        $menteesByRecordId[$row['record_id']] = $name;
                        $mentorRows[$currMentor][] = $row;
                        $numMentors[$currMentor]++;
                        $menteesByMentor[$currMentor] = $menteesByRecordId;
                    }
                    break;
				}
			}
			if (!$found) {
                $menteesByMentor[$myMentor] = [$row['record_id'] => $row['identifier_first_name']." ".$row['identifier_last_name']];
				$numMentors[$myMentor] = 1;
				$mentorRows[$myMentor] = [$row];
			}
		}
	}
}
echo "</table>";

function getConversionRate($rows) {
	$numer = 0;
	$denom = 0;

	$today = date("Y-m-d");
	$converted = array();
	$onK = array();
	$left = array();
	foreach ($rows as $row) {
		list($status, $date) = \Vanderbilt\FlightTrackerExternalModule\getConvertedStatus($row);
		if ($status == "Converted") {
			$converted[] = $row['identifier_first_name']." ".$row['identifier_last_name'];
			$numer++;
			$denom++;
		} else if ($status == "Left") {
			$left[] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		} else if ($status == "On External K") {
			$onK[] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		} else if ($status == "On Internal K") {
			$onK[] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		} else if ($status == "Not Converted") {
			$denom++;
		}
	}
	if ($denom === 0) {
		return array( "rate" => -100, "onK" => $onK, "converted" => $converted, "calc" => "0/0", "left" => $left);;
	}
	return array(
			"calc" => $numer."/".$denom,
			"rate" => (round($numer * 1000 / $denom) / 10),
			"onK" => $onK,
			"converted" => $converted,
			"left" => $left,
			);
}

function reformatMentees($menteesByRecordId, $convRateReturned) {
	global $pid;
	$menteesOut = [];
	foreach ($menteesByRecordId as $recordId => $mentee) {
		$url = Links::makeRecordHomeLink($pid, $recordId, $mentee);
		if (in_array($mentee, $convRateReturned['converted'])) {
			$menteesOut[] = $url." (R)";
		} else if (in_array($mentee, $convRateReturned['onK'])) {
			$menteesOut[] = $url." (K)";
		} else if (in_array($mentee, $convRateReturned['left'])) {
			$menteesOut[] = $url." (left)";
		} else if ($mentee) {
			$menteesOut[] = $url." (off K)";
		}
	}
	return $menteesOut;
}

arsort($numMentors);
$cnt = 1;
echo "<p class='centered'>(K) denotes on K or equivalent; (R) denotes on R01 or equivalent; (left) denotes left-institution; (off K) denotes a lack of conversion</p>";
echo "<h2><a class='button' href='javascript:;' onclick='$(\"#table1\").show(); $(\"#table2\").hide();'>Sorted by Number of Mentees</a></h2>";
echo "<h2><a class='button' href='javascript:;' onclick='$(\"#table1\").hide(); $(\"#table2\").show();'>Sorted by Conversion Rate</a></h2>";
echo "<table id='table1' style='display: none; margin-left: auto; margin-right: auto;'><tr class='even'><th>Mentor</th><th>Number of Mentees</th><th>Mentees</th><th>Conversion Rate</th></tr>";
$conversionRate = array();
$conversionCalc = array();
foreach ($numMentors as $mentor => $num) {
	if ($cnt % 2 == 1) {
		$rowClass = "odd";
	} else {
		$rowClass = "even";
	}

	$a = getConversionRate($mentorRows[$mentor]);
	$mentees = reformatMentees($menteesByMentor[$mentor], $a);

	$conversionRate[$mentor] = $a['rate'];
	$conversionCalc[$mentor] = $a['calc']; 
	$convRate = $conversionRate[$mentor];
	$convCalc = $conversionCalc[$mentor];
	if ($convRate < 0) {
		$convRate = "N/A";
	} else {
		$convRate = $convRate."%";
	}
	echo "<tr class='$rowClass'><td>$mentor</td><td>$num</td><td>".implode("<br>", $mentees)."</td><td>$convRate<br>$convCalc</td></tr>";
	$cnt++;
}
echo "</table>";

arsort($conversionRate);
echo "<table id='table2' style='display: none; margin-left: auto; margin-right: auto'><tr class='even'><th>Mentor</th><th>Number of Mentees</th><th>Mentees</th><th>Conversion Rate</th></tr>";
$cnt = 1;
foreach ($conversionRate as $mentor => $convRate) {
	if ($cnt % 2 == 1) {
		$rowClass = "odd";
	} else {
		$rowClass = "even";
	}
	$convCalc = $conversionCalc[$mentor];
	if ($convRate < 0) {
		$convRate = "N/A";
	} else {
		$convRate = $convRate."%";
	}
	$num = $numMentors[$mentor];
	$a = getConversionRate($mentorRows[$mentor]);
	$mentees = reformatMentees($menteesByMentor[$mentor], $a);

	echo "<tr class='$rowClass'><td>$mentor</td><td>$num</td><td>".implode("<br>", $mentees)."<td>$convRate<br>$convCalc</td></tr>";
	$cnt++;
}
echo "</table>";
