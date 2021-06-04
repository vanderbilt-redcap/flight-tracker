<?php

$files = scandir(__DIR__);
$skip = [".", ".."];
foreach ($files as $file) {
    if (!in_array($file, $skip)) {
        $fullFilename = __DIR__."/".$file;
        if (hasClassAndNamespaceDefined($fullFilename)) {
            require_once($fullFilename);
            if (isset($_GET['test'])) {
                echo "Requiring $fullFilename<br>";
            }
        } else {
            if (isset($_GET['test'])) {
                echo "Skipping $fullFilename<br>";
            }
        }
    }
}
$applicationClass = __DIR__."/../Application.php";
if (file_exists($applicationClass) && hasClassAndNamespaceDefined($applicationClass)) {
    require_once($applicationClass);
}

$careerDev = __DIR__."/../CareerDev.php";
if (file_exists($careerDev)) {
    require_once($careerDev);
}

$redcapConnect = __DIR__."/../../../redcap_connect.php";
if (file_exists($redcapConnect)) {
    require_once($redcapConnect);
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
