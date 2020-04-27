<?php

use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../Application.php");

$module = Application::getModule();

?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/8.11.8/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/spin.js/2.3.2/spin.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gasparesganga-jquery-loading-overlay@2.1.6/dist/loadingoverlay.min.js" integrity="sha384-L2MNADX6uJTVkbDNELTUeRjzdfJToVbpmubYJ2C74pwn8FHtJeXa+3RYkDRX43zQ" crossorigin="anonymous"></script>
<script src="<?= Application::link("js/datatables.min.js") ?>"></script>
<link rel="stylesheet" type="text/css" href="<?= Application::link("css/datatables.min.css") ?>" />

<div id="em-log-module-wrapper">
	<?=$module->initializeJavascriptModuleObject()?>
	<script>
		var details = {}

		var showDetails = function(logId){
			var width = window.innerWidth - 100;
			var height = window.innerHeight - 200;
			var content = '<pre style="max-height: ' + height + 'px">' + details[logId] + '</pre>'

			simpleDialog(content, 'Details', null, width)
		}

		var showSyncCancellationDetails = function(){
			var div = $('#em-log-module-cancellation-details').clone()
			div.show()

			var pre = div.find('pre');

			// Replace tabs with spaces for easy copy pasting into the mysql command line interface
			pre.html(pre.html().replace(/\t/g, '    '))

			trimPreIndentation(pre[0])

			simpleDialog(div, 'Sync Cancellation', null, 1000)
		}

		var trimPreIndentation = function(pre){
			var content = pre.innerHTML
			var firstNonWhitespaceIndex = content.search(/\S/)
			var leadingWhitespace = content.substr(0, firstNonWhitespaceIndex)
			pre.innerHTML = content.replace(new RegExp(leadingWhitespace, 'g'), '');
		}
	</script>

	<style>
		#em-log-module-wrapper .top-button-container{
			margin-top: 20px;
			margin-bottom: 50px;
		}

		#em-log-module-wrapper .top-button-container button{
			margin: 3px;
			min-width: 160px;
		}

		#em-log-module-wrapper th{
			font-weight: bold;
		}

		#em-log-module-wrapper .remote-project-title{
			margin-top: 5px;
			margin-left: 15px;
			font-weight: bold;
		}

		#em-log-module-wrapper td.message{
			  max-width: 800px;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		#em-log-module-wrapper a{
			/* This currently only exists for the output of the formatURLForLogs() method. */
			text-decoration: underline;
		}

		.em-log-module-spinner{
			position: relative;
			height: 60px;
			margin-top: -10px;
		}

		.swal2-popup{
		  font-size: 14px;
		  width: 500px;
		}

		.swal2-content{
		  font-weight: 500;
		}

		#em-log-module-log-entries_wrapper{
			max-width: 900px;
			margin: 0 auto;
		}

		#em-log-module-log-entries{
			width: 100%;
		}
	</style>

	<h1>Flight Tracker Log</h1>
	<h2><?= $tokenName ?></h2>
	<p class='centered'>(Refresh the page to see the latest.)</p>

	<p class='centered'><button onclick='submitLogs("<?= Application::link("log/email-logs.php") ?>"); return false;'>Report Today's Logs to Developers</button></p>

	<table id="em-log-module-log-entries" class="table table-striped table-bordered"></table>

	<script>
		Swal = Swal.mixin({
			buttonsStyling: false,
			allowOutsideClick: false
		})

		$(function(){
			var ajaxRequest = function(args) {
				var spinnerElement = $('<div class="em-log-module-spinner"></div>')[0]
				new Spinner().spin(spinnerElement)

				Swal.fire({
					title: spinnerElement,
					text: args.loadingMessage,
					showConfirmButton: false
				})

				var startTime = Date.now()
				$.post(args.url, null, function (response) {
					var millisPassed = Date.now() - startTime
					var delay = 2000 - millisPassed
					if (delay < 0) {
						delay = 0
					}

					setTimeout(function () {
						if (response === 'success') {
							Swal.fire('', args.successMessage + '  Check this page again after about a minute to see export progress logs.')
						}
						else {
							Swal.fire('', 'An error occurred.  Please see the browser console for details.')
							console.log('External Modules Log AJAX Response:', response)
						}
					}, delay)
				})
			}
		})

		$(function(){
			$.fn.dataTable.ext.errMode = 'throw';

			var lastOverlayDisplayTime = 0
			var table = $('#em-log-module-log-entries').DataTable({
				"pageLength": 100,
		        	"processing": true,
		        	"serverSide": true,
		        	"ajax": {
					url: '<?= Application::link("log/get-logs.php") ?>'
				},
				"autoWidth": false,
				"searching": false,
				"ordering": false,
				"order": [[ 0, "desc" ]],
				"columns": [
					{
						data: 'timestamp',
						title: 'Date/Time'
					},
					{
						data: 'message',
						title: 'Message'
					},
				],
				"dom": 'Blfptip'
		    }).on( 'draw', function () {
				var ellipsis = $('.dataTables_paginate .ellipsis')
				ellipsis.addClass('paginate_button')
				ellipsis.click(function(e){
					var jumpToPage = async function(){
						const response = await Swal.fire({
							text: 'What page number would like like to jump to?',
							input: 'text',
							showCancelButton: true
						})

						var page = response.value

						var pageCount = table.page.info().pages

						if(isNaN(page) || page < 1 || page > pageCount){
							Swal.fire('', 'You must enter a page between 1 and ' + pageCount)
						}
						else{
							table.page(page-1).draw('page')
						}
					}

					jumpToPage()

					return false
				})
		    }).on( 'processing.dt', function(e, settings, processing){
		    	// if(processing){
					// $.LoadingOverlay('show')
					// lastOverlayDisplayTime = Date.now()
		    	// }
		    	// else{
		    		// var secondsSinceDisplay = Date.now() - lastOverlayDisplayTime
		    		// var delay = Math.max(300, secondsSinceDisplay)
		    		// setTimeout(function(){
						// $.LoadingOverlay('hide')
		    		// }, delay)
		    	// }
		    })

			// $.LoadingOverlaySetup({
				// 'background': 'rgba(30,30,30,0.7)'
			// })
		})
	</script>
</div>
