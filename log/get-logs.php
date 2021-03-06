<?php

$offset = \db_real_escape_string($_GET['start']);
$limit = \db_real_escape_string($_GET['length']);
$limitClause = "limit $limit offset $offset";

$whereClause = "";
if ($_REQUEST['search']['value']) {
    $value = $_REQUEST['search']['value'];
    $whereClause = "WHERE message LIKE '%$value%'";
}

$columnName = 'count(1)';
$result = $module->queryLogs("select $columnName $whereClause");
$row = db_fetch_assoc($result);
$totalRowCount = $row[$columnName];

$results = $module->queryLogs("
	select log_id, timestamp, message
	order by log_id desc
	$whereClause
	$limitClause
");

$rows = [];
while($row = $results->fetch_assoc()){
	$rows[] = $row;
}

?>

{
	"draw": <?=$_GET['draw']?>,
	"recordsTotal": <?=$totalRowCount?>,
	"recordsFiltered": <?=$totalRowCount?>,
	"data": <?=json_encode($rows)?>
}
