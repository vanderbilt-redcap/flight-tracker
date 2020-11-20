class xTRACT {
    baseurl = '';
    basetoken = '';

    constructor(url, token) {
        this.basetoken = token;
        this.baseurl = url;
    }

    getProcessingMessage() {
        return this.getFlightTrackerImage()+' PROCESSING!';
    }

    getFlightTrackerImage() {
        return '<img src="'+this.getBase64OfLogo()+'" alt="Flight Tracker">';
    }

    getProgramName() {
        return this.getFlightTrackerImage()+' Flight Tracker';
    }

    getBase64OfLogo() {
        return 'data:image/png;base64,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';
    }

    getFlightTrackerColor() {
        return '#80191A';
    }

    getDataFromREDCap(record) {
        let postdata = {
            origin: location.href,
            token: this.basetoken
        };
        if (typeof record != 'undefined') {
            postdata['record'] = record;
        }
        let procMssg = this.getProcessingMessage();
        let color = this.getFlightTrackerColor();

        $.each($('h4'), function(idx, ob) {
            let h4HTML = $(ob).html();
            if (h4HTML.match(/ \(Pre-doc\)/) || h4HTML.match(/ \(Post-doc\)/)) {
                $(ob).append('<div id="flightTrackerSelect"><span style="color: '+color+';">'+procMssg+'</span></div>');
            }
        });
        let url = this.baseurl;
        let token = this.basetoken;
        $.post(this.baseurl, postdata, function(json) {
            let x = new xTRACT(url, token);
            if (x.isOkToProceed(json)) {
                console.log(json);
                let data = JSON.parse(json);
                if (data['error']) {
                    console.log('ERROR: '+data['error']);
                } else {
                    x.fillPage(data, location.href);
                }
            }
        });
    }

    isOkToProceed(json) {
        if (json.match(/^\s*</)) {
            console.log(json);
            alert('ERROR: Your REDCap Session has expired! Please log in to REDCap again.');
            return false;
        }
        return true;
    }

    hasNameOnPage() {
        let nameHash = this.getNameFromPage();
        return (nameHash['first'] && nameHash['last']);
    }

    getNameFromPage() {
        var hash = {};
        $.each($('h4'), function(idx, ob) {
            let html = $(ob).html();
            if (html.match(/ \(Pre-doc\)/)) {
                html = html.replace(/ \(Pre-doc\)/, '');
            } else if (html.match(/ \(Post-doc\)/)) {
                html = html.replace(/ \(Post-doc\)/, '');
            } else {
                return;    // inner function
            }
            html = html.replace(/<[^>]+>/g, '');
            let nodes = html.split(/,\s*/);
            if (nodes.length == 2) {
                hash = {};
                if (nodes[1].match(/ /)) {
                    let firstNameNodes = nodes[1].split(/ /);
                    if (firstNameNodes.length == 2) {
                        hash['first'] = firstNameNodes[0];
                        hash['middle'] = firstNameNodes[1];
                    } else {
                        hash['first'] = nodes[1];
                    }
                } else {
                    hash['first'] = nodes[1];
                }
                hash['last'] = nodes[0];
            }
        });
        return hash;
    }

    doNamesMatch(n1, n2) {
        let n1Lower = n1.toLowerCase();
        let n2Lower = n2.toLowerCase();
        let pairs = [ [n1Lower, n2Lower], [n1Lower, n2Lower] ];
        for (var i=0; i < pairs.length; i++) {
            let na = pairs[i][0];
            let nb = pairs[i][1];
            if (na.length >= 4) {
                let regex = new RegExp(na);
                if (regex.test(nb)) {
                    return true;
                }
            } else if (na == nb) {
                return true;
            }
        }
        return false;
    }

    matchName(first1, last1, name2Hash) {
        if (this.doNamesMatch(first1, name2Hash['first']) && this.doNamesMatch(last1, name2Hash['last'])) {
            return true;
        } else if (name2Hash['middle'] && this.doNamesMatch(first1, name2Hash['middle']) && this.doNamesMatch(last1, name2Hash['last'])) {
            return true;
        }
        return false;
    }

    makeNamePicker(firstnames, lastnames) {
        let thisRecordNameHash = this.getNameFromPage();
        var html = '<select id="nameSelect'+this.getSuffix()+'"><option value="">---NONE---</option>';
        for (let record in firstnames) {
            let firstname = firstnames[record];
            let lastname = lastnames[record];
            let fullname = firstname+' '+lastname;
            var selected = '';
            if (firstname && lastname && this.matchName(firstname, lastname, thisRecordNameHash)) {
                selected = ' selected';
            }
            html += '<option value="'+record+'"'+selected+'>'+record+': '+fullname+'</option>';
        }
        html += '</select>';
        return html;
    }

    getSuffix() {
        return 'FT';
    }

    getSelectedRecord() {
        if ($('#nameSelect'+this.getSuffix()).length > 0) {
            return $('#nameSelect'+this.getSuffix()).val();
        }
        return '';
    }

    selectOption(modalId, row) {
        let record = this.getSelectedRecord();
        console.log('selectOption '+record);
        let postdata = {
            modalId: modalId,
            row: row,
            record: record,
            token: this.basetoken
        };
        let token = this.basetoken;
        let url = this.baseurl;
        console.log(this.baseurl+' '+JSON.stringify(postdata));
        $.post(this.baseurl, postdata, function(json) {
            console.log(json);
            let x = new xTRACT(url, token);
            if (x.isOkToProceed(json)) {
                let data = JSON.parse(json);
                x.fillModalElements(modalId, data);
            }
        });
    }

    addOptionHTML(modalId, row, rowNum) {
        var lines = [];
        for (let key in row) {
            let value = row[key];
            lines.push('<b>'+key+'</b>: '+value);
        }
        if (lines.length === 0) {
            return '';
        }

        var varName = modalId+'_row'+rowNum+this.getSuffix();
        var html = '<script>if (typeof '+varName+' == "undefined") { var '+varName+' = '+JSON.stringify(row)+'; }</script>';
        html += '<div style="margin: 5px; padding: 8px; border: 1px solid #888;" onclick="let x = new xTRACT(\''+this.baseurl+'\', \''+this.basetoken+'\'); x.selectOption(\''+modalId+'\', '+varName+');">';
        html += lines.join('<br>');
        html += '</div>';
        return html;
    }

    isAllPMIDs(rows) {
        for (var i=0; i < rows.length; i++) {
            if (typeof(rows[i]['PMID']) == 'undefined') {
                return false;
            }
        }
        return true;
    }

    getPMIDList(rows) {
        var pmids = [];
        for (var i=0; i < rows.length; i++) {
            if (rows[i]['PMID']) {
                pmids.push(rows[i]['PMID']);
            }
        }
        return pmids.join(', ');
    }

    fillModalElements(modalId, data) {
        console.log('fillModalElements '+modalId);
        let noteId = modalId+'_AutoFill'+this.getSuffix();
        $('#'+noteId).html('');
        if (Object.keys(data).length == 0) {
            alert('No data exist on this record for this item');
        } else if (data['multipleItems'] && (data['multipleItems'].length >= 2)) {
            console.log('Multiple items');
            var html = '<h4>Multiple Options from '+this.getProgramName()+' (Click one)</h4>';
            if (this.isAllPMIDs(data['multipleItems'])) {
                let pmids = this.getPMIDList(data['multipleItems']);
                let row = { PMID: pmids };
                html += this.addOptionHTML(modalId, row, 0);
            } else {
                for (var i=0; i < data['multipleItems'].length; i++) {
                    let row = data['multipleItems'][i];
                    html += this.addOptionHTML(modalId, row, i);
                }
            }
            console.log(html);
            $('#'+noteId).html(html);
        } else {
            for (let elementId in data) {
                let value = data[elementId];
                console.log(elementId+' set to '+value);
                if (elementId == 'alert') {
                    alert(value);
                } else if (elementId == 'listedSupport') {
                    var text;
                    if (value) {
                        text = value;
                    } else {
                        text = 'No information recorded.';
                    }
                    $('#' + modalId).find('.modal-header').append('<div>' + text + '</div>');
                } else if (elementId.match(/^ms-/)) {
                    $('#' + elementId + ' div input[type=text]').val(value);      // auto-fill
                } else if ($('select#' + elementId).length > 0) {
                    if (!value) {
                        $('#' + elementId).val('');
                    } else if (!value.match(/\s/) && ($('#' + elementId + ' option[value=' + value + ']').length > 0)) {
                        $('#' + elementId).val(value);
                    } else {
                        // allow to match text/label of an option
                        $.each($('#' + elementId + ' option'), function (idx, ob) {
                            // cannot use elementId or value because these values might have changed
                            // => use relative location to inspect elements; data remains constant
                            // only works if there are no repeat labels!
                            let elementIdOb = $(ob).parent().attr('id');
                            let textForLabel = data[elementIdOb];
                            if ($(ob).text().toLowerCase() == textForLabel.toLowerCase()) {
                                let currentVal = $(ob).val();
                                $('#' + elementIdOb).val(currentVal);
                            }
                        });
                    }
                } else if ($('[type=checkbox][name=' + elementId + ']').length > 0) {
                    if (value) {
                        $('[type=checkbox][name=' + elementId + ']').attr('checked', true);
                    } else {
                        $('[type=checkbox][name=' + elementId + ']').attr('checked', false);
                    }
                } else if ($('[type=radio][name=' + elementId + ']').length > 0) {
                    $('[type=radio][name=' + elementId + ']').attr('checked', false);
                    if (value) {
                        $('[type=radio][name=' + elementId + '][value=' + value + ']').attr('checked', true);
                    }
                } else if (value) {
                    $('#' + elementId).val(value);
                } else {
                    $('#' + elementId).val('');
                }
            }
        }
    }

    fillModal(modalId) {
        let record = this.getSelectedRecord();
        console.log(modalId+' with record '+record);
        if (this.baseurl && this.basetoken && record) {
            let postdata = {
                origin: location.href,
                token: this.basetoken,
                modalId: modalId,
                record: record
            };
            if (modalId == 'addPublicationRecord') {
                if ($('#firstName').is(':visible') && $('#middleName').is(':visible') && $('#lastName').is(':visible')) {
                    postdata['step'] = '1';
                } else if ($('#PMID').is(':visible')) {
                    postdata['step'] = '2';
                }
            }
            let noteClass = 'AutoFill'+this.getSuffix();
            $('.'+noteClass).html(this.getProcessingMessage());
            let url = this.baseurl;
            let token = this.basetoken;
            console.log(this.baseurl+' '+JSON.stringify(postdata));
            $.post(this.baseurl, postdata, function(json) {
                console.log(json);
                let x = new xTRACT(url, token);
                if (x.isOkToProceed(json)) {
                    $('.' + noteClass).html('');
                    let data = JSON.parse(json);
                    if (data['error']) {
                        console.log('ERROR: ' + data['error']);
                    } else {
                        x.fillModalElements(modalId, data);
                    }
                }
            });
        } else if (!record) {
            console.log('ERROR: No record selected!');
        } else {
            console.log('ERROR: No base url or token');
        }
    }

    addAutoFills(token, url) {
        let color = this.getFlightTrackerColor();
        let suffix =  this.getSuffix();
        $.each($('.modal-content'), function(idx, ob) {
            let modalId = $(ob).parent().parent().attr('id');
            let noteDiv = '<div style="color: '+color+';" class="AutoFill'+suffix+'" id="'+modalId+'_AutoFill'+suffix+'"></div>';
            let x = new xTRACT(url, token);
            let link = '&nbsp;<a href="javascript:;" onclick="let x = new xTRACT(\''+url+'\', \''+token+'\'); x.fillModal(\''+modalId+'\');">Auto-Fill From '+x.getProgramName()+'</a>'+noteDiv;
            if ($(ob).find('.modal-header h4').length > 0) {
                $(ob).find('.modal-header h4').append(link);
            } else if ($(ob).find('.modal-header div h4').length > 0) {
                $(ob).find('.modal-header div h4').append(link);
            } else {
                $(ob).find('.modal-header').append(link);
            }
        });
    }

    fillPage(data, currentPage) {
        console.log('Fill page '+currentPage);
        $('div#flightTrackerSelect').html('');
        if (data['firstnames'] && data['lastnames'] && this.hasNameOnPage()) {
            let selectHTML = this.makeNamePicker(data['firstnames'], data['lastnames']);
            $('div#flightTrackerSelect').html('Match with '+this.getProgramName()+': '+selectHTML);
            this.addAutoFills(this.basetoken, this.baseurl);
        }
    }
}

