<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

function testConnectivity($token, $server, $pid, $howToReturn = "Email") {
    $sites = Application::getSites(FALSE);
    Application::log("Testing connection for ".count($sites)." servers");
    $html = "";
    if ($howToReturn == "HTML") {
        $html .= "
<script>
// coordinated with ConnectionStatus::encodeName()
function encodeName(str) {
    str = str.toLowerCase()
    str = str.replace(/\s+/g, '_')
    str = str.replace(/[^a-z]+|[^\w:\.\-]+/gi, '')
    return str
}

$(document).ready(function() {
    const sites = ".json_encode($sites).";
    for (const name in sites) {
        const server = sites[name]
        let encodedName = encodeName(name)
        $.post('" . Application::link('testConnectionStatus.php') . "', { 'redcap_csrf_token': getCSRFToken(), name: name }, function(json) {
            console.log(name+' ('+encodedName+'): '+json);
            let results = JSON.parse(json)
            let html = ''
            html += '<h2>'+name+' (<a href=\"https://'+server+'\">'+server+'</a>)</h2>'
            html += '<div class=\"centered bordered shadow\" style=\"max-width: 500px; margin 0 auto;\">'
            for (const key in results) {
                let result = results[key]
                var resultClass = 'green'
                if (result.match(/error/i)) {
                    resultClass = 'red'
                }
                html += '<p class=\"'+resultClass+' centered\" style=\"max-width: 500px; margin: 0 auto;\"><b>'+key+'</b>: '+result+'</p>'
            }
            html += '</div>'
            $('#'+encodedName).removeClass('yellow').html(html)
        })
    }
})
</script>";
        foreach ($sites as $name => $outboundServer) {
            $encodedName = ConnectionStatus::encodeName($name);
            $html .= "<div class='yellow centered' id='$encodedName'>Checking <b>$name</b> at $outboundServer...</div>\n";
        }
        return $html;
    } else {
        $numFailures = 0;
        $numTests = 0;
        foreach ($sites as $name => $outboundServer) {
            $connStatus = new ConnectionStatus($outboundServer, $pid);
            $results = $connStatus->test();
            foreach ($results as $key => $result) {
                if (preg_match("/error/i", $result)) {
                    Application::log("$server: $key - ".$result);
                    $numFailures++;
                }
                $numTests++;
            }
            $title = $name." (<a href='".$connStatus->getURL()."'>$server</a>)";
            $html .= ConnectionStatus::formatResultsInHTML($title, $results);
        }
        if ($numFailures == 0) {
            Application::log($numTests." tests passed over ".count($sites)." servers without failure");
        }

        $adminEmail = Application::getSetting("admin_email", $pid);
        $html = "
<style>
.green { background-color: #8dc63f; }
.red { background-color: #ffc3c4; }
</style>".$html;
        \REDCap::email($adminEmail, Application::getSetting("default_from", $pid), "Flight Tracker Connectivity Checker", $html);
    }
}