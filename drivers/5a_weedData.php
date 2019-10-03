<?php

require_once(dirname(__FILE__)."/../charts/baseWeb.php");

# to be run on the web
# provides a mechanism for manually deleting duplicates while combining others

# initial page is in else; follow-up page is in if

if (isset($_POST['submit'])) {
	$recsToDelete = array();
	foreach ($_POST as $field => $value) {
		if (preg_match("/^delete\d+$/", $field) && ($value == 'checkbox')) {
			$rec = preg_replace("/^delete/", "", $field);
			$recsToDelete[] = $rec;
		} else if (preg_match("/^addto\d+$/", $field) && $value) {
			$src = preg_replace("/^addto/", "", $field);
			$dest = $value;
			if ($dest && $src) {
				echo "Downloading $src and $dest to combine $src into $dest<br>";
				$data = array(
						'token' => $token,
						'content' => 'record',
						'format' => 'json',
						'type' => 'flat',
						'records' => array($src, $dest),
						'rawOrLabel' => 'raw',
						'rawOrLabelHeaders' => 'raw',
						'exportCheckboxLabel' => 'false',
						'exportSurveyFields' => 'false',
						'exportDataAccessGroups' => 'false',
						'returnFormat' => 'json'
				);
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $server);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_VERBOSE, 0);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_AUTOREFERER, true);
				curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
				curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
				$output = curl_exec($ch);
				curl_close($ch);

				echo "Combining $src into $dest<br>";
				$redcapData = json_decode($output, true);
				$combined = array();

				# do not overwrite data
				$srcRow = array();
				$destRow = array();
				$srcMax = 0;
				$destMax = array();
				foreach ($redcapData as $row) {
					if (!isset($row['redcap_repeat_instrument']) || ($row['redcap_repeat_instrument'] == "")) {
						if ($row['record_id'] == $src) {
							$srcRow = $row;
						} else if ($row['record_id'] == $dest) {
							$destRow = $row;
						}
					} else {
						if (!isset($destMax[$row['redcap_repeat_instrument']])) {
							$destMax[$row['redcap_repeat_instrument']]  = 0;
						}
						if ($row['record_id'] == $dest) {
							if ($destMax[$row['redcap_repeat_instrument']] < $row['redcap_repeat_instance']) {
								$destMax[$row['redcap_repeat_instrument']]  = $row['redcap_repeat_instance'];
							}
						}
					}
				}
				$combined[] = array();
				foreach ($destRow as $field => $value) {
					$combined[0][$field] = $value;
				}
				foreach ($srcRow as $field => $value) {
					if (!isset($combined[0][$field]) || !$combined[0][$field]) {
						$combined[0][$field] = $value;
					}
				}
				$instance = array();
				foreach ($destMax as $instrument => $cnt) {
					$instance[$instrument] = $cnt + 1;
				}
				foreach ($redcapData as $row) {
					if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "coeus")) {
						# check for repeats
						$repeat = false;
						foreach ($redcapData as $row2) {
							if (($row2['record_id'] == $dest) && isset($row['redcap_repeat_instrument']) && ($row2['redcap_repeat_instrument'] == "coeus")) {
								if (($row2['coeus_award_seq'] == $row['coeus_award_no']) && ($row2['coeus_award_no'] == $row['coeus_award_no'])) {
									$repeat = true;
									break;
								}
							}
						}
						if (!$repeat) {
							if ($row['record_id'] == $src) {
								$row['redcap_repeat_instance'] = $instance[$row['redcap_repeat_instrument']];
								$instance[$row['redcap_repeat_instrument']]++;
							}
							$row['record_id'] = $dest;
							$combined[] = $row;
						}
					}
				}

				echo "Uploading $dest<br>";
				$data = array(
					'token' => $token,
					'content' => 'record',
					'format' => 'json',
					'type' => 'flat',
					'overwriteBehavior' => 'normal',
					'data' => json_encode($combined),
					'returnContent' => 'count',
					'returnFormat' => 'json'
				);
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $server);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_VERBOSE, 0);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_AUTOREFERER, true);
				curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
				curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
				$output = curl_exec($ch);
				print "Upload of combine of $src into $dest: ".$output."<br>";
				curl_close($ch);
			}
		} else {
			// echo "Uncategorized $field => $value<br>";
		}
	}

	if (!empty($recsToDelete)) {
		echo "Deleting ".implode(", ", $recsToDelete)."<br>";
		$data = array(
			'token' => $token,
			'action' => 'delete',
			'content' => 'record',
			'records' => $recsToDelete
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $server);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = curl_exec($ch);
		echo "Delete ".count($recsToDelete)." records: $output<br>";
		curl_close($ch);
	}
} else {
	$data = array(
        	'token' => $token,
        	'content' => 'record',
        	'format' => 'json',
        	'type' => 'flat',
        	'rawOrLabel' => 'raw',
        	'rawOrLabelHeaders' => 'raw',
        	'exportCheckboxLabel' => 'false',
        	'exportSurveyFields' => 'false',
        	'exportDataAccessGroups' => 'false',
        	'returnFormat' => 'json'
	);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $server);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
	$output = curl_exec($ch);
	curl_close($ch);

	echo "<form method='POST'>";
	echo "<input type='submit' name='submit' id='submit'><br>";
	echo "<table>";
	$redcapData = json_decode($output, true);
	$records = array();
	foreach ($redcapData as $row) {
		if (!in_array($row['record_id'], $records)) {
			$records[] = $row['record_id'];
		}
	}

	function fixName($n) {
		$n = str_replace("???", "", $n);
		return $n;
	}

	function searchForMatches($lastName, $data, $recordId) {
		$lastName1 = fixName(strtolower($lastName));
		$matches = array();
		foreach ($data as $row) {
			if (($row['record_id'] != $recordId) && ($row['redcap_repeat_instance'] === "")) {
				$lastName2 = fixName(strtolower($row['last_name']));
				$quality = "";
				if ($lastName1 == $lastName2) {
					$quality = "Exact";
				} else if (preg_match("/$lastName1/", $lastName2) || preg_match("/$lastName2/", $lastName1)) {
					$quality = "Pseudo";
				}
				if ($quality) {
					$matches[] = "[$quality {$row['record_id']}: {$row['first_name']} {$row['last_name']}]";
				}
			}
		}
		return $matches;
	}

	$tab = 1;
	foreach ($redcapData as $row) {
		if ($row['redcap_repeat_instrument'] == "") {
			echo "<tr><td>";
			echo "{$row['record_id']} {$row['first_name']} {$row['last_name']}<br>";

			$disabled1 = " readonly";
			$disabled2 = " readonly";
			$matches = searchForMatches($row['last_name'], $redcapData, $row['record_id']);
			if (!empty($matches)) {
				echo "<b>Matches: (".implode(", ", $matches).")</b><br>";
				$disabled1 = " tabindex=$tab";
				$tab++;
				$disabled2 = " tabindex=$tab";
				$tab++;
			}

			$types = getDataTypes($redcapData, $row['record_id']);
			echo "Data types (".count($types)."): ".implode("; ", $types)."<br>";

			echo "Add to record <input type='text' name='addto{$row['record_id']}' id='addto{$row['record_id']}' onblur='if (this.value) { document.getElementById(\"delete{$row['record_id']}\").checked = true; }' $disabled1><br>";
			echo "<input type='checkbox' value='checkbox' id='delete{$row['record_id']}' name='delete{$row['record_id']}' $disabled2> Delete record {$row['record_id']}";
			echo "</td></tr>";
			echo "<tr><td>&nbsp;</td></tr>";
		}
	}
	echo "</table></form>";
}

function getDataTypes($data, $recordId) {
	$prefices = array(
			"demographics" => "newman_demographics",
			"data" => "newman_data",
			"sheet2" => "newman_sheet2",
			"nonrespondents" => "newman_nonrespondents",
			"vfrs" => "vfrs",
			"kl2" => "kl2_kl2",
			"coeus" => "coeus",
			);
	$found = array();
	foreach ($data as $row) {
		if ($row['record_id'] == $recordId) {
			foreach ($row as $field => $value) {
				if ($value && !preg_match("/_complete$/", $field)) {
					foreach ($prefices as $group => $prefix) {
						if (preg_match("/^$prefix/", $field)) {
							if (!in_array($group, $found)) {
								$found[] = $group;
							}
							break;  // prefix loop
						}
					}
				}
			}
		}
	}
	return $found;
}
?>
