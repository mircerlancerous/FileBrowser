function clipboard(){
	var self = this;
	this.Source = null;
	this.Copy = true;
	
	this.hasData = function(){
		if(this.Source !== null){
			return true;
		}
		return false;
	};
	
	this.copy = function(sourcepath){
		this.Source = decodeURIComponent(sourcepath);
		this.Copy = true;
	};
	
	this.cut = function(sourcepath){
		this.Source = decodeURIComponent(sourcepath);
		this.Copy = false;
	};
	
	this.paste = function(destpath,isDir){
		destpath = decodeURIComponent(destpath);
		var path = infoObj.path;
		var action = "copy";
		if(this.Copy){
			action += "&path="+encodeURIComponent(path)+
				"&source="+encodeURIComponent(this.Source)+
				"&destination="+encodeURIComponent(destpath);
		}
		else{
			action = "move&path="+encodeURIComponent(destpath)+
				"&source="+encodeURIComponent(this.Source);
		}
		var success = function(response){
				setInfo(response);
			};
		//reload the current directory in case of changes. Might be better to determine first if changes needed before doing reload (much more complicated)
		action += "&action2=getpath&path2="+encodeURIComponent(path);
		doAction(action,success);
		this.Source = null;
	};
}
var clipboardData = new clipboard();

function uploadManager(){
	var self = this;
	this.holder = null;
	this.barWidth = 150;
	
	this.newUpload = function(file,uploadPath){alert('uploads disabled');return;
		if(this.holder === null){
			this.holder = document.getElementById('upload');
		}
		//if file is a path to the file instead of an upload file object
		if(typeof(file) === 'string'){
			console.log(file);return;
		}
		
		var newDiv = document.createElement("div");
		newDiv.className = "holder";
		newDiv.innerHTML = "<span onclick=\"uploadObj.removeUploadBox(this);\">X</span><p>"+file.name+"</p>";
		var barDiv = document.createElement("div");
		barDiv.className = "progress";
		newDiv.appendChild(barDiv);
		this.holder.appendChild(newDiv);
		
		var xmlhttp;
		if (window.XMLHttpRequest){// code for IE7+, Firefox, Chrome, Opera, Safari
			xmlhttp=new XMLHttpRequest();
		}
		else{// code for IE6, IE5
			xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
		}
		
		var checkProgress = function(event,barDiv){
				var percent = 100;
				if(event){
					percent = parseInt(event.loaded / event.total * 100);
				}
				barDiv.style.width = (self.barWidth / 100 * percent) + "px";
			};
		
		xmlhttp.upload.addEventListener("progress",function(event){checkProgress(event,barDiv);},false);
		xmlhttp.onreadystatechange = function(){
				if(xmlhttp.readyState==4 && xmlhttp.status==200){
					if(xmlhttp.responseText == 'true'){
						//if the upload path equals the current path, then reload it
						var info = infoObj.path;
						if(uploadPath == info){
							getPath(uploadPath);
						}
					}
					else{
						alert(xmlhttp.responseText);
					}
				}
			};
		xmlhttp.onreadystatechange.bind(this);
		
		xmlhttp.open("POST","?drive="+encodeURIComponent(driveName)+
				"&action=upload&path="+encodeURIComponent(uploadPath)+
				"&file="+encodeURIComponent(file.name)
			,true);
		
		var formData = new FormData();
		formData.append('upload',file);
		xmlhttp.send(formData);
	};
	
	this.removeUploadBox = function(closeSpan){
		var box = closeSpan.parentNode;
		delete box.parentNode.removeChild(box);
	};
}
var uploadObj = new uploadManager();

