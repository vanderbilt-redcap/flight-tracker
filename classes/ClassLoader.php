<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . "/../../../redcap_connect.php");

spl_autoload_register(function ($class_name) {
    $parts = explode('\\', $class_name);
    $classNameWithoutNamespace = array_pop($parts);
    $path =  "$classNameWithoutNamespace.php";

    if(in_array($class_name, ['Application'])){
        $path = "../$path";
    }

    require_once(__DIR__ . "/$path");
});