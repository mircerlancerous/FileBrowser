<?php
class FileBrowserItem{
	public $path;
	public $content;
	public $date;
	public $isdir;
	public $size;
	public $sizeText;
	public $icon;
	public function FileBrowserItem(){}
}

class FolderContent{
	public $path;
	public $config;
	public $orderHeader = NULL;
	public $orderAsc = TRUE;
	public $header = array();
	public $items = array();
	public function FolderContent($path,$config){
		$this->path = $path;
		$this->config = $config;
	}
	public function AddHeader($header){
		if($this->orderHeader === NULL){
			$this->orderHeader = sizeof($this->header);
		}
		$this->header[] = $header;
	}
	public function AddItem($item){
		$this->items[] = $item;
	}
}

class FolderHeader{
	public $title;
	public $display;
	public $orderby;	//if NULL then order by the display property
	public $isname;
	public function FolderHeader($title,$display,$orderby=NULL,$isname=FALSE){
		$this->title = $title;
		$this->display = $display;
		$this->orderby = $orderby;
		$this->isname = $isname;
	}
}

class FTPFileBrowser{
	private $Path = "";
	private $Config = "";
	private $errors = "";
	private $conn_id = NULL;
	
	private $sourceObj = NULL;
	
	public function FTPFileBrowser($path,$config){
		$this->Path = $path;
		$this->Config = $config;
		
		if(function_exists("ftp_ssl_connect")){
			$this->conn_id = @ftp_ssl_connect($this->Config->ftp_server);
		}
		else{
			$this->conn_id = @ftp_connect($this->Config->ftp_server);
		}
		if($this->conn_id === FALSE){
			$this->addToErrors("failed to connect to ftp server");
			return;
		}
		$result = @ftp_login($this->conn_id, $this->Config->ftp_user_name, $this->Config->ftp_user_pass);
		if($result === FALSE){
			$this->Close();
			$this->addToErrors("login to ftp server failed");
			return;
		}
		$result = @ftp_pasv($this->conn_id,TRUE);
		if($result === FALSE){
			$this->Close();
			$this->addToErrors("failed to enter ftp passive mode");
		}
	}
	
	public function getDriveType(){
		return $this->Config->driveType;
	}
	
	public function Close(){
		if(!$this->conn_id){
			return;
		}
		ftp_close($this->conn_id); 
		$this->conn_id = NULL;
	}
	
	public function getErrors(){
		return $this->errors;
	}
	
	private function addToErrors($msg){
		if($this->errors){
			$this->errors .= "\r\n";
		}
		$this->errors .= $msg;
	}
	
	public function getPath(){
		$obj = new FolderContent($this->Path,$this->Config);
		$obj->AddHeader(new FolderHeader("Name","content",NULL,TRUE));
		$obj->AddHeader(new FolderHeader("Date Modified","date"));
		$obj->AddHeader(new FolderHeader("Size","sizeText","size"));
		
		$contents = $this->scandir();
		for($i=0; $i<2; $i++){
			foreach($contents as $content => $data){
				if($content == '.' || $content == '..'){
					continue;
				}
				$isDir = FALSE;
				if($data['type'] == 'directory'){
					$isDir = TRUE;
				}
				if(!$isDir && $i == 0){
					continue;
				}
				elseif($isDir && $i == 1){
					continue;
				}
				$obj->AddItem($this->getItem($content,$data,$isDir));
			}
		}
		echo json_encode($obj);
	}
	
	public function scandir($path=NULL){
		if($path === NULL){
			$path = $this->Path;
		}
		if(!$path){
			$path = ".";
		}
		if(is_array($children = @ftp_rawlist($this->conn_id, $path))){
			$items = array();
			
			foreach($children as $name => $child){
				if(!$name){
					continue;
				}
				$chunks = preg_split("/\s+/", $child);
				list($item['rights'], $item['number'], $item['user'], $item['group'], $item['size'], $item['month'], $item['day'], $item['time']) = $chunks;
				$item['type'] = $chunks[0]{0} === 'd' ? 'directory' : 'file';
				array_splice($chunks, 0, 8);
				$items[implode(" ", $chunks)] = $item;
			}
			
			return $items;
		}
		return FALSE;
	}
	
