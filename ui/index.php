<?php
session_start();

$USER = 'admin';
$PASS = 'admin';
$ROOT = realpath('/home/arkhivist/itsjustlife.cloud/dimmi');
$TITLE = 'Dimmi WebEditor (itsjustlife.cloud)';

function json_response($data, $code=200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function safe_abs($rel) {
    global $ROOT;
    $rel = trim($rel);
    $path = realpath($ROOT . '/' . $rel);
    if ($path === false || strpos($path, $ROOT) !== 0) return false;
    return $path;
}

function is_text($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $whitelist = ['txt','md','json','yaml','yml','opml','xml','py','js','css','html','htm'];
    return in_array($ext, $whitelist);
}

function handle_api() {
    global $ROOT;
    if (!isset($_GET['api'])) return;
    if (!isset($_SESSION['auth'])) json_response(['error'=>'unauthorized'],401);
    $api = $_GET['api'];
    $path = $_GET['path'] ?? '';
    switch($api) {
        case 'list':
            $abs = safe_abs($path);
            if ($abs===false || !is_dir($abs)) json_response(['error'=>'bad path'],400);
            $items=[];
            foreach (scandir($abs) as $name) {
                if ($name==='.' || $name==='..') continue;
                $full = $abs . '/' . $name;
                $items[]=[
                    'name'=>$name,
                    'rel'=>ltrim(str_replace($ROOT,'',$full),'/'),
                    'type'=>is_dir($full)?'dir':'file',
                    'size'=>is_dir($full)?0:filesize($full),
                    'mtime'=>filemtime($full)
                ];
            }
            json_response(['items'=>$items]);
        case 'read':
            $abs = safe_abs($path);
            if ($abs===false || !is_file($abs) || !is_text($abs) || filesize($abs)>5*1024*1024) json_response(['error'=>'bad file'],400);
            json_response(['content'=>file_get_contents($abs)]);
        case 'write':
            $abs = safe_abs($path);
            if ($abs===false || !is_file($abs) || !is_text($abs)) json_response(['error'=>'bad file'],400);
            $data=json_decode(file_get_contents('php://input'),true);
            if (!isset($data['content'])) json_response(['error'=>'bad json'],400);
            file_put_contents($abs,$data['content']);
            json_response(['ok'=>true]);
        case 'mkdir':
            $abs = safe_abs($path);
            if ($abs===false || !is_dir($abs)) json_response(['error'=>'bad path'],400);
            $data=json_decode(file_get_contents('php://input'),true);
            $name=$data['name']??'';
            $target=safe_abs(trim($path,'/').'/'.$name);
            if ($target===false) json_response(['error'=>'bad name'],400);
            mkdir($target,0777,true);
            json_response(['ok'=>true]);
        case 'newfile':
            $abs = safe_abs($path);
            if ($abs===false || !is_dir($abs)) json_response(['error'=>'bad path'],400);
            $data=json_decode(file_get_contents('php://input'),true);
            $name=$data['name']??'';
            $target=safe_abs(trim($path,'/').'/'.$name);
            if ($target===false) json_response(['error'=>'bad name'],400);
            touch($target);
            json_response(['ok'=>true]);
        case 'delete':
            $abs = safe_abs($path);
            if ($abs===false) json_response(['error'=>'bad path'],400);
            if (is_dir($abs)) {
                if (count(scandir($abs))>2) json_response(['error'=>'dir not empty'],400);
                rmdir($abs);
            } else {
                unlink($abs);
            }
            json_response(['ok'=>true]);
        case 'rename':
            $abs = safe_abs($path);
            $data=json_decode(file_get_contents('php://input'),true);
            $to=$data['to']??'';
            $target=safe_abs($to);
            if ($abs===false || $target===false) json_response(['error'=>'bad path'],400);
            rename($abs,$target);
            json_response(['ok'=>true]);
        case 'upload':
            $abs = safe_abs($path);
            if ($abs===false || !is_dir($abs)) json_response(['error'=>'bad path'],400);
            if (!isset($_FILES['file'])) json_response(['error'=>'no file'],400);
            $name=basename($_FILES['file']['name']);
            $target=safe_abs(trim($path,'/').'/'.$name);
            if ($target===false) json_response(['error'=>'bad name'],400);
            move_uploaded_file($_FILES['file']['tmp_name'],$target);
            json_response(['ok'=>true]);
        case 'whereami':
            json_response(['root'=>$ROOT]);
        default:
            json_response(['error'=>'unknown api'],400);
    }
}

if (isset($_GET['api'])) handle_api();

if (isset($_POST['do_login'])) {
    if ($_POST['user']===$USER && $_POST['pass']===$PASS) {
        $_SESSION['auth']=true;
        header('Location: index.php');
        exit;
    }
    $error='Invalid credentials';
}

if (!isset($_SESSION['auth'])) {
?>
<!doctype html>
<html><head><meta charset="utf-8"><title><?php echo $TITLE; ?></title></head>
<body>
<h2><?php echo $TITLE; ?></h2>
<?php if(isset($error)) echo '<p style="color:red">'.htmlspecialchars($error).'</p>'; ?>
<form method="post">
User: <input name="user"><br>
Pass: <input name="pass" type="password"><br>
<button name="do_login">Login</button>
</form>
</body></html>
<?php
    exit;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?php echo $TITLE; ?></title>
<style>
body{font-family:sans-serif;margin:0;display:flex;flex-direction:column;height:100vh;}
#topbar{background:#eee;padding:4px;display:flex;align-items:center;gap:8px;}
#breadcrumb span{cursor:pointer;color:blue;margin-right:4px;}
#panels{flex:1;display:flex;overflow:hidden;}
#folders,#files{width:20%;border-right:1px solid #ccc;overflow:auto;}
#editor{flex:1;display:flex;flex-direction:column;}
#editor textarea{flex:1;width:100%;}
#links{border-top:1px solid #ccc;padding:4px;}
ul{list-style:none;margin:0;padding:0;}
li{padding:2px 4px;cursor:pointer;}
li:hover{background:#def;}
#actions button{margin-right:4px;}
</style>
</head>
<body>
<div id="topbar">
<div id="breadcrumb"></div>
<input id="pathjump" placeholder="Jump to path"/>
<button onclick="jumpPath()">Go</button>
</div>
<div id="panels">
<div id="folders"></div>
<div id="files"></div>
<div id="editor">
<div id="actions">
<button onclick="saveFile()">Save</button>
<button onclick="renameFile()">Rename</button>
<button onclick="deleteEntry()">Delete</button>
<button onclick="formatContent()">Format</button>
<button onclick="minifyContent()">Minify</button>
</div>
<textarea id="content"></textarea>
</div>
</div>
<div id="links">
<h3>Links</h3>
<textarea id="linksArea" rows="4" style="width:100%"></textarea>
<button onclick="saveLinks()">Save Links</button>
</div>
<script>
let currentDir='';
let currentFile='';
let currentLinksFile='';
function api(url,opts={}){return fetch(url,opts).then(r=>r.json());}
function openDir(path){
    currentDir=path;
    breadcrumb();
    api('index.php?api=list&path='+encodeURIComponent(path)).then(data=>{
        let f=document.getElementById('folders');
        let fi=document.getElementById('files');
        f.innerHTML='';fi.innerHTML='';
        let up=path.split('/');
        up.pop();
        let upPath=up.join('/');
        let ulF=document.createElement('ul');
        if(path){
            let li=document.createElement('li');
            li.textContent='..';
            li.onclick=()=>openDir(upPath);
            ulF.appendChild(li);
        }
        data.items.filter(i=>i.type==='dir').sort((a,b)=>a.name.localeCompare(b.name)).forEach(item=>{
            let li=document.createElement('li');
            li.textContent=item.name;
            li.onclick=()=>openDir(item.rel);
            ulF.appendChild(li);
        });
        f.appendChild(ulF);
        let ulFile=document.createElement('ul');
        data.items.filter(i=>i.type==='file').sort((a,b)=>a.name.localeCompare(b.name)).forEach(item=>{
            let li=document.createElement('li');
            li.textContent=item.name;
            li.onclick=()=>loadFile(item.rel);
            ulFile.appendChild(li);
        });
        fi.appendChild(ulFile);
    });
}
function breadcrumb(){
    let bc=document.getElementById('breadcrumb');
    bc.innerHTML='';
    let parts=currentDir.split('/');
    let path='';
    let root=document.createElement('span');
    root.textContent='/';
    root.onclick=()=>openDir('');
    bc.appendChild(root);
    for(let p of parts){
        if(!p) continue;
        path+=(path?'/':'')+p;
        let span=document.createElement('span');
        span.textContent=p+'/';
        span.onclick=(()=>{let t=path;return()=>openDir(t);})();
        bc.appendChild(span);
    }
}
function jumpPath(){
    let p=document.getElementById('pathjump').value;
    api('index.php?api=list&path='+encodeURIComponent(p)).then(()=>openDir(p)).catch(()=>alert('bad path'));
}
function loadFile(rel){
    currentFile=rel;
    currentLinksFile=rel+'.links.json';
    api('index.php?api=read&path='+encodeURIComponent(rel)).then(data=>{
        document.getElementById('content').value=data.content;
        loadLinks();
    });
}
function saveFile(){
    if(!currentFile){alert('No file');return;}
    api('index.php?api=write&path='+encodeURIComponent(currentFile),{
        method:'POST',
        body:JSON.stringify({content:document.getElementById('content').value})
    }).then(()=>openDir(currentDir));
}
function deleteEntry(){
    if(!currentFile){alert('No file');return;}
    if(!confirm('Delete '+currentFile+'?')) return;
    api('index.php?api=delete&path='+encodeURIComponent(currentFile),{method:'POST'}).then(()=>{
        document.getElementById('content').value='';
        currentFile='';
        openDir(currentDir);
    });
}
function renameFile(){
    if(!currentFile){alert('No file');return;}
    let to=prompt('Enter new relative path for '+currentFile,currentFile);
    if(!to) return;
    if(!confirm('Rename/move to '+to+'?')) return;
    api('index.php?api=rename&path='+encodeURIComponent(currentFile),{
        method:'POST',
        body:JSON.stringify({to:to})
    }).then(()=>{openDir(currentDir);currentFile=to;});
}
function formatContent(){
    let txt=document.getElementById('content').value;
    if(currentFile.endsWith('.json')){
        document.getElementById('content').value=JSON.stringify(JSON.parse(txt),null,2);
    }else if(currentFile.endsWith('.opml')||currentFile.endsWith('.xml')){
        let parser=new DOMParser();
        let xml=parser.parseFromString(txt,'application/xml');
        let serializer=new XMLSerializer();
        let str=serializer.serializeToString(xml);
        document.getElementById('content').value=formatXml(str);
    }else if(currentFile.endsWith('.yaml')||currentFile.endsWith('.yml')){
        alert('YAML formatting not yet implemented');
    }else{
        alert('No formatter for this file');
    }
}
function minifyContent(){
    let txt=document.getElementById('content').value;
    if(currentFile.endsWith('.json')){
        document.getElementById('content').value=JSON.stringify(JSON.parse(txt));
    }else if(currentFile.endsWith('.yaml')||currentFile.endsWith('.yml')){
        alert('YAML minify not yet implemented');
    }else{
        alert('No minifier for this file');
    }
}
function loadLinks(){
    api('index.php?api=read&path='+encodeURIComponent(currentLinksFile)).then(data=>{
        document.getElementById('linksArea').value=data.content;
    }).catch(()=>{document.getElementById('linksArea').value='';});
}
function saveLinks(){
    if(!currentFile){alert('No file');return;}
    api('index.php?api=write&path='+encodeURIComponent(currentLinksFile),{
        method:'POST',
        body:JSON.stringify({content:document.getElementById('linksArea').value})
    }).then(()=>alert('Links saved'));
}
function formatXml(xml){
    let P="\n",indent=0,xmlText=xml.replace(/(>)(<)(\/*)/g,'$1'+P+'$2$3');
    return xmlText.split(P).map(line=>{
        let pad='';
        if(line.match(/.+<\/\w[^>]*>$/)||line.match(/^<\/.+>$/)) indent--;
        for(let i=0;i<indent;i++) pad+='  ';
        if(line.match(/^<[^!?]+[^\/>]*[^\/]>$/)) indent++;
        return pad+line;
    }).join(P);
}
openDir('');
</script>
</body>
</html>
