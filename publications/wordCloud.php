<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

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
    margin-bottom: .5rem;
}
form{width: 700px; margin:auto;    margin-bottom:2em;}
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
#chartdivcol{width: 28%; padding-right:2%; height:600px; display: inline-block;}
#chartdivcol>div{
    
}
#chartdiv {width: 70%;display:inline-block;height: 1px; background-color: #FFFFFF; }
#chartdiv>div{
    position: unset !important;

}

.tsubmit {
    color: #ffffff !important;
    background-color: #63acc2 !important;
    float: left;
    font-size: 14px !important;
    padding-bottom: 7px !important;
    margin-top: -16px;
}

.btn-light {
    color: #212529;
    background-color: #f8f9fa;
    border-color: #f8f9fa;
}
.mtitle{    font-weight: 700;
    font-size: 20px;
    margin: auto;
    width: 100%;
    text-align: center; margin-bottom:2em;}
.btn {
    display: inline-block;
    font-weight: 400;
    color: #212529;
    text-align: center;
    vertical-align: middle;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    background-color: transparent;
    border: 1px solid transparent;
    padding: .375rem .75rem;
    font-size: 1rem;
    line-height: 1.5;
    border-radius: .25rem;
    transition: color .15s ease-in-out,background-color .15s ease-in-out,border-color .15s ease-in-out,box-shadow .15s ease-in-out;
}
.bottomFooter {
    z-index: 1;
    border-top: 1px solid #efefef;
}

</style>
<?php

$possibleFields = ["citation_grants", "citation_mesh_terms", "citation_journal"];
$metadata = Download::metadata($token, $server);
if ($_POST['field'] && in_array($_POST['field'], $possibleFields)) {
    $field = REDCapManagement::sanitize($_POST['field']);
    $fields = ["record_id", "citation_include", $field];
    if ($_POST['cohort']) {
        $cohort = $_POST['cohort'];
        if ($cohort == "all") {
            $records = Download::recordIds($token, $server);
        } else {
            $records = Download::cohortRecordIds($token, $server, CareerDev::getModule(), $cohort);
        }
    } else {
        $records = Download::recordIds($token, $server);
    }
    $redcapData = Download::fieldsForRecords($token, $server, $fields, $records);
    $wordData = [];
    foreach ($redcapData as $row) {
        if ($row[$field] && ($row['citation_include'] == '1')) {
            $words = preg_split("/\s*;\s*/", $row[$field]);
            if ($field == "citation_grants") {
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
    arsort($wordData);
    echo makeFieldForm($token, $server, $metadata, $possibleFields, $_POST['cohort'] ? $_POST['cohort'] : "");
    //echo REDCapManagement::json_encode_with_spaces($wordData);
    ?>

    <script type="text/javascript">
        am4core.useTheme(am4themes_animated);
        // Themes end

        var chart = am4core.create("chartdiv", am4plugins_wordCloud.WordCloud);
        chart.fontFamily = "europa";
        var series = chart.series.push(new am4plugins_wordCloud.WordCloudSeries());
        series.randomness = 0.1;
        series.rotationThreshold = 1;

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
        $('#chartdiv').css('height','600px');
        series.dataFields.word = "tag";
        series.dataFields.value = "count";
        series.colors = new am4core.ColorSet();
        series.colors.step = 1;
        series.colors.passOptions = {};

        series.labels.template.url = "https://stackoverflow.com/questions/tagged/{word}";
        series.labels.template.urlTarget = "_blank";
        series.labels.template.tooltipText = "{word}: {value}";

        var hoverState = series.labels.template.states.create("hover");
        hoverState.properties.fill = am4core.color("#000000");

        var subtitle = chart.titles.create();
        subtitle.text = "";

        var title = chart.titles.create();
        title.text = "(WordCloud limited to the top 200 terms selected)";
        title.fontSize = 10;
        title.fontWeight = "300";
        title.fontColor = "#eeeeee";
        title.fontFamily = "europa";
        title.marginBottom = 20;
        $('.mtitle').html("<?php echo strtoupper('most popular '.str_replace('_terms','',str_replace('citation_','',$field)).' terms'); ?>");
//----

        var chartc = am4core.create("chartdivcol", am4charts.XYChart);
        chartc.fontFamily = "europa";
        chartc.fontSize = '11px';
        chartc.padding(4, 4, 4, 4);
        chartc.background.fill = '#f5f5f5';
        chartc.background.opacity = 1;

        var titlec = chartc.titles.create();
        titlec.text = "(Graph below displays the top 20 terms selected)";
        titlec.fontSize = 10;
        titlec.fontWeight = "300";
        titlec.fontColor = "#eeeeee";
        titlec.fontFamily = "europa";
        titlec.marginBottom = 20;

        var categoryAxisc = chartc.yAxes.push(new am4charts.CategoryAxis());
        categoryAxisc.renderer.grid.template.location = 0;
        categoryAxisc.dataFields.category = "tag";
        categoryAxisc.renderer.minGridDistance = 0;
        categoryAxisc.renderer.inversed = true;
        categoryAxisc.renderer.grid.template.disabled = true;

        var valueAxisc = chartc.xAxes.push(new am4charts.ValueAxis());
        valueAxisc.min = 0;

        var seriesc = chartc.series.push(new am4charts.ColumnSeries());
        seriesc.dataFields.categoryY = "tag";
        seriesc.dataFields.valueX = "count";
        seriesc.tooltipText = "{valueX.value}"
        seriesc.columns.template.strokeOpacity = 0;
        seriesc.columns.template.column.cornerRadiusBottomRight = 1;
        seriesc.columns.template.column.cornerRadiusTopRight = 1;

        var labelBulletc = seriesc.bullets.push(new am4charts.LabelBullet());
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
                    if ($tcountc == 20){
                        break; 
                    }
                }
                echo $wcc; 
            ?>
          ]


    </script>
    <?php

} else {
    echo makeFieldForm($token, $server, $metadata, $possibleFields);
}

function makeFieldForm($token, $server, $metadata, $possibleFields, $defaultCohort = "") {
    $link = Application::link("publications/wordCloud.php");
    $metadataLabels = REDCapManagement::getLabels($metadata);

    $html = "";
    $html .= "<h1>Which Field do You Want to Count Frequency with Publications?</h1>\n";
    $html .= "<form action='$link' method='POST'>";
    $html .= Application::generateCSRFTokenHTML();
    $cohorts = new Cohorts($token, $server, CareerDev::getModule());
    $html .= "<p class='centered'><div class='form-group'>".$cohorts->makeCohortSelectUI($defaultCohort)."</div> <div class='form-group'><label for='field'>Field:</label><select name='field' id='field' class='form-control'><option value=''>---SELECT---</option>";
    foreach ($possibleFields as $field) {
        $label = $metadataLabels[$field];
        $selected = "";
        if ($field == $_POST['field']) {
            $selected = " selected";
        }
        $html .= "<option value='$field'$selected>$label</option>";
    }
    $html .= "</select></div> <div class='form-group' style='width: 200px;'><label for='fields'></label><button class='btn btn-light tsubmit'>Make Word Cloud</button></div></p>";
    $html .= "</form><div class='mtitle'></div><div style='display:inline; width:100%;'><div id='chartdivcol'></div><div id='chartdiv'></div>";
    return $html;
}
