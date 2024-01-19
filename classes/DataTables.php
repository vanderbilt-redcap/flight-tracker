<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class DataTables {
    public static function makeIncludeHTML() {
        $jsLink = Application::link("js/datatables.min.js");
        $cssLink = Application::link("css/datatables.min.css");

        $html = "<script src='https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/8.11.8/sweetalert2.all.min.js'></script>";
        $html .= "<script src='https://cdnjs.cloudflare.com/ajax/libs/spin.js/2.3.2/spin.min.js'></script>";
        $html .= "<script src='https://cdn.jsdelivr.net/npm/gasparesganga-jquery-loading-overlay@2.1.6/dist/loadingoverlay.min.js' integrity='sha384-L2MNADX6uJTVkbDNELTUeRjzdfJToVbpmubYJ2C74pwn8FHtJeXa+3RYkDRX43zQ' crossorigin='anonymous'></script>";
        $html .= "<script src='$jsLink'></script>";
        $html .= "<link rel='stylesheet' type='text/css' href=$cssLink'' />";
        return $html;
    }

    public static function makeMainHTML($sourceRelativeLink, $module, $columns, $ordering = FALSE, $serverSide = FALSE) {
        $sourceAbsoluteLink = Application::link($sourceRelativeLink);
        $colsJSON = json_encode($columns);
        $init = $module->initializeJavascriptModuleObject();
        if (count($columns) >= 5) {
            $wrapperStyle = "{ margin: 0 auto; }";
        } else {
            $wrapperStyle = "{ max-width: 900px; margin: 0 auto; }";
        }
        $orderingText = json_encode($ordering);
        $serverSideText = json_encode($serverSide);
        return "
<div id='em-log-module-wrapper'>
    $init
	<script>
		const details = {}

		let showDetails = function(logId){
			const width = window.innerWidth - 100;
			const height = window.innerHeight - 200;
			const content = '<pre style=\"max-height: \" + height + \"px\">' + details[logId] + '</pre>';

			simpleDialog(content, 'Details', null, width)
		}

		let showSyncCancellationDetails = function(){
			const div = $('#em-log-module-cancellation-details').clone()
			div.show()

			const pre = div.find('pre');

			// Replace tabs with spaces for easy copy pasting into the mysql command line interface
			pre.html(pre.html().replace(/\t/g, '    '))

			trimPreIndentation(pre[0])

			simpleDialog(div, 'Sync Cancellation', null, 1000)
		}

		let trimPreIndentation = function(pre){
			const content = pre.innerHTML
			const firstNonWhitespaceIndex = content.search(/\S/)
			const leadingWhitespace = content.substr(0, firstNonWhitespaceIndex)
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

		#em-log-module-log-entries_wrapper $wrapperStyle

		#em-log-module-log-entries{
			width: 100%;
		}
	</style>

    <table id='em-log-module-log-entries' class='table table_search table-striped table-bordered'></table>

	<script>
        Swal = Swal.mixin({
			buttonsStyling: false,
			allowOutsideClick: false
		})

		$(function(){
            let ajaxRequest = function(args) {
                const spinnerElement = $('<div class=\"em-log-module-spinner\"></div>')[0]
				new Spinner().spin(spinnerElement)

				Swal.fire({
					title: spinnerElement,
					text: args.loadingMessage,
					showConfirmButton: false
				})

				const startTime = Date.now()
				$.post(args.url, { 'redcap_csrf_token': getCSRFToken() }, function (response) {
                    const millisPassed = Date.now() - startTime
					let delay = 2000 - millisPassed
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

            let lastOverlayDisplayTime = 0
			let table = $('#em-log-module-log-entries').DataTable({
				'pageLength': 100,
                'processing': true,
		        'serverSide': $serverSideText,
		        'ajax': {
                    url: '$sourceAbsoluteLink'
				},
				'autoWidth': false,
				'searching': true,
				'ordering': $orderingText,
				'order': [[ 0, 'desc' ]],
				'columns': $colsJSON,
				'dom': 'Blfptip'
            }).on( 'draw', function () {
                const ellipsis = $('.dataTables_paginate .ellipsis')
                ellipsis.addClass('paginate_button')
                ellipsis.click(function (e) {
                    const jumpToPage = async function () {
                        const response = await Swal.fire({
                            text: 'What page number would like like to jump to?',
                            input: 'text',
                            showCancelButton: true
                        })

                        const page = response.value

                        const pageCount = table.page.info().pages

                        if (isNaN(page) || page < 1 || page > pageCount) {
                            Swal.fire('', 'You must enter a page between 1 and ' + pageCount)
                        } else {
                            table.page(page - 1).draw('page')
                        }
                    }

                    jumpToPage()

                    return false
                })
		    }).on( 'processing.dt', function(e, settings, processing){
                if(processing){
                    $.LoadingOverlay('show')
                    lastOverlayDisplayTime = Date.now()
                } else {
                    const secondsSinceDisplay = Date.now() - lastOverlayDisplayTime
                    const delay = Math.max(300, secondsSinceDisplay)
                    setTimeout(function(){
                        $.LoadingOverlay('hide', true)
                    }, delay)
                }
            })

			$.LoadingOverlaySetup({
				'background': 'rgba(30,30,30,0.7)'
			})

        })
        </script>";
    }
}