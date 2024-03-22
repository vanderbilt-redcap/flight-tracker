<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Wrangler {
    const PILOT_GRANT_SETTING = "pilot_grants";

    public function __construct($wranglerType, $pid) {
        $this->wranglerType = $wranglerType;
        $this->pid = $pid;
        $this->token = Application::getSetting("token", $this->pid);
        $this->server = Application::getSetting("server", $this->pid);
    }

    public static function uploadCitations($post, $token, $server, $pid) {
        $allCitationFields = array_merge(
            ["record_id"],
            Download::metadataFieldsByPidWithPrefix($pid, "citation_"),
            Download::metadataFieldsByPidWithPrefix($pid, "eric_")
        );
        $metadata = Download::metadataByPid($pid, $allCitationFields);
        $pmids = [];
        if (
            isset($post['finalized'])
            || isset($post['omissions'])
            || isset($post['resets'])
            || isset($post['pilotGrants'])
        ) {
            $records = Download::recordIdsByPid($pid);
            $wranglerType = Sanitizer::sanitize($post['wranglerType'] ?? "Publications");
            $newFinalized = Sanitizer::sanitizeArray($post['finalized'] ?? []);
            $newOmissions = Sanitizer::sanitizeArray($post['omissions'] ?? []);
            $newResets = Sanitizer::sanitizeArray($post['resets'] ?? []);
            $pilotGrants = Sanitizer::sanitizeArray($post['pilotGrants'] ?? []);
            $pilotGrantHash = self::makePilotGrantHash($pilotGrants);
            $recordId = Sanitizer::getSanitizedRecord($post['record_id'], $records);

            # makes fields more minimal to filter out extra bibliometrics
            $citationFields = ["record_id", "citation_pmid", "eric_id"];
            $redcapData = Download::fieldsForRecordsByPid($pid, $citationFields, [$recordId]);
            $maxInstance = [
                "citation" => REDCapManagement::getMaxInstance($redcapData, "citation", $recordId),
                "eric" => REDCapManagement::getMaxInstance($redcapData, "eric", $recordId),
            ];

            $priorIDs = [];
            $upload = [];
            $toProcess = ["1" => $newFinalized, "0" => $newOmissions, "" => $newResets];
            $instruments = [
                "citation" => "citation_pmid",
                "eric" => "eric_id",
            ];
            foreach ($toProcess as $val => $aryOfIDs) {
                foreach ($aryOfIDs as $id) {
                    $matched = FALSE;
                    foreach ($redcapData as $row) {
                        foreach ($instruments as $instrument => $idField) {
                            if (
                                ($row['record_id'] == $recordId)
                                && ($row['redcap_repeat_instrument'] == $instrument)
                                && ($id == $row[$idField])
                            ) {
                                $prefix = REDCapManagement::getPrefixFromInstrument($instrument);
                                $verifyField = ($wranglerType == "FlagPublications") ? $prefix."_flagged" : $prefix."_include";
                                $pilotGrantField = $prefix."_pilot_grants";
                                $uploadRow = [
                                    "record_id" => $recordId,
                                    "redcap_repeat_instrument" => $instrument,
                                    "redcap_repeat_instance" => $row['redcap_repeat_instance'],
                                    $verifyField => $val,
                                ];
                                if (in_array($pilotGrantField, $allCitationFields)) {
                                    $uploadRow[$pilotGrantField] = implode(", ", $pilotGrantHash[$id] ?? []);
                                }
                                $priorIDs[] = $id;
                                $upload[] = $uploadRow;
                                $matched = TRUE;
                                break;
                            }
                        }
                    }
                    if (!$matched) {
                        # new citation
                        $instrument = Citation::getInstrumentFromId($id);
                        $maxInstance[$instrument]++;
                        if ($instrument == "citation") {
                            $uploadRows = Publications::getCitationsFromPubMed([$id], $metadata, "manual", $recordId, $maxInstance[$instrument], [], $pid);
                        } else if ($instrument == "eric") {
                            $uploadRows = Publications::getCitationsFromERIC([$id], $metadata, "manual", $recordId, $maxInstance[$instrument], [], [], $pid);
                        } else {
                            $uploadRows = [];
                        }
                        foreach ($uploadRows as $uploadRow) {
                            $upload[] = $uploadRow;
                        }
                        $priorIDs[] = $id;
                    }
                }
            }
            foreach ($pilotGrantHash as $id => $pilotGrants) {
                if (!in_array($id, $priorIDs)) {
                    foreach ($redcapData as $row) {
                        foreach ($instruments as $instrument => $idField) {
                            if (
                                ($row['record_id'] == $recordId)
                                && ($row['redcap_repeat_instrument'] == $instrument)
                                && ($id == $row[$idField])
                            ) {
                                $prefix = REDCapManagement::getPrefixFromInstrument($instrument);
                                $pilotGrantField = $prefix."_pilot_grants";
                                $uploadRow = [
                                    "record_id" => $recordId,
                                    "redcap_repeat_instrument" => $instrument,
                                    "redcap_repeat_instance" => $row['redcap_repeat_instance'],
                                ];
                                if (in_array($pilotGrantField, $allCitationFields)) {
                                    $uploadRow[$pilotGrantField] = implode(", ", $pilotGrants);
                                }
                                $upload[] = $uploadRow;
                                $priorIDs[] = $id;
                                break;
                            }
                        }
                    }
                }
            }
            foreach ($redcapData as $row) {
                foreach ($instruments as $instrument => $idField) {
                    $prefix = REDCapManagement::getPrefixFromInstrument($instrument);
                    $pilotGrantField = $prefix . "_pilot_grants";
                    if (
                        ($row['record_id'] == $recordId)
                        && ($row['redcap_repeat_instrument'] == $instrument)
                        && !in_array($row[$idField], $priorIDs)
                        && ($row[$pilotGrantField] ?? "" !== "")
                    ) {
                        $uploadRow = [
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => $instrument,
                            "redcap_repeat_instance" => $row['redcap_repeat_instance'],
                        ];
                        if (in_array($pilotGrantField, $allCitationFields)) {
                            $uploadRow[$pilotGrantField] = "";
                        }
                        $upload[] = $uploadRow;
                    }
                }
            }
            if (!empty($upload)) {
                $data = Upload::rows($upload, $token, $server);
            } else {
                $data = ["error" => "You don't have any new citations enqueued to change!"];
            }
            $pubs = new Publications($token, $server, $metadata);
            $pubs->setRows($redcapData);
            $pubs->deduplicateCitations($recordId);
            return $data;
        } else if (isset($post['pmid'])) {
            $pmid = Sanitizer::sanitize($post['pmid']);
            if ($pmid) {
                $pmids = [$pmid];
            }
        } else if (isset($post['pmids'])) {
            $pmids = Sanitizer::sanitizeArray($post['pmids']);
        } else {
            return ["error" => "You haven't made any changes!"];
        }


        if ($pmids && !empty($pmids)) {
            $records = Download::recordIdsByPid($pid);
            $recordId = Sanitizer::getSanitizedRecord($post['record_id'], $records);
            # makes fields more minimal to filter out extra bibliometrics
            $citationFields = Application::getCitationFields($metadata);
            $redcapData = Download::fieldsForRecordsByPid($pid, $citationFields, [$recordId]);

            $existingPMIDs = [];
            foreach ($redcapData as $row) {
                if (($row['redcap_repeat_instrument'] == "citation") && $row['citation_pmid']) {
                    $existingPMIDs[] = $row['citation_pmid'];
                }
            }
            $dedupedPMIDs = [];
            foreach ($pmids as $pmid) {
                if (!in_array($pmid, $existingPMIDs)) {
                    $dedupedPMIDs[] = $pmid;
                }
            }

            if (!empty($dedupedPMIDs) && $recordId) {
                $maxInstance = REDCapManagement::getMaxInstance($redcapData, "citation", $recordId);
                $maxInstance++;
                $upload = Publications::getCitationsFromPubMed($dedupedPMIDs, $metadata, "manual", $recordId, $maxInstance, [], $pid);
                for ($i = 0; $i < count($upload); $i++) {
                    if ($upload[$i]['redcap_repeat_instrument'] == "citation") {
                        $upload[$i]['citation_include'] = '1';
                    }
                }
                if (!empty($upload)) {
                    $data = Upload::rows($upload, $token, $server);
                } else {
                    $data = [ "error" => "PubMed no longer reports information for these PMIDs: ".REDCapManagement::makeConjunction($dedupedPMIDs) ];
                }
            } else {
                $data = ["error" => "All of the requested PMIDs exist in the database. Perhaps they have been omitted earlier."];
            }
            $pubs = new Publications($token, $server, $metadata);
            $pubs->setRows($redcapData);
            $pubs->deduplicateCitations($recordId);
            return $data;
        } else {
            $data = [ "error" => "Empty list of PMIDs" ];
            return $data;
        }
    }

    public static function arePilotGrantsOn($pid) {
        $pilotGrants = Application::getSetting(self::PILOT_GRANT_SETTING, $pid);
        return !empty($pilotGrants);
    }

    public static function getPilotGrantOptions($pid) {
        return Application::getSetting(self::PILOT_GRANT_SETTING, $pid) ?: [];
    }

    private static function makePilotGrantHash($pilotGrantAry) {
        $hash = [];
        foreach ($pilotGrantAry as $id) {
            list($citationID, $optionID) = explode("___", $id);
            if ($citationID && $optionID) {
                # leave ERIC IDs as is because that's how they're represented in REDCap
                $citationID = preg_replace("/^PMID/", "", $citationID);
                if (!isset($hash[$citationID])) {
                    $hash[$citationID] = [];
                }
                $hash[$citationID][] = $optionID;
            }
        }
        return $hash;
    }


    public static function getWranglerJS($recordId, $submissionUrl, $currPid, $searchPage = "") {
        $searchJS = "";
        if ($searchPage) {
            $searchJS = "
                $(document).ready(function() {
                    $('#search').keydown(function(e) {
                        if ((e.keyCode === 13) || (e.keyCode === 9)) {
                            const page = '$searchPage';
                            const name = $('#search').val();
                            search(page, '#searchDiv', name);
                        }
                    });
                });";
        }
        $csrfToken = Application::generateCSRFToken();

        return "<script>
        $searchJS

        function processWranglingResult(data, nextRecord, nextPageUrl) {
            const params = getUrlVars();
            const wranglerType = params['wranglerType'] ? '&wranglerType='+params['wranglerType'] : '';
            const isSuccessful = ((data['count'] && (data['count'] > 0)) || (data['item_count'] && (data['item_count'] > 0)));
            if (isSuccessful && nextRecord && nextPageUrl) {
                const count = data['count'] ?? data['item_count'] ?? 0;
                const mssg = count+' upload';   // coded message
                if (typeof portal === 'undefined') {
                    window.location.href = nextPageUrl+getHeaders()+'&mssg='+encodeURI(mssg)+'&record='+nextRecord+wranglerType;
                } else {
                    portal.refreshAction();
                }
            } else if (isSuccessful) {
                $('#uploading').hide();
                const count = data['count'] ?? data['item_count'] ?? 0;
                $.sweetModal({
                    content: 'Successfully uploaded '+count+' items!',
                    icon: $.sweetModal.ICON_SUCCESS
                });
                if (typeof portal === 'undefined') {
                    location.reload();
                } else {
                    portal.refreshAction();
                }
            } else if (data['error'] && (data['error'] === 'You haven\'t made any changes!')) {
                $.sweetModal({
                    content: data['error'],
                    icon: $.sweetModal.ICON_ERROR
                });
            } else if (data['error'] || (data['errors'] && Array.isArray(data['errors']) && data['errors'].length > 0)) {
                const errorMessage = data['error'] ?? data['errors'].join('<br/>');
                $('#uploading').hide();
                $('#finalize').show();
                $.sweetModal({
                    content: 'ERROR: '+errorMessage,
                    icon: $.sweetModal.ICON_ERROR
                });
            } else {
                const mssg = '0 upload';   // coded message
                if (typeof portal === 'undefined') {
                    window.location.href = nextPageUrl+getHeaders()+'&mssg='+encodeURI(mssg)+'&record='+nextRecord+wranglerType;
                } else {
                    portal.refreshAction();
                }
            }
        }
        
        function makeWranglingMessage(cnt) {
            let str = 'items';
            if (cnt === 1) {
                str = 'item';
            }
            return cnt+' '+str+' uploaded';
        }

        function submitWranglerChanges(recordId, nextRecord, nextPageUrl) {
            if (!recordId) {
                recordId = getRecord();
            }
            const newFinalized = [];
            const newOmits = [];
            const resets = [];
            $('#finalize').hide();
            $('#uploading').show();
            const params = getUrlVars();
            const type = params['wranglerType'];
            $('input[type=hidden]').each(function(idx, elem) {
                const elemId = $(elem).attr('id');
                const value = $(elem).val();
                let id = '';
                if ((typeof elemId != 'undefined') && elemId.match(/^PMID/)) {
                    id = elemId.replace(/^PMID/, '');
                } else if ((typeof elemId != 'undefined') && elemId.match(/^E[DJ]/)) {
                    id = elemId;
                } else	if ((typeof elemId != 'undefined') && elemId.match(/^USPO/)) {
                    id = elemId.replace(/^USPO/, '');
                }
        
                if (id && (value === 'include')) {
                    // checked => put in finalized
                    newFinalized.push(id);
                } else if (id && (value === 'exclude')) {
                    // unchecked => put in omits
                    newOmits.push(id);
                } else if (id && (value === 'reset')) {
                    resets.push(id);
                }
            });
            const pilotGrants = [];
            $('.pilotGrant input[type=checkbox]:checked').each(function(idx, elem) {
                pilotGrants.push($(elem).attr('id'));
            });

            const url = '$submissionUrl';
            if (url) {
                const postdata = {
                    record_id: recordId,
                    wranglerType: type,
                    omissions: newOmits,
                    resets: resets,
                    finalized: newFinalized,
                    pilotGrants: pilotGrants,
                    action: 'save',
                    pid: '$currPid',
                    redcap_csrf_token: '$csrfToken'
                };
                console.log('Posting to '+url+' '+JSON.stringify(postdata));
                presentScreen('Saving...');
                $.ajax({
                    url: url,
                    method: 'POST',
                    data: postdata,
                    success: function(json) {
                        console.log(json);
                        clearScreen();
                        try {
                            const data = JSON.parse(json);
                            processWranglingResult(data, nextRecord, nextPageUrl);
                        } catch(exception) {
                            console.error(exception);
                            $.sweetModal({
                                content: exception,
                                icon: $.sweetModal.ICON_ERROR
                            });
                        }
                    },
                    error: function(e) {
                        clearScreen();
                        if (!e.status || (e.status !== 200)) {
                            console.error(JSON.stringify(e));
                            $('#uploading').hide();
                            $('#finalize').show();
                            $.sweetModal({
                                content: 'ERROR: '+JSON.stringify(e),
                                icon: $.sweetModal.ICON_ERROR
                            });
                        } else if (e.responseText && (e.status === 200)) {
                            const json = e.responseText;
                            console.log(json);
                            try {
                                const data = JSON.parse(json);
                                processWranglingResult(data, nextRecord, nextPageUrl);
                            } catch(exception) {
                                console.error(exception);
                                $.sweetModal({
                                    content: exception,
                                    icon: $.sweetModal.ICON_ERROR
                                });
                            }
                        } else {
                            console.log(JSON.stringify(e));
                        }
                    }
                });
            }
        }

        function resetCitation(id) {
            $('#'+id).val('reset');
            const resetButton = '".Application::link("wrangler/reset.png")."'
            $('#image_'+id).attr('src', resetButton);
        }

        function selectAllCitations(divSelector) {
            $(divSelector).find('img').each(function(idx, ob) {
                const id = $(ob).attr('id').replace(/^image_/, '');
                $('#'+id).val('include');
                $(ob).attr('src', '".Application::link('wrangler/checked.png')."');
            });
        }

        function unselectAllCitations(divSelector) {
            $(divSelector).find('img').each(function(idx, ob) {
                const id = $(ob).attr('id').replace(/^image_/, '');
                $('#'+id).val('exclude');
                $(ob).attr('src', '".Application::link('wrangler/unchecked.png')."');
            });
        }

        function includeCitations(citations, nextUrl) {
            const pmids = [];
            if (citations) {
                const splitCitations = citations.split(/\\n/);
                for (let i = 0; i < splitCitations.length; i++) {
                    const citation = splitCitations[i];
                    if (citation) {
                        const pmid = getPMID(citation);
                        if (pmid) {
                            pmids.push(pmid);
                        }
                    }
                }
            }
            lookupPMIDs(pmids, nextUrl);
        }
        
        function lookupPMIDs(pmids, nextUrlOrCb) {
            if (pmids.length > 0) {
                presentScreen('Downloading...');
                const postdata = {
                    'redcap_csrf_token': '$csrfToken',
                    record_id: '$recordId',
                    pmids: pmids,
                    pid: '$currPid',
                    action: 'save'
                };
                saveItems('$submissionUrl', postdata, nextUrlOrCb);
            } else {
                $.sweetModal({
                    content: 'Please specify a citation!',
                    icon: $.sweetModal.ICON_ERROR
                });
            }
        }
        
        function lookForMatches(url, destSel) {
            $.post(url, { request: 'matchName', redcap_csrf_token: '$csrfToken' }, (json) => {
                console.log(json);
                $(destSel).html('');
                const data = JSON.parse(json);
                if (data.matches.length > 0) {
                    let html = '<div class=\"max-width bin padded light_grey\">';
                    let totalCitations = 0;
                    for (let i=0; i < data.matches.length; i++) {
                        const info = data.matches[i];
                        const pid = info['pid'] ?? '';
                        const record = info['record'] ?? '';
                        const name = info['name'] ?? '';
                        const projectName = info['project'] ?? '';
                        const newCitations = info['new_citations'] ?? [];
                        totalCitations += newCitations.length;
                        if (pid && record && name && (newCitations.length > 0)) {
                            const tag = name+' in '+projectName;
                            html += '<h4>'+newCitations.length+' Already Accepted from PubMed<br/>In Record '+record+': '+tag+' (pid '+pid+')</h4>';
                            if (newCitations.length > 1) {
                                const allPMIDs = [];
                                for (let j=0; j < newCitations.length; j++) {
                                    // allPMIDs cannot have quotes lest it interfere with the quotations
                                    // therefore, convert PMIDs to integers
                                    allPMIDs.push(parseInt(newCitations[j]['pmid']));
                                }
                                html += '<p class=\"centered\"><button class=\"green\" onclick=\"lookupPMIDs('+JSON.stringify(allPMIDs)+', () => { location.reload(); });\">Add All for This Project &amp; Refresh Page</button></p>';
                            }
                            for (let j=0; j < newCitations.length; j++) {
                                const citInfo = newCitations[j];
                                const citation = citInfo['citation'] ?? '';
                                const pmid = citInfo['pmid'] ?? '';
                                const pmcid = citInfo['pmcid'] ?? '';
                                const pmcidText = pmcid ? ' ('+pmcid+')' : '';
                                const addButton = ' <button class=\"smallest\" onclick=\"lookupPMIDs(['+pmid+']); return false;\">add</button>';
                                html += '<p class=\"alignLeft\"><strong>PMID '+pmid+pmcidText+'</strong>'+addButton+' <span class=\"smaller\">'+tag+'</span><br/>'+citation+'</p>';
                            }
                        }
                    }
                    if (totalCitations > 0) {
                        html += '<p class=\"centered\">After adding any citations:<br/><button class=\"green\" onclick=\"location.reload();\">Refresh Page</button></p>';
                        html += '</div>';
                        $(destSel).html(html);
                    } else {
                        $(destSel).html('');
                    }
                } else if (data.matches.length === 0) {
                    $(destSel).html('<p class=\"centered\">No matches in other projects.</p>');
                }
            });
        }
    
    function saveItems(url, postdata, nextUrlOrCb) {
        $.post(url, postdata, function(json) {
            console.log(json);
            const data = JSON.parse(json);
            if (data['error']) {
                makeNote(data['error']);
                $.sweetModal({
                    content: data['error'],
                    icon: $.sweetModal.ICON_ERROR
                });
                clearScreen();
            } else if (data['errors'] && (data['errors'].length > 0)) {
                makeNote(data['errors']);
                $.sweetModal({
                    content: data['errors'].join('<br/>'),
                    icon: $.sweetModal.ICON_ERROR
                });
                clearScreen();
            } else if (nextUrlOrCb && (typeof nextUrlOrCb === 'string') && (window.location.href !== nextUrlOrCb)) {
                window.location.href = nextUrlOrCb;
            } else if (nextUrlOrCb && (typeof nextUrlOrCb === 'string') && (window.location.href === nextUrlOrCb)) {
                location.reload();
            } else if (nextUrlOrCb && (typeof nextUrlOrCb === 'function')) {
                nextUrlOrCb();
            } else {
                makeNote();
                clearScreen();
            }
        });
    }

    function checkSubmitButton(citationSelector, enabledSelector) {
        const citations = $(citationSelector).val();
        if (citations) {
            $(enabledSelector+' button.includeButton').show();
        } else {
            $(enabledSelector+' button.includeButton').hide();
        }
    }

    function includeCitation(citation, nextUrl) {
        includeCitations(citation, nextUrl);
    }

    function resetPatent(id) {
        resetCitation(id);
    }

    function selectAllPatents(divSelector) {
        selectAllCitations(divSelector);
    }

    function unselectAllPatents(divSelector) {
        unselectAllCitations(divSelector);
    }

    function includePatents(patents, nextUrl) {
        const numbers = [];
        if (patents) {
            const splitPatents = patents.split(/\\n/);
            for (let i = 0; i < splitPatents.length; i++) {
                const patent = splitPatents[i];
                if (patent) {
                    const number = getPatentNumber(patent);
                    if (number) {
                        numbers.push(number);
                    }
                }
            }
        }
        if (numbers.length > 0) {
            presentScreen('Saving...');
            const postdata = {
                'redcap_csrf_token': '$csrfToken',
                record_id: '$recordId',
                numbers: numbers,
                action: 'save'
            };
            saveItems('$submissionUrl', postdata, nextUrl);
        } else {
            $.sweetModal({
                content: 'Please specify a patent!',
                icon: $.sweetModal.ICON_ERROR
            });
        }
    }

    function includePatent(patent, nextUrl) {
        includePatents(patent, nextUrl);
    }
</script>";
    }

    public function getEditText($notDoneCount, $includedCount, $recordId, $name, $lastName) {
        $person = "person";
        if (in_array($this->wranglerType, ["Publications", "FlagPublications"])) {
            $person = "author";
        } else if ($this->wranglerType == "Patents") {
            $person = "inventor";
        }
        $people = $person."s";
        if ($people == "persons") {
            $people = "people";
        }
        $singularWranglerType = ($this->wranglerType == "FlagPublications") ? "publication" : strtolower(substr($this->wranglerType, 0, strlen($this->wranglerType) - 1));
        $pluralWranglerType = $singularWranglerType."s";
        $lcWranglerType = ($this->wranglerType == "FlagPublications") ? "publications" : strtolower($this->wranglerType);
        $institutionFieldValues = Download::oneField($this->token, $this->server, "identifier_institution");
        $myInstitutions = $institutionFieldValues[$recordId] ? preg_split("/\s*[,;]\s*/", $institutionFieldValues[$recordId]) : [];
        $institutions = array_unique(array_merge($myInstitutions, Application::getInstitutions($this->pid), Application::getHelperInstitutions($this->pid)));

        $html = "";
        if ($this->wranglerType == "FlagPublications") {
            $html .= "<h1>Publication Flagger</h1>\n";
        } else {
            $html .= "<h1>".ucfirst($singularWranglerType)." Wrangler</h1>\n";
        }
        $html .= "<p class='centered'>This page is meant to confirm the association of $lcWranglerType with $people.</p>\n";
        if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
            $html .= "<h2>".$recordId.": ".$name."</h2>";
            $html .= "<p class='centered max-width'><strong>Institutions Searched For</strong>: ".REDCapManagement::makeConjunction($institutions)."</p>";
        }

        if (!NameMatcher::isCommonLastName($lastName) && ($notDoneCount > 0)) {
            $html .= "<p class='centered bolded'>";
            $html .= $lastName." is ".self::makeUncommonDefinition()." last name in the United States.<br>";
            $html .= "You likely can approve these $lcWranglerType without close review.<br>";
            $thisUrl = Application::link("wrangler/include.php");
            $html .= "<a href='javascript:;' onclick='submitWranglerChanges(\"$recordId\", $(\"#nextRecord\").val(), \"$thisUrl\"); return false;'><span class='green bolded'>Click here to approve all the $lcWranglerType for this record automatically.</span></a>";
            $html .= "</p>";
        }

        $existingLabel = self::getLabel($this->wranglerType, "Existing");
        if ($includedCount == 1) {
            $html .= "<h3 class='newHeader'>$includedCount $existingLabel ".ucfirst($singularWranglerType)." | ";
        } else if ($includedCount == 0) {
            $html .= "<h3 class='newHeader'>No $existingLabel ".ucfirst($pluralWranglerType)." | ";
        } else {
            $html .= "<h3 class='newHeader'>$includedCount $existingLabel ".ucfirst($pluralWranglerType)." | ";
        }

        $newLabel = self::getLabel($this->wranglerType, "New");
        if ($notDoneCount == 1) {
            $html .= "$notDoneCount $newLabel ".ucfirst($singularWranglerType)."</h3>\n";
        } else if ($notDoneCount == 0) {
            $html .= "No $newLabel ".ucfirst($pluralWranglerType)."</h3>\n";
        } else {
            $html .= "$notDoneCount $newLabel ".ucfirst($pluralWranglerType)."</h3>\n";
        }
        return $html;
    }

    public static function getLabel($wranglerType, $suggestedLabel) {
        if (($wranglerType == "FlagPublications") && ($suggestedLabel == "Existing")) {
            return "Flagged";
        } else if (($wranglerType == "FlagPublications") && ($suggestedLabel == "New")) {
            return "Unflagged";
        }
        return $suggestedLabel;
    }

    public static function makeUncommonDefinition() {
        return NameMatcher::makeUncommonDefinition();
    }

    public static function makeLongDefinition() {
        return NameMatcher::makeLongDefinition();
    }

    public function rightColumnText($recordId) {
        $prettyWranglerType = ($this->wranglerType == "FlagPublications") ? "Flagged Publications" : $this->wranglerType;
        $thisUrl = Application::link("wrangler/include.php");
        $html = "<button class='biggerButton green bolded' id='finalize' style='display: none; position: fixed; top: 200px;' onclick='submitWranglerChanges(\"$recordId\", $(\"#nextRecord\").val(), \"$thisUrl\"); return false;'>Finalize $prettyWranglerType</button><br>\n";
        $html .= "<div class='red shadow' style='height: 180px; padding: 5px; vertical-align: middle; position: fixed; top: 250px; text-align: center; display: none;' id='uploading'>\n";
        $html .= "<p>Uploading Changes...</p>\n";
        if ($this->wranglerType == "Publications") {
            $html .= "<p style='font-size: 12px;'>Redownloading citations from PubMed to ensure accuracy. May take up to one minute.</p>\n";
        }
        $html .= "</div>\n";

        # make button show/hide at various pixelations
        $html .= "
<script>
    $(document).ready(function() {
        // timeout to overcome API rate limit; 1.5 seconds seems adeqate; 1.0 seconds fails with immediate click
        setTimeout(function() {
            $('#finalize').show();
        }, 1500)
    });
</script>";
        return $html;
    }

    public static function getImageSize() {
        return 26;
    }

    public static function getImageLocation($img, $pid = "", $wranglerType = "") {
        $validImages = ["unchecked", "checked", "readonly"];
        if (!in_array($img, $validImages)) {
            throw new \Exception("Image ($img) must be in: ".implode(", ", $validImages));
        }
        if ($pid && Publications::areFlagsOn($pid) && ($wranglerType == "FlagPublications")) {
            $imgFile = "wrangler/flagged_".$img.".png";
        } else {
            $imgFile = "wrangler/".$img.".png";
        }
        return Application::link($imgFile, $pid);
    }

    # img is unchecked, checked, or readonly
    public static function makeCheckbox($id, $img, $pid = "", $wranglerType = "") {
        $imgFile = self::getImageLocation($img, $pid,$wranglerType);
        $checkedImg = self::getImageLocation("checked", $pid, $wranglerType);
        $uncheckedImg = self::getImageLocation("unchecked", $pid, $wranglerType);
        $size = self::getImageSize()."px";
        $js = "if ($(this).attr(\"src\").match(/unchecked/)) { $(\"#$id\").val(\"include\"); $(this).attr(\"src\", \"$checkedImg\"); } else { $(\"#$id\").val(\"exclude\"); $(this).attr(\"src\", \"$uncheckedImg\"); }";
        if ($img == "unchecked") {
            $value = "exclude";
        } else if ($img == "checked") {
            $value = "include";
        } else {
            $value = "";
        }
        $input = "<input type='hidden' id='$id' value='$value'>";
        if (($img == "unchecked") || ($img == "checked")) {
            return "<img src='$imgFile' id='image_$id' onclick='$js' style='width: $size; height: $size; float: left;'>".$input;
        }
        if ($img == "readonly") {
            return "<img src='$imgFile' id='image_$id' style='width: $size; height: $size; float: left;'>".$input;
        }
        return "";
    }

    protected $wranglerType;
    protected $pid;
    protected $token;
    protected $server;
}