<?php

use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

$params1 = [];
$params2 = [];
$offset = Sanitizer::sanitizeInteger($_GET['start']);
$limit = Sanitizer::sanitizeInteger($_GET['length']);
$limitClause = "limit ? offset ?";

$whereClause = "";
if ($_REQUEST['search']['value']) {
    $value = Sanitizer::sanitize($_REQUEST['search']['value']);
    $whereClause = "WHERE message LIKE ?";
    $params1[] = "%$value%";
    $params2[] = "%$value%";
}

$columnName = 'count(1)';
$result = $module->queryLogs("select $columnName $whereClause", $params1);
$row = db_fetch_assoc($result);
$totalRowCount = $row[$columnName];

$params2[] = $limit;
$params2[] = $offset;
$results = $module->queryLogs("
	select log_id, timestamp, message
	order by log_id desc
	$whereClause
	$limitClause
", $params2);

$rows = [];
while($row = $results->fetch_assoc()){
	$rows[] = $row;
}

?>

{
	"draw": <?= Sanitizer::sanitize($_GET['draw']) ?>,
	"recordsTotal": <?=$totalRowCount?>,
	"recordsFiltered": <?=$totalRowCount?>,
	"data": <?=json_encode($rows)?>
}
