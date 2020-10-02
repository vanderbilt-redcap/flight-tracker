<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/NIHTables.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Cohorts.php");


function makeLink($tableNum) {
	if ($text = NIHTables::getTableHeader($tableNum)) {
		$baseLink = Application::link("reporting/table.php");
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

$cohorts = new Cohorts($token, $server, $metadata);

?>

<h1>NIH Tables</h1>

<?php

if (($pid == 66635) && preg_match("/redcap.vanderbilt.edu/", $server)) {
    $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    if (isset($_GET['appointments'])) {
        $url = preg_replace("/\&appointments/", "", $currentUrl);
        echo "<p class='centered'><a href='$url'>View All Post-Docs</a></p>\n";
    } else {
        $url = $currentUrl . "&appointments";
        echo "<p class='centered'><a href='$url'>View Post-Docs by Appointment-Only</a></p>\n";
    }
}

?>

<p class="centered"><?= $cohorts->makeCohortSelect($_GET['cohort'], "if ($(this).val()) { window.location.href = \"".Application::link("reporting/index.php")."&cohort=\" + $(this).val(); } else { window.location.href = \"".Application::link("reporting/index.php")."\"; }") ?></p>

<h2>Predoctoral Scholars (<?= count($predocs) ?>)</h2>
<p class='centered'><?= $predocNames ? $predocNames : $emptyNames ?></p>

<h2>Postdoctoral Scholars (<?= count($postdocs) ?>)</h2>
<p class='centered'><?= $postdocNames ? $postdocNames : $emptyNames ?></p>

<h2><?= makeTableHeader("Common Metrics") ?></h2>
<p class='centered'><?= makeLink("Common Metrics") ?></p>

<h2><?= makeTableHeader("5") ?></h2>
<p class='centered'><?= makeLink("5A") ?></p>
<?php
if (isset($_GET['appointments'])) {
    echo "<p class='centered'>".makeLink("5B-VUMC")."</p>\n";
} else {
    echo "<p class='centered'>".makeLink("5B")."</p>\n";
}
?>

<h2><?= makeTableHeader("6") ?></h2>
<p class='centered'><?= makeLink("6AII") ?></p>
<?php
if (isset($_GET['appointments'])) {
    echo "<p class='centered'>".makeLink("6BII-VUMC")."</p>\n";
} else {
    echo "<p class='centered'>".makeLink("6BII")."</p>\n";
}
?>

<h2><?= makeTableHeader("8") ?></h2>
<p class='centered'><?= makeLink("8AI") ?></p>
<p class='centered'><?= makeLink("8AIII") ?></p>
<p class='centered'><?= makeLink("8AIV") ?></p>
<?php
if (isset($_GET['appointments'])) {
    echo "<p class='centered'>".makeLink("8CI-VUMC")."</p>\n";
    echo "<p class='centered'>".makeLink("8CIII-VUMC")."</p>\n";
} else {
    echo "<p class='centered'>".makeLink("8CI")."</p>\n";
    echo "<p class='centered'>".makeLink("8CIII")."</p>\n";
}

$bookmarkletJSURL = Application::link("js/xtract.js");
$cohortParam = "";
if ($_GET['cohort']) {
    $cohortParam = "&cohort=".$_GET['cohort'];
}

?>

<h2>Use with xTRACT (forthcoming)</h2>
<p class="centered">Use the following link to install with <a href="https://mreidsma.github.io/bookmarklets/installing.html">these instructions</a>. This script needs to be run (<i>i.e.</i>, the bookmark needs to be clicked) on every xTRACT page in NIH eCommons.</p>
<p class='centered'><a href="javascript:(function(){var%20script=document.createElement('script');script.type='text/javascript';script.src='<?= $bookmarkletJSURL ?>&'+Math.random();document.getElementsByTagName('head')[0].appendChild(script);getDataFromREDCap('<?= Application::link("/reporting/getData.php").$cohortParam ?>'})();">Bookmarklet</a></p>