var actionItem = null;
function showContextMenu(event,contextitem){
	event.preventDefault();
	event.stopPropagation();
	var mousepos = getMousePos(event);
	if(!mousepos){
		alert("Your browser doesn't support this feature");
		return;
	}
	var menu = document.createElement('div');
	menu.id = "contextmenu";
	menu.style.top = mousepos.posY+"px";
	menu.style.left = mousepos.posX+"px";
	var content = "";
	if(contextitem){
		actionItem = contextitem;
		selectItem(null,contextitem);
		var isDir = JSON.parse(contextitem.dataset.isdir);
		if(!isDir){
			content += "<div onclick=\"\">View</div>"
		}
		content += "<div onclick=\"\">Download</div>"+
			"<div class='separator'></div>"+
			"<div onclick=\"clipboardData.cut('"+encodeURIComponent(contextitem.dataset.path)+"');\">Cut</div>"+
			"<div onclick=\"clipboardData.copy('"+encodeURIComponent(contextitem.dataset.path)+"');\">Copy</div>";
		if(clipboardData.hasData()){
			var path = contextitem.dataset.path;
			if(!isDir){
				path = infoObj.path;
			}
			content += "<div onclick=\"clipboardData.paste('"+encodeURIComponent(path)+"',"+contextitem.dataset.isdir+");\">Paste</div>";
		}
		content += "<div class='separator'></div>"+
			"<div onclick=\"selectItem(event,actionItem);\">Rename</div>"+
			"<div onclick=\"deleteItem(actionItem);\">Delete</div>"+
			"<div class='separator'></div>";
	}
	else if(clipboardData.hasData()){
		content += "<div onclick=\"clipboardData.paste('"+encodeURIComponent(infoObj.path)+"',true);\">Paste</div>"+
			"<div class='separator'></div>";
	}
	content += "<div onclick=\"createFolder();\">New Folder</div>"+
		"<div onclick=\"createFile();\">New File</div>"+
		"<div onclick=\"document.location='options.php';\">Options</div>";
	menu.innerHTML = content;
	document.body.appendChild(menu);
}

function closeContextMenu(){
	var menu = document.getElementById('contextmenu');
	if(!menu){
		return;
	}
	delete menu.parentNode.removeChild(menu);
	actionItem = null;
}

function getMousePos(e){
	var mousepos = new Object();
	var ev=(!e)?window.event:e;//Moz:IE
	if (ev.pageX){mousepos.posX=ev.pageX;mousepos.posY=ev.pageY}//Mozilla or compatible
	else if(ev.clientX){mousepos.posX=ev.clientX;mousepos.posY=ev.clientY}//IE or compatible
	else{return false}//old browsers
	return mousepos;
}

var infoObj = null;
function setInfo(infostr){
	if(typeof(infostr) !== 'undefined'){
		try{
			infoObj = JSON.parse(infostr);
		}
		catch(e){
			alert(infostr);
			return;
		}
	}
	//set path info
	var pathelm = document.getElementById('path');
	pathelm.innerHTML = "";
	var path = infoObj.config.rootPath;
	var info = infoObj.path.split("/");
	var count = infoObj.config.rootPath.split("/").length;
	for(var i=0; i<info.length; i++){
		if(info[i] == ''){
			continue;
		}
		if(i < count-1){
			continue;
		}
		var first = true;
		if(i > 0 && i > count-1){
			path += "/"+info[i];
			var newSpan = document.createElement("span");
			newSpan.innerHTML = "/";
			pathelm.appendChild(newSpan);
			first = false;
		}
		var newAnchor = document.createElement("a");
		pathelm.appendChild(newAnchor);
		if(info[i] == '.'){
			newAnchor.innerHTML = infoObj.config.driveName;//"root";
		}
		else{
			if(!first){
				newAnchor.innerHTML = info[i];
			}
			else{
				newAnchor.innerHTML = infoObj.config.driveName;
			}
		}
		newAnchor.dataset.path = path;
		newAnchor.onclick = function(){
				getPath(this.dataset.path);
			};
		newAnchor.ondragover = function(event){event.preventDefault();};
		newAnchor.ondrop = function(event){droppedToFolder(event,this);};
		newAnchor.ondragenter = function(event){dragEnter(event,this);};
	}
	//set info content
	var infoElm = document.getElementById('info');
	infoElm.innerHTML = "";
	var newTable = document.createElement("table");
	infoElm.appendChild(newTable);
	var newRow = document.createElement("tr");
	newTable.appendChild(newRow);
	newRow.className = "columns";
	//create header
	for(var i=0; i<infoObj.header.length; i++){
		var newCell = document.createElement("td");
		newRow.appendChild(newCell);
		if(i == 0){
			newCell.colSpan = 2;
		}
		newCell.innerHTML = infoObj.header[i].title;
		if(infoObj.orderHeader == i){
			if(infoObj.orderAsc){
				newCell.innerHTML += " &darr;";
			}
			else{
				newCell.innerHTML += " &uarr;";
			}
		}
		newCell.onclick = (function(){
				var j = i;
				return function(){
						orderRows(j);
					};
			})();
	}
	//add items
	for(var i=0; i<infoObj.items.length; i++){
		newRow = document.createElement("tr");
		newTable.appendChild(newRow);
		newRow.className = "item";
		newRow.dataset.path = infoObj.items[i].path;
		newRow.draggable = true;
		newRow.onclick = function(event){selectItem(event,this);};
		newRow.ondragstart = function(event){dragStart(event,this);};
		newRow.ondragend = function(){dragEnd();};
		newRow.oncontextmenu = function(event){showContextMenu(event,this);};
		if(infoObj.items[i].isdir){
			newRow.ondblclick = function(){getPath(this);};
			newRow.ondragover = function(event){event.preventDefault();};
			newRow.ondrop = function(event){droppedToFolder(event,this);};
			newRow.ondragenter = function(event){dragEnter(event,this);};
			newRow.dataset.isdir = "true";
		}
		else{
			newRow.dataset.isdir = "false";
			newRow.ondrop = function(event){event.preventDefault();};
		}
		//create icon cell
		newCell = document.createElement("td");
		newRow.appendChild(newCell);
		newCell.className = "nopad";
		newCell.innerHTML = '<img src="images/'+infoObj.items[i].icon+'"/>';
		//create all other cells
		for(var v=0; v<infoObj.header.length; v++){
			newCell = document.createElement("td");
			newRow.appendChild(newCell);
			newCell.innerHTML = infoObj.items[i][infoObj.header[v].display];
			if(infoObj.header[v].isname){
				newCell.dataset.isname = "true";
			}
		}
	}
}

