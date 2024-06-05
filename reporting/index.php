<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\URLManagement;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

Application::increaseProcessingMax(1);

function makeMonthLink($tableNum) {
    $tableLink = Application::link("reporting/table.php");
    if (isset($_GET['cohort']) && $_GET['cohort']) {
        $tableLink .= "&cohort=".urlencode(REDCapManagement::sanitizeCohort($_GET['cohort']));
    }
    $basePage = URLManagement::getPage($tableLink);
    $hiddenHTML = URLManagement::getParametersAsHiddenInputs($tableLink);
    $title = NIHTables::getTableHeader($tableNum);
    return "<form action='$basePage' method='GET'>
    $hiddenHTML
    <input type='hidden' name='table' id='table' value='$tableNum' />
    <p class='centered'>
    <strong>Table $tableNum - $title</strong><br/>
    Show Publications in Training Plus <input type='number' name='months' id='months' value='18' /><label for='months'> Months</label> for Publication Delays<br/>
    <button>Get Table</button>
    </p>
</form>";
}

function makeLink($tableNum) {
    if ($tableNum == "Common Metrics") {
        $link = Application::link("reporting/commonMetrics.php");
        return "<a href='$link'>Common Metrics Table</a>";
    }
	if ($text = NIHTables::getTableHeader($tableNum)) {
		$baseLink = Application::link("reporting/table.php");
		if (isset($_GET['cohort']) && $_GET['cohort']) {
		    $baseLink .= "&cohort=".urlencode(REDCapManagement::sanitizeCohort($_GET['cohort']));
        }
		if (formatTableNum($tableNum) == $text) {
			$htmlText = $text;
		} else {
			$htmlText = formatTableNum($tableNum)." - $text";
		}
		$html = "<a href='$baseLink"."&table=$tableNum'>$htmlText</a>";
		return $html;
	}
	return "";
}

function formatTableNum($tableNum) {
	return NIHTables::formatTableNum($tableNum);
}

function makeTableHeader($tableNum) {
	if ($text = NIHTables::getTableHeader($tableNum)) {
		if ($tableNum != $text) {
			return "Table $tableNum: $text";
		} else {
			return $tableNum." Table";
		}
	}
	return "";
}

$cohort = isset($_GET['cohort']) ? REDCapManagement::sanitizeCohort($_GET['cohort']) :  "";
$metadata = Download::metadata($token, $server);
$tables = new NIHTables($token, $server, $pid, $metadata);
$predocs = $tables->downloadPredocNames();
if (isset($_GET['appointments'])) {
    $postdocs = $tables->downloadPostdocNames("8C-VUMC");
} else {
    $postdocs = $tables->downloadPostdocNames();
}

$predocNames = implode(", ", array_values($predocs));
$postdocNames = implode(", ", array_values($postdocs));
$emptyNames = "None";

$cohorts = new Cohorts($token, $server, Application::getModule());

?>

<h1>NIH Tables</h1>

<?php

if (($pid == 66635) && (preg_match("/redcap\.vanderbilt\.edu/", $server) || preg_match("/redcap\.vumc\.org/", $server))) {
    $currentUrl = Application::link("this");
    if (isset($_GET['appointments'])) {
        $url = preg_replace("/\&appointments/", "", $currentUrl);
        echo "<p class='centered'><a href='$url'>View All Post-Docs</a></p>\n";
    } else {
        $url = $currentUrl . "&appointments";
        echo "<p class='centered'><a href='$url'>View Post-Docs by Appointment-Only</a></p>\n";
    }
}

$note = "";
if (file_exists(dirname(__FILE__)."/../customGrants.php")) {
    $note = "(You can <a href='".Application::link("customGrants.php")."'>setup these in bulk</a>, too.)";
}

?>

<p class="centered"><?= $cohorts->makeCohortSelect($cohort, "if ($(this).val()) { window.location.href = \"".Application::link("reporting/index.php")."&cohort=\" + $(this).val(); } else { window.location.href = \"".Application::link("reporting/index.php")."\"; }") ?></p>

<h2>Set Up Flight Tracker</h2>

<h3>Sign Up New Scholars</h3>
<p class="centered max-width red">To sign up scholars to these lists, fill out a Custom Grant for each scholar. <?= $note ?> Under role, sign them up to your grant as a General Trainee, Pre-Doctoral Trainee, or Post-Doctoral Trainee. Then verify that the scholar is a part of the lists below.</p>
<h4><a href="<?= Application::link('reporting/signup.php') ?>">Quick Sign Up</a></h4>

<table class="centered max-width bordered">
    <tbody>
    <tr>
        <td style="vertical-align: top;">
            <h3>Predoctoral Scholars (<?= count($predocs) ?>)</h3>
            <p class='centered max-width'><?= $predocNames ?: $emptyNames ?></p>
        </td>
        <td style="vertical-align: top;">
            <h3>Postdoctoral Scholars (<?= count($postdocs) ?>)</h3>
            <p class='centered max-width'><?= $postdocNames ?: $emptyNames ?></p>
        </td>
    </tr>
    </tbody>