	private function getItem($content,$data,$isDir=NULL){
		$obj = new FileBrowserItem();
		$obj->path = $this->Path."/".$content;
		$obj->content = $content;
		$obj->date = $data['month']." ".$data['day']." ".$data['time'];
		$obj->isdir = $isDir;
		if($isDir === NULL){
			$obj->isdir = FALSE;
			if($data['type'] == 'directory'){
				$obj->isdir = TRUE;
			}
		}
		$obj->size = NULL;
		if(!$obj->isdir){
			$obj->size = $data['size'];
			if($obj->size >= 0){
				$obj->sizeText = format_size($obj->size);
			}
		}
		$obj->icon = getIcon($content,$isDir);
		return $obj;
	}
	
	public function doDelete(){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$name = getPathName($this->Path);
		$path = substr($this->Path,0,strpos($this->Path,$name)-1);
		$contents = $this->scandir($path);
		foreach($contents as $content => $data){
			if($content == $name){
				if($data['type'] == 'directory'){
					return $this->delTree($this->Path);
				}
				return ftp_delete($this->conn_id,$this->Path);
			}
		}
		$this->addToErrors("file or directory not found");
		return FALSE;
	}

	private function delTree($dir){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$contents = $this->scandir($dir);
		foreach($contents as $file => $data){
			if($file == '.' || $file == '..'){
				continue;
			}
			if($data['type'] == 'directory'){
				if(!$this->delTree("$dir/$file")){
					$this->addToErrors("failed to delete content directories");
					return FALSE;
				}
			}
			else{
				if(!ftp_delete($this->conn_id,"$dir/$file")){
					$this->addToErrors("failed to delete content files");
					return FALSE;
				}
			}
		}
		if(!@ftp_rmdir($this->conn_id,$dir)){
			$this->addToErrors("failed to delete folder");
			return FALSE;
		}
		return TRUE;
	}
	
	public function doMove($source){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$name = getPathName($source);
		return @ftp_rename($this->conn_id,$source,$this->Path."/".$name);
	}
	
	public function doCopy($source,$destination,$sourceDrive=NULL){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$name = getPathName($source);
		if($sourceDrive){
			$sourceConfig = getConfig($sourceDrive);
			if($sourceConfig->driveType == 'ftp'){
				$this->sourceObj = new FTPFileBrowser($source,$sourceConfig);
			}
			else{
				$this->sourceObj = new FileBrowser($source,$sourceConfig);
			}
		}
		else{
			$this->sourceObj = $this;
		}
		if(!$this->sourceObj->isDir($source)){
			$result = $this->fileCopy($source,$destination."/".$name);
		}
		else{
			//copy a folder to another folder
			$result = $this->dirCopy($source,$destination."/".$name);
			if(!$result){
				$this->addToErrors("failed to copy folder");
			}
		}
		$this->sourceObj->Close();
		return $result;
	}
	
	public function getReadFileHandle($file){
		$handle = tmpfile();
		if(!$handle){
			return FALSE;
		}
		if(!ftp_fget($this->conn_id,$handle,$file,FTP_BINARY)){
			$this->addToErrors("failed to get ftp file - ".$file);
			return FALSE;
		}
		fseek($handle,0);
		return $handle;
	}
	
	private function fileCopy($source,$destination){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$srchandle = $this->sourceObj->getReadFileHandle($source);
		if(!$srchandle){
			$this->addToErrors($this->sourceObj->getErrors());
			return FALSE;
		}
		$result = $this->newFile($destination,$srchandle);		//$srchandle closed in newFile()
		return $result;
	}
	
