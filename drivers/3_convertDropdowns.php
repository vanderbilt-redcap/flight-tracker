<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../small_base.php");

# Convert the dropdowns from old text format (values and strings) to a numerical dropdown
# Requires some user input

echo "Attempting download of metadata\n";
$data = [
	'token' => $token,
	'content' => 'metadata',
	'format' => 'json',
	'returnFormat' => 'json'
];
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
echo "Downloaded metadata... $token $server $output\n";

$metadata = json_decode($output, true);
echo count($metadata)." rows\n";

# a list of all dropdowns; may be dated
$dropdowns = [
			"newman_demographics_department1",
			"newman_demographics_department2",
			"newman_demographics_academic_rank",
			"newman_demographics_gender",
			"newman_demographics_race",
			"newman_demographics_ethnicity",
			"newman_demographics_degrees",
			"newman_data_degree1",
			"newman_data_degree2",
			"newman_data_degree3",
			"newman_data_department1",
			"newman_data_department2",
			"newman_data_rank",
			"newman_data_race",
			"newman_data_ethnicity",
			"newman_data_gender",
			"newman_sheet2_degree1",
			"newman_sheet2_degree2",
			"newman_sheet2_degree3",
			"newman_sheet2_department1",
			"newman_sheet2_department2",
			"newman_sheet2_rank",
			"newman_nonrespondents_gender",
			"newman_nonrespondents_race",
			"newman_nonrespondents_ethnicity",
			"newman_nonrespondents_rank",
			"summary_primary_dept",
		];
$depts = [
						"newman_demographics_department1",
						"newman_demographics_department2",
						"newman_data_department1",
						"newman_data_department2",
						"newman_sheet2_department1",
						"newman_sheet2_department2",
						"summary_primary_dept",
		];
$dropdownChoices = [];
$dropdownTypes = [];
foreach ($dropdowns as $field) {
	$dropdownChoices[$field] = [];
	if (preg_match("/_dept/", $field) || preg_match("/_department/", $field)) {
		$dropdownChoices[$field][] = "vfrs_department";
		$dropdownTypes[$field] = "dropdown";
	}
	if (preg_match("/_gender/", $field)) {
		$dropdownChoices[$field][] = "vfrs_gender";
		$dropdownTypes[$field] = "radio";
	}
	if (preg_match("/_race/", $field)) {
		$dropdownChoices[$field][] = "vfrs_race";
		$dropdownTypes[$field] = "radio";
	}
	if (preg_match("/_ethnicity/", $field)) {
		$dropdownChoices[$field][] = "vfrs_ethnicity";
		$dropdownTypes[$field] = "radio";
	}
	if (preg_match("/_degree/", $field)) {
		$dropdownChoices[$field][] = "vfrs_graduate_degree";
		$dropdownChoices[$field][] = "vfrs_degree3";
		$dropdownTypes[$field] = "dropdown";
	}
	if (preg_match("/_rank/", $field)) {
		$dropdownChoices[$field][] = "vfrs_current_appointment";
		$dropdownTypes[$field] = "dropdown";
	}
}

