function showTimeline(recordId) {
	$(".timeline").hide();

	var iframeId = "timeline_"+recordId;
	$("#"+iframeId).attr("src", "timeline.php?pid=<?= $pid ?>&record="+recordId);
	$("#"+iframeId).show();
}
