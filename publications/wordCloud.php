<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\NameMatcher;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

?>
    <script src="https://cdn.amcharts.com/lib/4/core.js"></script>
    <script src="https://cdn.amcharts.com/lib/4/charts.js"></script>
    <script src="https://cdn.amcharts.com/lib/4/plugins/wordCloud.js"></script>
    <script src="https://cdn.amcharts.com/lib/4/themes/animated.js"></script>
    <link rel="stylesheet" href="<?= CareerDev::link("/css/typekit.css").CareerDev::getVersion() ?>">
    <style type="text/css">
form label {
    font-size: 12px;
    font-weight: 500;
    letter-spacing: 0px;
    color: #000000;
}
label {
    display: inline-block;
    margin-top: .5rem;
}
form{width: 700px; margin:auto; margin-bottom:2em;}
.form-control {
    display: block;
    width: 200px;
    height: calc(1.5em + .75rem + 2px);
    padding: .375rem .75rem;
    font-size: 1rem;
    font-weight: 400;
    line-height: 1.5;
    color: #495057;
    background-color: #fff;
    background-clip: padding-box;
    border: 1px solid #ced4da;
    border-radius: .25rem;
    transition: border-color .15s ease-in-out,box-shadow .15s ease-in-out;
}

form input, form select {
    font-size: 14px !important;
    font-weight: 200 !important;
    letter-spacing: 0px;
    color: #000000;
}
.form-group{width: 33%;display: inline-block;}
#chartdivcol{width: 270px; margin-right:30px; height:600px; display: inline-block;}
#chartdivcol>div{
    
}
#chartdiv {width: 900px;display:inline-block;height: 1px; background-color: #FFFFFF; }
#chartdiv>div{
    position: unset !important;

}

.form-group button.tsubmit {
    margin-top: -100px;
}

button.tsubmit {
    color: #ffffff !important;
    background-color: #63acc2 !important;
    font-size: 14px !important;
    padding: .375rem .75rem 7px .75rem !important;
    display: inline-block;
    font-weight: 400;
    text-align: center;
    vertical-align: middle;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    border: 1px solid transparent;
    line-height: 1.5;
    border-radius: .25rem;
    transition: color .15s ease-in-out,background-color .15s ease-in-out,border-color .15s ease-in-out,box-shadow .15s ease-in-out;
}
.bottomFooter {
    z-index: 1;
    border-top: 1px solid #efefef;
}

select[multiple] {
    padding-right: 0;
    height: 7rem;
    width: 100%;
}

</style>
<?php

