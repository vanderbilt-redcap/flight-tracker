<?php

use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (!$_GET['table']) {
    exit;
}

$table = Sanitizer::sanitize($_GET['table']);
$includeDOI = Sanitizer::sanitize($_GET['includeDOI'] ?? FALSE);
if (NIHTables::getTableHeader($table)) {
    $metadata = Download::metadata($token, $server);
    $nihTables = new NIHTables($token, $server, $pid, $metadata);
    $html = $nihTables->getHTML($table, FALSE, $includeDOI);

    Application::writeHTMLToDoc($html, "Table.docx");
}
