class Portal {
    stateData = {};
    matchDiv = '#matchBox';
    loadingDiv = '.loading';
    menuDiv = 'nav';
    projectDiv = '#project';
    percentDiv = '#percentDone';
    loadingUrl = '';
    emphHeader = 'h3';
    subHeader = 'h4';
    url = '';
    maxMatchCols = 4;
    matchColWidth = 200;
    matchColMarginWidth = 32;
    selectedPid = '';
    selectedRecord = '';
    lastAction = '';
    photoDiv = '#photoDiv';
    postsDiv = '#posts';
    numRefreshes = 0;

    disassociateORCID = function(url, orcid, recordId, pid) {
        const postData = {
            action: 'removeORCID',
            record: recordId,
            pid: pid,
            orcid: orcid
        };
        this.runPost(url, postData, (data) => {
            $.sweetModal({
                icon: $.sweetModal.ICON_SUCCESS,
                title: 'Removed',
                content: "Removed!"
            });
            this.refreshAction();
        });
    }

    addORCID = function(url, orcidSel, recordId, pid) {
        const orcid = $(orcidSel).val();
        if (orcid === '') {
            return;
        }
        const postData = {
            action: 'addORCID',
            record: recordId,
            pid: pid,
            orcid: orcid
        };
        this.runPost(url, postData, (data) => {
            $.sweetModal({
                icon: $.sweetModal.ICON_SUCCESS,
                title: 'Added',
                content: "Added!"
            });
            this.refreshAction();
        });
    }

    setLoadingUrl = function(url) {
        this.loadingUrl = url;
    }

    updateLoadingBox = function(mssg) {
        const prev = $(this.loadingDiv).html();
        if (!mssg) {
            $(this.loadingDiv).html('');
        } else if (prev) {
            $(this.loadingDiv+" "+this.subHeader).html(mssg);
        } else {
            $(this.loadingDiv).html("<"+this.subHeader+" class='nomargin'>"+mssg+"</"+this.subHeader+"><p class='nomargin centered'><img src='"+this.loadingUrl+"' alt='Loading...' style='width: 48px; height: 48px;'/></p><div id='percentDone' class='centered'></div>");
        }
    }

    getMatches = function(url) {
        this.url = url;
        this.updateLoadingBox("Loading Matches from Flight Tracker...");
        this.runPost(this.url, {action: 'getPids'}, (data) => {
            const pids = data['pids'];
            if (pids.length > 1) {
                this.updateLoadingBox("Loading Matches from All "+pids.length+" Flight Trackers...");
            }
            const numPidsInBatch = 3;
            const pidBatches = [];
            for (let i = 0; i < pids.length; i += numPidsInBatch) {
                const batch = [];
                for (let j = i; (j < pids.length) && (j < i + numPidsInBatch); j++) {
                    batch.push(pids[j]);
                }
                if (batch.length > 0) {
                    pidBatches.push(batch);
                }
            }
            this.stateData.matchData = {};
            this.refreshMatches();
            $(this.percentDiv).html("0%");
            if (pids.length <= 1) {
                this.getMatchesFromPids(pidBatches, 0);
            } else {
                this.runPost(this.url, {action: 'getMatchesFromCache'}, (data) => {
                    if (data.length === 0) {
                        this.getMatchesFromPids(pidBatches, 0);
                    } else {
                        this.stateData.matchData = data;
                        this.refreshMatches(true);
                        this.prepareMatchesToSelect();
                        $(this.percentDiv).html("");
                    }
                });
            }
        });
    }

