<?php

namespace Vanderbilt\FlightTrackerExternalModule;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Consortium;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Consortium.php");

$bottomPadding = "<br><br><br><br><br>\n";
$grantNumberHeader = "";
if ($grantNumber = CareerDev::getSetting("grant_number")) {
	$grantNumberHeader = " - ".Grant::transformToBaseAwardNumber($grantNumber);
}

?>
<html>
<head>
<title>Flight Tracker <?= CareerDev::getVersion() ?> Dashboard</title>
</head>
<body>
<style>
.centered { text-align: center; }
a, .large { font-size: 18px; }
td { vertical-align: top; padding: 8px; }
input[type=text] { font-size: 18px; width: 300px; }
input[type=submit] { font-size: 18px; }
</style>
<script>
$(document).ready(function() {
	checkMetadata(<?= time() ?>);
});
</script>

<h1 style='margin-bottom: 0;'>Flight Tracker Central</h1>
<h3 class='nomargin' style='background-color: transparent;'>v<?= CareerDev::getVersion() ?></h3>
<h4 class='nomargin'>Watch Your Scholars Fly</h4>
<h5>from <img src="<?= Application::link("img/efs_small_logoonly.png") ?>" alt="Edge for Scholars" style="width: 27px; height: 20px;"> <a href='https://edgeforscholars.org'>Edge for Scholars</a></h5>

<h2><?= $tokenName.$grantNumberHeader ?></h2>

<div class='centered' id='metadataWarning'></div>

<?php
	################ Overhead with External Module
	$module = CareerDev::getModule();
	if ($module) {
		$hours = 12;    // 12 hours prior
		$priorTs = time() - $hours * 3600;
		$lockInfo = $module->getSystemSetting(\ExternalModules\ExternalModules::KEY_RESERVED_IS_CRON_RUNNING);
		if ($lockInfo && $lockInfo['time'] && ($lockInfo['time'] < $priorTs)) {
			echo makeWarning("Your cron has not completed within $hours hours. Your cron most likely needs to be reset. Please <a href='".CareerDev::link("reset_cron.php")."'>click here to do so</a>.");
		}

		$lockHours = 4;
		# remove old-style lock files; new lock files should be picked up by the REDCap clean-up cron 
		$lockFile = APP_PATH_TEMP."6_makeSummary.{$_GET['pid']}.lock";
		if (file_exists($lockFile)) {
			$fp = fopen($lockFile, "r");
			$lockDate = trim(fgets($fp));
			if ($lockDate) {
				$lockTs = strtotime($lockDate);
				if ($lockTs && ($lockTs < time() - $lockHours * 3600)) {
					unlink($lockFile);
				}
			}
			fclose($fp);
		}
	}
?>

