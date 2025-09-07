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

function rcopy($src,$dst){
  if(is_dir($src)){
    if(!is_dir($dst)) @mkdir($dst,0775,true);
    foreach(scandir($src) as $f){
      if($f==='.'||$f==='..') continue;
      rcopy($src.'/'.$f,$dst.'/'.$f);
    }
    return true;
  }
  $dir=dirname($dst); if(!is_dir($dir)) @mkdir($dir,0775,true);
  return @copy($src,$dst);
}

function uuidv4(){
  $data=random_bytes(16);
  $data[6]=chr(ord($data[6])&0x0f|0x40);
  $data[8]=chr(ord($data[8])&0x3f|0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));
}

function uuidv5($ns,$name){
  $ns=str_replace('-','',$ns); $ns=hex2bin($ns);
  if($ns===false || strlen($ns)!==16) return uuidv4();
  $hash=sha1($ns.$name);
  return sprintf('%08s-%04s-5%03s-%04x-%12s',
    substr($hash,0,8),
    substr($hash,8,4),
    substr($hash,12,3),
    (hexdec(substr($hash,16,4)) & 0x3fff) | 0x8000,
    substr($hash,20,12));
}

function cjsf_to_ark($items){
  $map=function($node) use (&$map){
    $title=$node['title']??($node['metadata']['title']??($node['content']??''));
    $out=['t'=>$title, 'id'=>$node['id']??'', 'arkid'=>$node['id']??'', 'children'=>[]];
    if(isset($node['content'])) $out['note']=$node['content'];
    if(!empty($node['links']) && is_array($node['links'])){
      $links=[];
      foreach($node['links'] as $l){
        $links[]=[
          'title'=>$l['metadata']['title']??'',
          'type'=>$l['type']??'',
          'target'=>$l['target']??'',
          'direction'=>$l['metadata']['direction']??null
        ];
      }
      if($links) $out['links']=$links;
    }
    if(!empty($node['children']) && is_array($node['children'])){
      $out['children']=array_map($map,$node['children']);
    }
    return $out;
  };
  return array_map($map,$items);
}

function cjsf_find_item_ref(&$items,$id,&$out){
  foreach($items as &$it){
    if(($it['id']??'')===$id){ $out=&$it; return true; }
    if(isset($it['children']) && is_array($it['children']) && cjsf_find_item_ref($it['children'],$id,$out)) return true;
  }
  return false;
}

