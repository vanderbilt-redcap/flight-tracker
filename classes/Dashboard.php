<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Dashboard {
    public function __construct($pid) {
        $this->pid = $pid;
    }

    public static function fractionToPercent($frac) {
        return (floor($frac * 1000) / 10)."%";
    }

    public static function addHTMLForParens($header) {
        $str = str_replace("(", "<br><span class='measurementHeaderSmall'>(", $header);
        return str_replace(")", ")</span>", $str);
    }

    public static function getPossibleLayouts() {
        return [
            "table",
            "display",
        ];
    }

    public function getTarget() {
        if (!isset($_GET['layout'])) {
            return "display";
        }
        $possibleLayouts = self::getPossibleLayouts();
        $layout = Sanitizer::sanitize($_GET['layout']);
        if (in_array($layout, $possibleLayouts)) {
            foreach ($possibleLayouts as $possibleLayout) {
                if ($possibleLayout == $layout) {
                    return $possibleLayout;
                }
            }
            return "";
        }
        throw new \Exception("Invalid layout ".$layout);
    }

    public function makeLineGraph($lines) {
        $html = "";

        $html .= "<script src='".Application::link("js/Chart.min.js")."'></script>\n";

        $html .= "<canvas id='canvas' class='chartjs-render-monitor' style='display: block; width: 700px; height: 350px;'></canvas>\n";

        $html .= "<script>\n";
        $xs = array();
        $minX = 10000;
        $maxX = 0;
        foreach ($lines as $title => $line) {
            foreach ($line as $year => $count) {
                if ($maxX < $year) {
                    $maxX = $year;
                }
                if ($minX > $year) {
                    $minX = $year;
                }
            }
        }
        for($year = $minX; $year <= $maxX; $year++) {
            $xs[] = $year;
        }

        $data = array(
            "labels" => $xs,
            "datasets" => array()
        );
        foreach ($lines as $title => $line) {
            $dataset = array(
                "label" => $title,
                "data" => array(),
                "fill" => FALSE,
                "borderColor" => "rgb(0, 192, 192)",
                "lineTension" => 0.1
            );
            foreach ($xs as $year) {
                if (!isset($line[$year])) {
                    $count = 0;
                } else {
                    $count = $line[$year];
                }
                $dataset['data'][] = $count;
            }
            $data['datasets'][] = $dataset;
        }

        $html .= "var config = {\n";
        $html .= "\ttype: 'line',\n";
        $html .= "\tdata: ".json_encode($data).",\n";
        $html .= "\toptions: {
        responsive: true,
        title: { display: true, text: 'Number of Publications' },
        tooltips: {
            mode: 'index',
            intersect: false,
        },
        hover: {
            mode: 'nearest',
            intersect: true
        },
        scales: {
            x: {
                display: true,
                title: {
                    display: true,
                    text: 'Year'
                }
            },
            y: {
                display: true,
                title: {
                    display: true,
                    text: 'Publication Count'
                }
            }
        }
    }\n";
        $html .= "\t};\n";

        $html .= "window.onload = function() { var ctx = document.getElementById('canvas').getContext('2d'); window.myLine = new Chart(ctx, config); };\n";

        $html .= "</script>\n";

        return $html;
    }

    private static function getPage() {
        if (isset($_GET['page'])) {
            return Sanitizer::sanitize($_GET['page']);
        } else {
            return basename(Sanitizer::sanitize($_SERVER['SCRIPT_NAME']));
        }
    }

    public function displayDashboardHeader($target, $otherTarget, $pid, $cohort = "") {
        global $token, $server;
        $html = "";

        $cohortUrl = "";
        if ($cohort || ($cohort == "all")) {
            $cohortUrl = "&cohort=".$cohort;
        }
        # page specified below in $pubChoices
        $trailingParams = "&layout=$target$cohortUrl";
        $pubChoices = [
            Application::link("dashboard/publicationsByCategory.php", $pid).$trailingParams => "By Category",
            Application::link("dashboard/publicationsByYear.php", $pid).$trailingParams => "By Year",
            Application::link("dashboard/publicationsByJournal.php", $pid).$trailingParams => "By Journal",
            Application::link("dashboard/publicationsByPublicationType.php", $pid).$trailingParams => "By PubMed Publication Type",
            Application::link("dashboard/publicationsByMESHTerms.php", $pid).$trailingParams => "By MESH Terms",
            Application::link("dashboard/publicationsByMetrics.php", $pid).$trailingParams => "Miscellaneous Metrics",
        ];

        $html .= "<div class='subnav'>\n";
        $html .= "<a class='yellow' href='".Application::link("dashboard/overall.php")."&layout=$target$cohortUrl'>Overall Summary</a>\n";
        $html .= "<a class='yellow' href='".Application::link("this")."&layout=$otherTarget$cohortUrl'>Switch Layouts</a>\n";

        $html .= "<a class='green' href='".Application::link("dashboard/grants.php")."&layout=$target$cohortUrl'>Grants</a>\n";
        $html .= "<a class='green' href='".Application::link("dashboard/grantBudgets.php")."&layout=$target$cohortUrl'>Grant Budgets</a>\n";
        $html .= "<a class='green' href='".Application::link("dashboard/grantBudgetsByYear.php")."&layout=$target$cohortUrl'>Grant Budgets by Year</a>\n";

        $html .= "<a class='orange' href='javascript:;' onclick='return false;'>Publications <select onchange='changePub(this);'>\n";
        $getPage = self::getPage();
        $sel = preg_match("/publicationsBy/", $getPage) ? "" : "selected";
        $html .= "<option value='' $sel>---SELECT---</option>\n";
        $getPageForRegEx = preg_replace("/\//", "\\/", $getPage);
        foreach ($pubChoices as $page => $label) {
            $sel = "";
            if (preg_match("/".$getPageForRegEx."/", $page)) {
                $sel = " selected";
            }
            $html .= "<option value='$page'$sel>$label</option>\n";
        }
        $html .= "</select></a>\n";

        $html .= "<a class='blue' href='".Application::link("dashboard/emails.php")."&layout=$target$cohortUrl'>Emails</a>\n";
        $html .= "<a class='blue' href='".Application::link("dashboard/demographics.php")."&layout=$target$cohortUrl'>Demographics</a>\n";
        $html .= "<a class='blue' href='".Application::link("dashboard/resources.php")."&layout=$target$cohortUrl'>Resources</a>\n";

        # This page does not seem to be sufficiently helpful to keep in
        // $html .= "<a class='blue' href='".Application::link("dashboard/dates.php")."&layout=$target$cohortUrl'>Dates</a>\n";


        $html .= "<script>\n";
        $html .= "function changePub(ob) {\n";
        $html .= "\tvar sel = $(ob).children('option:selected').val();\n";
        $html .= "\tif (sel) { window.location.href = sel; }\n";
        $html .= "}\n";
        $html .= "</script>\n";

        $cohorts = new Cohorts($token, $server, Application::getModule());
        $cohortTitles = $cohorts->getCohortTitles();
        $html .= "<a href='javascript:;' onclick='return false;' class='purple'>Select Cohort: <select onchange='if ($(this).val()) { window.location.href = \"?layout=$target&pid=$pid&page=".$_GET['page']."&prefix=".$_GET['prefix']."&cohort=\" + $(this).val(); } else { window.location.href = \"?layout=$target&pid=$pid&page=".$_GET['page']."&prefix=".$_GET['prefix']."\"; }'>\n";
        $html .= "<option value=''>---ALL---</option>\n";
        foreach ($cohortTitles as $title) {
            $html .= "<option value='$title'";
            if ($title == $cohort) {
                $html .= " selected";
            }
            $html .= ">$title</option>\n";
        }
        $html .= "</select></a>\n";
        $html .= "<a class='purple' href='".Application::link("/cohorts/viewCohorts.php")."'>View Cohorts</a>\n";

        $html .= "</div>\n";

        return $html;
    }

    public function makeHTML($headers, $measurements, $lines = [], $cohort = "", $numInRow = 4) {
        $target = $this->getTarget();
        if ($target == "table") {
            return $this->makeTableHTML($headers, $measurements, $lines, $cohort);
        } else if ($target == "display") {
            return $this->makeDisplayHTML($headers, $measurements, $lines, $cohort, $numInRow);
        } else {
            throw new \Exception("Invalid Target: $target");
        }
    }

    private function makeDisplayHTML($headers, $measurements, $lines = array(), $cohort = "", $numInRow = 4) {
        global $pid;

        if (empty($measurements) && empty($headers)) {
            return "<h1>Under Construction!</h1>\n";
        }

        if (!isset($headers)) {
            throw new \Exception("headers must be specified!");
        }
        if (!isset($measurements)) {
            throw new \Exception("measurements must be specified!");
        }

        $target = $this->getTarget();
        if ($target == "display") {
            $otherTarget = "table";
        } else {
            $otherTarget = "display";
        }

        $html = "";
        $html .= $this->displayDashboardHeader($target, $otherTarget, $pid, $cohort);
        $html .= "<div id='content'>\n";

        $i = 1;
        foreach ($headers as $header) {
            $html .= "<h$i>".$header."</h$i>\n";
            $i++;
        }

        if (empty($measurements)) {
            $html .= "<p class='centered'>No measurements have been made!</p>\n";
        }

        $i = 0;
        $html .= "<table style='margin-left: auto; margin-right: auto;'><tr>\n";
        foreach ($measurements as $header => $count) {
            $htmlHeader = self::addHTMLForParens($header);
            if (get_class($count) == "Vanderbilt\\CareerDevLibrary\\ObservedMeasurement") {
                $value = $count->getValue();
                $n = $count->getN();
                $html .= "<td class='measurement'>\n";
                $html .= "<div class='measurementHeader'>".$htmlHeader."</div>\n";
                $html .= "<div class='measurementNumber'>".REDCapManagement::pretty($value)."</div>\n";
                $html .= "<div class='measurementDenominator'>(n = <b>".REDCapManagement::pretty($n)."</b>)</div>\n";
                $html .= "</td>\n";
            } else if (get_class($count) == "Vanderbilt\\CareerDevLibrary\\DateMeasurement") {
                $date = $count->getMDY();
                $wDay = $count->getWeekDay();
                $html .= "<td class='dateMeasurement'>\n";
                $html .= "<div class='measurementHeader'>".$htmlHeader."</div>\n";
                $html .= "<div class='measurementDenominator'>$wDay</div>\n";
                $html .= "<div class='measurementDate'>".$date."</div>\n";
                $html .= "<div class='animationProgress'>&nbsp;</div>\n";
                $html .= "</td>\n";
            } else if (get_class($count) == "Vanderbilt\\CareerDevLibrary\\MoneyMeasurement") {
                $amt = $count->getAmount();
                $total = $count->getTotal();
                $html .= "<td class='moneyMeasurement'>\n";
                $html .= "<div class='measurementHeader'>".$htmlHeader."</div>\n";
                $html .= "<div class='measurementMoney'>".REDCapManagement::prettyMoney($amt, FALSE)."</div>\n";
                if ($total) {
                    $html .= "<progress class='animationProgress' max='1.0' value='".($amt / $total)."'></progress>\n";
                    $html .= "<div class='measurementDenominator'>out of ".REDCapManagement::prettyMoney($total, FALSE)."</div>\n";
                }
                $html .= "<div class='animationProgress'>&nbsp;</div>\n";
                $html .= "</td>\n";
            } else {
                $numer = $count->getNumerator();
                $html .= "<td class='measurement'>\n";
                $html .= "<div class='measurementHeader'>".$htmlHeader."</div>\n";
                $html .= "<div class='measurementNumber'>".REDCapManagement::pretty($numer)."</div>\n";
                $denom = $count->getDenominator();
                if ($denom) {
                    $html .= "<progress class='animationProgress' max='1.0' value='".($numer / $denom)."'></progress>\n";
                    $html .= "<div class='measurementDenominator'>out of <b>".REDCapManagement::pretty($denom)."</b> (".self::fractionToPercent($numer / $denom).")</div>\n";
                } else {
                    $html .= "<div class='measurementDenominator'>&nbsp;</div>\n";
                    $html .= "<div class='animationProgress'>&nbsp;</div>\n";
                }
                $html .= "</td>\n";
            }
            $i++;
            if ($i % $numInRow == 0) {
                $html .= "</tr></table>\n";
                $html .= "<div class='verticalSpacer'>&nbsp;</div>\n";
                $html .= "<table style='margin-left: auto; margin-right: auto;'><tr>\n";
            } else if ($i < count($measurements)) {
                $html .= "<td class='spacer'>&nbsp;</td>\n";
            }
        }
        $html .= "</tr></table>\n";

        if (!empty($lines)) {
            $html .= $this->makeLineGraph($lines);
        }
        $html .= "</div>\n";

        return $html;
    }

    private function makeTableHTML($headers, $measurements, $lines = array(), $cohort = "") {
        if (empty($measurements) && empty($headers)) {
            return "<h1>Under Construction!</h1>\n";
        }

        if (!isset($headers)) {
            throw new \Exception("headers must be specified!");
        }
        if (!isset($measurements)) {
            throw new \Exception("measurements must be specified!");
        }

        $target = $this->getTarget();
        if ($target == "display") {
            $otherTarget = "table";
        } else {
            $otherTarget = "display";
        }

        $html = "";
        $html .= $this->displayDashboardHeader($target, $otherTarget, $this->pid, $cohort);
        $html .= "<div id='content'>\n";

        $i = 1;
        foreach ($headers as $header) {
            $html .= "<h$i>".$header."</h$i>\n";
            $i++;
        }

        if (empty($measurements)) {
            $html .= "<p class='centered'>No measurements have been made!</p>\n";
        }

        $i = 1;
        $html .= "<table style='margin-left: auto; margin-right: auto;'>\n";
        foreach ($measurements as $header => $count) {
            if (($i % 2) == 1) {
                $rowClass = "odd";
            } else {
                $rowClass = "even";
            }
            $htmlHeader = self::addHTMLForParens($header);
            if (get_class($count) == "Vanderbilt\\CareerDevLibrary\\DateMeasurement") {
                $date = $count->getMDY();
                $wDay = $count->getWeekDay();
                $html .= "<tr class='dateMeasurement $rowClass'>\n";
                $html .= "<td class='measurementHeader'>".$htmlHeader."</td>\n";
                $html .= "<td class='measurementDenominator'>$wDay</td>\n";
                $html .= "<td class='measurementDate'>".$date."</td>\n";
                $html .= "</tr>\n";
            } else if (get_class($count) == "Vanderbilt\\CareerDevLibrary\\ObservedMeasurement") {
                $value = $count->getValue();
                $n = $count->getN();
                $html .= "<tr class='dateMeasurement $rowClass'>\n";
                $html .= "<td class='measurementHeader'>".$htmlHeader."</td>\n";
                $html .= "<td class='measurementNumerator'>".REDCapManagement::pretty($value)."</td>\n";
                $html .= "<td class='measurementDenominator'>(n = ".REDCapManagement::pretty($n).")</td>\n";
                $html .= "</tr>\n";
            } else if (get_class($count) == "Vanderbilt\\CareerDevLibrary\\MoneyMeasurement") {
                $amt = $count->getAmount();
                $total = $count->getTotal();
                $html .= "<tr class='measurement $rowClass'>\n";
                $html .= "<td class='measurementHeader'>".$htmlHeader."</div>\n";
                $html .= "<td class='measurementMoney'>".REDCapManagement::prettyMoney($amt, FALSE)."</div>\n";
                if ($total) {
                    $html .= "<td><progress class='animationProgress' max='1.0' value='".($amt / $total)."'></progress></td>\n";
                    $html .= "<td class='measurementDenominator'>out of ".REDCapManagement::prettyMoney($total, FALSE)."</div>\n";
                } else {
                    $html .= "<td></td><td></td>\n";
                }
                $html .= "</td>\n";
                $html .= "</tr>\n";
            } else {
                $numer = $count->getNumerator();
                $html .= "<tr class='measurement $rowClass'>\n";
                $html .= "<td class='measurementHeader'>".$htmlHeader."</td>\n";
                $html .= "<td class='measurementNumber'>".REDCapManagement::pretty($numer)."</td>\n";
                $denom = $count->getDenominator();
                if ($denom) {
                    $html .= "<td><progress class='animationProgress' max='1.0' value='".($numer / $denom)."'></progress></td>\n";
                    $html .= "<td class='measurementDenominator'>out of <b>".REDCapManagement::pretty($denom)."</b> (".self::fractionToPercent($numer / $denom).")</td>\n";
                }
                $html .= "</tr>\n";
            }
            $i++;
        }
        $html .= "</table>\n";

        if (!empty($lines)) {
            $html .= $this->makeLineGraph($lines);
        }
        $html .= "</div>\n";

        return $html;
    }

    protected $pid;
}