# makes an array out of the choices string (1, abc | 2, def)
function makeChoiceArray($choiceStr) {
	$choices = preg_split("/\s*\|\s*/", $choiceStr);
	$choices2 = [];
	foreach ($choices as $choice) {
		$a = preg_split("/\s*,\s*/", $choice);
		if (count($a) > 2) {
			$b = [];
			for ($i = 1; $i < count($a); $i++) {
				$b[] = $a[$i];
			}
			$a = [$a[0], implode(", ", $b)];
		}
		$choices2[$a[0]] = $a[1];
	}
	if (preg_match("/MD/", $choiceStr)) {
		$choices2[6] = "MD, MPH";
		$choices2[7] = "MD, MSCI";
		$choices2[8] = "MD, MS";
		$choices2[9] = "MD, PhD";
		$choices2[10] = "MS, MD, PhD";
		$choices2[11] = "MHS";
		$choices2[12] = "MD, MHS";
		$choices2[13] = "PharmD";
		$choices2[14] = "MD, PharmD";
		$choices2[15] = "PsyD";
		$choices2[16] = "MPH, MS";
		$choices2[17] = "RN";
	}
	if (preg_match("/Assistant Professor/", $choiceStr)) {
		unset($choices2[0]);
		$choices2[1] = "Research Fellow";
		$choices2[2] = "Clinical Fellow";
		$choices2[3] = "Instructor";
		$choices2[4] = "Research Assistant Professor";
		$choices2[5] = "Assistant Professor";
		$choices2[6] = "Associate Professor";
		$choices2[7] = "Professor";
		$choices2[8] = "Other";
	}
	if (preg_match("/Medicine/", $choiceStr)) {
		$choices2 = getDepartmentChoices();
	}
	if (isset($choices2[0])) {
		$choices3 = [];
		foreach ($choices2 as $i => $choice) {
			$choices[$i + 1] = $choice;
		}
		return $choices3;
	}
	return $choices2;
}

# make a string from the choices array
function makeChoiceString($choices) {
	$choices2 = [];
	foreach ($choices as $a => $b) {
		$choices2[] = "$a, $b";
	}

	return implode(" | ", $choices2);
}

$metadataNew = [];
foreach ($metadata as $row) {
	if ($row['field_name'] == "newman_demographics_primary_dept") {
		$row['field_name'] = "newman_demographics_department1";
		$metadataNew[] = $row;
		$row['field_name'] = "newman_demographics_department2";
		$metadataNew[] = $row;
	} else {
		$metadataNew[] = $row;
	}
}
$metadata = $metadataNew;
unset($metadataNew);

# make choices arrays
foreach ($metadata as $row) {
	$fieldName = $row['field_name'];
	foreach ($dropdownChoices as $field => $ary) {
		$i = 0;
		foreach ($ary as $source) {
			if ($source == $fieldName) {
				$dropdownChoices[$field][$i] = makeChoiceArray($row['select_choices_or_calculations']);
			}
			$i++;
		}
	}
}
foreach ($dropdownChoices as $field => $ary) {
	$ary2 = [];
	foreach ($ary as $a) {
		foreach ($a as $value => $label) {
			$ary2[$value] = $label;
		}
	}
	$dropdownChoices[$field] = $ary2;
}

$i = 0;
foreach ($metadata as $row) {
	if (in_array($row['field_name'], $dropdowns)) {
		$choices = makeChoiceString($dropdownChoices[$row['field_name']]);
		$type = $dropdownTypes[$row['field_name']];
		echo "Setting $i {$row['field_name']} to $type $choices\n";
		$metadata[$i]['select_choices_or_calculations'] = $choices;
		$metadata[$i]['field_type'] = $type;
	}
	$i++;
}

# backs up the metadata
$fp = fopen("metadata.json", "w");
fwrite($fp, json_encode($metadata));
fclose($fp);

# uploads the new metadata
$data = [
	'token' => $token,
	'content' => 'metadata',
	'format' => 'json',
	'data' => json_encode($metadata),
	'returnFormat' => 'json'
];
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
echo "Metadata upload: ".$output."\n";
curl_close($ch);


# download record IDs
$data = [
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'fields' => ['record_id'],
	'type' => 'flat',
	'rawOrLabel' => 'raw',
	'rawOrLabelHeaders' => 'raw',
	'exportCheckboxLabel' => 'false',
	'exportSurveyFields' => 'false',
	'exportDataAccessGroups' => 'false',
	'returnFormat' => 'json'
];
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

$rows = json_decode($output, true);
$recordIds = [];
foreach ($rows as $row) {
	$recordId = $row['record_id'];
	if (!in_array($recordId, $recordIds)) {
		$recordIds[] = $recordId;
	}
}

