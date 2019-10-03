<?php

require_once(dirname(__FILE__)."/../small_base.php");

require_once(dirname(__FILE__)."/../../Core/bootstrap.php");
require_once(dirname(__FILE__)."/../../Core/Libraries/LdapLookup.php");

function getLDAPMultiple($values, $count = 1) {
	if ($count <= 5) {
		try {
        		$info = \Plugin\LdapLookup::lookupUserDetailsByKeys(array_values($values), array_keys($values), "and", false);
			return $info;
		} catch (Exception $e) {
			echo $e->getMessage();
			echo "Sleeping...\n";
			sleep(15);
			echo "Attempt ".($count + 1)."\n";
			return getLDAPMultiple($values, $count + 1);
		}
	}
}

function getLDAP($type, $value, $count = 1)
{
	if ($count <= 5) {
		try {
        		$info = \Plugin\LdapLookup::lookupUserDetailsByKey($value, $type);
			return $info;
		} catch (Exception $e) {
			echo $e->getMessage();
			echo "Sleeping...\n";
			sleep(15);
			echo "Attempt ".($count + 1)."\n";
			return getLDAP($type, $value, $count + 1);
		}
	}
}


$data = array(
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'type' => 'flat',
	'fields' => array('record_id', 'identifier_first_name', 'identifier_last_name', 'identifier_vunet'),
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

$upload = array();
$redcapData = json_decode($output, true);
$values = array("Yeses" => 0, "Nos" => 0, "Blanks" => 0, "Fixed" => 0, "Errors" => array());
foreach ($redcapData as $row) {
	if ($row['identifier_vunet']) {
		$info = getLDAP("uid", $row['identifier_vunet']);
		if ($info) {
			$value = '1';
			$values["Yeses"]++;
		} else {
			$value = '0';
			$values["Nos"]++;
		}
		$upload[] = array("record_id" => $row['record_id'], "identifier_in_ldap" => $value);

		echo "Fetched $value for record {$row['record_id']} {$row['identifier_first_name']} {$row['identifier_last_name']}\n";

		# prevent over-taxing the server
		sleep(1);
	} else {
		echo "Writing blank record {$row['record_id']}  {$row['identifier_first_name']} {$row['identifier_last_name']}\n";
		$lastNames = preg_split("/[\s\-]+/", $row['identifier_last_name']);
		$firstNames = preg_split("/[\s\-]+/", $row['identifier_first_name']);
		$found = false;
		foreach ($lastNames as $lastName) {
			if (!$found) {
				$lastName = preg_replace("/^\(/", "", $lastName);
				$lastName = preg_replace("/\)$/", "", $lastName);

				foreach ($firstNames as $firstName) {
					$firstName = preg_replace("/^\(/", "", $firstName);
					$firstName = preg_replace("/\)$/", "", $firstName);
					$info = getLDAPMultiple(array("sn" => strtolower($lastName), "givenname" => $firstName));
					$uids = array();
					if ($info) {
						$acceptableClasses = array("Faculty", "Vanderbilt Medical Group");
						foreach ($info as $infoRow) {
							if (in_array($infoRow['vanderbiltpersonemployeeclass'][0], $acceptableClasses)) {
								echo $infoRow['givenname'][0]." ".$lastName.": ".$infoRow['vanderbiltpersonemployeeclass'][0]."\n";
								$uids[] = $infoRow['uid'][0];
							}
						}
					} else {
						echo "null\n";
					}
					echo "\n";
					if (empty($uids)) {
						$values["Blanks"]++;
						$upload[] = array("record_id" => $row['record_id'], "identifier_in_ldap" => '');
						$found = true;
						break;
					} else if (count($uids) == 1) {
							$upload[] = array("record_id" => $row['record_id'], "identifier_in_ldap" => '1', 'identifier_vunet' => $uids[0]);
						$values["Fixed"]++;
						$found = true;
						break;
					} else {
						$values["Errors"][] = json_encode($info);
					}
				}
			}
		}
	}
}

echo "Values: ".json_encode($values)."\n";
echo "Uploading results\n";
$data = array(
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'type' => 'flat',
	'overwriteBehavior' => 'overwrite',
	'forceAutoNumber' => 'false',
	'data' => json_encode($upload),
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
curl_close($ch);

echo "Uploaded: $output\n";

require_once(dirname(__FILE__)."/../../../redcap_connect.php");
\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "CareerDev LDAP script", "Complete: ".json_encode($values));