$possibleFields = ["citation_grants", "citation_mesh_terms", "citation_journal,eric_source", "eric_subject"];
$allCitationFields = Application::$citationFields;
$metadata = Download::metadata($token, $server, $allCitationFields);
$authors = makeAuthorArray();
$subjects = is_array($_GET['terms']) ?  $_GET['terms'] : [];
if ($_GET['field'] && in_array($_GET['field'], $possibleFields)) {
    $fieldsToDisplay = explode(",", Sanitizer::sanitize($_GET['field']));
    $startDate = Publications::adjudicateStartDate($_GET['limitPubs'] ?? "", $_GET['start'] ?? "");
    $endDate = Sanitizer::sanitizeDate($_GET['end'] ?? "");
    $startTs = $startDate ? strtotime($startDate) : "";
    $endTs = $endDate ? strtotime($endDate) : "";

    $fields = array_merge(["record_id"], $fieldsToDisplay);
    $includeFields = [];
    foreach ($fieldsToDisplay as $fieldToDisplay) {
        $includeField = getIncludeField($fieldToDisplay);
        if (
            preg_match("/^citation_/", $fieldToDisplay)
            && !in_array($includeField, $includeFields)
        ) {
            $includeFields[] = $includeField;
            $includeFields[] = "citation_authors";
            if (!empty($subjects)) {
                $includeFields[] = "citation_mesh_terms";
            }
            if ($startTs) {
                $fields[] = "citation_year";
                $fields[] = "citation_month";
                $fields[] = "citation_day";
            }
        } else if (
            preg_match("/^eric_/", $fieldToDisplay)
            && !in_array($includeField, $includeFields)
        ) {
            $includeFields[] = $includeField;
            $includeFields[] = "eric_author";
            if (!empty($subjects)) {
                $includeFields[] = "eric_subject";
            }
            if ($startTs) {
                $fields[] = "eric_sourceid";
                $fields[] = "eric_publicationdateyear";
            }
        }
    }

    if (!empty($includeFields)) {
        $fields = array_unique(array_merge($fields, $includeFields));
    }

    if ($_GET['cohort']) {
        $cohort = $_GET['cohort'];
        if ($cohort == "all") {
            $records = Download::recordIds($token, $server);
        } else {
            $records = Download::cohortRecordIds($token, $server, CareerDev::getModule(), $cohort);
        }
    } else {
        $records = Download::recordIds($token, $server);
    }
    $firstNames = Download::firstnames($token, $server);
    $lastNames = Download::lastnames($token, $server);
    $redcapData = Download::fieldsForRecords($token, $server, $fields, $records);
    $wordData = [];
    $numPubs = 0;
    foreach ($redcapData as $row) {
        $recordId = $row['record_id'];
        foreach ($fieldsToDisplay as $fieldToDisplay) {
            $includeField = getIncludeField($fieldToDisplay);
            if (
                $row[$fieldToDisplay]
                && ($row[$includeField] == '1')
                && datesCheckOut($row, $startTs, $endTs)
                && authorFiltersApply($row, $authors, $firstNames[$recordId], $lastNames[$recordId])
                && subjectTermsApply($row, $subjects)
            ) {
                $numPubs++;
                $words = preg_split("/\s*;\s*/", $row[$fieldToDisplay]);
                if ($fieldToDisplay == "citation_grants") {
                    for ($i = 0; $i < count($words); $i++) {
                        $words[$i] = str_replace(" ", "", $words[$i]);
                    }
                }
                foreach($words as $word) {
                    if ($word) {
                        if (!isset($wordData[$word])) {
                            $wordData[$word] = 0;
                        }
                        $wordData[$word]++;
                    }
                }
            }
        }
    }
    arsort($wordData);

    $numTerms = 200;
    $barChartTerms = 20;
    $accuracy = 5;
    $randomness = 0.1;
    $rotationThreshold = 1;
    if (in_array("citation_grants", $fieldsToDisplay)) {
        $wordData = transformToTitles($wordData);
    }

    echo makeFieldForm($token, $server, $metadata, $possibleFields, $subjects, $authors, $_GET['cohort'] ?: "", $startDate, $endDate);
    echo "<br/><br/><br/>";
    echo "<h2>Frequency Table</h2>";
    echo "<table class='centered bordered max-width'><thead><tr>";
    echo "<th style='width: 150px;'>Term</th>";
    echo "<th>Frequency</th>";
    echo "</tr></thead><tbody>";
    foreach ($wordData as $term => $frequency) {
        echo "<tr><td>$term</td><td>".REDCapManagement::pretty($frequency)."</td></tr>";
    }
    echo "</tbody></table>";
    echo "<br/><br/>";
    ?>

    <script type="text/javascript">
        $(document).ready(() => {
            am4core.useTheme(am4themes_animated);
            // Themes end

            const chart = am4core.create("chartdiv", am4plugins_wordCloud.WordCloud);
            chart.fontFamily = "europa";
            const series = chart.series.push(new am4plugins_wordCloud.WordCloudSeries());
            series.randomness = <?= $randomness ?>;
            series.rotationThreshold = <?= $rotationThreshold ?>;
            series.accuracy = <?= $accuracy ?>;

            series.data = [
                <?php
                $wc = "";
                $tcount = 0;
                foreach ($wordData as $key => $value) {
                    $wc .= '{"tag":"'.$key.'","count": '.$value.'},';
                    $tcount++;
                    if ($tcount == 150){
                        break;
                    }
                }
                echo $wc;
                ?>
            ];
            $('#chartdiv').css({height:'600px', width: '900px'}).append("<?= REDCapManagement::makeSaveDiv("svg", TRUE) ?>");

            const numTerms = <?= $numTerms ?>;
            series.dataFields.word = "tag";
            series.dataFields.value = "count";

            series.colors = getFlightTrackerColorSetForAM4(numTerms);

            series.labels.template.url = "https://stackoverflow.com/questions/tagged/{word}";
            series.labels.template.urlTarget = "_blank";
            series.labels.template.tooltipText = "{word}: {value}";

            const hoverState = series.labels.template.states.create("hover");
            hoverState.properties.fill = am4core.color("#000000");

            const subtitle = chart.titles.create();
            subtitle.text = "";

            const title = chart.titles.create();
            title.text = "(WordCloud limited to the top "+numTerms+" terms selected)";
            title.fontSize = 10;
            title.fontWeight = "300";
            title.fontColor = "#eeeeee";
            title.fontFamily = "europa";
            title.marginBottom = 20;
            $('.mtitle').html("Most Popular <?= ucfirst(getMainChunk($fieldsToDisplay[0])) ?> Terms Among <?= REDCapManagement::pretty($numPubs) ?> Publications");
            //----

            const numBarTerms = <?= $barChartTerms ?>;
            const chartc = am4core.create("chartdivcol", am4charts.XYChart);
            chartc.fontFamily = "europa";
            chartc.fontSize = '11px';
            chartc.padding(4, 4, 4, 4);
            chartc.background.fill = '#f5f5f5';
            chartc.background.opacity = 1;
            chartc.colors = getFlightTrackerColorSetForAM4(numBarTerms);

            const titlec = chartc.titles.create();
            titlec.text = "(Graph below displays the top "+numBarTerms+" terms selected)";
            titlec.fontSize = 10;
            titlec.fontWeight = "300";
            titlec.fontColor = "#eeeeee";
            titlec.fontFamily = "europa";
            titlec.marginBottom = 20;

            const categoryAxisc = chartc.yAxes.push(new am4charts.CategoryAxis());
            categoryAxisc.renderer.grid.template.location = 0;
            categoryAxisc.dataFields.category = "tag";
            categoryAxisc.renderer.minGridDistance = 0;
            categoryAxisc.renderer.inversed = true;
            categoryAxisc.renderer.grid.template.disabled = true;

            const valueAxisc = chartc.xAxes.push(new am4charts.ValueAxis());
            valueAxisc.min = 0;

            const seriesc = chartc.series.push(new am4charts.ColumnSeries());
            seriesc.dataFields.categoryY = "tag";
            seriesc.dataFields.valueX = "count";
            seriesc.tooltipText = "{valueX.value}"
            seriesc.columns.template.strokeOpacity = 0;
            seriesc.columns.template.column.cornerRadiusBottomRight = 1;
            seriesc.columns.template.column.cornerRadiusTopRight = 1;

            const labelBulletc = seriesc.bullets.push(new am4charts.LabelBullet());
            labelBulletc.label.horizontalCenter = "left";
            labelBulletc.label.dx = 10;
            labelBulletc.label.truncate = false;
            labelBulletc.label.text = "{values.valueX.workingValue.formatNumber('#')}";
            labelBulletc.locationX = 1;

            seriesc.columns.template.adapter.add("fill", function(fill, target){
                return chartc.colors.getIndex(target.dataItem.index);
            });

            categoryAxisc.sortBySeries = seriesc;
            chartc.data = [
                <?php

                $wcc = "";
                $tcountc = 0;
                foreach ($wordData as $key => $value) {
                    $wcc .= '{"tag":"'.$key.'","count": '.$value.'},';
                    $tcountc++;
                    if ($tcountc == $barChartTerms){
                        break;
                    }
                }
                echo $wcc;
                ?>
            ]

            $('#chartdivcol').append("<?= REDCapManagement::makeSaveDiv("svg", TRUE) ?>");

            $('#chartdiv>div>svg').attr('width', '900').attr('height', '600');
            $('#chartdivcol>div>svg').attr('width', '270').attr('height', '600');
        });
    </script>
    <?php

} else {
    echo makeFieldForm($token, $server, $metadata, $possibleFields, $subjects, $authors);
}

