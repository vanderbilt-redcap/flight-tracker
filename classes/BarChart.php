<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class BarChart extends Chart {
    public function __construct($cols, $labels, $id) {
        $this->cols = $cols;
        $this->labels = $labels;
        $this->id = REDCapManagement::makeHTMLId($id);
        $this->displayLegend = FALSE;
    }

    public function isCategoricalData() {
        $isAllNumeric = TRUE;
        foreach ($this->labels as $i => $label) {
            if (!is_numeric($label)) {
                $isAllNumeric = FALSE;
            }
        }
        return !$isAllNumeric;
    }

    public function showLegend($b) {
        $this->displayLegend = $b;
    }

    public function setColor($color) {
        if ((strlen($color) == 6) && preg_match("/^[0-9A-Fa-f]{6}$/", $color)) {
            $this->color = "#".$color;
        } else {
            $this->color = $color;
        }
    }

    public function setXAxisLabel($str) {
        $this->xAxisLabel = $str;
    }

    public function setYAxisLabel($str) {
        $this->yAxisLabel = $str;
    }

    public function getJSLocations() {
        return [Application::link("js/Chart.min.js")];
    }

    public function getCSSLocations() {
        return [];
    }

    public function addDataset($colsWithLabels, $color, $title) {
        $this->additionalDatasets[] = ["data" => $colsWithLabels, "color" => $color, "title" => $title];
    }

    public function getHTML($width, $height, $atBottomOfPage = FALSE) {
        $bars = count($this->cols);
        $displayLegendText = json_encode($this->displayLegend);
        if (empty($this->labels) || empty($this->cols)) {
            return "";
        }
        $saveDiv = REDCapManagement::makeSaveDiv("canvas", $atBottomOfPage);
        $html = "";
        $html .= "<div style='margin: 0 auto; width: {$width}px; height: {$height}px;' class='chartWrapper'>";
        $html .= "<canvas id='{$this->id}'></canvas>";
        $html .= "<script>
    const {$this->id}"."_ctx = document.getElementById('{$this->id}').getContext('2d');

var {$this->id}"."_chart = new Chart({$this->id}"."_ctx, {
    type: 'bar',
    data: {
      labels: ".json_encode($this->labels).",
      datasets: [{
        label: '',
        data: ".json_encode($this->cols).",
        backgroundColor: '{$this->color}',
      }";
        foreach ($this->additionalDatasets as $dataset) {
            $orderedData = [];
            foreach ($this->labels as $label) {
                $value = $dataset['data'][$label];
                if (!$value) {
                    $value = 0;
                }
                $orderedData[] = $value;
            }
            $html .= ",
            {
                label: '{$dataset['title']}',
                data: ".json_encode($orderedData).",
                backgroundColor: '{$dataset['color']}',
            }";
        }
        $html .= "]
    },
    options: {
      legend: {
        display: $displayLegendText,
      },
      scales: {";
        if ($this->xAxisLabel) {
            $html .= "
            x: {
              title: {
                display: true,
                text: '{$this->xAxisLabel}',
              }
            },";
        }
        $html .= "
        y: {
          title: {
            display: true,
            text: '{$this->yAxisLabel}',
          },
          ticks: {
            beginAtZero: true,
          }
        }
      }
    }
});
$(document).ready(function() {
    $('#{$this->id}').parent().append(\"$saveDiv\");
});
</script>";
        $html .= "</div>";
        return $html;
    }

    protected $xAxisLabel = "";
    protected $yAxisLabel = "";
    protected $cols = [];
    protected $labels = [];
    protected $color = "#d4d4eb";
    protected $id = "";
    protected $displayLegend = TRUE;
    protected $additionalDatasets = [];
}