	private function dirCopy($source,$destination){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		if(!$this->newFolder($destination)){
			return FALSE;
		}
		$contents = $this->sourceObj->scandir($source);
		foreach($contents as $file => $data){
			if($this->sourceObj->getDriveType() != 'ftp'){
				$file = $data;
			}
			if($file == '.' || $file == '..'){
				continue;
			}
			if($this->sourceObj->isDir($source.'/'.$file,$contents)){
				if(!$this->dirCopy($source.'/'.$file,$destination.'/'.$file)){
					return FALSE;
				}
			}
			else{
				if(!$this->fileCopy($source.'/'.$file,$destination.'/'.$file)){
					return FALSE;
				}
			}
		}
		return TRUE;
	}
	
	public function doRename($newpath){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		if(!@ftp_rename($this->conn_id,$this->Path,$newpath)){
			return FALSE;
		}
		$name = getPathName($newpath);
		return "images/".getIcon($name,is_dir($newpath));
	}
	
	public function isDir($path,$contents=NULL){
		$name = getPathName($path);
		if($contents === NULL){
			$path = getItemPath($path);
			$contents = $this->scandir($path);
		}
		foreach($contents as $content => $data){
			if($content == $name){
				if($data['type'] == 'directory'){
					return TRUE;
				}
				return FALSE;
			}
		}
		return FALSE;
	}
	
	public function newFolder($name){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		//check if directory exists first
		if($this->isDir($name)){
			return TRUE;
		}
		return @ftp_mkdir($this->conn_id,$name);
	}
	
	public function newFile($name,$handle=NULL){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		if($handle === NULL){
			$handle = tmpfile();
			if(!$handle){
				return FALSE;
			}
		}
		if(!ftp_fput($this->conn_id,$name,$handle,FTP_BINARY)){
			$this->addToErrors("failed to create file ".$name);
			return FALSE;
		}
		fclose($handle);
		return TRUE;
	}
	
	public function uploadFile($fileName){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		if($_FILES['upload']){
			if(!$_FILES['upload']['error']){
				if(isFileTypeUploadAllowed($_FILES['upload']['type'])){
					$handle = fopen($_FILES['upload']['tmp_name'],"r");
					return $this->newFile($this->Path."/".$_FILES['upload']['name'],$handle);
				}
				$this->addToErrors("type not allowed: ".$_FILES['upload']['type']);
			}
			else{
				if($_FILES['upload']['error'] == 1){
					$this->addToErrors("upload failed - file too large");
				}
				elseif($_FILES['upload']['error'] == 4){
					$this->addToErrors("upload failed - no file selected");
				}
				else{
					$this->addToErrors("upload error#: ".$_FILES['upload']['error']);
				}
			}
		}
		return FALSE;
	}
}

class FileBrowser{
	private $Path = "";
	private $Config = "";
	private $errors = "";
	
	private $sourceObj = NULL;
	
	public function FileBrowser($path,$config){
		$this->Path = $path;
		$this->Config = $config;
	}
	
	public function getDriveType(){
		return $this->Config->driveType;
	}
	
	public function Close(){}
	
	public function getErrors(){
		return $this->errors;
	}
	
	private function addToErrors($msg){
		if($this->errors){
			$this->errors .= "\r\n";
		}
		$this->errors .= $msg;
	}
	
	public function getPath(){
		if(!file_exists($this->Path)){
			echo "path not found";
			return FALSE;
		}
		$obj = new FolderContent($this->Path,$this->Config);
		$obj->AddHeader(new FolderHeader("Name","content",NULL,TRUE));
		$obj->AddHeader(new FolderHeader("Date Modified","date"));
		$obj->AddHeader(new FolderHeader("Size","sizeText","size"));
		$contents = $this->scandir($this->Path);
		for($i=0; $i<2; $i++){
			foreach($contents as $content){
				if($content == '.' || $content == '..'){
					continue;
				}
				$isDir = is_dir($this->Path."/".$content);
				if(!$isDir && $i == 0){
					continue;
				}
				elseif($isDir && $i == 1){
					continue;
				}
				$obj->AddItem($this->getItem($content,$isDir));
			}
		}
		echo json_encode($obj);
	}
	
	public function scandir($path=NULL){
		if($path === NULL){
			$path = $this->Path;
		}
		if(!$path){
			$path = ".";
		}
		return scandir($path);
	}
	
