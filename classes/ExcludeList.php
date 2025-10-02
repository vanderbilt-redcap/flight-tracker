<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/ClassLoader.php");

class ExcludeList
{
	public function __construct($type, $pid, $excludeList = [], $metadataFields = []) {
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
		if (empty($metadataFields)) {
			$this->metadataFields = Download::metadataFields($this->token, $this->server);
		} else {
			$this->metadataFields = $metadataFields;
		}
		if (empty($excludeList)) {
			$this->excludeList = [$this->field => Download::excludeList($this->token, $this->server, $this->field, $this->metadataFields)];
		} else {
			$this->excludeList = [$this->field => $excludeList];
		}

		$this->topicField = "exclude_publication_topics";
		if (($this->type == "Publications") && in_array($this->topicField, $metadataFields)) {
			$this->excludeList[$this->topicField] = Download::excludeList($this->token, $this->server, $this->topicField, $this->metadataFields);
		}

		$this->link = Application::link("/wrangler/updateExcludeList.php");
	}

	public function updateValue($recordId, $field, $value) {
		if (($field == $this->field) || ($field == $this->topicField)) {
			$row = [
				"record_id" => $recordId,
				$field => $value,
			];
			$feedback = Upload::oneRow($row, $this->token, $this->server);
			return $feedback;
		} else {
			return [];
		}
	}

	public function makeEditForm($recordId, $includeJS = true) {
		$html = "";
		if ($includeJS) {
			$html .= $this->makeJS();
		}
		$recordExcludeList = [];
		if (($this->type == "Publications") && isset($this->excludeList[$this->topicField])) {
			$recordExcludeList[$this->field] = implode(", ", $this->excludeList[$this->field][$recordId] ?? []);
			$fields = [$this->field => "<span title='A list, separated by commas, of names that are to be *excluded* when matching on this record. This is a list of potential mismatches that should be omitted in any downloads.'>Comma-Separated Author Exclude List</span>: "];
			$fields[$this->topicField] = "<span title=\"A list, separated by commas, of words that are to be *excluded* when matching in this record's publication titles. This is a list of potential mismatches that should be omitted in any downloads.\">Comma-Separated Title-Words Exclude List</span>: ";
			$recordExcludeList[$this->topicField] = implode(", ", $this->excludeList[$this->topicField][$recordId] ?? []);
		} else {
			$fields = [$this->field => "<span title='A list, separated by commas, of names that are to be *excluded* when matching on this record. This is a list of potential mismatches that should be omitted in any downloads.'>Comma-Separated Exclude List</span>: "];
			$recordExcludeList[$this->field] = implode(", ", $this->excludeList[$this->field][$recordId] ?? []);
		}
		foreach ($fields as $field => $title) {
			$html .= "<p class='centered'>";
			$html .= $title;
			$html .= "<input type='text' style='width: 300px;' name='excludeList_$field' id='excludeList_$field' value='{$recordExcludeList[$field]}'>";
			$html .= " <button onclick='updateExcludeList(\"$recordId\", \"$field\", $(\"#excludeList_$field\").val()); return false;'>Update</button>";
			$html .= "</p>";
		}
		return $html;
	}

	public function makeJS() {
		$link = $this->link;
		$type = $this->type;
		$html = "<script>
        function updateExcludeList(record, field, value) {
            presentScreen('Saving...');
            $.post('$link', {'redcap_csrf_token': getCSRFToken(), field: field, type: '$type', record: record, value: value}, function(html) {
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
	protected $topicField;
	protected $token;
	protected $server;
	protected $metadataFields;
	protected $link;
}