function makeFieldForm($token, $server, $metadata, $possibleFields, $subjects, $authors = ["first", "middle", "last"], $defaultCohort = "", $startDate = "", $endDate = "") {
    $link = Application::link("publications/wordCloud.php");
    $linkWithoutGET = explode("?", $link)[0];
    $metadataLabels = REDCapManagement::getLabels($metadata);
    $meshTerms = Download::oneFieldWithInstances($token, $server, "citation_mesh_terms");
    $pubmedIncludes = Download::oneFieldWithInstances($token, $server, "citation_include");
    $ericSubjects = Download::oneFieldWithInstances($token, $server, "eric_subject");
    $ericIncludes = Download::oneFieldWithInstances($token, $server, "eric_include");

    $html = "";
    $html .= "<h1>Which Field Do You Want to Count Frequency with Publications?</h1>";
    if (isset($_GET['field']) && ($_GET['field'] == "")) {
        $html .= "<h4>Please select a field!</h4>";
    }
    $html .= "<form action='$linkWithoutGET' method='GET'>";
    if (isset($_GET['limitPubs'])) {
        $limitYear = Sanitizer::sanitizeInteger($_GET['limitPubs']);
        $html .= "<input type='hidden' name='limitPubs' value='$limitYear' />";
    }
    $html .= REDCapManagement::getParametersAsHiddenInputs($link);
    if (isset($_GET['limitPubs'])) {
        $limitYear = Sanitizer::sanitizeInteger($_GET['limitPubs']);
        $html .= "<input type='hidden' name='limitPubs' value='$limitYear' />";
    }
    $cohorts = new Cohorts($token, $server, CareerDev::getModule());
    $cohortHTML = $cohorts->makeCohortSelectUI($defaultCohort);
    $selectHTML = "<label for='field'>Field:</label><select name='field' id='field' class='form-control'><option value=''>---SELECT---</option>";
    foreach ($possibleFields as $field) {
        $nodes = explode(",", $field);
        if (count($nodes) > 1) {
            $label = $metadataLabels[$nodes[0]]." (PubMed and ERIC)";
        } else if (isset($metadataLabels[$field])) {
            if (preg_match("/^citation_/", $field)) {
                $source = " (PubMed)";
            } else if (preg_match("/^eric_/", $field)) {
                $source = " (ERIC)";
            } else {
                $source = "";
            }
            $label = $metadataLabels[$field].$source;
        } else {
            $label = "";
        }
        if ($label) {
            $selected = "";
            if ($field == $_GET['field']) {
                $selected = " selected";
            }
            $selectHTML .= "<option value='$field'$selected>$label</option>";
        }
    }
    $selectHTML .= "</select>";
    $datesHTML = "<label for='start'>Start Date (optional): </label><input type='date' style='font-family: europa, Arial, Helvetica, sans-serif !important;' name='start' id='start' value='$startDate' /><br/><label for='end'>End Date (optional): </label><input type='date'  style='font-family: europa, Arial, Helvetica, sans-serif !important;' name='end' id='end' value='$endDate' />";

    $html .= "<div class='centered max-width'>";
    $html .= "<div class='form-group'>$cohortHTML</div>";
    $html .= "<div class='form-group'>$selectHTML</div>";
    $html .= "<div class='form-group'>$datesHTML</div>";
    $html .= "<div class='form-group'>".makeAuthorHTML($authors)."</div>";
    if (!empty($meshTerms) || !empty($ericSubjects)) {
        $html .= "<div class='form-group'>".makeTermSelect($meshTerms, $ericSubjects, $pubmedIncludes, $ericIncludes, $subjects)."</div>";
    }
    $html .= "<div class='form-group' style='padding-left: 25px; width: 200px;'><button class='tsubmit'>Make Word Cloud</button></div>";
    $html .= "</div>";
    $html .= "</form>";
    $html .= Publications::makeLimitButton("p", "tsubmit");
    $html .= "<h2 class='mtitle'></h2><div style='width: 1260px; margin: 0 auto;'><div id='chartdivcol'></div><div id='chartdiv'></div>";
    return $html;
}