function cjsf_find_parent(&$items,$id,&$parent,&$index,&$parentItem=null){
  foreach($items as $i=>&$it){
    if(($it['id']??'')===$id){
      $parent=&$items; $index=$i; return true;
    }
    if(isset($it['children']) && is_array($it['children'])){
      if(cjsf_find_parent($it['children'],$id,$parent,$index,$parentItem)){
        $parentItem=&$it; return true;
      }
    }
  }
  return false;
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

  if ($action==='get_file') {
    if (!is_file($abs)) bad('Not a file');
    $mime = mime_content_type($abs) ?: 'application/octet-stream';
    header('Content-Type: '.$mime);
    readfile($abs);
    exit;
  }

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
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    $content='';
    if($ext==='json'){
      $now=gmdate('Y-m-d\TH:i:s\Z');
      $content=json_encode([
        'schemaVersion'=>'1.0.0',
        'id'=>uuidv4(),
        'metadata'=>['title'=>'New File Title'],
        'root'=>[
          [
            'id'=>uuidv4(),
            'type'=>'note',
            'content'=>'This is your first item. Edit its content here.',
            'created'=>$now,
            'modified'=>$now,
            'metadata'=>['title'=>'Root Item']
          ]
        ]
      ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    }
    $ok=file_put_contents($dst,$content)!==false; audit('newfile',rel_of($dst),$ok); j(['ok'=>$ok]);
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

  if ($action==='copy_item' && $method==='POST') {
    $data=json_decode(file_get_contents('php://input'),true);
    $src=safe_abs($data['src'] ?? '');
    $dst=safe_abs($data['dst'] ?? '');
    if($src===false || $dst===false) bad('Invalid path');
    $ok=rcopy($src,$dst); audit('copy',rel_of($src),$ok,'-> '.rel_of($dst)); j(['ok'=>$ok]);
  }

  if ($action==='upload' && $method==='POST') {
    if (!is_dir($abs)) bad('Upload path is not a directory');
    if (empty($_FILES['file'])) bad('No file');
    $tmp=$_FILES['file']['tmp_name']; $name=basename($_FILES['file']['name']);
    $dst=safe_abs($path.'/'.$name); if($dst===false) bad('Bad target');
    $dir=dirname($dst); if(!is_dir($dir)) @mkdir($dir,0775,true);
    $ok=move_uploaded_file($tmp,$dst); audit('upload',rel_of($dst),$ok); j(['ok'=>$ok,'name'=>$name]);
  }

  if ($action==='download_folder') {
    if (!is_dir($abs)) bad('Not a directory');
    if (!class_exists('ZipArchive')) bad('ZipArchive not available',500);
    $zip=new ZipArchive();
    $tmp=tempnam(sys_get_temp_dir(),'zip');
    if($zip->open($tmp,ZipArchive::OVERWRITE)!==true) bad('Zip create failed',500);
    $srcLen=strlen($abs)+1;
    $add=function($dir) use (&$add,$zip,$srcLen){
      foreach(scandir($dir) as $f){
        if($f==='.'||$f==='..') continue;
        $p="$dir/$f"; $local=substr($p,$srcLen);
        if(is_dir($p)){ $zip->addEmptyDir($local); $add($p); }
        else $zip->addFile($p,$local);
      }
    };
    $add($abs);
    $zip->close();
    $base=basename($abs);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.($base?:'folder').'.zip"');
    header('Content-Length: '.filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
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

  if ($action==='standardize_opml' && $method==='POST') {
    if (!is_file($abs)) bad('Not a file');
    $ext=strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!in_array($ext,['opml','xml'])) bad('Not OPML/XML',415);
    $dom=opml_load_dom($abs); $body=opml_body($dom);
    $idMap=[];
    $map=function($node) use (&$map,&$idMap){
      foreach($node->childNodes as $c){
        if($c->nodeType!==XML_ELEMENT_NODE||strtolower($c->nodeName)!=='outline') continue;
        $id=$c->getAttribute('ark:id');
        $txt=$c->getAttribute('text')?:$c->getAttribute('title')?:'';
        if($id!=='') $idMap[$id]=$txt;
        if($c->hasChildNodes()) $map($c);
      }
    };
    $map($body);
    $walk=function($node) use (&$walk,&$idMap){
      foreach($node->childNodes as $c){
        if($c->nodeType!==XML_ELEMENT_NODE||strtolower($c->nodeName)!=='outline') continue;
        if(!$c->hasAttribute('ark:id')||trim($c->getAttribute('ark:id'))==='')
          $c->setAttribute('ark:id',uuidv4());
        if(!$c->hasAttribute('text')||$c->getAttribute('text')===''){
          $t=$c->getAttribute('title');
          if($t!=='') $c->setAttribute('text',$t);
        }
        if($c->hasAttribute('ark:links')){
          $ln=json_decode($c->getAttribute('ark:links'),true);
          if(is_array($ln)){
            $clean=[];
            foreach($ln as $l){
              if(!is_array($l)) continue;
              if(isset($l['to'])){
                $l['target']=$l['to'];
                $l['title']=$idMap[$l['to']] ?? '';
                unset($l['to']);
              }
              if(isset($l['title'],$l['type'],$l['target']) && $l['title']!=='' && $l['type']!=='' && $l['target']!=='')
                $clean[]=$l;
            }
            if($clean) $c->setAttribute('ark:links',json_encode($clean,JSON_UNESCAPED_SLASHES));
            else $c->removeAttribute('ark:links');
          } else $c->removeAttribute('ark:links');
        }
        if($c->hasChildNodes()) $walk($c);
      }
    };
    $walk($body);
    $dom->formatOutput=true; $ok=$dom->save($abs)!==false; audit('standardize_opml',$path,$ok);
    j(['ok'=>$ok,'size'=>filesize($abs),'mtime'=>filemtime($abs)]);
  }

  if ($action==='standardize_json' && $method==='POST') {
    if (!is_file($abs)) bad('Not a file');
    $ext=strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if ($ext!=='json') bad('Not JSON',415);
    $raw=@file_get_contents($abs); if($raw===false) bad('Read error',500);
    $doc=json_decode($raw,true);
    if(isset($doc['root']) && is_array($doc['root'])){
      $root=$doc['root'];
      $id=$doc['id'] ?? uuidv4();
      $title=$doc['metadata']['title'] ?? pathinfo($abs,PATHINFO_FILENAME);
    }elseif(is_array($doc)){
      $root=$doc;
      $id=uuidv4();
      $title=pathinfo($abs,PATHINFO_FILENAME);
    }else bad('Invalid JSON',422);
    $wrapped=[
      'schemaVersion'=>'1.0.0',
      'id'=>$id,
      'metadata'=>['title'=>$title],
      'root'=>$root
    ];
    $ok=file_put_contents($abs,json_encode($wrapped,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))!==false;
    audit('standardize_json',$path,$ok);
    j(['ok'=>$ok,'size'=>filesize($abs),'mtime'=>filemtime($abs)]);
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
    $idMap=[];
    $mapWalk=function($node) use (&$mapWalk,&$idMap){
      foreach($node->childNodes as $c){
        if ($c->nodeType!==XML_ELEMENT_NODE || strtolower($c->nodeName)!=='outline') continue;
        $ark=$c->getAttribute('ark:id');
        $txt=$c->getAttribute('title') ?: $c->getAttribute('text') ?: '';
        if($ark!=='') $idMap[$ark]=$txt;
        if($c->hasChildNodes()) $mapWalk($c);
      }
    };
    if($body) $mapWalk($body);
    $walk = function($node,$path='') use (&$walk,&$idMap){
      $out=[]; $i=0; foreach($node->childNodes as $c){
        if ($c->nodeType!==XML_ELEMENT_NODE || strtolower($c->nodeName)!=='outline') continue;
        $t = $c->getAttribute('title') ?: $c->getAttribute('text') ?: '•';
        $id = $path===''? (string)$i : $path.'/'.$i;
        $item = ['t'=>$t, 'id'=>$id, 'children'=>[]];
        if ($c->hasAttribute('_note')) $item['note']=$c->getAttribute('_note');
        if ($c->hasAttribute('ark:id')) $item['arkid']=$c->getAttribute('ark:id');
        if ($c->hasAttribute('ark:links')){
          $ln=json_decode($c->getAttribute('ark:links'),true);
          if(is_array($ln)){
            $links=[];
            foreach($ln as $l){
              if(!is_array($l)) continue;
              if(!isset($l['title']) && isset($l['to'])){
                $l['target']=$l['to'];
                $l['title']=$idMap[$l['to']] ?? '';
                unset($l['to']);
              }
              $links[]=$l;
            }
            if($links) $item['links']=$links;
          }
        }
        if ($c->hasChildNodes()) $item['children']=$walk($c,$id);
        $out[]=$item; $i++;
      } return $out;
    };
    $tree = $body? $walk($body) : [];
    j(['ok'=>true,'tree'=>$tree]);
  }

  if ($action==='json_tree') {
    $file = $_GET['file'] ?? $_POST['file'] ?? '';
    $fileAbs = safe_abs($file);
    if ($fileAbs===false || !is_file($fileAbs)) bad('Bad file');
    $ext = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION));
    if ($ext !== 'json') bad('Not JSON',415);
    $raw = @file_get_contents($fileAbs); if($raw===false) bad('Read error',500);
    $doc = json_decode($raw,true);
    if(!isset($doc['root']) || !is_array($doc['root'])) bad('Invalid document',422);
    j(['ok'=>true,'root'=>$doc['root']]);
  }

  if ($action==='get_all_json_nodes') {
    $file = $_GET['file'] ?? '';
    $fileAbs = safe_abs($file);
    if ($fileAbs===false || !is_file($fileAbs)) bad('Bad file');
    $ext = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION));
    if ($ext !== 'json') bad('Not JSON',415);
    $raw = @file_get_contents($fileAbs); if($raw===false) bad('Read error',500);
    $doc = json_decode($raw,true);
    if(!isset($doc['root']) || !is_array($doc['root'])) bad('Invalid document',422);
    $nodes = [];
    $walk = function($items) use (&$walk,&$nodes){
      foreach($items as $it){
        if(!is_array($it)) continue;
        $id=$it['id'] ?? '';
        $title=$it['title'] ?? '';
        if($id!=='') $nodes[]=['arkid'=>$id,'title'=>$title];
        if(!empty($it['children']) && is_array($it['children'])) $walk($it['children']);
      }
    };
    $walk($doc['root']);
    j(['ok'=>true,'nodes'=>$nodes]);
  }

  if ($action==='get_all_nodes') {
    $file = $_GET['file'] ?? '';
    $fileAbs = safe_abs($file);
    if ($fileAbs===false || !is_file($fileAbs)) bad('Bad file');
    $ext = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['opml','xml'])) bad('Not OPML/XML',415);
    $dom = opml_load_dom($fileAbs); $body = opml_body($dom);
    $nodes = [];
    $walk = function($node) use (&$walk,&$nodes){
      foreach($node->childNodes as $c){
        if($c->nodeType!==XML_ELEMENT_NODE || strtolower($c->nodeName)!=='outline') continue;
        $id=$c->getAttribute('ark:id');
        $text=$c->getAttribute('text') ?: $c->getAttribute('title') ?: '';
        if($id!=='') $nodes[]=['arkid'=>$id,'title'=>$text];
        if($c->hasChildNodes()) $walk($c);
      }
    };
    $walk($body);
    j(['ok'=>true,'nodes'=>$nodes]);
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
      case 'add_link': {
        $cur = opml_node_by_id($dom,$id);
        $link = $data['link'] ?? null;
        if(!is_array($link)) bad('Missing link',400);
        $linksAttr = $cur->getAttribute('ark:links');
        $links = $linksAttr ? json_decode($linksAttr,true) : [];
        if(!is_array($links)) $links=[];
        $links[] = $link;
        $cur->setAttribute('ark:links', json_encode($links, JSON_UNESCAPED_SLASHES));
        $sel = $cur;
      } break;
      case 'delete_link': {
        $cur = opml_node_by_id($dom,$id);
        $target = (string)($data['target'] ?? '');
        $linksAttr = $cur->getAttribute('ark:links');
        $links = $linksAttr ? json_decode($linksAttr,true) : [];
        if(is_array($links)){
          $links = array_values(array_filter($links,function($l) use ($target){ return ($l['target'] ?? '') !== $target; }));
        } else $links=[];
        if(empty($links)) $cur->removeAttribute('ark:links');
        else $cur->setAttribute('ark:links', json_encode($links, JSON_UNESCAPED_SLASHES));
        $sel = $cur;
      } break;
      default: bad('Unknown op',400);
    }
    opml_save_dom($dom,$fileAbs);
    j(['ok'=>true,'id'=>opml_id_of($sel ?: $body)]);
  }

  if ($action==='json_node' && $method==='POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $file = $data['file'] ?? '';
    $id   = $data['id'] ?? '';
    $op   = $data['op'] ?? '';
    $fileAbs = safe_abs($file);
    if ($fileAbs===false || !is_file($fileAbs)) bad('Bad file');
    $ext = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION));
    if ($ext !== 'json') bad('Not JSON',415);
    $raw = @file_get_contents($fileAbs); if($raw===false) bad('Read error',500);
    $doc = json_decode($raw,true);
    if(!is_array($doc) || (($doc['schemaVersion'] ?? '') !== '1.0.0')) bad('Invalid schema',422);
    if(!isset($doc['root']) || !is_array($doc['root'])) $doc['root']=[];
    $now=gmdate('Y-m-d\TH:i:s\Z');
    $selId=$id;
    $parentList=$index=$parentItem=null;
    if(in_array($op,['add_sibling','delete','move'])){
      if(!cjsf_find_parent($doc['root'],$id,$parentList,$index,$parentItem)) bad('Node not found',404);
      $item=&$parentList[$index];
    }else{
      $item=null; if(!cjsf_find_item_ref($doc['root'],$id,$item)) bad('Node not found',404);
    }
    switch($op){
      case 'set_title': {
        $title=(string)($data['title'] ?? '');
        $item['title']=$title;
        if(!isset($item['metadata']) || !is_array($item['metadata'])) $item['metadata']=[];
        $item['metadata']['title']=$title;
        $item['modified']=$now;
      } break;
      case 'add_child': {
        $title=trim($data['title'] ?? 'New');
        $new=[
          'id'=>uuidv4(), 'type'=>'note', 'title'=>$title, 'content'=>'',
          'created'=>$now, 'modified'=>$now, 'metadata'=>['title'=>$title],
          'links'=>[], 'children'=>[]
        ];
        if(!isset($item['children']) || !is_array($item['children'])) $item['children']=[];
        $item['children'][]=$new;
        $item['modified']=$now;
        $selId=$new['id'];
      } break;
      case 'add_sibling': {
        $title=trim($data['title'] ?? 'New');
        $new=[
          'id'=>uuidv4(), 'type'=>'note', 'title'=>$title, 'content'=>'',
          'created'=>$now, 'modified'=>$now, 'metadata'=>['title'=>$title],
          'links'=>[], 'children'=>[]
        ];
        array_splice($parentList,$index+1,0,[$new]);
        if($parentItem) $parentItem['modified']=$now;
        $selId=$new['id'];
      } break;
      case 'delete': {
        array_splice($parentList,$index,1);
        if($parentItem){ $parentItem['modified']=$now; $selId=$parentItem['id'] ?? ($doc['root'][0]['id'] ?? null); }
        else { $selId=$doc['root'][0]['id'] ?? null; }
      } break;
      case 'move': {
        $dir=$data['dir'] ?? '';
        if($dir==='up' && $index>0){
          $tmp=$parentList[$index-1]; $parentList[$index-1]=$parentList[$index]; $parentList[$index]=$tmp;
        }elseif($dir==='down' && $index<count($parentList)-1){
          $tmp=$parentList[$index+1]; $parentList[$index+1]=$parentList[$index]; $parentList[$index]=$tmp;
        }elseif($dir==='in' && $index>0){
          $prev=&$parentList[$index-1];
          if(!isset($prev['children']) || !is_array($prev['children'])) $prev['children']=[];
          $node=$parentList[$index];
          array_splice($parentList,$index,1);
          $prev['children'][]=$node;
          $prev['modified']=$now;
        }elseif($dir==='out' && $parentItem){
          $gpList=$gpIndex=$gpParent=null;
          if(!cjsf_find_parent($doc['root'],$parentItem['id'],$gpList,$gpIndex,$gpParent)) bad('Cannot outdent root-level',400);
          $node=$parentList[$index];
          array_splice($parentList,$index,1);
          array_splice($gpList,$gpIndex+1,0,[$node]);
          if($gpParent) $gpParent['modified']=$now;
        }
        $item['modified']=$now;
      } break;
      case 'add_link': {
        $link=$data['link'] ?? null; if(!is_array($link)) bad('Missing link',400);
        if(empty($link['id'])) $link['id']=uuidv5($id,($link['type']??'').'|'.($link['target']??''));
        if(!isset($item['links']) || !is_array($item['links'])) $item['links']=[];
        $item['links'][]=$link;
        $item['modified']=$now;
      } break;
      case 'delete_link': {
        $linkId=$data['linkId'] ?? null; $target=$data['target'] ?? null;
        if(isset($item['links']) && is_array($item['links'])){
          $item['links']=array_values(array_filter($item['links'],function($l) use ($linkId,$target){
            if($linkId) return ($l['id'] ?? '') !== $linkId;
            if($target) return ($l['target'] ?? '') !== $target;
            return true;
          }));
          $item['modified']=$now;
        }
      } break;
      default: bad('Unknown op',400);
    }
    $ok=file_put_contents($fileAbs,json_encode($doc,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))!==false;
    if(!$ok) bad('Write failed',500);
    j(['ok'=>true,'id'=>$selId]);
  }

  if ($action==='save_json_structure' && $method==='POST') {
    $file = $_GET['path'] ?? '';
    $fileAbs = safe_abs($file);
    if ($fileAbs===false) bad('Bad path');
    $ext = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION));
    if ($ext !== 'json') bad('Not JSON',415);
    $raw = file_get_contents('php://input');
    $ok = file_put_contents($fileAbs,$raw)!==false;
    if(!$ok) bad('Write failed',500);
    j(['ok'=>true]);
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
  <style>
    .mobile-view li > div{padding:1rem;font-size:1.125rem;}
    .mobile-view svg{width:1.5rem;height:1.5rem;}
  </style>
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
    <button onclick="openDir('')" class="px-2 py-1 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200 text-gray-600 hover:text-gray-800">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
      <span class="sr-only">Home</span>
    </button>
    <nav id="crumb" class="flex items-center gap-2 text-sm text-gray-600"></nav>
    <div class="ml-auto relative">
      <button id="settingsBtn" class="p-2 rounded hover:bg-gray-100" title="Settings">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 5.25h16.5M3.75 12h16.5m-16.5 6.75h16.5"/></svg>
      </button>
      <div id="settingsMenu" class="hidden absolute right-0 mt-2 w-40 bg-white border rounded shadow-md">
        <div class="px-4 py-2 border-b">
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" id="mobileToggle" class="mr-1">
            <span>Mobile View</span>
          </label>
        </div>
        <button id="aboutBtn" class="block w-full text-left px-4 py-2 hover:bg-gray-100 text-gray-700">About</button>
        <button id="versionBtn" class="block w-full text-left px-4 py-2 hover:bg-gray-100 text-gray-700">Version Log</button>
        <a href="?logout=1" class="block px-4 py-2 text-gray-700 hover:bg-gray-100">Logout</a>
      </div>
    </div>
  </header>
  <main class="flex-1 overflow-auto md:overflow-hidden p-4 space-y-4 md:space-y-0 md:grid md:grid-cols-3 md:gap-4 md:h-[calc(100vh-64px)] min-h-0">
    <!-- FIND -->
    <section class="bg-white rounded shadow flex flex-col overflow-hidden min-h-0">
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
      <div id="findBody" class="flex-1 flex flex-col overflow-hidden min-h-0">
        <div class="flex gap-2 p-4">
          <input id="pathInput" class="flex-1 border rounded px-2 py-1" placeholder="jump to path (rel)">
          <button onclick="jump()" class="p-2 text-blue-600 hover:text-blue-800" title="Open">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12l-7.5 7.5M21 12H3"/></svg>
          </button>
        </div>
        <ul id="folderList" class="flex-1 overflow-auto divide-y text-sm min-h-0"></ul>
      </div>
    </section>

    <!-- STRUCTURE -->
    <section class="bg-white rounded shadow flex flex-col overflow-hidden min-h-0">
      <div class="flex items-center gap-2 p-4 border-b">
        <h2 class="font-semibold flex-1">STRUCTURE</h2>
        <button onclick="newFilePrompt()" class="p-2 text-gray-600 hover:text-gray-800" title="New File">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
        </button>
        <label class="p-2 text-gray-600 hover:text-gray-800 cursor-pointer" title="Upload File">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
          <input type="file" class="hidden" onchange="uploadFile(this)">
        </label>
        <button id="pasteBtn" class="p-2 text-gray-600 hover:text-gray-800 hidden" title="Paste">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5h7.5m-7.5 0A2.25 2.25 0 006 6.75v11.25A2.25 2.25 0 008.25 20.25h7.5A2.25 2.25 0 0018 18V6.75a2.25 2.25 0 00-2.25-2.25m-7.5 0V3.75A2.25 2.25 0 0110.5 1.5h3a2.25 2.25 0 012.25 2.25V4.5"/></svg>
        </button>
        <div class="ml-auto flex gap-2">
          <button id="structListBtn" type="button" class="px-2 py-1 text-sm border rounded">List</button>
          <button id="structTreeBtn" type="button" class="px-2 py-1 text-sm border rounded" title="Show OPML ARK" disabled>ARK</button>
          <div class="relative">
            <button id="sort-btn" class="hidden px-2 py-1 text-sm border rounded" title="Sort">
              <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h13M3 8h9M3 12h5m4 0l4 4m0 0l4-4m-4 4V4"/></svg>
            </button>
            <div id="sort-menu" class="hidden absolute right-0 mt-1 bg-white border rounded shadow text-sm z-10">
              <button data-sort="name" class="block w-full text-left px-3 py-1 hover:bg-gray-100">Name</button>
              <button data-sort="size" class="block w-full text-left px-3 py-1 hover:bg-gray-100">Size</button>
              <button data-sort="date" class="block w-full text-left px-3 py-1 hover:bg-gray-100">Date</button>
            </div>
          </div>
        </div>
        <button class="ml-2 md:hidden" onclick="toggleSection('structBody', this)">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
        </button>
      </div>
      <div id="structBody" class="p-4 flex-1 flex flex-col overflow-hidden min-h-0">
        <ul id="fileList" class="flex-1 overflow-auto divide-y text-sm min-h-0"></ul>
        <div id="opmlTreeWrap" class="hidden flex-1 overflow-auto min-h-0"></div>
      </div>
    </section>

    <!-- CONTENT -->
    <section class="bg-white rounded shadow flex flex-col overflow-hidden min-h-0">
      <div class="flex flex-wrap items-center gap-2 p-4 border-b">
        <h2 class="font-semibold">CONTENT</h2>
        <input id="fileTitle" class="hidden text-sm bg-gray-100 rounded px-2 py-1" />
        <button id="fileRenameBtn" class="hidden p-2 text-green-600 hover:text-green-800" title="Rename">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
        </button>
        <button onclick="showCurrentInfo()" id="infoBtn" disabled class="p-2 text-gray-600 hover:text-gray-800 disabled:opacity-50" title="Info">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25h1.5v5.25h-1.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 9h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </button>
        <div id="contentTabs" class="hidden flex gap-2">
          <button id="codeTab" class="px-2 py-1 text-sm border rounded bg-gray-200">Code</button>
          <button id="previewTab" class="px-2 py-1 text-sm border rounded">Preview</button>
          <button id="edit-mode-btn" class="px-2 py-1 text-sm border rounded">Edit</button>
        </div>
        <div class="ml-auto flex gap-2 flex-wrap">
          <button onclick="downloadFile()" id="downloadBtn" disabled class="p-2 rounded text-gray-600 hover:text-gray-800 disabled:opacity-50" title="Download">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M7.5 9l4.5 4.5 4.5-4.5M12 13.5V3"/></svg>
          </button>
          <button onclick="save()" id="saveBtn" disabled class="p-2 rounded text-blue-600 hover:text-blue-800 disabled:opacity-50" title="Save">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M7.5 11.25l4.5 4.5 4.5-4.5M12 15.75V3"/></svg>
          </button>
          <button onclick="del()" id="delBtn" disabled class="p-2 rounded text-red-600 hover:text-red-800 disabled:opacity-50" title="Delete">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
          </button>
        </div>
        <button class="ml-2 md:hidden" onclick="toggleSection('contentBody', this)">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
        </button>
      </div>
      <div id="contentBody" class="flex-1 flex flex-col overflow-hidden min-h-0">
        <div id="nodeTitleRow" class="hidden flex items-center gap-2 p-4 border-b">
          <input id="nodeTitle" type="text" class="flex-1 border rounded px-2 py-1" placeholder="Title">
          <button id="titleSaveBtn" class="p-2 text-green-600 hover:text-green-800" title="Save Title">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
          </button>
        </div>
        <div id="imgPreviewWrap" class="hidden flex-1 overflow-auto items-center justify-center min-h-[16rem]"><img id="imgPreview" class="max-w-full" /></div>
        <div id="opmlPreview" class="p-4 flex-1 overflow-auto hidden"></div>
        <div id="content-preview" class="p-4 flex-1 overflow-auto hidden"></div>
        <textarea id="content-editor" class="p-4 flex-1 overflow-auto hidden"></textarea>
        <textarea id="ta" class="flex-1 w-full p-4 resize-none border-0 outline-none overflow-auto min-h-[16rem]" placeholder="Open a text file…" disabled></textarea>
        <div id="linkList" class="hidden border-t p-2 flex flex-wrap text-sm"></div>
      </div>
    </section>
  </main>

  <div id="modalOverlay" class="fixed inset-0 hidden bg-black bg-opacity-40 flex items-center justify-center">
    <div class="bg-white rounded p-6 w-80 max-w-full">
      <h3 id="modalTitle" class="font-semibold mb-2"></h3>
      <div id="modalBody" class="mb-4"></div>
      <div class="flex justify-between items-center">
        <button id="modalExtra" class="hidden px-3 py-1 border rounded text-red-600 border-red-600 hover:bg-red-50"></button>
        <div class="flex gap-2">
          <button id="modalCancel" class="px-3 py-1 border rounded">Cancel</button>
          <button id="modalOk" class="px-3 py-1 bg-blue-600 text-white rounded">OK</button>
        </div>
      </div>
    </div>
  </div>
  <footer class="text-xs text-gray-500 text-right p-2"><?=$TITLE?></footer>

  <script>