function orderRows(headeridx){
	if(infoObj.orderHeader == headeridx){
		infoObj.orderAsc = !infoObj.orderAsc;
	}
	else{
		infoObj.orderAsc = true;
		infoObj.orderHeader = headeridx;
	}
	
	infoObj.items.sort(doOrdering);
	
	setInfo();
}

function doOrdering(a,b){
	//if both are of the same type
	if((a.isdir && b.isdir) || (!a.isdir && !b.isdir)){
		var val = 0;
		var orderKey = infoObj.header[infoObj.orderHeader].orderby;
		if(orderKey === null){
			orderKey = infoObj.header[infoObj.orderHeader].display;
		}
		if(a[orderKey] > b[orderKey]){
			val = 1;
		}
		else if(a[orderKey] < b[orderKey]){
			val = -1;
		}
		//compare by name field in the case both are equal
		else{
			//find the name header
			for(var i=0; i<infoObj.header.length; i++){
				if(infoObj.header[i].isname){
					orderKey = infoObj.header[i].orderby;
					if(orderKey === null){
						orderKey = infoObj.header[i].display;
					}
					if(a[orderKey] > b[orderKey]){
						val = 1;
					}
					else if(a[orderKey] < b[orderKey]){
						val = -1;
					}
					break;
				}
			}
		}
		if(!infoObj.orderAsc){
			val *= -1;
		}
		return val;
	}
	//if a is a directory and b is not
	if(a.isdir){
		return -1;
	}
	//b must be a directory and a is not
	return 1;
}

function openSettings(){
	var success = function(response){
			setInfo(response);
		};
	var action = "getsettings";
	doAction(action,success);
}

var draggedItem = null;
function dragStart(event,dragitem){
	draggedItem = dragitem;
	event.dataTransfer.setData("Drive",driveName);
	event.dataTransfer.setData("Path",dragitem.dataset.path);
}

function dragEnd(){
	if(draggedItem){
		getPath(infoObj.path);
	}
	draggedItem = null;
}

var dragHoverItem = null;
function dragEnter(event,hoveritem){
	if(typeof(event) !== 'undefined' && event){
		event.preventDefault();
		event.stopPropagation();
	}
	if(dragHoverItem !== null){
		dragHoverItem.classList.remove('itemdragover');
		dragHoverItem = null;
	}
	if(typeof(hoveritem) !== 'undefined' && hoveritem){
		dragHoverItem = hoveritem;
		hoveritem.classList.add('itemdragover');
	}
}

function droppedToFolder(event,destfolder){
	dragEnter(event);
	var files = event.dataTransfer.files;
	if(files.length == 0){
		if(draggedItem){
			moveItem(draggedItem,destfolder);
			draggedItem = null;
		}
		else{
			var sourceDrive = event.dataTransfer.getData("Drive");
			if(sourceDrive == driveName){
				moveItem(event.dataTransfer.getData("Path"),destfolder);
			}
			else{
				var success = function(response){
						setInfo(response);
					};
				var action = "copy&path="+encodeURIComponent(infoObj.path)+
					"&source="+encodeURIComponent(event.dataTransfer.getData("Path"))+
					"&sourcedrive="+encodeURIComponent(sourceDrive)+
					"&destination="+encodeURIComponent(destfolder.dataset.path)+
					"&action2=getpath";
				doAction(action,success);
			}
		}
		return;
	}
	for(var i=0; i<files.length; i++){
		uploadObj.newUpload(files[i],destfolder.dataset.path);
	}
}

