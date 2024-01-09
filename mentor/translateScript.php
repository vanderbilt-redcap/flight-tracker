<?php

$usage = "Usage: php translateScript.php <new-namespace-name> <restore/adapt>\n  restore = to original namespace for github sync\n  adapt = to new namespace for project use\n";
if (count($argv) < 3) {
    die ($usage);
}

if ($argv[2] == "restore") {
    $newNS = "CareerDevLibrary";
    $originalNS = $argv[1];
} else if ($argv[2] == "adapt") {
    $originalNS = "CareerDevLibrary";
    $newNS = $argv[1];
} else {
    die($usage);
}

$dir = dirname(__FILE__);
$files = scandir($dir);
$skip = [".", "..", "translateScript.php"];
$origStrs = ["\\$originalNS", ".$originalNS"];
$newStrs = ["\\$newNS", ".$newNS"];
$trailers = ["\\", ";"];
foreach ($files as $file) {
    if (!in_array($file, $skip)) {
        replaceNamespacesInFile($dir."/".$file, $origStrs, $newStrs, $trailers);
    }
}

$applicationFilename = $dir."/../Application.php";
if (file_exists($applicationFilename)) {
    replaceNamespacesInFile($applicationFilename, $origStrs, $newStrs, $trailers);
}

function replaceNamespacesInFile($filename, $origStrs, $newStrs, $trailers) {
    if (is_dir($filename)) {
        return;
    }
    $fp = fopen($filename, "r");
    $newLines = [];
    while ($line = fgets($fp)) {
        $newLine = $line;
        foreach ($trailers as $trailer) {
            for ($i = 0; $i < count($origStrs); $i++) {
                $newLine = str_replace($origStrs[$i].$trailer, $newStrs[$i].$trailer, $newLine);
            }
        }
        $newLines[] = $newLine;
    }
    fclose($fp);

    $fp = fopen($filename, "w");
    fwrite($fp, implode("", $newLines));
    fclose($fp);
}
