<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

abstract class OracleConnection
{
	public function connect() {
		$this->connection = oci_connect($this->getUserId(), $this->getPassword(), $this->getServer());
		if (!$this->connection) {
			throw new \Exception("Unable to connect: ".$this->getUserId()." ".$this->getServer()." ".json_encode(oci_error()));
		}
		Application::log("Has connection to ".$this->getUserId()." ".strlen($this->getPassword())." ".$this->getServer());
		Application::log("oci_error: ".json_encode(oci_error()));
	}

	# returns the data in an array
	# problematic for very large queries
	protected function query($sql) {
		if (!$this->connection) {
			throw new \Exception("Unable to connect! ".json_encode(oci_error()));
		}
		// Application::log("Has connection to ".$this->getServer());
		// Application::log("oci_error: ".json_encode(oci_error()));

		$stmt = oci_parse($this->connection, $sql);
		if (!$stmt) {
			throw new \Exception("Unable to parse ".$sql.": ".json_encode(oci_error()));
		}

		Application::log($sql);
		oci_execute($stmt);
		// Application::log("Statement returned ".oci_num_rows($stmt)." rows");
		if ($error = oci_error($stmt)) {
			throw new \Exception("Unable to execute statement. ".json_encode($error));
		}

		$data = [];
		while ($row = oci_fetch_assoc($stmt)) {
			$data[] = $row;
		}

		oci_free_statement($stmt);

		return Sanitizer::sanitizeArray($data);
	}

	public function close() {
		$result = oci_close($this->connection);
		if (!$result) {
			throw new \Exception("Unable to disconnect");
		}
		return $result;
	}

	abstract public function getUserId();
	abstract public function getPassword();
	abstract public function getServer();

	private $connection = null;
}