function createFolder(){
	closeContextMenu();
	var folder = prompt("New folder name");
	if(!folder){
		return;
	}
	
	var path = infoObj.path;
	var success = function(response){
			setInfo(response);
		};
	var action = "newfolder&path="+encodeURIComponent(path)+
		"&name="+encodeURIComponent(folder)+
		"&action2=getpath";
	doAction(action,success);
}

function createFile(){
	closeContextMenu();
	var folder = prompt("New file name");
	if(!folder){
		return;
	}
	
	var path = infoObj.path;
	var success = function(response){
			setInfo(response);
		};
	var action = "newfile&path="+encodeURIComponent(path)+
		"&name="+encodeURIComponent(folder)+
		"&action2=getpath";
	doAction(action,success);
}

function moveItem(moveitem,destfolder){
	var success = function(response){
			try{
				if(JSON.parse(response) === true){
					if(typeof(moveitem) === 'string'){
						getPath(infoObj.path);
					}
					else{
						removeItem(moveitem);
					}
				}
				else{
					alert('error moving file');
				}
			}
			catch(e){
				alert(response);
			}
		};
	var path = "";
	if(moveitem){
		if(typeof(moveitem) === 'string'){
			path = moveitem;
		}
		else{
			path = moveitem.dataset.path;
		}
	}
	else{
		path = infoObj.path;
	}
	if(typeof(destfolder) !== 'string'){
		destfolder = destfolder.dataset.path;
	}
	var action = "move&path="+encodeURIComponent(destfolder)+
		"&source="+encodeURIComponent(path);
	doAction(action,success);
}

function deleteItem(deleteitem){
	if(typeof(deleteitem) === 'undefined'){
		if(selItem == null){
			return;
		}
		deleteitem = selItem;
	}
	//do nothing if we're editing the name
	if(deleteitem.getElementsByTagName("input").length > 0){
		return;
	}
	var name = deleteitem.dataset.path.split("/");
	name = name[name.length - 1];
	if(JSON.parse(deleteitem.dataset.isdir) == true){
		if(!confirm("Are you sure you want to delete the folder "+name+" and all of its contents?")){
			return;
		}
	}
	else{
		if(!confirm("Are you sure you want to delete the file "+name+"?")){
			return;
		}
	}
	var success = function(response){
			if(response === 'true'){
				removeItem(deleteitem);
			}
			else{
				alert(response);
			}
		};
	var action = "delete&path="+encodeURIComponent(deleteitem.dataset.path);
	doAction(action,success);
}

function removeItem(removeitem){
	if(typeof(removeitem) === 'undefined' || !removeitem){
		if(selItem == null){
			return;
		}
		removeitem = selItem;
	}
	if(removeitem == selItem){
		selItem = null;
	}
	delete removeitem.parentNode.removeChild(removeitem);
}

var selItem = null;
function selectItem(event,newitem){
	closeContextMenu();
	if(event){
		event.stopPropagation();
	}
	if(selItem != null && selItem.classList.contains("selected")){
		var saveRename = saveRenameItem();
		if(event){
			if(!saveRename && selItem == newitem){
				renameItem();
			}
			if(selItem == newitem || newitem === null){
				if(newitem !== null){
					return;
				}
			}
		}
		selItem.classList.remove("selected");
	}
	selItem = newitem;
	if(newitem == null){
		return;
	}
	newitem.classList.add("selected");
}

var renameDelay = null;
function renameItem(){
	var list = selItem.getElementsByTagName("td");
	selItem.draggable = false;
	for(var i=0; i<list.length; i++){
		if(typeof(list[i].dataset.isname) !== 'undefined'){
			renameDelay = setTimeout(function(){
					var newInput = document.createElement("input");
					newInput.type = "text";
					newInput.value = list[i].innerHTML;
					newInput.dataset.oldvalue = newInput.value;
					list[i].innerHTML = "";
					list[i].appendChild(newInput);
					newInput.onclick = function(event){
							event.stopPropagation();
						};
					newInput.focus();
				},250);
			break;
		}
	}
}

