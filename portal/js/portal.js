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

    compileMeSHTerms = function(numTerms) {
        const prefix = 'mesh_term_';
        const terms = [];
        for (let i=1; i <= numTerms; i++) {
            const field = prefix + i.toString();
            const term = $('#'+field+' option:selected').val();
            if (term !== "") {
                terms.push(term);
            }
        }
        return terms.join("; ");
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

    pretty = function(x) {
        return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    searchForTopics = function(url, topicString, field) {
        const trimmedTopicString = topicString.trim();
        const topics = trimmedTopicString ? trimmedTopicString.split(/\s*[,;]\s*/) : [];
        if (topics.length === 0) {
            this.processError("No topics provided!");
            return;
        }

        const specialLoadingDiv = "#searchingDiv";
        if (this.isLoading(specialLoadingDiv)) {
            this.processError("Please wait until your first search has completed!");
            return;
        }

        this.stateData.collaboratorMatches = {};
        this.runPost(this.url, {action: 'getPids'}, (data) => {
            const pids = data['pids'];
            if (pids.length === 0) {
                this.makeCollaboratorHTML(0);
            } else {
                this.updateLoadingBox("Searching for a Collaborator... This may take some time.", specialLoadingDiv)
                const pidBatches = this.batchPids(pids, 1);
                const doneCallback = function(self) { self.updateLoadingBox("", specialLoadingDiv); };
                const numProjectsLeft = this.getNumberOfProjectsInBatches(pidBatches, 0);

                $('#results').html(this.makeCollaboratorHTML(numProjectsLeft));
                this.iterateForCollaborators(url, null, field, pidBatches, 0, null, doneCallback);
            }
        });
    }

    processNewMatches = function(matches) {
        const onlyUnique = function(value, index, array) { return array.indexOf(value) === index; };
        for (let i = 0; i < matches.length; i++) {
            const match = matches[i];
            const name = match.name;    // names are formatted server-side to match against prior names
            const pmid = match.pmid;
            const email = match.email ?? "";
            if (typeof this.stateData.collaboratorMatches[name] !== "undefined") {
                // existing name
                if (this.stateData.collaboratorMatches[name].pmids.indexOf(pmid) < 0) {
                    // new PMID
                    this.stateData.collaboratorMatches[name].pmids.push(pmid);
                    this.stateData.collaboratorMatches[name].score += match.score;
                    if (typeof match.matched_terms == "object") {
                        // flatten into array - PHP passed it as an associative array
                        const newArray = [];
                        for (const item in match.matched_terms) {
                            newArray.push(match.matched_terms[item]);
                        }
                        match.matched_terms = newArray;
                    }
                    this.stateData.collaboratorMatches[name].terms = this.stateData.collaboratorMatches[name].terms.concat(match.matched_terms ?? []).filter(onlyUnique);
                }
            } else {
                // new name
                this.stateData.collaboratorMatches[name] = {
                    pmids: [pmid],
                    emails: [],   // added below if it exists
                    name: name,
                    pid: match.pid,
                    record: match.record,
                    score: match.score,
                    terms: match.matched_terms ?? []
                };
            }
            // compile emails for all instances
            if ((email !== '') && (this.stateData.collaboratorMatches[name].emails.indexOf(email.toLowerCase()) < 0)) {
                this.stateData.collaboratorMatches[name].emails.push(email.toLowerCase());
            }
        }
    }

    getSortedCollaboratorMatches = function() {
        const matchValues = Object.values(this.stateData.collaboratorMatches);
        matchValues.sort((a, b) => {
            // highest score on top - descending by score
            if (b.score > a.score) {
                return 1;
            } else if (a.score > b.score) {
                return -1;
            } else {
                return 0;
            }
        });
        return matchValues;
    }

    makeCollaboratorHTML = function(numProjectsLeft) {
        const projectNoun = (numProjectsLeft === 1) ? "project" : "projects";
        const numLeftMessage = "There are still "+numProjectsLeft+" more "+projectNoun+" to search...";
        const numNameMatches = Object.keys(this.stateData.collaboratorMatches).length;
        if ((numNameMatches === 0)  && (numProjectsLeft === 0)) {
            if (numProjectsLeft === 0) {
                return "<p>No matches found. Perhaps try another topic?</p>";
            } else {
                return "<p>No matches found yet. "+numLeftMessage+"</p>";
            }
        } else {
            let html = '';
            if (numProjectsLeft !== 0) {
                const namePlural = (numNameMatches === 1) ? "" : "s";
                html += "<p>"+this.pretty(numNameMatches)+" name"+namePlural+" have been matched. "+numLeftMessage+"</p>";
            }
            const sortedMatches = this.getSortedCollaboratorMatches();
            for (let i = 0; i < sortedMatches.length; i++) {
                const match = sortedMatches[i];
                if (match.pmids.length > 0) {
                    const emailLinks = [];
                    for (let j = 0; j < match.emails.length; j++) {
                        emailLinks.push("<a href='mailto:"+match.emails[j]+"'>"+match.emails[j]+"</a>");
                    }
                    const pubMedLink = "https://pubmed.ncbi.nlm.nih.gov/?term="+encodeURIComponent(match.pmids.join(","))+"&sort=pubdate";
                    const pluralPubs = (match.pmids.length === 1) ? "" : "s";
                    const pluralVerb = (match.pmids.length === 1) ? "es" : "";
                    const noun = (match.pmids.length === 1) ? "The paper" : "The papers";
                    const score = match.score ?? 0;
                    const name = match.name ?? "Unknown Name";

                    html += "<p class='centered max-width'><strong class='greentext'>"+name+"</strong> has "+match.pmids.length+" publication"+pluralPubs+" that match"+pluralVerb+" this topic [score: "+this.pretty(score)+"].<br/><a href='"+pubMedLink+"' target='_new'>"+noun+" can be accessed via PubMed.</a>";
                    const numTerms = match.terms.length;
                    if (numTerms > 0) {
                        const termPlural = (numTerms === 1) ? "" : "s";
                        html += "<br/><strong>"+this.pretty(numTerms)+" matched term"+termPlural+": </strong><span class='smaller'>"+match.terms.join("; ")+"</span>";
                    }
                    if (emailLinks.length === 0) {
                        html += "<br/>No emails on file.";
                    } else {
                        const emailPlural = (emailLinks.length === 1) ? "" : "s";
                        html += "<br/>Email"+emailPlural+" on file: "+emailLinks.join(", ")+".";
                    }
                    html += "</p>";

                    // I thought about adding a word cloud, adapted from publications/wordCloud.php into a new class.
                    // However, upon further reflection, a word cloud might obscure the results some by pulling in other topics.
                    // Plus, it never received much visible traction with anyone but me in Huddle.
                    // So I'm skipping it for now, but I'm documenting here in case it should be added at a later date.
                    // I kept pid and record in matches so that additions can be easily made if desired.
                }
            }
            return html;
        }
    }

    iterateForCollaborators = function(url, topics, field, pidBatches, i, alternativeTopics, doneCallback) {
        if (i >= pidBatches.length) {
            doneCallback(this);
        } else {
            const mainBox = $('#mainBox');
            const inputs = mainBox.find('input, textarea, select:not(.meshcombobox)');
            let formData = {};
            let counter = 0;
            inputs.each(function() {
                if ($(this).is('.custom-combobox-input')) {
                    if ($(this).val() !== '') {
                        formData['MeshTerm'+counter] = $(this).val();
                        counter++;
                    }
                } else {
                    formData[$(this).attr('id')] = $(this).val();
                }
            });
            let postdata = {
                action: 'search_projects_for_collaborator',
                field: field,
                pids: pidBatches[i],
                priorNames: Object.keys(this.stateData.collaboratorMatches)
            };
            postdata = { ...postdata, ...formData };
            // if undefined in postdata, alternativeTopics will be accessed by Flight Tracker and passed in the next iteration
            if (alternativeTopics !== null) {
                postdata['alternativeTopics'] = alternativeTopics;
            }
            this.runPost(url, postdata, (data) => {
                this.processNewMatches(data.matches ?? []);
                const numProjectsLeft = this.getNumberOfProjectsInBatches(pidBatches, i+1);
                $('#results').html(this.makeCollaboratorHTML(numProjectsLeft));
                this.iterateForCollaborators(url, topics, field, pidBatches, i + 1, data.alternativeTopics ?? [], doneCallback);
            });
        }
    }

    getNumberOfProjectsInBatches = function (pidBatches, startI) {
        let numProjectsLeft = 0;
        for (let j = startI; j < pidBatches.length; j++) {
            numProjectsLeft += pidBatches[j].length;
        }
        return numProjectsLeft;
    }

    isLoading = function(specialLoadingDiv) {
        const div = (typeof specialLoadingDiv !== 'undefined') ? specialLoadingDiv : this.loadingDiv;
        return ($(div).html() !== "");
    }

    updateLoadingBox = function(mssg, specialLoadingDiv) {
        const div = (typeof specialLoadingDiv !== 'undefined') ? specialLoadingDiv : this.loadingDiv;
        const prev = $(div).html();
        if (!mssg) {
            $(div).html('');
        } else if (prev) {
            $(div+" "+this.subHeader).html(mssg);
        } else {
            $(div).html("<"+this.subHeader+" class='nomargin'>"+mssg+"</"+this.subHeader+"><p class='nomargin centered'><img src='"+this.loadingUrl+"' alt='Loading...' style='width: 48px; height: 48px;'/></p><div id='percentDone' class='centered'></div>");
        }
    }

    batchPids = function(pids, numPidsInBatch)  {
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
        return pidBatches;
    }

    getMatches = function(url) {
        this.url = url;
        this.updateLoadingBox("Loading Matches from Flight Tracker...");
        this.runPost(this.url, {action: 'getPids'}, (data) => {
            const pids = data['pids'];
            if (pids.length > 1) {
                this.updateLoadingBox("Loading Matches from All "+pids.length+" Flight Trackers...");
            }
            const pidBatches = this.batchPids(pids, 3);
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

    rerouteNoDataPage = function(hash) {
        if ((hash === "#board") || (hash === "board")) {
            this.takeAction("board", "Bulletin Board");
        } else if ((hash === "#find_collaborator") || (hash === "find_collaborator")) {
            this.takeAction("find_collaborator", "Find a Collaborator");
        }
    }

    isNoDataHash = function(hash) {
        const noDataOptions = ["#board", "board", "#find_collaborator", "find_collaborator"];
        return (noDataOptions.indexOf(hash) !== -1);
    }

    prepareMatchesToSelect = function() {
        this.updateLoadingBox("");
        const numMatches = this.getNumMatches();
        if (numMatches === 1) {
            $('.multiProject').hide();
            this.setMatch(0);
        } else if ((numMatches === 0) && !this.isNoDataHash(window.location.hash)) {
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
        if (this.isNoDataHash(window.location.hash)) {
            this.rerouteNoDataPage(window.location.hash);
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
                } else if (this.isNoDataHash(window.location.hash)) {
                    this.rerouteNoDataPage(window.location.hash);
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
                const hash = this.isNoDataHash(subMenuItem['action']) ? subMenuItem['action'] : subMenuItem['action']+":"+this.selectedPid+":"+this.selectedRecord;
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
        if (this.isNoDataHash(action)) {
            window.location.hash = '#'+action;
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
        } else if (this.isNoDataHash(action)) {
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
                console.log(json.substring(0, 500) + "...");
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