	public function getItem($content,$isDir=NULL){
		$obj = new FileBrowserItem();
		$obj->path = $this->Path."/".$content;
		$obj->content = $content;
		$obj->date = date("Y-m-d H:i",@filemtime($this->Path."/".$content));
		$obj->isdir = $isDir;
		if($isDir === NULL){
			$obj->isdir = is_dir($this->Path."/".$content);
		}
		$obj->size = NULL;
		if(!$obj->isdir){
			$obj->size = @filesize($this->Path."/".$content);
			if($obj->size >= 0){
				$obj->sizeText = format_size($obj->size);
			}
		}
		$obj->icon = getIcon($content,$isDir);
		return $obj;
	}
	
	public function doDelete(){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$path = $this->Path;
		if(is_dir($path)){
			return $this->delTree($path);
		}
		else{
			return @unlink($path);
		}
	}

	private function delTree($dir){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$files = array_diff(scandir($dir), array('.','..'));
		foreach($files as $file){
			if(is_dir("$dir/$file")){
				if(!$this->delTree("$dir/$file")){
					return FALSE;
				}
			}
			else{
				if(!unlink("$dir/$file")){
					return FALSE;
				}
			}
		}
		return @rmdir($dir);
	}
	
	public function doMove($source){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$name = getPathName($source);
		return @rename($source,$this->Path."/".$name);
	}
	
	public function doCopy($source,$destination,$sourceDrive=NULL){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$name = getPathName($source);
		if($sourceDrive){
			$sourceConfig = getConfig($sourceDrive);
			if($sourceConfig->driveType == 'ftp'){
				$this->sourceObj = new FTPFileBrowser($source,$sourceConfig);
			}
			else{
				$this->sourceObj = new FileBrowser($source,$sourceConfig);
			}
		}
		else{
			$this->sourceObj = $this;
		}
		if(!$this->sourceObj->isDir($source)){
			$result = $this->fileCopy($source,$destination."/".$name);
		}
		else{
			//copy a folder to another folder
			$result = $this->dirCopy($source,$destination."/".$name);
			if(!$result){
				$this->addToErrors("failed to copy folder - ".$source);
			}
		}
		$this->sourceObj->Close();
		return $result;
	}
	
	public function getReadFileHandle($file){
		if(!file_exists($file)){
			$this->addToErrors("filepath invalid - ".$file);
			return FALSE;
		}
		$handle = @fopen($file,"r");
		if(!$handle){
			$this->addToErrors("failed to get file handle for ".$file);
			return FALSE;
		}
		return $handle;
	}
	
	private function fileCopy($source,$destination){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$srchandle = $this->sourceObj->getReadFileHandle($source);
		if(!$srchandle){
			$this->addToErrors($this->sourceObj->getErrors());
			return FALSE;
		}
		$result = $this->newFile($destination,$srchandle);		//$srchandle closed in newFile()
		return $result;
	}
	
	private function dirCopy($source,$destination){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		$this->newFolder($destination);
		$contents = $this->sourceObj->scandir($source);
		foreach($contents as $file => $data){
			if($this->sourceObj->getDriveType() != 'ftp'){
				$file = $data;
			}
			if($file == '.' || $file == '..'){
				continue;
			}
			if($this->sourceObj->isDir($source.'/'.$file,$contents)){
				if(!$this->dirCopy($source.'/'.$file,$destination.'/'.$file)){
					return FALSE;
				}
			}
			else{
				if(!$this->fileCopy($source.'/'.$file,$destination.'/'.$file)){
					return FALSE;
				}
			}
		}
		return TRUE;
	}
	
	public function doRename($newpath){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		if(!@rename($this->Path,$newpath)){
			return FALSE;
		}
		$name = getPathName($newpath);
		return "images/".getIcon($name,is_dir($newpath));
	}
	
	public function isDir($path){
		return is_dir($path);
	}
	
	public function newFolder($name){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		return @mkdir($name);
	}
	
