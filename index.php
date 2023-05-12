<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Consortium;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\FeatureSwitches;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

try {
    require_once(dirname(__FILE__)."/small_base.php");
    require_once(dirname(__FILE__)."/classes/Autoload.php");

    if (!empty($_POST)) {
        $switches = new FeatureSwitches($token, $server, $pid);
        $data = $switches->savePost($_POST);
        echo json_encode($data);
        exit;
    } else {
        if (($server === NULL) || ($server === "")) {
            $prefix = Sanitizer::sanitize($_GET['prefix'] ?? "flight_tracker");
            $pid = Sanitizer::sanitizePid($_GET['pid'] ?? "");
            if (!$pid) {
                die("You must supply a valid project-id!");
            }
            $url = "?prefix=$prefix&page=install&pid=$pid";
            header("Location: $url");
        }
    }

    if (Application::isTable1Project($pid) || Application::isSocialMediaProject($pid)) {
        header("Location: ".Links::makeProjectHomeURL($pid));
    }

    require_once(dirname(__FILE__)."/charts/baseWeb.php");

    if (!isset($pid)) {
        die("Invalid project id.");
    }

    $bottomPadding = "<br><br><br><br><br>\n";
    $grantNumberHeader = "";
    if ($grantNumber = CareerDev::getSetting("grant_number", $pid)) {
        $grantNumberHeader = " - ".Grant::translateToBaseAwardNumber($grantNumber);
    }

    $projectSettings = Download::getProjectSettings($token, $server);
    $projectNotes = "";
    if ($projectSettings['project_notes']) {
        $projectNotes = "<p class='centered'>".$projectSettings['project_notes']."</p>";
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
    <?php
    if (!CareerDev::getSetting("turn_off", $pid)) {
        $currTs = time();
        echo "<script>
$(document).ready(function() {
	checkMetadata($currTs);
});
</script>";
    }
    ?>

    <h1 style='margin-bottom: 0;'>Flight Tracker Central</h1>
    <h3 class='nomargin' style='background-color: transparent;'>v<?= CareerDev::getVersion() ?></h3>
    <h4 class='nomargin'>Watch Your Scholars Fly</h4>
    <h5>from <img src="<?= Application::link("img/efs_small_logoonly.png") ?>" alt="Edge for Scholars" style="width: 27px; height: 20px;"> <a href='https://edgeforscholars.org'>Edge for Scholars</a></h5>

    <h2><?= $tokenName.$grantNumberHeader ?></h2>

    <?= $projectNotes ?>

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
        $lockFilename = REDCapManagement::makeSafeFilename("6_makeSummary.$pid.lock");
        $lockFile = APP_PATH_TEMP.$lockFilename;
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
        $switches = new FeatureSwitches($token, $server, $pid);

        echo "<table style='margin: 0px auto 0px auto; border-radius: 10px; max-width: 90%;' class='blue'>\n";
        $settings = \Vanderbilt\FlightTrackerExternalModule\getAllSettings();
        if (empty($settings)) {
            echo "<tr>\n";
            echo "<td>\n";
            echo "<h3><i class='fa fa-info-circle'></i> Getting Started</h3>\n";
            echo "<ul class='larger'>
<li><strong>Step 1</strong>: Collect some data. Data will come in over the course of a week and stay updated each week. If you want to collect all data at once, click the link to “<a href='javascript:;' onclick='startTonight($pid);'>Run All Updates Tonight</a>” on the Flight Tracker Home page.</li>
<li><strong>Step 2</strong>: If no data are being pulled in from a given resource, go to the General menu &rarr; Test Connectivity page. Green means that a resource can connect to it; yellow means that it’s trying but hasn’t succeeded; and red indicates a failure. Your REDCap server might have a firewall, and you might need to pull in your IT team and REDCap admin to help. You can re-test until all indicators turn green.</li>
<li><strong>Step 3</strong>: You’ll need to wrangle your data via the Publication Wrangler and the Grant Wrangler. This step ensures that there aren’t false positives – that is, that the name-matching is correct. Also, keep an eye out for data holes (false negatives).</li>
<li><strong>Step 4</strong>: You might need to adjust scholar names to correspond with PubMed and the NIH RePORTER. You can adjust the names in the Identifiers form on each scholar’s REDCap record. The REDCap form will coach you how to handle maiden names and nicknames. One name option needs to match the data source, or else no data will pull!</li> 
<li><strong>Step 5</strong>: Add in other institutions specific to each scholar. Flight Tracker will automatically search for the home institution you entered in the setup. If a scholar has another institution (for example, either before or after their time with you), then you can enter it on a Position Change form on the scholar’s REDCap record. Like names, these need to correspond with the NIH RePORTER or PubMed.</li>
</ul>";
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
            echo "<td colspan='$numCols' class='centered'><a href='javascript:;' onclick='startTonight(".$pid.");'>Click to Run All Updates Tonight</a><br><span class='small'>(Otherwise, updates will run over the course of the week.)</span></td>\n";
            echo "</tr>\n";
        }
        echo "</table>\n";

        ?>

        <div style='margin: 25px 10px 0px 0px; padding: 4px 0;' class='blueBorder translucentBG'>
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

    <div style='float: right;' class='centeredMinus50'>
        <div style='margin: 50px 0px 0px 0px; padding: 4px 0;' class='blueBorder translucentBG'>
            <h3><i class='fa fa-toggle-on'></i> Features</h3>
            <?= $switches->makeHTML() ?>
            <h3><i class='fa fa-cogs'></i> Configurations</h3>
            <p class="centered"><a href="<?= Application::link("config.php") ?>">Configure Application</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="<?= Application::link("config.php")."&order" ?>">Configure Summaries</a></p>
        </div>

        <div class='blue' style="border-radius: 10px; max-width: 90%; margin: 25px auto 0 auto; padding: 8px;">
            <h3 style="margin-top: 0;"><i class='fa fa-search'></i> Search</h3>
            <form action='<?= CareerDev::link("search/index.php") ?>' method='POST'>
                <?= Application::generateCSRFTokenHTML() ?>
                <p class='centered'><input type='text' name='q' id='q' value=''> <input type='submit' value='Search Grants'></p>
            </form>

            <form action='<?= CareerDev::link("search/publications.php") ?>' method='POST'>
                <?= Application::generateCSRFTokenHTML() ?>
                <p class='centered'><input type='text' name='q' id='q' value=''> <input type='submit' value='Search Publications'></p>
            </form>
        </div>

        <?= $bottomPadding ?>
    </div>


    </body>
    </html>

    <?php
} catch (\Exception $e) {
    $mssg = $e->getMessage()."<br/><br/>".Sanitizer::sanitize($e->getTraceAsString());
    echo "Oops! Something went wrong. Please contact <a href='mailto:scott.j.pearson@vumc.org'>scott.j.pearson@vumc.org</a> with the below message.<br/>".$mssg;
}

function makeWarning($str) {
    return "<div class='centered red'>$str</div>\n";
}
