<?php
/*  Dimmi WebEditor — DreamHost-friendly single-file PHP app
    Panels: FIND | STRUCTURE | CONTENT
    Security: session auth, CSRF, path jail ($ROOT), editable extensions whitelist
    Extras: breadcrumb + path bar, JSON/XML(OPML) formatter, audit log
*/

/* ===== CONFIG ===== */
$USER = getenv('WEBEDITOR_USER') ?: 'admin';
$PASS = getenv('WEBEDITOR_PASS') ?: 'admin';
$TITLE = 'ARKHIVER ONLINE';

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

// ----- OPML helpers -----
function opml_load_dom($abs){
  if (!class_exists('DOMDocument')) bad('DOM extension missing',500);
  libxml_use_internal_errors(true);
  $dom=new DOMDocument('1.0','UTF-8');
  $dom->preserveWhiteSpace=false;
  if(!$dom->load($abs, LIBXML_NONET)) bad('Invalid OPML/XML',422);
  return $dom;
}
function opml_body($dom){
  $b=$dom->getElementsByTagName('body')->item(0);
  if(!$b) bad('No body',422);
  return $b;
}
function opml_outline_at($parent,$idx){
  $i=-1;
  foreach($parent->childNodes as $c){
    if($c->nodeType===XML_ELEMENT_NODE && strtolower($c->nodeName)==='outline'){
      $i++; if($i===(int)$idx) return $c;
    }
  }
  return null;
}
function opml_node_by_id($dom,$id){
  $parts = trim((string)$id)==='' ? [] : array_map('intval', explode('/',$id));
  $cur = opml_body($dom);
  foreach($parts as $ix){
    $cur = opml_outline_at($cur,$ix);
    if(!$cur) bad('Node not found',404);
  }
  return $cur;
}
function opml_id_of($node){
  $path=[];
  while($node && strtolower($node->nodeName)==='outline'){
    $ix=0;
    for($s=$node->previousSibling;$s;$s=$s->previousSibling){
      if($s->nodeType===XML_ELEMENT_NODE && strtolower($s->nodeName)==='outline') $ix++;
    }
    array_unshift($path,$ix);
    $node=$node->parentNode;
    if(!$node || strtolower($node->nodeName)==='body') break;
  }
  return implode('/',$path);
}
function opml_save_dom($dom,$abs){
  $tmp=$abs.'.tmp';
  if($dom->save($tmp)===false) bad('Write failed',500);
  if(!@rename($tmp,$abs)){ @unlink($tmp); bad('Replace failed',500); }
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
    // [PATCH] accept {to} or {name}; both are used in front-ends
    $data=json_decode(file_get_contents('php://input'),true);
    $to=trim(($data['to'] ?? $data['name'] ?? ''),'/'); if($to==='') bad('Missing target');
    $dst=safe_abs($to); if($dst===false) bad('Invalid target');
    $ok = @rename($abs,$dst); audit('rename',$path,$ok,"-> ".rel_of($dst)); j(['ok'=>$ok]);
  }

  if ($action==='upload' && $method==='POST') {
    if (!is_dir($abs)) bad('Upload path is not a directory');
    if (empty($_FILES['file'])) bad('No file');
    $tmp=$_FILES['file']['tmp_name']; $name=basename($_FILES['file']['name']);
    $dst=safe_abs($path.'/'.$name); if($dst===false) bad('Bad target');
    $dir=dirname($dst); if(!is_dir($dir)) @mkdir($dir,0775,true);
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

  // [PATCH] OPML → JSON tree for STRUCTURE panel
  if ($action==='opml_tree') {
    $file = $_GET['file'] ?? $_POST['file'] ?? '';
    $fileAbs = safe_abs($file);
    if ($fileAbs===false || !is_file($fileAbs)) bad('Bad file');
    $ext = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['opml','xml'])) bad('Not OPML/XML',415);
    $xml = @file_get_contents($fileAbs); if ($xml===false) bad('Read error',500);
    if (!class_exists('DOMDocument')) bad('DOM extension missing',500);
    libxml_use_internal_errors(true);
    $dom=new DOMDocument('1.0','UTF-8'); $dom->preserveWhiteSpace=false;
    if (!$dom->loadXML($xml, LIBXML_NONET)) bad('Invalid OPML/XML',422);
    $body = $dom->getElementsByTagName('body')->item(0);
    $walk = function($node,$path='') use (&$walk){
      $out=[]; $i=0; foreach($node->childNodes as $c){
        if ($c->nodeType!==XML_ELEMENT_NODE || strtolower($c->nodeName)!=='outline') continue;
        $t = $c->getAttribute('title') ?: $c->getAttribute('text') ?: '•';
        $id = $path===''? (string)$i : $path.'/'.$i;
        $item = ['t'=>$t, 'id'=>$id, 'children'=>[]];
        if ($c->hasAttribute('_note')) $item['note']=$c->getAttribute('_note');
        if ($c->hasChildNodes()) $item['children']=$walk($c,$id);
        $out[]=$item; $i++;
      } return $out;
    };
    $tree = $body? $walk($body) : [];
    j(['ok'=>true,'tree'=>$tree]);
  }

  // [PATCH] update single outline note in OPML
  if ($action==='set_note' && $method==='POST') {
    $file = $_GET['file'] ?? $_POST['file'] ?? '';
    $fileAbs = safe_abs($file);
    if ($fileAbs===false || !is_file($fileAbs)) bad('Bad file');
    $ext = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['opml','xml'])) bad('Not OPML/XML',415);
    $data = json_decode(file_get_contents('php://input'), true);
    $note = isset($data['note']) && is_string($data['note']) ? $data['note'] : '';
    $path = $_GET['path'] ?? $_POST['path'] ?? '';
    if (!class_exists('DOMDocument')) bad('DOM extension missing',500);
    libxml_use_internal_errors(true);
    $dom=new DOMDocument('1.0','UTF-8'); $dom->preserveWhiteSpace=false;
    if(!$dom->load($fileAbs, LIBXML_NONET)) bad('Invalid OPML/XML',422);
    $body=$dom->getElementsByTagName('body')->item(0);
    if(!$body) bad('No body',422);
    $indices=$path===''?[]:array_map('intval',explode('/',$path));
    $node=$body;
    foreach($indices as $idx){
      $count=0; $next=null;
      foreach($node->childNodes as $child){
        if($child->nodeType===XML_ELEMENT_NODE && strtolower($child->nodeName)==='outline'){
          if($count===$idx){ $next=$child; break; }
          $count++;
        }
      }
      if(!$next) bad('Node path not found',404);
      $node=$next;
    }
    if($note==='') $node->removeAttribute('_note'); else $node->setAttribute('_note',$note);
    $ok=$dom->save($fileAbs)!==false; audit('set_note',$file,$ok); j(['ok'=>$ok]);
  }

  // OPML node editing
  if ($action==='opml_node' && $method==='POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $file = $data['file'] ?? '';
    $id   = $data['id'] ?? '';
    $op   = $data['op'] ?? '';
    $fileAbs = safe_abs($file);
    if ($fileAbs===false || !is_file($fileAbs)) bad('Bad file');
    $ext = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['opml','xml'])) bad('Not OPML/XML',415);
    $dom = opml_load_dom($fileAbs); $body = opml_body($dom);
    $sel = null;
    switch($op){
      case 'set_title': {
        $cur = opml_node_by_id($dom,$id);
        $title = (string)($data['title'] ?? '');
        $cur->setAttribute('title',$title);
        if(!$cur->getAttribute('text')) $cur->setAttribute('text',$title);
        $sel = $cur;
      } break;
      case 'add_child': {
        $par = opml_node_by_id($dom,$id);
        $n = $dom->createElement('outline');
        $title = trim($data['title'] ?? 'New');
        if($title!=='') $n->setAttribute('title',$title);
        $par->appendChild($n);
        $sel = $n;
      } break;
      case 'add_sibling': {
        $cur = opml_node_by_id($dom,$id); $par=$cur->parentNode;
        $n = $dom->createElement('outline');
        $title = trim($data['title'] ?? 'New');
        if($title!=='') $n->setAttribute('title',$title);
        if($cur->nextSibling) $par->insertBefore($n,$cur->nextSibling); else $par->appendChild($n);
        $sel = $n;
      } break;
      case 'delete': {
        $cur = opml_node_by_id($dom,$id); $par=$cur->parentNode;
        $par->removeChild($cur);
        $sel = $par;
      } break;
      case 'move': {
        $dir = $data['dir'] ?? '';
        $cur = opml_node_by_id($dom,$id); $par=$cur->parentNode;
        $prev=null; $next=null;
        for($n=$cur->previousSibling;$n;$n=$n->previousSibling){ if($n->nodeType===XML_ELEMENT_NODE && strtolower($n->nodeName)==='outline'){ $prev=$n; break; } }
        for($n=$cur->nextSibling;$n;$n=$n->nextSibling){ if($n->nodeType===XML_ELEMENT_NODE && strtolower($n->nodeName)==='outline'){ $next=$n; break; } }
        if($dir==='up' && $prev){ $par->insertBefore($cur,$prev); }
        elseif($dir==='down' && $next){ if($next->nextSibling) $par->insertBefore($cur,$next->nextSibling); else $par->appendChild($cur); }
        elseif($dir==='in' && $prev){ $prev->appendChild($cur); }
        elseif($dir==='out'){
          if(strtolower($par->nodeName)!=='outline') bad('Cannot outdent root-level',400);
          $gp=$par->parentNode;
          if($par->nextSibling) $gp->insertBefore($cur,$par->nextSibling); else $gp->appendChild($cur);
        }
        $sel = $cur;
      } break;
      default: bad('Unknown op',400);
    }
    opml_save_dom($dom,$fileAbs);
    j(['ok'=>true,'id'=>opml_id_of($sel ?: $body)]);
  }

  bad('Unknown action',404);
}

  /* ===== HTML (UI) ===== */
  if (!$authed): ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=$TITLE?> — Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center">
  <div class="bg-white p-6 rounded shadow w-80">
    <h1 class="text-xl font-semibold mb-4 text-gray-800"><?=$TITLE?></h1>
    <form method="post" class="space-y-4">
      <input name="u" placeholder="user" autofocus class="w-full border rounded px-3 py-2">
      <input name="p" type="password" placeholder="password" class="w-full border rounded px-3 py-2">
      <button name="do_login" value="1" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-500">Sign in</button>
      <?php if(!empty($err)):?><div class="text-red-500 text-sm"><?=$err?></div><?php endif;?>
      <p class="text-xs text-gray-500">Set env vars WEBEDITOR_USER / WEBEDITOR_PASS for stronger creds.</p>
    </form>
  </div>
