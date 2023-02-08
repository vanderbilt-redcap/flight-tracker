<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use ExternalModules\ExternalModules;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\FeatureSwitches;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\WebOfScience;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\Sanitizer;

class CareerDev {
	public static $passedModule = NULL;

	public static function getVersion() {
		return "5.0.10";
	}

	public static function getLockFile($pid) {
	    $numHours = 4;
		return APP_PATH_TEMP.date("Ymdhis", time() + $numHours * 3600)."_6_makeSummary.$pid.lock";
	}

    public static function refreshRecordSummary($token, $server, $pid, $recordId, $throwException = FALSE) {
        if (self::getSetting("auto_recalculate", $pid)) {
            require_once(dirname(__FILE__) . "/drivers/6d_makeSummary.php");
            try {
                \Vanderbilt\CareerDevLibrary\makeSummary($token, $server, $pid, $recordId);
            } catch (\Exception $e) {
                if ($throwException) {
                    throw $e;
                } else {
                    echo "<div class='centered padded red'>" . $e->getMessage() . "</div>\n";
                }
            }
        }
    }

    public static function getUnknown() {
		return "Unknown";
	}

	public static function isWrangler() {
        $page = (isset($_GET['page']) && !is_array($_GET['page'])) ? $_GET['page'] : "";
		return preg_match("/wrangler/", $page);
	}

	public static function filterOutCopiedRecords($records) {
		return $records;
	}

	public static function isRecordCopied($record) {
		return FALSE;
	}

	public static function isCopiedProject($pid = NULL) {
	    if ($pid) {
            return self::getSetting("turn_off", $pid);
        } else {
            return self::getSetting("turn_off");
        }
	}

	public static function getSourcePid($destPid) {
	    if (self::isCopiedProject($destPid)) {
	        if ($sourcePid = self::getSetting("sourcePid", $destPid)) {
	            return $sourcePid;
            }
	        $module = self::getModule();
	        foreach ($module->getPids() as $pid) {
	            if (REDCapManagement::isActiveProject($pid)) {
	                $token = self::getSetting("token", $pid);
	                $server = self::getSetting("server", $pid);
                    $cohorts = new Cohorts($token, $server, $module);
                    foreach ($cohorts->getCohortNames() as $cohort) {
                        $destPid2 = $cohorts->getReadonlyPortalValue($cohort, "pid");
                        if ($destPid2 == $destPid) {
                            $sourcePid = $pid;
                            self::saveSetting("sourcePid", $sourcePid, $destPid);
                            return $sourcePid;
                        }
                    }
                }
            }
        }
	    return "";
    }

	public static function getSites($all = TRUE) {
        $sites = [
            "NIH RePORTER" => "api.reporter.nih.gov",
            "PubMed" => "eutils.ncbi.nlm.nih.gov",
            "PubMed Central Converter" => "www.ncbi.nlm.nih.gov",
            "iCite" => "icite.od.nih.gov",
    	    "ORCID" => "pub.orcid.org",
            "Statistics Reporting" => "redcap.vanderbilt.edu",
            "Altmetric" => "api.altmetric.com",
            "Patents View (US Patent Office)" => "api.patentsview.org",
            "NSF Grants" => "api.nsf.gov",
            "ERIC" => "api.ies.ed.gov",
            "Dept. of Ed. Grants" => "ies.ed.gov",
        ];
        if ($all || self::isScopusEnabled()) {
            $sites["Scopus (API)"] = "api.elsevier.com";
            $sites["Scopus (Dev)"] = "dev.elsevier.com";
        }
        if ($all || self::isWOSEnabled()) {
            $sites["Web of Science"] = "ws.isiknowledge.com";
        }
        return $sites;
    }

    public static function isWOSEnabled() {
	    $pid = self::getPid();
	    if ($pid) {
            list($userid, $passwd) = WebOfScience::getCredentials($pid);
            if ($userid && $passwd) {
                return TRUE;
            }
        }
	    return FALSE;
    }

    public static function isScopusEnabled() {
	    if (self::getSetting("scopus_api_key")) {
	        return TRUE;
        }
	    return FALSE;
    }

    public static function getSiteListHTML() {
        $sites = self::getSites();
        $html = "<ul>\n";
        foreach ($sites as $site => $domain) {
            $html .= "<li>$site ($domain)</li>\n";
        }
        $html .= "</ul>\n";
        return $html;
    }

    public static function getIntroductoryFromEmail() {
		return self::getSetting("introductory_from");;
	}

	public static function getEmailName($record) {
		return "initial_survey_$record";
	}

    public static function getGrantClasses() {
        return [
            "T" => "Training Grant (T)",
            "K" => "Career Development Grant (K)",
            "Other" => "Other (e.g., not related to a grant)",
        ];
    }

    public static function getServerClasses() {
        return [
            "test" => "Test/Development",
            "prod" => "Production Server",
        ];
    }

    public static function log($mssg, $pid = FALSE) {
	    if (!$mssg) {
	        return;
        }
	    $pid = Sanitizer::sanitizePid($pid);
	    if (self::isLocalhost()) {
            $page = (isset($_GET['page']) && !is_array($_GET['page'])) ? $_GET['page'] : "";
	        if (
	            isset($_GET['test'])
                || !preg_match('/reporting/', $page)
            ) {
                $mssg = Sanitizer::sanitizeWithoutStrippingHTML($mssg, FALSE);
                if ($pid) {
                    error_log("$pid: $mssg");
                    // echo "$pid: $mssg\n";
                } else {
                    error_log($mssg);
                    // echo "$mssg\n";
                }
            }
	        return;
        }
        if (isset($_GET['test'])) {
            $mssg = Sanitizer::sanitizeWithoutStrippingHTML($mssg, FALSE);
            echo $mssg . "<br>\n";
        } else {
            if (!is_array($pid)) {
                $pids = [$pid];
            } else if (!$pid) {
                $pid = self::getPid();
                if ($pid) {
                    $pids = [$pid];
                } else {
                    $pids = [];
                    if (self::isVanderbilt()) {
                        error_log($mssg);
                    }
                }
            } else {
                # $pid is an array
                $pids = $pid;
            }
            $module = self::getModule();
            if ($module) {
                foreach ($pids as $pid) {
                    if ($pid) {
                        $params = ["project_id" => $pid];
                        $module->log($mssg, $params);
                        if (self::isVanderbilt()) {
                            $currTime = date("Y-m-d H:i:s");
                            error_log($pid." ($currTime): ".$mssg);
                        }
                    } else if (self::isVanderbilt()) {
                        error_log($mssg);
                    }
                }
            } else if (self::isVanderbilt()) {
                error_log($mssg);
            }
        }
	}

	public static function isREDCap() {
		$rootPage = $_SERVER['PHP_SELF'] ?? "";
		if (strpos($rootPage, "ExternalModules") !== FALSE) {
			return FALSE;
		}
		if (strpos($rootPage, APP_PATH_WEBROOT) === FALSE) {
			return FALSE;
		}
		return TRUE;
	}

	public static function isHelpOn() {
		return (isset($_SESSION['showHelp']) && $_SESSION['showHelp']);
	}

	public static function getCurrPage() {
	    if (isset($_GET['page'])) {
            return REDCapManagement::sanitize($_GET['page']).".php";
        }
	    return "";
	}

	public static function isFAQ() {
		$currPage = self::getCurrPage();
		$faqs = array("help/faq.php", "help/how.php", "help/why.php");
		if (in_array($currPage, $faqs)) {
			return TRUE;
		}
		return FALSE;
	}

	public static function setPid($pid) {
	    // self::log("Setting pid to $pid", $pid);
        $pid = Sanitizer::sanitizePid($pid);
        $_GET['pid'] = $pid;
        $_GET['project_id'] = $pid;
		self::$pid = $pid;
	}

    private static function constructThisURL() {
        $isHTTPS = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off'));
        $serverPort = $_SERVER['SERVER_PORT'] ?? 0;
        $isSSLPort = $serverPort == 443;
        $protocol = ($isHTTPS || $isSSLPort) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? "";
        $uri = $_SERVER['REQUEST_URI'] ?? "";
        return $protocol.$host.$uri;
    }

