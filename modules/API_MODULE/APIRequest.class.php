<?php

class APIRequest {
	public $username;
	public $password;
	public $command;
	public $syncId;

	function __construct($username, $password, $command, $syncId = 0) {
		$this->username = $username;
		$this->password = $password;
		$this->command = $command;
		$this->syncId = $syncId;
	}
}

?>