</body>
</html>
<?php exit; endif; ?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=$TITLE?></title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-screen flex flex-col bg-gray-50 text-gray-800 overflow-x-hidden">
  <header class="flex items-center gap-4 p-4 bg-white shadow">
    <div id="rootNote" class="text-xs text-gray-500"></div>
    <button onclick="openDir('')" class="text-gray-600 hover:text-gray-800">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
      <span class="sr-only">Home</span>
    </button>
    <nav id="crumb" class="flex items-center text-sm text-gray-600"></nav>
    <a href="?logout=1" class="ml-auto text-gray-600 hover:text-gray-800" title="Logout">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/></svg>
    </a>
  </header>
  <main class="flex-1 overflow-auto md:overflow-hidden p-4 space-y-4 md:space-y-0 md:grid md:grid-cols-3 md:gap-4 md:h-[calc(100vh-64px)]">
    <!-- FIND -->
    <section class="bg-white rounded shadow flex flex-col">
      <div class="flex items-center gap-2 p-4 border-b">
        <h2 class="font-semibold flex-1">FIND</h2>
        <button onclick="mkdirPrompt()" class="p-2 text-gray-600 hover:text-gray-800" title="New Folder">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>
        </button>
        <label class="p-2 text-gray-600 hover:text-gray-800 cursor-pointer" title="Upload Folder">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
          <input type="file" webkitdirectory multiple class="hidden" onchange="uploadFolder(this)">
        </label>
        <button class="ml-2 md:hidden" onclick="toggleSection('findBody', this)">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
        </button>
      </div>
      <div id="findBody" class="flex-1 flex flex-col">
        <div class="flex gap-2 p-4">
          <input id="pathInput" class="flex-1 border rounded px-2 py-1" placeholder="jump to path (rel)">
          <button onclick="jump()" class="px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-500">Open</button>
        </div>
        <ul id="folderList" class="flex-1 overflow-auto divide-y text-sm"></ul>
      </div>
    </section>

    <!-- STRUCTURE -->
    <section class="bg-white rounded shadow flex flex-col">
      <div class="flex items-center gap-2 p-4 border-b">
        <h2 class="font-semibold flex-1">STRUCTURE</h2>
        <button onclick="newFilePrompt()" class="p-2 text-gray-600 hover:text-gray-800" title="New File">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
        </button>
        <label class="p-2 text-gray-600 hover:text-gray-800 cursor-pointer" title="Upload File">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
          <input type="file" class="hidden" onchange="uploadFile(this)">
        </label>
        <div class="ml-auto flex gap-2">
          <button id="structListBtn" type="button" class="px-2 py-1 text-sm border rounded">List</button>
          <button id="structTreeBtn" type="button" class="px-2 py-1 text-sm border rounded" title="Show OPML ARK" disabled>ARK</button>
        </div>
        <button class="ml-2 md:hidden" onclick="toggleSection('structBody', this)">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
        </button>
      </div>
      <div id="structBody" class="p-4 flex-1 flex flex-col overflow-hidden">
        <ul id="fileList" class="flex-1 overflow-auto divide-y text-sm"></ul>
        <div id="opmlTreeWrap" class="hidden flex-1 overflow-auto"></div>
        <div id="treeTools" class="hidden mt-2 flex gap-2 text-sm">
          <button class="px-2 py-1 border rounded" id="addChildBtn">Add Sub</button>
          <button class="px-2 py-1 border rounded" id="addSiblingBtn">Add Same</button>
          <button class="px-2 py-1 border rounded text-red-600" id="delNodeBtn">Delete</button>
          <button class="px-2 py-1 border rounded" id="upBtn">↑</button>
          <button class="px-2 py-1 border rounded" id="downBtn">↓</button>
          <button class="px-2 py-1 border rounded" id="outBtn">⇤</button>
          <button class="px-2 py-1 border rounded" id="inBtn">⇥</button>
        </div>
      </div>
    </section>

    <!-- CONTENT -->
    <section class="bg-white rounded shadow flex flex-col">
      <div class="flex items-center gap-2 p-4 border-b">
        <h2 class="font-semibold">CONTENT</h2>
        <span id="fileName" class="text-sm bg-gray-100 rounded px-2 py-1">—</span>
        <span id="fileSize" class="text-xs text-gray-500"></span>
        <span id="fileWhen" class="text-xs text-gray-500"></span>
        <div class="ml-auto flex gap-2">
          <button onclick="save()" id="saveBtn" disabled class="px-3 py-2 bg-blue-600 text-white rounded disabled:opacity-50">Save</button>
          <button onclick="del()" id="delBtn" disabled class="px-3 py-2 bg-red-600 text-white rounded disabled:opacity-50">Delete</button>
        </div>
        <button class="ml-2 md:hidden" onclick="toggleSection('contentBody', this)">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
        </button>
      </div>
      <div id="contentBody" class="flex-1 flex flex-col">
        <div id="nodeEditor" class="hidden p-4 border-b">
          <div class="flex items-center gap-2">
            <label class="text-sm">Title:</label>
            <input id="nodeTitle" class="flex-1 border rounded px-2 py-1" placeholder="Node title">
            <button id="saveTitleBtn" class="px-2 py-1 text-sm bg-blue-600 text-white rounded">Save Title</button>
          </div>
        </div>
        <textarea id="ta" class="flex-1 w-full p-4 resize-none border-0 outline-none" placeholder="Open a text file…" disabled></textarea>
      </div>
    </section>
  </main>

  <div id="newFileModal" class="fixed inset-0 hidden flex items-center justify-center bg-black bg-opacity-40">
    <div class="bg-white rounded p-6 w-64 space-y-4">
      <input id="newFileName" class="w-full border rounded px-2 py-1" placeholder="new file name">
      <div id="extBtns" class="flex gap-2">
        <button class="ext px-2 py-1 text-sm border rounded" data-ext=".txt">.txt</button>
        <button class="ext px-2 py-1 text-sm border rounded" data-ext=".html">.html</button>
        <button class="ext px-2 py-1 text-sm border rounded" data-ext=".md">.md</button>
        <button class="ext px-2 py-1 text-sm border rounded" data-ext=".opml">.opml</button>
      </div>
      <div class="flex justify-end gap-2">
        <button id="newFileCreateBtn" class="px-2 py-1 text-sm bg-blue-600 text-white rounded">Create</button>
        <button id="newFileCancelBtn" class="px-2 py-1 text-sm border rounded">Cancel</button>
      </div>
    </div>
  </div>
  <footer class="text-xs text-gray-500 text-right p-2"><?=$TITLE?></footer>

  <script>
