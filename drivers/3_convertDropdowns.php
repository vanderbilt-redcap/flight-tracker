<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../small_base.php");

# Convert the dropdowns from old text format (values and strings) to a numerical dropdown
# Requires some user input

echo "Attempting download of metadata\n";
$data = array(
    'token' => $token,
    'content' => 'metadata',
    'format' => 'json',
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
echo "Downloaded metadata... $token $server $output\n";

$metadata = json_decode($output, true);
echo count($metadata)." rows\n";

# a list of all dropdowns; may be dated
$dropdowns = array(
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
		);
$depts = array(
                        "newman_demographics_department1",
                        "newman_demographics_department2",
                        "newman_data_department1",
                        "newman_data_department2",
                        "newman_sheet2_department1",
                        "newman_sheet2_department2",
                        "summary_primary_dept",
		);
$dropdownChoices = array();
$dropdownTypes = array();
foreach ($dropdowns as $field) {
	$dropdownChoices[$field] = array();
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
	$choices2 = array();
	foreach ($choices as $choice) {
		$a = preg_split("/\s*,\s*/", $choice);
		if (count($a) > 2) {
			$b = array();
			for ($i=1; $i < count($a); $i++) {
				$b[] = $a[$i];
			}
			$a = array($a[0], implode(", ", $b));
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
		$choices3 = array();
		foreach ($choices2 as $i => $choice) {
			$choices[$i+1] = $choice;
		}
		return $choices3;
	}
	return $choices2;
}

# make a string from the choices array
function makeChoiceString($choices) {
	$choices2 = array();
	foreach ($choices as $a => $b) {
		$choices2[] = "$a, $b";
	}

	return implode(" | ", $choices2);
}

$metadataNew = array();
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
	$ary2 = array();
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
$data = array(
    'token' => $token,
    'content' => 'metadata',
    'format' => 'json',
    'data' => json_encode($metadata),
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
echo "Metadata upload: ".$output."\n";
curl_close($ch);


# download record IDs
$data = array(
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'fields' => array('record_id'),
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

$rows = json_decode($output, true);
$recordIds = array();
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
	$records = array();
	for ($j = 0; ($j < $pullSize) && ($j + 1 + $pullSize * $pull <= count($recordIds)) ; $j++) {
		$records[] = $pullSize * $pull + $j + 1;
	}
	$data = array(
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
	
	$records = json_decode($output, true);
	$departments = array();
	$departmentFields = array();
	$i = 0;
	foreach($records as $row) {
		foreach ($row as $field => $value) {
			if (isset($dropdownChoices[$field])) {
				if ($value !== "") {
					$value2 = $value;
					if (!isset($dropdownChoices[$field][$value2])) {
						$value = str_replace("&", "and", $value);

						# autopopulate some options
						if (($value == "Asst Prof.") || ($value == "Asst Prof") || ($value == "Asst. Prof") || ($value == "Asst. Prof.") || ($value == "Assistant") || ($value == "Assistant Prof") || ($value == "Assdt Prof") || ($value == "Prof") || ($value == "Professor")) {
							$value = "Assistant Professor";
						} else if (($value == "Assoc. Prof.") || ($value == "Assoc. Prof") || ($value == "Assc Prof") || ($value == "Assoc Prof") || ($value == "Research Assc Prof")) {
							$value = "Associate Professor";
						} else if ($value == "MB") {
							$value = "Other";
						} else if ($value == "MSc") {
							$value = "MS";
						} else if ($value == "MSPH") {
							$value = "MPH";
						} else if (($value == "MD MPH") || ($value == "MD,MPH")) {
							$value = "MD, MPH";
						} else if ($value == "MD MSCI") {
							$value = "MD, MSCI";
						} else if ($value == "MD, MSc") {
							$value = "MD, MS";
						} else if ($value == "MD PhD") {
							$value = "MD, PhD";
						} else if ($value == "MPH MSc") {
							$value = "MPH";
						} else if (($value == "Adjunct") || ($value == "Adj Assc Prof")) {
							$value = "Other";
						} else if (($value == "Rsch Fellow") || ($value == "Fellow") || ($value == "Resch Fellow")) {
							$value = "Research Fellow";
						} else if (($value == "Rsch Assist Prof") || ($value == "Staff Scientist Asst") || ($value == "Rsch Asst Prof") || ($value == "Res. Asst. Prof.") || (preg_match("/^Rsch Asst Prof/", $value))) {
							$value = "Research Assistant Professor";
						} else if (($value == "Rsch Instructor") || ($value == "Adjoint Instructor") || ($value == "Research Instructor") || ($value == "Res. Instructor") || ($value == "Res. Inst.") || ($value == "Resch Instructor")) {
							$value = "Instructor";
						} else if (($value == "Pathology") || ($value == "Pathology, Microbiology & Immunology") || ($value == "Pathology, Microbiology and Immunology")) {
							$value = "Pathology";
						} else if ($value == "Rad. Oncology") {
							$value = "Radiation Oncology";
						} else if ($value == "Biomed. Info.") {
							$value = "Biomedical Informatics";
						} else if (($value == "Meharry - Surgery") || ($value == "Medicine (Surgery)")) {
							$value = "Surgery";
						} else if ($value == "M") {
							$value = "Male";
						} else if ($value == "F") {
							$value = "Female";
						} else if ($value == "More than one race") {
							$value = "More Than One Race";
						} else if (($value == "Hispanic") || ($value == "H")) {
							$value = "Hispanic or Latino";
						} else if ($value == "W") {
							$value = "White";
						} else if ($value == "NH") {
							$value = "Non-Hispanic";
						} else if (($value == "Clinical Instructor") || ($value == "Resident Physician")) {
							$value = "Other";
						} else if (($value == "Af-Am") || ($value == "AA") || ($value == "Black") || ($value == "Ugandan")) {
							$value = "Black or African American";
						} else if ($value == "A") {
							$value = "Asian";
						} else if (preg_match("/PhD /", $value) || preg_match("/, PhD/", $value) || ($value == "Ph.D")) {
							$value = "PhD";
						} else if ($value == "BSN") {
							$value == "RN";
						} else if ($value == "Clinical Fellow") {
							$value = "Research Fellow";
						} else if ($value == "Psychologist") {
							$value = "Other";
						} else if ($value == "Rsch Asst. Prof") {
							$value = "Research Assistant Professor";
						} else if ($value == "Sci Review Officer NIH") {
							$value = "Other";
						} else if ($value == "Asst. Clin. Prof.") {
							$value = "Assistant Professor";
						} else if ($value == "Postdoc") {
							$value = "Research Fellow";
						} else if ($value == "Research Asst Prof") {
							$value = "Research Assistant Professor";
						} else if ($value == "Research Asst Prof") {
							$value = "Research Assistant Professor";
						} else if ($value == "Res Asst Prof") {
							$value = "Research Assistant Professor";
						} else if ($value == "Postdoctoral Fellow") {
							$value = "Research Fellow";
						} else if ($value == "Asst Pfo") {
							$value = "Assistant Professor";
						} else if ($value == "OH") {
							$value = "Unknown";
						} else if (($value == "Pediactrics") || ($value == "Pediatrics")) {
							$value = "General Pediatrics";
						} else if (preg_match("/^Medicine/", $value)) {
							$value = "General Internal Medicine";
						} else if ($value == "Urology") {
							$value = "Medicine ";
						} else if ($value == "Surgical Sciences/General Surgery") {
							$value = "Surgical Sciences";
						} else if ($value == "Pediatrics/ID") {
							$value = "Pediatrics";
						} else if ($value == "SON-Cardiovascular Medicine") {
							$value = "Nursing";
						} else if ($value == "VMS PGY5 Radiation Oncology") {
							$value = "Radiation Oncology";
						} else if ($value == "Psychiatry/Child-Adolescent Psych") {
							$value = "Psychiatry";
						} else if ($value == "Opthomology") {
							$value = "Ophthalmology and Visual Sciences";
						} else if ($value == "Pediatrics/ Pulmonary Allergy and Immunology") {
							$value = "Pediatrics/Pulmonary";
						} else if ($value == "Preventative Medicine") {
							$value = "Preventive Medicine";
						} else if ($value == "Pediatrics/Pediatric Hematology") {
							$value = "Pediatrics/Hematology";
						} else if ($value == "School of Nursing") {
							$value = "School of Nursing - Research Faculty";
						} else if ($value == "PMI") {
							$value = "Pathology";
						} else if ($value == "Cell and Developmental Bio., Dept of") {
							$value = "Cell and Developmental Biology";
						} else if ($value == "Physical Medicine and Rehabilitation") {
							$value = "Orthopaedic Surgery and Rehabilitation";
						} else if ($value == "Surgery/Meharry Medical College") {
							$value = "Other";
						} else if ($value == "Meharry") {
							$value = "Other";
						} else if ($value == "Ob. and Gyn.") {
							$value = "Obstetrics and Gynecology";
						} else if (($value == "Orthopaedics and Rehabilitation") || ($value == "Orthopaedics")) {
							$value = "Orthopaedic Surgery and Rehabilitation";
						} else if ($value == "Internal Medicine/Hematology /Oncology") {
							$value = "Medicine/Hematology Oncology";
						} else if ($value == "Radiology and Radiological Science") {
							$value = "Radiology and Radiological Sciences";
						}
						if (preg_match("/_degree/", $field)) {
							$textOptions = array();
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
								if (preg_match("/$value/",$label)) {
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
								foreach($dropdownChoices[$field] as $myvalue => $mylabel) {
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
	$found = array();
	foreach($records as $row) {
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
					$valids = array("y", "n");
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

	$data = array(
		'token' => $token,
		'content' => 'record',
		'format' => 'json',
		'type' => 'flat',
		'overwriteBehavior' => 'overwrite',
		'data' => json_encode($records),
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
	echo ($pull + 1).": Data upload: '".$output."'\n";
	curl_close($ch);
}
