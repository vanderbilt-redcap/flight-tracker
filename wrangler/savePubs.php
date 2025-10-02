<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$data = Wrangler::uploadCitations($_POST, $token, $server, $pid);
echo json_encode($data);