<div style='float: left; width: 50%;'>
<?php
	echo "<table style='margin: 0px auto 0px auto; border-radius: 10px;' class='blue'>\n";
	$settings = \Vanderbilt\FlightTrackerExternalModule\getAllSettings();
	if (empty($settings)) {
		echo "<tr>\n";
		echo "<td>\n";
		echo "<h3><i class='fa fa-info-circle'></i> Getting Started</h3>\n";
		echo "<ul class='larger'>\n";
		echo "<li><b>\"Where's my Data?\"</b> Flight Tracker is housed seemlessly in REDCap. Your new scholar records are already added. <i>Automated data collection</i> will start overnight and will continue overnight from here forward. When your data are collected, you will see the latest download information in this box. (We collect data <i>overnight</i> so as not to unduly burden the NIH's servers, which give us the information.)</li>\n";
		echo "<li><b>Manually Start Collection Tonight:</b> If you want to start collecting all of your data <i>tonight</i>, <a href='javascript:;' onclick='startTonight(".$_GET['pid'].");'>click here</a>.</li>\n";
		echo "<li><b>Menus</b> - In the meantime, explore by clicking around the menus above to see the wide array of viewing options.</li>\n";
		echo "<li><b>Help Menu</b> - The <i>Toggle Help</i> item will show if there are any relevant help topics on your current page. The <i>Brand Your Project</i> item will allow you to put your own logo in the upper-right corner.</li>\n";
		// DISABLED echo "<li><b>Emails</b> - If you wish to email your scholars an initial survey, click on <i>Scholars &rarr; Configure an Email</i>. It's recommended to wait a week or two for your data to populate until you email your scholars.</li>\n";
		echo "<li><b>Orientation</b> - Check out the tutorials in the <i>Orientation</i> box on the lower-right.</li>\n";
		echo "<li><b>Feedback</b> - We want to hear from you! Click on <i>Help &rarr; Feedback</i>. We can improve this software only if you let us know your thoughts!</li>\n";
		echo "<li><b>Internal Grants</b> - Many institutions have custom grants with custom names. You can enter these as <a href='".CareerDev::link("customGrants")."'>Custom Grants</a> and build the application to categorize them properly via the <a href='".CareerDev::link("lexicalTranslator.php")."'>Lexical Translator</a>.</li>\n";
		echo "<li><b>Customize</b> - Customize your application to fit your needs. We're building ways for you to customize your application via your own computer programmers and share your tweaks with others. <a href='".CareerDev::link("changes/README.md")."'>Click here to read more.</a></li>\n";
		echo "</ul>\n";
		echo "</td>\n";
		echo "</tr>\n";
	} else {
		echo "<tr>\n";
		echo "<td colspan='2'><h3><i class='fa fa-info-circle'></i> Status</h3></td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		$i = 0;
		$numCols = 2;
		foreach ($settings as $setting => $value) {
			$tsValue = strtotime($value);
			$daysAgo = floor((time() - $tsValue) / (24 * 3600));
			if (($i + 1 == count($settings)) && ($i % $numCols == 0)) {
				# last column which spans two columns
				echo "<td class='centered' colspan='2'>\n";
			} else {
				echo "<td class='centered'>\n";
			}
			echo "<h4 style='margin: 0;'>$setting</h4>\n";
			if ($tsValue && ($daysAgo >= 0)) {
				if ($daysAgo == 0) {
					echo "Today\n";
				} else if ($daysAgo == 1) {
					echo "$daysAgo Day Ago\n";
				} else {
					echo "$daysAgo Days Ago\n";
				}
			} else {
				echo "$value\n";
			}
			echo "</td>\n";
			$i++;
			if ($i % $numCols == 0) {
				echo "</tr>\n";
				echo "<tr>\n";
			}
		}
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td colspan='$numCols' class='centered'><a href='javascript:;' onclick='startTonight(".$_GET['pid'].");'>Click to Run All Updates Tonight</a><br><span class='small'>(Otherwise, updates will run over the course of the week.)</span></td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo $bottomPadding;

function makeWarning($str) {
	return "<div class='centered red'>$str</div>\n";
}
?>
</div>

<div style='float: right;' class='centeredMinus50'>
	<div class='blueBorder translucentBG'>
		<h3><i class='fa fa-search'></i> Search</h3>
		<form action='<?= CareerDev::link("search/index.php") ?>' method='POST'>
			<p class='centered'><input type='text' name='q' id='q' value=''> <input type='submit' value='Search Grants'></p>
		</form>
	
		<form action='<?= CareerDev::link("search/publications.php") ?>' method='POST'>
			<p class='centered'><input type='text' name='q' id='q' value=''> <input type='submit' value='Search Publications'></p>
		</form>
	</div>

	<div style='margin: 50px 0px 0px 0px; padding: 4px 0;' class='blueBorder translucentBG'>
		<h3><i class='fa fa-door-open'></i> Orientation</h3>
		<p class='centered'><i class='fa fa-video'></i> <a href='<?= CareerDev::link("help/videos.php") ?>'>Training Videos</a></p>
		<p class='centered'><a href='<?= CareerDev::link("help/why.php") ?>'>Why Use Flight Tracker?</a></p>
		<p class='centered'><a href='<?= CareerDev::link("help/how.php") ?>'>How to Use Flight Tracker?</a></p>
		<p class='centered'><a href='javascript:;' onclick='toggleHelp("<?= CareerDev::getHelpLink() ?>", "<?= CareerDev::getHelpHiderLink() ?>", "index.php");'>Enable Help on All Pages</a></p>
		<p class='centered'><a href='https://github.com/vanderbilt-redcap/flight-tracker/releases'>Release Log</a> (<a href='https://github.com/scottjpearson/flight-tracker/releases'>Old Releases</a>)</p>
		<h3><i class='fa fa-globe-americas'></i> Consortium</h3>
		<p class='centered'><a href='<?= CareerDev::link("community.php") ?>'>About the Consortium</a></p>
		<h4 class='nomargin'>Monthly Planning Meetings</h4>
		<p class='centered' style='margin-top: 0px;'>Next meeting is on <?= Consortium::findNextMeeting() ?>, at 1pm CT (2pm ET, 11am PT). Email <a href='mailto:scott.j.pearson@vumc.org'>Scott Pearson</a> for an invitation. (<a href='https://redcap.vanderbilt.edu/plugins/career_dev/consortium/'>View agenda</a>.)</p>
	</div>
	<?= $bottomPadding ?>
</div>


</body>
</html>