function makeTermSelect($meshTerms, $ericSubjects, $pubMedIncludes, $ericIncludes, $subjects) {
    $terms = [];
    $arrays = [
        "PubMed" => [
            "terms" => $meshTerms,
            "includes" => $pubMedIncludes,
        ],
        "ERIC" => [
            "terms" => $ericSubjects,
            "includes" => $ericIncludes,
        ],
    ];
    foreach ($arrays as $type => $ary) {
        foreach ($ary['terms'] as $recordId => $instances) {
            foreach ($instances as $instance => $instanceTermList) {
                if ($ary['includes'][$recordId][$instance] == "1") {
                    $instanceTerms = preg_split("/\s*[,;]\s*/", $instanceTermList);
                    foreach ($instanceTerms as $term) {
                        if ($term) {
                            if (!isset($terms[$term])) {
                                $terms[$term] = 0;
                            }
                            $terms[$term]++;
                        }
                    }
                }
            }
        }
    }
    arsort($terms);

    $html = "<br/>";
    $html .= "<label for='terms'>MeSH Terms &amp; ERIC Subjects:<br/>(Select multiple; selecting none means all are selected)</label>";
    $html .= "<select id='terms' name='terms[]' multiple>";
    foreach ($terms as $term => $cnt) {
        $selected = in_array($term, $subjects) ? "selected" : "";
        $html .= "<option value='$term' $selected>$term (".REDCapManagement::pretty($cnt).")</option>";
    }
    $html .= "</select>";
    $html .= "<div class='alignright smallest'><a href='javascript:;' onclick='$(\"#terms option:selected\").attr(\"selected\", false);'>clear</a></div>";

    return $html;
}