const CSRF = '<?=htmlspecialchars($_SESSION['csrf'] ?? '')?>';
const api=(act,params)=>fetch(`?api=${act}&`+new URLSearchParams(params||{}));
let currentDir='', currentFile='', currentOutlinePath='', currentFileInfo=null;
let clipboardPath='';
const icons={
  folder:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>',
  file:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>',
  edit:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>',
  trash:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>',
  download:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M7.5 9l4.5 4.5 4.5-4.5M12 13.5V3"/></svg>',
  info:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25h1.5v5.25h-1.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 9h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
  addSame:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>',
  addSub:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v7.5m0 0h7.5m-7.5 0v7.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 19.5L18 18l-1.5-1.5"/></svg>',
  link:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 015.657 5.657l-3 3a4 4 0 01-5.657-5.657m-1.414-1.414a4 4 0 010-5.657l3-3a4 4 0 015.657 5.657"/></svg>',
  kebab:'<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zm0 6a.75.75 0 110-1.5.75.75 0 010 1.5zm0 6a.75.75 0 110-1.5.75.75 0 010 1.5z"/></svg>'
};
const listBtn=document.getElementById('structListBtn');
const treeBtn=document.getElementById('structTreeBtn');
const treeWrap=document.getElementById('opmlTreeWrap');
const fileList=document.getElementById('fileList');
const saveBtn=document.getElementById('saveBtn');
const delBtn=document.getElementById('delBtn');
const downloadBtn=document.getElementById('downloadBtn');
const infoBtn=document.getElementById('infoBtn');
const ta=document.getElementById('ta');
const contentTabs=document.getElementById('contentTabs');
const codeTab=document.getElementById('codeTab');
const previewTab=document.getElementById('previewTab');
const opmlPreview=document.getElementById('opmlPreview');
const sortBtn=document.getElementById('sort-btn');
const sortMenu=document.getElementById('sort-menu');
const contentPreview=document.getElementById('content-preview');
const contentEditor=document.getElementById('content-editor');
const editModeBtn=document.getElementById('edit-mode-btn');
let selectedId=null, nodeMap={}, arkMap={}, currentLinks=[], currentJsonRoot=[], currentJsonDoc=null;
let fileSort={criterion:'name',direction:'asc'};
let originalRootOrder=[];
let saveContentTimer=null, saveTitleTimer=null;

function findJsonNode(items,id){
  for(const it of items||[]){
    if(it.id===id) return it;
    const ch=findJsonNode(it.children||[],id);
    if(ch) return ch;
  }
  return null;
}

function findJsonParent(items,id,parent=null){
  for(let i=0;i<(items||[]).length;i++){
    const it=items[i];
    if(it.id===id) return {parent,index:i,array:items};
    const res=findJsonParent(it.children||[],id,it);
    if(res) return res;
  }
  return null;
}