const CSRF = '<?=htmlspecialchars($_SESSION['csrf'] ?? '')?>';
const api=(act,params)=>fetch(`?api=${act}&`+new URLSearchParams(params||{}));
let currentDir='', currentFile='', currentOutlinePath='';
const newExts=['.txt','.html','.md','.opml'];
let newExtIndex=0;
const icons={
  folder:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>',
  file:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>',
  edit:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>',
  trash:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>'
};
const listBtn=document.getElementById('structListBtn');
const treeBtn=document.getElementById('structTreeBtn');
const treeWrap=document.getElementById('opmlTreeWrap');
const fileList=document.getElementById('fileList');
const treeTools=document.getElementById('treeTools');
const nodeEditor=document.getElementById('nodeEditor');
const nodeTitle=document.getElementById('nodeTitle');
const saveTitleBtn=document.getElementById('saveTitleBtn');
const addChildBtn=document.getElementById('addChildBtn');
const addSiblingBtn=document.getElementById('addSiblingBtn');
const delNodeBtn=document.getElementById('delNodeBtn');
const upBtn=document.getElementById('upBtn');
const downBtn=document.getElementById('downBtn');
const outBtn=document.getElementById('outBtn');
const inBtn=document.getElementById('inBtn');
let selectedId=null;
if(listBtn && treeBtn){
  listBtn.onclick=()=>hideTree();
  treeBtn.onclick=()=>showTree();
}