function datesCheckOut($row, $startTs, $endTs) {
    if (!$startTs) {
        return TRUE;
    }
    if (!$row['citation_year']) {
        return FALSE;
    }
    if ($row['redcap_repeat_instrument'] == "citation") {
        $year = $row['citation_year'];
        $month = $row['citation_month'] ? $row['citation_month'] : "01";
        $day = $row['citation_day'] ? $row['citation_day'] : "01";
        $date = $year."-".$month."-".$day;
    } else if ($row['redcap_repeat_instrument'] == "eric") {
        $date = Citation::getDateFromSourceID($row['eric_sourceid'], $row['eric_publicationdateyear']);
    } else {
        return FALSE;
    }
    $rowTs = strtotime($date);
    return (
        ($startTs <= $rowTs)
        && (
            !$endTs
            || ($endTs >= $rowTs)
        )
    );
}

function getIncludeField($field) {
    $nodes = explode("_", $field);
    return $nodes[0]."_include";
}

function getMainChunk($field) {
    $prefixes = ["eric_", "citation_"];
    $field = str_replace('_terms','', $field);
    foreach ($prefixes as $prefix) {
        $field = str_replace($prefix, "", $field);
    }
    return $field;
}

function transformToTitles($wordData) {
    $newWordData = [];
    foreach ($wordData as $awardNo => $cnt) {
        $activityCode = Grant::getActivityCode($awardNo);
        $instituteAbbreviation = Grant::getFundingInstituteAbbreviation($awardNo);
        if ($activityCode && $instituteAbbreviation) {
            $title = "$instituteAbbreviation $activityCode";
            if (isset($newWordData[$title])) {
                $newWordData[$title] += $cnt;
            } else {
                $newWordData[$title] = $cnt;
            }
        }
    }
    arsort($newWordData);
    return $newWordData;
}

function transformToGrantTitles($wordData, $reporter, $numTerms) {
    $newWordData = [];
    $i = 0;
    $firstAwards = [];
    foreach ($wordData as $awardNo => $cnt) {
        if ($i < $numTerms * 1.5) {
            $firstAwards[] = $awardNo;
        }
        $i++;
    }
    $translate = $reporter->getTitlesOfGrants($firstAwards);
    foreach ($wordData as $awardNo => $cnt) {
        if (isset($translate[$awardNo])) {
            $title = $translate[$awardNo];
            if (isset($newWordData[$title])) {
                $newWordData[$title] += $cnt;
            } else {
                $newWordData[$title] = $cnt;
            }
        }
    }
    arsort($newWordData);

    $i = 0;
    foreach ($newWordData as $title => $cnt) {
        if ($i >= $numTerms) {
            unset($newWordData[$title]);
        }
        $i++;
    }

    return breakTitlesIntoLines($newWordData, 50);
}

