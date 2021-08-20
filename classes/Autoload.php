<?php

namespace Vanderbilt\CareerDevLibrary;

$files = scandir(__DIR__);
$skip = [".", ".."];
foreach ($files as $file) {
    if (!in_array($file, $skip)) {
        $fullFilename = __DIR__."/".$file;
        if (hasClassAndNamespaceDefined($fullFilename)) {
            require_once($fullFilename);
        }
    }
}
$includeLocs = ["/../Application.php", "/../CareerDev.php", "/../../../redcap_connect.php"];
foreach ($includeLocs as $loc) {
    $loc = __DIR__.$loc;
    if (file_exists($loc)) {
        require_once($loc);
    }
}

function hasClassAndNamespaceDefined($filename) {
    $fp = fopen($filename, "r");
    $hasClass = FALSE;
    $hasNamespace = FALSE;
    while (!($hasClass && $hasNamespace) && ($line = fgets($fp))) {
        if (preg_match( "/\bclass\b\s+\w+/", $line)) {
            $hasClass = TRUE;
        }
        if (preg_match("/namespace Vanderbilt.CareerDevLibrary;/", $line)) {
            $hasNamespace = TRUE;
        }

    }
    fclose($fp);
    return $hasClass && $hasNamespace;
}
