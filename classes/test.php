<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>

<?php
	require_once(dirname(__FILE__)."/../small_base.php");
	require_once(dirname(__FILE__)."/UnitTester.php");

	$files = scandir(".");

	$skip = array(".", "..", "ModuleUnitTester.php", "UnitTester.php", "test.php", "testEmailManager.php", ".git");
	echo "<h1>Unit Tests</h1>\n";
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
			$myClass = preg_replace("/.php$/i", "", $file)."Test";

			if (class_exists($myClass)) {
				echo "<h2 class='blue'>Examining $file</h2>\n";
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
					echo "<h4 class='red'>$numBadResults Failures</h4>\n";
					echo "<div id='$myClass"."_results'>";
				} else {
					echo "<h4 class='green' onclick='$(\"#$myClass"."_results\").show();'>All Passed</h4>\n";
					echo "<div id='$myClass"."_results' style='display: none;'>";
				}
	
				$results = $tester->getResults();
				foreach ($results as $test => $ary) {
					echo "<h3>$test</h3>\n";
					foreach ($ary['results'] as $result) {
						if (preg_match("/FALSE/i", $result)) {
							echo "<span class='red'>ERROR $result</span><br>\n";
						} else if (preg_match("/TRUE/i", $result)) {
							echo "$result<br>\n";
						} 
					}
				}
				echo "</div>";
			}
		}
	}
	echo "<p>Done</p>\n";
