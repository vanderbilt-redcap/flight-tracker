$(document).ready(function () {
    $("#customQuestionsNum").on("change", function () {
        mentorConfigure.updateNumCustomQuestions(parseInt($("#customQuestionsNum").val()))
    })
    $("#configForm").on("submit", function (event) {
        mentorConfigure.serializeQuestions("formAttatch");
    })
    $("#configureOpen").on("click", function() {
        mentorConfigure.displayCustomQuestions(JSON.parse($("#custom_questions_data").val()))
    })
    let pathName = window.location.href;
    if (pathName.includes("mentorConfigure")) {
        mentorConfigure.displayCustomQuestions(JSON.parse($("#customQuestionData").val()))
    }
});

const mentorConfigure = {};

mentorConfigure.attachEventListeners = function() {
    $(".customQuestionTypeSelect").on("change", mentorConfigure.getCustomQuestionFields)
    $(".dynamicField").on("change", mentorConfigure.handleDynamicField)
}

mentorConfigure.destroyEventListeners = function() {
    $(".customQuestionTypeSelect").off("change");
    $(".dynamicField").off("change");
}

mentorConfigure.updateNumCustomQuestions = function (numQuestions) {
    const customQuestionArea = $("#customQuestionArea");
    customQuestionArea.empty();
    mentorConfigure.destroyEventListeners();
    customQuestionArea.slideUp();
    if (numQuestions > 0) {
        for (let i = 1; i <= numQuestions; i++) {
            customQuestionArea.append(mentorConfigure.getCustomQuestionPrimer(i));
        }
    }
    customQuestionArea.slideDown();
    mentorConfigure.attachEventListeners();
}



mentorConfigure.getCustomQuestionFields = function (event) {
    const customQuestionNumber = $(event.currentTarget).data("custom-question-num");
    const customQuestionFieldArea = $(`#customQuestion${customQuestionNumber}FieldsArea`)
    customQuestionFieldArea.slideUp();
    customQuestionFieldArea.empty();
    mentorConfigure.destroyEventListeners();
    customQuestionFieldArea.append(mentorConfigure.getCustomQuestionFieldsText($(`#customQuestion${customQuestionNumber}Type`).val(), customQuestionNumber));
    customQuestionFieldArea.slideDown();
    mentorConfigure.attachEventListeners();
}

mentorConfigure.displayCustomQuestions = function (customQuestionsData) {
    $("#customQuestionsNum").val(Object.keys(customQuestionsData).length);
    mentorConfigure.updateNumCustomQuestions(Object.keys(customQuestionsData).length);
    console.dir(customQuestionsData);
    Object.keys(customQuestionsData).forEach(function (key) {
        $('select[data-custom-question-num="' + key + '"]').val(customQuestionsData[key]['questionType']).trigger("change");
        $(`#customQuestion${key}Question`).val(customQuestionsData[key]['questionText']);
        switch (customQuestionsData[key]['questionType']) {
            case "multiChoice":
            case "multiSelect":
                let choices = customQuestionsData[key]['choices'];
                $(`#customQuestion${key}NumOptions`).val(Object.keys(choices).length).trigger("change");
                Object.keys(choices).forEach(function (choicekey) {
                    $(`#customQuestion${key}ChoiceArea`).find(`input[data-choice-number=${choicekey}]`).val(choices[choicekey]);
                });
        }
    });
}

mentorConfigure.getCustomQuestionPrimer = function (number) {
    return `
<div class="left-align">
<label class="left-align" for="customQuestion${number}Type">Question ${number} Response Type
<select id="customQuestion${number}Type" class="customQuestionTypeSelect left-align" data-custom-question-num="${number}">
    <option value=""></option>
    <option value="boolean">Yes/No</option>
    <option value="multiChoice">Multiple Choice</option>
    <option value="multiSelect">Multiple Select</option>
    <option value="text">Text</option>
</select>
</label>
</div>
<div id="customQuestion${number}FieldsArea">
</div>
<br>`
}

mentorConfigure.getNumOptionFields = function (number) {
    let html = ``
    for (let i = 0; i < number; i++) {
        html += `<option value="${i + 1}">${i + 1}</option>`
    }
    return html
}

mentorConfigure.getCustomQuestionFieldsText = function (type, number) {
    const numChoices = 10;
    switch (type) {
        case "boolean":
            return `
            <div data-question-number="${number}" data-question-type="${type}" class="customQuestionDiv">
                <label class="va-top" for="customQuestion${number}Question">Question Title</label>
                <textarea type="text" id="customQuestion${number}Question" class="questionText"></textarea>
            </div>
            `
        case "multiChoice":
            return `
            <div data-question-number="${number}" data-question-type="${type}" class="customQuestionDiv">
                <label class="va-top" for="customQuestion${number}Question">Question Title</label>
                <textarea type="text" id="customQuestion${number}Question" class="questionText"></textarea>
                <br>
                <label class="left-align" for="customQuestion${number}NumOptions">Number Of Choices</label>
                <select data-target="customQuestion${number}ChoiceArea" data-question-number="${number}"
                id="customQuestion${number}NumOptions" data-question-type="${type}" class="dynamicField">
                <option value=""></option>
                ${mentorConfigure.getNumOptionFields(numChoices)}
                </select>
                <div id="customQuestion${number}ChoiceArea">
                </div>
            </div>
            `
        case "multiSelect":
            return `
            <div data-question-number="${number}" data-question-type="${type}" class="customQuestionDiv">
                <label class="va-top" for="customQuestion${number}Question">Question Title</label>
                <textarea type="text" id="customQuestion${number}Question" class="questionText"></textarea>
                <br>
                <label for="customQuestion${number}NumOptions">Number Of Choices</label>
                <select data-target="customQuestion${number}ChoiceArea" data-question-number="${number}"
                id="customQuestion${number}NumOptions" data-question-type="${type}" class="dynamicField">
                <option value=""></option>
                ${mentorConfigure.getNumOptionFields(numChoices)}
                </select>
                <div id="customQuestion${number}ChoiceArea">
                </div>
            </div>
            `
        case "text":
            return  `
            <div data-question-number="${number}" data-question-type="${type}" class="customQuestionDiv">
                <label class="va-top" for="customQuestion${number}Question">Question Title</label>
                <textarea type="text" id="customQuestion${number}Question" class="questionText"></textarea>
            </div>`;
    }
}

