<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/ClassLoader.php");

class ExcludeList {
    public function __construct($type, $pid, $excludeList = [], $metadata = []) {
        $fields = [
            "Grants" => "exclude_grants",
            "Publications" => "exclude_publications",
            "ORCID" => "exclude_orcid",
        ];
        $validTypes = array_keys($fields);
        if (!in_array($type, $validTypes)) {
            throw new \Exception("Invalid Exclude-List type $type");
        }
        $this->type = $type;
        $this->pid = $pid;
        $this->field = $fields[$type];
        $this->token = Application::getSetting("token", $this->pid);
        $this->server = Application::getSetting("server", $this->pid);
        if (empty($metadata)) {
            $this->metadata = Download::metadata($this->token, $this->server);
        } else {
            $this->metadata = $metadata;
        }
        if (empty($excludeList)) {
            $this->excludeList = Download::excludeList($this->token, $this->server, $this->field, $this->metadata);
        } else {
            $this->excludeList = $excludeList;
        }
        $this->link = Application::link("/wrangler/updateExcludeList.php");
    }

    public function updateValue($recordId, $value) {
        $row = [
            "record_id" => $recordId,
            $this->field => $value,
            ];
        $feedback = Upload::oneRow($row, $this->token, $this->server);
        return $feedback;
    }

    public function makeEditForm($recordId, $includeJS = TRUE) {
        $recordExcludeList = implode(", ", $this->excludeList[$recordId]);
        $html = "";
        if ($includeJS) {
            $html .= $this->makeJS();
        }
        $html .= "<p class='centered'>";
        $html .= "Comma-Separated Exclude List: ";
        $html .= "<input type='text' style='width: 300px;' name='excludeList' id='excludeList' field='{$this->field}' value='$recordExcludeList'>";
        $html .= " <button onclick='updateExcludeList(\"$recordId\", $(\"#excludeList\").val()); return false;'>Update</button>";
        $html .= "</p>";
        return $html;
    }

    public function makeJS() {
        $link = $this->link;
        $type = $this->type;
        $html = "<script>
        function updateExcludeList(record, value) {
            presentScreen('Saving...');
            $.post('$link', {'redcap_csrf_token': getCSRFToken(), type: '$type', record: record, value: value}, function(html) {
                console.log(html);
                clearScreen();
            });
        }
        </script>";
        return $html;
    }

    protected $pid;
    protected $type;
    protected $field;
    protected $excludeList;
    protected $token;
    protected $server;
    protected $metadata;
    protected $link;
}
