<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/Chart.php");
require_once(dirname(__FILE__)."/../Application.php");

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

    public function getHTML($width, $height) {
        $html = "
<div id='{$this->name}' class='centered' style='width: {$width}px; height: {$height}px; background-color: white;'></div>

<script>
    $(document).ready(function() {
        am4core.useTheme(am4themes_animated);
        var chart = am4core.create('{$this->name}', am4charts.ChordDiagram);
        chart.colors.saturation = 0.45;
        chart.colors.step = 3;
        var colors = {
        };
        chart.data = ".json_encode($this->chartData).";

        chart.dataFields.fromName = 'from';
        chart.dataFields.toName = 'to';
        chart.dataFields.value = 'value';

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

        var nodeTemplate = chart.nodes.template;
        nodeTemplate.readerTitle = 'Click to show/hide or drag to rearrange';
        nodeTemplate.showSystemTooltip = true;
        nodeTemplate.propertyFields.fill = 'color';
        nodeTemplate.tooltipText = '{name}\'s connections';  // {total}

        // when rolled over the node, make all the links rolled-over
        nodeTemplate.events.on('over', function(event) {
            var node = event.target;
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
            var node = event.target;
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

        var label = nodeTemplate.label;
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
        var linkTemplate = chart.links.template;
        linkTemplate.strokeOpacity = 0;
        linkTemplate.fillOpacity = 0.15;
        linkTemplate.tooltipText = '{fromName} & {toName}:{value.value}';

        var hoverState = linkTemplate.states.create('hover');
        hoverState.properties.fillOpacity = 0.7;
        hoverState.properties.strokeOpacity = 0.7;

        var titleImage = chart.chartContainer.createChild(am4core.Image);
        titleImage.href = '';
        titleImage.x = 30
        titleImage.y = 30;
        titleImage.width = 200;
        titleImage.height = 200;
    })
</script>";
        return $html;
    }

    protected $name = "";
    protected $chartData = [];
    protected $isNonRibbon = FALSE;
}