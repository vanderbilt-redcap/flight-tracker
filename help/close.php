<?php

require_once(dirname(__FILE__)."/../small_base.php");

\Session::init("flight_tracker");
$_SESSION['showHelp'] = FALSE;
