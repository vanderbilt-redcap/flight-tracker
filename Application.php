<?php

namespace Vanderbilt\CareerDevLibrary;

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/CareerDev.php");

class Application {
    public static function getVersion() {
        return CareerDev::getVersion();
    }

    public static function isSuperUser() {
        $isSuperUser = FALSE;
        if (method_exists("\ExternalModules\ExternalModules", "isSuperUser")) {
            $isSuperUser = \ExternalModules\ExternalModules::isSuperUser();
        }
        return (SUPER_USER || $isSuperUser);
    }

    public static function getCredentialsDir() {
        $options = [
            "/app001/credentials",
            "/Users/pearsosj/credentials",
            "/Users/scottjpearson/credentials",
        ];
        foreach ($options as $dir) {
            if (file_exists($dir)) {
                return $dir;
            }
        }
        return "";
    }

    public static function getRelevantChoices() {
        return CareerDev::getRelevantChoices();
    }

    public static function getMetadataFiles() {
        $files = [
            dirname(__FILE__)."/metadata.json",
        ];
        if (CareerDev::isVanderbilt()) {
            $files[] = dirname(__FILE__)."/metadata.vanderbilt.json";
        }
        return $files;
    }

	public static function getPID($token) {
		return CareerDev::getPID($token);
	}

	public static function has($instrument) {
	    return CareerDev::has($instrument);
    }

	public static function getApplicationColors($alphas = ["1.0"]) {
        $colors = [];
        foreach ($alphas as $alpha) {
            # Flight Tracker RGBs
            $colors[] = "rgba(240, 86, 93, $alpha)";
            $colors[] = "rgba(141, 198, 63, $alpha)";
            $colors[] = "rgba(87, 100, 174, $alpha)";
            $colors[] = "rgba(247, 151, 33, $alpha)";
            $colors[] = "rgba(145, 148, 201, $alpha)";
        }
        return $colors;
    }

	public static function isVanderbilt() {
	    return CareerDev::isVanderbilt();
    }

    public static function getProjectTitle($pid = NULL) {
	    if ($pid) {
            $token = self::getSetting("token", $pid);
            $server = self::getSetting("server", $pid);
        } else {
            $token = self::getSetting("token");
            $server = self::getSetting("server");
        }
	    if ($token && $server) {
            return Download::projectTitle($token, $server);
        }
	    return "";
    }

    public static function getGrantClasses() {
	    return CareerDev::getGrantClasses();
    }

