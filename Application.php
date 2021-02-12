<?php

namespace Vanderbilt\CareerDevLibrary;

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/CareerDev.php");

class Application {
	public static function getPID($token) {
		return CareerDev::getPID($token);
	}

    public static function getProjectTitle() {
        return \REDCap::getProjectTitle();
    }

    public static function getGrantClasses() {
	    return CareerDev::getGrantClasses();
    }

    # TRUE iff &record= appended to end of page
    public static function isRecordPage($link) {
        $regexes = [
            "/profile\.php/",
            "/customGrants\.php/",
            "/initial\.php/",
            "/dashboard\//",
            "/wrangler\//",
            "/publications\/view\.php/",
        ];
        foreach ($regexes as $regex) {
            if (preg_match($regex, $link)) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public static function refreshRecordSummary($token, $server, $pid, $recordId) {
	    return CareerDev::refreshRecordSummary($token, $server, $pid, $recordId);
    }

    public static function getProgramName() {
	    return CareerDev::getProgramName();
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

    public static function getPatentFields($metadata) {
        $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
        $possibleFields = [
            "record_id",
            "patent_number",
            "patent_include",
            "patent_date",
            "patent_inventors",
            "patent_inventor_ids",
            "patent_assignees",
            "patent_assignee_ids",
            "patent_last_update",
        ];
        $fields = [];
        foreach ($possibleFields as $field) {
            if (in_array($field, $metadataFields)) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    public static function log($mssg, $pid = FALSE) {
		CareerDev::log($mssg, $pid);
	}

	public static function getInstitutions($pid = NULL) {
		return CareerDev::getInstitutions($pid);
	}

	public static function getEmailName($record) {
		return CareerDev::getEmailName($record);
	}

    public static function getCitationFields($metadata) {
        return REDCapManagement::screenForFields($metadata, self::$citationFields);
    }

    public static function getCustomFields($metadata) {
        return REDCapManagement::screenForFields($metadata, self::$customFields);
    }

    public static function getExporterFields($metadata) {
        return REDCapManagement::screenForFields($metadata, CareerDev::$exporterFields);
    }

    public static function getHelperInstitutions() {
        return [
            "Veterans Health Administration",
        ];
    }

    public static function getInstitution() {
		$insts = self::getInstitutions();
		if (count($insts) > 0) {
			return $insts[0];
		}
		return "";
	}

    public static function hasComposer() {
        return file_exists(self::getComposerAutoloadLocation());
    }

    public static function isTestGroup($pid) {
        return CareerDev::isTestGroup($pid);
	}

    public static function writeHTMLToDoc($html, $filename) {
	    if (self::hasComposer()) {
            require_once(self::getComposerAutoloadLocation());

            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();
            \PhpOffice\PhpWord\Shared\Html::addHtml($section, $html);

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment;filename="'.$filename.'"');
            $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $objWriter->save('php://output');
        }
    }

    public static function getComposerAutoloadLocation() {
        return dirname(__FILE__)."/vendor/autoload.php";
    }

    public static function isWebBrowser() {
        return $_SERVER['REQUEST_URI'];
    }

    public static function getModule() {
		return CareerDev::getModule();
	}
	public static function link($loc) {
		return CareerDev::link($loc);
	}

	public static function getSetting($field, $pid = "") {
		return CareerDev::getSetting($field, $pid);
	}

	public static function getSites() {
	    return CareerDev::getSites();
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

    public static $summaryFields = [
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
        "summary_urm",
        "summary_disability",
        "summary_disadvantaged",
        "summary_training_start",
        "summary_training_end",
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
        "summary_award_role_1",
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
        "summary_award_role_2",
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
        "summary_award_role_3",
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
        "summary_award_role_4",
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
        "summary_award_role_5",
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
        "summary_award_role_6",
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
        "summary_award_role_7",
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
        "summary_award_role_8",
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
        "summary_award_role_9",
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
        "summary_award_role_10",
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
        "summary_award_role_11",
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
        "summary_award_role_12",
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
        "summary_award_role_13",
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
        "summary_award_role_14",
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
        "summary_award_role_15",
        "summary_first_external_k",
        "summary_first_any_k",
        "summary_first_r01",
        "summary_first_k_to_first_r01",
        "summary_first_any_k_to_first_r01",
    ];

    private static $citationFields = array(
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
        "citation_abstract",
        "citation_is_research",
        "citation_num_citations",
        "citation_citations_per_year",
        "citation_expected_per_year",
        "citation_field_citation_rate",
        "citation_nih_percentile",
        "citation_rcr",
        "citation_icite_last_update",
        "citation_altmetric_score",
        "citation_altmetric_image",
        "citation_altmetric_details_url",
        "citation_altmetric_id",
        "citation_altmetric_fbwalls_count",
        "citation_altmetric_feeds_count",
        "citation_altmetric_gplus_count",
        "citation_altmetric_posts_count",
        "citation_altmetric_tweeters_count",
        "citation_altmetric_accounts_count",
        "citation_altmetric_last_update",
    );

    public static $customFields = array(
        "record_id",
        "custom_title",
        "custom_number",
        "custom_type",
        "custom_org",
        "custom_recipient_org",
        "custom_role",
        "custom_role_other",
        "custom_start",
        "custom_end",
        "custom_costs",
        "custom_last_update",
    );

    public static $positionFields = array(
        "record_id",
        "promotion_in_effect",
        "promotion_job_title",
        "promotion_job_category",
        "promotion_rank",
        "promotion_institution",
        "promotion_location",
        "promotion_department",
        "promotion_department_other",
        "promotion_division",
        "promotion_date",
    );
}
