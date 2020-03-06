<?php

namespace Vanderbilt\CareerDevLibrary;

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/CareerDev.php");

class Application {
	public static function getPID($token) {
		return CareerDev::getPID($token);
	}

	public static function getUnknown() {
		return CareerDev::getUnknown();
	}

	public static function filterOutCopiedRecords($records) {
		return CareerDev::filterOutCopiedRecords($records);
	}

	public static function getFeedbackEmail() {
		return "scott.j.pearson@vumc.org";
	}

	public static function log($mssg) {
		CareerDev::log($mssg);
	}

	public static function getInstitutions() {
		return CareerDev::getInstitutions();
	}

	public static function getEmailName($record) {
		return CareerDev::getEmailName($record);
	}

	public static function getInstitution() {
		$insts = self::getInstitutions();
		if (count($insts) > 0) {
			return $insts[0];
		}
		return "";
	}

	public static function getModule() {
		return CareerDev::getModule();
	}
	public static function link($loc) {
		return CareerDev::link($loc);
	}

	public static function getSetting($field) {
		return CareerDev::getSetting($field);
	}

        public static function getInternalKLength() {
                return CareerDev::getInternalKLength();
        }

        public static function getK12KL2Length() {
                return CareerDev::getK12KL2Length();
        }

        public static function getIndividualKLength() {
                return CareerDev::getIndividualKLength();
        }

	public static $institutionFields = array(
						"record_id",
						"identifier_institution",
						"identifier_institution_source",
						"identifier_institution_sourcetype",
						"identifier_left_job_title",
						"identifier_left_date",
						"identifier_left_date_source",
						"identifier_left_date_sourcetype",
						"identifier_left_job_category",
						);