	public function newFile($name,$handle=NULL){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		if($handle === NULL){
			return @file_put_contents($name,"");
		}
		$nfhandle = fopen($name,"w");
		if(!$nfhandle){
			return FALSE;
		}
		stream_copy_to_stream($handle,$nfhandle);
		fclose($nfhandle);
		fclose($handle);
		return TRUE;
	}
	
	public function uploadFile($fileName){
		if($this->Config->readOnly){
			$this->addToErrors("This drive is read only");
			return FALSE;
		}
		if($_FILES['upload']){
			if(!$_FILES['upload']['error']){
				if(isFileTypeUploadAllowed($_FILES['upload']['type'])){
					return move_uploaded_file($_FILES['upload']['tmp_name'],$this->Path."/".$_FILES['upload']['name']);
				}
				$this->addToErrors("type not allowed: ".$_FILES['upload']['type']);
			}
			else{
				if($_FILES['upload']['error'] == 1){
					$this->addToErrors("upload failed - file too large");
				}
				elseif($_FILES['upload']['error'] == 4){
					$this->addToErrors("upload failed - no file selected");
				}
				else{
					$this->addToErrors("upload error#: ".$_FILES['upload']['error']);
				}
			}
		}
		return FALSE;
	}
}
	
function getIcon($file,$isDir){
	$img = "dir.gif";
	if(!$isDir){
		$ext = strrpos($file,".");
		$ext = substr($file,$ext+1);
		$ext = strtolower($ext);
		switch($ext){
			case 'php':case 'php4':case 'php5':
				$img = "php.gif";
				break;
			case 'txt':
				$img = "cgi.gif";
				break;
			case 'exe':case 'com':
				$img = "bin.gif";
				break;
			case 'css':
				$img = "css.gif";
				break;
			case 'js':
				$img = "js.gif";
				break;
			case 'gif':case 'jpeg':case 'jpg':case 'png':case 'bmp':
				$img = "image.gif";
				break;
			case 'zip':
				$img = "zip.gif";
				break;
			case 'avi':case 'mp4':case 'mpeg':
				$img = "video.gif";
				break;
			case 'doc':case 'docx':
				$img = "word.gif";
				break;
			case 'pdf':
				$img = "pdf.gif";
				break;
			case 'htm':case 'html':
				$img = "html.gif";
				break;
			case 'mp3':
				$img = "mp3.gif";
				break;
			default:
				$img = "file.gif";
				break;
		}
	}
	return $img;
}

function format_size($size){
	$units = explode(' ', 'B KB MB GB TB PB');
	
    $mod = 1024;
    
    for ($i = 0; $size > $mod; $i++){
        $size /= $mod;
    }
    
    $endIndex = strpos($size, ".") + 3;
    
    return substr( $size, 0, $endIndex).' '.$units[$i];
}

function getPathName($path){
	return substr($path,strrpos("/".$path,"/"));
}

function getItemPath($path){
	$pos = strrpos("/".$path,"/");
	return substr($path,0,$pos-1);
}

function isFileTypeUploadAllowed($type){
	$texttype = strpos($type,"text/") === 0 ? $type : "text/plain";
	switch($type){
		//http://www.feedforall.com/mime-types.htm
		//all text types
		case $texttype:
		//images
		case 'image/gif':case 'image/jpeg':case 'image/bmp':case 'image/png':case 'image/tiff':
		//doc,xls,csv
		case 'application/msword':case 'application/vnd.ms-excel':
		case 'application/pdf':case 'text/plain':
		//docx
		case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
		//xlsx
		case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
		//mp3,mpeg,avi,wav
		case 'audio/mpeg':case 'video/mpeg':case 'video/x-msvideo':case 'audio/x-wav':
		//zip,rar
		case 'application/x-zip-compressed':case 'application/x-rar-compressed':case 'application/zip':
			return TRUE;
		default:break;
	}
	return FALSE;
}

function getConfig($driveName){
	$configArr = unserialize(file_get_contents("config.dat"));
	if(!$configArr){
		return FALSE;
	}
	foreach($configArr as $config){
		if($config->driveName == $driveName){
			return $config;
		}
	}
	return FALSE;
}

