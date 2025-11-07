<?php

use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

$params1 = [];
$params2 = [];
$offset = Sanitizer::sanitizeInteger($_GET['start'] ?? 0);
$limit = Sanitizer::sanitizeInteger($_GET['length'] ?? 100);
$limitClause = "limit $limit offset $offset";

$whereClause = "";
if (isset($_REQUEST['search']['value'])) {
    $value = Sanitizer::sanitize($_REQUEST['search']['value']);
    $whereClause = "WHERE message LIKE ?";
    $params1[] = "%$value%";
    $params2[] = "%$value%";
}

$columnName = 'count(1)';
$result = $module->queryLogs("select $columnName $whereClause", $params1);
$row = $result->fetch_assoc();
$totalRowCount = $row[$columnName];

$results = $module->queryLogs("
	select log_id, timestamp, message
	order by log_id desc
	$whereClause
	$limitClause
", $params2);

$rows = [];
while($row = $results->fetch_assoc()){
    $sanitizedRow = Sanitizer::sanitizeArray($row);
    $sanitizedRow['message'] = html_entity_decode($sanitizedRow['message']);
	$rows[] = $sanitizedRow;
}
$json = json_encode($rows);
$totalRowCount = Sanitizer::sanitizeInteger($totalRowCount);

?>

{
	"draw": <?= Sanitizer::sanitizeInteger($_GET['draw'] ?? 1) ?>,
	"recordsTotal": <?=$totalRowCount?>,
	"recordsFiltered": <?=$totalRowCount?>,
	"data": <?=$json?>
}
