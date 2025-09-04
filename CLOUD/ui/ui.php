<?php
/*  Dimmi WebEditor — DreamHost-friendly, single-file PHP editor
    Panels: FIND (folders) | STRUCTURE (files) | CONTENT (editor)
    Upgrades: env-based auth, CSRF, breadcrumb/path-bar, OPML/JSON formatter, audit log
    Jail root: $ROOT (all ops constrained here)
*/

/* ====== CONFIG ====== */
$CONFIG = [
  'title' => 'Dimmi WebEditor',
  'root' => (function () {
    $try = realpath(dirname(__DIR__, 2));
    if ($try && is_dir($try)) return $try;
    return realpath('/home/arkhivist/itsjustlife.cloud/dimmi');
  })(),
  'editable_exts' => ['txt','md','markdown','json','yaml','yml','xml','opml','csv','tsv','ini','conf','py','js','ts','css','html','htm','php'],
  'ignore_dirs' => ['.git','node_modules','.history','.cache'],
  'max_edit' => 5*1024*1024,
  'read_only' => false,
  'session_lifetime' => 8*3600
];
$USER = getenv('WEBEDITOR_USER') ?: 'admin';
$PASS = getenv('WEBEDITOR_PASS') ?: 'admin';
$TITLE = $CONFIG['title'];
$LOG_FILE = rtrim($CONFIG['root'],'/').'/.webeditor.log';

session_set_cookie_params(['httponly'=>true,'secure'=>isset($_SERVER['HTTPS']),'samesite'=>'Lax']);
session_start();
$authed = !empty($_SESSION['auth']);
if ($authed && empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
if ($authed) {
  if (isset($_SESSION['last']) && time()-$_SESSION['last'] > $CONFIG['session_lifetime']) {
    session_destroy();
    $authed = false;
    $expired = true;
  } else {
    $_SESSION['last'] = time();
  }
}
if (isset($_POST['do_login'])) {
  if (hash_equals($USER, $_POST['u'] ?? '') && hash_equals($PASS, $_POST['p'] ?? '')) {
    $_SESSION['auth'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $_SESSION['last'] = time();
    header('Location: '.$_SERVER['PHP_SELF']); exit;
  }
  $err = 'Invalid credentials';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit; }
$authed = !empty($_SESSION['auth']);

header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline';");

if (isset($_GET['log']) && $authed) {
  $log = @file_get_contents($LOG_FILE) ?: '';
  $lines = explode("\n", $log);
  $tail = array_slice($lines, -200);
  header('Content-Type: text/plain');
  echo implode("\n", $tail);
  exit;
}

/* ====== HELPERS ====== */
function j($x,$code=200){ http_response_code($code); header('Content-Type: application/json'); echo json_encode($x); exit; }
function bad($m,$code=400){ j(['ok'=>false,'error'=>$m],$code); }
function is_text($path){ global $CONFIG; $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)); return in_array($ext,$CONFIG['editable_exts']); }
function is_opml($path){ $lower=strtolower($path); return substr($lower,-5)==='.opml' || substr($lower,-9)==='.opml.xml'; }
// ---- Path jail helpers (drop near top) ---------------------------------
function normalize_rel($rel) {
  $rel = str_replace("\0",'', (string)$rel);
  $rel = preg_replace('#/+#','/',$rel);
  $parts = [];
  foreach (explode('/', trim($rel,'/')) as $seg) {
    if ($seg === '' || $seg === '.') continue;
    if ($seg === '..') return false; // disallow traversal entirely
    $parts[] = $seg;
  }
  return implode('/', $parts);
}
function safe_abs($rel) {
  global $CONFIG;
  $root    = rtrim($CONFIG['root'], '/');
  $rootReal = realpath($root);
  if ($rootReal === false || !is_dir($rootReal)) return false;
  $norm = normalize_rel($rel);
  if ($norm === false) return false;
  $abs = $root . ($norm !== '' ? '/' . $norm : '');
  // For existing paths, compare real paths against canonical root
  if (file_exists($abs)) {
    $real = realpath($abs);
    return (strpos($real, $rootReal) === 0) ? $real : false;
  }
  // For non-existent paths, validate the parent directory
  $dir  = dirname($abs);
  $rdir = realpath($dir);
  if ($rdir === false || strpos($rdir, $rootReal) !== 0) return false;
  return $rdir . '/' . basename($abs);
}
function rel_of($abs){ global $CONFIG; return trim(str_replace(rtrim($CONFIG['root'],'/'), '', $abs), '/'); }
function audit($action,$rel,$ok=true,$extra=''){         // append to .webeditor.log
  global $LOG_FILE; if(!$LOG_FILE) return;
  $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
  $ts = date('c');
  @file_put_contents($LOG_FILE, "$ts\t$ip\t$action\t$rel\t".($ok?'ok':'fail')."\t$extra\n", FILE_APPEND);
}

function need_write(){
  global $CONFIG; if($CONFIG['read_only']) bad('Read only',403);
}