	public static function getPid($tokenForPid = "") {
        $token = $tokenForPid;
		if ($token) {
			$pid = self::getPidFromToken($token);
			if (!$pid && self::$firstPidError) {
                self::$firstPidError = FALSE;
			    self::log("ERROR: Could not find pid $pid for $token: ".json_encode(debug_backtrace()));
            }
			return $pid;
		}
		if (self::$pid) {
			return self::$pid;
		}
        $thisUrl = self::constructThisURL();
        if (preg_match("/project_id=\d+/", $thisUrl) && preg_match("/pid=\d+/", $thisUrl)) {
            throw new \Exception("Invalid URL");
        }

		$requestedPid = FALSE;
 		if (isset($_GET['pid'])) {
			# least reliable because REDCap can sometimes change this value in other crons
            $module = self::getModule();
            if ($module) {
                $requestedPid = Sanitizer::sanitize($module->getProjectId($_GET['pid']));
            } else {
                $requestedPid = Sanitizer::sanitizePid($_GET['pid']);
            }
		} else if (isset($_GET['project_id'])) {
            $requestedPid = Sanitizer::sanitizePid($_GET['project_id']);
        }
 		if ($requestedPid && is_numeric($requestedPid)) {
 		    $module = self::getModule();
 		    $possiblePids = $module->getPids();
 		    foreach ($possiblePids as $possiblePid) {
 		        if ($possiblePid == $requestedPid) {
 		            return $possiblePid;
                }
            }
        }
		return NULL;
	}

	public static function getGeneralSettingName() {
		return "ft_data";
	}

	public static function getInternalKLength() {
		$value = self::getSetting("internal_k_length");
		if ($value) {
			return $value;
		} else {
			return "3";
		}
	}

	public static function getK12KL2Length() {
		$value = self::getSetting("k12_kl2_length");
		if ($value) {
			return $value;
		} else {
			return "3";
		}
	}

	public static function getIndividualKLength() {
		$value = self::getSetting("individual_k_length");
		if ($value) {
			return $value;
		} else {
			return "5";
		}
	}

	public static function getPageFromUrl($url) {
		$params = self::parseGetParams($url);
		return $params['page'].".php";
	}

	public static function parseGetParams($url) {
		$comps = parse_url($url);
		if (isset($comps['query'])) {
            $pairs = preg_split("/\&/", $comps['query']);
            $params = [];
            foreach ($pairs as $pair) {
                $a = preg_split("/=/", $pair);
                if (count($a) == 2) {
                    $params[$a[0]] = urldecode($a[1]);
                } else if (count($a) == 1) {
                    $params[$a[0]] = TRUE;
                } else {
                    throw new \Exception("GET parameter '$pair' could not be interpreted!");
                }
            }
            return $params;
        }
		return [];
	}

	public static function makeLogo() {
		return "<a href='https://redcap.vanderbilt.edu/plugins/career_dev/consortium/'><img src='".self::link("img/flight_tracker_logo_medium.png")."' alt='Flight Tracker for Scholars'></a>";
	}

	public static function link($relativeUrl, $pid = "", $withWebroot = FALSE) {
		return self::getLink($relativeUrl, $pid, $withWebroot);
	}

	# deprecated
	public static function getCities() {
		return self::getSetting("cities");
	}

	public static function enqueueTonight() {
		$module = CareerDev::getModule();
		if ($module) {
			$module->enqueueTonight();
		}
	}

	# used in the plugin version
	public static function getPluginModule() {
	    return self::getModule();
    }

	public static function getModule() {
		global $module;

		if ($module) {
			return $module;
		} else if (self::$passedModule) {
			return self::$passedModule;
		}
		return NULL;
	}

    public static function getRootDir($withWebroot = FALSE) {
        global $server;
        $directoryDir = "/plugins/";
        if ($withWebroot) {
            $newWebroot = str_replace("/api/", "", $server);
        } else if (Application::isLocalhost()) {
            $newWebroot = "https://localhost/redcap";
        } else {
            $newWebroot = "";
        }
        return $newWebroot.$directoryDir."career_dev";
    }