function toggleSection(id,btn){
  const el=document.getElementById(id);
  if(!el) return;
  el.classList.toggle('hidden');
  if(btn) btn.classList.toggle('rotate-180');
}

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
  const li=document.createElement('li');
  const ico=isDir?icons.folder:icons.file;
  const sizeHtml=isDir?'':`<span class="text-xs text-gray-500">${fmtSize(size)}</span>`;
  li.innerHTML=`<div class="flex items-center justify-between px-2 py-2 hover:bg-gray-100 rounded">
    <div class="flex items-center gap-2">${ico}<span>${name}</span></div>
    <div class="flex items-center gap-2">${sizeHtml}<button class="text-gray-500 hover:text-blue-600" onclick="renameItem(event,'${rel}')" title="Rename">${icons.edit}</button><button class="text-gray-500 hover:text-red-600" onclick="deleteItem(event,'${rel}')" title="Delete">${icons.trash}</button></div>
  </div>`;
  li.onclick=()=> isDir? openDir(rel) : openFile(rel,name,size,mtime);
  return li;
}
async function openDir(rel){
  currentDir = rel || ''; crumb(currentDir);
  const FL=document.getElementById('folderList'); FL.innerHTML='';
  const r=await (await api('list',{path:currentDir})).json(); if(!r.ok){alert(r.error||'list failed');return;}
  if(currentDir){ const up=currentDir.split('/').slice(0,-1).join('/'); const upName=up.split('/').pop() || '/'; const li=document.createElement('li'); li.textContent='↑ '+upName; li.onclick=()=>openDir(up); FL.appendChild(li); }
  r.items.filter(i=>i.type==='dir').sort((a,b)=>a.name.localeCompare(b.name)).forEach(d=>FL.appendChild(ent(d.name,d.rel,true,0,d.mtime)));
  const FI=document.getElementById('fileList'); FI.innerHTML='';
  r.items.filter(i=>i.type==='file').sort((a,b)=>a.name.localeCompare(b.name)).forEach(f=>FI.appendChild(ent(f.name,f.rel,false,f.size,f.mtime)));
}
function jump(){ const p=document.getElementById('pathInput').value.trim(); openDir(p); }
function fmtSize(b){ if(b<1024) return b+' B'; let u=['KB','MB','GB']; let i=-1; do{b/=1024;i++;}while(b>=1024&&i<2); return b.toFixed(1)+' '+u[i]; }
function fmtWhen(s){ try{return new Date(s*1000).toLocaleString();}catch{return ''; } }

