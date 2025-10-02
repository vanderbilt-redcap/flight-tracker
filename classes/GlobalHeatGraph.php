<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class GlobalHeatGraph extends Chart
{
	# $chartData is an associative array, which each entry in the form of COUNTRY => MARKER_TEXT
	public function __construct($name, $chartData, $pid = "") {
		$this->chartData = $chartData;
		$this->name = $name;
		$this->pid = $pid;
		$this->colorMin = "#D5D5DE";
		$this->colorMax = "#3F487B";
		$this->homeLatitude = false;
		$this->homeLongitude = false;
		$this->legendTitle = "";
		$this->showLabels = true;
	}

	public function setShowLabels($b) {
		$this->showLabels = $b;
	}

	public function setHeatColors($hexColorMin, $hexColorMax) {
		$this->colorMin = Chart::ensureHex($hexColorMin) ?: $this->colorMin;
		$this->colorMax = Chart::ensureHex($hexColorMax) ?: $this->colorMax;
	}

	public function setHomeCoords($latitude, $longitude) {
		$this->homeLatitude = $latitude;
		$this->homeLongitude = $longitude;
	}

	public function getJSLocations() {
		$urls = [
			Application::link("js/amcharts4/core.js"),
			Application::link("js/amcharts4/maps.js"),
			Application::link("js/amcharts4/worldLow.js"),
		];
		return $urls;
	}

	public function getCSSLocations() {
		return [];
	}

	public function setLegendTitle($title) {
		$this->legendTitle = $title;
	}

	public function getHTML($width, $height) {
		$html = "";
		$saveDiv = REDCapManagement::makeSaveDiv("svg");

		$imageData = [];
		$dataByCountryCode = [];
		$numDecimals = 4;
		foreach ($this->chartData as $country => $value) {
			$coords = Publications::getCountryCoordinate($country);
			if (!empty($coords)) {
				$imageData[] = [
					"latitude" => round($coords[0], $numDecimals),
					"longitude" => round($coords[1], $numDecimals),
					"title" => "$value",
				];
			}
			$countryCode = Publications::getCountryCode($country);
			if ($countryCode) {
				if (!isset($dataByCountryCode[$countryCode])) {
					$dataByCountryCode[$countryCode] = 0;
				}
				$dataByCountryCode[$countryCode] += $value;
			}
		}
		$polygonData = [];
		foreach ($dataByCountryCode as $countryCode => $value) {
			if (is_string($value)) {
				$value = number_format((float) $value, $numDecimals, ".", "");
			}
			$polygonData[] = [
				"id" => $countryCode,
				"value" => $value,
			];
		}

		$polygonJSON = json_encode($polygonData);
		$imageJSON = json_encode($imageData);

		$labelJS = "";
		if ($this->showLabels) {
			$labelJS = "
        let text = imageSeriesTemplate.createChild(am4core.Label);
        text.verticalCenter = 'middle';
        text.horizontalCenter = 'middle';
        text.nonScaling = true;
        text.interactionsEnabled = false;
        text.zIndex = 100;
        text.fontSize = 11;
        text.text = '[bold]{title}[/]';
";
		}

		$arcJS = "";
		if (($this->homeLongitude !== false) && ($this->homeLatitude !== false) && !empty($imageData)) {
			$countryJSLines = [];

			foreach ($imageData as $i => $line) {
				$lat = $line['latitude'];
				$long = $line['longitude'];
				$countryJSLines[] = "let country_$i = imageSeries.mapImages.create();
                country_$i.latitude = $lat;
                country_$i.longitude = $long;
                let countryLine_$i = lineSeries.mapLines.create();
                countryLine_$i.imagesToConnect = [home, country_$i];
                ";
			}

			$arcJS = "
            let lineSeries = map.series.push(new am4maps.MapLineSeries());
            lineSeries.mapLines.template.line.stroke = am4core.color('#888888');
            lineSeries.mapLines.template.line.strokeOpacity = 0.5;
            lineSeries.mapLines.template.line.strokeWidth = 1;
            lineSeries.mapLines.template.shortestDistance = true;

            let home = imageSeries.mapImages.create();
            home.latitude = {$this->homeLatitude};
            home.longitude = {$this->homeLongitude};
            ".implode("\n", $countryJSLines)."\n";

		}

		$html .= "
<div id='{$this->name}' class='centered' style='width: {$width}px; height: {$height}px; background-color: white;'></div>

<script>
    $(document).ready(function() {
        let map = am4core.create('{$this->name}', am4maps.MapChart);
        map.geodata = am4geodata_worldLow;
        map.projection = new am4maps.projections.Miller();
        map.seriesContainer.draggable = false;
        map.seriesContainer.resizeable = false;
        map.maxZoomLevel = 1;
        map.seriesContainer.events.disableType('doublehit');
        map.chartContainer.background.events.disableType('doublehit');
        
        let polygonSeries = map.series.push(new am4maps.MapPolygonSeries());
        polygonSeries.useGeodata = true;
        polygonSeries.exclude = ['AQ'];
        polygonSeries.heatRules.push({
          property: 'fill',
          target: polygonSeries.mapPolygons.template,
          min: am4core.color('{$this->colorMin}'),
          max: am4core.color('{$this->colorMax}')
        });
        let polygonTemplate = polygonSeries.mapPolygons.template;
        polygonTemplate.fill = am4core.color('#EEEEEE');
        
        let heatLegend = map.chartContainer.createChild(am4maps.HeatLegend);
        heatLegend.valign = 'bottom';
        heatLegend.align = 'left';
        heatLegend.width = am4core.percent(100);
        heatLegend.series = polygonSeries;
        heatLegend.orientation = 'horizontal';
        heatLegend.padding(20, 20, 0, 20);
        heatLegend.valueAxis.renderer.labels.template.fontSize = 10;
        heatLegend.valueAxis.renderer.minGridDistance = 40;
        heatLegend.valueAxis.dy = -15;
        heatLegend.valueAxis.title.text = '{$this->legendTitle}';
        heatLegend.valueAxis.title.align = 'center';
        heatLegend.valueAxis.title.valign = 'top';
        heatLegend.valueAxis.title.dy = -30;

        let imageSeries = map.series.push(new am4maps.MapImageSeries());
        let imageSeriesTemplate = imageSeries.mapImages.template;
        $labelJS
        imageSeriesTemplate.propertyFields.latitude = 'latitude';
        imageSeriesTemplate.propertyFields.longitude = 'longitude';

        $arcJS
        
        polygonSeries.data = $polygonJSON;
        imageSeries.data = $imageJSON;

        $('#{$this->name}').append(\"$saveDiv\");
        $('#{$this->name}>div>svg').attr('width', '$width').attr('height', '$height');
    });
</script>";
		return $html;
	}

	protected $pid = "";
	protected $name = "";
	protected $chartData = [];
	protected $colorMin = "";
	protected $colorMax = "";
	protected $homeLatitude = false;
	protected $homeLongitude = false;
	protected $legendTitle = "";
	protected $showLabels;
}