    public static function reportException(\Exception $e) {
	    $html = "<div class='red'>Exception: ".$e->getMessage()."</div>";
	    return $html;
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

    public static function generateCSRFToken() {
        $module = self::getModule();
        return $module->getCSRFToken();
    }

    public static function generateCSRFTokenHTML() {
        $csrfToken = self::generateCSRFToken();
        return "<input type='hidden' id='redcap_csrf_token' name='redcap_csrf_token' value='$csrfToken' />";
    }

    public static function getUsername() {
        if (defined('USERID')) {
            return USERID;
        }
        global $userid;
        if ($userid) {
            return $userid;
        }
        $module = self::getModule();
        if ($module) {
            return $module->getUsername();
        }
        return "";
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
        $possibleFields = [
            "record_id",
            "patent_number",
            "patent_include",
            "patent_title",
            "patent_abstract",
            "patent_date",
            "patent_inventors",
            "patent_inventor_ids",
            "patent_assignees",
            "patent_assignee_ids",
            "patent_last_update",
        ];
        return REDCapManagement::filterOutInvalidFields($metadata, $possibleFields);
    }

    public static function isPluginProject() {
        $link = self::link("index.php");
        return preg_match("/plugins/", $link);
    }

    public static function log($mssg, $pid = FALSE) {
		CareerDev::log($mssg, $pid);
	}

	public static function getInstitutions($pid = NULL) {
		return CareerDev::getInstitutions($pid);
	}

	public static function getImportHTML() {
        $version = CareerDev::getVersion();
        $str = "";
        $str .= "<link rel='stylesheet' href='https://use.fontawesome.com/releases/v5.8.2/css/all.css' integrity='sha384-oS3vJWv+0UjzBfQzYUhtDYW+Pj2yciDJxpsK1OYPAYjqT085Qq/1cq5FLXAZQ7Ay' crossorigin='anonymous' />";
        $str .= "<link rel='stylesheet' href='".self::link("/css/w3.css")."' />";
        $str .= "<script src='".self::link("/js/base.js")."&$version'></script>";

        $url = $_SERVER['PHP_SELF'];
        if (
            preg_match("/ExternalModules/", $url)
            || preg_match("/external_modules/", $url)
            || preg_match("/\/plugins\//", $url)
        ) {
            $str .= "<script src='".self::link("/js/jquery.min.js")."'></script>";
            $str .= "<script src='".self::link("/js/jquery-ui.min.js")."'></script>";
            $str .= "<script src='".self::link("/js/autocomplete.js")."&$version'></script>";
            $str .= "<link rel='icon' type='image/png' href='".self::link("/img/flight_tracker_icon.png")."' />";
            $str .= "<link rel='stylesheet' href='".self::link("/css/jquery-ui.css")."' />";
            $str .= "<link rel='stylesheet' href='".self::link("/css/jquery.sweet-modal.min.css")."' />";
            $str .= "<link rel='stylesheet' href='".self::link("/css/career_dev.css")."&$version' />";
            $str .= "<link rel='stylesheet' href='".self::link("/css/typekit.css")."&$version' />";
        }
        $str .= "<script src='".self::link("/js/jquery.sweet-modal.min.js")."'></script>";
        $str .= "<script>function getCSRFToken() { return '".self::generateCSRFToken()."'; }</script>";
        return $str;
    }

	public static function getHeader($tokenName = "") {
        $pid = CareerDev::getPID();
        $token = self::getSetting("token", $pid);
        $server = self::getSetting("server", $pid);

        if (!$tokenName) {
            $tokenName = self::getSetting("tokenName", $pid);
        }
        $module = self::getModule();
        $museoSansLink = self::link("/fonts/exljbris - MuseoSans-500.otf");

        $str = "";
        $str .= "
<style>
/* must add fonts here or they will not show up in REDCap menus */
@font-face { font-family: 'Museo Sans'; font-style: normal; font-weight: normal; src: url('$museoSansLink'); }

.w3-dropdown-hover { display: inline-block !important; float: none !important; }
.w3-dropdown-hover button,a.w3-bar-link { font-size: 12px; }
a.w3-bar-link { display: inline-block !important; float: none !important; }
.w3-bar { font-family: 'Museo Sans', Arial, Helvetica, sans-serif; text-align: center !important; }
a.w3-button,button.w3-button { padding: 6px 4px !important; }
a.w3-button,button.w3-button.with-image { padding: 8px 4px 6px 4px !important; }
a.w3-button { color: black !important; float: none !important; }
.w3-button a,.w3-dropdown-content a { color: white !important; font-size: 13px !important; }
.topHeaderWrapper { background-color: white; height: 80px; top: 0px; width: 100%; }
.topHeader { margin: 0 auto; max-width: 1200px; }
.topBar { font-family: 'Museo Sans', Arial, Helvetica, sans-serif; padding: 0px; }
.middleBar { font-family: 'Museo Sans', Arial, Helvetica, sans-serif; padding: 0px; margin-left: auto; margin-right: auto; text-align: center; max-width: 600px; }
a.nounderline { text-decoration: none; }
a.nounderline:hover { text-decoration: dotted; }
img.brandLogo { height: 40px; margin: 20px; }
#overlayFT { position: fixed; display: none; width: 100%; height: 100%; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0,0,0,0.7); z-index: 2; cursor: pointer; text-align: center; vertical-align: middle; }
.warning { color: white; }
p.centered { text-align: center; margin-left: auto; margin-right: auto; }

/* Coordinated with career_dev.css */
p.recessed { color: #888888; font-size: 11px; margin: 4px 12px 4px 12px; }
.recessed,.recessed a { color: #888888; font-size: 11px; }
p.recessed,div.recessed { margin: 2px; }
</style>";

        $str .= self::getImportHTML();

        $str .= "<header class='topHeaderWrapper'>";
        $str .= "<div class='topHeader'>";
        $str .= "<div class='topBar' style='float: left; padding-left: 5px;'><a href='https://redcap.vanderbilt.edu/plugins/career_dev/consortium/'><img alt='Flight Tracker for Scholars' src='".self::link("/img/flight_tracker_logo_small.png")."'></a></div>";
        if (isset($_GET['id']) || isset($_GET['record'])) {
            $records = Download::records($token, $server);
            $recordId = Sanitizer::getSanitizedRecord($_GET['id'] ?? $_GET['record'] ?? "", $records);
            if ($recordId) {
                $csrfToken = self::generateCSRFToken();
                $url = self::link("/summarizeRecordNow.php");
                $str .= "<div class='middleBar'><br/><button onclick='summarizeRecordNow(\"$url\", \"$recordId\", \"$csrfToken\"); return false;'>Regenerate Summary for this Record Now</button></div>";
            }
        }
        if ($base64 = $module->getBrandLogo()) {
            $str .= "<div class='topBar' style='float:right;'><img src='$base64' class='brandLogo'></div>";
        } else {
            $str .= "<div class='topBar' style='float:right;'><p class='recessed'>$tokenName</p></div>";
        }
        $str .= "</div>";
        $str .= "</header>";

        $switches = new FeatureSwitches($token, $server, $pid);
        $switchValues = $switches->getSwitches();

        $navBar = new NavigationBar();
        $navBar->addFALink("home", "Home", CareerDev::getHomeLink());
        $navBar->addFAMenu("clinic-medical", "General", CareerDev::getMenu("General"));
        if ($switches->isOn("Grants")) {
            $navBar->addMenu("<img src='".CareerDev::link("/img/grant_small.png")."'>Grants", CareerDev::getMenu("Grants"));
        }
        if ($switches->isOn("Publications")) {
            $navBar->addFAMenu("sticky-note", "Pubs", CareerDev::getMenu("Pubs"));
        }
        $navBar->addFAMenu("table", "View", CareerDev::getMenu("View"));
        $navBar->addFAMenu("calculator", "Wrangle", CareerDev::getMenu("Wrangler"));
        $navBar->addFAMenu("school", "Scholars", CareerDev::getMenu("Scholars"));
        $navBar->addMenu("<img src='".CareerDev::link("/img/redcap_translucent_small.png")."'>REDCap", CareerDev::getMenu("REDCap"));
        $navBar->addFAMenu("tachometer-alt", "Dashboards", CareerDev::getMenu("Dashboards"));
        $navBar->addFAMenu("filter", "Cohorts / Filters", CareerDev::getMenu("Cohorts"));
        $navBar->addFAMenu("chalkboard-teacher", "Mentors", CareerDev::getMenu("Mentors"));
        $navBar->addFAMenu("pen", "Resources", CareerDev::getMenu("Resources"));
        $navBar->addFAMenu("question-circle", "Help", CareerDev::getMenu("Help"));
        $str .= $navBar->getHTML();

        return $str;
    }

    public static function getFooter() {
        $px = 300;
        $str = "";
        $str .= "<style>
body { margin-bottom: 60px; }
footer { z-index: 1000000; position: fixed; left: 0; bottom: 0; width: 100%; background-color: white; }
.bottomBar { font-family: 'Museo Sans', Arial, Helvetica, sans-serif; padding: 5px; }
</style>";
        $str .= "<footer class='bottomFooter'>";
        $str .= "<div class='bottomBar' style='float: left;'>";
        $str .= "<div class='recessed' style='width: $px"."px;'>Copyright &#9400 ".date("Y")." <a class='nounderline' href='https://vumc.org/'>Vanderbilt University Medical Center</a></div>";
        $str .= "<div class='recessed' style='width: $px"."px;'>from <a class='nounderline' href='https://edgeforscholars.org/'>Edge for Scholars</a></div>";
        $str .= "<div class='recessed' style='width: $px"."px;'><a class='nounderline' href='https://projectredcap.org/'>Powered by REDCap</a></div>";
        $str .= "</div>";    // bottomBar
        $str .= "<div class='bottomBar' style='float: right;'><span class='recessed'>funded by</span><br>";
        $str .= "<a href='https://ncats.nih.gov/ctsa'><img src='".self::link("/img/ctsa.png")."' style='height: 22px;'></a></div>";
        $str .= "</div>";    // bottomBar
        $str .= "</footer>";    // bottomFooter
        return $str;
    }

    public static function getPids() {
        if (Application::isVanderbilt()) {
            if (Application::isExternalModule()) {
                $module = self::getModule();
                $pids = $module->getPids();
            } else {
                $ftModule = Application::getFlightTrackerModule();
                $pids = $ftModule->getPids();
            }
            if (Application::isLocalhost()) {
                $pids[] = 15;
            } else if (Application::isServer("redcap.vanderbilt.edu")) {
                $pids[] = 66635;
            } else if (Application::isServer("redcaptest.vanderbilt.edu")) {
                # TODO Add test projects with plugin
            }
            return $pids;
        } else {
            $module = self::getModule();
            return $module->getPids();
        }
    }

    public static function getMenteeAgreementLink() {
	    $token = self::getSetting("token");
	    $myPid = self::getPID($token);
	    $defaultLink = self::link("mentor/intro.php", $myPid, TRUE);
        if (self::isPluginProject()) {
            if (isset($_GET['test'])) {
                echo "plugin project<br>";
            }
            global $info;
            if (isset($info['prod'])) {
                $sourcePid = $info['prod']['pid'];
                return self::link("mentor/intro.php", $sourcePid, TRUE);
            }
            Application::log("Warning! Could not find prod in info!");
        } else if (CareerDev::isCopiedProject()) {
            if ($sourcePid = CareerDev::getSourcePid($myPid)) {
                return self::link("mentor/intro.php", $sourcePid, TRUE);
            }
            Application::log("Warning! Could not find sourcePid in copied project!");
        }
        return $defaultLink;
    }

    public static function getDefaultVanderbiltMenteeAgreementLink() {
	    return "https://medschool.vanderbilt.edu/msci/current-trainees/resources-for-funding-research-and-grant-assistance/";
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
        $ary = [
            "Veterans Health Administration",
        ];
        if (self::isVanderbilt()) {
            $ary[] = "Tennessee Valley Healthcare System";
        }
        return $ary;
    }

    public static function getInstitution($pid = NULL) {
		$insts = self::getInstitutions($pid);
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

            $filename = REDCapManagement::makeSafeFilename($filename);
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

    public static function isLocalhost() {
	    return CareerDev::isLocalhost();
    }

    public static function getModule() {
		return CareerDev::getModule();
	}

	public static function link($loc, $pid = "", $withWebroot = FALSE) {
		return CareerDev::link($loc, $pid, $withWebroot);
	}

    public static function isExternalModule() {
        $module = self::getModule();
        if (!$module) {
            return FALSE;
        }
        $moduleClassWithNamespaceNodes = explode("\\", get_class($module));
        $moduleClass = array_pop($moduleClassWithNamespaceNodes);
        return $moduleClass == "FlightTrackerExternalModule";
    }

    public static function getAllSettings($pid = "") {
	    return CareerDev::getAllSettings($pid);
    }

    public static function getSettingKeys($pid) {
        if ($_GET['pid'] == $pid) {
            if ($module = self::getModule()){
                $settings = $module->getProjectSettings($pid);
                return array_keys($settings);
            }
        } else {
            $prefix = CareerDev::getPrefix();
            $sql = "SELECT DISTINCT(s.key) AS array_key
                            FROM redcap_external_module_settings AS s
                                INNER JOIN redcap_external_modules AS m
                                    ON m.external_module_id = s.external_module_id
                            WHERE m.directory_prefix = '".db_real_escape_string($prefix)."'
                                AND s.project_id = '".db_real_escape_string($pid)."'";
            $q = db_query($sql);
            if ($error = db_error()) {
                throw new \Exception("ERROR: $error");
            }
            $keys = [];
            while ($row = db_fetch_assoc($q)) {
                $keys[] = $row['array_key'];
            }
            return $keys;
        }
    }

    public static function getFlightTrackerModule() {
        if (self::isExternalModule()) {
            return self::getModule();
        } else {
            $prefix = CareerDev::getPrefix();
            return \ExternalModules\ExternalModules::getModuleInstance($prefix);
        }
    }

    public static function isServer($server) {
        return (SERVER_NAME == $server);
    }

    public static function getSetting($field, $pid = "") {
		return CareerDev::getSetting($field, $pid);
	}

	public static function isTestServer() {
	    $value = self::getSetting("server_class");
        # TODO may want to consider removing ""; also consider "dev" as an option if specified
	    $testServerClasses = ["test", ""];
	    return in_array($value, $testServerClasses);
    }

	public static function saveSetting($field, $value, $pid = "") {
	    CareerDev::saveSetting($field, $value, $pid);
    }

	public static function getSites($all = TRUE) {
	    return CareerDev::getSites($all);
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
        "citation_grants",
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

    public static $customFields = [
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
        "custom_submission_status",
        "custom_submission_date",
    ];

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
