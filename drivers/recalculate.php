<?php

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$pid = $_GET['pid'];

echo "<script src='https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js'></script>";
?>
<script>
$(document).ready(function() {
	startTime();

	function startTime() {
		var today = new Date();
		var h = today.getHours();
		var m = today.getMinutes();
		var s = today.getSeconds();
		m = checkTime(m);
		s = checkTime(s);
		$('#time').html(h+":"+m+":"+s);
		var t = setTimeout(startTime, 500);
	}
	function checkTime(i) {
		if (i < 10) { i = "0" + i };
		return i;
	}
});
</script> 
<?php
echo "<h1>Launch CareerDev Recalculate Script</h1>";
echo "<p><a href='".Links::getServer()."/plugins/career_dev/drivers/6b_makeSummary.php?pid=".$pid."'>Launch Recalculate Script Now!</a></p>";
echo "<p>Do not close your browser or the recalculate script will stop!</p>";
echo "<p>Also, do not double-click the link!</p>";
echo "<div id='time'></div>";
