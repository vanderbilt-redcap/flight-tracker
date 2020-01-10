<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>

<?php
	require_once(dirname(__FILE__)."/../small_base.php");
	require_once(dirname(__FILE__)."/UnitTester.php");

	$files = scandir(".");

	$skip = array(".", "..", "ModuleUnitTester.php", "UnitTester.php", "test.php");
	echo "<h1>Unit Tests</h1>\n";
	$color = "#b1d8ff";
	$numFiles = 0;
	foreach ($files as $file) {
		if (!in_array($file, $skip)) {
			$numFiles++;
		}
	}
	echo "<h2>$numFiles files</h2>\n";
	foreach ($files as $file) {
		if (!in_array($file, $skip)) {
			require_once(dirname(__FILE__)."/".$file);
			echo "<h2 style='background-color: $color'>Examining $file</h2>\n";
			$myClass = preg_replace("/.php$/i", "", $file);

			try {
				$obj = new $myClass($token, $server, $pid);
			} catch (Exception $e) {
				echo $e->getMessage()."<br>";
			}
			$tester = new UnitTester();
			$tester->analyze($obj);

			$badResults = $tester->getFailures();
			$numBadResults = 0;
			foreach ($badResults as $test => $ary) {
				$numBadResults += count($ary);
			}
			if ($numBadResults > 0) {
				echo "<h4 style='background-color: #ff7c7c;'>$numBadResults Failures</h4>\n";
				echo "<div id='$myClass"."_results'>";
			} else {
				echo "<h4 style='background-color: #bdffb6;' onclick='$(\"#$myClass"."_results\").show();'>All Passed</h4>\n";
				echo "<div id='$myClass"."_results' style='display: none;'>";
			}

			$results = $tester->getResults();
			foreach ($results as $test => $ary) {
				echo "<h3>$test</h3>\n";
				foreach ($ary['results'] as $result) {
					if (preg_match("/FALSE/i", $result)) {
						echo "<span style='color: red;'>$result</span><br>\n";
					} else if (preg_match("/TRUE/i", $result)) {
						echo "$result<br>\n";
					} 
				}
			}
			echo "</div>";
		}
	}