mentorConfigure.handleDynamicField = function(event) {
    const eventTarget = $(event.currentTarget);
    const questionNumber = eventTarget.data("question-number");
    const questionType = eventTarget.data("question-type");
    const attatchTarget = eventTarget.data("target");
    const attachElement = $(`#${attatchTarget}`)
    const numFields = eventTarget.val();
    let html = "";
    for (let i = 0; i < numFields; i++) {
        switch (questionType) {
            case "multiChoice":
                html += `
            <label>Choice ${i + 1} Text</label>
            <input type="text" class="responseText" data-choice-number="${i + 1}">
            <br>`
            break;
            case "multiSelect":
                html += `
            <label>Choice ${i + 1} Text</label>
            <input type="text" class="responseText" data-choice-number="${i + 1}">
            <br>`
            break;
        }
    }
    attachElement.slideUp();
    attachElement.empty();
    attachElement.slideDown();
    attachElement.append(html);
}

mentorConfigure.serializeQuestions = function(url) {
    const questionArea = $("#customQuestionArea");
    const questionDivs = questionArea.find(".customQuestionDiv")
    let customQuestionInformation = {}
    questionDivs.each(function (index, element) {
        const questionType = $(element).data("question-type");
        const questionNumber = $(element).data("question-number");
        customQuestionInformation[questionNumber] = {"questionType": questionType}
        customQuestionInformation[questionNumber]["questionText"] = $(element).children("textarea").val()
        switch (questionType) {
            case "text":
                break;
            case "multiChoice":
            case "multiSelect":
                let choices = {}
                $(element).find("input.responseText").each(function (index, element) {
                   choices[$(element).data("choice-number")] = $(element).val();
                });
                customQuestionInformation[questionNumber]["choices"] = choices;
                break;
        }
    })
    console.dir(customQuestionInformation);
    console.log(JSON.stringify(customQuestionInformation))
    let questionInfo = JSON.stringify(customQuestionInformation)
    let csrfToken = $("#csrfToken").val();
    let postData = {
        "customQuestions": questionInfo,
        'redcap_csrf_token': csrfToken,
    };
    if (url === "formAttatch") {
        $('#custom_questions_data').val(questionInfo);
    } else {
        $.post(url, postData, (json) => {
            console.log(json);
            json = JSON.parse(json);
            if (json.result) {
                $("#responseArea").html(`<div class="success">Questions saved successfully. Notified ${json.result} mentees.</div>`)
            } else {
                $.sweetModal({
                    content: `${json.error}`,
                    icon: $.sweetModal.ICON_ERROR
                });
            }
        });
    }
}

mentorConfigure.unserializeQuestions = function(questionString) {
    console.dir(JSON.parse(questionString))
    const customQuestionData = JSON.parse(questionString);
    let html = '';
    Object.keys(customQuestionData).forEach(function (key) {
        html += mentorConfigure.buildQuestionHtml(customQuestionData[key], key);
    })
    $("#responseArea").append(html);
}

mentorConfigure.buildQuestionHtml = function(questionInfo, questionNumber) {
let returnHtml = ``;
    switch (questionInfo["questionType"]) {
        case "boolean":
            return `
            <label>${questionInfo['questionText']}</label>
            <br>
            <input id="question${questionNumber}Yes" type="radio" name="customQuestion${questionNumber}"/><label for="question${questionNumber}Yes">Yes</label>
            <input id="question${questionNumber}No" type="radio" name="customQuestion${questionNumber}"/><label for="question${questionNumber}No">No</label>
            <br>
            `
        case "multiChoice":
            returnHtml = `
            <label>${questionInfo['questionText']}</label>
            <br>
            <select>`;
            Object.keys(questionInfo['choices']).forEach(function (key) {
                returnHtml += `<option value="${key}">${questionInfo['choices'][key]}</option>`
            });
            returnHtml += `</select><br>`
            return returnHtml;
        case "multiSelect":
            returnHtml = `
            <label>${questionInfo['questionText']}</label>
            <br>`
            Object.keys(questionInfo['choices']).forEach(function (key) {
                returnHtml += `<input id="question${questionNumber}Response${key}" type="checkbox" name="customQuestion${questionNumber}Response${key}"/><label for="question${key}Yes">${questionInfo['choices'][key]}</label>`
            });
            return returnHtml;
        case "text":
            returnHtml = `
            <label>${questionInfo['questionText']}</label>
            <br>
                <input type="text" name="customQuestion${questionNumber}Response" id="question${questionNumber}Response">
            <br>
            `;
            return returnHtml;
        default:
            return ''
    }
}