unset($output);
unset($rows);
echo count($recordIds)." record ids.\n";

# pull 40 records at a time. Modify in batches
$pullSize = 40;
$numPulls = ceil(count($recordIds) / $pullSize);
for ($pull = 0; $pull < $numPulls; $pull++) {
	$records = [];
	for ($j = 0; ($j < $pullSize) && ($j + 1 + $pullSize * $pull <= count($recordIds)) ; $j++) {
		$records[] = $pullSize * $pull + $j + 1;
	}
	$data = [
		'token' => $token,
		'content' => 'record',
		'format' => 'json',
		'records' => $records,
		'type' => 'flat',
		'rawOrLabel' => 'raw',
		'rawOrLabelHeaders' => 'raw',
		'exportCheckboxLabel' => 'false',
		'exportSurveyFields' => 'false',
		'exportDataAccessGroups' => 'false',
		'returnFormat' => 'json'
	];
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

	$records = json_decode($output, true);
	$departments = [];
	$departmentFields = [];
	$i = 0;
	foreach ($records as $row) {
		foreach ($row as $field => $value) {
			if (isset($dropdownChoices[$field])) {
				if ($value !== "") {
					$value2 = $value;
					if (!isset($dropdownChoices[$field][$value2])) {
						$value = str_replace("&", "and", $value);

						# autopopulate some options
						if (($value == "Asst Prof.") || ($value == "Asst Prof") || ($value == "Asst. Prof") || ($value == "Asst. Prof.") || ($value == "Assistant") || ($value == "Assistant Prof") || ($value == "Assdt Prof") || ($value == "Prof") || ($value == "Professor")) {
							$value = "Assistant Professor";
						} elseif (($value == "Assoc. Prof.") || ($value == "Assoc. Prof") || ($value == "Assc Prof") || ($value == "Assoc Prof") || ($value == "Research Assc Prof")) {
							$value = "Associate Professor";
						} elseif ($value == "MB") {
							$value = "Other";
						} elseif ($value == "MSc") {
							$value = "MS";
						} elseif ($value == "MSPH") {
							$value = "MPH";
						} elseif (($value == "MD MPH") || ($value == "MD,MPH")) {
							$value = "MD, MPH";
						} elseif ($value == "MD MSCI") {
							$value = "MD, MSCI";
						} elseif ($value == "MD, MSc") {
							$value = "MD, MS";
						} elseif ($value == "MD PhD") {
							$value = "MD, PhD";
						} elseif ($value == "MPH MSc") {
							$value = "MPH";
						} elseif (($value == "Adjunct") || ($value == "Adj Assc Prof")) {
							$value = "Other";
						} elseif (($value == "Rsch Fellow") || ($value == "Fellow") || ($value == "Resch Fellow")) {
							$value = "Research Fellow";
						} elseif (($value == "Rsch Assist Prof") || ($value == "Staff Scientist Asst") || ($value == "Rsch Asst Prof") || ($value == "Res. Asst. Prof.") || (preg_match("/^Rsch Asst Prof/", $value))) {
							$value = "Research Assistant Professor";
						} elseif (($value == "Rsch Instructor") || ($value == "Adjoint Instructor") || ($value == "Research Instructor") || ($value == "Res. Instructor") || ($value == "Res. Inst.") || ($value == "Resch Instructor")) {
							$value = "Instructor";
						} elseif (($value == "Pathology") || ($value == "Pathology, Microbiology & Immunology") || ($value == "Pathology, Microbiology and Immunology")) {
							$value = "Pathology";
						} elseif ($value == "Rad. Oncology") {
							$value = "Radiation Oncology";
						} elseif ($value == "Biomed. Info.") {
							$value = "Biomedical Informatics";
						} elseif (($value == "Meharry - Surgery") || ($value == "Medicine (Surgery)")) {
							$value = "Surgery";
						} elseif ($value == "M") {
							$value = "Male";
						} elseif ($value == "F") {
							$value = "Female";
						} elseif ($value == "More than one race") {
							$value = "More Than One Race";
						} elseif (($value == "Hispanic") || ($value == "H")) {
							$value = "Hispanic or Latino";
						} elseif ($value == "W") {
							$value = "White";
						} elseif ($value == "NH") {
							$value = "Non-Hispanic";
						} elseif (($value == "Clinical Instructor") || ($value == "Resident Physician")) {
							$value = "Other";
						} elseif (($value == "Af-Am") || ($value == "AA") || ($value == "Black") || ($value == "Ugandan")) {
							$value = "Black or African American";
						} elseif ($value == "A") {
							$value = "Asian";
						} elseif (preg_match("/PhD /", $value) || preg_match("/, PhD/", $value) || ($value == "Ph.D")) {
							$value = "PhD";
						} elseif ($value == "BSN") {
							$value == "RN";
						} elseif ($value == "Clinical Fellow") {
							$value = "Research Fellow";
						} elseif ($value == "Psychologist") {
							$value = "Other";
						} elseif ($value == "Rsch Asst. Prof") {
							$value = "Research Assistant Professor";
						} elseif ($value == "Sci Review Officer NIH") {
							$value = "Other";
						} elseif ($value == "Asst. Clin. Prof.") {
							$value = "Assistant Professor";
						} elseif ($value == "Postdoc") {
							$value = "Research Fellow";
						} elseif ($value == "Research Asst Prof") {
							$value = "Research Assistant Professor";
						} elseif ($value == "Research Asst Prof") {
							$value = "Research Assistant Professor";
						} elseif ($value == "Res Asst Prof") {
							$value = "Research Assistant Professor";
						} elseif ($value == "Postdoctoral Fellow") {
							$value = "Research Fellow";
						} elseif ($value == "Asst Pfo") {
							$value = "Assistant Professor";
						} elseif ($value == "OH") {
							$value = "Unknown";
						} elseif (($value == "Pediactrics") || ($value == "Pediatrics")) {
							$value = "General Pediatrics";
						} elseif (preg_match("/^Medicine/", $value)) {
							$value = "General Internal Medicine";
						} elseif ($value == "Urology") {
							$value = "Medicine ";
						} elseif ($value == "Surgical Sciences/General Surgery") {
							$value = "Surgical Sciences";
						} elseif ($value == "Pediatrics/ID") {
							$value = "Pediatrics";
						} elseif ($value == "SON-Cardiovascular Medicine") {
							$value = "Nursing";
						} elseif ($value == "VMS PGY5 Radiation Oncology") {
							$value = "Radiation Oncology";
						} elseif ($value == "Psychiatry/Child-Adolescent Psych") {
							$value = "Psychiatry";
						} elseif ($value == "Opthomology") {
							$value = "Ophthalmology and Visual Sciences";
						} elseif ($value == "Pediatrics/ Pulmonary Allergy and Immunology") {
							$value = "Pediatrics/Pulmonary";
						} elseif ($value == "Preventative Medicine") {
							$value = "Preventive Medicine";
						} elseif ($value == "Pediatrics/Pediatric Hematology") {
							$value = "Pediatrics/Hematology";
						} elseif ($value == "School of Nursing") {
							$value = "School of Nursing - Research Faculty";
						} elseif ($value == "PMI") {
							$value = "Pathology";
						} elseif ($value == "Cell and Developmental Bio., Dept of") {
							$value = "Cell and Developmental Biology";
						} elseif ($value == "Physical Medicine and Rehabilitation") {
							$value = "Orthopaedic Surgery and Rehabilitation";
						} elseif ($value == "Surgery/Meharry Medical College") {
							$value = "Other";
						} elseif ($value == "Meharry") {
							$value = "Other";
						} elseif ($value == "Ob. and Gyn.") {
							$value = "Obstetrics and Gynecology";
						} elseif (($value == "Orthopaedics and Rehabilitation") || ($value == "Orthopaedics")) {
							$value = "Orthopaedic Surgery and Rehabilitation";
						} elseif ($value == "Internal Medicine/Hematology /Oncology") {
							$value = "Medicine/Hematology Oncology";
						} elseif ($value == "Radiology and Radiological Science") {
							$value = "Radiology and Radiological Sciences";
						}
						if (preg_match("/_degree/", $field)) {
							$textOptions = [];
							foreach ($dropdownChoices[$field] as $choiceValue => $label) {
								$textOptions[] = $label;
							}
							if (!in_array($value, $textOptions)) {
								$value = "Other";
							}
						}
						foreach ($dropdownChoices[$field] as $choiceValue => $label) {
							if (in_array($field, $depts)) {
								$value = str_replace("/", "\\\/", $value);
								$value = str_replace(".", "\\.", $value);
								if (preg_match("/$value/", $label)) {
									$value2 = $choiceValue;
									$records[$i][$field] = $value2;
									break;
								}
							} else {
								if ($value == $label) {
									$value2 = $choiceValue;
									$records[$i][$field] = $value2;
									break;
								}
							}
						}
						if (!isset($dropdownChoices[$field][$value2])) {
							if (!preg_match("/_dept/", $field) && !preg_match("/_department/", $field)) {
								echo $row['record_id'].": $field='$value2' not in ";
								echo makeChoiceString($dropdownChoices[$field]);
								echo "\n";
							} else {
								$maxScore = -1;
								$maxItem = "";
								$maxValue = "";
								foreach ($dropdownChoices[$field] as $myvalue => $mylabel) {
									$score = similar_text($value, $mylabel);
									if ($score > $maxScore) {
										$maxScore = $score;
										$maxItem = $mylabel;
										$maxValue = $myvalue;
									}
								}
								echo json_encode($dropdownChoices[$field])."\n";
								echo "($i of ".count($records).") $field - $value          Rec: ".$maxValue." ".$maxItem."\n";
								$line = readline("$field> ");
								$value2 = trim($line);
								$records[$i][$field] = $value2;

								$field2 = str_replace("1", "2", $field);
								if (($field != $field2) && (isset($records[$i][$field2]))) {
									$line2 = readline("$field2> ");
									$records[$i][$field2] = trim($line2);
								}
							}
						}
					}
				}
			}
		}
		$i++;
	}

	echo "\n";
	$found = [];
	foreach ($records as $row) {
		foreach ($dropdowns as $dropdown) {
			if ($row[$dropdown] && !is_numeric($row[$dropdown])) {
				echo "$recordId {$row['first_name']} {$row['last_name']} $dropdown is not numeric: {$row[$dropdown]}\n";
				$found[$row['record_id']] = $dropdown;
			}
		}
	}
	echo "\n";
	if (!empty($found)) {
		foreach ($found as $recordId => $dropdown) {
			$i = 0;
			foreach ($records as $row) {
				if (($row['record_id'] == $recordId) && ($row[$dropdown])) {
					echo "$recordId {$row['first_name']} {$row['last_name']} $dropdown is not numeric: {$row[$dropdown]}\n";
					echo "Blank out? ";
					$result = "";
					$valids = ["y", "n"];
					while (!in_array($result, $valids)) {
						$result = readline("y/n> ");
						$result = trim($result);
						if ($result == "y") {
							$records[$i][$dropdown] = "";
						}
					}
					break;  // records
				}
				$i++;
			}
		}
	}

	echo ($pull + 1)." of $numPulls) Attempting to upload...\n";

	$data = [
		'token' => $token,
		'content' => 'record',
		'format' => 'json',
		'type' => 'flat',
		'overwriteBehavior' => 'overwrite',
		'data' => json_encode($records),
		'returnContent' => 'count',
		'returnFormat' => 'json'
	];
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
	echo ($pull + 1).": Data upload: '".$output."'\n";
	curl_close($ch);
}
