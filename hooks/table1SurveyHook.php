<?php

use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

$pids = Application::getPids();
$postJS = "";
if (count($pids) > 0) {
    $link = Application::link("/reporting/getUserid.php", $pids[0]);
    $postJS = "
    $.post('$link', {}, (prefill) => {
        for (let field in prefill) {
            const value = prefill[field];
            $('[name='+field+']').val(value);
        }
    });
    ";
}

?>

<script>
    $(document).ready(() => {
        const fieldsToSkip = ['program', 'record_id', 'name', 'email'];
        $('input[type=text]').each((idx, ob) => {
            const fieldName = $(ob).attr('name');
            if (fieldsToSkip.indexOf(fieldName) < 0) {
                const html = makeNumberTextHTML(fieldName) + ' ' + makeReliabilityScaleHTML(fieldName);
                $(ob)
                    .attr('type', 'hidden')
                    .after(html);
            }
        });
        <?= $postJS ?>
    });

    function makeNumberTextHTML(fieldName) {
        const newElemFieldName = fieldName + "__value";
        return "<div style='float: left; padding-right: 20px;'><input type='text' name='"+newElemFieldName+"' onchange='updateHiddenField(\""+fieldName+"\");' onblur='redcap_validate(this, \"\", \"\", \"soft_typed\", \"integer\", 1)' style='width: 50px;' /></div>";
    }

    function updateHiddenField(fieldName) {
        const numberName = fieldName + "__value";
        const scaleName = fieldName + "__reliability";
        const number = $('input[name='+numberName+']').val();
        const radioSel = 'input[name='+scaleName+']:checked';
        const reliability = ($(radioSel).length > 0) ? $(radioSel).val() : '';
        if ((number !== '') && (reliability !== '')) {
            $('input[name='+fieldName+']').val(number+'['+reliability+']');
        } else if (number !== '') {
            $('input[name='+fieldName+']').val(number);
        } else {
            $('input[name='+fieldName+']').val('');
        }
    }

    function makeReliabilityScaleHTML(fieldName) {
        const newElemFieldName = fieldName + "__reliability";
        const radios = [];
        for (let i = 1; i <= 4; i++) {
            const newElemID = newElemFieldName + "__" + i;
            radios.push("<input type='radio' name='"+newElemFieldName+"' id='"+newElemID+"' value='"+i+"' onclick='updateHiddenField(\""+fieldName+"\");' /> <label for='"+newElemID+"' />"+i+"</label>");
        }
        return "<div style='float: left; font-weight: normal; vertical-align: middle;'><div style='font-size: 9pt; color: #000066;'><strong>Reliability Index</strong><br/>(1 = low, 4 = high)</div><div>" + radios.join('&nbsp;&nbsp;&nbsp;') + '</div></div>';
    }
</script>
<style>
    .note { clear: left; }
</style>
