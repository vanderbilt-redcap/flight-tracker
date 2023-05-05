<?php

use \Vanderbilt\CareerDevLibrary\Publications;

require_once(__DIR__."/../classes/Autoload.php");

try {
    $pmids = [34527889];
    $output = Publications::pullFromEFetch($pmids);
    echo "<h1>Successful!</h1><code>".htmlspecialchars($output)."</code>";
} catch (\Exception $e) {
    echo "<h1>Error!</h1>".$e->getMessage();
}