function saveRenameItem(){
	if(!selItem){
		return false;
	}
	var inputElm = selItem.getElementsByTagName("input")[0];
	if(!inputElm){
		return false;
	}
	selItem.draggable = true;
	
	var newname = inputElm.value;
	if(newname == inputElm.dataset.oldvalue){
		cancelRenameItem();
		return;
	}
	
	var path = infoObj.path;
	var action = "rename&path="+encodeURIComponent(selItem.dataset.path)+
		"&newpath="+encodeURIComponent(path + "/" + newname);
	
	var success = (function(){
			var renameditem = selItem;
			return function(response){
					if(response !== 'false'){
						inputElm.parentNode.innerHTML = newname;
						renameditem.dataset.path = path + "/" + newname;
						if(JSON.parse(renameditem.dataset.isdir) == false){
							//the response will contain the name of the icon to be used
							var img = renameditem.getElementsByTagName('img')[0];
							img.src = response;
						}
					}
					else{
						alert("error saving new name");
						inputElm.parentNode.innerHTML = inputElm.dataset.oldvalue;
					}
				};
		}());
	doAction(action,success);
	
	return true;
}

function cancelRenameItem(){
	if(!selItem){
		return;
	}
	var inputElm = selItem.getElementsByTagName("input")[0];
	if(!inputElm){
		return;
	}
	selItem.draggable = true;
	inputElm.parentNode.innerHTML = inputElm.dataset.oldvalue;
}

function getPath(path){
	if(renameDelay){
		clearTimeout(renameDelay);
		renameDelay = null;
	}
	var success = function(response){
			setInfo(response);
		};
	var action = "getpath";
	if(typeof(path) !== 'undefined'){
		if(typeof(path) !== 'string'){
			path = path.dataset.path;
		}
		action += "&path="+encodeURIComponent(path);
	}
	doAction(action,success);
}

var driveName = "";
function doAction(action,successFunction,formData){
	var xmlhttp;
	if (window.XMLHttpRequest){// code for IE7+, Firefox, Chrome, Opera, Safari
		xmlhttp=new XMLHttpRequest();
	}
	else{// code for IE6, IE5
		xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
	}
	xmlhttp.onreadystatechange = function(){
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200){
				if(typeof(successFunction) === 'function'){
					successFunction(xmlhttp.responseText);
				}
			}
		};
	if(action != ""){
		action = "?drive="+encodeURIComponent(driveName)+"&action="+action;
	}
	if(typeof(formData) === 'undefined' || !formData){
		xmlhttp.open("GET",action,true);
		xmlhttp.send();
	}
	else{
		xmlhttp.open("POST",action,true);
		xmlhttp.send(formData);
	}
}

window.onkeydown = function(event){
		//console.log(event.keyCode);
		//if delete key pressed
		if(event.keyCode == 46){
			deleteItem();
		}
		//if escape key pressed
		if(event.keyCode == 27){
			cancelRenameItem();
		}
		//if enter key pressed
		if(event.keyCode == 13){
			saveRenameItem();
		}
	};

window.onclick = function(event){
		closeContextMenu();
		selectItem(event,null);
	};

window.oncontextmenu = function(event){
		event.preventDefault();
		showContextMenu(event,null);
	};

window.ondragover = function(event){
		event.preventDefault();
	};
window.ondragenter = function(event){
		event.preventDefault();
		dragEnter(event);
	};

window.ondrop = function(event){
		event.preventDefault();
		event.stopPropagation();
		
		var destfolder = infoObj.path;
		var files = event.dataTransfer.files;
		if(files.length == 0 && !draggedItem){
			var sourceDrive = event.dataTransfer.getData("Drive");
			if(driveName != sourceDrive){
				var success = function(response){
						setInfo(response);
					};
				var action = "copy&path="+encodeURIComponent(destfolder)+
					"&source="+encodeURIComponent(event.dataTransfer.getData("Path"))+
					"&sourcedrive="+encodeURIComponent(sourceDrive)+
					"&destination="+encodeURIComponent(destfolder)+
					"&action2=getpath";
				doAction(action,success);
			}
			else{
				moveItem(event.dataTransfer.getData("Path"),destfolder);
			}
			return;
		}
		if(draggedItem){
			draggedItem = null;
			return;
		}
		for(var i=0; i<files.length; i++){
			uploadObj.newUpload(files[i],destfolder);
		}
	};
/*
window.onbeforeunload = function(){
		return "Are you sure?";
	};
*/
window.onresize = function(){
		//right justify path if longer than holder - scrollbar? I'm thinking no for now
	};

window.onload = function(){
		if(driveName){
			getPath();
		}
		else{
			document.location = "options.php";
		}
	};