</table>

<h2><?= makeTableHeader("5") ?></h2>
<?php

echo makeMonthLink("5A");
if (isset($_GET['appointments'])) {
    echo makeMonthLink("5B-VUMC");
} else {
    echo makeMonthLink("5B");
}

if (Application::isVanderbilt() && Application::isPluginProject($pid) && !Application::isLocalhost()) {
    echo "<h2>".makeTableHeader("6")."</h2>";
    echo "<p class='centered max-width'>".makeLink("6AII")."</p>";
    if (isset($_GET['appointments'])) {
        echo "<p class='centered max-width'>".makeLink("6BII-VUMC")."</p>";
    } else {
        echo "<p class='centered max-width'>".makeLink("6BII")."</p>";
    }
}

$part1Message = "Only scholars whose appointments overlap with the last ".NIHTables::PART_1_YEARS." years are included in this table.";
$part3Message = "Only scholars whose appointments overlap with the last ".NIHTables::PART_3_YEARS." years are included in this table.";

?>
<h2><?= makeTableHeader("8") ?></h2>
<p class='centered max-width'><?= makeLink("8AI")."<br/>$part1Message" ?></p>
<p class='centered max-width'><?= makeLink("8AIII")."<br/>$part3Message" ?></p>
<p class='centered max-width'><?= makeLink("8AIV") ?></p>
<?php
if (isset($_GET['appointments'])) {
    echo "<p class='centered max-width'>".makeLink("8CI-VUMC")."<br/>$part1Message</p>";
    echo "<p class='centered max-width'>".makeLink("8CIII-VUMC")."<br/>$part3Message</p>";
} else {
    echo "<p class='centered max-width'>".makeLink("8CI")."<br/>$part1Message</p>";
    echo "<p class='centered max-width'>".makeLink("8CIII")."<br/>$part3Message</p>";
}

?>

<h2><?= makeTableHeader("CTSA Common Metrics") ?></h2>
<p class='centered'><a href="<?= Application::link("reporting/commonMetrics.php") ?>">CTSA Common Metrics Table</a></p>

<?php

$bookmarkletJSURL = Application::link("js/xtract.js");
$cohortParam = "";
if ($cohort) {
    $cohortParam = "&cohort=".$cohort;
}
$appointmentParam = "";
if (isset($_GET['appointments'])) {
    $appointmentParam = "&appointments";
}

?>

<h2>Use with xTRACT for Reporting to NIH</h2>
<p class="centered max-width"><a href="https://public.era.nih.gov/commons/"><img src="<?= Application::link("img/era.png") ?>" alt="eRA Commons"> eRA Commons</a></p>
<p class="centered max-width">The eRA Commons recently updated its interface. This change made our bookmarklet obsolete. We plan to investigate revisions in the near future. Please contact <a href="mailto:scott.j.pearson@vumc.org">scott.j.pearson@vumc.org</a> if you'd like an update.</p>
<!--
<h4>Step-By-Step Instructions</h4>
<ol class="max-width centered">
    <li class="left-align">Right-click on the below bookmarklet link.</li>
    <li class="left-align">Click copy link from the menu that appears.</li>
    <li class="left-align">Go to your browser's bookmarks bar and right-click the bar.</li>
    <li class="left-align">Paste the link onto the bookmarks bar. It should begin with the words <code>javascript:</code>.</li>
    <li class="left-align">Go to eRA Commons via the above link.</li>
    <li class="left-align">Navigate to xTRACT and select your grant.</li>
    <li class="left-align">Proceed as if you are preparing a Research Training Dataset (RTD).</li>
    <li class="left-align">Click on the <i>Participating Trainees</i> link and select a trainee to edit.</li>
    <li class="left-align">On the page entitled <i>Participating Trainee Detail</i> (at <code>editParticipatingPersonHome.era</code>), click on the bookmarklet link in the bookmarks bar.</li>
    <li class="left-align">The script will attempt to match the trainee's name to your Flight Tracker database. You can adjust the record if the match is incorrect.</li>
    <li class="left-align">Open the dialog boxes to fill out the data. Flight Tracker will provide an option to 'Auto-Fill' if data exist. Please check over the information for accuracy.</li>
    <li class="left-align">If the page refreshed, you will need to run the bookmarklet anew on the page. There will be a Flight Tracker logo if the bookmarklet has already been run.</li>
</ol>
<p class='centered'><a href="javascript:(function(){var%20script=document.createElement('script');script.type='text/javascript';script.src='<?= $bookmarkletJSURL ?>&'+Math.random();document.getElementsByTagName('head')[0].appendChild(script);setTimeout(function(){var%20x=new%20xTRACT('<?= Application::link("/reporting/getData.php").$cohortParam.$appointmentParam."&NOAUTH" ?>', '<?= $token ?>', '<?= Application::generateCSRFToken() ?>');x.getDataFromREDCap();},1000);})();">Unique Bookmarklet for <?= Application::getProjectTitle() ?></a></p>
-->