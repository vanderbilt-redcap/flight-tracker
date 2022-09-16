<?php

use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

$offset = REDCapManagement::sanitize($_GET['start']);
$limit = REDCapManagement::sanitize($_GET['length']);
$limitClause = "limit $limit offset $offset";

$whereClause = "";
if ($_REQUEST['search']['value']) {
    $value = REDCapManagement::sanitize($_REQUEST['search']['value']);
    $whereClause = "WHERE message LIKE '%$value%'";
}

$columnName = 'count(1)';
$result = $module->queryLogs("select $columnName $whereClause", []);
$row = db_fetch_assoc($result);
$totalRowCount = $row[$columnName];

$results = $module->queryLogs("
	select log_id, timestamp, message
	order by log_id desc
	$whereClause
	$limitClause
", []);

$rows = [];
while($row = $results->fetch_assoc()){
	$rows[] = $row;
}

?>

{
	"draw": <?= REDCapManagement::sanitize($_GET['draw'])?>,
	"recordsTotal": <?=$totalRowCount?>,
	"recordsFiltered": <?=$totalRowCount?>,
	"data": <?=json_encode($rows)?>
}
