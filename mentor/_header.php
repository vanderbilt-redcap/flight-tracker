<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../classes/Autoload.php";

$uidString = "";
if (isset($_GET['uid'])) {
	$uidString = "&uid=".REDCapManagement::sanitize($_GET['uid']);
}


if (Application::getProgramName() == "Flight Tracker Mentee-Mentor Agreements") {
	$restartMssg = "Restart";
} else {
	$restartMssg = "Front Page";
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="">
  <meta name="author" content="">

  <title><?= Application::getProgramName()." - Mentee-Mentor Agreement" ?></title>
    <script src="<?= Application::link("mentor/js/jquery.min.js") ?>"></script>

  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css" integrity="sha384-Vkoo8x4CGsO3+Hhxv8T/Q5PaXtkKtu6ug5TOeNV6gBiFeWPGFN9MuhOf23Q9Ifjh" crossorigin="anonymous">
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>

  <!-- Custom fonts for this template -->
  <link href="<?= Application::link("mentor/css/all.min.css") ?>" rel="stylesheet">
  <link href="<?= Application::link("mentor/css/simple-line-icons.css") ?>" rel="stylesheet" type="text/css">
  <link href="https://fonts.googleapis.com/css?family=Lato:300,400,700,300italic,400italic,700italic" rel="stylesheet" type="text/css">
<link rel="stylesheet" href="<?= Application::link("/css/typekit.css").Application::getVersion() ?>">

  <!-- Custom styles for this template -->
  <link href="<?= Application::link("mentor/css/landing-page.css") ?>" rel="stylesheet">
    <link rel='icon' type='image/png' href='<?= Application::link("/img/flight_tracker_icon.png") ?>' />

    <script>
        function getCSRFToken() {
            return "<?= Application::generateCSRFToken() ?>";
        }
    </script>

</head>

<body>

  <!-- Navigation -->
  <nav class="navbar navbar-light bg-light static-top">
    <div class="container">
      <a class="navbar-brand" href="<?= Application::link("mentor/intro.php").$uidString ?>" title="<?= $restartMssg ?>"><img alt="<?= Application::getProgramName() ?>" src="<?= Application::link("mentor/img/logo.png") ?>" style="max-width: 175px;"></a>
        <a class="navbar-btn" href="<?= Application::link("mentor/intro.php").$uidString ?>"><?= $restartMssg ?></a>
    </div>
  </nav>
