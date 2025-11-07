<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Links;

require_once(__DIR__."/classes/Autoload.php");

class CareerDevHelp {
	public static function getHelp($title, $menu) {
		$help = self::getHelpHash();
		if (isset($help[$menu]) && isset($help[$menu][$title])) {
			$texts = self::readFiles($help[$menu][$title]);
			$html = self::format($texts, TRUE);
			return $html;
		}
		return "";
	}

    public static function getVideoVaultLinkHTML() {
        $url = "https://redcap.vumc.org/plugins/career_dev/help/videos.php";
        $videosHTML = Links::makeLink($url, "Flight Tracker Video Vault", TRUE);
        return "<p class='smaller nomargin centered' style='font-family: europa, Helvetica, Arial, sans-serif;'>$videosHTML</p>";
    }

	public static function getHelpPage($page) {
		$help = self::getHelpHash();
		$regs = "";
		foreach ($help as $menu => $titles) {
			foreach ($titles as $title => $files) {
                if (in_array($page, $files)) {
                    if (in_array($title, ["Publication Wrangler", "Patent Wrangler"])) {
                        $wranglerType = $_GET['wranglerType'];
                        if (
                            (($wranglerType == "Patents") && ($title == "Patent Wrangler"))
                            || (($wranglerType == "Publications") && ($title == "Publication Wrangler"))
                        ) {
                            return self::readFile($page);
                        }
                    } else {
                        return self::readFile($page);
                    }
				}
			}
		}
		return "";
	}

	public static function getPageTitle($page) {
                $help = self::getHelpHash();
                foreach ($help as $menu => $titles) {
                        foreach ($titles as $title => $files) {
                                if (in_array($page, $files)) {
                                        return $title;
                                } 
                        }
                }
                return "";
	}

    private static function replaceCodeBlocks($line) {
        if (preg_match_all("/executeCode\((\w+),\s*(\w+)/", $line, $matches)) {
            for ($i = 0; $i < count($matches[0]); $i++) {
                $matchStr = $matches[0][$i];
                $className = $matches[1][$i];
                $method = $matches[2][$i];
                $returnHTML = "[$className::$method - Result not found]";
                $namespaces = array("", "Vanderbilt\\FlightTrackerExternalModule\\", "Vanderbilt\\CareerDevLibrary\\");
                foreach ($namespaces as $ns) {
                    if (method_exists($ns.$className, $method)) {
                        $returnHTML = call_user_func(array($ns.$className, $method));
                        break;
                    }
                }
                $line = str_replace($matchStr, $returnHTML, $line);
            }
        }
        return $line;
    }

    private static function replaceHelpLinks($line) {
		if (preg_match_all("/[\"']launchHelp\([\"']([\w\.]+)[\"']\);[\"']/", $line, $matches)) {
			for ($i = 0; $i < count($matches[0]); $i++) {
				$matchStr = $matches[0][$i];
				$filename = $matches[1][$i];
				$line = str_replace($matchStr, "'".CareerDev::getHelpLink()."&htmlPage=".urlencode($filename)."' target='_NEW'", $line);
			}
		}
		return $line;
	}

	private static function format($texts, $noImages = FALSE) {
		$strippedTexts = array();

		foreach ($texts as $text) {
			$lines = explode("\n", $text);
			$strippedLines = array();
			foreach ($lines as $line) {
				$line = self::replaceHelpLinks($line);
                $line = self::replaceCodeBlocks($line);
				if (!$noImages) {
					if (!preg_match("/<img /", $line)) {
						array_push($strippedLines, $line);
					} else if (preg_match("/<img[^>]+src=['\"]([^'^\"]+)['\"]/", $line, $matches)) {
						$matchedStr = $matches[0];
						$file = preg_replace("/^\//", "", $matches[1]);
						$newFile = CareerDev::link("/help/".$file);
						$newStr = str_replace($file, $newFile, $matchedStr);
						$newLine = str_replace($matchedStr, $newStr, $line);
						$newLine = str_replace("<img ", "<img style='width: 100%;'", $newLine);
						array_push($strippedLines, $newLine);
					}
				} else if (!preg_match("/<img /", $line)) {
					array_push($strippedLines, $line);
				}
			}
			array_push($strippedTexts, implode("\n", $strippedLines));
		}

		return implode("<hr>\n", $strippedTexts);
	}

	private static function readFiles($files) {
		$texts = array();
		foreach ($files as $file) {
			$text = self::readFile($file);
			if ($text) { array_push($texts, $text); }
		}
		return $texts;
	}

	private static function readFile($file) {
		$filepath = dirname(__FILE__)."/help/".REDCapManagement::makeSafeFilename($file);
		if (file_exists($filepath)) {
			$fp = fopen($filepath, "r");
			$currText = "";
			while ($text = fgets($fp)) {
				$currText .= $text;
			}
			fclose($fp);
			return $currText;
		}
		return "";
	}