/* ====== API ====== */
if (isset($_GET['api'])) {
  if (!$authed) bad('Unauthorized',401);
  if (!$CONFIG['root'] || !is_dir($CONFIG['root'])) bad('Invalid root in config.',500);
  $action = $_GET['api']; $path = $_GET['path'] ?? ''; $abs = safe_abs($path);
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($method==='POST') { // CSRF check
    $hdr = $_SERVER['HTTP_X_CSRF'] ?? '';
    if ($hdr !== ($_SESSION['csrf'] ?? '')) bad('CSRF',403);
  }

  if ($action==='whereami') {
    $root = $CONFIG['root'];
    $rootReal = realpath($root);
    j(['ok'=>true,'root'=>$root,'root_real'=>$rootReal,'exists'=>($rootReal!==false)]);
  }

  if ($action==='list') {
    if (!is_dir($abs)) bad('Not a directory');
    $ignored = array_flip($CONFIG['ignore_dirs']);
    $items=[]; foreach(scandir($abs) as $n){
      if ($n==='.'||$n==='..') continue;
      if (isset($ignored[$n])) continue;
      $p="$abs/$n"; $items[]=['name'=>$n,'rel'=>rel_of($p),'type'=>is_dir($p)?'dir':'file','size'=>is_file($p)?filesize($p):0,'mtime'=>filemtime($p)];
    }
    j(['ok'=>true,'items'=>$items]);
  }

  if ($action==='read') {
    if (!is_file($abs)) bad('Not a file');
    if (!is_text($abs)) bad('Not an editable text file');
    if (filesize($abs) > $CONFIG['max_edit'] && !is_opml($abs)) bad('Refusing to open files > 5MB');
    j(['ok'=>true,'content'=>file_get_contents($abs)]);
  }

  if ($action==='write' && $method==='POST') {
    need_write();
    if (!is_file($abs)) bad('Not a file');
    if (!is_writable($abs)) bad('Not writable');
    if (!is_text($abs)) bad('Not an editable text file');
    $data = json_decode(file_get_contents('php://input'),true);
    if (!is_array($data) || !array_key_exists('content',$data)) bad('Bad JSON');
    $tmp=$abs.'.tmp';
    $ok = file_put_contents($tmp,$data['content'])!==false && @rename($tmp,$abs);
    if(!$ok) @unlink($tmp);
    audit('write',$path,$ok);
    j(['ok'=>$ok]);
  }

  if ($action==='mkdir' && $method==='POST') {
    need_write();
    $data=json_decode(file_get_contents('php://input'),true); $name=trim(($data['name']??''),'/');
    if ($name==='') bad('Missing name');
    $dst=safe_abs($path.'/'.$name); if($dst===false) bad('Invalid target');
    if (file_exists($dst)) bad('Exists already');
    $ok=mkdir($dst,0775,true); audit('mkdir',rel_of($dst),$ok); j(['ok'=>$ok]);
  }

  if ($action==='newfile' && $method==='POST') {
    need_write();
    $data=json_decode(file_get_contents('php://input'),true); $name=trim(($data['name']??''),'/');
    if ($name==='') bad('Missing name');
    if (strpos($name,'.')===false) $name.='.txt';
    $dst=safe_abs($path.'/'.$name); if($dst===false) bad('Invalid target');
    if (file_exists($dst)) bad('Exists already');
    $ok = file_put_contents($dst,"")!==false; audit('newfile',rel_of($dst),$ok); j(['ok'=>$ok]);
  }

  if ($action==='delete' && $method==='POST') {
    need_write();
    if (!file_exists($abs)) bad('Not found');
    $ok = is_dir($abs) ? @rmdir($abs) : @unlink($abs);
    audit('delete',$path,$ok); j(['ok'=>$ok]);
  }

  if ($action==='rename' && $method==='POST') {
    need_write();
    $data = json_decode(file_get_contents('php://input'), true);
    $to   = trim(($data['to'] ?? $data['name'] ?? ''), '/');
    if ($to === '') bad('Missing target');
    $dst  = safe_abs($to);  if ($dst===false) bad('Invalid target');
    $ok   = @rename($abs, $dst);
    audit('rename', $path, $ok, '-> '.rel_of($dst));
    j(['ok'=>$ok]);
  }

  if ($action==='upload' && $method==='POST') {
    need_write();
    if (!is_dir($abs)) bad('Upload path is not a directory');
    if (empty($_FILES['file'])) bad('No file');
    $tmp=$_FILES['file']['tmp_name']; $name=basename($_FILES['file']['name']);
    $dst=safe_abs($path.'/'.$name); if($dst===false) bad('Bad target');
    $ok=move_uploaded_file($tmp,$dst); audit('upload',rel_of($dst),$ok); j(['ok'=>$ok,'name'=>$name]);
  }

  // ===== OPML helpers =====  // [NODE PATCH]
  function opml_load_dom($abs) {
    if (!class_exists('DOMDocument')) bad('DOM extension missing',500);
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0','UTF-8');
    $dom->preserveWhiteSpace = false;
    if (!$dom->load($abs, LIBXML_NONET)) bad('Invalid OPML/XML',422);
    return $dom;
  }
  function opml_body($dom){
    $b = $dom->getElementsByTagName('body')->item(0);
    if(!$b) bad('No <body> in OPML',422);
    return $b;
  }
  function opml_outline_at($parent,$idx){
    $i=-1; foreach($parent->childNodes as $c){
      if($c->nodeType===XML_ELEMENT_NODE && strtolower($c->nodeName)==='outline'){
        $i++; if($i===(int)$idx) return $c;
      }
    } return null;
  }
  function opml_node_by_id($dom,$id){ // id like "0/2/5"
    $p = trim((string)$id)==='' ? [] : array_map('intval', explode('/',$id));
    $cur = opml_body($dom);
    foreach($p as $ix){ $cur = opml_outline_at($cur,$ix); if(!$cur) bad('Node not found',404); }
    return $cur;
  }
  function opml_index_of($node){
    $i=-1; while($node && $node->previousSibling){
      $node=$node->previousSibling;
      if($node->nodeType===XML_ELEMENT_NODE && strtolower($node->nodeName)==='outline') $i++;
    } return $i;
  }
  function opml_id_of($node){ // compute "0/2/5"
    $path=[]; $n=$node;
    while($n && strtolower($n->nodeName)==='outline'){
      $ix = 0; $s=$n; while($s=$s->previousSibling){
        if($s->nodeType===XML_ELEMENT_NODE && strtolower($s->nodeName)==='outline') $ix++;
      }
      array_unshift($path,$ix);
      $n=$n->parentNode;
      if(!$n || strtolower($n->nodeName)==='body') break;
    }
    return implode('/',$path);
  }
  function opml_atomic_save($dom,$abs){
    $tmp=$abs.'.tmp';
    if($dom->save($tmp)===false) bad('Write failed',500);
    if(!@rename($tmp,$abs)){ @unlink($tmp); bad('Replace failed',500); }
  }
  function opml_ext_ok($abs){
    $ext=strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if(!in_array($ext,['opml','xml'])) bad('Not OPML/XML',415);
  }

  // ===== Return tree with IDs =====
  if ($action==='opml_tree') {  // [NODE PATCH]
    $file = $_GET['file'] ?? $_POST['file'] ?? '';
    $abs  = safe_abs($file); if($abs===false || !is_file($abs)) bad('Bad file');
    opml_ext_ok($abs);
    $dom = opml_load_dom($abs);
    $body = opml_body($dom);
    $walk = function($node,$prefix='') use (&$walk){
      $out=[]; $i=0;
      foreach($node->childNodes as $c){
        if($c->nodeType!==XML_ELEMENT_NODE || strtolower($c->nodeName)!=='outline') continue;
        $t = $c->getAttribute('title') ?: $c->getAttribute('text') ?: '•';
        $id = ($prefix==='') ? strval($i) : ($prefix.'/'.$i);
        $childs = $c->hasChildNodes() ? $walk($c,$id) : [];
        $out[] = ['id'=>$id,'t'=>$t,'children'=>$childs];
        $i++;
      } return $out;
    };
    j(['ok'=>true,'tree'=>$walk($body,'')]);
  }

  // ===== Node editing =====
  if ($action==='opml_node' && $method==='POST') {  // [NODE PATCH]
    need_write();
    $data = json_decode(file_get_contents('php://input'), true);
    $file = $data['file'] ?? ''; $op = $data['op'] ?? ''; $id = $data['id'] ?? '';
    $abs  = safe_abs($file); if($abs===false || !is_file($abs)) bad('Bad file');
    opml_ext_ok($abs);
    $dom = opml_load_dom($abs); $body=opml_body($dom);

    $sel = null;
    switch($op){
      case 'add_child': {
        $parent = opml_node_by_id($dom,$id);
        $n = $dom->createElement('outline');
        $title = trim($data['title'] ?? 'New');
        if($title!=='') $n->setAttribute('title',$title);
        $parent->appendChild($n);
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

      case 'set_title': {
        $cur = opml_node_by_id($dom,$id);
        $title = (string)($data['title'] ?? '');
        $cur->setAttribute('title',$title);
        if(!$cur->getAttribute('text')) $cur->setAttribute('text',$title);
        $sel = $cur;
      } break;

      case 'set_attrs': {
        $cur = opml_node_by_id($dom,$id);
        $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
        foreach($attrs as $k=>$v){
          $k = preg_replace('/[^a-zA-Z0-9_:\-]/','',$k);
          $v = is_array($v) ? implode(',', $v) : (string)$v;
          if($v==='') $cur->removeAttribute($k); else $cur->setAttribute($k,$v);
        }
        $sel = $cur;
      } break;

      case 'delete': {
        $cur = opml_node_by_id($dom,$id);
        $p = $cur->parentNode; $p->removeChild($cur);
        $sel = $p; // select parent after delete
      } break;

      case 'move': {
        $dir = $data['dir'] ?? '';
        $cur = opml_node_by_id($dom,$id);
        $par = $cur->parentNode;
        // helpers: previous / next outline sibling
        $prev=null; $next=null;
        for($n=$cur->previousSibling;$n;$n=$n->previousSibling){ if($n->nodeType===XML_ELEMENT_NODE && strtolower($n->nodeName)==='outline'){ $prev=$n; break; } }
        for($n=$cur->nextSibling;$n;$n=$n->nextSibling){ if($n->nodeType===XML_ELEMENT_NODE && strtolower($n->nodeName)==='outline'){ $next=$n; break; } }
        if($dir==='up' && $prev){
          $par->insertBefore($cur,$prev);
        } elseif($dir==='down' && $next){
          if($next->nextSibling) $par->insertBefore($cur,$next->nextSibling); else $par->appendChild($cur);
        } elseif($dir==='in' && $prev){
          // become last child of previous sibling
          $prev->appendChild($cur);
        } elseif($dir==='out'){
          if(strtolower($par->nodeName)!=='outline') bad('Cannot outdent root-level',400);
          $g = $par->parentNode; // grandparent
          if($par->nextSibling) $g->insertBefore($cur,$par->nextSibling); else $g->appendChild($cur);
        }
        $sel = $cur;
      } break;

      case 'move_to': { // [DRAG PATCH]
        $cur = opml_node_by_id($dom,$id);
        $destId = $data['dest'] ?? ''; $where = $data['where'] ?? 'after';
        $dest = opml_node_by_id($dom,$destId);
        // prevent drop into its own subtree
        for($a=$dest; $a; $a=$a->parentNode){ if($a===$cur){ bad('Cannot move into descendant',400); } if(strtolower($a->nodeName)==='body') break; }
        if ($where==='into'){
          $dest->appendChild($cur);
        } else {
          $par = $dest->parentNode;
          if ($where==='before'){ $par->insertBefore($cur,$dest); }
          else { if($dest->nextSibling) $par->insertBefore($cur,$dest->nextSibling); else $par->appendChild($cur); }
        }
        $sel = $cur;
      } break;

      default: bad('Unknown op',400);
    }

    opml_atomic_save($dom,$abs);
    $selectId = $sel ? opml_id_of($sel) : $id;
    j(['ok'=>true,'select'=>$selectId]);
  }

  // ===== Links API =====
  function links_path($abs){ return $abs.'.links.json'; }  // [NODE PATCH]
  function links_load($abs){
    $p=links_path($abs);
    if(!file_exists($p)) return ['meta'=>['v'=>1],'nodes'=>[]];
    $j=json_decode(@file_get_contents($p),true);
    if(!$j) return ['meta'=>['v'=>1],'nodes'=>[]];
    if(empty($j['meta'])) $j['meta']=['v'=>1];
    if(empty($j['nodes'])) $j['nodes']=[];
    return $j;
  }
  function links_save_atomic($abs,$obj){
    $p=links_path($abs); $tmp=$p.'.tmp';
    if(file_put_contents($tmp, json_encode($obj,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))===false) bad('Links write failed',500);
    if(!@rename($tmp,$p)){ @unlink($tmp); bad('Links replace failed',500); }
  }

  if($action==='links_list'){  // [NODE PATCH]
    $file=$_GET['file']??''; $id=$_GET['id']??'';
    $abs=safe_abs($file); if($abs===false || !is_file($abs)) bad('Bad file');
    $data=links_load($abs);
    $items = $id!=='' ? ($data['nodes'][$id] ?? []) : $data['nodes'];
    j(['ok'=>true,'items'=>$items]);
  }

  if($action==='links_edit' && $method==='POST'){  // [NODE PATCH]
    need_write();
    $data=json_decode(file_get_contents('php://input'),true);
    $file=$data['file']??''; $id=$data['id']??''; $op=$data['op']??'';
    $abs=safe_abs($file); if($abs===false || !is_file($abs)) bad('Bad file');
    $obj=links_load($abs);
    $obj['nodes'][$id] = $obj['nodes'][$id] ?? [];
    $now = date('c');

    if($op==='add'){
      $it=$data['item']??[];
      $it['id'] = $it['id'] ?? substr(bin2hex(random_bytes(6)),0,12);
      $it['label'] = trim($it['label'] ?? 'link');
      $it['href']  = trim($it['href']  ?? '');
      $it['tags']  = array_values(array_filter(array_map('trim', is_array($it['tags']??[])?$it['tags']:explode(',',(string)($it['tags']??'')))));
      $it['note']  = (string)($it['note']??'');
      $it['created']=$now; $it['updated']=$now;
      $obj['nodes'][$id][]=$it;
    } elseif($op==='update'){
      $linkId=$data['linkId']??''; $it=$data['item']??[];
      foreach($obj['nodes'][$id] as &$ref){
        if($ref['id']===$linkId){
          foreach(['label','href','note'] as $k){ if(isset($it[$k])) $ref[$k]=(string)$it[$k]; }
          if(isset($it['tags'])) $ref['tags']=array_values(array_filter(array_map('trim', is_array($it['tags'])?$it['tags']:explode(',',$it['tags']))));
          $ref['updated']=$now;
        }
      } unset($ref);
    } elseif($op==='delete'){
      $linkId=$data['linkId']??'';
      $obj['nodes'][$id]=array_values(array_filter($obj['nodes'][$id], fn($x)=>$x['id']!==$linkId));
    } else bad('Unknown links op',400);

    links_save_atomic($abs,$obj);
    j(['ok'=>true]);
  }

  if ($action==='format' && $method==='POST') { // OPML/XML/JSON pretty-print
    if (!is_file($abs)) bad('Not a file');
    $data=json_decode(file_get_contents('php://input'),true);
    $content = $data['content'] ?? '';
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if ($ext==='json') {
      $decoded = json_decode($content,true);
      if ($decoded===null) bad('Invalid JSON',400);
      j(['ok'=>true,'content'=>json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)]);
    }
    if ($ext==='xml' || $ext==='opml') {
      libxml_use_internal_errors(true);
      $dom=new DOMDocument('1.0','UTF-8'); $dom->preserveWhiteSpace=false; $dom->formatOutput=true;
      if(!$dom->loadXML($content)) bad('Invalid XML/OPML',400);
      j(['ok'=>true,'content'=>$dom->saveXML()]);
    }
    bad('Unsupported for format',400);
  }

  // [SEARCH PATCH] recursive regex search (text files only)
  if ($action==='search') {
    $q = $_GET['q'] ?? ''; if($q==='') bad('Empty regex');
    $baseRel = $_GET['path'] ?? ''; $base = safe_abs($baseRel); if($base===false || !is_dir($base)) bad('Bad path');
    $exts = array_filter(array_map('strtolower', array_map('trim', explode(',', $_GET['exts'] ?? ''))));
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
    $hits=[]; $cnt=0; $ignored = array_flip($CONFIG['ignore_dirs']);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    $delim = '#'; $pattern = $delim . $q . $delim . 'u';
    foreach ($it as $f){
      if ($cnt >= $limit) break;
      if (!$f->isFile()) continue;
      $sub = trim(str_replace(rtrim($CONFIG['root'],'/'), '', $f->getPath()), '/');
      $firstDir = $sub !== '' ? explode('/',$sub)[0] : '';
      if (isset($ignored[$firstDir])) continue;
      $rel = rel_of($f->getPathname()); $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
      if (!empty($exts) && !in_array($ext,$exts)) continue;
      if (in_array($ext,['png','jpg','jpeg','gif','webp','pdf','zip','gz','mp4','mp3','wav'])) continue;
      $txt = @file_get_contents($f->getPathname()); if ($txt===false) continue;
      $lines = preg_split('/\R/', $txt);
      for ($i=0; $i<count($lines) && $cnt<$limit; $i++){
        $ok = @preg_match($pattern, $lines[$i]);
        if ($ok === 1){
          $snip = mb_substr($lines[$i], 0, 220);
          $hits[] = ['rel'=>$rel, 'name'=>basename($rel), 'line'=>$i+1, 'snip'=>$snip];
          $cnt++;
        }
      }
    }
    j(['ok'=>true,'hits'=>$hits]);
  }

  bad('Unknown action',404);
}