async function openFile(rel,name,size,mtime){
  currentFile=rel; currentOutlinePath='';
  fileName.textContent=name; fileSize.textContent=size?fmtSize(size):''; fileWhen.textContent=mtime?fmtWhen(mtime):'';
  const r=await (await api('read',{path:rel})).json(); const ta=document.getElementById('ta');
  if (!r.ok) { ta.value=''; ta.disabled=true; btns(false); return; }
  ta.value=r.content; ta.disabled=false; btns(true);
  const ext=name.toLowerCase().split('.').pop();
  document.getElementById('structTreeBtn').disabled = !['opml','xml'].includes(ext);
  hideTree();
}
function btns(on){ saveBtn.disabled=!on; delBtn.disabled=!on; }
async function save(){
  if(!currentFile) return;
  const content=document.getElementById('ta').value;
  if(currentOutlinePath){
    const r=await (await fetch(`?api=set_note&`+new URLSearchParams({file:currentFile,path:currentOutlinePath}),{
      method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({note:content})
    })).json();
    if(!r.ok){alert(r.error||'Save failed');return;}
    loadTree();
  }else{
    const body=JSON.stringify({content});
    const r=await (await fetch(`?api=write&`+new URLSearchParams({path:currentFile}),{
      method:'POST',headers:{'X-CSRF':CSRF},body
    })).json();
    if(!r.ok){alert(r.error||'Save failed');return;}
  }
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
function newFilePrompt(){
  const m=document.getElementById('newFileModal');
  const input=document.getElementById('newFileName');
  m.classList.remove('hidden');
  newExtIndex=0;
  updateExtBtns();
  input.value='';
  input.focus();
}
function updateExtBtns(){
  document.querySelectorAll('#extBtns .ext').forEach((b,i)=>{
    b.classList.toggle('ring', i===newExtIndex);
    b.classList.toggle('ring-blue-500', i===newExtIndex);
  });
}
document.querySelectorAll('#extBtns .ext').forEach((btn,i)=>{
  btn.addEventListener('click',()=>{ newExtIndex=i; updateExtBtns(); document.getElementById('newFileName').focus(); });
});
document.getElementById('newFileName').addEventListener('keydown',(e)=>{
  if(e.key==='Tab'){ e.preventDefault(); newExtIndex=(newExtIndex+1)%newExts.length; updateExtBtns(); }
  if(e.key==='Enter'){ e.preventDefault(); createNewFile(); }
});
document.getElementById('newFileCreateBtn').addEventListener('click', createNewFile);
document.getElementById('newFileCancelBtn').addEventListener('click', ()=>{ document.getElementById('newFileModal').classList.add('hidden'); });

async function createNewFile(){
  let name=document.getElementById('newFileName').value.trim();
  if(!name) return;
  if(!name.includes('.')) name+=newExts[newExtIndex];
  const r=await (await fetch(`?api=newfile&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({name})})).json();
  if(!r.ok){ alert(r.error||'newfile failed'); return; }
  document.getElementById('newFileModal').classList.add('hidden');
  openDir(currentDir);
}
async function uploadFile(inp){
  if(!inp.files.length) return; const fd=new FormData(); fd.append('file',inp.files[0]);
  const r=await (await fetch(`?api=upload&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:fd})).json();
  if(!r.ok){alert(r.error||'upload failed');return;} openDir(currentDir);
}

