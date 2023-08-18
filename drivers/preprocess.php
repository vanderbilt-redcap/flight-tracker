<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

function preprocessSharing($pids, $destPids = NULL) {
    if (!isset($destPids)) {
        $destPids = $pids;
    }
    if (empty($pids) || empty($destPids)) {
        return;
    }
    $module = Application::getModule();
    if ($module) {
        $module->shareDataInternally($pids, $destPids);
    }
}

function preprocessPortal($pids) {
    $module = Application::getModule();
    if ($module) {
        $module->preprocessScholarPortal($pids);
    }
}