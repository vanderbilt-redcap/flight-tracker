<?php

use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../../../classes/Autoload.php");

$relativeDir = "reporting/react-2/run/";

$icon64 = Application::link($relativeDir."flight_tracker_icon_64.png");
$icon192 = Application::link($relativeDir."flight_tracker_icon_192.png");
$indexLink = Application::link($relativeDir."index.php");

$manifest = [
  "short_name" => "Table Tracker",
  "name" => "Flight Tracker Table Tracker (1-4)",
  "icons" => [
      [
          "src" => $icon64,
          "sizes" => "64x64 32x32 24x24 16x16",
          "type" => "image/png"
      ],
      [
          "src" => $icon192,
          "type" => "image/png",
          "sizes" => "192x192"
      ]
  ],
  "start_url" => $indexLink,
  "display" => "standalone",
  "theme_color" => "#000000",
  "background_color" => "#ffffff"
];

header('Content-Type: application/json');
echo json_encode($manifest);
