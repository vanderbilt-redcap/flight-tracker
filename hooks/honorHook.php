<?php

# for data entry page in REDCap so that admins can add new honors or committees to their institutional list
# NOT for survey page

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$affectedFields = DataDictionaryManagement::HONORACTIVITY_SPECIAL_FIELDS;
$driverUrl = Application::link("modifyHonorMetadata.php", $pid);

$choicesBySource = [];
$destFields = [];
$prefix = REDCapManagement::getPrefixFromInstrument($instrument);
foreach ($affectedFields as $sourceField => $destFieldsForSource) {
    $choicesBySource[$sourceField] = DataDictionaryManagement::getChoicesForField($pid, $destFieldsForSource[0]);
    foreach ($destFieldsForSource as $destField) {
        if (preg_match("/^$prefix"."_/", $destField)) {
            $destFields[$sourceField] = $destField;
            break;
        }
    }
}
$sourceFieldsJSON = json_encode(array_keys($affectedFields));
$choicesBySourceJSON = json_encode($choicesBySource);
$destFieldsJSON = json_encode($destFields);

?>

<script>
    $(document).ready(() => {
        placeHonorButtons();
        refreshHonorButtons();
    });
    const sourceFields = <?= $sourceFieldsJSON ?>;
    const choices = <?= $choicesBySourceJSON ?>;
    const destFields = <?= $destFieldsJSON ?>;
    for (let i=0; i < sourceFields.length; i++) {
        const field = sourceFields[i];
        $('[name='+field+']').on('blur', () => { refreshHonorButtons(); });
    }

    function addToMetadata(sourceField) {
        const label = $('[name='+sourceField+']').val();
        if (label) {
            const postdata = {
                redcap_csrf_token: '<?= Application::generateCSRFToken() ?>',
                field: sourceField,
                label: label,
                record: '<?= $record ?>',
            };
            $.post('<?= $driverUrl ?>', postdata, (json) => {
                try {
                    const data = JSON.parse(json);
                    if (data.error) {
                        console.log(data.error);
                        $.sweetModal({
                            content: "ERROR: "+data.error,
                            icon: $.sweetModal.ICON_ERROR
                        });
                    } else if (data.result && data.index && data.label) {
                        console.log(data.result);
                        const destField = destFields[sourceField];
                        const newConfig = {
                            text: data.label,
                            'data-mlm-field': destField,
                            'data-mlm-type': 'enum',
                            'data-mlm-value': data.index,
                            value: data.index,
                        };
                        const selectElem = $('[name='+destField+']');
                        const dropdownElem = $('#rc-ac-input_'+destField);

                        // add new option
                        selectElem.append($('<option>', newConfig));
                        // update the options in the dropdown
                        const options = [];
                        $('[name='+destField+'] option').each((idx, ob) => {
                            const label = $(ob).text();
                            $(ob).attr('selected', false);  // unselect current value
                            options.push(label);
                        });
                        // select the new option
                        $('[name='+destField+'] option[value='+data.index+']').attr('selected', true);
                        dropdownElem.element = selectElem;
                        dropdownElem.autocomplete("option", { source: options });
                        dropdownElem.val(data.label);
                        $.sweetModal({
                            content: 'Added to your project\'s list!',
                            icon: $.sweetModal.ICON_SUCCESS,
                        })
                    } else {
                        console.log(json);
                    }
                } catch (e) {
                    console.error(json);
                    $.sweetModal({
                        content: "Invalid message! You may need to log into REDCap again. "+e,
                        icon: $.sweetModal.ICON_ERROR
                    });
                }
            });
        } else {
            console.error("No label for "+sourceField+"!");
        }
    }

    function placeHonorButtons() {
        for (let i=0; i < sourceFields.length; i++) {
            const field = sourceFields[i];
            const buttonField = field + '_button';
            $('[name='+field+']').parent().after('<div id=\"'+buttonField+'\" style=\"font-size: 11px; display: none;\"><button class=\"blue\" onclick=\"addToMetadata(\''+field+'\'); return false;\">Add to Institutional List</button></div>')
        }
    }

    function refreshHonorButtons() {
        for (let i=0; i < sourceFields.length; i++) {
            const field = sourceFields[i];
            const buttonOb = $('#'+field+'_button');
            buttonOb.hide();
            const ob = $('[name='+field+']');
            const value = ob.val();
            if ((value !== '') && ob.is(':visible')) {
                const lowerValue = value.toLowerCase();
                const fieldChoices = choices[field];
                let found = false;
                for (const index in fieldChoices) {
                    const lowerLabel = fieldChoices[index].toLowerCase();
                    if (lowerValue === lowerLabel) {
                        found = true;
                    }
                }
                if (!found) {
                    buttonOb.show();
                }
            }
        }
    }
</script>
