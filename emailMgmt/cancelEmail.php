<?php

use \Vanderbilt\CareerDevLibrary\EmailManager;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$name = Sanitizer::sanitizeWithoutChangingQuotes($_GET['name']);
$module = Application::getModule();
$mgr = new EmailManager($token, $server, $pid, $module);
try {
    $mgr->disable($name);
    echo Sanitizer::sanitizeOutput($name)." successfully turned off.";
} catch (\Exception $e) {
    echo "Could not turn off the email ".Sanitizer::sanitizeOutput($name)."<br/>".$e->getMessage();
}
