<?php
/**
 * @var $module Vanderbilt\FlightTrackerExternalModule\FlightTrackerExternalModule
 */
?>
<style>
    .btn-primary {
        background-color: #51B852 !important;
        border-color: #51B852 !important;
    }
    .btn-primary:hover, .btn-primary:focus {
        background-color: #449944 !important;
        border-color: #3d8b3d !important;
    }
    .custom-btn-group .btn-outline-primary {
        color: #51B852 !important;
        border-color: #51B852 !important;
        background-color: #fff !important;
    }
    .custom-btn-group .btn-outline-primary:hover,
    .custom-btn-group .btn-outline-primary:focus,
    .custom-btn-group .btn-outline-primary.active {
        color: #fff !important;
        background-color: #51B852 !important;
        border-color: #51B852 !important;
    }
</style>
<?php $module->loadBootstrap() ?>
<?php $module->addJS('js/scholarPortal/FindACollaborator.js') ?>
<div class="container mt-5">
    <div class="container">
        <div class="row align-items-center mb-2">
            <div class="col-4 text-start">
            </div>
            <div class="col-4"></div>
            <div class="col-4 text-end">
            </div>
        </div>
        <div class="row mb-2">
            <div class="col text-center">
                <h2 style="font-size:1.5rem; margin:0;">Find a Collaborator</h2>
            </div>
        </div>
        <div class="row mb-4">
            <div class="col text-center">
                <p style="max-width:800px; margin:0 auto; font-size:1.1rem;">
                    What topic(s) do you want to search for? Doing so will search all <?php echo count($module->getPids()) ?> Flight Trackers on this server and may take some time. It will search everyone's publications using MeSH Terms and PubMed Keywords.
                </p>
            </div>
        </div>
    </div>
    <!-- TODO: For finishing basic search in the display table Number of papers matched should be a link to https://pubmed.ncbi.nlm.nih.gov/?term=27229652,30104761 with a comma seperated list of PMIDs hyperlink text (View Papers)\ -->
    <div class="card p-4 mb-4">
	    <form id="searchForm">
	      <div class="row align-items-end">
              <div class="col-12 mb-3">
                  <div class="input-group">
                      <input type="text" class="form-control" id="mainSearch" name="mainSearch" placeholder="Search..." aria-label="Search">
                      <span class="input-group-text">
      <i class="fa fa-search"></i>
    </span>
                  </div>
              </div>
	      </div>
            <div class="row mt-3 mb-4">
                <div class="col">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </div>
	    </form>
    </div>
<div class="mb-2">
  <div class="btn-group custom-btn-group" role="group" aria-label="Toggle columns">
    <input type="checkbox" class="btn-check toggle-col" id="btn-academic-rank" data-col="academic-rank" autocomplete="off">
    <label class="btn btn-outline-primary btn-sm" for="btn-academic-rank">Academic Rank</label>

    <input type="checkbox" class="btn-check toggle-col" id="btn-department" data-col="department" autocomplete="off">
    <label class="btn btn-outline-primary btn-sm" for="btn-department">Department</label>

    <input type="checkbox" class="btn-check toggle-col" id="btn-degrees" data-col="degrees" autocomplete="off">
    <label class="btn btn-outline-primary btn-sm" for="btn-degrees">Degrees</label>

    <input type="checkbox" class="btn-check toggle-col" id="btn-has-k" data-col="has-k" autocomplete="off">
    <label class="btn btn-outline-primary btn-sm" for="btn-has-k">Has a K?</label>

    <input type="checkbox" class="btn-check toggle-col" id="btn-has-r" data-col="has-r" autocomplete="off">
    <label class="btn btn-outline-primary btn-sm" for="btn-has-r">Has a R?</label>
  </div>
</div>
    <div id="loadingSpinner" class="text-center my-4" style="display:none;">
        <p id="searchStatusText"></p>
        <img src="<?php echo \Vanderbilt\CareerDevLibrary\Application::link('img/loading.gif') ?>" alt="Loading..." style="width: 48px; height: 48px;">
    </div>
    <div class="mb-2 text-end">
        <button id="downloadCsvBtn" class="btn btn-success btn-sm" type="button" style="display:none;">
            Download CSV
        </button>
    </div>
    <div id="resultsTable" style="display:none;">
        <div class="row mt-5">
            <div class="col-12">
                <h3 class="text-center mb-3">Search Results</h3>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                        <tr class="table-head">
                            <th>Score</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Number of papers matched</th>
                            <th>Date of most recent paper matched</th>
                            <th class="academic-rank" style="display:none;">Academic Rank</th>
                            <th class="department" style="display:none;">Department</th>
                            <th class="degrees" style="display:none;">Degrees</th>
                            <th class="has-k" style="display:none;">Has a K?</th>
                            <th class="has-r" style="display:none;">Has a R?</th>
                            <th class="urm-status" style="display:none;">URM Status</th>
                            <th class="race-ethnicity" style="display:none;">Race/Ethnicity</th>
                        </tr>
                        </thead>
                        <tbody>

                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <p id="searchStats" style="display: none"></p>
</div>