function breakTitlesIntoLines($wordData, $maxCharsPerLine) {
    $newWordData = [];
    foreach ($wordData as $title => $cnt) {
        $newTitle = wordwrap($title, $maxCharsPerLine, "\\n");
        $newTitle = preg_replace("/\"/", "\\\"", $newTitle);
        $newTitle = preg_replace("/'/", "\\'", $newTitle);
        $newWordData[$newTitle] = $cnt;
    }
    return $newWordData;
}

function makeAuthorArray() {
    $authors = [];
    if (isset($_GET['first_author']) && ($_GET['first_author'] == "on")) {
        $authors[] = "first";
    }
    if (isset($_GET['middle_author']) && ($_GET['middle_author'] == "on")) {
        $authors[] = "middle";
    }
    if (isset($_GET['last_author']) && ($_GET['last_author'] == "on")) {
        $authors[] = "last";
    }
    if (empty($authors)) {
        $authors = ["first", "middle", "last"];
    }
    return $authors;
}

function makeAuthorHTML($authors) {
    $spacer = "<br/>";
    $checked = in_array("first", $authors) ? "checked" : "";
    $html = "<label>Author Placement:</label><br/>";
    $html .= "<input type='checkbox' name='first_author' $checked id='first_author' /> <label for='first_author'>First Author</label>";
    $html .= $spacer;
    $checked = in_array("middle", $authors) ? "checked" : "";
    $html .= "<input type='checkbox' name='middle_author' $checked id='middle_author' /> <label for='middle_author'>Middle Author</label>";
    $html .= $spacer;
    $checked = in_array("last", $authors) ? "checked" : "";
    $html .= "<input type='checkbox' name='last_author' $checked id='last_author' /> <label for='last_author'>Last Author</label>";
    return $html;
}

function subjectTermsApply($row, $requestedSubjectTerms) {
    if (empty($requestedSubjectTerms)) {
        return TRUE;
    }
    if ($row['redcap_repeat_instrument'] == "eric") {
        $subjectList = $row['eric_subject'] ?? "";
    } else if ($row['redcap_repeat_instrument'] == "citation") {
        $subjectList = $row['citation_mesh_terms'] ?? "";
    } else {
        return TRUE;
    }
    if (!$subjectList) {
        return FALSE;
    }
    $subjects = preg_split("/\s*[,;]\s*/", strtolower(trim($subjectList)));
    if (empty($subjects) || REDCapManagement::isArrayBlank($subjects)) {
        return FALSE;
    }
    foreach ($requestedSubjectTerms as $requestedTerm) {
        $requestedTerm = strtolower($requestedTerm);
        if ($requestedTerm && in_array($requestedTerm, $subjects)) {
            return TRUE;
        }
    }
    return FALSE;
}

function authorFiltersApply($row, $authorTypes, $nameFirst, $nameLast) {
    if (empty($authorTypes)) {
        return FALSE;
    }
    if ($row['redcap_repeat_instrument'] == "eric") {
        $authorList = $row['eric_author'] ?? "";
    } else if ($row['redcap_repeat_instrument'] == "citation") {
        $authorList = $row['citation_authors'] ?? "";
    } else {
        return TRUE;
    }
    if (!$authorList) {
        return TRUE;
    }

    $authors = preg_split("/\s*[,;]\s*/", $authorList);
    if (empty($authors) || REDCapManagement::isArrayBlank($authors)) {
        return TRUE;
    }
    list($firstAuthorFirst, $firstAuthorLast) = NameMatcher::splitName($authors[0]);
    $matchesFirstAuthor = NameMatcher::matchByInitials($firstAuthorLast, $firstAuthorFirst, $nameLast, $nameFirst);
    $lastAuthor = $authors[count($authors) - 1];
    list($lastAuthorFirst, $lastAuthorLast) = NameMatcher::splitName($lastAuthor);
    $matchesLastAuthor = NameMatcher::matchByInitials($lastAuthorLast, $lastAuthorFirst, $nameLast, $nameFirst);
    if (in_array("first", $authorTypes) && $matchesFirstAuthor) {
        return TRUE;
    }
    if (in_array("last", $authorTypes) && $matchesLastAuthor) {
        return TRUE;
    }
    if (in_array("middle", $authorTypes) && !$matchesFirstAuthor && !$matchesLastAuthor) {
        # we know it matched because it's been included in the record's citations
        return TRUE;
    }
    return FALSE;
}