/* ====== HTML (UI) ====== */
if (!$authed): ?>
<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?=$TITLE?> — Login</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;background:#0f0f12;color:#e5e5e5;display:grid;place-items:center;height:100dvh;margin:0}
.card{background:#141418;border:1px solid #2e2e36;padding:24px;border-radius:16px;min-width:280px}
h1{margin:0 0 16px;font-size:18px}
input{width:100%;padding:10px 12px;margin:8px 0;background:#0f0f12;border:1px solid #2e2e36;color:#fff;border-radius:10px}
button{width:100%;padding:10px 12px;background:#1e1e26;border:1px solid #3a3a46;color:#fff;border-radius:10px;cursor:pointer}
.err{color:#ff6b6b;margin:8px 0 0}
.tip{opacity:.7;font-size:12px;margin-top:6px}
</style>
<div class="card">
  <h1><?=$TITLE?></h1>
  <form method="post">
    <input name="u" placeholder="user" autofocus>
    <input name="p" type="password" placeholder="password">
    <button name="do_login" value="1">Sign in</button>
    <?php if(!empty($err)):?><div class="err"><?=$err?></div><?php endif;?>
    <?php if(!empty($expired)):?><div class="err">Session expired</div><script>localStorage.setItem('sessionExpired','1');</script><?php endif;?>
    <div class="tip">Tip: set env vars WEBEDITOR_USER / WEBEDITOR_PASS to avoid hardcoded creds.</div>
  </form>
</div>
<?php exit; endif; ?>
<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?=$TITLE?></title>
<style>
/* ---------- Design tokens (unified) ---------- */
:root{
  --bg:#0f0f12; --panel:#121218; --line:#262631; --text:#e5e5e5;
  --muted:#a5b2c2; --accent:#7cc9ff; --danger:#ff6b6b; --ok:#7de39a;
  --gap:10px; --radius:12px; --focus:#7cc9ff66; --top-h:52px; --tabs-h:58px;
  --tabs-space:0px; /* filled on mobile via media query */
}
/* [UX PATCH] Light theme overrides */
:root[data-theme="light"]{
  --bg:#f6f7fb; --panel:#ffffff; --line:#dfe3ea; --text:#16181d;
  --muted:#5c6b7c; --accent:#146ca8; --danger:#d81d2b; --ok:#118a3a;
}
/* Prefer system when auto */
@media (prefers-color-scheme: light){
  :root[data-theme="auto"]{
    --bg:#f6f7fb; --panel:#ffffff; --line:#dfe3ea; --text:#16181d;
    --muted:#5c6b7c; --accent:#146ca8; --danger:#d81d2b; --ok:#118a3a;
  }
}
*{box-sizing:border-box} html,body{height:100%}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif; padding-bottom:var(--tabs-space);}
.top{position:sticky;top:0;z-index:5;display:flex;gap:12px;align-items:center;padding:10px;height:var(--top-h);border-bottom:1px solid var(--line);background:linear-gradient(180deg,rgba(18,18,24,.95),rgba(18,18,24,.85))}
.path{opacity:.8}
.kv{display:flex;gap:8px;align-items:center}
.input{padding:10px 12px;background:#0e0e14;border:1px solid var(--line);color:#fff;border-radius:10px;min-height:40px}
.btn{padding:10px 12px;border:1px solid var(--line);background:#181822;border-radius:10px;color:#ddd;cursor:pointer;min-height:40px}
.btn.small{padding:6px 10px;min-height:36px}
.btn:focus,.input:focus{outline:2px solid var(--focus);outline-offset:2px}
.btn[disabled]{opacity:.5;cursor:not-allowed}
.grid{display:grid;grid-template-columns:280px 340px 1fr;gap:var(--gap);height:calc(100dvh - var(--top-h) - var(--tabs-space));padding:var(--gap)}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);display:flex;flex-direction:column;min-height:0}
.head{position:sticky;top:0;background:var(--panel);padding:8px 10px;border-bottom:1px solid var(--line);display:flex;gap:8px;align-items:center;z-index:1}
.body{padding:8px;overflow:auto;flex:1;min-height:0}
ul{list-style:none;margin:0;padding:0}
li{padding:6px;border-radius:8px;cursor:pointer}
li:hover{background:#181822}
small{opacity:.6}
.row{display:flex;gap:8px;align-items:center;justify-content:space-between}
.editorbar{padding:8px;border-bottom:1px solid var(--line);display:flex;gap:8px;align-items:center}
.tag{background:#1e1e26;border:1px solid var(--line);padding:3px 6px;border-radius:6px;font-size:12px}
.mono{font-family:ui-monospace,Consolas,monospace}
textarea{width:100%;height:100%;flex:1;min-height:220px;resize:none;background:#0e0e14;color:#e5e5e5;border:0;padding:14px;font-family:ui-monospace,Consolas,monospace}
footer{position:fixed;right:10px;bottom:8px;opacity:.5}
.crumb a{color:#aee;text-decoration:none;margin-right:6px}.crumb a:hover{text-decoration:underline}
/* [UX PATCH] Toasts */
#toasts{position:fixed; right:12px; bottom:12px; display:flex; flex-direction:column; gap:8px; z-index:20}
.toast{min-width:220px; max-width:360px; padding:10px 12px; border-radius:10px; border:1px solid var(--line); background:#1a1c28cc; color:var(--text); backdrop-filter:blur(8px);
  animation: slideIn .18s ease-out}
.toast.ok{border-color:rgba(125,227,154,.5)}
.toast.err{border-color:rgba(216,29,43,.5)}
.toast .tmsg{font-size:13px}
.toast .tbar{display:flex; justify-content:space-between; gap:8px; align-items:center}
.toast .tbtn{border:0;background:transparent;color:var(--muted);cursor:pointer}
/* [UPLOAD PROG] bar */
.toast .tprog{height:6px;border-radius:999px;background:#0002;border:1px solid #ffffff22;overflow:hidden;margin-top:6px}
.toast .tprog > i{display:block;height:100%;width:0%;background:linear-gradient(90deg, var(--accent), #9cf)}
@keyframes slideIn{from{transform:translateY(6px);opacity:.0} to{transform:translateY(0);opacity:1}}
/* [UX PATCH] Context menu */
#ctx{position:fixed; z-index:30; display:none; min-width:160px; background:var(--panel); border:1px solid var(--line); border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.35)}
#ctx button{display:block; width:100%; text-align:left; padding:10px 12px; border:0; background:transparent; color:var(--text); cursor:pointer}
#ctx button:hover{background:#181822}
/* [UX PATCH] Pull hint */
.pullHint{position:absolute; top:-34px; left:0; right:0; height:24px; display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:12px}
/* [PALETTE] */
#palette{position:fixed; inset:0; display:none; align-items:flex-start; justify-content:center; background:rgba(0,0,0,.4); z-index:40; padding-top:8vh}
#palette .box{width:min(720px, 92vw); background:var(--panel); border:1px solid var(--line); border-radius:14px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.5)}
#palette .box .head{display:flex; align-items:center; gap:8px; padding:10px; border-bottom:1px solid var(--line)}
#palette input{flex:1}
#plist{max-height:min(60vh, 520px); overflow:auto}
#plist .item{padding:10px 12px; border-bottom:1px solid #ffffff12; cursor:pointer}
#plist .item[aria-selected="true"]{background:#1e2030}
/* [SEARCH DRAWER] */
#searchDrawer{position:fixed; right:12px; top:calc(var(--top-h) + 12px); width:min(520px, 92vw); background:var(--panel); border:1px solid var(--line); border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.4); display:none; z-index:35}
#searchDrawer .head{display:flex; gap:8px; align-items:center; padding:10px; border-bottom:1px solid var(--line)}
#searchResults{max-height:min(60vh, 520px); overflow:auto; padding:8px}
#searchResults .hit{padding:8px; border-radius:8px; cursor:pointer}
#searchResults .hit:hover{background:#181822}
/* ---------- Mobile layout ---------- */
@media (max-width: 900px){
  :root{ --tabs-space: calc(var(--tabs-h) + env(safe-area-inset-bottom)); }
  .grid{grid-template-columns:1fr;grid-auto-rows:1fr;height:calc(100dvh - var(--top-h) - var(--tabs-space));}
  .panel{display:none}
  .panel.active{display:flex}
  .only-desktop{display:none !important}
}
/* Bottom tabs */
.mobileTabs{display:none}
@media (max-width: 900px){
 .mobileTabs{position:fixed;left:0;right:0;bottom:0;height:var(--tabs-h);padding:6px calc(6px + env(safe-area-inset-left)) 6px calc(6px + env(safe-area-inset-right));
   background:var(--panel);border-top:1px solid var(--line);display:flex;gap:8px;justify-content:space-between;align-items:center;z-index:6}
 .tab{flex:1;display:flex;gap:8px;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:12px;padding:10px 12px;color:#ddd;background:#171721}
 .tab[aria-selected="true"]{background:#1e2030;border-color:#3a3a46}
 .tab svg{width:18px;height:18px}
}
</style>

<div class="top">
  <div class="path" id="rootNote">root: …</div>
  <div class="crumb" id="crumb"></div>
  <button class="btn" onclick="openDir('')">Home</button>
  <div class="kv" style="margin-left:auto">
    <input id="pathInput" class="input" placeholder="jump to path (rel)">
    <button class="btn" onclick="jump()">Open</button>
    <button class="btn" id="searchBtn" title="Search (/)">Search</button>
    <!-- [UX PATCH] Theme switcher -->
    <select id="themeSel" class="input" style="width:120px">
      <option value="dark">Dark</option>
      <option value="auto">Auto</option>
      <option value="light">Light</option>
    </select>
    <a class="btn" href="?logout=1">Logout</a>
  </div>
</div>

<div class="grid">
  <div class="panel" id="paneFind">
    <div class="head"><strong>FIND</strong><button class="btn" onclick="mkdirPrompt()">+ Folder</button>
      <label class="btn only-desktop" style="position:relative;overflow:hidden">Upload Folder<input type="file" webkitdirectory multiple style="position:absolute;inset:0;opacity:0" onchange="uploadFolder(this)"></label>
    </div>
    <div class="body" style="position:relative">
      <div class="pullHint">↓ Pull to refresh</div>
      <ul id="folderList"></ul>
    </div>
  </div>

  <div class="panel" id="paneStruct">
    <div class="head"><strong>STRUCTURE</strong>
      <button class="btn" onclick="newFilePrompt()">+ File</button>
      <label class="btn" style="position:relative;overflow:hidden">Upload<input type="file" style="position:absolute;inset:0;opacity:0" onchange="uploadFile(this)"></label>
      <!-- [NODE PATCH] List / Tree toggle -->
      <span style="margin-left:auto; display:flex; gap:6px">
        <button class="btn small" id="structListBtn" type="button">List</button>
        <button class="btn small" id="structTreeBtn" type="button" title="Show OPML tree" disabled>Tree</button>
      </span>
    </div>
    <div class="body" style="position:relative">
      <div class="pullHint">↓ Pull to refresh</div>
      <ul id="fileList"></ul>
      <div id="opmlTreeWrap" style="display:none; position:absolute; inset:8px; overflow:auto"></div>
      <div id="treeTools" class="row" style="display:none; gap:6px; margin-top:6px">
        <button class="btn small" id="addChildBtn">+ Child</button>
        <button class="btn small" id="addSiblingBtn">+ Sibling</button>
        <button class="btn small" id="delNodeBtn">Delete</button>
        <button class="btn small" id="upBtn">↑</button>
        <button class="btn small" id="downBtn">↓</button>
        <button class="btn small" id="outBtn">⇤</button>
        <button class="btn small" id="inBtn">⇥</button>
      </div>
    </div>
  </div>

  <div class="panel" id="paneContent">
    <div class="editorbar">
      <strong>CONTENT</strong>
      <span class="tag" id="fileName">—</span>
      <span class="tag mono" id="fileSize"></span>
      <span class="tag" id="fileWhen"></span>
      <div style="margin-left:auto"></div>
      <!-- [FORMAT2] -->
      <button class="btn" onclick="formatDocV2()" id="fmt2Btn" disabled>Format</button>
      <button class="btn" onclick="save()" id="saveBtn" disabled>Save</button>
      <button class="btn" onclick="del()"  id="delBtn"  disabled>Delete</button>
    </div>
    <div class="body" style="padding:0;display:flex;flex-direction:column">
      <div id="nodeEditor" style="display:none; padding:8px; border-bottom:1px solid var(--line)">
        <div class="row" style="gap:8px; align-items:center">
          <label>Title:</label>
          <input id="nodeTitle" class="input" style="flex:1" placeholder="Node title">
          <button class="btn small" id="saveTitleBtn">Save Title</button>
        </div>
        <div style="margin-top:8px">
          <strong>Links</strong>
          <div id="linksList" style="margin-top:6px; display:flex; flex-direction:column; gap:6px"></div>
          <div class="row" style="gap:6px; margin-top:6px">
            <input class="input" id="newLinkLabel" placeholder="label">
            <input class="input" id="newLinkHref" placeholder="https://...">
            <input class="input" id="newLinkTags" placeholder="tags (comma)">
            <button class="btn small" id="addLinkBtn">+ Add</button>
          </div>
        </div>
      </div>
      <textarea id="ta" placeholder="Open a text file…" disabled></textarea>
    </div>
  </div>
</div>
<!-- Bottom tabs (mobile) -->
<nav class="mobileTabs" aria-label="Primary">
  <button class="tab" id="tabFind" aria-selected="false" title="Find">
    <svg viewBox="0 0 24 24" fill="none"><path d="M11 20a9 9 0 1 1 6.32-2.68L22 22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> Find
  </button>
  <button class="tab" id="tabStruct" aria-selected="false" title="Structure">
    <svg viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h10M4 18h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> Structure
  </button>
  <button class="tab" id="tabContent" aria-selected="true" title="Content">
    <svg viewBox="0 0 24 24" fill="none"><path d="M5 5h14v14H5z" stroke="currentColor" stroke-width="2"/><path d="M8 9h8M8 13h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> Content
  </button>
</nav>
<!-- [PALETTE] -->
<div id="palette" aria-modal="true" role="dialog">
  <div class="box">
    <div class="head">
      <input id="palInput" class="input" placeholder="Type a command or file… (Esc to close)">
    </div>
    <div id="plist"></div>
  </div>
</div>
<!-- [SEARCH PATCH] quick drawer -->
<div id="searchDrawer">
  <div class="head">
    <input id="reInput" class="input" placeholder="regex (e.g. ^title|^text=)" />
    <input id="extInput" class="input" placeholder="ext csv (e.g. opml,md,txt)" style="width:160px" />
    <button class="btn" id="runSearchBtn">Run</button>
    <button class="btn" id="closeSearchBtn">Close</button>
  </div>
  <div id="searchResults"></div>
</div>
<!-- [UX PATCH] Toast stack & context menu -->
<div id="toasts"></div>
<div id="ctx" role="menu">
  <button data-act="open">Open</button>
  <button data-act="rename">Rename</button>
  <button data-act="delete">Delete</button>
</div>
<footer><?=$TITLE?></footer>
<script>
const CSRF = '<?=htmlspecialchars($_SESSION['csrf'] ?? '')?>';
const api = (act,params)=>fetch(`?api=${act}&`+new URLSearchParams(params||{}));
let currentDir='', currentFile='';
let isMobile = window.matchMedia('(max-width: 900px)').matches;
const searchBtn = document.getElementById('searchBtn');
function setPane(p){
  if(!isMobile) return;
  ['Find','Struct','Content'].forEach(k=>{
    document.getElementById('pane'+k).classList.toggle('active', k===p);
    document.getElementById('tab'+k).setAttribute('aria-selected', k===p ? 'true':'false');
  });
}
function autoPaneTo(p){ if(isMobile) setPane(p); }
window.addEventListener('resize', ()=>{ isMobile = window.matchMedia('(max-width: 900px)').matches; if(isMobile){ setPane('Content'); } else { ['paneFind','paneStruct','paneContent'].forEach(id=>document.getElementById(id).classList.add('active')); }});
['paneFind','paneStruct','paneContent'].forEach(id=>document.getElementById(id).classList.add('active'));
tabFind.onclick   = ()=> setPane('Find');
tabStruct.onclick = ()=> setPane('Struct');
tabContent.onclick= ()=> setPane('Content');

/* ==================== UX PATCH: toasts, theme, shortcuts, pull, context ==================== */
// Toasts
function toast(msg, kind='info', secs=2.6){
  const box=document.getElementById('toasts');
  const t=document.createElement('div'); t.className='toast ' + (kind==='ok'?'ok':kind==='err'?'err':'');
  t.innerHTML=`<div class="tbar"><span class="tmsg">${escapeHtml(msg)}</span><button class="tbtn" aria-label="close">✕</button></div>`;
  box.appendChild(t);
  const close=()=>{ t.style.opacity='0'; setTimeout(()=>t.remove(),180); };
  t.querySelector('.tbtn').onclick=close;
  setTimeout(close, secs*1000);
}
function escapeHtml(s){ return s.replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
// Make any legacy alert() show as toast
window.alert = (m)=>toast(String(m));

// Theme switcher
const themeSel = document.getElementById('themeSel');
function setTheme(v){ document.documentElement.setAttribute('data-theme', v); localStorage.setItem('theme', v); }
themeSel.onchange = ()=> setTheme(themeSel.value);
setTheme(localStorage.getItem('theme') || 'dark'); themeSel.value = localStorage.getItem('theme') || 'dark';

// Keyboard shortcuts
document.addEventListener('keydown', (e)=>{
  const tag = (e.target.tagName||'').toLowerCase();
  const inField = tag==='input' || tag==='textarea' || e.target.isContentEditable;
  // Cmd/Ctrl+S: save
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s') { e.preventDefault(); save(); }
  // Cmd/Ctrl+K: palette
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='k') { e.preventDefault(); openPalette(); }
  // F: focus jump
  if (!inField && e.key.toLowerCase()==='f') { e.preventDefault(); document.getElementById('pathInput').focus(); }
  // / : open search drawer
  if (!inField && e.key==='/') { e.preventDefault(); toggleSearch(true); }
  // 1/2/3: switch panes
  if (!inField && ['1','2','3'].includes(e.key)) {
    e.preventDefault();
    if (e.key==='1') setPane('Find');
    if (e.key==='2') setPane('Struct');
    if (e.key==='3') setPane('Content');
  }
});

// Pull-to-refresh (mobile lists)
for (const body of document.querySelectorAll('.panel .body')){
  let startY=0, pulling=false;
  body.addEventListener('touchstart', (e)=>{ if(!isMobile) return; startY = e.touches[0].clientY; pulling=false; }, {passive:true});
  body.addEventListener('touchmove', (e)=>{
    if(!isMobile) return;
    const dy = e.touches[0].clientY - startY;
    if (body.scrollTop<=0 && dy>40){ body.querySelector('.pullHint')?.style.setProperty('color', 'var(--accent)'); pulling=true; }
  }, {passive:true});
  body.addEventListener('touchend', ()=>{
    if(!isMobile) return;
    body.querySelector('.pullHint')?.style.removeProperty('color');
    if (pulling){
      pulling=false;
      if (body.contains(document.getElementById('folderList'))) openDir(currentDir);
      else if (body.contains(document.getElementById('fileList'))) openDir(currentDir);
      toast('Refreshed ✓','ok',1.4);
    }
  });
}

// Context menu (right-click / long press)
const ctx = document.getElementById('ctx');
let ctxTarget = null;
function showCtx(x,y, target){ ctxTarget=target; ctx.style.display='block'; positionCtx(x,y); }
function hideCtx(){ ctx.style.display='none'; ctxTarget=null; }
function positionCtx(x,y){
  const r=ctx.getBoundingClientRect(); const W=innerWidth, H=innerHeight;
  ctx.style.left = Math.min(x, W - r.width - 8) + 'px';
  ctx.style.top  = Math.min(y, H - r.height - 8) + 'px';
}
document.addEventListener('click', (e)=>{ if(e.target.closest('#ctx')) return; hideCtx(); });
ctx.addEventListener('click', (e)=>{
  const act = e.target.dataset.act; if(!act || !ctxTarget) return;
  const {rel,isDir,name,size,mtime} = ctxTarget.dataset;
  if (act==='open'){
    if (isDir==='1') openDir(rel); else openFile(rel,name,Number(size)||0,Number(mtime)||0);
  } else if (act==='rename'){
    renameItem(new Event('noop'), rel);
  } else if (act==='delete'){
    deleteItem(new Event('noop'), rel);
  }
  hideCtx();
});
const structListBtn=document.getElementById('structListBtn');
const structTreeBtn=document.getElementById('structTreeBtn');
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
const linksList=document.getElementById('linksList');
const newLinkLabel=document.getElementById('newLinkLabel');
const newLinkHref=document.getElementById('newLinkHref');
const newLinkTags=document.getElementById('newLinkTags');
const addLinkBtn=document.getElementById('addLinkBtn');

function setCrumb(rel){
  const c=document.getElementById('crumb'); c.innerHTML='';
  const parts = rel? rel.split('/'): [];
  let acc = '';
  const rootA = document.createElement('a'); rootA.textContent='/'; rootA.href='#'; rootA.onclick=(e)=>{e.preventDefault(); openDir('');}; c.appendChild(rootA);
  parts.forEach((p,i)=>{
    acc += (i?'/':'') + p;
    const a=document.createElement('a'); a.textContent=p; a.href='#'; a.onclick=(e)=>{e.preventDefault(); openDir(acc);}; c.appendChild(a);
  });
  document.getElementById('pathInput').value = rel || '';
}

async function init(){
  const info = await (await api('whereami')).json();
  rootNote.textContent = 'root: ' + (info.root_real || info.root || '(unset)');
  openDir('');
  if(localStorage.getItem('sessionExpired')){
    toast('Session expired','err');
    localStorage.removeItem('sessionExpired');
  }
}

function ent(name,rel,isDir,size,mtime){
  const li=document.createElement('li');
  li.innerHTML=`<div class="row"><div>${isDir?'📁':'📄'} ${name}</div><small>${isDir?'':fmtSize(size)}</small></div>`;
  li.onclick=()=> isDir? openDir(rel) : openFile(rel,name,size,mtime);
  const row = li.querySelector('.row') || li;
  row.dataset.rel=rel; row.dataset.isDir=isDir? '1':'0'; row.dataset.name=name; row.dataset.size=size; row.dataset.mtime=mtime;
  li.addEventListener('contextmenu', (e)=>{ e.preventDefault(); showCtx(e.clientX, e.clientY, row); });
  let t=null;
  li.addEventListener('touchstart', (e)=>{ if(!isMobile) return; t=setTimeout(()=>{ const touch=e.touches[0]; showCtx(touch.clientX, touch.clientY, row); }, 500); }, {passive:true});
  li.addEventListener('touchend', ()=>{ if(t){ clearTimeout(t); t=null; } }, {passive:true});
  return li;
}

async function openDir(rel){
  currentDir = rel || '';
  setCrumb(currentDir);
  // left: folders
  const FL=document.getElementById('folderList'); FL.innerHTML='';
  const r = await (await api('list',{path:currentDir})).json();
  if (!r.ok){ toast(r.error||'list failed','err'); return; }
  if (currentDir){
    const up = currentDir.split('/').slice(0,-1).join('/');
    const li = document.createElement('li'); li.textContent='⬆️ ..'; li.onclick=()=>openDir(up); FL.appendChild(li);
  }
  r.items.filter(i=>i.type==='dir').sort((a,b)=>a.name.localeCompare(b.name)).forEach(d=>FL.appendChild(ent(d.name,d.rel,true,0,d.mtime)));
  // middle: files
  const FI=document.getElementById('fileList'); FI.innerHTML='';
  r.items.filter(i=>i.type==='file').sort((a,b)=>a.name.localeCompare(b.name)).forEach(f=>FI.appendChild(ent(f.name,f.rel,false,f.size,f.mtime)));
  autoPaneTo('Find');
}

function jump(){
  const p = document.getElementById('pathInput').value.trim();
  openDir(p);
}

function fmtSize(b){ if(b<1024) return b+' B'; let u=['KB','MB','GB']; let i=-1; do{ b/=1024;i++; }while(b>=1024&&i<2); return b.toFixed(1)+' '+u[i]; }
function fmtWhen(s){ try{return new Date(s*1000).toLocaleString();}catch{ return ''; } }

async function openFile(rel,name,size,mtime){
  currentFile = rel;
  fileName.textContent = name;
  fileSize.textContent = size?fmtSize(size):'';
  fileWhen.textContent = mtime?fmtWhen(mtime):'';
  const r = await (await api('read',{path:rel})).json();
  const ta = document.getElementById('ta');
  if (!r.ok){ alert(r.error||'Cannot open'); ta.value=''; ta.disabled=true; btns(false); return; }
  ta.value = r.content; ta.disabled = false; btns(true);
  const ext=name.toLowerCase().split('.').pop();
  document.getElementById('structTreeBtn').disabled = !['opml','xml'].includes(ext);
  hideTree(); // default to list on open
  autoPaneTo('Content');
  document.getElementById('fmt2Btn').disabled = !['json','xml','opml','md','markdown'].includes(ext);
}

function btns(on){
  saveBtn.disabled = !on; delBtn.disabled = !on;
}

async function save(){
  if (!currentFile) return;
  const body = JSON.stringify({content: document.getElementById('ta').value});
  const r = await (await fetch(`?api=write&`+new URLSearchParams({path:currentFile}), {method:'POST', headers:{'X-CSRF':CSRF}, body})).json();
  if (!r.ok){ toast(r.error||'Save failed','err'); return; }
  toast('Saved ✓','ok');
}

async function del(){
  if(!currentFile) return; if(!confirm('Delete this file?')) return;
  const r = await (await fetch(`?api=delete&`+new URLSearchParams({path:currentFile}), {method:'POST', headers:{'X-CSRF':CSRF}})).json();
  if (!r.ok){ toast(r.error||'Delete failed','err'); return; }
  ta.value=''; ta.disabled=true; btns(false); openDir(currentDir); toast('Deleted','ok');
}

async function mkdirPrompt(){
  const name=prompt('New folder name:'); if(!name) return;
  const r=await (await fetch(`?api=mkdir&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({name})})).json();
  if(!r.ok){ toast(r.error||'mkdir failed','err'); return; } openDir(currentDir); toast('Folder created','ok');
}

async function newFilePrompt(){
  const name=prompt('New file name:'); if(!name) return;
  const r=await (await fetch(`?api=newfile&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({name})})).json();
  if(!r.ok){ toast(r.error||'newfile failed','err'); return; } openDir(currentDir); toast('File created','ok');
}

async function uploadFile(inp){
  if(!inp.files.length) return; const fd=new FormData(); fd.append('file',inp.files[0]);
  const r=await (await fetch(`?api=upload&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:fd})).json();
  if(!r.ok){ toast(r.error||'upload failed','err'); return; } openDir(currentDir); toast('Uploaded ✓','ok',1.6);
}

async function uploadFolder(inp){
  if(!inp.files.length) return;
  const prog = makeProgToast(`Uploading ${inp.files.length} files…`, inp.files.length);
  for(const f of inp.files){
    const fd=new FormData(); fd.append('file',f);
    const relPath=f.webkitRelativePath||f.name;
    const subdir=relPath.split('/').slice(0,-1).join('/');
    const target=currentDir+(subdir?`/${subdir}`:'');
    const r=await (await fetch(`?api=upload&`+new URLSearchParams({path:target}),{method:'POST',headers:{'X-CSRF':CSRF},body:fd})).json();
    if(!r.ok){ prog.fail(r.error||'upload failed'); return; }
    prog.step();
  }
  openDir(currentDir); toast('Folder uploaded ✓','ok',1.8);
}

async function deleteItem(ev,rel){
  ev.stopPropagation();
  if(!confirm('Delete?')) return;
  const r=await (await fetch(`?api=delete&`+new URLSearchParams({path:rel}),{method:'POST',headers:{'X-CSRF':CSRF}})).json();
  if(!r.ok){ toast(r.error||'delete failed','err'); return; }
  if(currentFile===rel){ document.getElementById('ta').value=''; document.getElementById('ta').disabled=true; btns(false); }
  openDir(currentDir); toast('Deleted','ok');
}

async function renameItem(ev,rel){
  ev.stopPropagation();
  const name=prompt('Rename to:'); if(!name) return;
  const dir = rel.split('/').slice(0,-1).join('/');
  const target = (dir? dir+'/' : '') + name.replace(/^\/+/,'');
  const r=await (await fetch(`?api=rename&`+new URLSearchParams({path:rel}),{
    method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({to:target})
  })).json();
  if(!r.ok){ toast(r.error||'rename failed','err'); return; } openDir(currentDir); toast('Renamed ✓','ok');
}
// ===== Tree selection & tools ====================================  // [NODE PATCH]
let selectedId = null;

function hideTree(){ treeWrap.style.display='none'; fileList.style.visibility='visible'; treeTools.style.display='none'; nodeEditor.style.display='none'; }
function showTree(){
  if(!currentFile) return;
  treeWrap.style.display='block'; fileList.style.visibility='hidden';
  loadTree();
  treeTools.style.display='flex';
  autoPaneTo('Struct');
}

function renderTree(nodes){
  const wrap=document.createElement('div'); wrap.style.lineHeight='1.35'; wrap.style.fontSize='14px';
  const mk=(arr,prefix='')=>{
    const ul=document.createElement('ul'); ul.style.listStyle='none'; ul.style.paddingLeft='14px'; ul.style.margin='6px 0';
    for(const n of arr){
      const li=document.createElement('li');
      const row=document.createElement('div'); row.style.display='flex'; row.style.alignItems='center'; row.style.gap='.35rem';
      const has=n.children && n.children.length;
      const caret=document.createElement('span'); caret.textContent=has?'▸':'•'; caret.style.cursor=has?'pointer':'default';
      const title=document.createElement('span'); title.textContent=n.t;
      row.dataset.id = n.id;
      row.onclick = (e)=>{
        if(has && e.target===caret){ child.style.display = child.style.display==='none' ? 'block':'none'; caret.textContent = child.style.display==='none' ? '▸':'▾'; }
        selectNode(n.id, n.t);
      };
      li.appendChild(row);
      if(has){ const child=mk(n.children,n.id); child.style.display='none'; li.appendChild(child); }
      ul.appendChild(li);
    } return ul;
  };
  wrap.appendChild(mk(nodes));
  treeWrap.replaceChildren(wrap);
}

async function loadTree(){
  const data = await (await api('opml_tree',{file:currentFile})).json();
  if(!data.ok){ treeWrap.textContent = data.error||'OPML error'; return; }
  renderTree(data.tree||[]);
}

async function selectNode(id, title){
  selectedId = id;
  nodeEditor.style.display='block';
  nodeTitle.value = title || '';
  await refreshLinks();
}

// ===== Node ops (buttons) =========================================
async function nodeOp(op, extra={}){
  if(!currentFile || selectedId===null) return;
  const body = JSON.stringify({file:currentFile, op, id:selectedId, ...extra});
  const r = await (await fetch(`?api=opml_node`, {method:'POST', headers:{'X-CSRF':CSRF,'Content-Type':'application/json'}, body})).json();
  if(!r.ok){ alert(r.error||'node op failed'); return; }
  selectedId = r.select ?? selectedId;
  await loadTree(); // refresh view
  await refreshLinks();
}

addChildBtn.onclick   = ()=> nodeOp('add_child',{title:'New'});
addSiblingBtn.onclick = ()=> nodeOp('add_sibling',{title:'New'});
delNodeBtn.onclick    = ()=> { if(confirm('Delete this node?')) nodeOp('delete'); };
upBtn.onclick         = ()=> nodeOp('move',{dir:'up'});
downBtn.onclick       = ()=> nodeOp('move',{dir:'down'});
inBtn.onclick         = ()=> nodeOp('move',{dir:'in'});
outBtn.onclick        = ()=> nodeOp('move',{dir:'out'});

saveTitleBtn.onclick  = ()=> nodeOp('set_title',{title:nodeTitle.value});

// Enable Tree only for OPML
function enableTreeIfOPML(name){
  const ext=name.toLowerCase().split('.').pop();
  structTreeBtn.disabled = !['opml','xml'].includes(ext);
}

// Hook existing openFile
const _openFile = openFile;
openFile = async (rel,name,size,mtime)=>{
  await _openFile(rel,name,size,mtime);
  enableTreeIfOPML(name);
  nodeEditor.style.display='none'; selectedId=null;
  autoPaneTo('Content');
};

// ===== Links CRUD ================================================
async function refreshLinks(){
  if(!currentFile || selectedId===null) return;
  const data = await (await api('links_list',{file:currentFile, id:selectedId})).json();
  if(!data.ok){ linksList.textContent = data.error||'links error'; return; }
  const items = data.items || [];
  const list = document.createElement('div');
  for(const it of items){
    const row=document.createElement('div'); row.className='row'; row.style.gap='6px';
    const lbl=in('label',it.label); const href=in('href',it.href); const tags=in('tags',(it.tags||[]).join(', '));
    const save=document.createElement('button'); save.className='btn small'; save.textContent='Save';
    const del=document.createElement('button');  del.className='btn small';  del.textContent='X';
    save.onclick=async ()=>{
      const body = JSON.stringify({file:currentFile,id:selectedId,op:'update',linkId:it.id, item:{
        label:lbl.value, href:href.value, tags:tags.value
      }});
      const r = await (await fetch(`?api=links_edit`,{method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},body})).json();
      if(!r.ok) alert(r.error||'save link failed');
    };
    del.onclick=async ()=>{
      if(!confirm('Delete link?')) return;
      const body = JSON.stringify({file:currentFile,id:selectedId,op:'delete',linkId:it.id});
      const r = await (await fetch(`?api=links_edit`,{method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},body})).json();
      if(!r.ok) alert(r.error||'delete link failed'); else refreshLinks();
    };
    row.append(lbl,href,tags,save,del); list.append(row);
  }
  linksList.replaceChildren(list);

  function in(ph,val){ const e=document.createElement('input'); e.className='input'; e.placeholder=ph; e.value=val||''; e.style.flex='1'; return e; }
}

addLinkBtn.onclick = async ()=>{
  if(!currentFile || selectedId===null) return;
  const label=newLinkLabel.value.trim()||'link';
  const href =newLinkHref.value.trim();
  const tags =newLinkTags.value.trim();
  const body = JSON.stringify({file:currentFile,id:selectedId,op:'add', item:{label,href,tags}});
  const r = await (await fetch(`?api=links_edit`,{method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},body})).json();
  if(!r.ok){ alert(r.error||'add link failed'); return; }
  newLinkLabel.value=''; newLinkHref.value=''; newLinkTags.value='';
  refreshLinks();
};

// Tree/List buttons (assumes existing ids)
structListBtn.onclick = ()=> hideTree();
structTreeBtn.onclick = ()=> showTree();

/* ================== [UPLOAD PROG] progress toasts ================== */
const uploadQueue = [];
function makeProgToast(label,total){
  const box=document.getElementById('toasts');
  const t=document.createElement('div'); t.className='toast';
  t.innerHTML=`<div class="tbar"><span class="tmsg">${escapeHtml(label)}</span><span class="tmsg" id="pc">0%</span></div><div class="tprog"><i></i></div>`;
  const bar=t.querySelector('i'); const pc=t.querySelector('#pc');
  box.appendChild(t);
  let done=0;
  return {
    step(){ done++; const p=Math.round(done/total*100); bar.style.width=p+'%'; pc.textContent=p+'%'; if(p>=100){ t.classList.add('ok'); setTimeout(()=>t.remove(),1200);} },
    fail(msg){ t.classList.add('err'); t.querySelector('.tmsg').textContent = msg||'Error'; setTimeout(()=>t.remove(),2200); }
  };
}

/* ================== [DRAG PATCH] Tree drag-and-drop ================== */
let dragId=null;
opmlTreeWrap.addEventListener('dragstart', e=>{
  const row=e.target.closest('[data-id]'); if(!row) return;
  dragId=row.dataset.id; e.dataTransfer.setData('text/plain', dragId);
  e.dataTransfer.effectAllowed='move';
});
opmlTreeWrap.addEventListener('dragover', e=>{
  if(!dragId) return; e.preventDefault();
  const rect=opmlTreeWrap.getBoundingClientRect();
  if(e.clientY<rect.top+40) opmlTreeWrap.scrollTop-=20;
  if(e.clientY>rect.bottom-40) opmlTreeWrap.scrollTop+=20;
});
opmlTreeWrap.addEventListener('drop', async e=>{
  if(!dragId) return;
  const row=e.target.closest('[data-id]'); if(!row){ dragId=null; return; }
  e.preventDefault();
  const dest=row.dataset.id;
  const y=row.getBoundingClientRect(); const relY=(e.clientY - y.top)/y.height;
  const where = relY<0.33 ? 'before' : relY>0.67 ? 'after' : 'into';
  if(dest===dragId) { dragId=null; return; }
  await nodeOp('move_to',{id:dragId, dest, where});
  dragId=null;
});

// make rows draggable in renderTree()
const _renderTree = renderTree;
renderTree = (nodes)=>{
  _renderTree(nodes);
  opmlTreeWrap.querySelectorAll('div[data-id]').forEach(el=>{ el.setAttribute('draggable','true'); });
};

/* ================== [PALETTE] Command palette ================== */
const palette=document.getElementById('palette'), palInput=document.getElementById('palInput'), plist=document.getElementById('plist');
function openPalette(){ palette.style.display='flex'; palInput.value=''; fillPalette(); palInput.focus(); }
function closePalette(){ palette.style.display='none'; }
palette.addEventListener('click', e=>{ if(e.target===palette) closePalette(); });
document.addEventListener('keydown', e=>{ if(palette.style.display==='flex' && e.key==='Escape'){ e.preventDefault(); closePalette(); }});
palInput.addEventListener('input', fillPalette);
palInput.addEventListener('keydown', e=>{
  const cur = plist.querySelector('.item[aria-selected="true"]') || plist.firstElementChild;
  if(e.key==='ArrowDown'){ e.preventDefault(); (cur?.nextElementSibling||plist.firstElementChild)?.setAttribute('aria-selected','true'); cur?.removeAttribute('aria-selected'); }
  if(e.key==='ArrowUp'){ e.preventDefault(); (cur?.previousElementSibling||plist.lastElementChild)?.setAttribute('aria-selected','true'); cur?.removeAttribute('aria-selected'); }
  if(e.key==='Enter'){ e.preventDefault(); cur?.click(); }
});
function fillPalette(){
  const q=(palInput.value||'').toLowerCase();
  const cmds=[
    {t:'Open path…', run:()=>document.getElementById('pathInput').focus()},
    {t:'New file', run:()=>newFilePrompt()},
    {t:'New folder', run:()=>mkdirPrompt()},
    {t:'Save file', run:()=>save()},
    {t:'Format document', run:()=>formatDocV2(), show:()=>!document.getElementById('fmt2Btn').disabled},
    {t:'Toggle Tree/List', run:()=>document.getElementById('structTreeBtn').disabled?null:(opmlTreeWrap.style.display==='none'?showTree():hideTree())},
    {t:'Switch → Find (1)', run:()=>setPane('Find')},
    {t:'Switch → Structure (2)', run:()=>setPane('Struct')},
    {t:'Switch → Content (3)', run:()=>setPane('Content')},
    {t:'Search… (/)', run:()=>toggleSearch(true)}
  ];
  const files=[...document.querySelectorAll('#fileList .row')].map(r=>({t:'Open: '+r.dataset.name, run:()=>openFile(r.dataset.rel, r.dataset.name, Number(r.dataset.size)||0, Number(r.dataset.mtime)||0)}));
  const all=[...cmds, ...files].filter(c=>!c.show || c.show());
  const hits=all.filter(c=>c.t.toLowerCase().includes(q));
  plist.innerHTML=''; hits.forEach((h,i)=>{ const d=document.createElement('div'); d.className='item'; d.textContent=h.t; if(i==0) d.setAttribute('aria-selected','true'); d.onclick=()=>{ closePalette(); h.run&&h.run(); }; plist.appendChild(d); });
}

/* ================== [FORMAT2] Inline prettify ================== */
function formatDocV2(){
  if(!currentFile) return;
  const name = fileName.textContent||'';
  const ext = name.toLowerCase().split('.').pop();
  const ta=document.getElementById('ta');
  if(['json','xml','opml'].includes(ext)){
    return formatViaServer();
  }
  if(['md','markdown'].includes(ext)){
    ta.value = prettyMarkdown(ta.value);
    toast('Formatted Markdown ✓','ok');
  }
}
async function formatViaServer(){
  const body=JSON.stringify({content:document.getElementById('ta').value});
  const r=await (await fetch(`?api=format&`+new URLSearchParams({path:currentFile}),{method:'POST',headers:{'X-CSRF':CSRF},body})).json();
  if(!r.ok){ toast(r.error||'format failed','err');return;} document.getElementById('ta').value=r.content; toast('Formatted ✓','ok');
}
function prettyMarkdown(md){
  const lines = md.replace(/\s+$/g,'').split(/\r?\n/);
  const out=[]; for(let i=0;i<lines.length;i++){
    let L=lines[i].replace(/[ \t]+$/,'');
    if(/^\s*[*•]\s/.test(L)) L=L.replace(/^\s*[*•]\s/,'- ');
    if(/^\s*#{1,6}\s+/.test(L) && out.length && out[out.length-1]!=='' ) out.push('');
    if(/^\s*\d+\.\s+/.test(L) && out.length && out[out.length-1]==='' && i>0 && /^\s*\d+\.\s+/.test(lines[i-1])){}
    out.push(L);
  }
  if(out[out.length-1]!=='') out.push('');
  return out.join('\n');
}

/* ================== [SEARCH PATCH] Drawer + API client ================== */
const searchDrawer=document.getElementById('searchDrawer');
const reInput=document.getElementById('reInput'), extInput=document.getElementById('extInput');
searchBtn.onclick = ()=> toggleSearch(true);
document.getElementById('closeSearchBtn').onclick = ()=> toggleSearch(false);
document.getElementById('runSearchBtn').onclick = runSearch;
function toggleSearch(on){ searchDrawer.style.display = on?'block':'none'; if(on){ reInput.focus(); } }
async function runSearch(){
  const q=reInput.value.trim()||'.'; const exts=extInput.value.trim();
  const r=await (await api('search',{path:currentDir, q, exts, limit:200})).json();
  const box=document.getElementById('searchResults'); box.innerHTML='';
  if(!r.ok){ box.textContent=r.error||'search failed'; return; }
  for(const h of r.hits){
    const d=document.createElement('div'); d.className='hit';
    d.innerHTML=`<div class="mono" style="font-size:12px;opacity:.75">${h.rel}:${h.line}</div><div>${escapeHtml(h.snip)}</div>`;
    d.onclick=async ()=>{
      await openFile(h.rel, h.name || h.rel.split('/').pop(), h.size||0, 0);
      const ta=document.getElementById('ta'); const lines=ta.value.split(/\n/); let pos=0;
      for(let i=0;i<Math.min(h.line-1, lines.length);i++) pos+=lines[i].length+1;
      ta.focus(); ta.setSelectionRange(pos,pos);
      toggleSearch(false);
    };
    box.appendChild(d);
  }
}

init();
</script>
