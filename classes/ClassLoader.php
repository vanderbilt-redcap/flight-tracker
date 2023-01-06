<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . "/../../../redcap_connect.php");

spl_autoload_register(function (string $class_name) {
    $parts = explode('\\', $class_name);
    $classNameWithoutNamespace = array_pop($parts);
    $path =  "$classNameWithoutNamespace.php";

    if(in_array($classNameWithoutNamespace, ['Application', 'CareerDev'])){
        $path = "../$path";
    }

    $filename = __DIR__."/$path";
    if (file_exists($filename)) {
        require_once($filename);
    }
});