async function saveCurrentJsonStructure(){
  if(!currentFile || !currentFile.toLowerCase().endsWith('.json') || !currentJsonDoc) return;
  currentJsonDoc.root=currentJsonRoot;
  const body=JSON.stringify(currentJsonDoc,null,2);
  try{
    await fetch(`?api=save_json_structure&path=${encodeURIComponent(currentFile)}`,{
      method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},body
    });
    ta.value=body;
  }catch(e){console.error('save failed',e);}
}
function cjsf_to_ark(items){
  function walk(arr){
    const out=[];
    for(const it of arr||[]){
      if(!it || typeof it!=='object') continue;
      const title=it.title||(it.metadata&&it.metadata.title)||it.content||'•';
      const n={
        t:title,
        id:it.id||'',
        arkid:it.id||'',
        note:it.content||''
      };
      if(Array.isArray(it.links) && it.links.length){
        n.links=it.links.map(l=>({
          title:(l.metadata&&l.metadata.title)||'',
          type:l.type||'',
          target:l.target||'',
          direction:l.metadata&&l.metadata.direction||null
        }));
      }
      if(Array.isArray(it.children) && it.children.length) n.children=walk(it.children);
      else n.children=[];
      out.push(n);
    }
    return out;
  }
  return walk(items);
}
function getAllJsonNodes(){
  const out=[];
  (function walk(arr){
    for(const it of arr||[]){
      if(!it || typeof it!=='object') continue;
      out.push({id:it.id||'', title:(it.metadata&&it.metadata.title)||it.content||''});
      if(Array.isArray(it.children) && it.children.length) walk(it.children);
    }
  })(currentJsonRoot);
  return out;
}
const settingsBtn=document.getElementById('settingsBtn');
const settingsMenu=document.getElementById('settingsMenu');
if(settingsBtn && settingsMenu){
  settingsBtn.addEventListener('click',e=>{e.stopPropagation(); settingsMenu.classList.toggle('hidden');});
  document.addEventListener('click',e=>{ if(!settingsMenu.contains(e.target)) settingsMenu.classList.add('hidden'); });
}
document.addEventListener('click',e=>{ if(!e.target.closest('.itemMenu')) hideMenus(); });
function toggleMenu(btn){ const m=btn.nextElementSibling; document.querySelectorAll('.itemMenu').forEach(el=>{ if(el!==m) el.classList.add('hidden');}); m.classList.toggle('hidden'); }
function hideMenus(){ document.querySelectorAll('.itemMenu').forEach(el=>el.classList.add('hidden')); }

const pasteBtn=document.getElementById('pasteBtn');
if(pasteBtn) pasteBtn.addEventListener('click', pasteItem);
const mobileToggle=document.getElementById('mobileToggle');
if(mobileToggle) mobileToggle.addEventListener('change',()=>{ document.body.classList.toggle('mobile-view', mobileToggle.checked); });
const aboutBtn=document.getElementById('aboutBtn');
if(aboutBtn) aboutBtn.addEventListener('click',()=>{settingsMenu.classList.add('hidden'); modalInfo('About','<p>Simple file manager.</p>');});
const versionBtn=document.getElementById('versionBtn');
if(versionBtn) versionBtn.addEventListener('click',()=>{settingsMenu.classList.add('hidden'); modalInfo('Version Log','<p>v1.0</p>');});
if(listBtn && treeBtn){
  listBtn.onclick=()=>hideTree();
  treeBtn.onclick=()=>showTree();
}

if(codeTab && previewTab){
  codeTab.addEventListener('click',()=>toggleContentMode('code'));
  previewTab.addEventListener('click',()=>toggleContentMode('preview'));
}
if(editModeBtn){
  editModeBtn.addEventListener('click',()=>{
    if(selectedId!==null){
      const node=findJsonNode(currentJsonRoot,selectedId);
      contentEditor.value=node?node.content||'':'';
    }
    toggleContentMode('edit');
  });
}

function toggleContentMode(mode){
  const isStruct=currentFile && ['opml','xml','json'].includes(currentFile.split('.').pop().toLowerCase());
  const previewEl=isStruct?opmlPreview:contentPreview;
  [ta,contentEditor,contentPreview,opmlPreview].forEach(el=>{ if(el) el.style.display='none'; });
  if(mode==='code' && ta) ta.style.display='';
  if(mode==='edit' && contentEditor) contentEditor.style.display='';
  if(mode==='preview' && previewEl) previewEl.style.display='';
  if(codeTab) codeTab.classList.toggle('bg-gray-200',mode==='code');
  if(previewTab) previewTab.classList.toggle('bg-gray-200',mode==='preview');
  if(editModeBtn) editModeBtn.classList.toggle('bg-gray-200',mode==='edit');
}
if(contentEditor){
  contentEditor.addEventListener('input',()=>{
    const node=findJsonNode(currentJsonRoot,selectedId);
    if(node){
      node.content=contentEditor.value;
      node.modified=new Date().toISOString();
      if(contentPreview) contentPreview.innerHTML=node.content;
      renderOpmlPreview(cjsf_to_ark(currentJsonRoot));
      clearTimeout(saveContentTimer);
      saveContentTimer=setTimeout(()=>saveCurrentJsonStructure(),500);
    }
  });
}

function escapeHtml(str){
  return str.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
}
function mdLinks(str){
  return str.replace(/\[([^\]]+)\]\(([^)]+)\)/g,'<a href="$2">$1</a>');
}
function renderOpmlPreview(nodes){
  const jsonMode=currentFile.toLowerCase().endsWith('.json');
  const usedIds=new Set();
  function slug(str){
    const base=(str||'section').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'')||'section';
    let id=base, i=1; while(usedIds.has(id)) id=base+'-'+(i++); usedIds.add(id); return id;
  }
  function makeTitleEditable(span,id){
    span.addEventListener('click',e=>{
      if(!jsonMode) return; e.stopPropagation();
      const input=document.createElement('input');
      input.type='text'; input.value=span.textContent;
      span.replaceWith(input); input.focus();
      const finish=()=>{
        const val=input.value;
        span.textContent=val;
        input.replaceWith(span);
        const node=findJsonNode(currentJsonRoot,id); if(node){
          node.title=val;
          if(node.metadata && typeof node.metadata==='object') node.metadata.title=val;
          else if(!node.metadata){
            node.metadata = { title: val };
          }
          node.modified=new Date().toISOString();
        }
        saveCurrentJsonStructure();
      };
      input.addEventListener('blur',finish);
      input.addEventListener('keydown',e=>{if(e.key==='Enter'){finish();}});
    });
  }
  function makeNoteEditable(div,id,n){
    div.addEventListener('click',()=>{
      if(!jsonMode) return;
      const textarea=document.createElement('textarea');
      textarea.value=n.note||'';
      textarea.className='w-full border rounded p-1 text-sm';
      div.replaceWith(textarea);
      textarea.focus();
      const finish=()=>{
        const val=textarea.value;
        const node=findJsonNode(currentJsonRoot,id); if(node){ node.content=val; node.modified=new Date().toISOString(); }
        n.note=val;
        const newDiv=document.createElement('div');
        newDiv.className=div.className;
        newDiv.innerHTML=mdLinks(escapeHtml(val).replace(/\n/g,'<br>'));
        makeNoteEditable(newDiv,id,n);
        textarea.replaceWith(newDiv);
        saveCurrentJsonStructure();
      };
      textarea.addEventListener('blur',finish);
    });
  }
  function walk(arr,level){
    const ul=document.createElement('ul');
    ul.className='list-none space-y-2'+(level?' ml-4 pl-4 border-l border-gray-300':'');
    arr.forEach((n,i)=>{
      const li=document.createElement('li');
      li.className='mt-2';
      if(level===0){ li.id=n._id || (n._id=slug(n.t)); }
      const title=document.createElement('div');
      title.className='flex items-center cursor-pointer';
      const titleSpan=document.createElement('span');
      titleSpan.textContent=n.t||'';
      if(level===0) titleSpan.className='font-bold text-lg';
      else if(level===1) titleSpan.className='font-semibold';
      if(jsonMode) makeTitleEditable(titleSpan,n.id);
      if(n.children && n.children.length){
        const caret=document.createElement('span');
        caret.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-4 h-4 text-gray-500 transform transition-transform"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';
        caret.className='mr-1';
        title.append(caret,titleSpan);
        const childUl=walk(n.children,level+1);
        childUl.style.display='none';
        const toggle=()=>{
          const open=childUl.style.display==='none';
          childUl.style.display=open?'block':'none';
          const svg=caret.querySelector('svg');
          if(svg) svg.classList.toggle('rotate-90',open);
        };
        title.addEventListener('click',toggle);
        li.appendChild(title);
        if(n.note){
          const note=document.createElement('div');
          note.className='ml-2 text-gray-600 text-sm';
          note.innerHTML=mdLinks(escapeHtml(n.note).replace(/\n/g,'<br>'));
          if(jsonMode) makeNoteEditable(note,n.id,n);
          li.appendChild(note);
        }
        if(n.links && n.links.length){
          const linkDiv=document.createElement('div');
          linkDiv.className='ml-2 flex flex-wrap gap-2 text-sm';
          n.links.forEach(l=>{
            const a=document.createElement('a');
            a.textContent=l.title||l.target;
            a.href=l.target;
            a.dataset.link=JSON.stringify(l);
            a.className='inline-block px-2 py-1 bg-gray-100 rounded hover:bg-gray-200 text-gray-700';
            linkDiv.appendChild(a);
          });
          li.appendChild(linkDiv);
        }
        li.appendChild(childUl);
      }else{
        title.appendChild(titleSpan);
        li.appendChild(title);
        if(n.note){
          const note=document.createElement('div');
          note.className='ml-2 text-gray-600 text-sm';
          note.innerHTML=mdLinks(escapeHtml(n.note).replace(/\n/g,'<br>'));
          if(jsonMode) makeNoteEditable(note,n.id,n);
          li.appendChild(note);
        }
        if(n.links && n.links.length){
          const linkDiv=document.createElement('div');
          linkDiv.className='ml-2 flex flex-wrap gap-2 text-sm';
          n.links.forEach(l=>{
            const a=document.createElement('a');
            a.textContent=l.title||l.target;
            a.href=l.target;
            a.dataset.link=JSON.stringify(l);
            a.className='inline-block px-2 py-1 bg-gray-100 rounded hover:bg-gray-200 text-gray-700';
            linkDiv.appendChild(a);
          });
          li.appendChild(linkDiv);
        }
      }
      ul.appendChild(li);
    });
    return ul;
  }
  opmlPreview.innerHTML='';
  const toc=document.createElement('div');
  toc.className='mb-4';
  const tocTitle=document.createElement('div');
  tocTitle.className='font-bold mb-2';
  tocTitle.textContent='Table of Contents';
  const tocList=document.createElement('ul');
  tocList.className='flex flex-wrap gap-4 text-sm';
  nodes.forEach((n,i)=>{
    if(!n._id) n._id=slug(n.t);
    const a=document.createElement('a');
    a.href='#'+n._id;
    a.textContent=n.t||('Section '+(i+1));
    a.className='inline-block px-2 py-1 bg-gray-100 rounded hover:bg-gray-200 text-gray-700';
    const li=document.createElement('li');
    li.appendChild(a);
    tocList.appendChild(li);
  });
  toc.appendChild(tocTitle);
  toc.appendChild(tocList);
  opmlPreview.appendChild(toc);
  opmlPreview.appendChild(walk(nodes,0));
  attachPreviewLinks();
}
function renderJsonPreview(nodes){ renderOpmlPreview(nodes); }
function attachPreviewLinks(){
  opmlPreview.querySelectorAll('a').forEach(a=>{
    a.addEventListener('click',e=>{
      e.preventDefault();
      const data=a.dataset.link;
      if(data){
        try{ followLink(JSON.parse(data)); return; }catch{}
      }
      const href=a.getAttribute('href')||'';
      if(/^https?:\/\//i.test(href)){
        window.open(href,'_blank');
      }else if(href.endsWith('/')){
        openDir(href.replace(/\/$/,''));
      }else{
        openFile(href, href.split('/').pop(),0,0);
      }
    });
  });
}

