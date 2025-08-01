
$(document).ready(function () {
    console.dir(cohorts);

    $('#IndexFieldSelect').on('change', function () {
        if ($(this).val() === COHORT_CONST) {
            $('#cohortSelectionDiv').slideDown(200);
            $('#CohortFilterSelect').slideUp(200);
        } else {
            $('#cohortSelectionDiv').slideUp(200);
            $('#CohortFilterSelect').slideDown(200);
            $('input[name="cohorts[]"]').prop('checked', false);
        }
    });

    const urlParams = new URLSearchParams(window.location.search);
    const selectedCohorts = urlParams.getAll('cohorts[]');
    for (const cohort of selectedCohorts) {
        $('input[name="cohorts[]"][value="' + cohort + '"]').prop('checked', true);
    }

    $('#IndexFieldSelect').trigger('change');


})
