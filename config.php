<?php
class DriveConfig{
	public $driveName = "Local";
	public $driveType = "local";
	public $timezone = "America/Los_Angeles";
	public $defaultPath = "";
	public $rootPath = "";		//limits access by the file browser to this folder and its contents
	public $readOnly = FALSE;
	
	public $ftp_server = "";
	public $ftp_user_name = "";
	public $ftp_user_pass = "";
	
	public function Config(){
		$this->rootPath = rtrim($this->rootPath,"/");
	}
}
?>
