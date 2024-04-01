<?php

use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$descriptions = DataDictionaryManagement::getInstrumentDescriptions();

echo "<script>\n";
echo "$(document).ready(() => {\n";
echo "$('#event_grid_table').before(\"<p style='font-weight: bold; font-size: 1.15em;'>Click an instrument's name to see a description.</p>\");\n";
foreach ($descriptions as $instrument => $description) {
    echo "$('[data-mlm-name=$instrument]').after(\"<div id='$instrument"."_descr' style='max-width: 250px; font-size: 0.9em; display: none; padding-left: 8px;'>$description</div>\");\n";
    echo "$('[data-mlm-name=$instrument]').attr('title', 'Click to view description');\n";
    echo "$('[data-mlm-name=$instrument]').css({cursor: 'pointer'});\n";
    echo "$('[data-mlm-name=$instrument]').on('click', () => { $('[data-mlm-name=$instrument]').next().slideDown(); });\n";
    echo "$('[data-mlm-name=$instrument]').on('mouseout', () => { $('[data-mlm-name=$instrument]').next().slideUp(); });\n";
}
echo "});\n";
echo "</script>";