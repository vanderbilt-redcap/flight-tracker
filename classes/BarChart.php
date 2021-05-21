<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/Chart.php");

class BarChart extends Chart {
    public function __construct($cols, $labels, $name) {
        $this->cols = $cols;
        $this->labels = $labels;
        $this->name = $name;
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

    public function getHTML($width, $height) {
        $bars = count($this->cols);
        if (empty($this->labels) || empty($this->cols)) {
            return "";
        }
        $html = "";
        $html .= "<div style='margin: 0 auto; width: {$width}px; height: {$height}px;' class='chartWrapper'>";
        $html .= "<canvas id='{$this->name}'></canvas>";
        $html .= "<script>
    const {$this->name}"."_ctx = document.getElementById('{$this->name}').getContext('2d');

var {$this->name}"."_chart = new Chart({$this->name}"."_ctx, {
    type: 'bar',
    data: {
      labels: ".json_encode($this->labels).",
      datasets: [{
        label: '',
        data: ".json_encode($this->cols).",
        backgroundColor: '{$this->color}',
      }]
    },
    options: {
      legend: {
        display: false,
      },
      scales: {
        xAxes: [{
          display: false,
          barPercentage: 1,
          ticks: {
            max: $bars,
          }
        }, {
          display: true,
          scaleLabel: {
            display: true,
            labelString: '{$this->xAxisLabel}',
          },
          ticks: {
            autoSkip: true,
            beginAtZero: true,
            max: $bars,
          }
        }],
        yAxes: [{
          scaleLabel: {
            display: true,
            labelString: '{$this->yAxisLabel}',
          },
          ticks: {
            beginAtZero: true,
          }
        }]
      }
    }
});
</script>";
        $html .= "</div>";
        return $html;
    }

    protected $xAxisLabel = "";
    protected $yAxisLabel = "";
    protected $name = "";
    protected $cols = [];
    protected $labels = [];
    protected $color = "#d4d4eb";
}