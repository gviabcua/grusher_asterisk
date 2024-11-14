<?php

namespace App;

class Config {
	public $asteriskManager = [];
	protected $configFileName;
	function __construct(){
		$backLevel = 4;
		$this->configFileName = "config.ini";

		if ( ! file_exists( dirname( __DIR__, $backLevel ) ."/". $this->configFileName ) ) {
			$backLevel = 1;
		} 
		$config = parse_ini_file( dirname( __DIR__, $backLevel ) ."/". $this->configFileName, true);
		$this->asteriskManager = $config['AsteriskManager'];
	}
   
	public function getManagerConfig() {
		return $this->asteriskManager;
	}

	public function getManagerConfigHost() {
		return $this->asteriskManager['host'];
	}

	public function getManagerUsername() {
		return $this->asteriskManager['username'];
	}

	public function getManagerSecret() {
		return $this->asteriskManager['secret'];
	}

	public function getManagerport() {
		return $this->asteriskManager['port'];
	}
}