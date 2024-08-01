<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class LineBarGraph extends Chart {
    const LINE_ORDER = 50;

    public function __construct($lineLabel, $lineCols, $barLabel, $barCols, $labels, $id) {
        $this->lineLabel = $lineLabel;
        $this->lineAxisLabel = $lineLabel;
        $this->lineCols = $lineCols;
        $this->barLabel = $barLabel;
        $this->barAxisLabel = $barLabel;
        $this->barCols = $barCols;
        $this->labels = $labels;
        $this->id = REDCapManagement::makeHTMLId($id);
        $this->displayLegend = FALSE;
        $this->varName = $this->id."_".REDCapManagement::makeHash(8);
    }

    public function setColors($lineColor, $barColor) {
        $this->lineColor = Chart::ensureHex($lineColor) ?: $this->lineColor;
        $this->barColor = Chart::ensureHex($barColor) ?: $this->barColor;
    }

    public function setXAxisLabel($str) {
        $this->xAxisLabel = $str;
    }

    public function setLineAxisLabel($str) {
        $this->lineAxisLabel = $str;
    }

    public function showLegend($b) {
        $this->displayLegend = $b;
    }

    public function getJSLocations() {
        return [Application::link("js/chart.min.js")];
    }

    public function getCSSLocations() {
        return [];
    }

    public function addNewLine($label, $cols, $color) {
        if (!empty($cols)) {
            $adjustedColor = Chart::ensureHex($color) ?: $this->lineColor;
            $this->additionalLines[] = [
                "label" => $label,
                "data" => $cols,
                "borderColor" => $adjustedColor,
                "backgroundColor" => $adjustedColor,
                "yAxisID" => 'y_line',
                "order" => self::LINE_ORDER - count($this->additionalLines)
            ];
        }
    }

    public function getHTML($width, $height, $atBottomOfPage = FALSE) {
        $displayLegendText = json_encode($this->displayLegend);
        if (empty($this->labels) || empty($this->lineCols) || empty($this->barCols)) {
            return "";
        }
        $saveDiv = REDCapManagement::makeSaveDiv("canvas", $atBottomOfPage);

        $additionalDatasetJSON = "";
        foreach ($this->additionalLines as $line) {
            $additionalDatasetJSON .= ", ".json_encode($line);
        }
        $labelJSON = json_encode($this->labels);
        $barJSON = json_encode($this->barCols);
        $lineJSON = json_encode($this->lineCols);
        $barOrder = self::LINE_ORDER + 1;
        $lineOrder = self::LINE_ORDER;

        $xAxisJSON = "";
        if ($this->xAxisLabel) {
            $xAxisJSON = "
            x: {
              title: {
                display: true,
                text: '{$this->xAxisLabel}',
              }
            },";
        }

        $html = "";
        $html .= "<div style='margin: 0 auto; width: {$width}px; height: {$height}px;' class='chartWrapper'>";
        $html .= "<canvas id='{$this->id}'></canvas>";
        $html .= "<script>
    const {$this->varName} = document.getElementById('{$this->id}').getContext('2d');

    let {$this->varName}_chart = new Chart({$this->varName}, {
        type: 'line',
        data: {
          labels: $labelJSON,
          datasets: [
            {
              label: '{$this->barLabel}',
              data: $barJSON,
              borderColor: '{$this->barColor}',
              backgroundColor: '{$this->barColor}',
              type: 'bar',
              yAxisID: 'y_bar',
              order: $barOrder
            },
            {
              label: '{$this->lineLabel}',
              data: $lineJSON,
              borderColor: '{$this->lineColor}',
              backgroundColor: '{$this->lineColor}',
              yAxisID: 'y_line',
              order: $lineOrder
            }
            $additionalDatasetJSON
          ]
        },
        options: {
          responsive: true,
          plugins: {
            legend: {
                display: $displayLegendText,
                position: 'top'
            },
            title: {
                display: false
            }
          },
          scales: {
            $xAxisJSON
            y_bar: {
              type: 'linear',
              position: 'left',
              title: {
                display: true,
                text: '{$this->barAxisLabel}',
              },
              ticks: {
                beginAtZero: true,
              },
            },
            y_line: {
              type: 'linear',
              position: 'right',
              title: {
                display: true,
                text: '{$this->lineAxisLabel}',
              },
              ticks: {
                beginAtZero: true,
              },
              grid: {
                drawOnChartArea: false, // only want the grid lines for one axis to show up
              },
            }
          }
        }
    });
    $(document).ready(function() {
        $('#{$this->id}').parent().append(\"$saveDiv\");
    });
</script>";
        return $html;
    }

    protected $additionalLines = [];
    protected $lineLabel = "";
    protected $barLabel = "";
    protected $xAxisLabel = "";
    protected $yAxisLabel = "";
    protected $barAxisLabel = "";
    protected $lineAxisLabel = "";
    protected $lineCols = [];
    protected $barCols = [];
    protected $labels = [];
    protected $lineColor = "#5764ae";
    protected $barColor = "#d4d4eb";
    protected $id = "";
    protected $varName = "";
    protected $displayLegend = FALSE;
}