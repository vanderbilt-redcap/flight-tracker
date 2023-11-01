<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class LineGraph extends Chart {
    public function __construct($cols, $labels, $id) {
        $this->cols = $cols;
        $this->labels = $labels;
        $this->id = REDCapManagement::makeHTMLId($id);
    }

    public function setColor($color) {
        $this->color = Chart::ensureHex($color) ?: $this->color;
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
        if (empty($this->labels) || empty($this->cols)) {
            return "";
        }
        $tension = "0.1";
        $saveDiv = REDCapManagement::makeSaveDiv("canvas", $atBottomOfPage);
        $html = "";
        $html .= "<div style='margin: 0 auto; width: {$width}px; height: {$height}px;' class='chartWrapper'>";
        $html .= "<canvas id='{$this->id}'></canvas>";
        $html .= "<script>
    const {$this->id}"."_ctx = document.getElementById('{$this->id}').getContext('2d');

const {$this->id}"."_chart = new Chart({$this->id}"."_ctx, {
    type: 'line',
    data: {
      labels: ".json_encode($this->labels).",
      datasets: [{
        label: '',
        data: ".json_encode($this->cols).",
        borderColor: '{$this->color}',
        fill: false,
        tension: $tension
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
                borderColor: '{$dataset['color']}',
                tension: $tension,
                fill: false,
            }";
        }
        $html .= "]
    },
    options: {
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
    protected $additionalDatasets = [];
}