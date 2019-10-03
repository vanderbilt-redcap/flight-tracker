<?php

namespace Vanderbilt\CareerDevLibrary;


require_once(dirname(__FILE__)."/Links.php");
class Definitions {

	public function __construct($subtext = "") {
		$this->definitions = array();
		$this->addDefaultDefinitions();

		$this->subtext = $subtext;
	}

	public function formatTerm($term) {
		$lowerTerm = strtolower($term);
		foreach ($this->definitions as $currTerm => $currDef) {
			if ($lowerTerm == strtolower($currTerm)) {
				return $currTerm;
			}
		}
		return $term;
	}

	public function getDefinitionForTerm($term) {
		$lowerTerm = strtolower($term);
		foreach ($this->definitions as $currTerm => $currDef) {
			if ($lowerTerm == strtolower($currTerm)) {
				return $currDef;
			}
		}
		return "";
	}

	public function getDefinitionsAsArray() {
		return $this->definitions;
	}

	public function getTermsAsArray() {
		return array_keys($this->definitions);
	}

	private static function getCoeusURL() {
		return "https://coeus.mc.vanderbilt.edu/coeus/CoeusWebStart.jsp";
	}

	public static function getCoeusText() {
		return "This value is reported by ".Links::makeLink(self::getCoeusURL(), "COEUS").". ";
	}

	public static function getBudgetGuidanceText() {
		return "(See ".Links::makeLink("https://ww2.mc.vanderbilt.edu/osp/51050", "OSP's further information").".) ";
	}

	public static function getNIHDocsText() {
		return  "(See ".Links::makeLink("https://era.nih.gov/sites/default/files/Deciphering_NIH_Application.pdf", "NIH documentation")." for more information.)";
	}

	private function addDefaultDefinitions() {
		$coeusReported = self::getCoeusText();
		$budgetGuidance = self::getBudgetGuidanceText();
		$nihDocs = self::getNIHDocsText();

		$this->addDefinition("Grant Number", "The identifier of a single application that has been awarded. A Grant Number consists of the following parts: Application Type (1), Activity Code (R01), Institute Code (CA), Serial Number (654321), Support Year (01), and Other Suffixes (A1). The prior example parts would form the Grant Number 1R01CA654321-01A1. ".$nihDocs);
		$this->addDefinition("Grant", "A single application for federal, foundation, and other nonprofit extramural funding  that has been awarded. Also may encompass internal VUMC funding in the form of career development or bridge grants awarded via a competitive process.");
		$this->addDefinition("Project Number", "(Also known as Base Award Number.) The Activity Code, Institute Code, and Serial Number from a Grant Number - combined into one number. For example, 1R01CA654321-01A1 and 5R01CA654321-02 have the identifiers R01 + CA + 654321 that give us the project number R01CA654321. ".$nihDocs);
		$this->addDefinition("Grant Provider", "The organization or institution which provides the grant and grant funding.");
		$this->addDefinition("Contract", "An agreement with a partner in industry for work to be done in exchange for a specific sum of money.");
		$this->addDefinition("Subcontract", "A Grant given by one Grant Provider primarily and principally to another entity (that is not VUMC); the other entity enters into an agreement with VUMC to perform work related to the research the Grant is funding. The amount to VUMC is said to be a Grant Provider's Subcontract. (For example, NIH awards a Grant to Harvard, and the Harvard PI of this grant writes a subcontract to a PI at VUMC to perform certain work for the project using funds from the NIH Grant; it would be categorized as a NIH Subcontract.)");
		$this->addDefinition("Direct Budget", "Costs that can be readily and specifically identified with a particular sponsored project relatively easily and with a high degree of accuracy. ".$coeusReported.$budgetGuidance);
		$this->addDefinition("Indirect Budget", "Costs that are assigned to the institution receiving the grant for common objectives (such as electricity or building maintenance). These costs are a percentage of the Total Budget for a Grant. ".$coeusReported.$budgetGuidance);
		$this->addDefinition("Total Budget", "The amount of money awarded from the Grant Provider to be used on the project. This value is the sum of the Direct Budget + the Indirect Budget. ".$coeusReported.$budgetGuidance);
		$this->addDefinition("Budget Start/End Dates", "The span of time that the Budgets fund in one Grant. ".$coeusReported);
		$this->addDefinition("Project Start/End Dates", "The (projected) span of time for the entire Project. ".$coeusReported);
		$this->addDefinition("Timespan", "The period of time (start-time to end-time) that you request for the report. This value is identified in the header above the main table and the key.");
		$this->addDefinition("Timespans and Budgets", "The calculations below assume that a Budget is distributed equally and linearly by the fraction of time covered by the budget in the requested Timespan. This amount is, in turn, summed for the requested Timespan for the given Grant Provider. [Amount applied] = [Budget dollar amount] * [the number of days that the Timespan overlaps/intersects the Grant Budget] / [the number of days in a Budget].");
		$this->addDefinition("COEUS", "The sole data source of this report, available ".Links::makeLink(self::getCoeusURL(), "here").". COEUS checks the validity and integrity of the data.");
		$this->addDefinition("Prime Sponsor", "The organization from which the funds originate.");
		$this->addDefinition("Direct Sponsor", "The organization from which we receive the funds directly.");
	}

	public function addDefinition($term, $definition) {
		if (isset($this->definitions[$term])) {
			throw new \Exception("$term is already defined!");
		}
		$this->definitions[$term] = $definition;
	}

	public function getHTML() {
		$html = "";

		foreach ($this->definitions as $term => $def) {
			$html .= "<p><b>$term</b> - $def</p>\n";
		}
		$html .= "<p style='text-align: center;'>".$this->getCodeBookLink()."</p>\n";

		return $html;
	}

	public function getCodeBookLink() {
		return "Download the Project's CodeBook ".Links::makeLink("https://redcap.vanderbilt.edu/plugins/career_dev/docs/Codebook.docx", "here").".";
	}

	public function getSubText() {
		return $this->subtext;
	}

	private $definitions;
	private $subtext;
}
