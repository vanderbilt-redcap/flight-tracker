<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>

<style>
body { font-family: Arial, Helvetica, sans-serif; }
.red { background-color: #ffc3c4; }
.green { background-color: #caffc3; }
.yellow { background-color: #fffde2; }
.blue { background-color: #d5ddf6; }
.purple { background-color: #f4c3ff; }
.orange { background-color: #ffcd85; }
.grey { background-color: #dddddd; }
</style>

<?php
	require_once(dirname(__FILE__)."/../small_base.php");
	require_once(__DIR__ . '/ClassLoader.php');

	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);

	$prefix = "";
	if ($_GET['prefix']) {
		$prefix = $_GET['prefix'];
	}

	$files = scandir(dirname(__FILE__));

	$title = "Unit Tests";
	if ($prefix) {
		$title .= " for $prefix";
	}
	echo "<h1>$title</h1>\n";
	$numFiles = 0;
	foreach ($files as $file) {
		if (isValidPrefix($file, $prefix)) {
			$numFiles++;
		}
	}

	$html = "";
	$numTests = 0;
	$numResults = 0;
	foreach ($files as $file) {
		if (isValidPrefix($file, $prefix)) {
			require_once(dirname(__FILE__)."/".$file);
			$myClass = preg_replace("/\.php$/i", "", $file);

			if (class_exists("\\Vanderbilt\\CareerDevLibrary\\$myClass")) {
				$html .= "<h3 class='blue'>Examining $file</h3>\n";
				try {
					$classWithNamespace = "\\Vanderbilt\\CareerDevLibrary\\".$myClass;
					$obj = new $classWithNamespace($token, $server, $pid);
				} catch (Exception $e) {
					$html .= $e->getMessage()."<br>";
				}
				$tester = new \Vanderbilt\CareerDevLibrary\UnitTester();
				$tester->analyze($obj);

				$badResults = $tester->getFailures();
				$numBadResults = 0;
				foreach ($badResults as $test => $ary) {
					$numBadResults += count($ary);
				}
				if ($numBadResults > 0) {
					$html .= "<h4 class='red'>$numBadResults Failures</h4>\n";
					$html .= "<div id='$myClass"."_results'>";
				} else {
					$html .= "<h4 class='green' onclick='$(\"#$myClass"."_results\").show();'>All Passed</h4>\n";
					$html .= "<div id='$myClass"."_results'>";
				}
	
				$results = $tester->getResults();
				$numTests += count($results);
				foreach ($results as $test => $ary) {
					$html .= "<h3>$test</h3>\n";
					$numResults += count($ary['results']);
					foreach ($ary['results'] as $result) {
						if (preg_match("/FALSE/i", $result)) {
							$html .= "<span style='color: red;'>ERROR $result</span><br>\n";
						} else if (preg_match("/TRUE/i", $result)) {
							$html .= "$result<br>\n";
						} 
					}
				}
				$html .= "</div>";
			} else {
				$html .= "Skipping class $myClass from ".dirname(__FILE__)."/$file<br>";
			}
		}
	}
	echo "<h2 class='blue'>$numTests Tests with $numResults Results Executed over $numFiles files</h2>\n";
	echo $html;
	echo "<h2 class='blue'>Complete</h2>\n";

function isValidPrefix($file, $prefix) {
	$validPrefices = array("FlightTracker", "CareerDev");
	$skip = array(".", "..", "ModuleUnitTester.php", "UnitTester.php", "test.php", "testEmailManager.php", ".git");

	$filePrefix = preg_replace("/\.php$/i", "", $file);
	$filePrefix = preg_replace("/Tester/i", "", $filePrefix);
	$isValidPrefix = (strtolower($prefix) == strtolower($filePrefix));
	if (!$prefix) {
		$isValidPrefix = in_array($filePrefix, $validPrefices);
	}
	if ($isValidPrefix) {
		$isValidPrefix = !in_array($file, $skip);
	}
	return $isValidPrefix;
	
}
