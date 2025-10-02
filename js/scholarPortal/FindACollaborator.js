$(document).ready(function() {
    const module = ExternalModules.Vanderbilt.FlightTrackerExternalModule;

    // Use jQuery event binding for the search form
    $('#searchForm').on('submit', function(e) {
        e.preventDefault();
        $('#resultsTable').hide();
        $('#downloadCsvBtn').hide();
        $('#loadingSpinner').show();
        module.ajax('ajaxRouter', {ajaxAction: 'getFlightTrackerPids'}).then(function (response) {
            console.dir(response);
            // Initialize progress UI
            $('#searchStatusText').data('currentPidSearching', 1);
            $('#searchStatusText').text(`Searching FlightTracker Project 1 of ${response.length}`);

            // Process pids sequentially so we can send accumulated names from prior responses
            (async function processPidsSequentially(pids) {
                const accumulatedNames = new Set();
                const totalPids = pids.length;

                for (let index = 0; index < totalPids; index++) {
                    const pid = pids[index];
                    const currentPid = index + 1;

                    try {
                        // Send previously collected names along with the current pid
                        const payload = {
                            ajaxAction: 'collaboratorBasicSearch',
                            searchString: $('#mainSearch').val(),
                            pids: [pid],
                            names: Array.from(accumulatedNames)
                        };

                        const resp = await module.ajax('ajaxRouter', payload);
                        console.dir(resp);

                        // Update progress UI
                        // increment stored counter (keeps previous behavior)
                        $('#searchStatusText').data('currentPidSearching', $('#searchStatusText').data('currentPidSearching') + 1);
                        $('#searchStatusText').text(`Searching FlightTracker Project ${$('#searchStatusText').data('currentPidSearching')} of ${totalPids}`);

                        // If matches found, show results and add rows; also collect names from this response
                        if (resp.matchesFound > 0) {
                            $('#resultsTable').show();
                            $('#downloadCsvBtn').show();
                            for (const key in resp.searchResults) {
                                const resultObj = resp.searchResults[key];
                                // resultObj is a collection of entries; iterate its members to collect names
                                if (resultObj) {
                                    for (const entryKey in resultObj) {
                                        const entry = resultObj[entryKey];
                                        if (entry && entry.name) {
                                            accumulatedNames.add(entry.name);
                                        }
                                    }
                                }
                                addDataToResultsTable(resultObj);
                            }
                        }

                        updateSearchStats(resp);

                        // If last pid, hide spinner and status text
                        if (currentPid === totalPids) {
                            $('#loadingSpinner').hide();
                            $('#searchStatusText').hide();
                        }
                    } catch (err) {
                        console.log('Error in ajaxRouter for pid', pid, err);
                        // continue to next pid even on error
                        if (index === totalPids - 1) {
                            $('#loadingSpinner').hide();
                            $('#searchStatusText').hide();
                        }
                    }
                }
            })(response);
        }).catch(function (err) {
            console.log(err)
        })
    });

    // Use jQuery to bind change handlers to toggle-col checkboxes
    $('.toggle-col').each(function() {
        $(this).on('change', function() {
            const colClass = $(this).attr('data-col');
            const show = $(this).prop('checked');
            // Toggle display of column cells using jQuery
            $('.' + colClass).each(function() {
                $(this).css('display', show ? '' : 'none');
            });
            // Toggle the active class on the associated label (if present) instead of the checkbox itself
            const checkboxId = $(this).attr('id');
            if (checkboxId) {
                const label = $('label[for="' + checkboxId + '"]');
                if (label.length) {
                    label.toggleClass('active', show);
                }
            }
        });
    });

    // Update CSV export to only include visible columns (use jQuery traversal)
    $('#downloadCsvBtn').on('click', function() {
        const $table = $('#resultsTable table');
        let csv = [];
        // Get visible column indexes from the first row
        const visibleIndexes = [];
        const $firstRowCells = $table.find('tr').first().children();
        $firstRowCells.each(function(i, cell) {
            if ($(cell).css('display') !== 'none') {
                visibleIndexes.push(i);
            }
        });

        // Iterate rows and collect visible cells
        $table.find('tr').each(function() {
            let rowData = [];
            $(this).children().each(function(i, cell) {
                if (visibleIndexes.indexOf(i) !== -1) {
                    let text = $(cell).html().replace(/<br\s*\/?>/gi, '\n').replace(/"/g, '""');
                    rowData.push('"' + text.replace(/<[^>]*>?/gm, '') + '"');
                }
            });
            csv.push(rowData.join(','));
        });

        const csvContent = csv.join('\r\n');
        const blob = new Blob([csvContent], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);

        // Create anchor using jQuery, trigger download, then remove
        const $a = $('<a>').attr({ href: url, download: 'results.csv' }).appendTo('body');
        $a[0].click();
        $a.remove();
        URL.revokeObjectURL(url);
    });
})


function addDataToResultsTable(resultRow) {
    const table = $('#resultsTable table');
    const newRow = $('<tr></tr>');
    let name = '';
    let email = '';
    let mostRecentPublication = '';
    let publicationIdList = [];
    let publicationCount = 0;
    let publicationLinks = '';
    let academicRank, department, degrees, has_k, has_r = '';
    let latestPublicationDate = new Date(0);
    let score = 0;
    for (const result in resultRow) {
        let publicationDate = new Date(resultRow[result].citation_date);
        name = resultRow[result].name || '';
        email = resultRow[result].email || '';
        publicationIdList.push(resultRow[result].pmid);
        publicationCount++;
        academicRank = resultRow[result].academic_rank || '';
        department = resultRow[result].department || '';
        degrees = resultRow[result].degrees || '';
        has_k = resultRow[result].has_k ? 'Yes' : 'No';
        has_r = resultRow[result].has_r ? 'Yes' : 'No';
        score = Math.max(resultRow[result].score, score);
        latestPublicationDate = publicationDate > latestPublicationDate ? publicationDate : latestPublicationDate;
    }
    let publicationLink = new URL('https://pubmed.ncbi.nlm.nih.gov');
    publicationLink.searchParams.set('term', publicationIdList.join(','));
    $(newRow).data('score', score);

    $(newRow).append(
        `<td>${score}</td>
         <td>${name}</td>
         <td><a href="mailto:${email}">${email}</a></td>
         <td><a href="${publicationLink.href}" target="_blank">${publicationCount} Publications matched</a></td>
         <td>${latestPublicationDate.toLocaleString('en-US', {month: 'long', day: 'numeric', year: 'numeric'})}</td>
         <td class="academic-rank" style="display: none">${academicRank}</td>
         <td class="department" style="display: none">${department}</td>
         <td class="degrees" style="display: none">${degrees}</td>
         <td class="has-k" style="display: none">${has_k}</td>
         <td class="has-r" style="display: none">${has_r}</td>`
    );
    if ($(table).find('tr').length === 1) {
        $(table).append(newRow);
    } else {
        $(table).find('tr:not(.table-head)').each(function(index) {
            const existingScore = $(this).data('score') || 0;
            if (existingScore < score) {
                $(this).before(newRow);
                return false; // Break out of the each loop
            } else if ($(table).find('tr:not(.table-head)').length === index + 1) {
                $(table).append(newRow);
            }
        })
    }
}

function updateSearchStats(reponse) {
    $('#searchStats').show();
    let totalRecordsSearched = $('#searchStats').data('totalRecordsSearched') || 0;
    totalRecordsSearched = totalRecordsSearched + reponse.totalRecordsSearched;
    let matchesFound = $('#searchStats').data('matchesFound') || 0;
    matchesFound = matchesFound + reponse.matchesFound;
    let publicationsSearched = $('#searchStats').data('totalPublicationsSearched') || 0;
    publicationsSearched = publicationsSearched + reponse.totalPublicationsSearched;
    let totalSearchTime = parseFloat($('#searchStats').data('totalSearchTime')) || 0;
    totalSearchTime = totalSearchTime + parseFloat(reponse.totalDataRetrievalTime) + parseFloat(reponse.totalRecordProcessingTime);
    $('#searchStats').data('totalRecordsSearched', totalRecordsSearched);
    $('#searchStats').data('matchesFound', matchesFound);
    $('#searchStats').data('totalPublicationsSearched', publicationsSearched);
    $('#searchStats').data('totalSearchTime', totalSearchTime.toFixed(2));
    $('#searchStats').text(`Searched ${totalRecordsSearched} Scholars, ${publicationsSearched} Publications finding ${matchesFound} matches.`);
}
