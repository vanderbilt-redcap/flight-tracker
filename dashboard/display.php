<style>
td.measurement,td.dateMeasurement,td.moneyMeasurement { vertical-align: bottom; border-radius: 10px; background-color: #dddddd; padding: 10px; width: 225px; box-shadow: 2px 2px 5px #444444; }
td.spacer { width: 20px; background-color: transparent; }
td.verticalSpacer { height: 20px; background-color: white; }
.measurementHeader,.measurementNumber,.measurementDenominator,.measurementDate,.measurementMoney { text-align: center; }
.measurementHeader { font-size: 24px; color: #888888; padding-bottom: 8px; }
.measurementHeaderSmall { font-size: 18px; }
.measurementNumber { font-size: 80px; }
.measurementDate { font-size: 40px; }
.measurementMoney { font-size: 30px; }
.measurementNumber,.measurementDate,.measurementMoney { font-weight: bold; color: #5f0000; text-shadow: 2px 2px 5px #444444; }
.measurementDenominator { font-size: 16px; color: #888888; padding-top: 8px; }
.animationProgress { text-align: center; width: 100%; height: 8px; -webkit-appearance: none; appearance: none; }
progress[value]::-webkit-progress-value { background-image: -webkit-linear-gradient(-45deg, transparent 66%, rgba(0, 0, 0, .1) 66%, rgba(0, 0, 0, .1) 66%, transparent 66%), -webkit-linear-gradient(top, rgba(255, 255, 255, .25), rgba(0, 0, 0, .25)), -webkit-linear-gradient(left, #998800, #5f0000); background-size: 35px 20px, 100% 100%, 100% 100%; borrder-radius: 4px; }
progress[value]::-webkit-progress-bar { background-color: #cccccc; border-radius: 4px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.25) inset; }
h1 { font-size: 60px; margin-top: 20px; margin-bottom: 20px; }
td.header { padding: 5px; }
</style>

<?php

require_once(dirname(__FILE__)."/base.php");
