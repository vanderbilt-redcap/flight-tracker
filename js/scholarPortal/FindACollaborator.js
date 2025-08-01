

$(document).ready(function() {
    const module = ExternalModules.Vanderbilt.FlightTrackerExternalModule;

    document.getElementById('searchForm').addEventListener('submit', function(e) {
        e.preventDefault();
        $('#resultsTable').hide();
        $('#downloadCsvBtn').hide();
        $('#loadingSpinner').show();
        module.ajax('ajaxRouter', {ajaxAction: 'getFlightTrackerPids'}).then(function (response) {
            console.dir(response);
            $('#searchStatusText').data('currentPidSearching', 1);
            $('#searchStatusText').text(`Searching FlightTracker Project 1 of ${response.length}`);
            response.forEach(function (pid, index, pidArray) {
                let currentPid = index+1;
                let totalPids = pidArray.length;
                module.ajax('ajaxRouter', {ajaxAction: 'collaboratorBasicSearch', searchString: $('#mainSearch').val(), pids: [pid]}).then(function(response) {
                    console.dir(response);
                    $('#searchStatusText').data('currentPidSearching', $('#searchStatusText').data('currentPidSearching') + 1);
                    $('#searchStatusText').text(`Searching FlightTracker Project ${$('#searchStatusText').data('currentPidSearching')} of ${totalPids}`);
                    if (response.matchesFound > 0) {
                        $('#resultsTable').show();
                        $('#downloadCsvBtn').show();
                        for (const key in response.searchResults) {
                            addDataToResultsTable(response.searchResults[key]);
                        }
                    }
                    updateSearchStats(response);
                    if (currentPid === totalPids) {
                        $('#loadingSpinner').hide();
                        $('#searchStatusText').hide();
                    }
                }).catch(function (err) {
                    console.log('Error in ajaxRouter:', err);
                })
            })
        }).catch(function (err) {
            console.log(err)
        })
    });

    document.querySelectorAll('.toggle-col').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            const colClass = this.getAttribute('data-col');
            const show = this.checked;
            document.querySelectorAll('.' + colClass).forEach(function(cell) {
                cell.style.display = show ? '' : 'none';
            });
        });
    });

    // Update CSV export to only include visible columns
    document.getElementById('downloadCsvBtn').addEventListener('click', function() {
        const table = document.querySelector('#resultsTable table');
        let csv = [];
        // Get visible column indexes
        const visibleIndexes = [];
        for (let i = 0; i < table.rows[0].cells.length; i++) {
            if (table.rows[0].cells[i].style.display !== 'none') {
                visibleIndexes.push(i);
            }
        }
        for (let row of table.rows) {
            let rowData = [];
            visibleIndexes.forEach(function(i) {
                let cell = row.cells[i];
                let text = cell.innerHTML.replace(/<br\s*\/?>/gi, '\n').replace(/"/g, '""');
                rowData.push('"' + text.replace(/<[^>]*>?/gm, '') + '"');
            });
            csv.push(rowData.join(','));
        }
        const csvContent = csv.join('\r\n');
        const blob = new Blob([csvContent], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = url;
        a.download = 'results.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
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