	public static function getFAQ() {
		$texts = array();
		$filesRead = array();
		foreach (self::getHelpHash() as $menu => $titles) {
			foreach ($titles as $title => $files) {
				array_push($texts, "<h2>$title</h2>\n");
				foreach ($files as $file) {
					if (!in_array($file, $filesRead)) {
						array_push($texts, self::readFile($file));
						array_push($filesRead, $file);
					}
				}
			}
		}
		foreach (self::getHowHelpHash() as $title => $files) {
			foreach ($files as $file) {
				if (!in_array($file, $filesRead)) {
					array_push($texts, self::readFile($file));
					array_push($filesRead, $file);
				}
			}
		}
		foreach (self::getWhyHelpHash() as $title => $files) {
			foreach ($files as $file) {
				if (!in_array($file, $filesRead)) {
					array_push($texts, self::readFile($file));
					array_push($filesRead, $file);
				}
			}
		}
		foreach (self::getExtendHelpHash() as $title => $files) {
			foreach ($files as $file) {
				if (!in_array($file, $filesRead)) {
					array_push($texts, self::readFile($file));
					array_push($filesRead, $file);
				}
			}
		}

		return self::format($texts, FALSE);
	}

	public static function getHowToExtend() {
		$help = self::getExtendHelpHash();
		$texts = array();
		foreach ($help as $title => $files) {
			$texts = array_merge($texts, self::readFiles($files));
		}
		return self::format($texts, FALSE);
	}

	public static function getHowToUse() {
		$help = self::getHowHelpHash();
		$texts = array();
		foreach ($help as $title => $files) {
			$texts = array_merge($texts, self::readFiles($files));
		}
		return self::format($texts, FALSE);
	}

	public static function getWhyUse() {
		$help = self::getWhyHelpHash();
		$texts = array();
		foreach ($help as $title => $files) {
			$texts = array_merge($texts, self::readFiles($files));
		}
		return self::format($texts, FALSE);
	}

	# different format than getHelpHash -- $title => array of files
	private static function getWhyHelpHash() {
		$help = array(
				"Who?" => array("who.html"),
				"Why?" => array("why.html"),
				);
		return $help;
	}

	# different format than getHelpHash -- $title => array of files
	private static function getExtendHelpHash() {
		$help = array(
				"How Can I Extend This Application?" => array("addNewDataSources.html"),
				);
		return $help;
	}

	# different format than getHelpHash -- $title => array of files
	private static function getHowHelpHash() {
		$help = array(
				"Setting Up a Database" => array("changes.html"),
				"Adding New Scholars" => array("addScholars.html"),
				"Email Management" => array("emailMgmt.html"),
                "External Sites Accessed" => array("whitelist.html"),
				"Solving Situations" => array("situations.html"),
				"Manual Curation: Grant Wrangler" => array("grantWrangler.html"),
                "Manual Curation: Publication Wrangler" => array("pubWrangler.html"),
                "Manual Curation: Patent Wrangler" => array("patentWrangler.html"),
				"Importing Old Grant Data and Adding New Grants" => array("addNewGrants.html"),
				"Missingness Report" => array("missingness.html"),
				"Designing Cohorts" => array("cohortDesign.html"),
				"The Departure of Scholars" => array("departure.html"),
				"Timelines" => array("timelines.html"),
				);
		return $help;
	}

	private static function getHelpHash() {
        $help = [];
        $help[""] = [
            "Front Page" => ["useCaseSearches.html", "changes.html"],
        ];
        $help["General"] = [
            "K2R Conversion Calculator" => ["useCaseConversion.html"],
            "Kaplan-Meier Conversion Curve" => ["kaplanMeierCurves.html"],
            "Configure Application" => ["copyProject.html"],
            "Copy Project to Another Server" => ["copyProject.html"],
            "NIH Reporting" => ["nihReporting.html"],
        ];
        $help["View"] = [
            "Demographics Table" => ["useCaseDemographics.html", "useCaseStats.html"],
            "Missingness Report" => ["missingness.html"],
        ];
        $help["Grants"] = [
            "Stylized CDA Table" => ["useCaseBins.html"],
            "Social Network of Grant Collaboration" => ["socialNetworks.html"],
            "Compare Data Sources" => ["useCaseBins.html"],
        ];
        $help["Pubs"] = [
            "Social Network of Co-Authorship" => ["socialNetworks.html"],
        ];
        $help["Wrangle"] = [
            "Grant Wrangler" => ["grantWrangler.html", "bins.html"],
            "Publication Wrangler" => ["pubWrangler.html"],
            "Patent Wrangler" => ["patentWrangler.html"],
            "Add a Custom Grant" => ["addNewGrants.html"],
            "Add Custom Grants by Bulk" => ["addNewGrants.html", "nihReporting.html"],
        ];
        $help["Scholars"] = [
            "Scholar Profiles" => ["useCaseProfiles.html", "useCasePubs.html"],
            "Add a New Scholar" => ["addScholars.html"],
            "Configure an Email" => ["emailMgmt.html"],
        ];
        $help["Dashboards"] = [
            "Resources" => ["useCaseResources.html"],
        ];
        $help["Mentors"] = [
            "Mentor Performance" => ["useCaseMentors.html"],
        ];
        $help["Cohorts / Filters"] = [
            "Add a New Cohort" => ["cohortDesign.html", "useCaseStats.html", "useCaseGrantsAndPubs.html", "bins.html"],
            "View Cohort Metrics" => ["useCaseCohortMetrics.html"],
        ];
        $help["Resources"] = [
            "Manage" => ["useCaseResources.html"],
            "Participation Roster" => ["useCaseResources.html"],
            "Dashboard Metrics" => ["useCaseResources.html"],
            "Measure ROI" => ["roi.html"],
        ];
        return $help;
	}
}