include_once("config.php");

##########################################################################################

$actionKey = "";
$actionOutput = "";
while(array_key_exists("action".$actionKey,$_REQUEST)){
	if($actionKey == ''){
		if(!array_key_exists("drive",$_REQUEST) || !file_exists("config.dat")){
			header("Location: options.php");
			exit;
		}

		$config = getConfig($_REQUEST['drive']);
		if($config === FALSE){
			header("Location: options.php");
			exit;
		}

		date_default_timezone_set($config->timezone);
		$path = "";
	}
	if(array_key_exists("path".$actionKey,$_REQUEST)){
		$path = $_REQUEST['path'.$actionKey];
	}
	elseif(!$path){
		$path = $config->defaultPath;
	}
	switch($config->driveType){
		case 'local':
			$fbObj = new FileBrowser($path,$config);
			break;
		case 'ftp':
			$fbObj = new FTPFileBrowser($path,$config);
			break;
		default:
			echo "invalid drive type";
			exit;
	}
	$errors = $fbObj->getErrors();
	if($errors){
		echo $errors;
		exit;
	}
	$actionOutput = "";
	ob_start();
	switch($_REQUEST['action'.$actionKey]){
		case 'upload':
			$result = $fbObj->uploadFile($_REQUEST['file']);
			if($result === TRUE){
				echo "true";
			}
			else{
				$error = $fbObj->getErrors();
				if(!$error){
					echo "unknown error";
				}
				else{
					echo $error;
				}
			}
			break;
		case 'newfile':
			echo json_encode($fbObj->newFile($path."/".$_REQUEST['name']));
			break;
		case 'newfolder':
			echo json_encode($fbObj->newFolder($path."/".$_REQUEST['name']));
			break;
		case 'rename':
			$result = $fbObj->doRename($_REQUEST['newpath']);
			if(!$result){
				echo 'false';
			}
			else{
				//name of the icon to use
				echo $result;
			}
			break;
		case 'move':
			echo json_encode($fbObj->doMove($_REQUEST['source']));
			break;
		case 'copy':
			$sourceDrive = NULL;
			if(array_key_exists("sourcedrive",$_REQUEST)){
				$sourceDrive = $_REQUEST['sourcedrive'];
			}
			$result = $fbObj->doCopy($_REQUEST['source'],$_REQUEST['destination'],$sourceDrive);
			if($result === TRUE){
				echo "true";
			}
			else{
				$error = $fbObj->getErrors();
				if(!$error){
					echo "unknown error";
				}
				else{
					echo $error;
				}
			}
			break;
		case 'delete':
			$result = $fbObj->doDelete();
			if($result === TRUE){
				echo "true";
			}
			else{
				$error = $fbObj->getErrors();
				if(!$error){
					echo "unknown error";
				}
				else{
					echo $error;
				}
			}
			break;
		case 'getpath':
			$fbObj->getPath();
			break;
		default:break;
	}
	if($actionKey == ''){
		$actionKey = 2;
	}
	else{
		$actionKey++;
	}
	$actionOutput = ob_get_clean();
}
if($actionKey !== ''){
	echo $actionOutput;
	$fbObj->Close();
	exit;
}
?><!DOCTYPE>
<html>
<head>
	<title>File Browser</title>
	<meta content="text/html;charset=utf-8" http-equiv="Content-Type">
	<meta content="utf-8" http-equiv="encoding">
	<link rel="shortcut icon" href="images/file.gif"/>
	<script type="text/javascript" src="includes/filebrowser.js"></script>
	<link href="includes/filebrowser.css" type="text/css" rel="stylesheet"/>
	<?php
	if(array_key_exists("driveName",$_REQUEST)){?>
		<script type="text/javascript">
			var driveName = <?php echo '"'.$_REQUEST['driveName'].'"';?>;
		</script>
		<?php
	}?>
</head>
<body>
	<div id="path"></div>
	<div id="info"></div>
	<div id="upload"></div>
</body>
</html>
