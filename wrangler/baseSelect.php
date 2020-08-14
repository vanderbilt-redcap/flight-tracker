<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");
require_once(dirname(__FILE__)."/../Application.php");

define("IMG_SIZE", Citation::getImageSize());

require_once(dirname(__FILE__)."/css.php");
?>
    <script>
        $(document).ready(function() {
            $('#search').keydown(function(e) {
                if ((e.keyCode == 13) || (e.keyCode == 9)) {
                    var url = window.location.href;
                    var pageWithGet = url.replace(/^.+\//, "");
                    var page = pageWithGet.replace(/\?.+$/, "");
                    var name = $('#search').val();
                    search(page, '#searchDiv', name);
                }
            });
        });

        function resetCitation(id) {
            $('#'+id).val("reset");
            let resetButton = "<?= Application::link("wrangler/reset.php") ?>"
            $('#image_'+id).attr("src", resetButton);
        }

        function includeCitations(citations) {
            var splitCitations = citations.split(/\n/);
            var pmids = [];
            for (var i = 0; i < splitCitations.length; i++) {
                var citation = splitCitations[i];
                if (citation) {
                    var pmid = getPMID(citation);
                    if (pmid) {
                        pmids.push(pmid);
                    }
                }
            }
            if (pmids.length > 0) {
                presentScreen("Saving...");
                $.post("<?= Application::link("wrangler/savePubs.php") ?>", { record_id: '<?= $_GET['record'] ?>', pmids: pmids }, function(data) {
                    console.log("saveComplete "+JSON.stringify(data));
                    clearScreen();
                    if (data['error']) {
                        makeNote(data['error']);
                    } else if (data['errors']) {
                        makeNote(data['errors']);
                    } else {
                        makeNote();
                    }
                });
            } else {
                alert("Please specify a citation!");
            }
        }

        function includeCitation(citation) {
            if (citation) {
                var pmid = getPMID(citation);
                if (pmid) {
                    presentScreen("Saving...");
                    $.post("<?= Application::link("wrangler/savePubs.php") ?>", { record_id: '<?= $_GET['record'] ?>', pmid: pmid }, function(data) {
                        console.log("saveComplete "+JSON.stringify(data));
                        clearScreen();
                        if (data['error']) {
                            makeNote(data['error']);
                        } else if (data['errors']) {
                            makeNote(data['errors']);
                        } else {
                            makeNote();
                        }
                    });
                }
                if (pmid) {
                    id = "PMID" + pmid;
                } else {
                    id = "ID" + maxid;
                }

                var js = "if ($(this).attr(\"src\") == \"checked.png\") { $(\"#"+id+"\").val(\"exclude\"); $(this).attr(\"src\", \"unchecked.png\"); } else { $(\"#"+id+"\").val(\"include\"); $(this).attr(\"src\", \"checked.png\"); }";

                $("#manualCitation").val("");
            } else {
                alert("Please specify a citation!");
            }
        }
    </script>
<?php

function getSearch() {
	return Publications::getSearch();
}

function getSelectRecord() {
	return Publications::getSelectRecord();
}
