<?php

use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$descriptions = DataDictionaryManagement::getInstrumentDescriptions();

echo "<script>\n";
echo "$(document).ready(() => {\n";
echo "$('.dataTable').before('<p style=\"font-weight: bold; font-size: 1.15em;\">Hold your mouse over the column header with an instrument to see a description.</p>');\n";
foreach ($descriptions as $instrument => $description) {
    $description = str_replace("&amp;", "&", $description);
    $description = str_replace("&rarr;", "->", $description);
    echo "$('th div span[data-mlm-name=$instrument]').parent().parent().attr('title', \"$description\");\n";
}
echo "});\n";
echo "</script>";