function toggleSection(id,btn){
  const el=document.getElementById(id);
  if(!el) return;
  el.classList.toggle('hidden');
  if(btn) btn.classList.toggle('rotate-180');
}

function modal(opts){
  const overlay=document.getElementById('modalOverlay');
  const {title='', body='', onOk=()=>{}, onCancel=()=>{}, okText='OK', cancelText='Cancel', showCancel=true, extra=null} = opts;
  document.getElementById('modalTitle').textContent=title;
  const bodyEl=document.getElementById('modalBody');
  if(typeof body==='string') bodyEl.innerHTML=body; else { bodyEl.innerHTML=''; bodyEl.appendChild(body); }
  const ok=document.getElementById('modalOk'); ok.textContent=okText;
  const cancel=document.getElementById('modalCancel'); cancel.textContent=cancelText; cancel.style.display=showCancel?'':'none';
  const extraBtn=document.getElementById('modalExtra');
  if(extra){
    extraBtn.textContent=extra.text || '';
    extraBtn.onclick=()=>{overlay.classList.add('hidden'); extra.onClick();};
    extraBtn.classList.remove('hidden');
  }else{
    extraBtn.classList.add('hidden');
    extraBtn.onclick=null;
  }
  ok.onclick=()=>{overlay.classList.add('hidden'); onOk();};
  cancel.onclick=()=>{overlay.classList.add('hidden'); onCancel();};
  overlay.classList.remove('hidden');
}
function modalPrompt(title,def,cb){
  const input=document.createElement('input');
  input.type='text'; input.className='w-full border rounded px-2 py-1'; input.value=def||'';
  modal({title, body:input, onOk:()=>cb(input.value), onCancel:()=>cb(null)});
  setTimeout(()=>input.focus(),0);
  input.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); document.getElementById('modalOk').click(); }});
}
function modalInfo(title,html){
  modal({title, body:html, showCancel:false});
}
function modalConfirm(title,msg,cb){
  modal({title, body:msg, onOk:()=>cb(true), onCancel:()=>cb(false)});
}
function closeModal(){ document.getElementById('modalOverlay').classList.add('hidden'); }

function copyItem(ev,rel){
  if(ev) ev.stopPropagation();
  clipboardPath=rel;
  if(pasteBtn) pasteBtn.classList.remove('hidden');
  openDir(currentDir);
}
async function pasteItem(){
  if(!clipboardPath) return;
  await pasteTo(currentDir);
}
async function pasteTo(dest){
  if(!clipboardPath) return;
  const name=clipboardPath.split('/').pop();
  const dst=dest?(dest+'/'+name):name;
  const r=await (await fetch('?api=copy_item',{method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},body:JSON.stringify({src:clipboardPath,dst:dst})})).json();
  if(!r.ok){modalInfo('Error',r.error||'Copy failed'); return;}
  clipboardPath=''; if(pasteBtn) pasteBtn.classList.add('hidden');
  openDir(currentDir);
}
let moveSrc='', moveDest='', moveCur='';
async function moveItem(ev,rel){
  moveSrc=rel; moveDest=''; moveCur='';
  const body=document.createElement('div');
  body.className='flex flex-col h-72';
  const filter=document.createElement('input');
  filter.type='text'; filter.placeholder='filter...';
  filter.className='border rounded px-2 py-1 mb-2';
  const list=document.createElement('ul');
  list.className='flex-1 overflow-auto';
  body.append(filter,list);
  filter.addEventListener('input',()=>{
    const q=filter.value.toLowerCase();
    Array.from(list.children).forEach(li=>{
      if(li.textContent==='..') return;
      li.style.display=li.textContent.toLowerCase().includes(q)?'':'none';
    });
  });
  function highlight(li){ Array.from(list.children).forEach(ch=>ch.classList.remove('bg-blue-100')); li.classList.add('bg-blue-100'); }
  async function load(){
    const r=await (await api('list',{path:moveCur})).json();
    if(!r.ok) return;
    list.innerHTML='';
    if(moveCur){
      const up=document.createElement('li');
      up.textContent='..';
      up.className='px-2 py-1 cursor-pointer hover:bg-gray-100';
      up.onclick=()=>{ moveCur=moveCur.split('/').slice(0,-1).join('/'); moveDest=moveCur; load(); };
      list.appendChild(up);
    }
    r.items.filter(i=>i.type==='dir').forEach(d=>{
      const li=document.createElement('li');
      li.textContent=d.name;
      li.className='px-2 py-1 cursor-pointer hover:bg-gray-100';
      li.onclick=()=>{ moveDest=d.rel; highlight(li); };
      li.ondblclick=()=>{ moveCur=d.rel; moveDest=d.rel; load(); };
      list.appendChild(li);
    });
  }
  await load();
  modal({title:'Move to...', body, onOk:async()=>{
    const dest=moveDest || moveCur;
    const name=moveSrc.split('/').pop();
    const dst=dest?dest+'/'+name:name;
    const r=await (await fetch(`?api=rename&`+new URLSearchParams({path:moveSrc}),{method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({to:dst})})).json();
    if(!r.ok){modalInfo('Error',r.error||'Move failed'); return;}
    openDir(currentDir);
  }});
}