	public static $summaryFields = array(
						"record_id",
						"identifier_first_name",
						"identifier_last_name",
						"identifier_email",
						"identifier_email_source",
						"identifier_email_sourcetype",
						"identifier_userid",
						"identifier_coeus",
						"identifier_reporter",
						"identifier_pubmed",
						"identifier_institution",
						"identifier_institution_source",
						"identifier_institution_sourcetype",
						"identifier_left_job_title",
						"identifier_left_date",
						"identifier_left_date_source",
						"identifier_left_date_sourcetype",
						"identifier_left_job_category",
						"summary_degrees",
						"summary_primary_dept",
						"summary_gender",
						"summary_race_ethnicity",
						"summary_current_rank",
						"summary_dob",
						"summary_citizenship",
						"summary_ever_internal_k",
						"summary_ever_individual_k_or_equiv",
						"summary_ever_k12_kl2",
						"summary_ever_r01_or_equiv",
						"summary_ever_external_k_to_r01_equiv",
						"summary_ever_last_external_k_to_r01_equiv",
						"summary_ever_first_any_k_to_r01_equiv",
						"summary_ever_last_any_k_to_r01_equiv",
						"summary_first_any_k",
						"summary_first_external_k",
						"summary_last_any_k",
						"summary_last_external_k",
						"summary_survey",
						"summary_publication_count",
						"summary_total_budgets",
						"summary_award_source_1",
						"summary_award_date_1",
						"summary_award_end_date_1",
						"summary_award_type_1",
						"summary_award_title_1",
						"summary_award_sponsorno_1",
						"summary_award_age_1",
						"summary_award_nih_mechanism_1",
						"summary_award_total_budget_1",
						"summary_award_direct_budget_1",
						"summary_award_percent_effort_1",
						"summary_award_source_2",
						"summary_award_date_2",
						"summary_award_end_date_2",
						"summary_award_type_2",
						"summary_award_title_2",
						"summary_award_sponsorno_2",
						"summary_award_age_2",
						"summary_award_nih_mechanism_2",
						"summary_award_total_budget_2",
						"summary_award_direct_budget_2",
						"summary_award_percent_effort_2",
						"summary_award_source_3",
						"summary_award_date_3",
						"summary_award_end_date_3",
						"summary_award_type_3",
						"summary_award_title_3",
						"summary_award_sponsorno_3",
						"summary_award_age_3",
						"summary_award_nih_mechanism_3",
						"summary_award_total_budget_3",
						"summary_award_direct_budget_3",
						"summary_award_percent_effort_3",
						"summary_award_source_4",
						"summary_award_date_4",
						"summary_award_end_date_4",
						"summary_award_type_4",
						"summary_award_title_4",
						"summary_award_sponsorno_4",
						"summary_award_age_4",
						"summary_award_nih_mechanism_4",
						"summary_award_total_budget_4",
						"summary_award_direct_budget_4",
						"summary_award_percent_effort_4",
						"summary_award_source_5",
						"summary_award_date_5",
						"summary_award_end_date_5",
						"summary_award_type_5",
						"summary_award_title_5",
						"summary_award_sponsorno_5",
						"summary_award_age_5",
						"summary_award_nih_mechanism_5",
						"summary_award_total_budget_5",
						"summary_award_direct_budget_5",
						"summary_award_percent_effort_5",
						"summary_award_source_6",
						"summary_award_date_6",
						"summary_award_end_date_6",
						"summary_award_type_6",
						"summary_award_title_6",
						"summary_award_sponsorno_6",
						"summary_award_age_6",
						"summary_award_nih_mechanism_6",
						"summary_award_total_budget_6",
						"summary_award_direct_budget_6",
						"summary_award_percent_effort_6",
						"summary_award_source_7",
						"summary_award_date_7",
						"summary_award_end_date_7",
						"summary_award_type_7",
						"summary_award_title_7",
						"summary_award_sponsorno_7",
						"summary_award_age_7",
						"summary_award_nih_mechanism_7",
						"summary_award_total_budget_7",
						"summary_award_total_budget_7",
						"summary_award_direct_budget_7",
						"summary_award_percent_effort_7",
						"summary_award_source_8",
						"summary_award_date_8",
						"summary_award_end_date_8",
						"summary_award_type_8",
						"summary_award_title_8",
						"summary_award_sponsorno_8",
						"summary_award_age_8",
						"summary_award_nih_mechanism_8",
						"summary_award_total_budget_8",
						"summary_award_direct_budget_8",
						"summary_award_percent_effort_8",
						"summary_award_source_9",
						"summary_award_date_9",
						"summary_award_end_date_9",
						"summary_award_type_9",
						"summary_award_title_9",
						"summary_award_sponsorno_9",
						"summary_award_age_9",
						"summary_award_nih_mechanism_9",
						"summary_award_total_budget_9",
						"summary_award_direct_budget_9",
						"summary_award_percent_effort_9",
						"summary_award_source_10",
						"summary_award_date_10",
						"summary_award_end_date_10",
						"summary_award_type_10",
						"summary_award_title_10",
						"summary_award_sponsorno_10",
						"summary_award_age_10",
						"summary_award_nih_mechanism_10",
						"summary_award_total_budget_10",
						"summary_award_direct_budget_10",
						"summary_award_percent_effort_10",
						"summary_award_source_11",
						"summary_award_date_11",
						"summary_award_end_date_11",
						"summary_award_type_11",
						"summary_award_title_11",
						"summary_award_sponsorno_11",
						"summary_award_age_11",
						"summary_award_nih_mechanism_11",
						"summary_award_total_budget_11",
						"summary_award_direct_budget_11",
						"summary_award_percent_effort_11",
						"summary_award_source_12",
						"summary_award_date_12",
						"summary_award_end_date_12",
						"summary_award_type_12",
						"summary_award_title_12",
						"summary_award_sponsorno_12",
						"summary_award_age_12",
						"summary_award_nih_mechanism_12",
						"summary_award_total_budget_12",
						"summary_award_direct_budget_12",
						"summary_award_percent_effort_12",
						"summary_award_source_13",
						"summary_award_date_13",
						"summary_award_end_date_13",
						"summary_award_type_13",
						"summary_award_title_13",
						"summary_award_sponsorno_13",
						"summary_award_age_13",
						"summary_award_nih_mechanism_13",
						"summary_award_total_budget_13",
						"summary_award_direct_budget_13",
						"summary_award_percent_effort_13",
						"summary_award_source_14",
						"summary_award_date_14",
						"summary_award_end_date_14",
						"summary_award_type_14",
						"summary_award_title_14",
						"summary_award_sponsorno_14",
						"summary_award_age_14",
						"summary_award_nih_mechanism_14",
						"summary_award_total_budget_14",
						"summary_award_direct_budget_14",
						"summary_award_percent_effort_14",
						"summary_award_source_15",
						"summary_award_date_15",
						"summary_award_end_date_15",
						"summary_award_type_15",
						"summary_award_title_15",
						"summary_award_sponsorno_15",
						"summary_award_age_15",
						"summary_award_nih_mechanism_15",
						"summary_award_total_budget_15",
						"summary_award_direct_budget_15",
						"summary_award_percent_effort_15",
						"summary_first_external_k",
						"summary_first_any_k",
						"summary_first_r01",
						"summary_first_k_to_first_r01",
						"summary_first_any_k_to_first_r01",
						);

	public static $citationFields = array(
						"record_id",
						"citation_pmid",
						"citation_include",
						"citation_source",
						"citation_pmcid",
						"citation_authors",
						"citation_title",
						"citation_pub_types",
						"citation_mesh_terms",
						"citation_journal",
						"citation_volume",
						"citation_issue",
						"citation_year",
						"citation_month",
						"citation_day",
						"citation_pages",
						"citation_is_research",
						"citation_num_citations",
						"citation_citations_per_year",
						"citation_expected_per_year",
						"citation_field_citation_rate",
						"citation_nih_percentile",
						"citation_rcr",
						);



}
