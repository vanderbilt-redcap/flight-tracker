<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/NIHTables.php");
require_once(dirname(__FILE__)."/../classes/Download.php");


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

$predocs = Download::predocNames($token, $server);
$postdocs = Download::postdocNames($token, $server);

$predocNames = implode(", ", array_values($predocs));
$postdocNames = implode(", ", array_values($postdocs));
$emptyNames = "None";

?>

<h1>NIH Tables</h1>

<h2>Predoctoral Scholars (<?= count($predocs) ?>)</h2>
<p class='centered'><?= $predocNames ? $predocNames : $emptyNames ?></p>

<h2>Postdoctoral Scholars (<?= count($postdocs) ?>)</h2>
<p class='centered'><?= $postdocNames ? $postdocNames : $emptyNames ?></p>

<h2><?= makeTableHeader("Common Metrics") ?></h2>
<p class='centered'><?= makeLink("Common Metrics") ?></p>

<h2><?= makeTableHeader("5") ?></h2>
<p class='centered'><?= makeLink("5A") ?></p>
<p class='centered'><?= makeLink("5B") ?></p>

<h2><?= makeTableHeader("6") ?></h2>
<p class='centered'><?= makeLink("6AII") ?></p>
<p class='centered'><?= makeLink("6BII") ?></p>

<h2><?= makeTableHeader("8") ?></h2>
<p class='centered'><?= makeLink("8AI") ?></p>
<p class='centered'><?= makeLink("8AIII") ?></p>
<p class='centered'><?= makeLink("8AIV") ?></p>
<p class='centered'><?= makeLink("8CI") ?></p>
<p class='centered'><?= makeLink("8CIII") ?></p>
