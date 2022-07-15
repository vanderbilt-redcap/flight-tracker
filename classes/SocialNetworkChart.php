<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class SocialNetworkChart extends Chart {
    public function __construct($name, $chartData) {
        $this->chartData = $chartData;
        $this->name = $name;
    }

    public function setNonRibbon($bool) {
        $this->isNonRibbon = $bool;
    }

    public function getJSLocations() {
        $urls = [
            Application::link("js/amcharts4/core.js"),
            Application::link("js/amcharts4/charts.js"),
            Application::link("js/amcharts4/animated.js"),
        ];
        return $urls;
    }

    public function getCSSLocations() {
        return [];
    }

    private static function hasField($chartData, $field) {
        foreach ($chartData as $row) {
            if (isset($row[$field]) && $row[$field]) {
                return TRUE;
            }
        }
        return FALSE;
    }

    private static function makeLegend($legendInfo, $width) {
        if (!empty($legendInfo)) {
            $html = "<div style='text-align: center; font-size: 12px; width: {$width}px;' class='centered'>";
            $spans = [];
            foreach ($legendInfo as $color => $label) {
                $spans[] = "<span style='height: 20px; background-color: $color;'>&nbsp;&nbsp;&nbsp;</span> $label";
            }
            $html .= implode("&nbsp;&nbsp;&nbsp;", $spans);
            $html .= "</div>";
            return $html;
        }
        return "";
    }

    public function getHTML($width, $height, $showLabels = TRUE, $legendInfo = [], $atBottomOfPage = FALSE) {
        $html = "";
        $saveDiv = REDCapManagement::makeSaveDiv("svg", $atBottomOfPage);
        $html .= self::makeLegend($legendInfo, $width);
        $html .= "
<div id='{$this->name}' class='centered' style='width: {$width}px; height: {$height}px; background-color: white;'></div>

<script>
    $(document).ready(function() {
        am4core.useTheme(am4themes_animated);
        const chart = am4core.create('{$this->name}', am4charts.ChordDiagram);
        chart.colors.saturation = 0.45;
        chart.colors.step = 3;
        const colors = {};   // used by chart
        chart.data = ".json_encode($this->chartData).";
        
        // to avoid cropping off long names
        const paddingSize = 75;
        chart.paddingTop = paddingSize;
        chart.paddingBottom = paddingSize;
        chart.paddingLeft = paddingSize;
        chart.paddingRight = paddingSize;

        chart.dataFields.fromName = 'from';
        chart.dataFields.toName = 'to';
        chart.dataFields.value = 'value';
        ";
        if (self::hasField($this->chartData, "nodeColor")) {
            $html .= "
            chart.dataFields.color = 'nodeColor';
            chart.nodes.label = { 'disabled': true };
            ";
        }
        $html .= "
        chart.nonRibbon = ".json_encode($this->isNonRibbon).";
        let link = chart.links.template;
        link.middleLine.strokeWidth = 2;
        link.middleLine.strokeOpacity = 0.4;

        chart.nodePadding = 0.5;
        chart.minNodeSize = 0.01;
        chart.startAngle = 80;
        chart.endAngle = chart.startAngle + 360;
        chart.sortBy = 'value';
        chart.fontSize = 10;

        const nodeTemplate = chart.nodes.template;
        nodeTemplate.readerTitle = 'Click to show/hide or drag to rearrange';
        nodeTemplate.showSystemTooltip = true;
        nodeTemplate.propertyFields.fill = 'color';
        nodeTemplate.tooltipText = '{name}\'s connections';  // {total}
";
        if (!$showLabels) {
            $html .= "
            nodeTemplate.label.disabled = true;
            ";
        }
        $html .= "

        // when rolled over the node, make all the links rolled-over
        nodeTemplate.events.on('over', function(event) {
            const node = event.target;
            node.outgoingDataItems.each(function(dataItem) {
                if(dataItem.toNode){
                    dataItem.link.isHover = true;
                    dataItem.toNode.label.isHover = true;
                }
            })
            node.incomingDataItems.each(function(dataItem) {
                if(dataItem.fromNode){
                    dataItem.link.isHover = true;
                    dataItem.fromNode.label.isHover = true;
                }
            })

            node.label.isHover = true;
        })

        // when rolled out from the node, make all the links rolled-out
        nodeTemplate.events.on('out', function(event) {
            const node = event.target;
            node.outgoingDataItems.each(function(dataItem) {
                if(dataItem.toNode){
                    dataItem.link.isHover = false;
                    dataItem.toNode.label.isHover = false;
                }
            })
            node.incomingDataItems.each(function(dataItem) {
                if(dataItem.fromNode){
                    dataItem.link.isHover = false;
                    dataItem.fromNode.label.isHover = false;
                }
            })

            node.label.isHover = false;
        })

        const label = nodeTemplate.label;
        label.relativeRotation = 90;

        label.fillOpacity = 0.4;
        let labelHS = label.states.create('hover');
        labelHS.properties.fillOpacity = 1;

        nodeTemplate.cursorOverStyle = am4core.MouseCursorStyle.pointer;
        nodeTemplate.adapter.add('fill', function(fill, target) {
            let node = target;
            let counters = {};
            let mainChar = false;
            node.incomingDataItems.each(function(dataItem) {
                if (dataItem.color) {
                    return dataItem.color;
                }
                if(colors[dataItem.toName]){
                    mainChar = true;
                }

                if(isNaN(counters[dataItem.fromName])){
                    counters[dataItem.fromName] = dataItem.value;
                }
                else{
                    counters[dataItem.fromName] += dataItem.value;
                }
            })
            if(mainChar){
                return fill;
            }

            let count = 0;
            let color;
            let biggest = 0;
            let biggestName;

            for(var name in counters){
                if(counters[name] > biggest){
                    biggestName = name;
                    biggest = counters[name];
                }
            }
            if(colors[biggestName]){
                fill = colors[biggestName];
            }

            return fill;
        })

        // link template
        const linkTemplate = chart.links.template;
        linkTemplate.strokeOpacity = 0;
        linkTemplate.fillOpacity = 0.15;
        linkTemplate.tooltipText = '{fromName} & {toName}:{value.value}';

        const hoverState = linkTemplate.states.create('hover');
        hoverState.properties.fillOpacity = 0.7;
        hoverState.properties.strokeOpacity = 0.7;

        const titleImage = chart.chartContainer.createChild(am4core.Image);
        titleImage.href = '';
        titleImage.x = 30
        titleImage.y = 30;
        titleImage.width = 200;
        titleImage.height = 200;
        
        $('#{$this->name}').append(\"$saveDiv\");
        $('#{$this->name}>div>svg').attr('width', '$width').attr('height', '$height');
})
</script>";
        return $html;
    }

    protected $name = "";
    protected $chartData = [];
    protected $isNonRibbon = FALSE;
}