function crumb(rel){
  const c=document.getElementById('crumb'); c.innerHTML='';
  const parts = rel? rel.split('/') : [];
  let acc='';
  const cls='px-2 py-1 bg-gray-100 rounded-md hover:bg-gray-200';
  parts.forEach((p,i)=>{
    acc+=(i?'/':'')+p;
    const a=document.createElement('a'); a.textContent=p; a.href='#'; a.className=cls;
    a.onclick=(e)=>{e.preventDefault(); openDir(acc);};
    c.appendChild(a);
  });
  document.getElementById('pathInput').value = rel || '';
}
async function init(){
  openDir('');
}
function ent(name,rel,isDir,size,mtime){
  const li=document.createElement('li');
  const row=document.createElement('div');
  row.className='flex items-center justify-between px-2 py-2 hover:bg-gray-100 rounded cursor-pointer';
  const left=document.createElement('div');
  left.className='flex items-center gap-2';
  left.innerHTML=(isDir?icons.folder:icons.file)+`<span>${name}</span>`;
  const wrap=document.createElement('div');
  wrap.className='relative';
  const btn=document.createElement('button');
  btn.className='p-1 text-gray-500 hover:text-gray-800';
  btn.innerHTML=icons.kebab;
  btn.onclick=e=>{e.stopPropagation(); toggleMenu(btn);};
  const menu=document.createElement('div');
  menu.className='itemMenu hidden absolute right-0 mt-1 w-32 bg-white border rounded shadow-md z-10 text-sm';
  const makeItem=(label,fn)=>{
    const a=document.createElement('a');
    a.href='#'; a.textContent=label; a.className='block px-4 py-2 hover:bg-gray-100';
    a.onclick=ev=>{ev.stopPropagation(); fn(); hideMenus();};
    menu.appendChild(a);
  };
  makeItem('Info',()=>showInfo(rel,name,size,mtime));
  makeItem('Download',()=>{isDir?downloadFolder(null,rel):downloadItem(null,rel,name);});
  makeItem('Rename',()=>renameItem(null,rel));
  makeItem('Delete',()=>deleteItem(null,rel));
  makeItem('Copy',()=>copyItem(null,rel));
  makeItem('Move',()=>moveItem(null,rel));
  if(!isDir && name.toLowerCase().endsWith('.opml'))
    makeItem('Clean & Standardize File',()=>{
      modalConfirm('Standardize','Clean & standardize this OPML file?',async ok=>{
        if(!ok) return;
        const r=await (await fetch(`?api=standardize_opml&path=`+encodeURIComponent(rel),{method:'POST',headers:{'X-CSRF':CSRF}})).json();
        if(!r.ok){ modalInfo('Error',r.error||'Standardize failed'); return; }
        modalInfo('Success','File standardized');
        await openDir(currentDir);
        if(currentFile===rel){ await openFile(rel,name,r.size||size,r.mtime||mtime); await loadTree(); }
      });
    });
  if(!isDir && name.toLowerCase().endsWith('.json'))
    makeItem('Clean & Standardize JSON',()=>{
      modalConfirm('Standardize','Clean & standardize this JSON file?',async ok=>{
        if(!ok) return;
        const r=await (await fetch(`?api=standardize_json&path=`+encodeURIComponent(rel),{method:'POST',headers:{'X-CSRF':CSRF}})).json();
        if(!r.ok){ modalInfo('Error',r.error||'Standardize failed'); return; }
        modalInfo('Success','File standardized');
        await openDir(currentDir);
        if(currentFile===rel){ await openFile(rel,name,r.size||size,r.mtime||mtime); await loadTree(); }
      });
    });
  if(isDir && clipboardPath) makeItem('Paste',()=>pasteTo(rel));
  wrap.appendChild(btn); wrap.appendChild(menu);
  row.appendChild(left); row.appendChild(wrap);
  li.appendChild(row);
  li.onclick=()=> isDir? openDir(rel) : openFile(rel,name,size,mtime);
  return li;
}
async function openDir(rel){
  currentDir = rel || ''; crumb(currentDir);
  document.getElementById('fileTitle').classList.add('hidden');
  document.getElementById('fileRenameBtn').classList.add('hidden');
  btns(false); infoBtn.disabled=true; currentFileInfo=null; document.getElementById('imgPreviewWrap').classList.add('hidden'); ta.classList.remove('hidden');
  const FL=document.getElementById('folderList'); FL.innerHTML='';
  const r=await (await api('list',{path:currentDir})).json(); if(!r.ok){modalInfo('Error',r.error||'list failed');return;}
  if(currentDir){
    const up=currentDir.split('/').slice(0,-1).join('/');
    const upName=up.split('/').pop() || '/';
    const li=document.createElement('li');
    li.className='px-2 py-2 bg-gray-100 hover:bg-gray-200 cursor-pointer rounded';
    li.textContent='↑ '+upName;
    li.onclick=()=>openDir(up);
    FL.appendChild(li);
  }
  r.items.filter(i=>i.type==='dir').sort((a,b)=>a.name.localeCompare(b.name)).forEach(d=>FL.appendChild(ent(d.name,d.rel,true,0,d.mtime)));
  const FI=document.getElementById('fileList'); FI.innerHTML='';
  r.items.filter(i=>i.type==='file').sort((a,b)=>{
    let cmp=0;
    if(fileSort.criterion==='name') cmp=a.name.localeCompare(b.name);
    else if(fileSort.criterion==='size') cmp=(a.size||0)-(b.size||0);
    else if(fileSort.criterion==='date') cmp=(a.mtime||0)-(b.mtime||0);
    return fileSort.direction==='asc'?cmp:-cmp;
  }).forEach(f=>FI.appendChild(ent(f.name,f.rel,false,f.size,f.mtime)));
}
function jump(){ const p=document.getElementById('pathInput').value.trim(); openDir(p); }
function fmtSize(b){ if(b<1024) return b+' B'; let u=['KB','MB','GB']; let i=-1; do{b/=1024;i++;}while(b>=1024&&i<2); return b.toFixed(1)+' '+u[i]; }
function showInfo(rel,name,size,mtime){
  const parts=rel.split('/');
  const file=parts.pop();
  let acc='';
  let crumbs='<a href="#" onclick="closeModal(); openDir(\'\'); return false;">root</a>';
  parts.forEach((p,i)=>{ acc+=(i?'/':'')+p; const esc=acc.replace(/'/g,"\\'"); crumbs += ' / <a href="#" onclick="closeModal(); openDir(\''+esc+'\'); return false;">'+p+'</a>'; });
  crumbs += ' / '+file;
  const html=`<div class="space-y-1"><div>${crumbs}</div><div>Name: ${name}</div><div>Size: ${fmtSize(size)}</div><div>Modified: ${new Date(mtime*1000).toLocaleString()}</div></div>`;
  modalInfo('Info', html);
}
function showCurrentInfo(){ if(currentFileInfo) showInfo(currentFile,currentFileInfo.name,currentFileInfo.size,currentFileInfo.mtime); }
async function openFile(rel,name,size,mtime){
  currentFile=rel; currentOutlinePath=''; selectedId=null; currentFileInfo={name,size,mtime};
  document.getElementById('nodeTitleRow').classList.add('hidden');
  const titleInput=document.getElementById('fileTitle');
  const renameBtn=document.getElementById('fileRenameBtn');
  const imgWrap=document.getElementById('imgPreviewWrap');
  const img=document.getElementById('imgPreview');
  titleInput.classList.remove('hidden');
  renameBtn.classList.remove('hidden');
  titleInput.value=name;
  infoBtn.disabled=false;
  const ext=name.toLowerCase().split('.').pop();
  const isImg=['png','jpg','jpeg','gif','webp','svg'].includes(ext);
  if(isImg){
    ta.classList.add('hidden');
    imgWrap.classList.remove('hidden');
    img.src='?api=get_file&path='+encodeURIComponent(rel);
    ta.value=''; ta.disabled=true;
    btns(false); delBtn.disabled=false; downloadBtn.disabled=false;
    document.getElementById('structTreeBtn').disabled=true;
    contentTabs.classList.add('hidden');
    opmlPreview.classList.add('hidden');
    hideTree();
    return;
  }else{
    imgWrap.classList.add('hidden'); img.src=''; ta.classList.remove('hidden');
  }
  const r=await (await api('read',{path:rel})).json();
  if (!r.ok) { ta.value=''; ta.disabled=true; btns(false); titleInput.classList.add('hidden'); renameBtn.classList.add('hidden'); infoBtn.disabled=true; contentTabs.classList.add('hidden'); opmlPreview.classList.add('hidden'); return; }
  ta.value=r.content; ta.disabled=false; btns(true);
  const extLower=ext.toLowerCase();
  const isStruct=['opml','xml','json'].includes(extLower);
  document.getElementById('structTreeBtn').disabled = !isStruct;
  hideTree();
  if(isStruct){
    contentTabs.classList.remove('hidden');
    opmlPreview.classList.remove('hidden');
    contentPreview.classList.add('hidden');
    showCodeView();
    try{
      const isJson=extLower==='json';
      if(isJson){
        try{ currentJsonDoc=JSON.parse(r.content); currentJsonRoot=currentJsonDoc.root||[]; }
        catch{ currentJsonDoc={schemaVersion:'1.0.0',id:'',metadata:{},root:[]}; currentJsonRoot=[]; }
      } else { currentJsonDoc=null; }
      const endpoint=isJson?'json_tree':'opml_tree';
      const p=await (await api(endpoint,{file:rel})).json();
      if(p.ok){
        if(isJson) currentJsonRoot=p.root||currentJsonRoot;
        const tree=isJson? cjsf_to_ark(currentJsonRoot) : (p.tree||[]);
        renderOpmlPreview(tree);
      }else opmlPreview.textContent=p.error||'Structure parse error.';
    }catch{ opmlPreview.textContent='Structure load error.'; }
  }else{
    contentTabs.classList.add('hidden');
    opmlPreview.classList.add('hidden');
    contentPreview.classList.add('hidden');
  }
}
function btns(on){ saveBtn.disabled=!on; delBtn.disabled=!on; downloadBtn.disabled=!on; }
async function save(){
  if(!currentFile) return;
  const content=document.getElementById('ta').value;
  if(currentOutlinePath){
    const r=await (await fetch(`?api=set_note&`+new URLSearchParams({file:currentFile,path:currentOutlinePath}),{
      method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({note:content})
    })).json();
    if(!r.ok){modalInfo('Error',r.error||'Save failed');return;}
    loadTree(getExpanded());
  }else{
    const body=JSON.stringify({content});
    const r=await (await fetch(`?api=write&`+new URLSearchParams({path:currentFile}),{
      method:'POST',headers:{'X-CSRF':CSRF},body
    })).json();
    if(!r.ok){modalInfo('Error',r.error||'Save failed');return;}
  }
}
async function del(){
  if(!currentFile) return;
  modalConfirm('Delete','Delete this file?',async ok=>{
    if(!ok) return;
    const r=await (await fetch(`?api=delete&`+new URLSearchParams({path:currentFile}),{method:'POST',headers:{'X-CSRF':CSRF}})).json();
    if(!r.ok){modalInfo('Error',r.error||'Delete failed');return;}
    ta.value=''; ta.disabled=true; btns(false); openDir(currentDir);
  });
}
async function renameCurrent(){
  if(!currentFile) return;
  const name=document.getElementById('fileTitle').value.trim();
  if(!name) return;
  const dir=currentFile.split('/').slice(0,-1).join('/');
  const target=(dir? dir+'/' : '')+name.replace(/^\/+/,'');
  const r=await (await fetch(`?api=rename&`+new URLSearchParams({path:currentFile}),{
    method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({to:target})
  })).json();
  if(!r.ok){modalInfo('Error',r.error||'rename failed');return;}
  currentFile=target;
  await openDir(currentDir);
  openFile(target,name,0,0);
}
function downloadFile(){
  if(!currentFile) return;
  downloadItem(null,currentFile,document.getElementById('fileTitle').value || 'download');
}
async function mkdirPrompt(){
  modalPrompt('New folder name','',async name=>{
    if(!name) return;
    const r=await (await fetch(`?api=mkdir&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({name})})).json();
    if(!r.ok){modalInfo('Error',r.error||'mkdir failed');return;}
    openDir(currentDir);
  });
}
async function newFilePrompt(){
  const wrap=document.createElement('div');
  wrap.className='space-y-2';
  const input=document.createElement('input');
  input.type='text'; input.className='w-full border rounded px-2 py-1';
  const select=document.createElement('select');
  select.className='w-full border rounded px-2 py-1';
  ['.json','.txt','.md','.opml','.html'].forEach(ext=>{ const o=document.createElement('option'); o.value=ext; o.textContent=ext; select.appendChild(o);});
  select.value='.json';
  wrap.append(input,select);
  modal({title:'New file name', body:wrap, onOk:async()=>{
    let name=input.value.trim();
    const ext=select.value;
    if(!name){
      const list=await (await api('list',{path:currentDir})).json();
      let base=currentDir?currentDir.split('/').join('-')+'-':'New-';
      let candidate=base+ext;
      let n=1;
      const names=(list.items||[]).map(i=>i.name);
      while(names.includes(candidate)){ candidate=base+n+ext; n++; }
      name=candidate;
    }else if(!name.includes('.')){
      name+=ext;
    }
    const r=await (await fetch(`?api=newfile&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({name})})).json();
    if(!r.ok){modalInfo('Error',r.error||'newfile failed');return;}
    openDir(currentDir);
  }});
  setTimeout(()=>input.focus(),0);
  input.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); document.getElementById('modalOk').click(); }});
}
async function uploadFile(inp){
  if(!inp.files.length) return; const fd=new FormData(); fd.append('file',inp.files[0]);
  const r=await (await fetch(`?api=upload&`+new URLSearchParams({path:currentDir}),{method:'POST',headers:{'X-CSRF':CSRF},body:fd})).json();
  if(!r.ok){modalInfo('Error',r.error||'upload failed');return;} openDir(currentDir);
}

async function uploadFolder(inp){
  if(!inp.files.length) return;
  for(const f of inp.files){
    const fd=new FormData(); fd.append('file',f);
    const relPath=f.webkitRelativePath||f.name;
    const subdir=relPath.split('/').slice(0,-1).join('/');
    const target=currentDir+(subdir?`/${subdir}`:'');
    const r=await (await fetch(`?api=upload&`+new URLSearchParams({path:target}),{method:'POST',headers:{'X-CSRF':CSRF},body:fd})).json();
    if(!r.ok){modalInfo('Error',r.error||'upload failed');return;}
  }
  openDir(currentDir);
}

