<?php

namespace ManiaControl;

/**
 * Database connection class
 *
 * @author steeffeen & kremsy
 */
class Database {
	/**
	 * Public properties
	 */
	public $mysqli = null;
	
	/**
	 * Private properties
	 */
	private $maniaControl = null;
	private $config = null;

	/**
	 * Construct database connection
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		
		// Load config
		$this->config = FileUtil::loadConfig('database.xml');
		
		// Get mysql server information
		$host = $this->config->xpath('host');
		$port = $this->config->xpath('port');
		$user = $this->config->xpath('user');
		$pass = $this->config->xpath('pass');
		
		if (!$host) {
			trigger_error("Invalid database configuration (host).", E_USER_ERROR);
		}
		if (!$port) {
			trigger_error("Invalid database configuration (port).", E_USER_ERROR);
		}
		if (!$user) {
			trigger_error("Invalid database configuration (user).", E_USER_ERROR);
		}
		if (!$pass) {
			trigger_error("Invalid database configuration (pass).", E_USER_ERROR);
		}
		
		$host = (string) $host[0];
		$port = (int) $port[0];
		$user = (string) $user[0];
		$pass = (string) $pass[0];
		
		// Open database connection
		$this->mysqli = new \mysqli($host, $user, $pass, null, $port);
		if ($this->mysqli->connect_error) {
			trigger_error($this->mysqli->connect_error, E_USER_ERROR);
		}
		$this->mysqli->set_charset("utf8");
		
		$this->initDatabase();
		$this->optimizeTables();
	}

	/**
	 * Destruct database connection
	 */
	public function __destruct() {
		$this->mysqli->close();
	}

	/**
	 * Connect to the defined database (create it if needed)
	 *
	 * @return bool
	 */
	private function initDatabase() {
		$dbname = $this->config->xpath('database');
		if (!$dbname) {
			trigger_error("Invalid database configuration (database).", E_USER_ERROR);
			return false;
		}
		$dbname = (string) $dbname[0];
		
		// Try to connect
		$result = $this->mysqli->select_db($dbname);
		if ($result) {
			return true;
		}
		
		// Create database
		$databaseQuery = "CREATE DATABASE ?;";
		$databaseStatement = $this->mysqli->prepare($databaseQuery);
		if ($this->mysqli->error) {
			trigger_error($this->mysqli->error, E_USER_ERROR);
			return false;
		}
		$databaseStatement->bind_param('s', $dbname);
		$databaseStatement->execute();
		if ($databaseStatement->error) {
			trigger_error($databaseStatement->error, E_USER_ERROR);
			return false;
		}
		$databaseStatement->close();
		
		// Connect to new database
		$this->mysqli->select_db($dbname);
		if ($this->mysqli->error) {
			trigger_error("Couldn't select database '{$dbname}'. " . $this->mysqli->error, E_USER_ERROR);
			return false;
		}
		return true;
	}

	/**
	 * Optimize all existing tables
	 *
	 * @return bool
	 */
	private function optimizeTables() {
		$showQuery = "SHOW TABLES;";
		$result = $this->mysqli->query($showQuery);
		if ($this->mysqli->error) {
			trigger_error($this->mysqli->error);
			return false;
		}
		$count = $result->num_rows;
		if ($count <= 0) {
			return true;
		}
		$optimizeQuery = "OPTIMIZE TABLE ";
		$index = 0;
		while ($row = $result->fetch_row()) {
			$tableName = $row[0];
			$optimizeQuery .= "`{$tableName}`";
			if ($index < $count - 1) {
				$optimizeQuery .= ", ";
			}
			$index++;
		}
		$optimizeQuery .= ";";
		$this->mysqli->query($optimizeQuery);
		if ($this->mysqli->error) {
			trigger_error($this->mysqli->error);
			return false;
		}
		return true;
	}
}

?>
