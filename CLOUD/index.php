<?php
/*  Dimmi WebEditor — DreamHost-friendly single-file PHP app
    Panels: FIND | STRUCTURE | CONTENT
    Security: session auth, CSRF, path jail ($ROOT), editable extensions whitelist
    Extras: breadcrumb + path bar, JSON/XML(OPML) formatter, audit log
*/

/* ===== CONFIG ===== */
$USER = getenv('WEBEDITOR_USER') ?: 'admin';
$PASS = getenv('WEBEDITOR_PASS') ?: 'admin';
$TITLE = 'Dimmi WebEditor (itsjustlife.cloud)';

$ROOT = (function () {
  // Prefer sibling ../dimmi relative to this file, else fall back to absolute
  $try = realpath(dirname(__FILE__) . '/../dimmi');
  if ($try && is_dir($try)) return $try;
  return realpath('/home/arkhivist/itsjustlife.cloud/dimmi');
})();

$MAX_EDIT = 5 * 1024 * 1024; // inline editor size cap
$EDIT_EXTS = ['txt','md','markdown','json','yaml','yml','xml','opml','csv','tsv','ini','conf','py','js','ts','css','html','htm','php'];
$LOG_FILE = $ROOT ? $ROOT.'/.webeditor.log' : null;

/* ===== AUTH ===== */
session_start();
if (isset($_POST['do_login'])) {
  $u = $_POST['u'] ?? ''; $p = $_POST['p'] ?? '';
  if (hash_equals($USER,$u) && hash_equals($PASS,$p)) {
    $_SESSION['auth'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    header('Location: '.$_SERVER['PHP_SELF']); exit;
  }
  $err = 'Invalid credentials';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit; }
$authed = !empty($_SESSION['auth']);
if ($authed && empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* ===== HELPERS ===== */
function j($x,$code=200){ http_response_code($code); header('Content-Type: application/json'); echo json_encode($x); exit; }
function bad($m,$code=400){ j(['ok'=>false,'error'=>$m],$code); }
function is_text($path){ global $EDIT_EXTS; $ext=strtolower(pathinfo($path, PATHINFO_EXTENSION)); return in_array($ext,$EDIT_EXTS); }
function safe_abs($rel){
  global $ROOT; if(!$ROOT) return false;
  $rel = ltrim($rel ?? '', '/');
  $abs = realpath($ROOT.'/'.$rel);
  if ($abs===false) $abs = $ROOT.'/'.$rel;                 // allow non-existing targets
  $abs = preg_replace('#/+#','/',$abs);
  $root = rtrim($ROOT,'/');
  if (strpos($abs,$root)!==0) return false;                // jail-break attempt
  return $abs;
}
function rel_of($abs){ global $ROOT; return trim(str_replace($ROOT,'',$abs),'/'); }
function audit($action,$rel,$ok=true,$extra=''){
  global $LOG_FILE; if(!$LOG_FILE) return;
  $ip = $_SERVER['REMOTE_ADDR'] ?? '-'; $ts = date('c');
  @file_put_contents($LOG_FILE, "$ts\t$ip\t$action\t$rel\t".($ok?'ok':'fail')."\t$extra\n", FILE_APPEND);
}

/* ===== API ===== */
if (isset($_GET['api'])) {
  if (!$authed) bad('Unauthorized',401);
  if (!$ROOT || !is_dir($ROOT)) bad('Invalid ROOT (set near top of file).',500);
  $action = $_GET['api']; $path = $_GET['path'] ?? ''; $abs = safe_abs($path);
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($method==='POST') { $hdr = $_SERVER['HTTP_X_CSRF'] ?? ''; if ($hdr !== ($_SESSION['csrf'] ?? '')) bad('CSRF',403); }

  if ($action==='whereami') j(['ok'=>true,'root'=>$ROOT]);

  if ($action==='list') {
    if (!is_dir($abs)) bad('Not a directory');
    $items = [];
    foreach (scandir($abs) as $n){
      if ($n==='.'||$n==='..') continue;
      $p="$abs/$n"; $items[]=['name'=>$n,'rel'=>rel_of($p),'type'=>is_dir($p)?'dir':'file','size'=>is_file($p)?filesize($p):0,'mtime'=>filemtime($p)];
    }
    j(['ok'=>true,'items'=>$items]);
  }

  if ($action==='read') {
    if (!is_file($abs)) bad('Not a file');
    if (!is_text($abs)) bad('Not an editable text file');
    if (filesize($abs) > $GLOBALS['MAX_EDIT']) bad('Refusing to open files > 5MB');
    j(['ok'=>true,'content'=>file_get_contents($abs)]);
  }

  if ($action==='write' && $method==='POST') {
    if (!is_file($abs)) bad('Not a file');
    if (!is_writable($abs)) bad('Not writable');
    if (!is_text($abs)) bad('Not an editable text file');
    $data = json_decode(file_get_contents('php://input'),true);
    if (!is_array($data) || !array_key_exists('content',$data)) bad('Bad JSON');
    $ok = file_put_contents($abs,$data['content'])!==false; audit('write',$path,$ok);
    j(['ok'=>$ok]);
  }

  if ($action==='mkdir' && $method==='POST') {
    $data=json_decode(file_get_contents('php://input'),true); $name=trim(($data['name']??''),'/');
    if ($name==='') bad('Missing name');
    $dst=safe_abs($path.'/'.$name); if($dst===false) bad('Invalid target');
    if (file_exists($dst)) bad('Exists already');
    $ok=mkdir($dst,0775,true); audit('mkdir',rel_of($dst),$ok); j(['ok'=>$ok]);
  }

  if ($action==='newfile' && $method==='POST') {
    $data=json_decode(file_get_contents('php://input'),true); $name=trim(($data['name']??''),'/');
    if ($name==='') bad('Missing name');
    $dst=safe_abs($path.'/'.$name); if($dst===false) bad('Invalid target');
    if (file_exists($dst)) bad('Exists already');
    $ok=file_put_contents($dst,"")!==false; audit('newfile',rel_of($dst),$ok); j(['ok'=>$ok]);
  }

  if ($action==='delete' && $method==='POST') {
    if (!file_exists($abs)) bad('Not found');
    $ok = is_dir($abs) ? @rmdir($abs) : @unlink($abs);     // rmdir only removes empty dirs
    audit('delete',$path,$ok); j(['ok'=>$ok]);
  }

  if ($action==='rename' && $method==='POST') {
    $data=json_decode(file_get_contents('php://input'),true);
    $to=trim(($data['to']??''),'/'); if($to==='') bad('Missing target');
    $dst=safe_abs($to); if($dst===false) bad('Invalid target');
    $ok = @rename($abs,$dst); audit('rename',$path,$ok,"-> ".rel_of($dst)); j(['ok'=>$ok]);
  }

  if ($action==='upload' && $method==='POST') {
    if (!is_dir($abs)) bad('Upload path is not a directory');
    if (empty($_FILES['file'])) bad('No file');
    $tmp=$_FILES['file']['tmp_name']; $name=basename($_FILES['file']['name']);
    $dst=safe_abs($path.'/'.$name); if($dst===false) bad('Bad target');
    $ok=move_uploaded_file($tmp,$dst); audit('upload',rel_of($dst),$ok); j(['ok'=>$ok,'name'=>$name]);
  }

  if ($action==='format' && $method==='POST') {
    if (!is_file($abs)) bad('Not a file');
    $data=json_decode(file_get_contents('php://input'),true);
    $content = $data['content'] ?? '';
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if ($ext==='json') {
      $decoded = json_decode($content,true); if ($decoded===null) bad('Invalid JSON',400);
      j(['ok'=>true,'content'=>json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)]);
    }
    if ($ext==='xml' || $ext==='opml') {
      if (!class_exists('DOMDocument')) bad('DOM extension not available on server',500);
      libxml_use_internal_errors(true);
      $dom=new DOMDocument('1.0','UTF-8'); $dom->preserveWhiteSpace=false; $dom->formatOutput=true;
      if(!$dom->loadXML($content)) bad('Invalid XML/OPML',400);
      j(['ok'=>true,'content'=>$dom->saveXML()]);
    }
    bad('Unsupported for format',400);
  }

  bad('Unknown action',404);
}

/* ===== HTML (UI) ===== */
if (!$authed): ?>
<!doctype html><meta charset="utf-8"><title><?=$TITLE?> — Login</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;background:#0f0f12;color:#e5e5e5;display:grid;place-items:center;height:100dvh;margin:0}
.card{background:#141418;border:1px solid #2e2e36;padding:24px;border-radius:16px;min-width:280px}
h1{margin:0 0 16px;font-size:18px}
input{width:100%;padding:10px 12px;margin:8px 0;background:#0f0f12;border:1px solid #2e2e36;color:#fff;border-radius:10px}
button{width:100%;padding:10px 12px;background:#1e1e26;border:1px solid #3a3a46;color:#fff;border-radius:10px;cursor:pointer}
.err{color:#ff6b6b;margin:8px 0 0}.tip{opacity:.7;font-size:12px;margin-top:6px}
</style>
<div class="card"><h1><?=$TITLE?></h1>
<form method="post">
  <input name="u" placeholder="user" autofocus>
  <input name="p" type="password" placeholder="password">
  <button name="do_login" value="1">Sign in</button>
  <?php if(!empty($err)):?><div class="err"><?=$err?></div><?php endif;?>
  <div class="tip">Set env vars WEBEDITOR_USER / WEBEDITOR_PASS for stronger creds.</div>
</form></div>
<?php exit; endif; ?>

<!doctype html><meta charset="utf-8"><title><?=$TITLE?></title>
<style>
:root{--bg:#0f0f12;--panel:#121218;--line:#262631;--text:#e5e5e5}
*{box-sizing:border-box} html,body{height:100%}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif}
.top{display:flex;gap:12px;align-items:center;padding:10px;border-bottom:1px solid var(--line)}
.input{padding:6px 8px;background:#0e0e14;border:1px solid var(--line);color:#fff;border-radius:8px}
.btn{padding:6px 10px;border:1px solid var(--line);background:#181822;border-radius:8px;color:#ddd;cursor:pointer}
.grid{display:grid;grid-template-columns:260px 320px 1fr;gap:8px;height:calc(100% - 56px);padding:8px}
.panel{background:#121218;border:1px solid var(--line);border-radius:12px;display:flex;flex-direction:column;min-height:0}
.head{padding:8px 10px;border-bottom:1px solid var(--line);display:flex;gap:8px;align-items:center}
.body{padding:8px;overflow:auto}
ul{list-style:none;margin:0;padding:0} li{padding:6px;border-radius:8px;cursor:pointer} li:hover{background:#181822}
small{opacity:.6} .row{display:flex;gap:8px;align-items:center;justify-content:space-between}
.editorbar{padding:8px;border-bottom:1px solid var(--line);display:flex;gap:8px;align-items:center}
.tag{background:#1e1e26;border:1px solid var(--line);padding:3px 6px;border-radius:6px;font-size:12px}
.mono{font-family:ui-monospace,Consolas,monospace}
textarea{width:100%;height:100%;resize:none;background:#0e0e14;color:#e5e5e5;border:0;padding:12px;font-family:ui-monospace,Consolas,monospace}
footer{position:fixed;right:10px;bottom:8px;opacity:.5}
.crumb a{color:#aee;text-decoration:none;margin-right:6px}.crumb a:hover{text-decoration:underline}
</style>

<div class="top">
  <div id="rootNote">root: …</div>
  <div class="crumb" id="crumb" style="margin-left:8px"></div>
  <div style="margin-left:auto;display:flex;gap:8px">
    <input id="pathInput" class="input" placeholder="jump to path (rel)">
    <button class="btn" onclick="jump()">Open</button>
    <a class="btn" href="?logout=1">Logout</a>
  </div>
</div>

<div class="grid">
  <div class="panel">
    <div class="head"><strong>FIND</strong><button class="btn" onclick="mkdirPrompt()">+ Folder</button></div>
    <div class="body"><ul id="folderList"></ul></div>
  </div>
  <div class="panel">
    <div class="head"><strong>STRUCTURE</strong><button class="btn" onclick="newFilePrompt()">+ File</button>
      <label class="btn" style="position:relative;overflow:hidden">Upload<input type="file" style="position:absolute;inset:0;opacity:0" onchange="uploadFile(this)"></label>
    </div>
    <div class="body"><ul id="fileList"></ul></div>
  </div>
  <div class="panel">
    <div class="editorbar">
      <span class="tag" id="fileName">—</span>
      <span class="tag mono" id="fileSize"></span>
      <span class="tag" id="fileWhen"></span>
      <div style="margin-left:auto"></div>
      <button class="btn" onclick="formatDoc()" id="fmtBtn" disabled>Format</button>
      <button class="btn" onclick="save()" id="saveBtn" disabled>Save</button>
      <button class="btn" onclick="del()" id="delBtn" disabled>Delete</button>
    </div>
    <div class="body" style="padding:0;display:flex;flex-direction:column">
      <textarea id="ta" placeholder="Open a text file…" disabled></textarea>
    </div>
  </div>
</div>
<footer><?=$TITLE?></footer>

<script>
const CSRF = '<?=htmlspecialchars($_SESSION['csrf'] ?? '')?>';
const api=(act,params)=>fetch(`?api=${act}&`+new URLSearchParams(params||{}));
let currentDir='', currentFile='';

function crumb(rel){
  const c=document.getElementById('crumb'); c.innerHTML='';
  const parts = rel? rel.split('/') : [];
  let acc=''; const root=document.createElement('a'); root.textContent='/'; root.href='#'; root.onclick=(e)=>{e.preventDefault(); openDir('');};
  c.appendChild(root);
  parts.forEach((p,i)=>{ acc+=(i?'/':'')+p; const a=document.createElement('a'); a.textContent=p; a.href='#'; a.onclick=(e)=>{e.preventDefault(); openDir(acc);}; c.appendChild(a); });
  document.getElementById('pathInput').value = rel || '';
}
async function init(){
  const info=await (await api('whereami')).json(); rootNote.textContent='root: '+(info.root||'(unset)'); openDir('');
}
function ent(name,rel,isDir,size,mtime){
  const li=document.createElement('li'); li.innerHTML=`<div class="row"><div>${isDir?'📁':'📄'} ${name}</div><small>${isDir?'':fmtSize(size)}</small></div>`;
  li.onclick=()=> isDir? openDir(rel) : openFile(rel,name,size,mtime); return li;
}
async function openDir(rel){
  currentDir = rel || ''; crumb(currentDir);
  const FL=document.getElementById('folderList'); FL.innerHTML='';
  const r=await (await api('list',{path:currentDir})).json(); if(!r.ok){alert(r.error||'list failed');return;}
  if(currentDir){ const up=currentDir.split('/').slice(0,-1).join('/'); const li=document.createElement('li'); li.textContent='⬆️ ..'; li.onclick=()=>openDir(up); FL.appendChild(li); }
  r.items.filter(i=>i.type==='dir').sort((a,b)=>a.name.localeCompare(b.name)).forEach(d=>FL.appendChild(ent(d.name,d.rel,true,0,d.mtime)));
  const FI=document.getElementById('fileList'); FI.innerHTML='';
  r.items.filter(i=>i.type==='file').sort((a,b)=>a.name.localeCompare(b.name)).forEach(f=>FI.appendChild(ent(f.name,f.rel,false,f.size,f.mtime)));
}
function jump(){ const p=document.getElementById('pathInput').value.trim(); openDir(p); }
function fmtSize(b){ if(b<1024) return b+' B'; let u=['KB','MB','GB']; let i=-1; do{b/=1024;i++;}while(b>=1024&&i<2); return b.toFixed(1)+' '+u[i]; }
function fmtWhen(s){ try{return new Date(s*1000).toLocaleString();}catch{return '';} }

async function openFile(rel,name,size,mtime){
  currentFile=rel; fileName.textContent=name; fileSize.textContent=size?fmtSize(size):''; fileWhen.textContent=mtime?fmtWhen(mtime):'';
  const r=await (await api('read',{path:rel})).json(); const ta=document.getElementById('ta');
  if(!r.ok){ alert(r.error||'Cannot open'); ta.value=''; ta.disabled=true; btns(false); return; }
  ta.value=r.content; ta.disabled=false; btns(true);
  const ext=name.toLowerCase().split('.').pop(); document.getElementById('fmtBtn').disabled=!['json','xml','opml'].includes(ext);
}
function btns(on){ saveBtn.disabled=!on; delBtn.disabled=!on; }
async function save(){
  if(!currentFile) return;
  const body=JSON.stringify({content:document.getElementById('ta').value});
  const r=await (await fetch(`?api=write&`+new URLSearchParams({path:currentFile}),{method:'POST',headers:{'X-CSRF':CSRF},body})).json();
  if(!r.ok){alert(r.error||'Save failed');return;} alert('Saved');
}
async function del(){
  if(!currentFile) return; if(!confirm('Delete this file?')) return;
  const r=await (await fetch(`?api=delete&`+new URLSearchParams({path:currentFile}),{method:'POST',headers:{'X-CSRF':CSRF}})).json();
  if(!r.ok){alert(r.error||'Delete failed');return;} ta.value=''; ta.disabled=true; btns(false); openDir(currentDir);
}
async function mkdirPrompt(){
  const name=prompt('New folder name:'); if(!name) return;
  const r=await (await fetch(`?api=mkdir&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({name})})).json();
  if(!r.ok){alert(r.error||'mkdir failed');return;} openDir(currentDir);
}
async function newFilePrompt(){
  const name=prompt('New file name:'); if(!name) return;
  const r=await (await fetch(`?api=newfile&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({name})})).json();
  if(!r.ok){alert(r.error||'newfile failed');return;} openDir(currentDir);
}
async function uploadFile(inp){
  if(!inp.files.length) return; const fd=new FormData(); fd.append('file',inp.files[0]);
  const r=await (await fetch(`?api=upload&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:fd})).json();
  if(!r.ok){alert(r.error||'upload failed');return;} openDir(currentDir);
}
async function formatDoc(){
  if(!currentFile) return; const body=JSON.stringify({content:document.getElementById('ta').value});
  const r=await (await fetch(`?api=format&`+new URLSearchParams({path:currentFile}),{method:'POST',headers:{'X-CSRF':CSRF},body})).json();
  if(!r.ok){alert(r.error||'format failed');return;} document.getElementById('ta').value=r.content;
}
init();
</script>
