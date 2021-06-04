<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\EmailManager;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$dates = array(
		"Spring 2018" => array(
					"initial" => "2018-04-04",
					"followup" => "2018-04-17",
					"message" => "Dear [name],<br><br>Our team at Vanderbilt would like to describe the career paths of individuals like you as we aim to improve training and resources and to seek grants to better support early career researchers with the tools they need to thrive.<br><br>We have pre-filled the linked survey with as much descriptive information as we can gather from other sources. We would be very grateful if you would pull out your CV and take 10-15 minutes to verify information and fill in any blanks.<br><br>Use the link below by <b>April 20</b>. Many thanks for helping us capture key data about your success.<br><br>[survey_link_initial_survey]<br><br>Thanks-KH",
					"subject" => "10 minutes to help future career development scholars",
					"filter" => "all",
					),
		"Fall 2018 - Initial" => array(
						"initial" => "2018-09-11",
						"followup" => "2018-09-25",
						"message" => "Dear [name],<br><br>We are starting a yearly survey to help us keep track of Newman Society members’ successes, such as new grants, papers, and promotions. The survey is pre-filled with as much information as we can gather from other sources, including the survey you filled out last year, so you will just need to confirm or update items.<br><br>We are testing the new survey with a small group of scholars, and I hope you will take a few minutes to not only fill out/confirm yours, but write back to let us know if you run into any difficulties, if any questions don’t make sense or could be reworded, or if we should ask anything else on the survey.<br><br>Please use the link below to complete the survey by <b>Tuesday, September 21</b>. Many thanks for helping us capture key data about your success.<br><br>[survey_link_initial_survey]<br><br>Thanks-KH<br><br><b>Katherine E. Hartmann, MD, PhD</b><br>Associate Dean, Clinical and Translational Scientist Development<br>Deputy Director, Institute for Medicine & Public Health<br>Director, Graduate Studies in Epidemiology<br>Professor, Obstetrics & Gynecology and Medicine",
						"subject" => "10 minutes to help us keep up with your successes",
						"filter" => "some",
						"none_complete" => TRUE,
						),
		"Fall 2018 - Followup" => array(
						"initial" => "2018-09-11",
						"followup" => "2018-09-25",
						"message" => "Dear [name],<br><br>We are starting a yearly survey to help us keep track of Newman Society members’ successes, such as new grants, papers, and promotions. The survey is pre-filled with as much information as we can gather from other sources, including the survey you filled out last year, so you will just need to confirm or update items.<br><br>We are testing the new survey with a small group of scholars, and I hope you will take a few minutes to not only fill out/confirm yours, but write back to let us know if you run into any difficulties, if any questions don’t make sense or could be reworded, or if we should ask anything else on the survey.<br><br>Please use the link below to complete the survey by <b>Tuesday, September 18</b>. Many thanks for helping us capture key data about your success.<br><br>[survey_link_followup]<br><br>Thanks-KH<br><br><b>Katherine E. Hartmann, MD, PhD</b><br>Associate Dean, Clinical and Translational Scientist Development<br>Deputy Director, Institute for Medicine & Public Health<br>Director, Graduate Studies in Epidemiology<br>Professor, Obstetrics & Gynecology and Medicine",
						"subject" => "10 minutes to help us keep up with your successes",
						"filter" => "some",
						"last_complete" => 3,
					),
		"Summer 2019 - Initial" => array(
						"initial" => "2019-06-28",
						"message" => "Dear [name],<br><br>We are honoring mentors of multiple K scholars at Vanderbilt's annual Visiting Scholars Day celebration this summer. We want to be sure to count all of your mentor's scholars. Please help us by confirming your K mentor on the survey below, which also helps us keep track of your successes, such as grants, papers, and promotions. This helps us describe career paths of individuals like you as we aim to improve training and resources and to seek grants to better support early career researchers with the tools they need to thrive.<br><br>The survey is pre-filled with what you’ve told us already, as well as with as much information as we can gather from other sources like NIH RePORTER, Coeus and PubMed. We would be very grateful if you would pull out your CV and take 5-10 minutes to verify information and fill in any blanks.<br><br>Many thanks for helping us capture key data about your success.<br><br><a href='[survey_link_initial_survey]'>[survey_link_initial_survey]</a><br><br>Thanks-KH<br><br><b>Katherine E. Hartmann, MD, PhD</b><br>Associate Dean, Clinical and Translational Scientist Development<br>Deputy Director, Institute for Medicine & Public Health<br>Director, Graduate Studies in Epidemiology<br>Professor, Obstetrics & Gynecology and Medicine",
						"subject" => "5-10 minutes to help us honor your mentor",
						"filter" => "some",
						"none_complete" => TRUE,
						),
		"Summer 2019 - Followup" => array(
						"initial" => "2019-06-28",
						"message" => "Dear [name],<br><br>We are honoring mentors of multiple K scholars at Vanderbilt's annual Visiting Scholars Day celebration this summer. We want to be sure to count all of your mentor's scholars. Please help us by confirming your K mentor on the survey below, which also helps us keep track of your successes, such as grants, papers, and promotions. This helps us describe career paths of individuals like you as we aim to improve training and resources and to seek grants to better support early career researchers with the tools they need to thrive.<br><br>The survey is pre-filled with what you’ve told us already, as well as with as much information as we can gather from other sources like NIH RePORTER, Coeus and PubMed. We would be very grateful if you would pull out your CV and take 5-10 minutes to verify information and fill in any blanks.<br><br>Many thanks for helping us capture key data about your success.<br><br><a href='[survey_link_followup]'>[survey_link_followup]</a><br><br>Thanks-KH<br><br><b>Katherine E. Hartmann, MD, PhD</b><br>Associate Dean, Clinical and Translational Scientist Development<br>Deputy Director, Institute for Medicine & Public Health<br>Director, Graduate Studies in Epidemiology<br>Professor, Obstetrics & Gynecology and Medicine",
						"subject" => "5-10 minutes to help us honor your mentor",
						"filter" => "some",
						"last_complete" => 6,
						),
		);

$mgr = new EmailManager($token, $server, $pid, $module);
foreach ($dates as $name => $ary) {
	$emailSetting = EmailManager::getBlankSetting();
	$emailSetting['who']['from'] = "katherine.hartmann@vumc.org";
	$emailSetting['who']['filter'] = $ary['filter'];
	$emailSetting['who']['sent'] = array();
	$emailSetting['what']['subject'] = $ary['subject'];
	$emailSetting['what']['message'] = $ary['message'];
	$emailSetting['when']['initial_time'] = $ary['initial']." 09:00:00 AM";
	$sentAry = array("ts" => strtotime($emailSetting['when']['initial_time']), "records" => "all");
	array_push($emailSetting['who']['sent'], $sentAry);
	if ($ary['none_complete']) {
		$emailSetting['when']['none_complete'] = TRUE;
	}
	if ($ary['last_complete']) {
		$emailSetting['when']['last_complete'] = $ary['last_complete'];
	}
	if ($ary['followup']) {
		$emailSetting['when']['followup_time'] = $ary['followup']." 09:00:00 AM";
		$sentAry = array("ts" => strtotime($emailSetting['when']['followup_time']), "records" => "all");
		array_push($emailSetting['who']['sent'], $sentAry);
	}
	$mgr->saveSetting($name, $emailSetting);
}
