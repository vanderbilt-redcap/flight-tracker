<script>

    $('input[type=text]').each(function(idx, ob) {
        if ($(ob).attr('name') && $(ob).attr('name').match(/_date$/) && !$(ob).val()) {
            let name = $(ob).attr('name');
            var numUnderscores = 0;
            for (var i=0; i < name.length; i++) {
                if (name[i] == '_') {
                    numUnderscores++;
                }
            }
            if (numUnderscores == 1) {
                if ($(ob).hasClass('date_ymd')) {
                    $(ob).val('<?= date("Y-m-d") ?>');
                } else if ($(ob).hasClass('date_mdy')) {
                    $(ob).val('<?= date("m-d-Y") ?>');
                } else if ($(ob).hasClass('date_dmy')) {
                    $(ob).val('<?= date("d-m-Y") ?>');
                }
            }
        }
    });

</script>