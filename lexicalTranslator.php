<?php

use \Vanderbilt\CareerDevLibrary\GrantLexicalTranslator;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/GrantLexicalTranslator.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/CareerDev.php");

echo "<h1>Lexical Translation for Grants</h1>\n";
echo "<h2>Manage Grants Specific to ".INSTITUTION."</h2>\n";

echo "<p class='centered'>Items at the top take priority. <a href='".CareerDev::link("lexicalOrder.php")."'>Reorder here</a>.</p>\n";

$translator = new GrantLexicalTranslator($token, $server, CareerDev::getModule());

if (count($_POST) > 0) {
	$translator->parsePOST($_POST);
	echo "<p class='centered'>Changes made.</p>\n";
}
echo $translator->getEditHTML();