function downloadFolder(ev,rel){
  if(ev) ev.stopPropagation();
  const a=document.createElement('a');
  a.href='?api=download_folder&'+new URLSearchParams({path:rel});
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

function downloadItem(ev,rel,name){
  if(ev) ev.stopPropagation();
  const a=document.createElement('a');
  a.href='?api=get_file&'+new URLSearchParams({path:rel});
  a.download=name;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

async function renameItem(ev,rel){
  if(ev) ev.stopPropagation();
  const oldName = rel.split('/').pop();
  modalPrompt('Rename',oldName,async name=>{
    if(!name || name===oldName) return;
    const dir = rel.split('/').slice(0,-1).join('/');
    const target = (dir? dir+'/' : '') + name.replace(/^\\+/,'');
    const r=await (await fetch(`?api=rename&`+new URLSearchParams({path:rel}),{
      method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify({to:target})
    })).json();
    if(!r.ok){modalInfo('Error',r.error||'rename failed');return;}
    openDir(currentDir);
  });
}

async function deleteItem(ev,rel){
  if(ev) ev.stopPropagation();
  modalConfirm('Delete','Delete this item?',async ok=>{
    if(!ok) return;
    const r=await (await fetch(`?api=delete&`+new URLSearchParams({path:rel}),{method:'POST',headers:{'X-CSRF':CSRF}})).json();
    if(!r.ok){modalInfo('Error',r.error||'delete failed');return;}
    if(currentFile===rel){ document.getElementById('ta').value=''; document.getElementById('ta').disabled=true; btns(false); currentFile=''; }
    openDir(currentDir);
  });
}

function getExpanded(){
  const set=new Set();
  treeWrap.querySelectorAll('div[data-id]').forEach(row=>{
    const caret=row.querySelector('.caret');
    if(caret && caret.textContent==='▾') set.add(row.dataset.id);
  });
  return set;
}
function restoreExpanded(set){
  treeWrap.querySelectorAll('div[data-id]').forEach(row=>{
    if(set.has(row.dataset.id)){
      const caret=row.querySelector('.caret');
      if(caret && caret.textContent==='▸') toggleChildren(row.dataset.id);
    }
  });
}
function addChild(id){
  modalPrompt('Enter title for new node','',async t=>{
    if(t===null) return;
    if(currentFile.toLowerCase().endsWith('.json')){
      const parent=findJsonNode(currentJsonRoot,id);
      if(!parent) return;
      const now=new Date().toISOString();
      const newItem={
        id:crypto.randomUUID(),
        type:'note',
        title:t,
        content:'',
        created:now,
        modified:now,
        metadata:{title:t},
        links:[],
        children:[]
      };
      parent.children=parent.children||[];
      parent.children.push(newItem);
      const expanded=getExpanded();
      expanded.add(id);
      await saveCurrentJsonStructure();
      renderTree(cjsf_to_ark(currentJsonRoot));
      restoreExpanded(expanded);
      selectNode(newItem.id,t,'',[]);
    }else{
      nodeOp('add_child',{title:t},id);
    }
  });
}
function addSibling(id){
  modalPrompt('Enter title for new node','',async t=>{
    if(t===null) return;
    if(currentFile.toLowerCase().endsWith('.json')){
      const info=findJsonParent(currentJsonRoot,id);
      if(!info) return;
      const {parent,index,array}=info;
      const now=new Date().toISOString();
      const newItem={
        id:crypto.randomUUID(),
        type:'note',
        title:t,
        content:'',
        created:now,
        modified:now,
        metadata:{title:t},
        links:[],
        children:[]
      };
      const targetArray=parent?parent.children:currentJsonRoot;
      targetArray.splice(index+1,0,newItem);
      const expanded=getExpanded();
      if(parent) expanded.add(parent.id);
      await saveCurrentJsonStructure();
      renderTree(cjsf_to_ark(currentJsonRoot));
      restoreExpanded(expanded);
      selectNode(newItem.id,t,'',[]);
    }else{
      nodeOp('add_sibling',{title:t},id);
    }
  });
}
function deleteNode(id){ modalConfirm('Delete node','Delete this node?',ok=>{ if(ok) nodeOp('delete',{},id); }); }
let renameTargetId=null;
function renameNode(id,oldTitle){
  renameTargetId=id;
  modalPrompt('Rename node',oldTitle,async t=>{
    if(t===null) return;
    if(currentFile.toLowerCase().endsWith('.json')){
      const node=findJsonNode(currentJsonRoot,renameTargetId);
      if(node){
        node.title=t;
        node.metadata=node.metadata||{};
        node.metadata.title=t;
        node.modified=new Date().toISOString();
        await saveCurrentJsonStructure();
        const expanded=getExpanded();
        renderTree(cjsf_to_ark(currentJsonRoot));
        restoreExpanded(expanded);
        selectNode(node.id,node.metadata?.title||node.title,node.content,node.links||[]);
      }
    }else{
      nodeOp('set_title',{title:t},renameTargetId);
    }
  });
}
function hideTree(){
  treeWrap.classList.add('hidden');
  fileList.classList.remove('hidden');
  selectedId=null;
  currentOutlinePath='';
  if(sortBtn) sortBtn.classList.remove('hidden');
}
function showTree(){
  if(!currentFile) return;
  treeWrap.classList.remove('hidden');
  fileList.classList.add('hidden');
  if(sortBtn) sortBtn.classList.add('hidden');
  loadTree();
}
if(sortBtn && sortMenu){
  sortBtn.addEventListener('click',e=>{
    e.stopPropagation();
    sortMenu.classList.toggle('hidden');
  });
  document.addEventListener('click',e=>{
    if(!sortMenu.contains(e.target) && e.target!==sortBtn) sortMenu.classList.add('hidden');
  });
  sortMenu.querySelectorAll('button').forEach(btn=>{
    btn.addEventListener('click',()=>{
      const criterion=btn.dataset.sort;
      if(fileSort.criterion===criterion) fileSort.direction=fileSort.direction==='asc'?'desc':'asc';
      else { fileSort.criterion=criterion; fileSort.direction='asc'; }
      sortMenu.classList.add('hidden');
      openDir(currentDir);
    });
  });
}
function renderTree(nodes){
  nodeMap={}; arkMap={};
  const wrap=document.createElement('div');
  wrap.className='text-base leading-relaxed';
  function walk(arr,level,parent){
    for(const n of arr){
      const row=document.createElement('div');
      row.className='flex items-center gap-2 px-2 py-2 hover:bg-gray-100 rounded cursor-pointer select-none';
      const has=n.children && n.children.length;
      if(has) row.classList.add('bg-gray-50');
      row.style.marginLeft=(level*20)+'px';
      row.dataset.id=n.id;
      row.dataset.parent=parent||'';
      if(level>0) row.classList.add('hidden');
      const caret=document.createElement('span');
      caret.textContent=has?'▸':'•';
      caret.className=has?'caret text-lg w-5 select-none':'text-gray-400 w-5';
      const title=document.createElement('span');
      title.textContent=n.t;
      title.className='title flex-1';
      row.append(caret,title);
      nodeMap[n.id]=n;
      if(n.arkid) arkMap[n.arkid]=n.id;
      const actions=document.createElement('div');
      actions.className='ml-auto flex items-center gap-1';
      const addChildBtn=document.createElement('button');
      addChildBtn.innerHTML=icons.addSub;
      addChildBtn.className='text-gray-500 hover:text-blue-600';
      addChildBtn.title='Add Sub';
      addChildBtn.onclick=(e)=>{e.stopPropagation(); addChild(n.id);};
      const addSiblingBtn=document.createElement('button');
      addSiblingBtn.innerHTML=icons.addSame;
      addSiblingBtn.className='text-gray-500 hover:text-blue-600';
      addSiblingBtn.title='Add Same';
      addSiblingBtn.onclick=(e)=>{e.stopPropagation(); addSibling(n.id);};
      const addLinkBtn=document.createElement('button');
      addLinkBtn.innerHTML=icons.link;
      addLinkBtn.className='text-gray-500 hover:text-blue-600';
      addLinkBtn.title='Add Link';
      addLinkBtn.onclick=(e)=>{e.stopPropagation(); openLinkModal(n.id);};
      const editBtn=document.createElement('button');
      editBtn.innerHTML=icons.edit;
      editBtn.className='text-gray-500 hover:text-blue-600';
      editBtn.title='Rename';
      editBtn.onclick=(e)=>{e.stopPropagation(); renameNode(n.id,n.t);};
      const delBtn=document.createElement('button');
      delBtn.innerHTML=icons.trash;
      delBtn.className='text-gray-500 hover:text-red-600';
      delBtn.title='Delete';
      delBtn.onclick=(e)=>{e.stopPropagation(); deleteNode(n.id);};
      actions.append(addChildBtn,addSiblingBtn,addLinkBtn,editBtn,delBtn);
      row.append(actions);
      if(n.links && n.links.length){
        const direct=document.createElement('div');
        direct.className='flex items-center gap-1 ml-2';
        n.links.forEach((l,i)=>{
          const btn=document.createElement('button');
          btn.innerHTML=icons.link;
          if(i>0){
            const sup=document.createElement('sup');
            sup.className='text-xs';
            sup.textContent=i+1;
            btn.appendChild(sup);
          }
          btn.className='text-gray-500 hover:text-blue-600';
          btn.title=l.title || l.target;
          btn.onclick=(e)=>{e.stopPropagation(); followLink(l);};
          direct.appendChild(btn);
        });
        row.append(direct);
      }
      row.addEventListener('click',()=>selectNode(n.id,n.t,n.note,n.links||[]));
      if(has){
        row.addEventListener('dblclick',e=>{e.stopPropagation(); toggleChildren(n.id);});
      }
      wrap.appendChild(row);
      if(has) walk(n.children,level+1,n.id);
    }
  }
  walk(nodes,0,'');
  treeWrap.replaceChildren(wrap);
}

function toggleChildren(id){
  const row=treeWrap.querySelector(`div[data-id="${id}"]`);
  const caret=row.querySelector('.caret');
  const expand=caret.textContent==='▸';
  caret.textContent=expand?'▾':'▸';
  if(expand) showChildren(id); else hideChildren(id);
}
function showChildren(id){
  treeWrap.querySelectorAll(`div[data-parent="${id}"]`).forEach(ch=>{
    ch.classList.remove('hidden');
    const caret=ch.querySelector('.caret');
    if(caret && caret.textContent==='▾') showChildren(ch.dataset.id);
  });
}
function hideChildren(id){
  treeWrap.querySelectorAll(`div[data-parent="${id}"]`).forEach(ch=>{
    ch.classList.add('hidden');
    hideChildren(ch.dataset.id);
  });
}
async function loadTree(expanded=null){
  try{
    const isJson=currentFile.toLowerCase().endsWith('.json');
    const endpoint=isJson?'json_tree':'opml_tree';
    const r=await (await api(endpoint,{file:currentFile})).json();
    if(!r.ok){ treeWrap.textContent=r.error||'Structure parse error.'; return; }
    if(isJson){
      currentJsonRoot=r.root||[];
      originalRootOrder=currentJsonRoot.slice();
    }
    const tree=isJson? cjsf_to_ark(currentJsonRoot) : (r.tree||[]);
    renderTree(tree);
    if(expanded) restoreExpanded(expanded);
  }catch(e){ treeWrap.textContent='Structure load error.'; }
}
function selectNode(id,title,note,links=[]){
  selectedId=id;
  currentLinks=links||[];
  const titleRow=document.getElementById('nodeTitleRow');
  const titleInput=document.getElementById('nodeTitle');
  titleInput.value=title||'';
  titleRow.classList.remove('hidden');
  if(contentTabs) contentTabs.classList.remove('hidden');
  const node=findJsonNode(currentJsonRoot,id);
  if(node && contentPreview){
    contentPreview.innerHTML=node.content||'';
  }
  toggleContentMode('preview');
  renderLinks();
}
async function nodeOp(op,extra={},id=selectedId){
  if(!currentFile || id===null) return;
  const isJson=currentFile.toLowerCase().endsWith('.json');
  const endpoint=isJson?'json_node':'opml_node';
  const expanded=getExpanded();
  if(op==='add_child') expanded.add(id);
  const body=JSON.stringify({file:currentFile,op,id,...extra});
  const r=await (await fetch(`?api=${endpoint}`,{method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},body})).json();
  if(!r.ok){ modalInfo('Error',r.error||'node op failed'); return; }
  selectedId=r.id ?? id;
  await loadTree(expanded);
  if(isJson){
    currentJsonDoc.root=currentJsonRoot;
    renderOpmlPreview(cjsf_to_ark(currentJsonRoot));
    await saveCurrentJsonStructure();
  }
  const sel=treeWrap.querySelector(`div[data-id="${selectedId}"]`);
  if(sel){ sel.scrollIntoView({block:'nearest'}); }
  const n=nodeMap[selectedId];
  if(n){ selectNode(selectedId,n.t,n.note,n.links||[]); }
}

function renderLinks(){
  const wrap=document.getElementById('linkList');
  if(!currentLinks || currentLinks.length===0){ wrap.classList.add('hidden'); wrap.innerHTML=''; return; }
  wrap.innerHTML='';
  currentLinks.forEach(l=>{
    const item=document.createElement('div');
    item.className='flex items-center bg-gray-100 border rounded-md px-2 py-1 mr-2 mb-2 cursor-pointer';
    item.onclick=()=>followLink(l);
    const title=document.createElement('span');
    title.textContent=l.title||l.target;
    item.appendChild(title);
    const edit=document.createElement('button');
    edit.innerHTML=icons.edit;
    edit.className='ml-2 text-gray-500 hover:text-blue-600';
    edit.onclick=(e)=>{e.stopPropagation(); openLinkModal(selectedId,l);};
    item.appendChild(edit);
    wrap.appendChild(item);
  });
  wrap.classList.remove('hidden');
}

function gotoNodeByPath(path){
  const parts=path.split('/');
  let acc='';
  for(const p of parts){
    acc=acc?acc+'/'+p:p;
    const row=treeWrap.querySelector(`div[data-id="${acc}"]`);
    if(row){
      const caret=row.querySelector('.caret');
      if(caret && caret.textContent==='▸') toggleChildren(acc);
    }
  }
  const row=treeWrap.querySelector(`div[data-id="${path}"]`);
  if(row){ row.click(); row.scrollIntoView({block:'nearest'}); }
}

function followLink(l){
  switch(l.type){
    case 'relation':
    case 'node':{
      const row=treeWrap.querySelector(`div[data-id="${l.target}"]`);
      if(row){
        let parent=row.dataset.parent;
        while(parent){
          const pr=treeWrap.querySelector(`div[data-id="${parent}"]`);
          if(pr){
            const caret=pr.querySelector('.caret');
            if(caret && caret.textContent==='▸') toggleChildren(parent);
            parent=pr.dataset.parent;
          }else break;
        }
        row.click();
        row.scrollIntoView({block:'nearest'});
      }
      break; }
    case 'folder':
      openDir(l.target);
      break;
    case 'file':
      openFile(l.target);
      break;
    case 'url':{
      let url=l.target||'';
      if(!/^https?:\/\//i.test(url)) url='https://'+url;
      window.open(url,'_blank');
      break; }
  }
}

async function pickPath(cb){
  let cur=''; let dest='';
  const body=document.createElement('div'); body.className='h-64 overflow-auto border rounded';
  const list=document.createElement('ul'); body.appendChild(list);
  function highlight(li){ list.querySelectorAll('li').forEach(x=>x.classList.remove('bg-blue-100')); li.classList.add('bg-blue-100'); }
  async function load(){
    const r=await (await api('list',{path:cur})).json();
    list.innerHTML='';
    if(cur){ const up=document.createElement('li'); up.textContent='..'; up.className='px-2 py-1 cursor-pointer hover:bg-gray-100'; up.onclick=()=>{cur=cur.split('/').slice(0,-1).join('/'); dest=cur; load();}; list.appendChild(up); }
    r.items.sort((a,b)=> a.type===b.type? a.name.localeCompare(b.name) : (a.type==='dir'?-1:1));
    r.items.forEach(d=>{
      const li=document.createElement('li'); li.textContent=d.name; li.className='px-2 py-1 cursor-pointer hover:bg-gray-100';
      li.onclick=()=>{ dest=d.rel; highlight(li); };
      if(d.type==='dir') li.ondblclick=()=>{ cur=d.rel; dest=cur; load(); };
      list.appendChild(li);
    });
  }
  await load();
  modal({title:'Select path', body, onOk:()=>cb(dest)});
}

async function openLinkModal(id, existing=null){
  const wrap=document.createElement('div');
  const typeSel=document.createElement('select');
  typeSel.className='w-full border rounded mb-2 px-2 py-1';
  ['node','folder','file','url'].forEach(t=>{ const o=document.createElement('option'); o.value=t; o.textContent=t.charAt(0).toUpperCase()+t.slice(1); typeSel.appendChild(o); });
  const targetDiv=document.createElement('div'); targetDiv.className='mb-2';
  const titleInput=document.createElement('input'); titleInput.className='w-full border rounded px-2 py-1'; titleInput.placeholder='Title';
  wrap.append(typeSel,targetDiv,titleInput);
  let targetInput=null; const editing=!!existing;
  if(editing){ typeSel.value=existing.type==='relation'?'node':existing.type; }
  async function refresh(){
    targetDiv.innerHTML='';
    const type=typeSel.value;
    if(type==='node'){
      const sel=document.createElement('select'); sel.className='w-full border rounded px-2 py-1';
      if(currentFile.toLowerCase().endsWith('.json')){
        getAllJsonNodes().forEach(n=>{ const o=document.createElement('option'); o.value=n.id; o.textContent=n.title; sel.appendChild(o); });
      }else{
        const r=await (await api('get_all_nodes',{file:currentFile})).json();
        if(r.ok){ r.nodes.forEach(n=>{ const o=document.createElement('option'); o.value=n.arkid; o.textContent=n.title; sel.appendChild(o); }); }
      }
      sel.onchange=()=>{ titleInput.value=sel.options[sel.selectedIndex]?.textContent || ''; };
      targetDiv.appendChild(sel); targetInput=sel;
      if(editing){ sel.value=existing.target; }
      if(sel.options.length && !titleInput.value) titleInput.value=sel.options[sel.selectedIndex].textContent;
    }else if(type==='folder' || type==='file'){
      const inp=document.createElement('input'); inp.className='w-full border rounded px-2 py-1 mb-2'; inp.readOnly=true;
      const btn=document.createElement('button'); btn.className='px-3 py-1 border rounded bg-gray-100 hover:bg-gray-200'; btn.textContent='Choose...';
      btn.onclick=()=>{ pickPath(p=>{ if(p){ inp.value=p; if(!titleInput.value) titleInput.value=p.split('/').pop(); }}); };
      targetDiv.append(inp,btn); targetInput=inp;
      if(editing){ inp.value=existing.target; }
    }else if(type==='url'){
      const inp=document.createElement('input'); inp.className='w-full border rounded px-2 py-1'; inp.placeholder='https://';
      inp.addEventListener('input',()=>{ if(!titleInput.value) titleInput.value=inp.value; });
      targetDiv.appendChild(inp); targetInput=inp;
      if(editing){ inp.value=existing.target; }
    }
  }
  typeSel.onchange=refresh; await refresh();
  if(editing && existing.title) titleInput.value=existing.title;
  modal({title: editing?'Edit Link':'Add Link', body:wrap, okText:editing?'Save Link':'Add Link', extra: editing?{text:'Delete Link', onClick:async()=>{ if(currentFile.toLowerCase().endsWith('.json')){ const node=findJsonNode(currentJsonRoot,id); if(node){ node.links=(node.links||[]).filter(l=>l.id!==existing.id); node.modified=new Date().toISOString(); await saveCurrentJsonStructure(); currentLinks=(node.links||[]).map(l=>({id:l.id||'',type:l.type||'',title:l.metadata?.title||'',target:l.target||'',direction:l.metadata?.direction||null})); const expanded=getExpanded(); renderTree(cjsf_to_ark(currentJsonRoot)); restoreExpanded(expanded); selectNode(id,node.metadata?.title||node.title,node.content,currentLinks); }} else { await nodeOp('delete_link',{target:existing.target},id); } }}:null, onOk:async()=>{
    const selected=typeSel.value;
    const type=selected==='node'?'relation':selected;
    const target=targetInput ? targetInput.value.trim() : '';
    if(!target){ modalInfo('Error','Target required'); return; }
    let title=titleInput.value.trim();
    if(!title){
      if(selected==='node' && targetInput.options) title=targetInput.options[targetInput.selectedIndex]?.textContent||'';
      else if(type==='url') title=target;
      else title=target.split('/').pop();
    }
    if(currentFile.toLowerCase().endsWith('.json')){
      const node=findJsonNode(currentJsonRoot,id);
      if(!node) return;
      const now=new Date().toISOString();
      const link={id:existing?.id || crypto.randomUUID(),type,target,metadata:{title,direction: existing?.direction || 'one-way'}};
      node.links=node.links||[];
      if(editing){ node.links=node.links.filter(l=>l.id!==existing.id); }
      node.links.push(link);
      node.modified=now;
      await saveCurrentJsonStructure();
      currentLinks=(node.links||[]).map(l=>({id:l.id||'',type:l.type||'',title:l.metadata?.title||'',target:l.target||'',direction:l.metadata?.direction||null}));
      const expanded=getExpanded();
      renderTree(cjsf_to_ark(currentJsonRoot));
      restoreExpanded(expanded);
      selectNode(id,node.metadata?.title||node.title,node.content,currentLinks);
    }else{
      const link={title,type,target,direction: existing?.direction || 'one-way'};
      if(editing) await nodeOp('delete_link',{target:existing.target},id);
      await nodeOp('add_link',{link},id);
    }
  }});
}
document.getElementById('fileRenameBtn').addEventListener('click', renameCurrent);
document.getElementById('fileTitle').addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); renameCurrent(); }});
document.getElementById('titleSaveBtn').addEventListener('click', saveTitle);
const nodeTitleInput=document.getElementById('nodeTitle');
nodeTitleInput.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); saveTitle(); }});
nodeTitleInput.addEventListener('input',()=>{ clearTimeout(saveTitleTimer); saveTitleTimer=setTimeout(saveTitle,500); });
nodeTitleInput.addEventListener('blur',saveTitle);
async function saveTitle(){
  if(selectedId===null) return;
  const title=nodeTitleInput.value.trim();
  await nodeOp('set_title',{title});
}
init();
</script>
</body>
</html>
