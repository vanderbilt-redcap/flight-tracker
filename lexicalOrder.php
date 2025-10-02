<?php

use Vanderbilt\CareerDevLibrary\GrantLexicalTranslator;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

echo "<h1>Lexical Translation Order for Grants</h1>\n";
echo "<h2>Manage Grants Specific to ".INSTITUTION."</h2>\n";

echo "<p class='centered'>Items at the top take priority. <a href='".CareerDev::link("lexicalTranslator.php")."'>Translate here</a>.</p>\n";

$metadata = Download::metadata($token, $server);
$translator = new GrantLexicalTranslator($token, $server, CareerDev::getModule());

echo $translator->getOrderHTML();
