<?php

use Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../../../classes/Autoload.php");
require_once(dirname(__FILE__)."/../../../small_base.php");

$relativeDir = "reporting/upload_react/run/";

$filesToInclude = [
	"/\.js$/",
	"/\.css$/",
];

$filesToIncludeList = [];
foreach ($filesToInclude as $fileRegex) {
	$dir = $relativeDir;
	$files = scandir(dirname(__FILE__)."/../../../".$dir);
	foreach ($files as $filename) {
		if (preg_match($fileRegex, $filename)) {
			$filesToIncludeList[] = $dir.$filename;
		}
	}
}

$driverLink = Application::link("reporting/upload.php");
$museoSansLink = Application::link("fonts/exljbris - MuseoSans-500.otf");
$georgiaLink = Application::link("fonts/georgia.woff");
$backgroundLink = Application::link("css/general.css");
$iconHTML = Application::makeIcon();

$headerHTML = Application::getHeader();
$footerHTML = Application::getFooter();

?>
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8"/>
<?php
	foreach ($filesToIncludeList as $file) {
		if (preg_match("/\.css$/", $file)) {
			$link = Application::link($file)."&".Application::getVersion();
			echo "<link href=\"$link\" rel=\"stylesheet\" />\n";
		}
	}
?>
        <link href="<?= $backgroundLink ?>" rel="stylesheet" />
        <?= $iconHTML ?>
        <title>Flight Tracker - Upload NIH Training Tables</title>

        <style>
        @font-face{font-family:"Museo Sans";font-style:normal;font-weight:400;src:url("<?= $museoSansLink ?>")}
        @font-face{font-family:'Georgia';font-style:normal;font-weight:normal;src:url("<?= $georgiaLink ?>")}
        </style>

            <script>
            function getCSRFToken() {
                return '<?= Application::generateCSRFToken() ?>';
            }
        </script>
    </head>
    <body>
        <?= $headerHTML ?>
        <noscript>You need to enable JavaScript to run this app.</noscript>
        <div id="root" link="<?= $driverLink ?>"></div>
<?php
	// Vite emits a single native ES module bundle with no code-splitting, so there's
	// no webpack runtime/chunk-loader needed here (unlike the old CRA/webpack build).
	foreach ($filesToIncludeList as $file) {
		if (preg_match("/\.js$/", $file)) {
			$link = Application::link($file)."&".Application::getVersion();
			echo "<script type=\"module\" src=\"$link\"></script>\n";
		}
	}
?>
        <?= $footerHTML ?>
    </body>
</html>
