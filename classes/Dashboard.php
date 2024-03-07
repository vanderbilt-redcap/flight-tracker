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

    private function addGrantTypeSelect($selectedOption, $cohort, $target) {
        $thisUrl = Application::link("this");
        $options = [
            "prior" => "Career-Defining Awards",
            "all_pis" => "All Awards in PI/Co-PI Role",
            "deduped" => "All Awards, Regardless of Role",
        ];
        $cohortParam = $cohort ? "&cohort=".urlencode($cohort) : "";
        $html = "<p class='centered'><label for='grantType'>Type of Grant: </label><select name='grantType' id='grantType' onchange='location.href=\"$thisUrl$cohortParam&layout=$target&grantType=\"+$(\"#grantType :selected\").val();'>";
        foreach ($options as $value => $label) {
            $selected = ($selectedOption == $value) ? "selected" : "";
            $html .= "<option value='$value' $selected>$label</option>";
        }
        $html .= "</select></p>";
        return $html;
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
        $saveDiv = REDCapManagement::makeSaveDiv("canvas", TRUE);

        $html .= "<script src='".Application::link("js/Chart.min.js")."'></script>";

        $canvasId = "lineCanvas";
        $html .= "<canvas id='$canvasId' class='chartjs-render-monitor' style='display: block; width: 700px; height: 350px;'></canvas>";

        $html .= "<script>";
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

        $html .= "var config = {";
        $html .= "\ttype: 'line',";
        $html .= "\tdata: ".json_encode($data).",";
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
    }";
        $html .= "\t};";

        $html .= "
        window.onload = function() { const ctx = document.getElementById('$canvasId').getContext('2d'); window.myLine = new Chart(ctx, config); };
        $(document).ready(function() {
            $('#$canvasId').parent().append(\"$saveDiv\");
        });
";

        $html .= "</script>";

        return $html;
    }

    private static function getPage() {
        if (isset($_GET['page'])) {
            return Sanitizer::sanitize($_GET['page']);
        } else {
            return basename(Sanitizer::sanitize($_SERVER['SCRIPT_NAME'] ?? ""));
        }
    }

    private function displayDashboardHeader($target, $otherTarget, $pid, $cohort = "", $grantType = "") {
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

        $color = "blue";
        $grantTypeParam = $grantType ? "&grantType=".urlencode($grantType) : "";
        $html .= "<div class='subnav'>";
        $html .= "<a class='$color' href='".Application::link("this")."&layout=$otherTarget$cohortUrl$grantTypeParam'>Switch Layouts</a>";
        $html .= "<a class='$color' href='javascript:;' onclick='return false;'>Publications <select onchange='changePub(this);'>";
        $getPage = self::getPage();
        $sel = preg_match("/publicationsBy/", $getPage) ? "" : "selected";
        $html .= "<option value='' $sel>---SELECT---</option>";
        $getPageForRegEx = preg_replace("/\//", "\\/", $getPage);
        foreach ($pubChoices as $page => $label) {
            $sel = "";
            if (preg_match("/".$getPageForRegEx."/", $page)) {
                $sel = " selected";
            }
            $html .= "<option value='$page'$sel>$label</option>";
        }
        $html .= "</select></a>";


        $html .= "<script>
function changePub(ob) {
    const sel = $(ob).children('option:selected').val();
    if (sel) {
        window.location.href = sel;
    }
}
</script>";

        $cohorts = new Cohorts($token, $server, Application::getModule());
        $cohortTitles = $cohorts->getCohortTitles();
        $thisUrl = Application::link("this");
        $html .= "<a href='javascript:;' onclick='return false;' class='$color'>Select Cohort: <select id='cohort' onchange='if ($(this).val()) { window.location.href = \"$thisUrl&layout=$target$grantTypeParam&cohort=\" + $(this).val(); } else { window.location.href = \"$thisUrl&layout=$target$grantTypeParam\"; }'>";
        $html .= "<option value=''>---ALL---</option>";
        foreach ($cohortTitles as $title) {
            $html .= "<option value='$title'";
            if ($title == $cohort) {
                $html .= " selected";
            }
            $html .= ">$title</option>";
        }
        $html .= "</select></a>";
        $html .= "</div>";

        return $html;
    }

    public function makeHTML($headers, $measurements, $lines = [], $cohort = "", $numInRow = 4, $defaultGrantType = "") {
        $target = $this->getTarget();
        if ($target == "table") {
            return $this->makeTableHTML($headers, $measurements, $lines, $cohort, $defaultGrantType);
        } else if ($target == "display") {
            return $this->makeDisplayHTML($headers, $measurements, $lines, $cohort, $numInRow, $defaultGrantType);
        } else {
            throw new \Exception("Invalid Target: $target");
        }
    }

    private function makeDisplayHTML($headers, $measurements, $lines = array(), $cohort = "", $numInRow = 4, $defaultGrantType = "") {
        global $pid;

        if (empty($measurements) && empty($headers)) {
            return "<h1>Under Construction!</h1>";
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
        $html .= $this->displayDashboardHeader($target, $otherTarget, $pid, $cohort, $defaultGrantType);
        $html .= "<div id='content'>";
        if ($defaultGrantType) {
            $html .= $this->addGrantTypeSelect($defaultGrantType, $cohort, $target);
        }

        $html .= self::makeHeaders($headers);

        if (empty($measurements)) {
            $html .= "<p class='centered'>No measurements have been made!</p>";
        }

        $i = 0;
        $html .= "<table style='margin-left: auto; margin-right: auto;'><tr>";
        foreach ($measurements as $header => $count) {
            $htmlHeader = self::addHTMLForParens($header);
            $countClassName = get_class($count);
            if ($countClassName == "Vanderbilt\\CareerDevLibrary\\ObservedMeasurement") {
                $value = $count->getValue();
                $n = $count->getN();
                $html .= "<td class='measurement'>";
                $html .= "<div class='measurementHeader'>".$htmlHeader."</div>";
                $html .= "<div class='measurementNumber'>".REDCapManagement::pretty($value)."</div>";
                $html .= "<div class='measurementDenominator'>(n = <b>".REDCapManagement::pretty($n)."</b>)</div>";
                $html .= "</td>";
            } else if ($countClassName == "Vanderbilt\\CareerDevLibrary\\DateMeasurement") {
                $date = $count->getMDY();
                $wDay = $count->getWeekDay();
                $html .= "<td class='dateMeasurement'>";
                $html .= "<div class='measurementHeader'>".$htmlHeader."</div>";
                $html .= "<div class='measurementDenominator'>$wDay</div>";
                $html .= "<div class='measurementDate'>".$date."</div>";
                $html .= "<div class='animationProgress'>&nbsp;</div>";
                $html .= "</td>";
            } else if ($countClassName == "Vanderbilt\\CareerDevLibrary\\MoneyMeasurement") {
                $amt = $count->getAmount();
                $total = $count->getTotal();
                $html .= "<td class='moneyMeasurement'>";
                $html .= "<div class='measurementHeader'>".$htmlHeader."</div>";
                $html .= "<div class='measurementMoney'>".REDCapManagement::prettyMoney($amt, FALSE)."</div>";
                if ($total) {
                    $html .= "<progress class='animationProgress' max='1.0' value='".($amt / $total)."'></progress>";
                    $html .= "<div class='measurementDenominator'>out of ".REDCapManagement::prettyMoney($total, FALSE)."</div>";
                }
                $html .= "<div class='animationProgress'>&nbsp;</div>";
                $html .= "</td>";
            } else {
                $numer = $count->getNumerator();
                $html .= "<td class='measurement'>";
                $html .= "<div class='measurementHeader'>".$htmlHeader."</div>";
                $html .= "<div class='measurementNumber'>".REDCapManagement::pretty($numer)."</div>";
                $denom = $count->getDenominator();
                if ($denom) {
                    $html .= "<progress class='animationProgress' max='1.0' value='".($numer / $denom)."'></progress>";
                    $html .= "<div class='measurementDenominator'>out of <b>".REDCapManagement::pretty($denom)."</b> (".self::fractionToPercent($numer / $denom).")</div>";
                } else {
                    $html .= "<div class='measurementDenominator'>&nbsp;</div>";
                    $html .= "<div class='animationProgress'>&nbsp;</div>";
                }
                $html .= "</td>";
            }
            $i++;
            if ($i % $numInRow == 0) {
                $html .= "</tr></table>";
                $html .= "<div class='verticalSpacer'>&nbsp;</div>";
                $html .= "<table style='margin-left: auto; margin-right: auto;'><tr>";
            } else if ($i < count($measurements)) {
                $html .= "<td class='spacer'>&nbsp;</td>";
            }
        }
        $html .= "</tr></table>";

        if (!empty($lines)) {
            $html .= $this->makeLineGraph($lines);
        }
        $html .= "</div>";

        return $html;
    }

    private static function makeHeaders($headers) {
        $html = "";
        $i = 1;
        foreach ($headers as $header) {
            $plainHTML = FALSE;
            foreach (["p", "div"] as $tag) {
                if (preg_match("/^<$tag.+<\/$tag>$/i", $header)) {
                    $plainHTML = TRUE;
                }
            }
            if ($plainHTML) {
                $html .= $header."";
            } else {
                $html .= "<h$i>".$header."</h$i>";
                $i++;
            }
        }
        return $html;
    }

    private function makeTableHTML($headers, $measurements, $lines = array(), $cohort = "", $defaultGrantType = "") {
        if (empty($measurements) && empty($headers)) {
            return "<h1>Under Construction!</h1>";
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
        $html .= $this->displayDashboardHeader($target, $otherTarget, $this->pid, $cohort, $defaultGrantType);
        $html .= "<div id='content'>";
        if ($defaultGrantType) {
            $html .= $this->addGrantTypeSelect($defaultGrantType, $cohort, $target);
        }

        $html .= self::makeHeaders($headers);
        if (empty($measurements)) {
            $html .= "<p class='centered'>No measurements have been made!</p>";
        }

        $i = 1;
        $html .= "<table style='margin-left: auto; margin-right: auto;'>";
        foreach ($measurements as $header => $count) {
            if (($i % 2) == 1) {
                $rowClass = "odd";
            } else {
                $rowClass = "even";
            }
            $htmlHeader = self::addHTMLForParens($header);
            $countClassName = get_class($count);
            if ($countClassName == "Vanderbilt\\CareerDevLibrary\\DateMeasurement") {
                $date = $count->getMDY();
                $wDay = $count->getWeekDay();
                $html .= "<tr class='dateMeasurement $rowClass'>";
                $html .= "<td class='measurementHeader'>".$htmlHeader."</td>";
                $html .= "<td class='measurementDenominator'>$wDay</td>";
                $html .= "<td class='measurementDate'>".$date."</td>";
                $html .= "</tr>";
            } else if ($countClassName == "Vanderbilt\\CareerDevLibrary\\ObservedMeasurement") {
                $value = $count->getValue();
                $n = $count->getN();
                $html .= "<tr class='dateMeasurement $rowClass'>";
                $html .= "<td class='measurementHeader'>".$htmlHeader."</td>";
                $html .= "<td class='measurementNumerator'>".REDCapManagement::pretty($value)."</td>";
                $html .= "<td class='measurementDenominator'>(n = ".REDCapManagement::pretty($n).")</td>";
                $html .= "</tr>";
            } else if ($countClassName == "Vanderbilt\\CareerDevLibrary\\MoneyMeasurement") {
                $amt = $count->getAmount();
                $total = $count->getTotal();
                $html .= "<tr class='measurement $rowClass'>";
                $html .= "<td class='measurementHeader'>".$htmlHeader."</div>";
                $html .= "<td class='measurementMoney'>".REDCapManagement::prettyMoney($amt, FALSE)."</div>";
                if ($total) {
                    $html .= "<td><progress class='animationProgress' max='1.0' value='".($amt / $total)."'></progress></td>";
                    $html .= "<td class='measurementDenominator'>out of ".REDCapManagement::prettyMoney($total, FALSE)."</div>";
                } else {
                    $html .= "<td></td><td></td>";
                }
                $html .= "</td>";
                $html .= "</tr>";
            } else {
                $numer = $count->getNumerator();
                $html .= "<tr class='measurement $rowClass'>";
                $html .= "<td class='measurementHeader'>".$htmlHeader."</td>";
                $html .= "<td class='measurementNumber'>".REDCapManagement::pretty($numer)."</td>";
                $denom = $count->getDenominator();
                if ($denom) {
                    $html .= "<td><progress class='animationProgress' max='1.0' value='".($numer / $denom)."'></progress></td>";
                    $html .= "<td class='measurementDenominator'>out of <b>".REDCapManagement::pretty($denom)."</b> (".self::fractionToPercent($numer / $denom).")</td>";
                } else {
                    $html .= "<td></td>";
                    $html .= "<td></td>";
                }
                $html .= "</tr>";
            }
            $i++;
        }
        $html .= "</table>";

        if (!empty($lines)) {
            $html .= $this->makeLineGraph($lines);
        }
        $html .= "</div>";

        return $html;
    }

    protected $pid;
}