    private static function getThisPageURL($project_id) {
        if (Application::isPluginProject($project_id)) {
            $port = $_SERVER['SERVER_PORT'] ?? "";
            $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || ($port == 443)) ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'] ?? "";
            $fileLocation = explode("?", $_SERVER['REQUEST_URI'] ?? "")[0];
            $url = $protocol . $host . $fileLocation;
            if ($project_id) {
                $url .= "?pid=".urlencode($project_id);
            } else {
                $url .= "?pid=".urlencode(Sanitizer::sanitizePid($_GET['pid']));
            }
            if (isset($_GET['limitPubs'])) {
                $limitYear = Sanitizer::sanitizeInteger($_GET['limitPubs']);
                if ($limitYear) {
                    $url .= "&limitPubs=".$limitYear;
                }
            }
            return $url;
        } else {
            $fullURL = self::constructThisURL();
            $url = explode("?", $fullURL)[0];
            $paramKeys = ["page", "pid", "prefix", "project_id", "limitPubs"];
            $initialSeparator = "?";
            foreach ($paramKeys as $key) {
                if (isset($_GET[$key])) {
                    $url .= "$initialSeparator$key=".urlencode(urldecode(Sanitizer::sanitize($_GET[$key])));
                    $initialSeparator = "&";
                }
            }
            return $url;
        }
    }

    private static function getPluginPageURL($http, $project_id, $withWebroot, $isMentorAgreementPage) {
        if ($isMentorAgreementPage) {
            $pidParamName = "project_id";
        } else {
            $pidParamName = "pid";
        }
        $http = $http."?$pidParamName=".$project_id;
        return self::getRootDir($withWebroot)."/".$http;
    }

    public static function getModulePageURL($http, $project_id, $isMentorAgreementPage, $module) {
        $page = preg_replace("/^\//", "", $http);
        $url = $module->getUrl($page);
        if (
            (isset($_GET['project_id']) && is_numeric($_GET['project_id']))
            || $isMentorAgreementPage
        ) {
            if (preg_match("/pid=/", $url)) {
                $url = preg_replace("/pid=/", "project_id=", $url);
            } else if (is_numeric($_GET['project_id'])) {
                $validPids = Application::getPids();
                foreach ($validPids as $possiblePid) {
                    if ($possiblePid == $project_id) {
                        $url .= "&project_id=".$possiblePid;
                    }
                }
            } else if (!preg_match("/project_id=/", $url)) {
                $url .= "&project_id=$project_id";
            }
        }
        if ($project_id && is_numeric($project_id)) {
            $url = preg_replace("/pid=\d+/", "pid=$project_id", $url);
            $url = preg_replace("/project_id=\d+/", "project_id=$project_id", $url);
            if (!preg_match("/pid=/", $url) && !preg_match("/project_id=/", $url)) {
                $url .= "&pid=$project_id";
            }
        }
        return $url;
    }

    private static function getREDCapSignupURL() {
        if (self::isVanderbilt()) {
            return 'https://redcap.vanderbilt.edu/surveys/?s=L4R3NJ8XME';
        } else {
            global $homepage_contact_url, $homepage_contact_email;
            if ($homepage_contact_url) {
                return $homepage_contact_url;
            } else {
                return "mailto:$homepage_contact_email";
            }
        }
    }

    public static function getLink($relativeUrl, $pid = "", $withWebroot = FALSE) {
        $relativeUrl = preg_replace("/^\//", "", $relativeUrl);
        $isMentorAgreementPage = (
            preg_match("/^mentor\//", $relativeUrl)
            && !preg_match("/^mentor\/dashboard/", $relativeUrl)
            && !preg_match("/^mentor\/config/", $relativeUrl)
        );
        if (!$pid) {
            $pid = self::getPID();
        }
        if ($relativeUrl == "this") {
            return self::getThisPageURL($pid);
        } else if ($relativeUrl == "signupToREDCap") {
            return self::getREDCapSignupURL();
        } else if (Application::isPluginProject($pid)) {
            return self::getPluginPageURL($relativeUrl, $pid, $withWebroot, $isMentorAgreementPage);
        } else if ($module = self::getModule()) {
            return self::getModulePageURL($relativeUrl, $pid, $isMentorAgreementPage, $module);
        }
		return "";
	}

    public static function getVFRSToken() {
        $file = Application::getCredentialsDir()."/career_dev/vfrs.php";
        if (file_exists($file)) {
            include($file);
            global $vfrsToken;
            return $vfrsToken;
        }
        return "";
    }

    public static function getREDCapDir() {
		if (APP_PATH_WEBROOT) {
			# get rid of trailing /'s per convention
			return preg_replace("/\/$/", "", APP_PATH_WEBROOT);
		}
		return "/redcap_v8.0.0";
	}

	public static function getInstitutionCount() {
		return count(self::getInstitutions());
	}

	public static function getInstitutions($pid = NULL) {
		$shortInst = self::getShortInstitution($pid);
		$longInst = self::getInstitution($pid);

		$institutions = array();
		if (preg_match("/".strtolower($shortInst)."/", strtolower($longInst))) {
			array_push($institutions, $shortInst);
			array_push($institutions, $longInst);
		} else {
			array_push($institutions, $longInst);
		}

		$otherInsts = preg_split("/\s*[,\/]\s*/", self::getSetting("other_institutions", $pid));
		foreach ($otherInsts as $otherInst) {
			if ($otherInst && !in_array($otherInst, $institutions)) {
				array_push($institutions, $otherInst);
			}
		}

		return $institutions;
	}

	public static function isEligible($pid) {
		if ($pid == 73405) {
			$userids = array("pearsosj", "newmanpd", "vanhoose");
		} else {
			$userids = array("pearsosj", "newmanpd", "heltonre");
		}
		return in_array(USERID, $userids);
	}

	private static function getCredentialsFile() {
	    return Application::getCredentialsDir()."/career_dev/credentials.php";
    }

	# gets the pid of a token if a PID context is applicable
	# returns current PID if no token is specified and if using the current server
	# otherwise returns empty string
	public static function getPidFromToken($localToken = "") {
        global $pid, $token, $server, $info;
	    if (file_exists(self::getCredentialsFile())) {
	        include(self::getCredentialsFile());
        }
		if (!$localToken) {
			if (strpos($server, SERVER_NAME) !== FALSE) {
				return $pid;
			}
		}
		if ($localToken == $token) {
			return $pid;
		}
		foreach ($info as $key => $row) {
			if (($row['token'] == $localToken) && (strpos($row['server'], SERVER_NAME) !== FALSE)) {
				return $row['pid'];
			}
            if (isset($row['mentorToken']) && ($row['mentorToken'] == $localToken) && (strpos($row['server'], SERVER_NAME) !== FALSE)) {
                return $row['mentorPid'];
            }
		}
		if ($relevantPid = self::getPidFromDatabase($localToken)) {
            return $relevantPid;
        }
		return "";
	}

    # to distinguish between Vanderbilt servers (which pull straight from the git repo) and those from the REDCap repo
    # flightTracker = Vanderbilt
    # flight_tracker = from REDCap Repo
	public static function getPrefix() {
        if (self::isVanderbilt() || self::isLocalhost()) {
            return "flightTracker";
        } else {
            return "flight_tracker";
        }
    }

	public static function getModuleId() {
	    return ExternalModules::getIdForPrefix(self::getPrefix());
    }

	public static function getPidFromDatabase($localToken) {
	    if (isset(self::$tokenTranslateToPid[$localToken])) {
	        return self::$tokenTranslateToPid[$localToken];
        } else if (isset(self::$mentorTokenTranslateToPid[$localToken])) {
	        return self::$mentorTokenTranslateToPid[$localToken];
        }
        $fieldsToSearch = ["token", "mentor_token"];
	    $prefix = self::getPrefix();
        if ($prefix) {
            $module = self::getModule();
            foreach ($fieldsToSearch as $field) {
                $sql = "SELECT s.project_id AS project_id FROM redcap_external_module_settings AS s INNER JOIN redcap_external_modules AS m ON m.external_module_id = s.external_module_id WHERE s.key = ? AND m.directory_prefix = ? AND s.value = ?";
                $q = $module->query($sql, [$field, $prefix, $localToken]);
                $numRows = $q->num_rows;
                $currentPid = FALSE;
                while ($row = $q->fetch_assoc()) {
                    $currentPid = $row["project_id"];
                    break;
                }
                if ($currentPid) {
                    if ($field == "token") {
                        self::$tokenTranslateToPid[$localToken] = $currentPid;
                        return $currentPid;
                    } else if ($field == "mentor_token") {
                        # mentor_token
                        $mentorPid = self::getSetting('mentor_pid', $currentPid);
                        self::$mentorTokenTranslateToPid[$localToken] = $mentorPid;
                        return $mentorPid;
                    } else {
                        throw new \Exception("Looking through invalid field $field");
                    }
                } else {
                    self::log("Could not find $field; found $numRows rows from $sql");
                }
            }
        } else {
            throw new \Exception("Could not find module-id");
        }
        return "";
    }

	public static function getHelpLink() {
		return self::link("/help/index.php");
	}

	public static function getHelpHiderLink() {
		return self::link("/help/close.php");
	}

	public static function getHomeLink() {
		return self::link("/index.php");
	}

	public static function saveCurrentDate($setting, $pid) {
		$ary = self::getSetting(self::getGeneralSettingName(), $pid);
		if (!is_array($ary)) {
		    $ary = [];
        }
		$ary[$setting] = date("Y-m-d");
		self::setSetting(self::getGeneralSettingName(), $ary, $pid);
	}

	public static function clearDate($setting, $pid) {
        $ary = self::getSetting(self::getGeneralSettingName(), $pid);
        if (isset($ary[$setting])) {
            unset($ary[$setting]);
            self::setSetting(self::getGeneralSettingName(), $ary, $pid);
        }
    }

	public static function saveSetting($field, $value, $pid = NULL) {
	    self::setSetting($field, $value, $pid);
    }

	public static function setSetting($field, $value, $pid = NULL) {
		$module = self::getModule();
		if ($module) {
		    if (!$pid) {
                $pid = self::getPid();
            }
			if ($pid) {
                $module->setProjectSetting($field, $value, $pid);
            } else {
                throw new \Exception("Could not find pid!");
            }
		} else {
			throw new \Exception("Could not find module!");
		}
	}

	public static function getAllSettings($pid = "") {
        $module = self::getModule();
        if ($module) {
            if (!$pid) {
                $pid = self::getPid();
            }
            if ($pid) {
                return $module->getProjectSettings($pid);
            }
        }
        return [];
    }

	public static function getSetting($field, $pid = "") {
	    if (
            self::isVanderbilt()
            && ((Application::isServer("redcap.vanderbilt.edu") && ($pid == 66635))
                || ((Application::isLocalhost() && ($pid == 15))))
        ) {
	        # TODO add redcaptest.vanderbilt.edu
	        $module = ExternalModules::getModuleInstance("vanderbilt_plugin-settings");
	        $value = $module->getProjectSetting($field, $pid);
	        if ($value) {
	            return $value;
            }
            if (file_exists(self::getCredentialsFile())) {
                include_once(self::getCredentialsFile());
                global $info;
                if (isset($info['prod'][$field])) {
                    return $info['prod'][$field];
                }
            }
            if (($field == "admin_email") || ($field == "adminEmail")) {
                return "scott.j.pearson@vumc.org";
            }
            return "";
        }
		$module = Application::getFlightTrackerModule();
		if ($module) {
		    if (!$pid) {
                $pid = self::getPid();
            }
			$value = $module->getProjectSetting($field, $pid);
		    if ($value) {
		        return $value;
            } else if ($field == "default_from") {
		        return "noreply.flighttracker@vumc.org";
            }
		}
		return "";
	}

	public static function getTimezone() {
		return self::getSetting("timezone");
	}

	public static function getShortInstitution($pid) {
		return self::getSetting("short_institution", $pid);
	}

	public static function getInstitution($pid) {
		return self::getSetting("institution", $pid);
	}

	public static function getProgramName() {
		return "Flight Tracker";
	}

	public static function getMenuBackgrounds() {
		return array(
                "View" => "view.css",
                "Grants" => "view.css",
                "Pubs" => "view.css",
                "Patents" => "view.css",
				"Scholars" => "scholars.css",
				"Dashboards" => "dashboards.css",
				"Cohorts / Filters" => "cohorts.css",
				"General" => "general.css",
				"Resources" => "resources.css",
				"Mentors" => "mentoring.css",
				"REDCap" => "",
				"Wrangle" => "wrangle.css",
				"Help" => "help.css",
				"Env" => "env.css",
				);
	}

	public static function getBackgroundCSS() {
		$currPage = urlencode(REDCapManagement::sanitize($_GET['page']));
		$bgs = self::getMenuBackgrounds();

        $page = (isset($_GET['page']) && !is_array($_GET['page'])) ? $_GET['page'] : "";
		if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
			return self::link("/css/white.css");
		}
		if ($page == "index") {
			return self::link("/css/front.css");
		}

		$default = "";
		if (preg_match("/search\//", $page)) {
			$default = self::link("/css/env.css");
		}
        if (preg_match("/reporting\//", $page)) {
            $default = self::link("/css/general.css");
        }
        if (preg_match("/dashboard\//", $page)) {
            $default = self::link("/css/dashboards.css");
        }

		foreach ($bgs as $menu => $css) {
			if ($css && ($menu != "Environment")) {
				$menuItems = self::getMenu($menu);
				foreach ($menuItems as $itemName => $menuPage) {
					if ((strpos($menuPage, $currPage) !== FALSE) && !isREDCap()) {
						return self::link("/css/".$css);
					}
				}
			}
		}
		return $default;
	}

	public static function isViDERInstalledForSystem() {
		$modules = ExternalModules::getEnabledModules();
		return isset($modules["vider"]);
	}

	public static function isViDERInstalledForProject() {
		global $pid;
		$modules = ExternalModules::getEnabledModules($pid);
		return isset($modules["vider"]);
	}

	public static function makeBackgroundCSSLink() {
		$css = self::getBackgroundCSS();
		if ($css) {
			return "<link rel='stylesheet' type='text/css' href='$css'>";
		}
		return "";
	}

	public static function getMenus() {
		return array_keys(self::getMenuBackgrounds());
	}

	public static function isVanderbilt() {
	    if (Application::isLocalhost()) {
	        return TRUE;
        }
		return preg_match("/vanderbilt.edu/", SERVER_NAME);
	}

	public static function getRepeatingFormsAndLabels($metadata = []) {
	    return DataDictionaryManagement::getRepeatingFormsAndLabels($metadata);
    }

    public static function getRelevantChoices() {
	    $itemChoices = [
            "scholars" => "Initial Survey (self-survey)",
            "followup" => "Followup Survey (self-survey)",
            "custom" => "New Custom Grants (REDCap)",
            "modify" => "Manual Modification Field (REDCap)",
            "manual" => "Manual Form (REDCap)",
            "pubmed" => "PubMed (online database)",
            "reporter" => "Federal Reporter (online database)",
            "exporter" => "NIH ExPORTER (online database)",
            "nih_reporter" => "NIH RePORTER (online database)",
            "nsf" => "NSF Grants",
            "ies" => "Dept. of Ed. Grants",
        ];
	    if (self::isVanderbilt()) {
            $itemChoices = array_merge($itemChoices, [
                "coeus" => "COEUS (online database)",
                "vfrs" => "VFRS Survey (self-survey)",
                "accessvu" => "AccessVU (online database)",
                "ldap" => "University Directory",
                "vera" => "VERA (online database)",
            ]);
	        if (self::getPid() == 66635) {
                $itemChoices = array_merge($itemChoices, [
                    "data" => "Newman Data (spreadsheet)",
                    "sheet2" => "Newman Sheet2 (spreadsheet)",
                    "demographics" => "Newman Demographics (spreadsheet)",
                    "expertise" => "Expertise Survey (self-survey)",
                    "new2017" => "2017 New Scholars (spreadsheet)",
                    "k12", "K12 List (spreadsheet)",
                    "nonrespondents" => "Nonrespondents (spreadsheet)",
                ]);
            }
        } else {
            $itemChoices = array_merge($itemChoices, [
                "local_gms" => "Institutional Grants Management System",
            ]);
        }
	    return $itemChoices;
    }

    public static function isLocalhost() {
	    return (SERVER_NAME == "localhost");
    }

    public static function isTestGroup($pid) {
        return (SERVER_NAME == "redcap.vanderbilt.edu") && in_array($pid, [105963, 101785]);
    }

    public static function duplicateAllSettings($srcPid, $destPid, $defaultSettings = []) {
	    if ($srcPid && $destPid) {
	        self::setPid($srcPid);
	        $module = self::getModule();
	        $srcSettings = $module->getProjectSettings($srcPid);
	        self::log("Got srcSettings for $srcPid ".substr( json_encode($srcSettings), 0, 50))."...";
            $destSettings = $defaultSettings;
            $skip = ["version"];
            foreach ($srcSettings as $setting => $value) {
                $v = isset($value['value']) ? $value['value'] : $value;
                if (!isset($destSettings[$setting]) && !isset($value['system_value']) && !in_array($setting, $skip)) {
                    $destSettings[$setting] = $v;
                }
            }

            foreach ($destSettings as $setting => $value) {
                self::saveSetting($setting, $value, $destPid);
            }
            self::log("Copied ".count($destSettings)." settings from $srcPid to $destPid");
        } else {
	        throw new \Exception("Could not find source PID $srcPid or destination PID $destPid");
        }
    }

    public static function has($instrument, $pid = "") {
        if (!$pid) {
            $pid = self::getPid();
        }
        if ($instrument == "patent") {
            return Download::hasField($pid, "patent_number", $instrument);
        }
        if ($instrument == "mentoring_agreement") {
            return Download::hasField($pid, "mentoring_frequency", $instrument);
        }
        return FALSE;
    }

	public static function getMenu($menuName) {
		$pid = self::getPid();
		$r = self::getREDCapDir();
        if ($menuName == "Grants") {
            $ary = [
                "Stylized CDA Table" => self::link("/charts/makeGrantTable.php")."&CDA",
                "Stylized Table of Grants" => self::link("/charts/makeGrantTable.php"),
                "List of All Grants" => self::link("/charts/makeGrantTable.php")."&plain",
                "Social Network of Grant Collaboration" => self::link("/socialNetwork/collaboration.php")."&grants",
                "Compare Data Sources" => self::link("/tablesAndLists/dataSourceCompare.php"),
                "Financial ROI for Grants" => self::link("/financial/roi.php"),
                "Search Grants" => self::link("/search/index.php"),
                "Search Within a Timespan" => self::link("/search/inTimespan.php"),
                "Grant Budgets, Active at a Time" => self::link("/financial/budget.php")."&timespan=active",
                "All-Time Grant Budgets" => self::link("/financial/budget.php")."&timespan=all",
            ];
            if (self::isVanderbilt()) {
                $ary["Grant Success Rates"] = self::link("/successRate.php");
                // $ary['Evaluate Grant Submissions'] = self::link("/submissions.php");
            }
            return $ary;
        }
        if (in_array($menuName, ["Pubs", "Publications"])) {
            $ary = [
                "Publication List" => self::link("/publications/view.php"),
                "Brag: Publications Widget" => self::link("/brag.php")."&showHeaders",
                "Social Network of Co-Authorship" => self::link("/socialNetwork/collaboration.php"),
                "Word Clouds of Publications" => self::link("/publications/wordCloud.php"),
                "Search Publications" => self::link("/search/publications.php"),
                "Publication Impact Measures" => self::link("/publications/scoreDistribution.php"),
                "Public Access Compliance" => self::link("/publications/complianceReport.php"),
            ];
            return $ary;
        }
        if ($menuName == "View") {
            $token = self::getSetting("token", $pid);
            $server = self::getSetting("server", $pid);
            $switches = new FeatureSwitches($token, $server, $pid);
            $ary = [
                "Demographics Table" => self::link("/charts/makeDemographicsTable.php"),
                "REDCap Reports" => $r."/DataExport/index.php",
                "Missingness Report<br>(Computationally Expensive)" => self::link("/tablesAndLists/missingness.php"),
            ];
            if (self::has("patent") && $switches->isOnForProject("Patents")) {
                $ary["Patent Viewer"] = self::link("patents/view.php");
            }
            return $ary;
        }
		if (($menuName == "Mentoring") || ($menuName == "Mentor") || ($menuName == "Mentors")) {
            $token = self::getSetting("token", $pid);
            $server = self::getSetting("server", $pid);
            $switches = new FeatureSwitches($token, $server, $pid);
		    $ary = [];
		    if (self::has("mentoring_agreement") && $switches->isOnForProject("Mentee-Mentor")) {
                $ary["Configure Mentee-Mentor Agreements"] = self::link("/mentor/config.php");
                $ary["Add Mentors for Existing Scholars"] = self::link("addMentor.php");
                $ary["Mentee-Mentor Agreements Dashboard"] = self::link("/mentor/dashboard.php");
            }
			$ary = array_merge($ary, [
					"List of Mentors" => self::link("/tablesAndLists/mentorList.php"),
					"Mentor Performance" => self::link("/tablesAndLists/mentorConversion.php"),
					"All Mentor Data (CSV)" => self::link("/tablesAndLists/generateMentoringCSV.php"),
                    "Trainees Becoming Mentors" => self::link("/tablesAndLists/trainee2mentor.php"),
					]);
		    return $ary;
		}
		if ($menuName == "Scholars") {
            $ary = [
                "Add a New Scholar" => self::link("/addNewScholar.php"),
                "Scholar Profiles" => self::link("/profile.php"),
                "Inactivity Report" => self::link("/inactive.php"),
            ];
            $ary["Configure an Email"] = self::link("/emailMgmt/configure.php");
            $ary["View Email Log"] = self::link("/emailMgmt/log.php");
            $ary["View Email Queue"] = self::link("/emailMgmt/viewQueue.php");
            $ary["Who Has Responded to Surveys?"] = self::link("/emailMgmt/surveySubmissions.php");
            $ary["Import General Data"] = self::link("/import.php");
            $ary["Import Positions"] = self::link("/bulkImport.php") . "&positions";
            if (self::isVanderbilt() && ($pid == 145767)) {
                $ary["LDAP Lookup"] = "https://redcap.vanderbilt.edu/plugins/career_dev/LDAP/ldapLookup.php";
            }

            return $ary;
        }
		if ($menuName == "Dashboards") {
			$ary = [
					"Overall" => self::link("/dashboard/overall.php"),
					"Grants" => self::link("/dashboard/grants.php"),
					"Grant Budgets" => self::link("/dashboard/grantBudgets.php"),
					"Grant Budgets by Year" => self::link("/dashboard/grantBudgetsByYear.php"),
					"Publications" => self::link("/dashboard/publicationsByCategory.php"),
					"Emails" => self::link("/dashboard/emails.php"),
					"Demographics" => self::link("/dashboard/demographics.php"),
					"Dates" => self::link("/dashboard/dates.php"),
					"Resources" => self::link("/dashboard/resources.php"),
					];
			if (self::has("mentoring_agreement")) {
			    $ary["Mentee-Mentor Agreements"] = self::link("mentor/dashboard.php");
            }
            return $ary;
		}
		if (($menuName == "Cohorts / Filters") || ($menuName == "Cohorts")) {
			return array(
					"Add/Modify a Cohort" => self::link("/cohorts/addCohort.php"),
					"View Existing Cohorts" => self::link("/cohorts/viewCohorts.php"),
					"Manage Cohorts" => self::link("/cohorts/manageCohorts.php"),
					"Cohort Outcomes" => self::link("/cohorts/profile.php"),
					"Export a Cohort" => self::link("/cohorts/exportCohort.php"),
                    "View Cohort Metrics" => self::link("/cohorts/selectCohort.php"),
                    "Hand-Pick a Cohort" => self::link("/cohorts/pickCohort.php"),
					);
		}
		if ($menuName == "General") {
			return [
                'NIH Reporting Tables 2-4' => self::link("reporting/tables2-4/run/index.php"),
                "NIH Reporting Tables 5 &amp; 8" => self::link("reporting/index.php"),
                "Upload Prior NIH Reporting Tables" => self::link("reporting/upload_react/run/index.php"),
                "List of Scholar Names" => self::link("/tablesAndLists/summaryNames.php"),
                "K2R Conversion Calculator" => self::link("/k2r/index.php"),
                "Kaplan-Meier Conversion Success Curve" => self::link("/charts/kaplanMeierCurve.php"),
                "Configure Application" => self::link("/config.php"),
                "Configure Summaries" => self::link("/config.php")."&order",
                "Logging" => self::link("/log/index.php"),
                "Custom Programming" => self::link("/changes/README.md"),
                "Test Connectivity" => self::link("/testConnectivity.php"),
                "Copy Project to Another Server" => self::link("/copyProject.php"),
            ];
		}
		if ($menuName == "REDCap") {
			return [
                "REDCap Project" => $r."/index.php",
                "My REDCap Projects" => APP_PATH_WEBROOT_FULL."index.php?action=myprojects",
                "Add/Edit Records" => $r."/DataEntry/record_home.php",
                "Export Data or View Reports" => $r."/DataExport/index.php",
                "Create a New Report" => $r."/DataExport/index.php?create=1&addedit=1",
                "Data Dictionary" => $r."/Design/data_dictionary_upload.php",
                "Online Designer" => $r."/Design/online_designer.php",
                "Logging" => $r."/Logging/index.php",
                "API Playground" => $r."/API/playground.php",
            ];
		}
		if ($menuName == "Resources") {
			return [
                "Participation Roster" => self::link("/resources/add.php"),
                "Participation Roster for All Projects" => self::link("/resources/add.php")."&allPids",
                "Manage" => self::link("/resources/manage.php"),
                "Dashboard Metrics" => self::link("/dashboard/resources.php"),
                "Measure Resource ROI" => self::link("/resources/roi.php"),
                "Scholar Resource Use" => self::link("/resources/table.php"),
            ];
		}
		if ($menuName == "Help") {
			$currPage = self::getCurrPage();
			return [
					"Toggle Help" => "toggleHelp(\"".self::getHelpLink()."\", \"".self::getHelpHiderLink()."\", \"$currPage\");",
					"Flight Tracker Consortium" => self::link("/community.php"),
					"About Flight Tracker" => self::link("/help/about.php"),
					"Why Use?" => self::link("/help/why.php"),
					"How to Use?" => self::link("/help/how.php"),
					"Introductory Video" => self::link("/help/intro.php"),
                    "Full FAQ" => self::link("/help/faq.php"),
                    "Codebook" => self::link("/help/Codebook.pdf"),
					"How to Extend?" => self::link("/help/extend.php"),
					"Brand Your Project" => self::link("/help/brand.php"),
					// "Feedback" => self::link("/help/feedback.php"),
					];
		}
		if (($menuName == "Wrangle Data") || ($menuName == "Wrangle") || ($menuName == "Wrangler")) {
			$ary = [];
			$token = self::getSetting("token", $pid);
			$server = self::getSetting("server", $pid);
			$switches = new FeatureSwitches($token, $server, $pid);
            if ($switches->isOnForProject("Grants")) {
                $ary["Add a Custom Grant"] = self::link("/customGrants.php");
                $ary["Add Custom Grants by Bulk"] = self::link("/bulkImport.php")."&grants";
                $ary["*NEW* Grant Wrangler"] = self::link("/wrangler/index_new.php");
            }
			if ($switches->isOnForProject("Publications")) {
                $ary["Publication Wrangler"] = self::link("/wrangler/include.php")."&wranglerType=Publications";
            }
            if ($switches->isOnForProject("Grants")) {
                $ary["Lexical Translator"] = self::link("/lexicalTranslator.php");
            }
            $ary["Position Change Wrangler"] = self::link("/wrangler/positions.php");
			if (self::has("patent") && $switches->isOnForProject("Patents")) {
                $ary["Patent Wrangler"] = self::link("/wrangler/include.php")."&wranglerType=Patents";
            }
			return $ary;
		}
		return [];
	}

    private static $citationFields = [
        "record_id",
        "citation_pmid",
        "citation_doi",
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
        "eric_id",
        "eric_link",
        "eric_include",
        "eric_author",
        "eric_description",
        "eric_isbn",
        "eric_issn",
        "eric_peerreviewed",
        "eric_publicationdateyear",
        "eric_publicationtype",
        "eric_publisher",
        "eric_subject",
        "eric_title",
        "eric_sourceid",
        "eric_source",
        "eric_sponsor",
        "eric_url",
        "eric_institution",
        "eric_iesgrantcontractnum",
        "eric_ieswwcreviewed",
        "eric_e_datemodified",
        "eric_e_fulltext",
        "eric_educationlevel",
        "eric_last_update",
    ];

    public static $smallCitationFields = [
        "record_id",
        "citation_pmid",
        "citation_include",
        "citation_is_research",
        "eric_id",
        "eric_include",
        "eric_peerreviewed",
    ];

	public static $resourceFields = array(
						'record_id',
						'resources_participated',
						'resources_resource',
						);

	public static $reporterFields = array(
						"reporter_projectnumber",
						"reporter_fy",
						"reporter_title",
						"reporter_department",
						"reporter_agency",
						"reporter_ic",
						"reporter_totalcostamount",
						"reporter_nihapplid",
						"reporter_smapplid",
						"reporter_budgetstartdate",
						"reporter_budgetenddate",
						"reporter_contactpi",
						"reporter_otherpis",
						"reporter_congressionaldistrict",
						"reporter_dunsid",
						"reporter_latitude",
						"reporter_longitude",
						"reporter_orgname",
						"reporter_orgcity",
						"reporter_orgstate",
						"reporter_orgcountry",
						"reporter_orgzipcode",
						"reporter_projectstartdate",
						"reporter_projectenddate",
						"reporter_cfdacode",
						);

    public static $customFields = [
        "record_id",
        "custom_title",
        "custom_number",
        "custom_type",
        "custom_start",
        "custom_end",
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

    public static $exporterFields = array(
						"exporter_application_id",
						"exporter_activity",
						"exporter_administering_ic",
						"exporter_application_type",
						"exporter_arra_funded",
						"exporter_award_notice_date",
						"exporter_budget_start",
						"exporter_budget_end",
						"exporter_cfda_code",
						"exporter_core_project_num",
						"exporter_ed_inst_type",
						"exporter_foa_number",
						"exporter_full_project_num",
						"exporter_funding_ics",
						"exporter_funding_mechanism",
						"exporter_fy",
						"exporter_ic_name",
						"exporter_nih_spending_cats",
						"exporter_org_city",
						"exporter_org_country",
						"exporter_org_dept",
						"exporter_org_district",
						"exporter_org_duns",
						"exporter_org_fips",
						"exporter_org_ipf_code",
						"exporter_org_name",
						"exporter_org_state",
						"exporter_org_zipcode",
						"exporter_phr",
						"exporter_pi_ids",
						"exporter_pi_names",
						"exporter_program_officer_name",
						"exporter_project_start",
						"exporter_project_end",
						"exporter_project_terms",
						"exporter_project_title",
						"exporter_serial_number",
						"exporter_study_section",
						"exporter_study_section_name",
						"exporter_subproject_id",
						"exporter_suffix",
						"exporter_support_year",
						"exporter_direct_cost_amt",
						"exporter_indirect_cost_amt",
						"exporter_total_cost",
						"exporter_total_cost_sub_project",
						"exporter_abstract",
						"exporter_last_update",
						);

	public static $followupFields = array(
						"record_id",
						"followup_name_first",
						"followup_name_middle",
						"followup_name_last",
						"followup_email",
						"followup_name_preferred",
						"followup_name_preferred_enter",
						"followup_name_maiden",
						"followup_name_maiden_enter",
						"followup_primary_mentor",
						"followup_d15a",
						"followup_primary_dept",
						"followup_primary_dept_oth",
						"followup_division",
						"followup_institution",
						"followup_institution_oth",
						"followup_academic_rank",
						"followup_academic_rank_oth",
						"followup_academic_rank_dt",
						"followup_tenure_status",
						"followup_prev_appt",
						"followup_d16",
						"followup_prev1_primary_dept",
						"followup_prev1_primary_dept_oth",
						"followup_prev1_division",
						"followup_prev1_institution",
						"followup_prev1_academic_rank",
						"followup_prev1_academic_rank_oth",
						"followup_prev1_academic_rank_stdt",
						"followup_prev1_academic_rank_enddt",
						"followup_prev1_appt",
						"followup_d17",
						"followup_prev2_primary_dept",
						"followup_prev2_primary_dept_oth",
						"followup_prev2_division",
						"followup_prev2_institution",
						"followup_prev2_academic_rank",
						"followup_prev2_academic_rank_oth",
						"followup_prev2_academic_rank_stdt",
						"followup_prev2_academic_rank_enddt",
						"followup_prev2_appt",
						"followup_d18",
						"followup_prev3_primary_dept",
						"followup_prev3_primary_dept_oth",
						"followup_prev3_division",
						"followup_prev3_institution",
						"followup_prev3_academic_rank",
						"followup_prev3_academic_rank_oth",
						"followup_prev3_academic_rank_stdt",
						"followup_prev3_academic_rank_enddt",
						"followup_prev3_appt",
						"followup_d19",
						"followup_prev4_primary_dept",
						"followup_prev4_primary_dept_oth",
						"followup_prev4_division",
						"followup_prev4_institution",
						"followup_prev4_academic_rank",
						"followup_prev4_academic_rank_oth",
						"followup_prev4_academic_rank_stdt",
						"followup_prev4_academic_rank_enddt",
						"followup_prev4_appt",
						"followup_d20",
						"followup_prev5_primary_dept",
						"followup_prev5_primary_dept_oth",
						"followup_prev5_division",
						"followup_prev5_institution",
						"followup_prev5_academic_rank",
						"followup_prev5_academic_rank_oth",
						"followup_prev5_academic_rank_stdt",
						"followup_prev5_academic_rank_enddt",
						"followup_grant1_d",
						"followup_grant1_title",
						"followup_grant1_number",
						"followup_grant1_org",
						"followup_grant1_role",
						"followup_grant1_role_other",
						"followup_grant1_start",
						"followup_grant1_end",
						"followup_grant1_costs",
						"followup_grant1_another",
						"followup_grant2_d",
						"followup_grant2_title",
						"followup_grant2_number",
						"followup_grant2_org",
						"followup_grant2_role",
						"followup_grant2_role_other",
						"followup_grant2_start",
						"followup_grant2_end",
						"followup_grant2_costs",
						"followup_grant2_another",
						"followup_grant3_d",
						"followup_grant3_title",
						"followup_grant3_number",
						"followup_grant3_org",
						"followup_grant3_role",
						"followup_grant3_role_other",
						"followup_grant3_start",
						"followup_grant3_end",
						"followup_grant3_costs",
						"followup_grant3_another",
						"followup_grant4_d",
						"followup_grant4_title",
						"followup_grant4_number",
						"followup_grant4_org",
						"followup_grant4_role",
						"followup_grant4_role_other",
						"followup_grant4_start",
						"followup_grant4_end",
						"followup_grant4_costs",
						"followup_grant4_another",
						"followup_grant5_d",
						"followup_grant5_title",
						"followup_grant5_number",
						"followup_grant5_org",
						"followup_grant5_role",
						"followup_grant5_role_other",
						"followup_grant5_start",
						"followup_grant5_end",
						"followup_grant5_costs",
						"followup_grant5_another",
						"followup_grant6_d",
						"followup_grant6_title",
						"followup_grant6_number",
						"followup_grant6_org",
						"followup_grant6_role",
						"followup_grant6_role_other",
						"followup_grant6_org",
						"followup_grant6_role",
						"followup_grant6_role_other",
						"followup_grant6_start",
						"followup_grant6_end",
						"followup_grant6_costs",
						"followup_grant6_another",
						"followup_grant7_d",
						"followup_grant7_title",
						"followup_grant7_number",
						"followup_grant7_org",
						"followup_grant7_role",
						"followup_grant7_role_other",
						"followup_grant7_start",
						"followup_grant7_end",
						"followup_grant7_costs",
						"followup_grant7_another",
						"followup_grant8_d",
						"followup_grant8_title",
						"followup_grant8_number",
						"followup_grant8_org",
						"followup_grant8_role",
						"followup_grant8_role_other",
						"followup_grant8_start",
						"followup_grant8_end",
						"followup_grant8_costs",
						"followup_grant8_another",
						"followup_grant9_d",
						"followup_grant9_title",
						"followup_grant9_number",
						"followup_grant9_org",
						"followup_grant9_role",
						"followup_grant9_role_other",
						"followup_grant9_start",
						"followup_grant9_end",
						"followup_grant9_costs",
						"followup_grant9_another",
						"followup_grant10_d",
						"followup_grant10_title",
						"followup_grant10_number",
						"followup_grant10_org",
						"followup_grant10_role",
						"followup_grant10_role_other",
						"followup_grant10_start",
						"followup_grant10_end",
						"followup_grant10_costs",
						"followup_grant10_another",
						"followup_grant11_d",
						"followup_grant11_title",
						"followup_grant11_number",
						"followup_grant11_org",
						"followup_grant11_role",
						"followup_grant11_role_other",
						"followup_grant11_start",
						"followup_grant11_end",
						"followup_grant11_costs",
						"followup_grant11_another",
						"followup_grant12_d",
						"followup_grant12_title",
						"followup_grant12_number",
						"followup_grant12_org",
						"followup_grant12_role",
						"followup_grant12_role_other",
						"followup_grant12_start",
						"followup_grant12_end",
						"followup_grant12_costs",
						"followup_grant12_another",
						"followup_grant13_d",
						"followup_grant13_title",
						"followup_grant13_number",
						"followup_grant13_org",
						"followup_grant13_role",
						"followup_grant13_role_other",
						"followup_grant13_start",
						"followup_grant13_end",
						"followup_grant13_costs",
						"followup_grant13_another",
						"followup_grant14_d",
						"followup_grant14_title",
						"followup_grant14_number",
						"followup_grant14_org",
						"followup_grant14_role",
						"followup_grant14_role_other",
						"followup_grant14_start",
						"followup_grant14_end",
						"followup_grant14_costs",
						"followup_grant14_another",
						"followup_grant15_d",
						"followup_grant15_title",
						"followup_grant15_number",
						"followup_grant15_org",
						"followup_grant15_role",
						"followup_grant15_role_other",
						"followup_grant15_start",
						"followup_grant15_end",
						"followup_grant15_costs",
						"followup_date",
						"followup_complete",
						);

	public static $checkFields = array(
						"record_id",
						"check_ecommons_id",
						"check_name_first",
						"check_name_middle",
						"check_name_last",
						"check_name_preferred",
						"check_name_preferred_enter",
						"check_name_maiden",
						"check_name_maiden_enter",
						"check_date_of_birth",
						"check_gender",
						"check_race",
						"check_ethnicity",
						"check_disadvantaged",
						"check_disability",
						"check_disability_type",
						"check_citizenship",
						"check_citizenship_acquired",
						"check_perm_residency_recvd",
						"check_d1",
						"check_degree1",
						"check_degree1_oth",
						"check_clinicaldoct_yesno1",
						"check_degree1_month",
						"check_degree1_year",
						"check_degree1_institution",
						"check_degree1_country",
						"check_degree1_another",
						"check_d2",
						"check_degree2",
						"check_degree2_oth",
						"check_clinicaldoct_yesno2",
						"check_degree2_month",
						"check_degree2_year",
						"check_degree2_institution",
						"check_degree2_country",
						"check_degree2_another",
						"check_d3",
						"check_degree3",
						"check_degree3_oth",
						"check_clinicaldoct_yesno3",
						"check_degree3_month",
						"check_degree3_year",
						"check_degree3_institution",
						"check_degree3_country",
						"check_degree3_another",
						"check_d4",
						"check_degree4",
						"check_degree4_oth",
						"check_clinicaldoct_yesno4",
						"check_degree4_month",
						"check_degree4_year",
						"check_degree4_institution",
						"check_degree4_country",
						"check_degree4_another",
						"check_d5",
						"check_degree5",
						"check_degree5_oth",
						"check_clinicaldoct_yesno5",
						"check_degree5_month",
						"check_degree5_year",
						"check_degree5_institution",
						"check_degree5_country",
						"check_residency1",
						"check_d6",
						"check_residency1_month",
						"check_residency1_year",
						"check_residency1_institution",
						"check_residency1_country",
						"check_residency1_another",
						"check_d7",
						"check_residency2_month",
						"check_residency2_year",
						"check_residency2_institution",
						"check_residency2_country",
						"check_residency2_another",
						"check_d8",
						"check_residency3_month",
						"check_residency3_year",
						"check_residency3_country",
						"check_residency3_institution",
						"check_residency3_another",
						"check_d9",
						"check_residency4_month",
						"check_residency4_year",
						"check_residency4_institution",
						"check_residency4_country",
						"check_d10",
						"check_residency4_another",
						"check_residency5_month",
						"check_residency5_year",
						"check_residency5_institution",
						"check_residency5_country",
						"check_fellowship",
						"check_d11",
						"check_fellow1_month",
						"check_fellow1_year",
						"check_fellow1_institution",
						"check_fellow1_country",
						"check_fellow1_another",
						"check_d12",
						"check_fellow2_month",
						"check_fellow2_year",
						"check_fellow2_institution",
						"check_fellow2_country",
						"check_fellow2_another",
						"check_d13",
						"check_fellow3_month",
						"check_fellow3_year",
						"check_fellow3_institution",
						"check_fellow3_country",
						"check_fellow3_another",
						"check_d14",
						"check_fellow4_month",
						"check_fellow4_year",
						"check_fellow4_institution",
						"check_fellow4_country",
						"check_fellow4_another",
						"check_d15",
						"check_fellow5_month",
						"check_fellow5_year",
						"check_fellow5_institution",
						"check_fellow5_country",
						"check_board_eligible",
						"check_board_completed_year",
						"check_d15a",
						"check_primary_dept",
						"check_primary_dept_oth",
						"check_division",
						"check_institution",
						"check_institution_oth",
						"check_academic_rank",
						"check_academic_rank_oth",
						"check_academic_rank_dt",
						"check_tenure_status",
						"check_prev_appt",
						"check_d16",
						"check_prev1_primary_dept",
						"check_prev1_primary_dept_oth",
						"check_prev1_division",
						"check_prev1_institution",
						"check_prev1_academic_rank",
						"check_prev1_academic_rank_oth",
						"check_prev1_academic_rank_stdt",
						"check_prev1_academic_rank_enddt",
						"check_prev1_appt",
						"check_d17",
						"check_prev2_primary_dept",
						"check_prev2_primary_dept_oth",
						"check_prev2_division",
						"check_prev2_institution",
						"check_prev2_academic_rank",
						"check_prev2_academic_rank_oth",
						"check_prev2_academic_rank_stdt",
						"check_prev2_academic_rank_enddt",
						"check_prev2_appt",
						"check_d18",
						"check_prev3_primary_dept",
						"check_prev3_primary_dept_oth",
						"check_prev3_division",
						"check_prev3_institution",
						"check_prev3_academic_rank",
						"check_prev3_academic_rank_oth",
						"check_prev3_academic_rank_stdt",
						"check_prev3_academic_rank_enddt",
						"check_prev3_appt",
						"check_d19",
						"check_prev4_primary_dept",
						"check_prev4_primary_dept_oth",
						"check_prev4_division",
						"check_prev4_institution",
						"check_prev4_academic_rank",
						"check_prev4_academic_rank_oth",
						"check_prev4_academic_rank_stdt",
						"check_prev4_academic_rank_enddt",
						"check_prev4_appt",
						"check_d20",
						"check_prev5_primary_dept",
						"check_prev5_primary_dept_oth",
						"check_prev5_division",
						"check_prev5_institution",
						"check_prev5_academic_rank",
						"check_prev5_academic_rank_oth",
						"check_prev5_academic_rank_stdt",
						"check_prev5_academic_rank_enddt",
						"check_grant1_d",
						"check_grant1_title",
						"check_grant1_number",
						"check_grant1_org",
						"check_grant1_role",
						"check_grant1_role_other",
						"check_grant1_start",
						"check_grant1_end",
						"check_grant1_costs",
						"check_grant1_another",
						"check_grant2_d",
						"check_grant2_title",
						"check_grant2_number",
						"check_grant2_org",
						"check_grant2_role",
						"check_grant2_role_other",
						"check_grant2_start",
						"check_grant2_end",
						"check_grant2_costs",
						"check_grant2_another",
						"check_grant3_d",
						"check_grant3_title",
						"check_grant3_number",
						"check_grant3_org",
						"check_grant3_role",
						"check_grant3_role_other",
						"check_grant3_start",
						"check_grant3_end",
						"check_grant3_costs",
						"check_grant3_another",
						"check_grant3_another",
						"check_grant4_d",
						"check_grant4_title",
						"check_grant4_number",
						"check_grant4_org",
						"check_grant4_role",
						"check_grant4_role_other",
						"check_grant4_start",
						"check_grant4_end",
						"check_grant4_costs",
						"check_grant4_another",
						"check_grant5_d",
						"check_grant5_title",
						"check_grant5_number",
						"check_grant5_org",
						"check_grant5_role",
						"check_grant5_role_other",
						"check_grant5_start",
						"check_grant5_end",
						"check_grant5_costs",
						"check_grant5_another",
						"check_grant6_d",
						"check_grant6_title",
						"check_grant6_number",
						"check_grant6_org",
						"check_grant6_role",
						"check_grant6_role_other",
						"check_grant6_start",
						"check_grant6_end",
						"check_grant6_costs",
						"check_grant6_another",
						"check_grant7_d",
						"check_grant7_title",
						"check_grant7_number",
						"check_grant7_org",
						"check_grant7_role",
						"check_grant7_role_other",
						"check_grant7_start",
						"check_grant7_end",
						"check_grant7_costs",
						"check_grant7_another",
						"check_grant8_d",
						"check_grant8_title",
						"check_grant8_number",
						"check_grant8_org",
						"check_grant8_role",
						"check_grant8_role_other",
						"check_grant8_start",
						"check_grant8_end",
						"check_grant8_costs",
						"check_grant8_another",
						"check_grant9_d",
						"check_grant9_title",
						"check_grant9_number",
						"check_grant9_org",
						"check_grant9_role",
						"check_grant9_role_other",
						"check_grant9_start",
						"check_grant9_end",
						"check_grant9_costs",
						"check_grant9_another",
						"check_grant10_d",
						"check_grant10_title",
						"check_grant10_number",
						"check_grant10_org",
						"check_grant10_role",
						"check_grant10_role_other",
						"check_grant10_start",
						"check_grant10_end",
						"check_grant10_costs",
						"check_grant10_another",
						"check_grant11_d",
						"check_grant11_title",
						"check_grant11_number",
						"check_grant11_org",
						"check_grant11_role",
						"check_grant11_role_other",
						"check_grant11_start",
						"check_grant11_end",
						"check_grant11_costs",
						"check_grant11_another",
						"check_grant12_d",
						"check_grant12_title",
						"check_grant12_number",
						"check_grant12_org",
						"check_grant12_role",
						"check_grant12_role_other",
						"check_grant12_start",
						"check_grant12_end",
						"check_grant12_costs",
						"check_grant12_another",
						"check_grant13_d",
						"check_grant13_title",
						"check_grant13_number",
						"check_grant13_org",
						"check_grant13_role",
						"check_grant13_role_other",
						"check_grant13_start",
						"check_grant13_end",
						"check_grant13_costs",
						"check_grant13_another",
						"check_grant14_d",
						"check_grant14_title",
						"check_grant14_number",
						"check_grant14_org",
						"check_grant14_role",
						"check_grant14_role_other",
						"check_grant14_start",
						"check_grant14_end",
						"check_grant14_costs",
						"check_grant14_another",
						"check_grant15_d",
						"check_grant15_title",
						"check_grant15_number",
						"check_grant15_org",
						"check_grant15_role",
						"check_grant15_role_other",
						"check_grant15_start",
						"check_grant15_end",
						"check_grant15_costs",
						"check_date",
						"initial_survey_complete",
						);

	public static $coeus2Fields = [
        "coeus2_id",
        "coeus2_altid",
        "coeus2_title",
        "coeus2_collaborators",
        "coeus2_role",
        "coeus2_in_progress",
        "coeus2_approval_in_progress",
        "coeus2_award_status",
        "coeus2_submitted_to_agency",
        "coeus2_awaiting_pi_approval",
        "coeus2_status",
        "coeus2_center_number",
        "coeus2_lead_department",
        "coeus2_agency_id",
        "coeus2_agency_name",
        "coeus2_agency_grant_number",
        "coeus2_grant_award_type",
        "coeus2_current_period_indirect_funding",
        "coeus2_current_period_total_funding",
        "coeus2_current_period_start",
        "coeus2_current_period_end",
        "coeus2_grant_activity_type",
        "coeus2_current_period_direct_funding",
        "coeus2_last_update",
    ];

	public static $coeusFields = array(
						"coeus_org",
						"coeus_dev_prop",
						"coeus_ip_number",
						"coeus_ip_seq",
						"coeus_award_no",
						"coeus_award_seq",
						"coeus_activity_type_code",
						"coeus_activity_type_description",
						"coeus_award_type_code",
						"coeus_award_type_description",
						"coeus_opportunity",
						"coeus_lead_unit",
						"coeus_lead_unit_name",
						"coeus_lead_department",
						"coeus_direct_sponsor_type",
						"coeus_direct_sponsor_name",
						"coeus_prime_sponsor_type",
						"coeus_prime_sponsor_name",
						"coeus_sponsor_award_number",
						"coeus_nih_mechanism",
						"coeus_title",
						"coeus_budget_start_date",
						"coeus_budget_end_date",
						"coeus_direct_cost_budget_period",
						"coeus_indirect_cost_budget_period",
						"coeus_total_cost_budget_period",
						"coeus_project_start_date",
						"coeus_project_start_date_note",
						"coeus_project_end_date",
						"coeus_project_end_date_note",
						"coeus_project_direct",
						"coeus_project_indirect",
						"coeus_project_total",
						"coeus_special_review_flag",
						"coeus_clinical_trial_phase1",
						"coeus_clinical_trial_phase2",
						"coeus_clinical_trial_phase3",
						"coeus_clinical_trial_phase4",
						"coeus_award_create_date",
						"coeus_award_last_updated",
						"coeus_award_status_code",
						"coeus_award_status",
						"coeus_person_id",
						"coeus_percent_effort",
						"coeus_calendar_year_effort",
						"coeus_academic_year_effort",
						"coeus_summer_year_effort",
						"coeus_person_name",
						"coeus_pi_flag",
						"coeus_multi_pi_flag",
						"coeus_project_role",
						"coeus_email_address",
						"coeus_era_commons_user_name",
						"coeus_home_unit",
						"coeus_directory_department",
						"coeus_directory_title",
						"coeus_coeus_department",
						"coeus_update_timestamp",
						"coeus_career_active",
						);

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
						
	public static $calculateFields = array(
						"record_id",
						"summary_calculate_order",
						"summary_calculate_list_of_awards",
						"summary_calculate_to_import",
						);

    public static $nihreporterFields = [
        "record_id",
        "nih_appl_id",
        "nih_subproject_id",
        "nih_fiscal_year",
        "nih_org_name",
        "nih_org_city",
        "nih_org_state",
        "nih_org_state_name",
        "nih_dept_type",
        "nih_project_num",
        "nih_project_serial_num",
        "nih_org_country",
        "nih_award_type",
        "nih_activity_code",
        "nih_award_amount",
        "nih_is_active",
        "nih_is_territory",
        "nih_project_num_split",
        "nih_principal_investigators",
        "nih_contact_pi_name",
        "nih_program_officers",
        "nih_agency_ic_fundings",
        "nih_cong_dist",
        "nih_spending_categories",
        "nih_project_start_date",
        "nih_project_end_date",
        "nih_all_text",
        "nih_foa",
        "nih_full_study_section",
        "nih_award_notice_date",
        "nih_is_new",
        "nih_mechanism_code_dc",
        "nih_core_project_num",
        "nih_terms",
        "nih_pref_terms",
        "nih_abstract_text",
        "nih_project_title",
        "nih_phr_text",
        "nih_spending_categories_desc",
        "nih_awd_doc_num",
        "nih_init_encumbrance_date",
        "nih_can_task",
        "nih_special_topic_code",
        "nih_agency_code",
        "nih_covid_response",
        "nih_last_update",
    ];

    private static $pid = "";
    private static $firstPidError = TRUE;

	private static $tokenTranslateToPid = [];
	private static $mentorTokenTranslateToPid = [];
}
