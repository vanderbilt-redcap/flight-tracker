<?php

namespace Vanderbilt\FlightTrackerExternalModule;

# a part of a hook
# puts in colored background in reports for erroneus entries

?>
<script>
$(document).ready(function() {
	if ($('[name=\"summary_first_k_to_first_r01\"]').val() >= 5) {
		$('[name=\"summary_first_k_to_first_r01\"]').css({'background-color': '#ff8181'});
	} else if ($('[name=\"summary_first_k_to_first_r01\"]').val() < 5) {
		$('[name=\"summary_first_k_to_first_r01\"]').css({'background-color': '#fdffce'});
	} else {
		$('[name=\"summary_first_k_to_first_r01\"]').css({'background-color': 'white'});
	}

	var url = window.location.href;
	var comps = url.split(/#/);
	var field = comps[1];
	// put after the REDCap focus refresh, which starts after 10 ms; from DataEntry.js
	setTimeout(function() {
		console.log("Highlighted Field: "+field);
		$('[name='+field+']').focus();
	}, 150);
});
</script>