    prepareMatchesToSelect = function() {
        this.updateLoadingBox("");
        const numMatches = this.getNumMatches();
        if (numMatches === 1) {
            $('.multiProject').hide();
            this.setMatch(0);
        } else if ((numMatches === 0) && (window.location.hash !== '#board')) {
            $('#noDataMessage').show();
            $('.multiProject').hide();
        } else {
            $('.multiProject').show();
            if (window.location.hash) {
                const hashAry = window.location.hash.replace(/^#/, '').split(/:/);
                if (hashAry.length === 3) {
                    this.setMatchByLicensePlate(hashAry[1], hashAry[2]);
                }
            } else {
                $('#welcomeMessage').show();
            }
        }
        if (window.location.hash === "#board") {
            this.takeAction("board", "Bulletin Board");
        }
    }

    reopenSurvey = function(instrument, pid, recordId) {
        const matches = this.stateData.matchData['matches'] ?? {};
        if (typeof matches[pid][recordId] !== 'undefined') {
            this.runPost(this.url, { action: 'reopenSurvey', instrument: instrument, pid: pid, record: recordId }, (data) => {
                const link = data.link ?? "";
                if (link) {
                    window.open(link, '_blank');
                } else {
                    this.processError("Invalid link provided");
                }
            });
        } else {
            this.processError("Invalid request");
        }
    }

    setMatchByLicensePlate = function(pid, recordId) {
        const matches = this.stateData.matchData['matches'] ?? {};
        $('.match').removeClass('selectedMatch');
        if (typeof matches[pid][recordId] !== 'undefined') {
            this.clearMainBox();
            this.selectedPid = pid;
            this.selectedRecord = recordId;
            $('.match[record='+recordId+'][pid='+pid+']').addClass('selectedMatch');
            const projectTitle = $('.selectedMatch .projectTitle').html();
            this.getMenu(projectTitle, pid, recordId);
        }
    }

    setMatch = function(index) {
        const matches = this.stateData.matchData['matches'] ?? {};
        let i = -1;
        $('.match').removeClass('selectedMatch');
        for (const pid in matches) {
            for (const recordId in matches[pid]) {
                i++;
                if (i === index) {
                    this.clearMainBox();
                    $('.match[record='+recordId+'][pid='+pid+']').addClass('selectedMatch');
                    if ((pid !== this.selectedPid) || (recordId !== this.selectedRecord)) {
                        this.selectedPid = pid;
                        this.selectedRecord = recordId;
                        const projectTitle = $('.selectedMatch .projectTitle').html();
                        const cb = () => { window.scrollTo({ top: 0, behavior: 'smooth' }) };
                        this.getMenu(projectTitle, pid, recordId, cb);
                    }
                    return;
                }
            }
        }
    }

    deepMerge = function(a, b) {
        const newObj = {};
        if (typeof a == "object") {
            for (const key in a) {
                const value = a[key];
                if (typeof value == "object") {
                    newObj[key] = structuredClone(value);
                } else {
                    newObj[key] = value;
                }
            }
        }
        if (typeof b == "object") {
            for (const key in b) {
                const value = b[key];
                if (typeof newObj[key] == "undefined") {
                    if (typeof value == "object") {
                        newObj[key] = structuredClone(value);
                    } else {
                        newObj[key] = value;
                    }
                } else if (typeof newObj[key] == "object") {
                    newObj[key] = this.deepMerge(newObj[key], value);
                } else {
                    newObj[key] = value;
                }
            }
        }
        return newObj;
    }

    getMatchesFromPids = function(pidBatches, i) {
        if (i < pidBatches.length) {
            this.runPost(this.url, { action: 'getMatches', pids: pidBatches[i] }, (data) => {
                const percentDone = Math.ceil((i + 1) * 100 / pidBatches.length);
                $(this.percentDiv).html(percentDone+"%");
                let photo = this.stateData.matchData.photo ?? "";
                if (data.photo) {
                    photo = data.photo;
                }
                this.stateData.matchData = this.deepMerge(this.stateData.matchData, data);
                this.stateData.matchData.photo = photo;
                this.refreshMatches(i + 1 >= pidBatches.length);
                this.getMatchesFromPids(pidBatches, i+1);
            });
        } else {
            this.prepareMatchesToSelect();
            $(this.percentDiv).html("");
        }
    }

    getMenu = function(projectTitle, pid, recordId, cb) {
        this.updateLoadingBox("Loading Menu...");
        this.stateData.menu = [];
        this.refreshMenu(projectTitle);
        this.runPost(this.url, { action: 'getMenu', record: recordId, pid: pid }, (data) => {
            this.stateData.menu = data.menu ?? {};
            this.refreshMenu(projectTitle);
            this.updateLoadingBox("");
            if (window.location.hash) {
                const hashAry = window.location.hash.replace(/^#/, '').split(/:/);
                if (hashAry.length === 3) {
                    const action = hashAry[0];
                    this.takeAction(action, this.getMenuTitle(action));
                } else if (window.location.hash === '#board') {
                    this.takeAction('board', 'Bulletin Board');
                }
            } else {
                $('#welcomeMessage').show();
            }
            if (cb) {
                cb();
            }
        });
    }

    getMenuTitle = function(action) {
        const menu = this.stateData.menu ?? {};
        for (const menuItem in menu) {
            for (let i = 0; i < menu[menuItem].length; i++) {
                const subMenuItem = this.stateData.menu[menuItem][i];
                if (subMenuItem['action'] === action) {
                    return subMenuItem['title'];
                }
            }
        }
    }

    prependToPosts = function(html) {
        const previousText = $(this.postsDiv).html();
        const newText = previousText.match(/Nothing has been posted yet/) ? html : html+previousText;
        $(this.postsDiv).html(newText);
    }

    deletePost = function(postuser, datetime) {
        if (!postuser || !datetime) {
            return false;
        }
        const postData = {
            action: 'delete_post',
            postuser: postuser,
            date: datetime
        };
        this.runPost(this.url, postData, (data) => {
            if (data.html) {
                $('#mainBox').html(data.html);
            } else {
                this.processError("Unknown Request! " + JSON.stringify(data));
            }
        });
    }

    submitPost = function(postSelector) {
        const text = $(postSelector).val();
        if (text) {
            const postData = {
                action: 'submit_post',
                text: text
            };
            this.runPost(this.url, postData, (data) => {
                if (data.html) {
                    this.prependToPosts(data.html);
                    $(postSelector).val("");
                } else {
                    this.processError("Unknown Request! "+JSON.stringify(data));
                }
            });
        }
    }

    uploadPhoto = function(formSelector) {
        const url = $(formSelector).attr('action');
        $.ajax({
            url: url,
            type: 'POST',
            data: new FormData($(formSelector)[0]),
            cache: false,
            contentType: false,
            processData: false,
            success: (json) => {
                try {
                    const data = JSON.parse(json);
                    if (data.error) {
                        this.processError(data.error);
                    } else {
                        $.sweetModal({
                            icon: $.sweetModal.ICON_SUCCESS,
                            title: 'Saved',
                            content: "Saved!"
                        });
                        if (data.photo) {
                            this.stateData.matchData.photo = data.photo;
                            this.refreshPhoto();
                        }
                    }
                } catch (e) {
                    this.processError(e.message);
                }
            },
            error: (request, status, error) => {
                console.error("Error code ("+request.status+"): "+error);
                this.processError(request.responseText);
            }
        });
    }

    validateFile = function(ob) {
        const file = ob.files[0];
        const numMegabytes = 10;
        if (file && file.size > 1024 * 1024 * numMegabytes) {
            this.processError("The maximum file size is "+numMegabytes+" MB");
        }
    }

    refreshMenu = function (title) {
        if (typeof this.stateData.menu == "undefined") {
            $(this.menuDiv).html('');
            return;
        }

        const menuItemWidth = 140;
        const menuWidth = menuItemWidth * Object.keys(this.stateData.menu).length;
        let html = '';
        for (const menuItem in this.stateData.menu) {
            html += "<div class='dropdown'><button class='dropbtn menuItem'>"+menuItem+"</button>";
            html += "<div class='dropdown-content'>";
            for (let i=0; i < this.stateData.menu[menuItem].length; i++) {
                const subMenuItem = this.stateData.menu[menuItem][i];
                const hash = (subMenuItem['action'] === "board") ? subMenuItem['action'] : subMenuItem['action']+":"+this.selectedPid+":"+this.selectedRecord;
                html += "<a href='#"+hash+"' onclick='portal.takeAction(\""+subMenuItem['action']+"\", \""+subMenuItem['title']+"\"); return false;'>"+subMenuItem['title']+"</a>";
            }
            html += "</div></div>";
        }
        $(this.menuDiv).html(html).css({ width: menuWidth+"px" });
        if (html !== '') {
            $(this.menuDiv).show();
        }
        if (title) {
            $(this.projectDiv).html("Using Data from "+title);
        }
    }

    clearMainBox = function() {
        $('#mainBox').html('');
        $(this.loadingDiv+'.lower').hide();
    }

    getPID = function() {
        if (window.location.hash) {
            const nodes = window.location.hash.split(/:/);
            if (nodes.length >= 3) {
                return nodes[1];
            }
        }
        return "";
    }

    takeAction = function(action, label) {
        if (action === this.lastAction) {
            return;
        }
        if ((this.lastAction === 'scholar_collaborations') || (this.lastAction === 'group_collaborations')) {
            // amCharts dispose call: https://www.amcharts.com/docs/v4/tutorials/chart-was-not-disposed/
            const pid = this.getPID();
            if (typeof window['chartReg_'+pid] !== "undefined") {
                window['chartReg_'+pid].dispose();
                delete window['chartReg_'+pid];
            }
        }
        this.lastAction = action;
        $('#welcomeMessage').hide();
        this.clearMainBox();
        this.updateLoadingBox("Loading Data About "+label+"...");
        if (action === 'board') {
            window.location.hash = '#board';
        } else {
            window.location.hash = action+":"+this.selectedPid+":"+this.selectedRecord;
        }
        const queries = [];
        if (action === "connect") {
            for (const pid in this.stateData.matchData.matches) {
                for (const record in this.stateData.matchData.matches[pid]) {
                    const name = this.stateData.matchData.matches[pid][record];
                    queries.push({pid: pid, record: record, name: name});
                    break;
                }
                break;
            }
        } else if (action === "board") {
            $('#noDataMessage').hide();
            const menuHTML = $(this.menuDiv).html();
            if (menuHTML === '') {
                $(this.menuDiv).hide();
            }
            queries.push({pid: '', record: '', name: ''});
        } else if ((this.selectedPid !== '') && (this.selectedRecord !== '')) {
            for (const pid in this.stateData.matchData.matches) {
                for (const record in this.stateData.matchData.matches[pid]) {
                    if ((this.selectedPid === pid) && (this.selectedRecord === record)) {
                        const name = this.stateData.matchData.matches[pid][record];
                        queries.push({pid: pid, record: record, name: name});
                    }
                }
            }
        } else {
            for (const pid in this.stateData.matchData.matches) {
                for (const record in this.stateData.matchData.matches[pid]) {
                    const name = this.stateData.matchData.matches[pid][record];
                    queries.push({pid: pid, record: record, name: name});
                }
            }
        }
        this.runQuery(action, queries, 0);
    }

    runQuery = function(action, queries, i) {
        if (i < queries.length) {
            const query = queries[i];
            const postData = {
                action: action,
                pid: query.pid,
                record: query.record,
                name: query.name,
                projectTitle: this.stateData.matchData.projectTitles[query.pid]
            };
            console.log(i+": Running "+JSON.stringify(postData));
            this.runPost(this.url, postData, (data) => {
                if (this.lastAction === action) {
                    if (data.html) {
                        this.appendToMainBox(data.html);
                        this.runQuery(action, queries, i+1);
                    } else {
                        this.processError("Unknown Request! "+JSON.stringify(data));
                    }
                }
            });
        } else {
            this.updateLoadingBox("");
        }
    }

    appendToMainBox = function(html) {
        $('#mainBox').append(html);
        if ($('#mainBox').html() !== '') {
            $(this.loadingDiv+'.lower').show();
        } else {
            $(this.loadingDiv+'.lower').hide();
        }
    }

    getNumMatches = function() {
        const matches = this.stateData.matchData['matches'] ?? {};
        let count = 0;
        for (const pid in matches) {
            for (const recordId in matches[pid]) {
                count++;
            }
        }
        return count;
    }

    refreshPhoto = function() {
        const data = this.stateData.matchData ?? {};
        const photoBase64 = data.photo ?? "";
        const photo = photoBase64 ? "<img id='photo' src='"+photoBase64+"' alt='Photo' />" : "";
        if (photo) {
            $(this.photoDiv).html(photo);
        }
    }

    refreshMatches = function(done) {
        const data = this.stateData.matchData ?? {};
        if (Object.keys(data).length === 0) {
            return;
        }
        const projectTitles = data['projectTitles'] ?? {};
        const matches = data['matches'] ?? {};
        this.refreshPhoto();

        const numMatches = this.getNumMatches();
        const selectNote = (numMatches > 1) ? ": Select One" : "";
        const clearDiv = "<section style='clear: both'></section>";
        const matchesHeaderText = getPortalHeaderText();
        const matchesNote = '<p class="centered max-width nomargin">Each Flight Tracker project you have ever been a part of is listed below, including ones where you are no longer being tracked. Choose the project for which you wish to view your information.</p>';
        const header = '<'+this.emphHeader+' class="nomargin">'+matchesHeaderText+selectNote+'</'+this.emphHeader+'><h5 class="multiProject" style="display: none;">Select a Flight Tracker Project to Use Their Data</h5>'+matchesNote;
        let html = '';
        const boxWidth = $(this.matchDiv).width();
        const maxNumCols = Math.floor(boxWidth / (this.matchColWidth + this.matchColMarginWidth));
        const numCols = (maxNumCols > this.maxMatchCols) ? this.maxMatchCols : maxNumCols;
        let i = 0;
        for (const pid in matches) {
            const projectTitle = projectTitles[pid] ?? "";
            for (const recordId in matches[pid]) {
                const name = matches[pid][recordId];
                html += this.citeProjectHTML(projectTitle, recordId, name, pid, i);
                i++;
                if (i % numCols === 0) {
                    html += clearDiv;
                }
            }
        }
        if ((html === '') && done) {
            html = header + "<p class='centered'>No matches. <a href='https://redcap.link/flight_tracker'>Learn more about introducing your group's program director or administrator to Flight Tracker</a>.</p>";
        } else if (html === '') {
            html = header + "<p class='centered'>Searching...</p>";
        } else {
            const matchesDivWidth = this.calculateMatchesWidth(numMatches, boxWidth);
            const boxHeight = 120;
            html = header + "<div style='max-width: "+matchesDivWidth+"px; margin: 0 auto "+boxHeight+"px auto;'>"+html+"</div>";
        }
        $(this.matchDiv).html(html);
        $('.notme').on("click", (event) => {
            // stops from triggering div.match's onclick event
            event.stopPropagation();
        });
    }

    disassociate = function(pid, recordId) {
        this.runPost(this.url, { action: 'disassociate', pid: pid, record: recordId }, (data) => {
            if (typeof this.stateData.matchData.matches[pid][recordId] !== 'undefined') {
                delete this.stateData.matchData.matches[pid][recordId];
                if (Object.keys(this.stateData.matchData.matches[pid]).length === 0) {
                    delete this.stateData.matchData.matches[pid];
                }
                this.refreshMatches(true);
            }
        })
    }

    calculateMatchesWidth = function(numMatches, boxWidth) {
        const colWidth = this.matchColWidth;
        const marginWidth = this.matchColMarginWidth;
        const maxCols = this.maxMatchCols;
        let numCols = (numMatches > maxCols) ? maxCols : numMatches;
        do {
            const width = numCols * (colWidth + marginWidth);
            if (boxWidth > width) {
                return width;
            }
            numCols--;
        } while (numMatches >= 1);
        return colWidth + marginWidth;
    }

    citeProjectHTML = function(title, recordId, name, pid, i) {
        return "<div class='centered match' record='"+recordId+"' pid='"+pid+"' onclick='portal.setMatch("+i+");'><div class='bolded projectTitle'>"+title+"</div><div>Matched Name: "+name+"</div><div class='alignright'><a class='notme' href='javascript:;' onclick='portal.disassociate(\""+pid+"\", \""+recordId+"\");'>remove match</a></div></div>";
    }

    processError = function(mssg) {
        let onCloseEvent = () => {};
        if (mssg.match(/Unexpected token '<'/)) {
            mssg = "You need to log into REDCap again to proceed. After you log in, please refresh this page.";
            const popupUrl = location.href.replace("?", "?closeWindow&")
            onCloseEvent = () => { window.open(popupUrl, '_blank', "popup,width=400,height=400"); }
        } else if (mssg.match(/Unexpected token 'A'/)) {
            this.numRefreshes++;
            if (this.numRefreshes >= 3) {
                mssg = "A REDCap database error has occurred. Please try refreshing again in a few minutes.";
            } else {
                setTimeout(() => { this.refreshAction(); }, 2000);
                return;
            }
        }
        console.error(mssg);
        $.sweetModal({
            icon: $.sweetModal.ICON_ERROR,
            title: 'Error',
            content: mssg,
            onClose: onCloseEvent
        });
        this.updateLoadingBox("");
    }

    searchForMentor = function(url, pid, recordId, nameOb) {
        const nameToSearch = $(nameOb).val();
        if (nameToSearch) {
            const postData = {
                action: 'searchForName',
                pid: pid,
                record: recordId,
                name: nameToSearch
            };
            this.runPost(url, postData, (data) => {
                if (typeof data.html !== "undefined") {
                    $('#searchResults').html(data.html);
                } else {
                    this.processError("No UIDs");
                }
            });
        } else {
            this.processError("No name provided to lookup!");
        }
    }

    runPost = function(url, postData, cb) {
        if (typeof postData.redcap_csrf_token === "undefined") {
            postData.redcap_csrf_token = getCSRFToken();
        }
        console.log(JSON.stringify(postData));
        $.post(url, postData, (json) => {
            try {
                console.log(json.substring(0, 300) + "...");
                const data = JSON.parse(json);
                if (data.error) {
                    this.processError(data.error);
                } else {
                    this.numRefreshes = 0;
                    cb(data);
                }
            } catch(e) {
                this.processError(e.message);
            }
        });
    }

    submitMentorNameAndUserid = function(url, pid, recordId, nameOb, useridOb) {
        const nameValue = $(nameOb).val();
        const useridValue = $(useridOb).val();
        if (!nameValue) {
            this.processError("You must provide a name for the mentor!");
        } else {
            this.addMentorData(url, pid, recordId, nameValue, useridValue);
        }
    }

    selectMentors = function(url, pid, recordId, mentorName, radioOb) {
        if ($(radioOb+":checked").length === 0) {
            this.processError("You must select an option for the mentor "+mentorName+"!");
        } else {
            const selectedValue = $(radioOb+":checked").val();
            this.addMentorData(url, pid, recordId, mentorName, selectedValue);
        }
    }

    addMentorData = function(url, pid, recordId, name, userid) {
        const postData = {
            action: 'submitMentorNameAndUserid',
            pid: pid,
            record: recordId,
            name: name,
            userid: userid
        };
        this.runPost(url, postData, (data) => {
            $.sweetModal({
                icon: $.sweetModal.ICON_SUCCESS,
                title: 'Saved',
                content: "Saved!"
            });
            this.refreshAction();
        });
    }

    refreshAction = function() {
        const lastAction = this.lastAction;
        if (lastAction) {
            this.lastAction = "";
            const lastLabel = this.getMenuTitle(lastAction);
            this.takeAction(lastAction, lastLabel);
        }
    }
}
