<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . "/../../../redcap_connect.php");

spl_autoload_register(function ($class_name) {
    $parts = explode('\\', $class_name);
    $classNameWithoutNamespace = array_pop($parts);
    $path =  "$classNameWithoutNamespace.php";

    if(in_array($class_name, ['Application', 'CareerDev'])){
        $path = "../$path";
    }

    $filename = __DIR__."/$path";
    if (file_exists($filename)) {
        require_once($filename);
    }
});