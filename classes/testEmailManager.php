<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>

<?php
	use \Vanderbilt\CareerDevLibrary\Download;
	use \Vanderbilt\CareerDevLibrary\EmailManager;
	use \Vanderbilt\CareerDevLibrary\UnitTester;

	define("NOAUTH", TRUE);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    require_once(dirname(__FILE__)."/../small_base.php");
	require_once(dirname(__FILE__)."/UnitTester.php");

	echo "<h1>Email Manager Unit Tests</h1>\n";
	$color = "#b1d8ff";
	require_once(dirname(__FILE__)."/EmailManager.php");
	require_once(dirname(__FILE__)."/Download.php");

	try {
		$metadata = Download::metadata($token, $server);
		$obj = new EmailManager($token, $server, $pid, NULL, $metadata);
	} catch (Exception $e) {
		echo $e->getMessage()."<br>";
	}
	$tester = new UnitTester();
	try {
        $tester->analyze($obj);
    } catch (Exception $e) {
        echo $e->getMessage()."<br>";
    }
	$myClass = get_class($obj);

	$badResults = $tester->getFailures();
	$numBadResults = 0;
	foreach ($badResults as $test => $ary) {
		$numBadResults += count($ary);
	}
	if ($numBadResults > 0) {
		echo "<h4 style='background-color: #ff7c7c;'>$numBadResults Failures from ".$tester->getTestCount()." tests</h4>\n";
		echo "<div id='$myClass"."_results'>";
	} else {
		echo "<h4 style='background-color: #bdffb6;' onclick='$(\"#$myClass"."_results\").show();'>All Passed (".$tester->getTestCount().")</h4>\n";
		echo "<div id='$myClass"."_results' style='display: none;'>";
	}

	$results = $tester->getResults();
	foreach ($results as $test => $ary) {
		echo "<h3>$test</h3>\n";
		foreach ($ary['results'] as $result) {
			if (preg_match("/FALSE/i", $result)) {
				echo "<span style='color: red;'>ERROR: $result</span><br>\n";
			} else if (preg_match("/TRUE/i", $result)) {
				echo "$result<br>\n";
			}
		}
	}
	echo "</div>";