async function uploadFolder(inp){
  if(!inp.files.length) return;
  for(const f of inp.files){
    const fd=new FormData(); fd.append('file',f);
    const relPath=f.webkitRelativePath||f.name;
    const subdir=relPath.split('/').slice(0,-1).join('/');
    const target=currentDir+(subdir?`/${subdir}`:'');
    const r=await (await fetch(`?api=upload&`+new URLSearchParams({path:target}),{method:'POST',headers:{'X-CSRF':CSRF},body:fd})).json();
    if(!r.ok){alert(r.error||'upload failed');return;}
  }
  openDir(currentDir);
}

async function renameItem(ev,rel){
  ev.stopPropagation();
  const oldName = rel.split('/').pop();
  const name = prompt('Rename to:', oldName);
  if(!name || name === oldName) return;
  const dir = rel.split('/').slice(0,-1).join('/');
  const target = (dir? dir+'/' : '') + name.replace(/^\\+/,'');
  const r=await (await fetch(`?api=rename&`+new URLSearchParams({path:rel}),{
    method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({to:target})
  })).json();
  if(!r.ok){alert(r.error||'rename failed');return;} openDir(currentDir);
}

async function deleteItem(ev,rel){
  ev.stopPropagation();
  if(!confirm('Delete this item?')) return;
  const r=await (await fetch(`?api=delete&`+new URLSearchParams({path:rel}),{method:'POST',headers:{'X-CSRF':CSRF}})).json();
  if(!r.ok){alert(r.error||'delete failed');return;}
  if(currentFile===rel){ document.getElementById('ta').value=''; document.getElementById('ta').disabled=true; btns(false); currentFile=''; }
  openDir(currentDir);
}
function hideTree(){
  treeWrap.classList.add('hidden');
  fileList.classList.remove('hidden');
  treeTools.classList.add('hidden');
  nodeEditor.classList.add('hidden');
  selectedId=null;
  currentOutlinePath='';
}
function showTree(){
  if(!currentFile) return;
  treeWrap.classList.remove('hidden');
  fileList.classList.add('hidden');
  treeTools.classList.remove('hidden');
  loadTree();
}
function renderTree(nodes){
  const wrap=document.createElement('div');
  wrap.className='text-base leading-relaxed';
  const mk=(arr)=>{
    const ul=document.createElement('ul');
    ul.className='list-none pl-4 my-1';
    for(const n of arr){
      const li=document.createElement('li');
      const row=document.createElement('div');
      row.className='flex items-center gap-2 px-2 py-2 hover:bg-gray-100 rounded cursor-pointer';
      const has=n.children && n.children.length;
      const caret=document.createElement('span');
      caret.textContent=has?'▸':'•';
      caret.className=has?'cursor-pointer select-none':'text-gray-400';
      const title=document.createElement('span'); title.textContent=n.t;
      row.append(caret,title); row.dataset.id=n.id;
      let child=null;
      if(has){ child=mk(n.children); child.classList.add('hidden'); li.appendChild(child); }
      row.onclick=(e)=>{
        if(has && e.target===caret){
          child.classList.toggle('hidden');
          caret.textContent = child.classList.contains('hidden') ? '▸' : '▾';
        }
        selectNode(n.id,n.t,n.note);
      };
      li.appendChild(row);
      ul.appendChild(li);
    }
    return ul;
  };
  wrap.appendChild(mk(nodes));
  treeWrap.replaceChildren(wrap);
}
async function loadTree(){
  try{
    const r=await (await api('opml_tree',{file:currentFile})).json();
    if(!r.ok){ treeWrap.textContent=r.error||'OPML parse error.'; return; }
    renderTree(r.tree||[]);
  }catch(e){ treeWrap.textContent='OPML load error.'; }
}
function selectNode(id,title,note){
  selectedId=id;
  currentOutlinePath=id;
  nodeEditor.classList.remove('hidden');
  nodeTitle.value=title||'';
  if(note!==undefined){
    const ta=document.getElementById('ta');
    ta.value=note||''; ta.disabled=false; btns(false);
    fileName.textContent=title||''; fileSize.textContent=''; fileWhen.textContent='';
  }
}
async function nodeOp(op,extra={}){
  if(!currentFile || selectedId===null) return;
  const body=JSON.stringify({file:currentFile,op,id:selectedId,...extra});
  const r=await (await fetch(`?api=opml_node`,{method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},body})).json();
  if(!r.ok){ alert(r.error||'node op failed'); return; }
  selectedId=r.select ?? selectedId;
  await loadTree();
}
addChildBtn.onclick   = ()=> nodeOp('add_child',{title:'New'});
addSiblingBtn.onclick = ()=> nodeOp('add_sibling',{title:'New'});
delNodeBtn.onclick    = ()=> { if(confirm('Delete this node?')) nodeOp('delete'); };
upBtn.onclick         = ()=> nodeOp('move',{dir:'up'});
downBtn.onclick       = ()=> nodeOp('move',{dir:'down'});
outBtn.onclick        = ()=> nodeOp('move',{dir:'out'});
inBtn.onclick         = ()=> nodeOp('move',{dir:'in'});
saveTitleBtn.onclick  = ()=> nodeOp('set_title',{title:nodeTitle.value});
init();
</script>
</body>
</html>
