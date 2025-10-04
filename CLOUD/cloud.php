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
    $title=$node['title']??($node['metadata']['title']??($node['content']??($node['note']??'')));
    $out=['t'=>$title, 'id'=>$node['id']??'', 'arkid'=>$node['id']??'', 'children'=>[]];
    $note='';
    if(array_key_exists('note',$node)) $note=$node['note'];
    elseif(array_key_exists('content',$node) && (isset($node['title']) || isset($node['metadata']['title']))) $note=$node['content'];
    if($note!=='') $out['note']=$note;
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

function json_get_root(&$doc,&$key){
  if(isset($doc['root']) && is_array($doc['root'])){ $key='root'; return $doc['root']; }
  if(isset($doc['items']) && is_array($doc['items'])){ $key='items'; return $doc['items']; }
  if(is_array($doc)){ $key=null; return $doc; }
  bad('Invalid document',422);
}

function json_get_title($node){
  return $node['title'] ?? ($node['metadata']['title'] ?? ($node['content'] ?? ($node['note'] ?? '')));
}
function json_set_title(&$node,$title){
  if(array_key_exists('title',$node)) $node['title']=$title;
  elseif(isset($node['metadata']) && is_array($node['metadata']) && array_key_exists('title',$node['metadata'])) $node['metadata']['title']=$title;
  elseif(array_key_exists('content',$node) && !array_key_exists('title',$node)) $node['content']=$title;
  else $node['title']=$title;
}
function json_get_note($node){
  if(array_key_exists('note',$node)) return $node['note'];
  if(array_key_exists('content',$node) && (array_key_exists('title',$node) || (isset($node['metadata']['title'])))) return $node['content'];
  return '';
}
function json_set_note(&$node,$note){
  if(array_key_exists('note',$node)) $node['note']=$note;
  elseif(array_key_exists('content',$node) && (array_key_exists('title',$node) || (isset($node['metadata']['title'])))) $node['content']=$note;
  else $node['content']=$note;
}
function json_new_node_like($ref,$title,$now){
  $n=['id'=>uuidv4(),'children'=>[]];
  if(isset($ref['type'])) $n['type']=$ref['type'];
  if(isset($ref['title'])) $n['title']=$title;
  if(isset($ref['metadata']) && is_array($ref['metadata'])) $n['metadata']=['title'=>$title];
  if(isset($ref['note'])) $n['note']='';
  if(isset($ref['content'])){
    if(isset($ref['title']) || (isset($ref['metadata']['title']))) $n['content']='';
    else $n['content']=$title;
  }
  if(isset($ref['links'])) $n['links']=[];
  foreach(['icon','color','tileKind'] as $field){
    if(isset($ref[$field])) $n[$field]=$ref[$field];
  }
  if(isset($ref['created'])) $n['created']=$now;
  if(isset($ref['modified'])) $n['modified']=$now;
  return $n;
}

function render_login_ui($title,$err=''){
  $safeTitle=htmlspecialchars($title, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
  $errMsg=trim((string)$err);
  $errHtml=$errMsg!==''?'<div class="text-red-500 text-sm">'.htmlspecialchars($errMsg, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</div>':'';
  echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle} — Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .mobile-view li > div{padding:1rem;font-size:1.125rem;}
    .mobile-view svg{width:1.5rem;height:1.5rem;}
  </style>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center">
  <div class="bg-white p-6 rounded shadow w-80">
    <h1 class="text-xl font-semibold mb-4 text-gray-800">{$safeTitle}</h1>
    <form method="post" class="space-y-4">
      <input name="u" placeholder="user" autofocus class="w-full border rounded px-3 py-2">
      <input name="p" type="password" placeholder="password" class="w-full border rounded px-3 py-2">
      <button name="do_login" value="1" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-500">Sign in</button>
      {$errHtml}
      <p class="text-xs text-gray-500">Set env vars WEBEDITOR_USER / WEBEDITOR_PASS for stronger creds.</p>
    </form>
  </div>
</body>
</html>
HTML;
  exit;
}

/* ===== Door helpers (new builder) ===== */
// door.json storage (seed/load/save) happens here so Door + classic stay in sync.
function door_path(){
  global $ROOT;
  if(!$ROOT) return null;
  $base=rtrim($ROOT,'/');
  if($base==='') return null;
  return $base.'/DATA/door/door.json';
}

function door_default_root_node($now=null){
  $now=$now ?? gmdate('c');
  return [
    'id'=>'root',
    'title'=>'Atrium',
    'note'=>'Root room',
    'children'=>[],
    'links'=>[],
    'created'=>$now,
    'modified'=>$now
  ];
}

function door_default_document(){
  $now=gmdate('c');
  return [
    'schemaVersion'=>'1.1',
    'metadata'=>['title'=>'Atrium'],
    'root'=>[door_default_root_node($now)]
  ];
}

function door_seed_if_needed(){
  $path=door_path();
  if(!$path) return ['ok'=>false,'error'=>'Door storage unavailable'];
  $dir=dirname($path);
  if(!is_dir($dir) && !@mkdir($dir,0775,true)){
    return ['ok'=>false,'error'=>'Unable to create DATA/door directory'];
  }
  if(!is_file($path) || filesize($path)===0){
    $doc=door_default_document();
    $json=json_encode($doc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false) return ['ok'=>false,'error'=>'Unable to seed door.json'];
    if(@file_put_contents($path,$json)===false){
      return ['ok'=>false,'error'=>'Unable to write initial door.json'];
    }
  }
  return ['ok'=>true,'path'=>$path];
}

function door_load_doc(){
  $seed=door_seed_if_needed();
  if(!$seed['ok']) return $seed;
  $path=$seed['path'];
  $raw=@file_get_contents($path);
  if($raw===false) return ['ok'=>false,'error'=>'Unable to read door.json'];
  $data=json_decode($raw,true);
  if($data===null && json_last_error()!==JSON_ERROR_NONE){
    return ['ok'=>false,'error'=>'door.json is not valid JSON'];
  }
  if(!is_array($data)) $data=[];
  return ['ok'=>true,'path'=>$path,'doc'=>$data];
}

function door_save_doc($data){
  $seed=door_seed_if_needed();
  if(!$seed['ok']) return $seed;
  $path=$seed['path'];
  $tmp=$path.'.tmp';
  $json=json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  if($json===false) return ['ok'=>false,'error'=>'Failed to encode door.json'];
  if(@file_put_contents($tmp,$json)===false) return ['ok'=>false,'error'=>'Failed to write temporary door.json'];
  if(!@rename($tmp,$path)){
    @unlink($tmp);
    return ['ok'=>false,'error'=>'Failed to replace door.json'];
  }
  return ['ok'=>true,'path'=>$path];
}

function door_build_index(&$doc){
  $dirty=false;
  if(!is_array($doc)){
    $doc=[];
    $dirty=true;
  }
  $rootKey='root';
  if(isset($doc['root']) && is_array($doc['root'])){
    $roots=&$doc['root'];
  }elseif(isset($doc['items']) && is_array($doc['items'])){
    $rootKey='items';
    $roots=&$doc['items'];
  }else{
    $doc['root']=[];
    $roots=&$doc['root'];
    $dirty=true;
  }
  if(!is_array($roots)){
    $doc[$rootKey]=[];
    $roots=&$doc[$rootKey];
    $dirty=true;
  }
  if(!$roots){
    $doc[$rootKey]=[door_default_root_node()];
    $roots=&$doc[$rootKey];
    $dirty=true;
  }
  if(!isset($roots[0]) || !is_array($roots[0])){
    $doc[$rootKey]=[door_default_root_node()];
    $roots=&$doc[$rootKey];
    $dirty=true;
  }
  $rootId=trim((string)($roots[0]['id'] ?? ''));
  if($rootId===''){
    $roots[0]['id']='root';
    $rootId='root';
    $dirty=true;
  }
  $nodesById=[];
  $parentById=[];
  $walk=function (&$nodes,$parentId) use (&$walk,&$nodesById,&$parentById,&$dirty,&$rootId){
    $keys=array_keys($nodes);
    foreach($keys as $key){
      if(!is_array($nodes[$key])){
        unset($nodes[$key]);
        $dirty=true;
        continue;
      }
      $node=&$nodes[$key];
      $id=trim((string)($node['id'] ?? ''));
      if($id===''){
        $id=($parentId===null && empty($nodesById)) ? 'root' : uuidv4();
        $node['id']=$id;
        $dirty=true;
      }
      if($parentId===null && empty($nodesById)){
        $rootId=$id;
      }
      if(isset($nodesById[$id])){
        $id=uuidv4();
        $node['id']=$id;
        $dirty=true;
      }
      if(!isset($node['children']) || !is_array($node['children'])){
        $node['children']=[];
        $dirty=true;
      }
      $childKeys=array_keys($node['children']);
      foreach($childKeys as $childKey){
        if(!is_array($node['children'][$childKey])){
          unset($node['children'][$childKey]);
          $dirty=true;
        }
      }
      $node['children']=array_values($node['children']);
      if(!isset($node['links']) || !is_array($node['links'])){
        if(isset($node['links'])) $dirty=true;
        $node['links']=[];
      }
      $normalized=[];
      $seen=[];
      foreach($node['links'] as $link){
        $clean=door_clean_link_payload($link,true);
        if(!$clean){
          $dirty=true;
          continue;
        }
        $linkId=$clean['id'];
        if(isset($seen[$linkId])){
          $dirty=true;
          continue;
        }
        $seen[$linkId]=true;
        $normalized[]=$clean;
      }
      if($node['links']!==$normalized){
        $node['links']=$normalized;
        $dirty=true;
      }
      $nodesById[$id]=&$node;
      $parentById[$id]=$parentId;
      $walk($node['children'],$id);
      unset($node);
    }
    $nodes=array_values($nodes);
  };
  $walk($roots,null);
  if(!$nodesById){
    $doc[$rootKey]=[door_default_root_node()];
    $roots=&$doc[$rootKey];
    $dirty=true;
    $walk($roots,null);
  }
  if(!isset($nodesById[$rootId])){
    $rootId=array_key_first($nodesById);
  }
  $rootId=$rootId ?: 'root';
  return [
    'ok'=>true,
    'rootKey'=>$rootKey,
    'rootId'=>$rootId,
    'roots'=>&$roots,
    'nodes'=>$nodesById,
    'parents'=>$parentById,
    'dirty'=>$dirty
  ];
}

function door_json($arr,$code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

function door_node_title($node){
  if(!is_array($node)) return '';
  $title=trim((string)($node['title'] ?? ''));
  if($title!=='') return $title;
  if(isset($node['metadata']['title'])){
    $metaTitle=trim((string)$node['metadata']['title']);
    if($metaTitle!=='') return $metaTitle;
  }
  if(isset($node['note']) && trim((string)$node['note'])!=='') return trim((string)$node['note']);
  return trim((string)($node['id'] ?? ''));
}

function door_node_note($node){
  if(!is_array($node)) return '';
  if(array_key_exists('note',$node)) return (string)$node['note'];
  if(array_key_exists('content',$node)) return (string)$node['content'];
  return '';
}

function door_collect_nodes($nodes,&$out){
  foreach((array)$nodes as $node){
    if(!is_array($node)) continue;
    $id=trim((string)($node['id'] ?? ''));
    if($id==='') continue;
    $out[$id]=door_node_title($node);
    if(!empty($node['children']) && is_array($node['children'])){
      door_collect_nodes($node['children'],$out);
    }
  }
}

function door_require_csrf(){
  $token=$_SESSION['csrf'] ?? '';
  $header=$_SERVER['HTTP_X_CSRF'] ?? '';
  if(!$token || !hash_equals($token,$header)) door_json(['ok'=>false,'error'=>'CSRF'],403);
}

function &door_root_list(&$data,&$rootKey){
  if(isset($data['root']) && is_array($data['root'])){
    $rootKey='root';
    return $data['root'];
  }
  if(isset($data['items']) && is_array($data['items'])){
    $rootKey='items';
    return $data['items'];
  }
  $rootKey='root';
  if(!isset($data['root']) || !is_array($data['root'])) $data['root']=[];
  return $data['root'];
}

function door_find_node_location(&$nodes,$id,&$parentList,&$index){
  foreach($nodes as $i=>&$node){
    if(!is_array($node)) continue;
    if(($node['id'] ?? '')===$id){
      $parentList=&$nodes;
      $index=$i;
      return true;
    }
    if(!empty($node['children']) && is_array($node['children'])){
      if(door_find_node_location($node['children'],$id,$parentList,$index)) return true;
    }
  }
  return false;
}

function door_find_node_with_breadcrumb($nodes,$target,$trail=[]){
  foreach((array)$nodes as $node){
    if(!is_array($node)) continue;
    $id=trim((string)($node['id'] ?? ''));
    $title=door_node_title($node);
    $entry=['id'=>$id,'title'=>$title];
    $nextTrail=array_merge($trail,[$entry]);
    if($target==='' || $target===$id){
      return ['node'=>$node,'breadcrumb'=>$nextTrail];
    }
    if(!empty($node['children']) && is_array($node['children'])){
      $found=door_find_node_with_breadcrumb($node['children'],$target,$nextTrail);
      if($found) return $found;
    }
  }
  return null;
}

function door_response_node($node){
  if(!is_array($node)) return new stdClass();
  $id=trim((string)($node['id'] ?? ''));
  $children=[];
  foreach((array)($node['children'] ?? []) as $child){
    if(!is_array($child)) continue;
    $childId=trim((string)($child['id'] ?? ''));
    if($childId==='') continue;
    $children[]=$childId;
  }
  $links=[];
  foreach((array)($node['links'] ?? []) as $link){
    if(!is_array($link)) continue;
    $target=trim((string)($link['target'] ?? ''));
    if($target==='') continue;
    $title=trim((string)($link['title'] ?? ''));
    $labelRaw=$link['label'] ?? '';
    $label=trim((string)$labelRaw);
    if($label==='') $label=$title!==''?$title:$target;
    $links[]=[
      'id'=>(string)($link['id'] ?? ''),
      'title'=>$title,
      'label'=>$label,
      'target'=>$target,
      'type'=>(string)($link['type'] ?? '')
    ];
  }
  return [
    'id'=>$id,
    'title'=>door_node_title($node),
    'note'=>door_node_note($node),
    'children'=>$children,
    'links'=>$links,
    'created'=>$node['created'] ?? null,
    'modified'=>$node['modified'] ?? null
  ];
}

function door_clean_link_payload($link,$generateId=false){
  if(!is_array($link)) return null;
  $target=trim((string)($link['target'] ?? ''));
  if($target==='') return null;
  $id=trim((string)($link['id'] ?? ''));
  if($id===''){
    if(!$generateId) return null;
    $id=uuidv4();
  }
  $title=trim((string)($link['title'] ?? ''));
  $label=trim((string)($link['label'] ?? ''));
  if($label==='') $label=$title!==''?$title:$target;
  $type=trim((string)($link['type'] ?? ''));
  $clean=[
    'id'=>$id,
    'target'=>$target,
    'label'=>$label
  ];
  if($title!=='') $clean['title']=$title;
  if($type!=='') $clean['type']=$type;
  return $clean;
}

function door_upsert_links(&$links,$updates){
  if(!is_array($links)) $links=[];
  if(!is_array($updates)) return false;
  $normalized=[];
  $seen=[];
  foreach($updates as $link){
    $clean=door_clean_link_payload($link,true);
    if(!$clean) continue;
    if(isset($seen[$clean['id']])) continue;
    $seen[$clean['id']]=true;
    $normalized[]=$clean;
  }
  $changed=$links!==$normalized;
  if($changed) $links=$normalized;
  return $changed;
}

function door_remove_links(&$links,$remove){
  if(!is_array($links) || !is_array($remove)) return false;
  $set=[];
  foreach($remove as $id){
    $val=trim((string)$id);
    if($val!=='') $set[$val]=true;
  }
  if(!$set) return false;
  $before=count($links);
  $links=array_values(array_filter($links,function($link) use ($set){
    if(!is_array($link)) return false;
    $id=trim((string)($link['id'] ?? ''));
    $target=trim((string)($link['target'] ?? ''));
    if($id!=='' && isset($set[$id])) return false;
    if($target!=='' && isset($set[$target])) return false;
    return true;
  }));
  return count($links)!==$before;
}

function door_filter_ids($items){
  $out=[];
  foreach((array)$items as $val){
    if(is_string($val) || is_int($val) || is_float($val)){
      $trim=trim((string)$val);
      if($trim!=='') $out[$trim]=true;
    }
  }
  return array_keys($out);
}

function door_search_nodes($nodes,$query,$limit){
  $results=[];
  $needle=mb_strtolower($query);
  $limit=max(1,(int)$limit);
  $walk=function($items) use (&$walk,&$results,$needle,$limit){
    foreach((array)$items as $node){
      if(count($results)>=$limit) break;
      if(!is_array($node)) continue;
      $id=$node['id'] ?? '';
      $title=door_node_title($node);
      $note=door_node_note($node);
      $haystack=mb_strtolower($title.' '.($note ?? '').' '.$id);
      if($needle==='' || mb_strpos($haystack,$needle)!==false){
        $results[]=['id'=>$id,'title'=>$title ?: ($id ?: 'Room')];
        if(count($results)>=$limit) break;
      }
      if(!empty($node['children']) && is_array($node['children'])){
        $walk($node['children']);
      }
      if(count($results)>=$limit) break;
    }
  };
  $walk($nodes);
  return $results;
}

function door_search_files($query,$limit){
  global $ROOT;
  $out=[];
  if(!$ROOT) return $out;
  $base=rtrim($ROOT,'/');
  $ark=$base.'/ARKHIVE';
  if(!is_dir($ark)) return $out;
  $limit=max(1,(int)$limit);
  $needle=mb_strtolower($query);
  try{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ark,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
      if(count($out)>=$limit) break;
      if(!$file->isFile()) continue;
      if(strtolower($file->getExtension())!=='md') continue;
      $name=$file->getBasename('.md');
      $rel=rel_of($file->getPathname());
      if($rel===false) continue;
      $haystack=mb_strtolower($name.' '.$rel);
      if($needle!=='' && mb_strpos($haystack,$needle)===false) continue;
      $out[]=['path'=>$rel,'name'=>$name];
    }
  }catch(Exception $e){
    // best-effort: ignore filesystem errors
  }
  return $out;
}

function door_handle_data(){
  $result=door_load_doc();
  if(!$result['ok']){
    $message=$result['error'] ?? 'Unknown door error';
    audit('door_data','DATA/door/door.json',false,$message);
    door_json(['ok'=>false,'error'=>$message],500);
  }
  $doc=$result['doc'];
  $index=door_build_index($doc);
  if($index['dirty']){
    $save=door_save_doc($doc);
    if(!$save['ok']){
      $message=$save['error'] ?? 'Failed to save door.json';
      audit('door_data','DATA/door/door.json',false,$message);
      door_json(['ok'=>false,'error'=>$message],500);
    }
  }
  $rootId=$index['rootId'];
  $target=trim((string)($_GET['id'] ?? ''));
  if($target==='') $target=$rootId;
  if(!isset($index['nodes'][$target])) $target=$rootId;
  if(!isset($index['nodes'][$target])){
    audit('door_data','DATA/door/door.json#'.$target,false,'Node not found');
    door_json(['ok'=>false,'error'=>'Node not found','rootId'=>$rootId],404);
  }
  $node=&$index['nodes'][$target];
  $breadcrumb=[];
  $current=$target;
  while($current!==null && isset($index['nodes'][$current])){
    $currentNode=$index['nodes'][$current];
    array_unshift($breadcrumb,['id'=>$current,'title'=>door_node_title($currentNode)]);
    $current=$index['parents'][$current] ?? null;
  }
  $children=[];
  foreach((array)$node['children'] as $child){
    if(!is_array($child)) continue;
    $childId=trim((string)($child['id'] ?? ''));
    if($childId==='') continue;
    $children[]=[
      'id'=>$childId,
      'title'=>door_node_title($child),
      'note'=>door_node_note($child)
    ];
  }
  $nodePayload=door_response_node($node);
  $allNodes=[];
  door_collect_nodes($index['roots'],$allNodes);
  door_json([
    'ok'=>true,
    'node'=>$nodePayload,
    'children'=>$children,
    'links'=>$nodePayload['links'],
    'breadcrumb'=>$breadcrumb,
    'allNodes'=>$allNodes,
    'rootId'=>$rootId
  ]);
}

function door_handle_create(){
  door_require_csrf();
  $payload=json_decode(file_get_contents('php://input'),true);
  if(!is_array($payload)){
    audit('door_create','DATA/door/door.json',false,'Bad JSON payload');
    door_json(['ok'=>false,'error'=>'Bad JSON'],422);
  }
  $load=door_load_doc();
  if(!$load['ok']){
    $message=$load['error'] ?? 'Unable to load door.json';
    audit('door_create','DATA/door/door.json',false,$message);
    door_json(['ok'=>false,'error'=>$message],500);
  }
  $doc=$load['doc'];
  $index=door_build_index($doc);
  if($index['dirty']){
    $save=door_save_doc($doc);
    if(!$save['ok']){
      $message=$save['error'] ?? 'Failed to save door.json';
      audit('door_create','DATA/door/door.json',false,$message);
      door_json(['ok'=>false,'error'=>$message],500);
    }
  }
  $rootId=$index['rootId'];
  $parentId=trim((string)($payload['parentId'] ?? ''));
  if($parentId==='') $parentId=$rootId;
  if(!isset($index['nodes'][$parentId])) $parentId=$rootId;
  if(!isset($index['nodes'][$parentId])){
    audit('door_create','DATA/door/door.json#'.$parentId,false,'Parent not found');
    door_json(['ok'=>false,'error'=>'Parent not found'],404);
  }
  $parent=&$index['nodes'][$parentId];
  if(!isset($parent['children']) || !is_array($parent['children'])) $parent['children']=[];
  $title=trim((string)($payload['title'] ?? ''));
  if($title==='') $title='New Room';
  $note=(string)($payload['note'] ?? '');
  $now=gmdate('c');
  $newId=trim((string)($payload['id'] ?? ''));
  if($newId==='') $newId=uuidv4();
  while(isset($index['nodes'][$newId])){
    $newId=uuidv4();
  }
  $new=[
    'id'=>$newId,
    'title'=>$title,
    'note'=>$note,
    'children'=>[],
    'links'=>[],
    'created'=>$now,
    'modified'=>$now
  ];
  if(isset($payload['links']) && is_array($payload['links'])){
    door_upsert_links($new['links'],$payload['links']);
  }
  $parent['children'][]=$new;
  if(isset($parent['modified'])) $parent['modified']=$now;
  $save=door_save_doc($doc);
  if(!$save['ok']){
    $message=$save['error'] ?? 'Failed to save door.json';
    audit('door_create','DATA/door/door.json#'.$newId,false,$message);
    door_json(['ok'=>false,'error'=>$message],500);
  }
  audit('door_create','DATA/door/door.json#'.$newId,true);
  door_json(['ok'=>true,'id'=>$newId,'parentId'=>$parentId]);
}

function door_handle_update(){
  door_require_csrf();
  $payload=json_decode(file_get_contents('php://input'),true);
  if(!is_array($payload)){
    audit('door_update','DATA/door/door.json',false,'Bad JSON payload');
    door_json(['ok'=>false,'error'=>'Bad JSON'],422);
  }
  $load=door_load_doc();
  if(!$load['ok']){
    $message=$load['error'] ?? 'Unable to load door.json';
    audit('door_update','DATA/door/door.json',false,$message);
    door_json(['ok'=>false,'error'=>$message],500);
  }
  $doc=$load['doc'];
  $index=door_build_index($doc);
  if($index['dirty']){
    $save=door_save_doc($doc);
    if(!$save['ok']){
      $message=$save['error'] ?? 'Failed to save door.json';
      audit('door_update','DATA/door/door.json',false,$message);
      door_json(['ok'=>false,'error'=>$message],500);
    }
  }
  $rootId=$index['rootId'];
  $id=trim((string)($payload['id'] ?? ''));
  if($id==='') $id=$rootId;
  if(!isset($index['nodes'][$id])) $id=$rootId;
  if(!isset($index['nodes'][$id])){
    audit('door_update','DATA/door/door.json#'.$id,false,'Node not found');
    door_json(['ok'=>false,'error'=>'Node not found'],404);
  }
  $node=&$index['nodes'][$id];
  $changed=false;
  $now=gmdate('c');
  if(array_key_exists('title',$payload)){
    $title=trim((string)$payload['title']);
    $node['title']=$title===''?'Untitled room':$title;
    $changed=true;
  }
  if(array_key_exists('note',$payload)){
    $node['note']=(string)$payload['note'];
    $changed=true;
  }
  $knownIds=array_fill_keys(array_keys($index['nodes']),true);
  if(isset($payload['addChildren']) && is_array($payload['addChildren'])){
    if(!isset($node['children']) || !is_array($node['children'])) $node['children']=[];
    foreach($payload['addChildren'] as $child){
      if(!is_array($child)) continue;
      $childTitle=trim((string)($child['title'] ?? ''));
      if($childTitle==='') $childTitle='Untitled room';
      $childNote=(string)($child['note'] ?? '');
      $childId=trim((string)($child['id'] ?? ''));
      if($childId==='') $childId=uuidv4();
      while(isset($knownIds[$childId])){
        $childId=uuidv4();
      }
      $knownIds[$childId]=true;
      $newChild=[
        'id'=>$childId,
        'title'=>$childTitle,
        'note'=>$childNote,
        'children'=>[],
        'links'=>[],
        'created'=>$now,
        'modified'=>$now
      ];
      if(isset($child['links']) && is_array($child['links'])){
        door_upsert_links($newChild['links'],$child['links']);
      }
      $node['children'][]=$newChild;
      $changed=true;
    }
  }
  if(isset($payload['removeChildren']) && is_array($payload['removeChildren']) && !empty($node['children'])){
    $removeIds=door_filter_ids($payload['removeChildren']);
    if($removeIds){
      $before=count($node['children']);
      $node['children']=array_values(array_filter($node['children'],function($child) use ($removeIds){
        if(!is_array($child)) return false;
        $cid=trim((string)($child['id'] ?? ''));
        return $cid==='' || !in_array($cid,$removeIds,true);
      }));
      if(count($node['children'])!==$before) $changed=true;
    }
  }
  if(isset($payload['upsertLinks']) && is_array($payload['upsertLinks'])){
    if(!isset($node['links']) || !is_array($node['links'])) $node['links']=[];
    if(door_upsert_links($node['links'],$payload['upsertLinks'])) $changed=true;
  }
  if(isset($payload['removeLinkIds']) && is_array($payload['removeLinkIds']) && !empty($node['links'])){
    if(door_remove_links($node['links'],$payload['removeLinkIds'])) $changed=true;
  }
  if(!$changed){
    door_json(['ok'=>true]);
  }
  $node['modified']=$now;
  $save=door_save_doc($doc);
  if(!$save['ok']){
    $message=$save['error'] ?? 'Failed to save door.json';
    audit('door_update','DATA/door/door.json#'.$id,false,$message);
    door_json(['ok'=>false,'error'=>$message],500);
  }
  audit('door_update','DATA/door/door.json#'.$id,true);
  door_json(['ok'=>true]);
}

function door_handle_delete(){
  door_require_csrf();
  $payload=json_decode(file_get_contents('php://input'),true);
  if(!is_array($payload)){
    audit('door_delete','DATA/door/door.json',false,'Bad JSON payload');
    door_json(['ok'=>false,'error'=>'Bad JSON'],422);
  }
  $load=door_load_doc();
  if(!$load['ok']){
    $message=$load['error'] ?? 'Unable to load door.json';
    audit('door_delete','DATA/door/door.json',false,$message);
    door_json(['ok'=>false,'error'=>$message],500);
  }
  $doc=$load['doc'];
  $index=door_build_index($doc);
  if($index['dirty']){
    $save=door_save_doc($doc);
    if(!$save['ok']){
      $message=$save['error'] ?? 'Failed to save door.json';
      audit('door_delete','DATA/door/door.json',false,$message);
      door_json(['ok'=>false,'error'=>$message],500);
    }
  }
  $rootId=$index['rootId'];
  $id=trim((string)($payload['id'] ?? ''));
  if($id==='') $id=$rootId;
  if(!isset($index['nodes'][$id])) $id=$rootId;
  if($id===$rootId){
    audit('door_delete','DATA/door/door.json#'.$id,false,'Cannot delete the root room');
    door_json(['ok'=>false,'error'=>'Cannot delete the root room'],400);
  }
  if(!isset($index['nodes'][$id])){
    audit('door_delete','DATA/door/door.json#'.$id,false,'Node not found');
    door_json(['ok'=>false,'error'=>'Node not found'],404);
  }
  $parentId=$index['parents'][$id] ?? null;
  if($parentId===null || !isset($index['nodes'][$parentId])){
    audit('door_delete','DATA/door/door.json#'.$id,false,'Node not found');
    door_json(['ok'=>false,'error'=>'Node not found'],404);
  }
  $parent=&$index['nodes'][$parentId];
  if(!isset($parent['children']) || !is_array($parent['children'])){
    audit('door_delete','DATA/door/door.json#'.$id,false,'Node not found');
    door_json(['ok'=>false,'error'=>'Node not found'],404);
  }
  $before=count($parent['children']);
  $parent['children']=array_values(array_filter($parent['children'],function($child) use ($id){
    if(!is_array($child)) return false;
    $cid=trim((string)($child['id'] ?? ''));
    return $cid==='' || $cid!==$id;
  }));
  if(count($parent['children'])===$before){
    audit('door_delete','DATA/door/door.json#'.$id,false,'Node not found');
    door_json(['ok'=>false,'error'=>'Node not found'],404);
  }
  $now=gmdate('c');
  if(isset($parent['modified'])) $parent['modified']=$now;
  $save=door_save_doc($doc);
  if(!$save['ok']){
    $message=$save['error'] ?? 'Failed to save door.json';
    audit('door_delete','DATA/door/door.json#'.$id,false,$message);
    door_json(['ok'=>false,'error'=>$message],500);
  }
  audit('door_delete','DATA/door/door.json#'.$id,true);
  door_json(['ok'=>true]);
}
function door_handle_search(){
  $query=trim((string)($_GET['q'] ?? ''));
  $nodeLimit=max(1,min(100,(int)($_GET['node_limit'] ?? 25)));
  $fileLimit=max(1,min(100,(int)($_GET['file_limit'] ?? 50)));
  if($query===''){
    door_json(['ok'=>true]);
  }
  $load=door_load_doc();
  if(!$load['ok']){
    $message=$load['error'] ?? 'Unable to load door.json';
    audit('door_search','DATA/door/door.json',false,$message);
    door_json(['ok'=>false,'error'=>$message],500);
  }
  $doc=$load['doc'];
  $index=door_build_index($doc);
  if($index['dirty']){
    $save=door_save_doc($doc);
    if(!$save['ok']){
      $message=$save['error'] ?? 'Failed to save door.json';
      audit('door_search','DATA/door/door.json',false,$message);
      door_json(['ok'=>false,'error'=>$message],500);
    }
  }
  // Execute searches to ensure the request succeeds even if results are ignored per envelope spec.
  door_search_nodes($index['roots'],$query,$nodeLimit);
  door_search_files($query,$fileLimit);
  door_json(['ok'=>true]);
}

function door_render_shell($title){
  $csrf=$_SESSION['csrf'] ?? '';
  $csrfJs=json_encode($csrf, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  $safeTitle=htmlspecialchars($title, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
  header('Content-Type: text/html; charset=utf-8');
  echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle} — Door</title>
  <style>
    :root{color-scheme:light dark;}
    body{margin:0;font-family:"Inter",system-ui,-apple-system,Segoe UI,sans-serif;background:#f8fafc;color:#0f172a;min-height:100vh;display:flex;flex-direction:column;}
    .door-header{display:flex;flex-wrap:wrap;align-items:center;gap:1rem;padding:1.25rem 1.5rem;background:#111827;color:#f9fafb;}
    .door-header h1{font-size:1.5rem;font-weight:700;letter-spacing:0.08em;margin:0;}
    .door-search{margin-left:auto;display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;}
    .door-search input{padding:0.6rem 0.75rem;border-radius:0.5rem;border:1px solid #cbd5f5;min-width:14rem;font-size:0.95rem;}
    .door-search button{padding:0.6rem 1rem;border-radius:0.5rem;border:1px solid #2563eb;background:#2563eb;color:#f9fafb;font-weight:600;cursor:pointer;}
    .door-search button:hover{background:#1d4ed8;}
    .door-main{flex:1;display:flex;flex-direction:column;gap:1.5rem;padding:1.5rem;max-width:960px;margin:0 auto;width:100%;box-sizing:border-box;}
    .door-error{padding:0.75rem 1rem;margin:1rem 1.5rem;border-radius:0.5rem;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;font-weight:600;}
    .door-error.hidden{display:none;}
    .door-message{min-height:1.5rem;font-size:0.95rem;color:#64748b;}
    .door-breadcrumb{display:flex;flex-wrap:wrap;gap:0.5rem;font-size:0.9rem;align-items:center;}
    .door-breadcrumb button{background:none;border:none;color:#2563eb;font-weight:500;cursor:pointer;padding:0.25rem 0.5rem;border-radius:0.375rem;}
    .door-breadcrumb button:hover{background:#e0f2fe;}
    .door-card{background:#ffffff;border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.25rem;box-shadow:0 6px 24px rgba(148,163,184,0.12);}
    .door-node-title{font-size:1.4rem;font-weight:700;margin:0;}
    .door-node-note{margin-top:0.75rem;font-size:0.95rem;color:#475569;white-space:pre-wrap;}
    .door-children-header{display:flex;align-items:center;justify-content:space-between;gap:0.75rem;margin-top:0.5rem;}
    .door-children-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(12rem,1fr));gap:0.75rem;margin-top:0.75rem;}
    .door-child{display:flex;flex-direction:column;gap:0.35rem;align-items:flex-start;padding:0.85rem;border-radius:0.75rem;border:1px solid #cbd5f5;background:#eff6ff;color:#1d4ed8;text-align:left;width:100%;cursor:pointer;font-size:0.95rem;min-height:4rem;box-sizing:border-box;}
    .door-child:hover{background:#dbeafe;}
    .door-child-note{font-size:0.8rem;color:#64748b;}
    .door-empty{font-size:0.9rem;color:#94a3b8;border:1px dashed #cbd5f5;padding:0.75rem;border-radius:0.75rem;}
    .door-new{padding:0.65rem 1.1rem;border-radius:0.5rem;border:1px solid #2563eb;background:#2563eb;color:#f8fafc;font-weight:600;cursor:pointer;}
    .door-new:hover{background:#1d4ed8;}
    .door-search-results{display:flex;flex-direction:column;gap:0.75rem;}
    .door-search-group h3{margin:0 0 0.25rem;font-size:0.95rem;color:#334155;}
    .door-search-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:0.35rem;}
    .door-search-list button{background:none;border:none;color:#2563eb;font-weight:500;text-align:left;cursor:pointer;padding:0.25rem 0;border-bottom:1px solid #e2e8f0;}
    .door-search-list button:hover{color:#1d4ed8;}
    .door-search-list a{color:#2563eb;text-decoration:none;border-bottom:1px solid #e2e8f0;padding:0.25rem 0;}
    .door-search-error{font-size:0.9rem;color:#b91c1c;}
    @media (max-width:640px){
      .door-main{padding:1rem;}
      .door-header{justify-content:flex-start;}
      .door-search{width:100%;margin-left:0;}
      .door-search input{flex:1;min-width:0;width:100%;}
      .door-search button{width:100%;}
    }
  </style>
</head>
<body>
  <header class="door-header">
    <h1>DOOR</h1>
    <form id="door-search" class="door-search" autocomplete="off" role="search" aria-label="Search rooms and files">
      <input id="door-search-input" type="search" placeholder="Search" aria-label="Search term">
      <button type="submit">Search</button>
    </form>
  </header>
  <div id="door-error" class="door-error hidden" role="alert"></div>
  <main id="app-door" class="door-main">
    <div id="door-message" class="door-message" role="status" aria-live="polite"></div>
    <nav id="door-breadcrumb" class="door-breadcrumb" aria-label="Breadcrumb"></nav>
    <section class="door-card" aria-live="polite">
      <h2 id="door-title" class="door-node-title">Loading…</h2>
      <p id="door-note" class="door-node-note" hidden></p>
    </section>
    <section>
      <div class="door-children-header">
        <h3 class="door-section-title">Rooms</h3>
        <button id="door-create" type="button" class="door-new">+ New</button>
      </div>
      <div id="door-children" class="door-children-grid" role="list"></div>
    </section>
    <section id="door-search-results" class="door-search-results" aria-live="polite"></section>
  </main>
  <script>
    (function(){
      window.__csrf={$csrfJs};
      window.openContentDrawer = window.openContentDrawer || function(filePath){
        alert('Content drawer is coming soon for '+(filePath||'this item'));
      };
      const defaultParams=new URLSearchParams(window.location.search);
      if(!defaultParams.has('mode')) defaultParams.set('mode','door');
      const basePath=window.location.pathname;
      const state={currentId:null,rootId:null,loading:false};
      const messageEl=document.getElementById('door-message');
      const errorEl=document.getElementById('door-error');
      const titleEl=document.getElementById('door-title');
      const noteEl=document.getElementById('door-note');
      const breadcrumbEl=document.getElementById('door-breadcrumb');
      const childrenEl=document.getElementById('door-children');
      const createBtn=document.getElementById('door-create');
      const searchForm=document.getElementById('door-search');
      const searchInput=document.getElementById('door-search-input');
      const searchResults=document.getElementById('door-search-results');

      console.log('[Door] JS loaded');

      function buildUrl(action, extra){
        const params=new URLSearchParams(defaultParams);
        params.set('door',action);
        if(extra){
          for(const [key,val] of Object.entries(extra)){
            if(val===undefined || val===null || val==='') params.delete(key);
            else params.set(key,val);
          }
        }
        return basePath+'?'+params.toString();
      }

      function setError(message){
        if(!message){
          errorEl.textContent='';
          errorEl.classList.add('hidden');
        }else{
          errorEl.textContent=message;
          errorEl.classList.remove('hidden');
        }
      }

      function setMessage(text){
        messageEl.textContent=text||'';
      }

      function escapeHtml(str){
        return str.replace(/[&<>"']/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]||c;});
      }

      function renderNode(payload){
        const node=payload.node||{};
        const title=(node.title||'').trim()||'Untitled room';
        titleEl.textContent=title;
        const note=(node.note||'').trim();
        if(note){
          noteEl.textContent=note;
          noteEl.hidden=false;
        }else{
          noteEl.textContent='';
          noteEl.hidden=true;
        }
        breadcrumbEl.innerHTML='';
        (payload.breadcrumb||[]).forEach(function(crumb,idx,arr){
          const id=crumb.id||'';
          const label=(crumb.title||id||'Room').trim()||'Room';
          if(idx===arr.length-1){
            const span=document.createElement('span');
            span.textContent=label;
            breadcrumbEl.appendChild(span);
          }else{
            const btn=document.createElement('button');
            btn.type='button';
            btn.textContent=label;
            btn.addEventListener('click',()=>loadNode(id));
            breadcrumbEl.appendChild(btn);
            const sep=document.createElement('span');
            sep.textContent='›';
            sep.setAttribute('aria-hidden','true');
            sep.style.color='#94a3b8';
            sep.style.margin='0 0.25rem';
            breadcrumbEl.appendChild(sep);
          }
        });
        childrenEl.innerHTML='';
        const kids=payload.children||[];
        if(!kids.length){
          const empty=document.createElement('div');
          empty.className='door-empty';
          empty.textContent='No rooms yet. Use “+ New” to add one.';
          childrenEl.appendChild(empty);
        }else{
          kids.forEach(function(child){
            const btn=document.createElement('button');
            btn.className='door-child';
            btn.type='button';
            btn.setAttribute('role','listitem');
            btn.innerHTML='<strong>'+escapeHtml((child.title||'Untitled room').trim()||'Untitled room')+'</strong>';
            const note=(child.note||'').trim();
            if(note){
              const noteSpan=document.createElement('span');
              noteSpan.className='door-child-note';
              noteSpan.textContent=note;
              btn.appendChild(noteSpan);
            }
            btn.addEventListener('click',()=>loadNode(child.id));
            childrenEl.appendChild(btn);
          });
        }
        searchResults.innerHTML='';
      }

      async function loadNode(id){
        if(state.loading) return;
        state.loading=true;
        setMessage('Loading…');
        try{
          const url=buildUrl('data',{id:id||''});
          console.log('[Door] GET',url);
          const res=await fetch(url,{headers:{'Accept':'application/json'}});
          const payload=await res.json().catch(()=>({ok:false,error:'Invalid response'}));
          if(!res.ok || !payload.ok){
            throw new Error(payload.error || res.statusText || 'Failed to load room');
          }
          state.rootId=payload.rootId || state.rootId || 'root';
          state.currentId=(payload.node&&payload.node.id)||state.rootId;
          renderNode(payload);
          setError('');
        }catch(err){
          console.error('[Door] data error',err);
          setError(err.message || 'Failed to load room');
        }finally{
          state.loading=false;
          setMessage('');
        }
      }

      async function createRoom(){
        const parentId=state.currentId || state.rootId || 'root';
        const url=buildUrl('create',{});
        console.log('[Door] POST',url);
        createBtn.disabled=true;
        try{
          const res=await fetch(url,{
            method:'POST',
            headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF':window.__csrf||''},
            body:JSON.stringify({parentId:parentId,title:'New Room'})
          });
          const payload=await res.json().catch(()=>({ok:false,error:'Invalid response'}));
          if(!res.ok || !payload.ok){
            throw new Error(payload.error || res.statusText || 'Unable to create room');
          }
          await loadNode(parentId);
        }catch(err){
          console.error('[Door] create error',err);
          setError(err.message || 'Unable to create room');
        }finally{
          createBtn.disabled=false;
        }
      }

      async function runSearch(term){
        if(!term){
          searchResults.innerHTML='';
          return;
        }
        setMessage('Searching…');
        try{
          const url=buildUrl('search',{q:term});
          console.log('[Door] SEARCH',url);
          const res=await fetch(url,{headers:{'Accept':'application/json'}});
          const payload=await res.json().catch(()=>({ok:false,error:'Invalid response'}));
          if(!res.ok || !payload.ok){
            throw new Error(payload.error || res.statusText || 'Search failed');
          }
          renderSearchResults(payload);
          setError('');
        }catch(err){
          console.error('[Door] search error',err);
          searchResults.innerHTML='<p class="door-search-error">'+escapeHtml(err.message||'Search failed')+'</p>';
          setError(err.message || 'Search failed');
        }finally{
          setMessage('');
        }
      }

      function renderSearchResults(payload){
        searchResults.innerHTML='';
        const nodes=payload.nodes||[];
        const files=payload.files||[];
        if(!nodes.length && !files.length){
          searchResults.innerHTML='<p class="door-search-error">No matches.</p>';
          return;
        }
        if(nodes.length){
          const group=document.createElement('div');
          group.className='door-search-group';
          const heading=document.createElement('h3');
          heading.textContent='Rooms';
          group.appendChild(heading);
          const list=document.createElement('div');
          list.className='door-search-list';
          nodes.forEach(function(node){
            const btn=document.createElement('button');
            btn.type='button';
            btn.textContent=(node.title||node.id||'Room');
            btn.addEventListener('click',()=>{
              loadNode(node.id);
            });
            list.appendChild(btn);
          });
          group.appendChild(list);
          searchResults.appendChild(group);
        }
        if(files.length){
          const group=document.createElement('div');
          group.className='door-search-group';
          const heading=document.createElement('h3');
          heading.textContent='Files';
          group.appendChild(heading);
          const list=document.createElement('div');
          list.className='door-search-list';
          files.forEach(function(file){
            const link=document.createElement('a');
            link.href=file.path?('../'+file.path):'#';
            link.textContent=file.name||file.path||'File';
            link.target='_blank';
            link.rel='noopener';
            list.appendChild(link);
          });
          group.appendChild(list);
          searchResults.appendChild(group);
        }
      }

      createBtn.addEventListener('click',createRoom);
      searchForm.addEventListener('submit',function(event){
        event.preventDefault();
        runSearch((searchInput.value||'').trim());
      });

      loadNode('root');
    })();
  </script>
</body>
</html>
HTML;
  exit;
}

// Door router: query ?door=<action> handles data/create/update/delete/search; default returns shell.
function door_handle_mode($title){
  $route=$_GET['door'] ?? '';
  $method=strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  if($route==='data' && $method==='GET') door_handle_data();
  elseif($route==='create' && $method==='POST') door_handle_create();
  elseif($route==='update' && $method==='POST') door_handle_update();
  elseif($route==='delete' && $method==='POST') door_handle_delete();
  elseif($route==='search' && $method==='GET') door_handle_search();
  door_render_shell($title);
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
if(isset($_GET['action']) && $_GET['action']==='search'){
  if(!$authed) bad('Unauthorized',401);
  if(!$ROOT || !is_dir($ROOT)) bad('Invalid ROOT (set near top of file).',500);
  door_handle_search();
}
if (isset($_GET['api'])) {
  if (!$authed) bad('Unauthorized',401);
  if (!$ROOT || !is_dir($ROOT)) bad('Invalid ROOT (set near top of file).',500);
  $action = $_GET['api']; $path = $_GET['path'] ?? ''; $abs = safe_abs($path);
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($method==='POST') { $hdr = $_SERVER['HTTP_X_CSRF'] ?? ''; if ($hdr !== ($_SESSION['csrf'] ?? '')) bad('CSRF',403); }

  if ($action==='whereami') j(['ok'=>true,'root'=>$ROOT]);

  if ($action==='search') door_handle_search();

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

  if ($action==='rebuild_door' && $method==='POST') {
    $script=$ROOT.'/scripts/build_door_nodes.php';
    if(!is_file($script)) bad('Script not found',500);
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
    $desc=[1=>['pipe','w'], 2=>['pipe','w']];
    $proc=@proc_open($cmd,$desc,$pipes,$ROOT);
    if(!is_resource($proc)) bad('Failed to launch script',500);
    $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code=proc_close($proc);
    $ok=$code===0;
    audit('exec','scripts/build_door_nodes.php',$ok);
    j(['ok'=>$ok,'exitCode'=>$code,'stdout'=>$stdout,'stderr'=>$stderr]);
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
    $key=null; $root=json_get_root($doc,$key);
    $title=json_get_title($doc);
    j(['ok'=>true,'root'=>$root,'title'=>$title]);
  }

  if ($action==='get_all_json_nodes') {
    $file = $_GET['file'] ?? '';
    $fileAbs = safe_abs($file);
    if ($fileAbs===false || !is_file($fileAbs)) bad('Bad file');
    $ext = strtolower(pathinfo($fileAbs, PATHINFO_EXTENSION));
    if ($ext !== 'json') bad('Not JSON',415);
    $raw = @file_get_contents($fileAbs); if($raw===false) bad('Read error',500);
    $doc = json_decode($raw,true);
    $key=null; $root=json_get_root($doc,$key);
    $nodes = [];
    $walk = function($items) use (&$walk,&$nodes){
      foreach($items as $it){
        if(!is_array($it)) continue;
        $id=$it['id'] ?? '';
        $title=json_get_title($it);
        if($id!=='') $nodes[]=['arkid'=>$id,'title'=>$title];
        if(!empty($it['children']) && is_array($it['children'])) $walk($it['children']);
      }
    };
    $walk($root);
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
    $rootKey=null; $root=&json_get_root($doc,$rootKey);
    $now=gmdate('Y-m-d\TH:i:s\Z');
    $selId=$id;
    $parentList=$index=$parentItem=null;
    if(in_array($op,['add_sibling','delete','move'])){
      if(!cjsf_find_parent($root,$id,$parentList,$index,$parentItem)) bad('Node not found',404);
      $item=&$parentList[$index];
    }else{
      $item=null; if(!cjsf_find_item_ref($root,$id,$item)) bad('Node not found',404);
    }
    switch($op){
      case 'set_title': {
        $title=(string)($data['title'] ?? '');
        json_set_title($item,$title);
        $item['modified']=$now;
      } break;
      case 'add_child': {
        $title=trim($data['title'] ?? 'New');
        $ref = (!empty($item['children']) && isset($item['children'][0]) && is_array($item['children'][0])) ? $item['children'][0] : $item;
        $new=json_new_node_like($ref,$title,$now);
        if(!isset($item['children']) || !is_array($item['children'])) $item['children']=[];
        $item['children'][]=$new;
        $item['modified']=$now;
        $selId=$new['id'];
      } break;
      case 'add_sibling': {
        $title=trim($data['title'] ?? 'New');
        $new=json_new_node_like($item,$title,$now);
        array_splice($parentList,$index+1,0,[$new]);
        if($parentItem) $parentItem['modified']=$now;
        $selId=$new['id'];
      } break;
      case 'delete': {
        array_splice($parentList,$index,1);
        if($parentItem){ $parentItem['modified']=$now; $selId=$parentItem['id'] ?? ($root[0]['id'] ?? null); }
        else { $selId=$root[0]['id'] ?? null; }
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
          if(!cjsf_find_parent($root,$parentItem['id'],$gpList,$gpIndex,$gpParent)) bad('Cannot outdent root-level',400);
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
    $save = ($rootKey===null) ? $root : $doc;
    $ok=file_put_contents($fileAbs,json_encode($save,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))!==false;
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

$doorMode = isset($_GET['mode']) && strtolower($_GET['mode']) === 'door';
if ($doorMode) {
  if (!$authed) render_login_ui($TITLE,$err ?? '');
  door_handle_mode($TITLE);
  exit;
}

  /* ===== HTML (UI) ===== */
  if (!$authed) render_login_ui($TITLE,$err ?? '');

?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=$TITLE?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Classic UI font/spacing/button tokens come from these Tailwind utility mixes. -->
  <style type="text/tailwindcss">
    .pane-header {@apply flex flex-wrap items-center gap-2 p-4 border-b;}
    .pane-title {@apply font-semibold flex-1;}
    .pane-controls {@apply flex items-center gap-2;}
    .pane-meta {@apply flex items-center gap-2 px-4 py-2 border-b;}
    .file-input {@apply flex-1 border rounded px-2 py-1;}
    .title-input {@apply flex-1 border rounded px-2 py-1;}
    .primary {@apply px-3 py-1 bg-blue-600 text-white rounded;}
    .mono {@apply font-mono;}
  </style>
  <style>
    #doorTree{padding:0.75rem;}
    #doorTree .door-node-entry{margin-bottom:0.5rem;}
    #doorTree .door-node-row{display:flex;align-items:center;gap:0.5rem;padding:0.5rem;border:1px solid #e2e8f0;border-radius:0.5rem;background:#f8fafc;box-shadow:0 1px 1px rgba(148,163,184,0.15);transition:border-color .2s ease,background-color .2s ease,box-shadow .2s ease;}
    #doorTree .door-node-row:hover{border-color:#bfdbfe;background:#eff6ff;}
    #doorTree .door-node-row.active{border-color:#2563eb;background:#dbeafe;box-shadow:0 0 0 1px rgba(37,99,235,0.35);}
    #doorTree .door-node-caret{width:1.75rem;height:1.75rem;display:flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:0.375rem;background:#ffffff;color:#6b7280;transition:background-color .2s ease,color .2s ease,border-color .2s ease;}
    #doorTree .door-node-caret:hover{background:#f1f5f9;color:#1d4ed8;border-color:#bfdbfe;}
    #doorTree .door-node-caret:focus{outline:2px solid rgba(37,99,235,0.35);outline-offset:2px;}
    #doorTree .door-node-spacer{width:1.75rem;display:flex;align-items:center;justify-content:center;color:#cbd5f5;}
    #doorTree .door-node-button{flex:1;background:none;border:none;padding:0;font:inherit;color:#1f2937;text-align:left;cursor:pointer;transition:color .2s ease;font-size:0.875rem;}
    #doorTree .door-node-button:hover{color:#1d4ed8;}
    #doorTree .door-node-button.active{color:#1d4ed8;font-weight:600;}
    #doorTree .door-node-button:focus{outline:2px solid rgba(37,99,235,0.35);outline-offset:2px;}
    #doorTree .door-node-children{margin-left:1.5rem;padding-left:0.75rem;border-left:1px dashed #d1d5db;margin-top:0.5rem;}
    #doorContent h1,#doorContent h2,#doorContent h3{margin-top:1.5rem;font-weight:600;color:#1f2937;}
    #doorContent p{margin-top:0.75rem;color:#374151;}
    #doorContent ul{margin-top:0.75rem;margin-left:1.25rem;list-style:disc;color:#374151;}
    #doorContent li{margin-top:0.25rem;}
    #doorContent hr{margin:1.5rem 0;border:0;border-top:1px solid #e5e7eb;}
    #doorContent pre{background:#111827;color:#f9fafb;padding:0.75rem;border-radius:0.5rem;overflow:auto;margin-top:0.75rem;font-size:0.875rem;}
    #doorContent code{background:#e5e7eb;color:#1f2937;padding:0.1rem 0.25rem;border-radius:0.25rem;}
    #doorContent .door-chip{display:inline-flex;align-items:center;gap:0.25rem;padding:0.25rem 0.75rem;border-radius:0.5rem;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;font-weight:500;cursor:pointer;transition:background-color .2s ease,border-color .2s ease,color .2s ease,box-shadow .2s ease;text-decoration:none;}
    #doorContent .door-chip:hover{background:#dbeafe;border-color:#60a5fa;color:#1d4ed8;}
    #doorContent .door-chip:focus{outline:2px solid rgba(37,99,235,0.35);outline-offset:2px;}
    .door-article{background:#ffffff;border:1px solid #e5e7eb;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 1px rgba(148,163,184,0.14);min-height:6rem;}
    #doorTeleports .door-teleport-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(12rem,1fr));gap:0.75rem;margin:0;padding:0;}
    #doorTeleports .door-teleport-list li{list-style:none;}
    #doorTeleports .door-teleport-meta{font-size:0.75rem;color:#6b7280;margin-top:0.35rem;padding-left:0.5rem;}
    #doorTeleports .door-chip{width:100%;justify-content:center;box-shadow:0 1px 1px rgba(148,163,184,0.2);}
    #doorTeleports .door-chip:hover{box-shadow:0 4px 12px rgba(96,165,250,0.25);}
    #doorTeleports a.door-chip{display:inline-flex;}
    #doorActions{min-height:2.5rem;}
    #doorActions .door-action-btn{display:inline-flex;align-items:center;gap:0.5rem;padding:0.35rem 0.9rem;border-radius:0.5rem;border:1px solid #cbd5f5;background:#f8fafc;color:#1d4ed8;font-weight:500;box-shadow:0 1px 1px rgba(148,163,184,0.18);transition:background-color .2s ease,border-color .2s ease,box-shadow .2s ease,color .2s ease;}
    #doorActions .door-action-btn:hover{background:#eff6ff;border-color:#93c5fd;box-shadow:0 4px 12px rgba(147,197,253,0.35);}
    #doorActions .door-action-btn:focus{outline:2px solid rgba(37,99,235,0.35);outline-offset:2px;}
    #doorActions .door-action-btn svg{width:1rem;height:1rem;}
    .door-children{background:#ffffff;border:1px solid #e5e7eb;border-radius:0.75rem;padding:1rem 1.25rem;box-shadow:0 1px 1px rgba(148,163,184,0.12);}
    .door-children-header{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:1rem;}
    .door-children-title{font-size:0.95rem;font-weight:600;color:#1f2937;}
    .door-children-count{font-size:0.8rem;color:#6b7280;}
    .door-child-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(12rem,1fr));gap:0.75rem;}
    .door-child-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.75rem;padding:0.85rem;display:flex;flex-direction:column;gap:0.5rem;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;background-image:linear-gradient(135deg,rgba(248,250,252,0.95),rgba(239,246,255,0.95));}
    .door-child-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(148,163,184,0.25);border-color:#bfdbfe;}
    .door-child-button{display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem;text-align:left;width:100%;background:none;border:none;padding:0;margin:0;font:inherit;color:#1f2937;cursor:pointer;}
    .door-child-button:focus{outline:2px solid rgba(37,99,235,0.35);outline-offset:2px;}
    .door-child-title{font-weight:600;color:#1f2937;font-size:0.95rem;}
    .door-child-meta{font-size:0.75rem;color:#6b7280;}
    .door-child-open{align-self:flex-start;padding:0.25rem 0.6rem;border-radius:9999px;background:#e0f2fe;color:#0369a1;font-size:0.7rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;}
    .door-child-empty{font-size:0.85rem;color:#6b7280;padding:0.25rem 0.5rem;border:1px dashed #cbd5f5;border-radius:0.5rem;background:#f8fafc;}
  </style>
</head>
<body class="h-screen flex flex-col bg-gray-50 text-gray-800 overflow-x-hidden">
  <header class="flex items-center gap-4 p-4 bg-white shadow">
    <button onclick="openDir('')" class="px-2 py-1 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200 text-gray-600 hover:text-gray-800">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
      <span class="sr-only">Home</span>
    </button>
    <nav id="crumb" class="flex items-center gap-2 text-sm text-gray-600"></nav>
    <button id="doorModeBtn" type="button" onclick="toggleDoorMode()" class="px-3 py-1 text-sm border border-blue-200 text-blue-600 rounded hover:bg-blue-50">Door</button>
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
  <main class="flex-1 overflow-auto md:overflow-hidden p-4 space-y-4 md:space-y-0 md:grid md:grid-cols-4 md:gap-4 md:h-[calc(100vh-64px)] min-h-0">
    <!-- FIND -->
    <section id="pane-find" class="order-1 bg-white rounded shadow flex flex-col overflow-hidden min-h-0">
      <header class="pane-header">
        <h3 class="pane-title">FIND</h3>
        <div class="pane-controls">
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
      </header>
      <div class="pane-meta">
        <input id="meta-file-FIND" class="file-input mono flex-1" placeholder="path/to/directory" />
      </div>
      <div id="findBody" class="flex-1 flex flex-col overflow-hidden min-h-0">
        <ul id="folderList" class="flex-1 overflow-auto divide-y text-sm min-h-0"></ul>
      </div>
    </section>

    <!-- STRUCTURE -->
    <section id="pane-structure" class="order-2 bg-white rounded shadow flex flex-col overflow-hidden min-h-0">
      <header class="pane-header">
        <h3 class="pane-title">STRUCTURE</h3>
        <div class="pane-controls">
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
      </header>
      <div class="pane-meta">
        <label>File Name <input id="meta-file-STRUCTURE" class="file-input mono" /></label>
        <label>Structure Title <input id="meta-title-STRUCTURE" class="title-input" /></label>
        <button id="meta-save-STRUCTURE" class="px-2 py-1 border rounded" title="Save">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h18v18H3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 3v6h6V3"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 15h8v6H8z"/></svg>
        </button>
      </div>
      <div id="structBody" class="p-4 flex-1 flex flex-col overflow-hidden min-h-0">
        <ul id="fileList" class="flex-1 overflow-auto divide-y text-sm min-h-0"></ul>
        <div id="opmlTreeWrap" class="hidden flex-1 overflow-auto min-h-0"></div>
      </div>
    </section>

    
    <!-- CONTENT -->
    <section id="pane-content" class="order-3 bg-white rounded shadow flex flex-col overflow-hidden min-h-0">
      <header class="pane-header">
        <h3 class="pane-title">CONTENT</h3>
        <div class="pane-controls">
          <button onclick="showCurrentInfo()" id="infoBtn" disabled class="p-2 text-gray-600 hover:text-gray-800 disabled:opacity-50" title="Info">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25h1.5v5.25h-1.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 9h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </button>
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
          <button class="ml-2 md:hidden" onclick="toggleSection('content-body', this)">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
          </button>
        </div>
      </header>
      <div class="pane-meta">
        <label>Node Title <input id="meta-title-CONTENT" class="title-input" /></label>
      </div>
      <div id="content-body" class="p-4 flex-1 flex flex-col overflow-hidden min-h-0">
        <textarea id="content-note" class="flex-1 w-full resize-none"></textarea>

        <ul id="link-list"></ul>
        <button id="link-add" class="mt-2">Add Link</button>
      </div>
    </section>

<!-- PREVIEW -->
    <section id="pane-preview" class="order-4 bg-white rounded shadow flex flex-col overflow-hidden min-h-0">
      <header class="pane-header">
        <h3 class="pane-title">PREVIEW</h3>
        <div class="pane-controls">
          <div class="flex gap-2">
            <button id="preview-web-btn" class="px-2 py-1 text-sm border rounded bg-gray-200">WEB</button>
            <button id="preview-raw-btn" class="px-2 py-1 text-sm border rounded">RAW</button>
            <button id="preview-save" class="px-2 py-1 text-sm border rounded bg-blue-600 text-white hidden">Save</button>
          </div>
          <button class="ml-auto md:hidden" onclick="toggleSection('previewBody', this)">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
          </button>
        </div>
      </header>
      <div class="pane-meta">
        <input id="meta-file-PREVIEW" class="file-input mono" readonly />
        <button id="meta-save-PREVIEW" class="px-2 py-1 border rounded" title="Save">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h18v18H3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 3v6h6V3"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 15h8v6H8z"/></svg>
        </button>
      </div>
      <div id="previewBody" class="flex-1 flex flex-col overflow-hidden min-h-0">
        <div id="preview-web" class="flex-1 overflow-auto p-4"></div>
        <textarea id="preview-raw" class="hidden flex-1 p-4 font-mono overflow-auto"></textarea>
      </div>
    </section>

  </main>

  <div id="doorMode" class="hidden flex-1 flex flex-col p-4 gap-4">
    <div class="bg-white rounded shadow p-4 flex flex-col gap-3">
      <div class="flex flex-wrap items-center gap-3">
        <h2 class="text-lg font-semibold text-gray-700">DOOR MODE</h2>
        <button id="doorRebuildBtn" type="button" onclick="rebuildDoorSeeds()" class="px-3 py-1 bg-blue-600 text-white rounded shadow-sm hover:bg-blue-500">Rebuild Seeds</button>
        <button id="doorHelpBtn" type="button" class="px-3 py-1 border border-blue-200 text-sm text-blue-600 rounded hover:bg-blue-50 focus:ring-2 focus:ring-blue-300" aria-expanded="false" aria-controls="doorHelpPanel">How it works</button>
        <a id="doorBuilderLink" href="cloud.php?mode=door" class="px-3 py-1 border rounded text-sm text-gray-700 hover:bg-gray-100 focus:ring-2 focus:ring-blue-300">Open Door Builder</a>
        <span id="doorStatus" class="text-sm text-gray-500 flex-1 min-w-[12rem]" role="status" aria-live="polite"></span>
        <div class="ml-auto flex gap-2">
          <button type="button" onclick="refreshDoorDataset()" class="px-3 py-1 border rounded text-sm text-gray-600 hover:bg-gray-100">Refresh</button>
          <button type="button" onclick="exitDoorMode()" class="px-3 py-1 border rounded text-sm text-gray-600 hover:bg-gray-100">Back to Editor</button>
        </div>
      </div>
      <div id="doorHelpPanel" class="hidden w-full bg-blue-50 border border-blue-200 rounded p-4 text-sm text-blue-900 space-y-3" role="region" aria-label="Door mode help" tabindex="-1">
        <div class="flex items-start justify-between gap-2">
          <p class="font-semibold">Door mode quick tour</p>
          <button type="button" id="doorHelpClose" class="text-blue-700 hover:text-blue-900 focus:ring-2 focus:ring-blue-300 rounded px-2" aria-label="Close help panel">Close</button>
        </div>
        <ul class="list-disc list-inside space-y-1">
          <li><span class="font-semibold">Atlas:</span> Expand the tree on the left and choose a room to load its notes.</li>
          <li><span class="font-semibold">Child rooms:</span> Follow the room cards and teleport buttons to hop between related spaces.</li>
          <li><span class="font-semibold">Editing:</span> Door mode is read-only. Use the editor panes or the Door Builder button for full CRUD tools.</li>
        </ul>
        <p class="text-xs text-blue-800">Tip: keyboard users can tab to the Atlas tree, use arrow keys to explore, and press Enter to open a room.</p>
      </div>
    </div>
    <div class="flex-1 grid grid-cols-1 md:grid-cols-4 gap-4 min-h-0">
      <div class="bg-white rounded shadow flex flex-col min-h-0">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Atlas</div>
        <div id="doorTree" class="flex-1 overflow-auto text-sm divide-y"></div>
      </div>
      <div class="bg-white rounded shadow flex flex-col md:col-span-3 min-h-0">
        <div class="px-4 py-3 border-b">
          <h3 id="doorTitle" class="text-xl font-semibold text-gray-800">Select a room</h3>
          <div id="doorMeta" class="text-sm text-gray-500 mt-1"></div>
          <div id="doorActions" class="mt-3 flex flex-wrap gap-2 hidden"></div>
        </div>
        <div id="doorContent" class="flex-1 overflow-auto p-4 text-sm leading-relaxed bg-gray-50 space-y-6"></div>
        <div id="doorTeleports" class="px-4 py-3 border-t">
          <h4 class="font-semibold text-gray-700 mb-2">Teleports</h4>
          <p id="doorTeleportEmpty" class="text-sm text-gray-500">No teleports yet.</p>
        </div>
      </div>
    </div>
  </div>

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

  <script src="assets/js/cloud-classic.js"></script>
  <script>
const CSRF = '<?=htmlspecialchars($_SESSION['csrf'] ?? '')?>';
const api=(act,params)=>fetch(`?api=${act}&`+new URLSearchParams(params||{}));
let currentDir='', currentFile='', currentOutlinePath='', currentFileInfo=null;
let clipboardPath='';
const state={doc:null};
let selectedId=null, nodeMap={}, arkMap={}, currentLinks=[], currentJsonRoot=[], currentJsonDoc=null, currentJsonRootKey=null;
const doorState={active:false,ready:false,loading:false,nodes:{},rootId:null,selectedId:null,contentCache:{},expanded:new Set(),parents:{}};
const doorHelpBtn=document.getElementById('doorHelpBtn');
const doorHelpPanel=document.getElementById('doorHelpPanel');
const doorHelpClose=document.getElementById('doorHelpClose');

const queryParams=new URLSearchParams(window.location.search||'');
const selectedPathParam=(queryParams.get('selectedPath')||'').trim();
const selectedIdParam=(queryParams.get('selectedId')||'').trim();
const selectedPathInfo={raw:selectedPathParam,isDir:selectedPathParam.endsWith('/')};
let doorSelectionPending=selectedIdParam?selectedIdParam:null;

function setDoorHelpVisibility(show){
  if(!doorHelpBtn || !doorHelpPanel) return;
  doorHelpBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
  doorHelpPanel.classList.toggle('hidden', !show);
  if(show){
    doorHelpPanel.focus();
  }
}

if(doorHelpBtn && doorHelpPanel){
  doorHelpBtn.addEventListener('click',()=>{
    const expanded=doorHelpBtn.getAttribute('aria-expanded')==='true';
    setDoorHelpVisibility(!expanded);
  });
  if(doorHelpClose){
    doorHelpClose.addEventListener('click',()=>{
      setDoorHelpVisibility(false);
      doorHelpBtn.focus();
    });
  }
  doorHelpPanel.addEventListener('keydown',event=>{
    if(event.key==='Escape'){
      setDoorHelpVisibility(false);
      doorHelpBtn.focus();
    }
  });
}
function updateMeta(){
  const node = selectedId!==null ? findJsonNode(currentJsonRoot, selectedId) : null;
  const title = node ? getNodeTitle(node) : '';
  ['FIND','STRUCTURE','CONTENT','PREVIEW'].forEach(p=>{
    const f=document.getElementById('meta-file-'+p);
    const t=document.getElementById('meta-title-'+p);
    if(f){
      if(p==='FIND') f.value=currentDir || '';
      else if(p!=='CONTENT') f.value=currentFile || currentDir || '';
    }
    if(t){
      if(p==='STRUCTURE' && state.doc) t.value=getNodeTitle(state.doc)||'';
      else t.value=title;
    }
  });
}
updateMeta();

async function focusSelectedPath(info){
  const raw=(info && info.raw) ? info.raw : '';
  if(!raw){
    await openDir('');
    return;
  }
  const segments=raw.replace(/\\/g,'/').split('/').filter(Boolean);
  if(!segments.length){
    await openDir('');
    return;
  }
  if(info && info.isDir){
    await openDir(segments.join('/'));
    return;
  }
  const fileName=segments.pop();
  const dir=segments.join('/');
  await openDir(dir);
  if(fileName){
    const fullPath=dir?`${dir}/${fileName}`:fileName;
    await openFile(fullPath,fileName,0,0);
  }
}

function applyMetaBindings(pane){
  const fileInput=document.getElementById(`meta-file-${pane}`);
  const titleInput=document.getElementById(`meta-title-${pane}`);
  const saveBtn=document.getElementById(`meta-save-${pane}`);

  if(pane==='FIND'){
    if(fileInput){
      fileInput.addEventListener('keydown',e=>{
        if(e.key==='Enter'){
          const newPath=fileInput.value.trim();
          if(newPath) openDir(newPath);
        }
      });
    }
    return;
  }

  if(pane==='PREVIEW'){
    if(!fileInput || !saveBtn) return;
    fileInput.readOnly=true;
    saveBtn.addEventListener('click',async()=>{
      if(state.doc){ await saveDocument(currentFile,state.doc); emit('documentChanged'); }
    });
    return;
  }

  if(pane==='CONTENT'){
    if(titleInput){
      titleInput.addEventListener('input',()=>{
        if(selectedId===null) return;
        const node=findJsonNode(currentJsonRoot,selectedId);
        if(node) setNodeTitle(node,titleInput.value.trim());
        updateMeta();
      });
    }
    return;
  }

  if(!fileInput || !titleInput || !saveBtn) return;

  if(pane==='STRUCTURE'){
    saveBtn.addEventListener('click',async()=>{
      const newPath=fileInput.value.trim();
      const newTitle=titleInput.value.trim();
      let changed=false;
      if(newPath && newPath!==currentFile){
        await renameDocument(currentFile,newPath);
        currentFile=newPath;
        changed=true;
      }
      if(state.doc && newTitle && newTitle!==getNodeTitle(state.doc)){
        setNodeTitle(state.doc,newTitle);
        await saveDocument(currentFile,state.doc);
        changed=true;
      }
      if(changed) emit('documentChanged');
    });
    return;
  }
}
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
const sortBtn=document.getElementById('sort-btn');
const sortMenu=document.getElementById('sort-menu');
// Classic preview + RAW render/save wiring lives here (PreviewService hooks below).
const contentPreview=document.getElementById('content-preview');
const contentEditor=document.getElementById('content-editor');
const contentNote=document.getElementById('content-note');
const linkList=document.getElementById('link-list');
const linkAdd=document.getElementById('link-add');
const bus=new EventTarget();
const emit=(type,detail)=>bus.dispatchEvent(new CustomEvent(type,{detail}));
const on=(type,handler)=>bus.addEventListener(type,handler);
let fileSort={criterion:'name',direction:'asc'};
let originalRootOrder=[];
let saveContentTimer=null, saveTitleTimer=null, nodeTitleInput=null;

function getNodeTitle(node){
  return node.title || (node.metadata&&node.metadata.title) || node.content || node.note || '';
}
function setNodeTitle(node,val){
  if('title' in node) node.title=val;
  else if(node.metadata && 'title' in node.metadata) node.metadata.title=val;
  else if('content' in node && !('title' in node)) node.content=val;
  else node.title=val;
}
function getNodeNote(node){
  if('note' in node) return node.note;
  if('content' in node && ('title' in node || (node.metadata&&'title' in node.metadata))) return node.content;
  return '';
}
function setNodeNote(node,val){
  if('note' in node) node.note=val;
  else if('content' in node && ('title' in node || (node.metadata&&'title' in node.metadata))) node.content=val;
  else node.content=val;
}

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

function newNodeLike(ref,title,now){
  const n={id:crypto.randomUUID(),children:[]};
  if('type' in ref) n.type=ref.type;
  if('title' in ref) n.title=title;
  if(ref.metadata) n.metadata={title};
  if('note' in ref) n.note='';
  if('content' in ref){
    if('title' in ref || (ref.metadata&&ref.metadata.title!==undefined)) n.content='';
    else n.content=title;
  }
  if('links' in ref) n.links=[];
  if('created' in ref) n.created=now;
  if('modified' in ref) n.modified=now;
  return n;
}

async function saveDocument(path, doc){
  try{
    await fetch(`?api=save_json_structure&path=${encodeURIComponent(path)}`,{
      method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},
      body:JSON.stringify(doc,null,2)
    });
  }catch(e){console.error('save failed',e);}
}

async function renameDocument(oldPath, newPath){
  try{
    await fetch(`?api=rename&`+new URLSearchParams({path:oldPath}),{
      method:'POST',headers:{'X-CSRF':CSRF,'Content-Type':'application/json'},
      body:JSON.stringify({to:newPath})
    });
  }catch(e){console.error('rename failed',e);}
}

async function saveCurrentJsonStructure(){
  if(!currentFile || !currentFile.toLowerCase().endsWith('.json')) return;
  let doc;
  if(currentJsonRootKey===null){
    doc=currentJsonRoot;
  }else{
    if(!currentJsonDoc || typeof currentJsonDoc!=='object') currentJsonDoc={};
    currentJsonDoc[currentJsonRootKey]=currentJsonRoot;
    doc=currentJsonDoc;
  }
  await saveDocument(currentFile,doc);
  if(ta) ta.value=JSON.stringify(doc,null,2);
  state.doc=doc;
  emit('documentChanged',doc);
}
function cjsf_to_ark(items){
  function walk(arr){
    const out=[];
    for(const it of arr||[]){
      if(!it || typeof it!=='object') continue;
      const title=it.title||(it.metadata&&it.metadata.title)||it.content||it.note||'•';
      const note=('note' in it)? it.note : (('content' in it && (it.title||(it.metadata&&it.metadata.title)))? it.content : '');
      const n={
        t:title,
        id:it.id||'',
        arkid:it.id||''
      };
      if(note) n.note=note;
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
      out.push({id:it.id||'', title:getNodeTitle(it)});
      if(Array.isArray(it.children) && it.children.length) walk(it.children);
    }
  })(currentJsonRoot);
  return out;
}

function refreshPreview(treeOverride=null){
  if(!window.PreviewService) return;
  const isJson=currentFile && currentFile.toLowerCase().endsWith('.json');
  const helpers={
    findNode:id=>findJsonNode(currentJsonRoot,id),
    setNodeTitle:(node,val)=>{ setNodeTitle(node,val); node.modified=new Date().toISOString(); },
    setNodeNote:(node,val)=>{ setNodeNote(node,val); node.modified=new Date().toISOString(); },
    saveStructure:()=>saveCurrentJsonStructure(),
    followLink,
    openDir,
    openFile
  };
  const doc=state.doc || (currentJsonRootKey===null?currentJsonRoot:currentJsonDoc);
  const nodes=treeOverride || cjsf_to_ark(currentJsonRoot);
  PreviewService.renderWeb({
    doc,
    docTitle: getNodeTitle(doc||{}),
    nodes,
    jsonMode: !!isJson,
    helpers
  });
  PreviewService.renderRaw(doc);
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

function escapeHtml(str){
  return str.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
}
const doorAttrEscapeMap={'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'};
function escapeAttr(str){
  return str.replace(/[&<>"']/g,c=>doorAttrEscapeMap[c]||c);
}
function mdLinks(str){
  return str.replace(/\[([^\]]+)\]\(([^)]+)\)/g,'<a href="$2">$1</a>');
}

function toggleDoorMode(){ doorState.active ? exitDoorMode() : enterDoorMode(); }

function enterDoorMode(){
  if(doorState.active) return;
  doorState.active=true;
  const main=document.querySelector('main');
  if(main) main.classList.add('hidden');
  const door=document.getElementById('doorMode');
  if(door) door.classList.remove('hidden');
  const btn=document.getElementById('doorModeBtn');
  if(btn){
    btn.classList.add('bg-blue-600','text-white','border-blue-600');
    btn.classList.remove('text-blue-600','border-blue-200');
  }
  setDoorHelpVisibility(false);
  renderDoorPlaceholder('Loading dataset…');
  loadDoorDataset();
}

function exitDoorMode(){
  if(!doorState.active) return;
  doorState.active=false;
  const main=document.querySelector('main');
  if(main) main.classList.remove('hidden');
  const door=document.getElementById('doorMode');
  if(door) door.classList.add('hidden');
  const btn=document.getElementById('doorModeBtn');
  if(btn){
    btn.classList.remove('bg-blue-600','text-white','border-blue-600');
    btn.classList.add('text-blue-600','border-blue-200');
  }
  setDoorHelpVisibility(false);
}

function renderDoorPlaceholder(message){
  const title=document.getElementById('doorTitle');
  const meta=document.getElementById('doorMeta');
  const content=document.getElementById('doorContent');
  const teleports=document.getElementById('doorTeleports');
  const actions=document.getElementById('doorActions');
  const infoMessage=message && message.trim()!=='' ? `<p class="text-sm text-gray-500">${escapeHtml(message)}</p>` : '';
  const rootId=escapeHtml(doorState.rootId || 'mind-atlas');
  if(title) title.textContent = message || 'Select a room';
  if(meta) meta.textContent = '';
  if(actions){
    actions.innerHTML='';
    actions.classList.add('hidden');
  }
  if(content){
    content.innerHTML = `
      <div class="space-y-4 text-sm text-gray-600">
        ${infoMessage}
        <p><span class="font-semibold">1. Navigate:</span> Use the <strong>Atlas</strong> tree on the left to expand rooms and pick where to go next.</p>
        <p><span class="font-semibold">2. Follow child rooms:</span> Click room cards or teleport buttons to jump to linked spaces.</p>
        <p><span class="font-semibold">3. Edit content:</span> Door mode is a reader. Switch back to the editor panes or use <em>Open Door Builder</em> for full editing.</p>
        <div class="space-y-2">
          <p class="font-semibold text-gray-700">Try an example:</p>
          <ul class="list-disc list-inside space-y-1 text-blue-700">
            <li><button type="button" class="hover:underline focus:outline-none focus:ring-2 focus:ring-blue-300 rounded" data-door-target="${rootId}">Open the root room</button></li>
            <li><span class="text-gray-600">In any room, look for blue links or the teleport list to move deeper.</span></li>
          </ul>
        </div>
      </div>`;
    wireDoorContentLinks();
  }
  if(teleports){
    teleports.innerHTML = '<h4 class="font-semibold text-gray-700 mb-2">Teleports</h4><p class="text-sm text-gray-500">No teleports yet.</p>';
  }
}

async function loadDoorDataset(force=false){
  if(doorState.loading) return;
  if(!force && doorState.ready) return;
  doorState.loading=true;
  const status=document.getElementById('doorStatus');
  if(status) status.textContent='Loading seeds…';
  try{
    const indexData=await fetchDoorJson('DATA/door/index.json');
    const nodeData=await fetchDoorJson('DATA/door/nodes.json');
    const map={};
    (nodeData.nodes||[]).forEach(n=>{ map[n.id]=n; });
    const parents={};
    Object.values(map).forEach(node=>{
      if(!node || !Array.isArray(node.children)) return;
      node.children.forEach(childId=>{
        if(!childId) return;
        if(!parents[childId]) parents[childId]=[];
        if(!parents[childId].includes(node.id)) parents[childId].push(node.id);
      });
    });
    doorState.nodes=map;
    doorState.rootId=indexData.root||'mind-atlas';
    doorState.ready=true;
    doorState.contentCache={};
    doorState.selectedId=null;
    doorState.parents=parents;
    doorState.expanded=new Set([doorState.rootId]);
    if(doorSelectionPending && map[doorSelectionPending]){
      expandDoorAncestors(doorSelectionPending);
    }
    buildDoorTree();
    if(Object.keys(map).length){
      if(doorSelectionPending && map[doorSelectionPending]){
        selectDoorNode(doorSelectionPending);
        doorSelectionPending=null;
      }else{
        selectDoorNode(doorState.rootId);
      }
      if(status){
        const count=Object.keys(map).length;
        status.textContent=`Loaded ${count} room${count===1?'':'s'}.`;
      }
    }else{
      renderDoorPlaceholder('Dataset is empty. Run “Rebuild Seeds”.');
      if(status) status.textContent='Dataset is empty.';
    }
  }catch(err){
    console.error(err);
    doorState.ready=false;
    doorState.expanded=new Set();
    doorState.parents={};
    renderDoorPlaceholder('Unable to load door dataset. Run “Rebuild Seeds”.');
    if(status) status.textContent='Load failed: '+err.message;
  }finally{
    doorState.loading=false;
  }
}

async function fetchDoorJson(path){
  const res=await api('read',{path});
  if(!res.ok) throw new Error('Request failed');
  const data=await res.json();
  if(!data.ok) throw new Error(data.error || `Unable to read ${path}`);
  try{
    return data.content ? JSON.parse(data.content) : {};
  }catch(err){
    throw new Error(`Invalid JSON in ${path}`);
  }
}

async function loadDoorContent(node){
  if(!node || !node.contentPath) return '';
  if(doorState.contentCache[node.id]) return doorState.contentCache[node.id];
  const res=await api('read',{path:node.contentPath});
  if(!res.ok) throw new Error('Request failed');
  const data=await res.json();
  if(!data.ok) throw new Error(data.error || 'Unable to load room content');
  const content=data.content || '';
  doorState.contentCache[node.id]=content;
  return content;
}

function buildDoorTree(){
  const tree=document.getElementById('doorTree');
  if(!tree) return;
  tree.innerHTML='';
  if(!doorState.ready){
    tree.innerHTML='<div class="p-4 text-sm text-gray-500">Dataset not loaded.</div>';
    return;
  }
  if(!doorState.rootId || !doorState.nodes[doorState.rootId]){
    tree.innerHTML='<div class="p-4 text-sm text-gray-500">Root node missing.</div>';
    return;
  }
  if(!(doorState.expanded instanceof Set)) doorState.expanded=new Set();
  doorState.expanded.add(doorState.rootId);
  appendDoorNode(doorState.rootId, tree, 0, new Set(), doorState.expanded);
  highlightDoorTreeSelection();
}

function expandDoorAncestors(id){
  if(!id || !doorState || !doorState.parents) return;
  const visited=new Set();
  let current=id;
  while(current && !visited.has(current)){
    visited.add(current);
    const list=doorState.parents[current];
    if(!Array.isArray(list) || !list.length) break;
    const parentId=list[0];
    if(!parentId) break;
    if(!(doorState.expanded instanceof Set)) doorState.expanded=new Set();
    doorState.expanded.add(parentId);
    current=parentId;
  }
}

function appendDoorNode(id, container, depth, visited, expanded){
  if(!id || visited.has(id)) return;
  const node=doorState.nodes[id];
  if(!node) return;
  const hasChildren=Array.isArray(node.children) && node.children.length>0;
  const row=document.createElement('div');
  row.className='door-node-entry';
  const header=document.createElement('div');
  header.className='door-node-row';
  header.style.paddingLeft=`${depth*16}px`;
  let childWrap=null;
  if(hasChildren){
    if(!(expanded instanceof Set)) expanded=new Set();
    const caret=document.createElement('button');
    caret.type='button';
    caret.className='door-node-caret';
    const isExpanded=expanded.has(id);
    caret.textContent=isExpanded?'▾':'▸';
    caret.setAttribute('aria-label','Toggle children');
    caret.setAttribute('aria-expanded',isExpanded?'true':'false');
    caret.addEventListener('click',ev=>{
      ev.stopPropagation();
      const open=expanded.has(id);
      if(open) expanded.delete(id); else expanded.add(id);
      const nowOpen=!open;
      caret.textContent=nowOpen?'▾':'▸';
      caret.setAttribute('aria-expanded',nowOpen?'true':'false');
      if(childWrap) childWrap.classList.toggle('hidden',!nowOpen);
      highlightDoorTreeSelection();
    });
    header.appendChild(caret);
  }else{
    const spacer=document.createElement('span');
    spacer.className='door-node-spacer';
    spacer.textContent='•';
    header.appendChild(spacer);
  }
  const btn=document.createElement('button');
  btn.type='button';
  btn.dataset.node=id;
  btn.className='door-node-button text-sm';
  btn.textContent=node.title || id;
  btn.addEventListener('click',()=>selectDoorNode(id));
  header.appendChild(btn);
  row.appendChild(header);
  container.appendChild(row);
  const nextVisited=new Set(visited);
  nextVisited.add(id);
  if(hasChildren){
    childWrap=document.createElement('div');
    childWrap.className='door-node-children space-y-1';
    const showChildren=expanded.has(id);
    if(!showChildren) childWrap.classList.add('hidden');
    (node.children||[]).forEach(child=>{
      if(child && child!==id && !nextVisited.has(child)) appendDoorNode(child, childWrap, depth+1, nextVisited, expanded);
    });
    row.appendChild(childWrap);
  }
}

function highlightDoorTreeSelection(){
  const tree=document.getElementById('doorTree');
  if(!tree) return;
  tree.querySelectorAll('.door-node-row').forEach(row=>row.classList.remove('active'));
  tree.querySelectorAll('button[data-node]').forEach(btn=>{
    if(btn.dataset.node===doorState.selectedId){
      btn.classList.add('active');
      const row=btn.closest('.door-node-row');
      if(row) row.classList.add('active');
    }else{
      btn.classList.remove('active');
    }
  });
}

async function selectDoorNode(id){
  if(!doorState.ready) return;
  const node=doorState.nodes[id];
  if(!node) return;
  doorState.selectedId=id;
  highlightDoorTreeSelection();
  const title=document.getElementById('doorTitle');
  const meta=document.getElementById('doorMeta');
  const contentEl=document.getElementById('doorContent');
  const status=document.getElementById('doorStatus');
  const actions=document.getElementById('doorActions');
  if(title) title.textContent=node.title || id;
  if(meta){
    const tags=[];
    if(node.branch) tags.push(node.branch);
    if(node.kind) tags.push(node.kind==='dir'?'Folder':'Entry');
    if(Array.isArray(node.children) && node.children.length){
      const count=node.children.length;
      tags.push(`${count} child${count===1?'':'ren'}`);
    }
    if(node.missing) tags.push('Stub');
    if(node.sourcePath) tags.push(node.sourcePath);
    else if(node.contentPath) tags.push(node.contentPath);
    meta.textContent=tags.join(' · ');
  }
  if(actions){
    actions.innerHTML='';
    const buttons=[];
    if(node.sourcePath){
      const btn=document.createElement('button');
      btn.type='button';
      btn.className='door-action-btn';
      const folderIcon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3.75 6.75A1.5 1.5 0 015.25 5.25h4.028a1.5 1.5 0 011.06.44l1.222 1.22a1.5 1.5 0 001.06.44h5.63a1.5 1.5 0 011.5 1.5v8.25A1.5 1.5 0 0118.25 18H5.25A1.5 1.5 0 013.75 16.5v-9.75z"/></svg>';
      const fileIcon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3.75h4.5L18.75 9v9.75a1.5 1.5 0 01-1.5 1.5H9a1.5 1.5 0 01-1.5-1.5V5.25A1.5 1.5 0 019 3.75z"/><path d="M13.5 3.75V9H18"/></svg>';
      const label=node.kind==='dir'?'Open folder in editor':'Open note in editor';
      btn.innerHTML=`${node.kind==='dir'?folderIcon:fileIcon}<span>${escapeHtml(label)}</span>`;
      btn.addEventListener('click',ev=>{ev.preventDefault(); doorOpenPath(node.sourcePath, node.kind);});
      buttons.push(btn);
    }
    if(buttons.length){
      buttons.forEach(btn=>actions.appendChild(btn));
      actions.classList.remove('hidden');
    }else{
      actions.classList.add('hidden');
    }
  }
  if(status) status.textContent=`Loading ${node.title || id}…`;
  try{
    const content=await loadDoorContent(node);
    if(contentEl){
      const fragment=document.createDocumentFragment();
      const childrenSection=renderDoorChildrenSection(node);
      if(childrenSection) fragment.appendChild(childrenSection);
      const article=document.createElement('article');
      article.className='door-article';
      article.innerHTML=renderDoorMarkdown(content);
      fragment.appendChild(article);
      contentEl.innerHTML='';
      contentEl.appendChild(fragment);
      wireDoorContentLinks();
    }
  }catch(err){
    if(contentEl){
      contentEl.innerHTML='';
      const childrenSection=renderDoorChildrenSection(node);
      if(childrenSection) contentEl.appendChild(childrenSection);
      const errorBox=document.createElement('article');
      errorBox.className='door-article';
      errorBox.innerHTML=`<p class="text-sm text-red-600">${escapeHtml(err.message)}</p>`;
      contentEl.appendChild(errorBox);
    }
  }finally{
    if(status) status.textContent=`Viewing ${node.title || id}`;
  }
  renderDoorTeleports(node);
}

function renderDoorInline(text){
  if(!text) return '';
  const segments=[];
  let last=0; let match;
  const codeRe=/`([^`]+)`/g;
  while((match=codeRe.exec(text))){
    if(match.index>last) segments.push({type:'text', value:text.slice(last, match.index)});
    segments.push({type:'code', value:match[1]});
    last=codeRe.lastIndex;
  }
  if(last<text.length) segments.push({type:'text', value:text.slice(last)});
  return segments.map(seg=>{
    if(seg.type==='code'){
      const nodeId=seg.value.trim();
      if(nodeId && doorState.nodes && doorState.nodes[nodeId]){
        const label=doorState.nodes[nodeId].title || nodeId;
        return `<button type="button" class="door-node-link door-chip" data-door-target="${escapeAttr(nodeId)}">${escapeHtml(label)}</button>`;
      }
      return `<code>${escapeHtml(seg.value)}</code>`;
    }
    let result=''; let pos=0; let linkMatch;
    const linkRe=/\[([^\]]+)\]\(([^)]+)\)/g;
    while((linkMatch=linkRe.exec(seg.value))){
      if(linkMatch.index>pos) result+=escapeHtml(seg.value.slice(pos, linkMatch.index));
      const label=escapeHtml(linkMatch[1]);
      const href=escapeHtml(linkMatch[2]);
      result+=`<a href="${href}" target="_blank" rel="noopener" class="door-chip">${label}</a>`;
      pos=linkRe.lastIndex;
    }
    if(pos<seg.value.length) result+=escapeHtml(seg.value.slice(pos));
    return result;
  }).join('');
}

function wireDoorContentLinks(){
  const content=document.getElementById('doorContent');
  if(!content) return;
  content.querySelectorAll('[data-door-target]').forEach(el=>{
    const targetId=el.getAttribute('data-door-target');
    if(!targetId || !doorState.nodes[targetId] || el.dataset.doorBound==='1') return;
    el.dataset.doorBound='1';
    el.addEventListener('click',()=>selectDoorNode(targetId));
  });
}

function renderDoorMarkdown(md){
  if(!md) return '<p class="text-sm text-gray-500">No content yet.</p>';
  const lines=md.replace(/\r\n/g,'\n').split('\n');
  let html='';
  let inList=false;
  let inCode=false;
  let codeLines=[];
  for(const line of lines){
    if(line.startsWith('```')){
      if(inCode){
        html+=`<pre><code>${escapeHtml(codeLines.join('\n'))}</code></pre>`;
        inCode=false; codeLines=[];
      }else{
        inCode=true; codeLines=[];
      }
      continue;
    }
    if(inCode){ codeLines.push(line); continue; }
    if(/^\s*[-*]\s+/.test(line)){
      if(!inList){ html+='<ul>'; inList=true; }
      html+=`<li>${renderDoorInline(line.replace(/^\s*[-*]\s+/,''))}</li>`;
      continue;
    }
    if(inList){ html+='</ul>'; inList=false; }
    if(/^#{1,3}\s+/.test(line)){
      const level=line.match(/^#{1,3}/)[0].length;
      const text=line.replace(/^#{1,3}\s+/,'');
      html+=`<h${level}>${renderDoorInline(text)}</h${level}>`;
    }else if(/^---+$/.test(line.trim())){
      html+='<hr />';
    }else if(line.trim()===''){
      html+='';
    }else{
      html+=`<p>${renderDoorInline(line)}</p>`;
    }
  }
  if(inList) html+='</ul>';
  if(inCode) html+=`<pre><code>${escapeHtml(codeLines.join('\n'))}</code></pre>`;
  return html;
}

function renderDoorTeleports(node){
  const wrap=document.getElementById('doorTeleports');
  if(!wrap) return;
  wrap.innerHTML='<h4 class="font-semibold text-gray-700 mb-2">Teleports</h4>';
  const links=node.links||[];
  if(!links.length){
    const p=document.createElement('p');
    p.className='text-sm text-gray-500';
    p.textContent='No teleports yet.';
    wrap.appendChild(p);
    return;
  }
  const ul=document.createElement('ul');
  ul.className='door-teleport-list';
  links.forEach(link=>{
    const li=document.createElement('li');
    if(link.type==='node' && doorState.nodes[link.target]){
      const target=doorState.nodes[link.target];
      const btn=document.createElement('button');
      btn.type='button';
      btn.className='door-chip door-node-link';
      btn.textContent=link.title || target.title || link.target;
      btn.onclick=()=>selectDoorNode(link.target);
      li.appendChild(btn);
      if(link.path){
        const meta=document.createElement('div');
        meta.className='door-teleport-meta';
        meta.textContent=link.path;
        li.appendChild(meta);
      }
    }else if(link.type==='url'){
      const a=document.createElement('a');
      a.href=link.target;
      a.target='_blank';
      a.rel='noopener';
      a.className='door-chip';
      a.textContent=link.title || link.target;
      li.appendChild(a);
    }else if(['path','file','folder','structure'].includes((link.type||'').toLowerCase())){
      const btn=document.createElement('button');
      btn.type='button';
      btn.className='door-chip';
      btn.textContent=link.title || link.target;
      const kindHint=(link.nodeKind||link.type||'');
      btn.addEventListener('click',()=>doorOpenPath(link.target, kindHint));
      li.appendChild(btn);
      if(link.target){
        const meta=document.createElement('div');
        meta.className='door-teleport-meta';
        meta.textContent=link.target;
        li.appendChild(meta);
      }
    }else{
      const span=document.createElement('span');
      span.className='text-sm text-gray-600';
      span.textContent=`${link.title || link.target} → ${link.target}`;
      li.appendChild(span);
    }
    ul.appendChild(li);
  });
  wrap.appendChild(ul);
}

function renderDoorChildrenSection(node){
  if(!node || !doorState || !doorState.nodes) return null;
  const ids=Array.isArray(node.children)?node.children:[];
  const seen=new Set();
  const children=[];
  ids.forEach(childId=>{
    if(!childId || seen.has(childId)) return;
    const child=doorState.nodes[childId];
    if(!child) return;
    seen.add(childId);
    children.push(child);
  });
  if(!children.length) return null;
  children.sort((a,b)=>{
    const at=(a.title||a.id||'').toLowerCase();
    const bt=(b.title||b.id||'').toLowerCase();
    return at.localeCompare(bt,'en',{sensitivity:'base'});
  });
  const section=document.createElement('section');
  section.className='door-children';
  const header=document.createElement('div');
  header.className='door-children-header';
  const titleEl=document.createElement('div');
  titleEl.className='door-children-title';
  titleEl.textContent='Child rooms';
  const countEl=document.createElement('div');
  countEl.className='door-children-count';
  countEl.textContent=`${children.length} room${children.length===1?'':'s'}`;
  header.append(titleEl,countEl);
  section.appendChild(header);
  const grid=document.createElement('div');
  grid.className='door-child-grid';
  children.forEach(child=>{
    const card=document.createElement('div');
    card.className='door-child-card';
    const btn=document.createElement('button');
    btn.type='button';
    btn.className='door-child-button';
    btn.addEventListener('click',()=>selectDoorNode(child.id));
    const textWrap=document.createElement('div');
    textWrap.className='flex flex-col gap-1';
    const title=document.createElement('div');
    title.className='door-child-title';
    title.textContent=child.title || child.id;
    textWrap.appendChild(title);
    const metaParts=[];
    if(child.branch) metaParts.push(child.branch);
    if(child.kind) metaParts.push(child.kind==='dir'?'Folder':'Entry');
    if(child.missing) metaParts.push('Stub');
    if(child.sourcePath) metaParts.push(child.sourcePath);
    if(metaParts.length){
      const meta=document.createElement('div');
      meta.className='door-child-meta';
      meta.textContent=metaParts.join(' · ');
      textWrap.appendChild(meta);
    }
    btn.appendChild(textWrap);
    const arrow=document.createElement('span');
    arrow.className='text-blue-500 text-xs font-semibold tracking-wide uppercase self-center';
    arrow.textContent='View';
    btn.appendChild(arrow);
    card.appendChild(btn);
    if(child.sourcePath){
      const open=document.createElement('button');
      open.type='button';
      open.className='door-child-open';
      open.textContent=child.kind==='dir'?'Open folder':'Open note';
      open.addEventListener('click',ev=>{ev.stopPropagation(); doorOpenPath(child.sourcePath, child.kind);});
      card.appendChild(open);
    }
    grid.appendChild(card);
  });
  section.appendChild(grid);
  return section;
}

async function doorOpenPath(path, kind){
  if(!path) return;
  const clean=String(path).replace(/^\/+/, '');
  const hint=(kind||'').toString().toLowerCase();
  let target=clean;
  if(!target){
    exitDoorMode();
    await openDir('');
    return;
  }
  let isDir=hint==='dir' || hint==='folder';
  if(!isDir && hint!=='file' && hint!=='structure'){
    try{
      const res=await api('list',{path:target});
      if(res.ok){
        const data=await res.json();
        if(data && data.ok) isDir=true;
      }
    }catch(err){
      // Ignore; fall back to treating as file.
    }
  }
  exitDoorMode();
  if(isDir){
    await openDir(target);
    return;
  }
  const parts=target.split('/');
  const name=parts.pop() || target;
  const parent=parts.join('/');
  await openDir(parent);
  await openFile(target, name);
}

async function rebuildDoorSeeds(){
  const btn=document.getElementById('doorRebuildBtn');
  const status=document.getElementById('doorStatus');
  if(btn) btn.disabled=true;
  if(status) status.textContent='Rebuilding seeds…';
  try{
    const res=await fetch(`?api=rebuild_door`,{method:'POST',headers:{'X-CSRF':CSRF}});
    if(!res.ok) throw new Error('Request failed');
    const data=await res.json();
    if(!data.ok){
      const msg=(data.stderr||data.error||'Script failed').trim();
      throw new Error(msg||'Script failed');
    }
    doorState.ready=false;
    doorState.nodes={};
    doorState.contentCache={};
    doorState.expanded=new Set();
    if(status) status.textContent=(data.stdout||'Seeds rebuilt.').trim();
    await loadDoorDataset(true);
  }catch(err){
    console.error(err);
    if(status) status.textContent='Rebuild failed: '+err.message;
  }finally{
    if(btn) btn.disabled=false;
  }
}

async function refreshDoorDataset(){
  await loadDoorDataset(true);
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
}
async function init(){
  await focusSelectedPath(selectedPathInfo);
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
  currentDir = rel || '';
  currentFile='';
  crumb(currentDir);
  btns(false); infoBtn.disabled=true; currentFileInfo=null;
  const imgWrap = document.getElementById('imgPreviewWrap');
  if (imgWrap) imgWrap.classList.add('hidden');
  if(ta) ta.classList.remove('hidden');
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
  updateMeta();
}
function jump(){ const p=document.getElementById('meta-file-FIND').value.trim(); openDir(p); }
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
  const nodeTitleRow=document.getElementById('nodeTitleRow');
  if(nodeTitleRow) nodeTitleRow.classList.add('hidden');
  const imgWrap=document.getElementById('imgPreviewWrap');
  const img=document.getElementById('imgPreview');
  infoBtn.disabled=false;
  updateMeta();
  const ext=name.toLowerCase().split('.').pop();
  const isImg=['png','jpg','jpeg','gif','webp','svg'].includes(ext);
  if(isImg){
    if(ta) ta.classList.add('hidden');
    if (imgWrap) imgWrap.classList.remove('hidden');
    if (img) img.src='?api=get_file&path='+encodeURIComponent(rel);
    if(ta){ ta.value=''; ta.disabled=true; }
    btns(false); delBtn.disabled=false; downloadBtn.disabled=false;
    document.getElementById('structTreeBtn').disabled=true;
    if(contentTabs) contentTabs.classList.add('hidden');
    if(window.PreviewService){
      PreviewService.renderWeb({doc:null, docTitle:'', nodes:[], jsonMode:false, helpers:{}});
      PreviewService.renderRaw(null);
      PreviewService.saveRaw(null);
    }
    hideTree();
    return;
  }else{
    if (imgWrap) imgWrap.classList.add('hidden');
    if (img) img.src='';
    if(ta) ta.classList.remove('hidden');
  }
  const r=await (await api('read',{path:rel})).json();
  if (!r.ok) {
    if(ta){ ta.value=''; ta.disabled=true; }
    btns(false); infoBtn.disabled=true;
    if(contentTabs) contentTabs.classList.add('hidden');
    if(window.PreviewService){
      PreviewService.renderWeb({doc:null, docTitle:'', nodes:[], jsonMode:false, helpers:{}});
      PreviewService.renderRaw(null);
      PreviewService.saveRaw(null);
    }
    return;
  }
  if(ta){ ta.value=r.content; ta.disabled=false; } btns(true);
  const extLower=ext.toLowerCase();
  const isStruct=['opml','xml','json'].includes(extLower);
  document.getElementById('structTreeBtn').disabled = !isStruct;
  hideTree();
  if(isStruct){
    if(contentTabs) contentTabs.classList.remove('hidden');
    if(contentPreview) contentPreview.classList.add('hidden');
    try{
      const isJson=extLower==='json';
      if(isJson){
        try{
          const parsed=JSON.parse(r.content);
          if(Array.isArray(parsed)){ currentJsonDoc=null; currentJsonRoot=parsed; currentJsonRootKey=null; }
          else if(Array.isArray(parsed.root)){ currentJsonDoc=parsed; currentJsonRoot=parsed.root; currentJsonRootKey='root'; }
          else if(Array.isArray(parsed.items)){ currentJsonDoc=parsed; currentJsonRoot=parsed.items; currentJsonRootKey='items'; }
          else { currentJsonDoc=parsed; currentJsonRoot=[]; currentJsonRootKey='root'; }
        }catch{ currentJsonDoc=null; currentJsonRoot=[]; currentJsonRootKey=null; }
      } else { currentJsonDoc=null; currentJsonRootKey=null; }
      const endpoint=isJson?'json_tree':'opml_tree';
      const p=await (await api(endpoint,{file:rel})).json();
      if(p.ok){
        if(isJson){
          currentJsonRoot=p.root||currentJsonRoot;
          if(currentJsonDoc) currentJsonDoc[currentJsonRootKey]=currentJsonRoot;
          const metaTitle=document.getElementById('meta-title-STRUCTURE');
          const fname=name.replace(/\.[^/.]+$/,'');
          const rootTitle=getNodeTitle(currentJsonRoot);
          const t=(rootTitle||'').trim() || fname;
          if(metaTitle) metaTitle.value=t;
        }
        const tree=isJson? cjsf_to_ark(currentJsonRoot) : (p.tree||[]);
        state.doc=currentJsonRootKey===null?currentJsonRoot:currentJsonDoc;
        refreshPreview(tree);
        if(window.PreviewService){
          PreviewService.saveRaw(async parsed=>{
            state.doc=parsed;
            if(Array.isArray(parsed)){
              currentJsonDoc=null; currentJsonRoot=parsed; currentJsonRootKey=null;
            }else if(Array.isArray(parsed.root)){
              currentJsonDoc=parsed; currentJsonRoot=parsed.root; currentJsonRootKey='root';
            }else if(Array.isArray(parsed.items)){
              currentJsonDoc=parsed; currentJsonRoot=parsed.items; currentJsonRootKey='items';
            }else{
              currentJsonDoc=parsed; currentJsonRoot=[]; currentJsonRootKey='root';
            }
            await saveDocument(currentFile, parsed);
            emit('documentChanged', parsed);
            refreshPreview(isJson? cjsf_to_ark(currentJsonRoot) : tree);
          });
        }
        emit('documentChanged',state.doc);
      }else{
        const previewEl=document.getElementById('preview-web');
        if(previewEl) previewEl.textContent=p.error||'Structure parse error.';
        if(window.PreviewService) PreviewService.saveRaw(null);
      }
    }catch{
      const previewEl=document.getElementById('preview-web');
      if(previewEl) previewEl.textContent='Structure load error.';
      if(window.PreviewService) PreviewService.saveRaw(null);
    }
  }else{
    if(contentTabs) contentTabs.classList.add('hidden');
    if(contentPreview) contentPreview.classList.add('hidden');
    if(window.PreviewService){
      PreviewService.renderWeb({doc:null, docTitle:'', nodes:[], jsonMode:false, helpers:{}});
      PreviewService.renderRaw(null);
      PreviewService.saveRaw(null);
    }
  }
}
function btns(on){ saveBtn.disabled=!on; delBtn.disabled=!on; downloadBtn.disabled=!on; }
async function save(){
  if(!currentFile) return;
  const taEl=document.getElementById('ta');
  if(!taEl) return;
  const content=taEl.value;
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
    if(ta){ ta.value=''; ta.disabled=true; } btns(false); openDir(currentDir);
  });
}
function downloadFile(){
  if(!currentFile) return;
  const title=document.getElementById('meta-title-CONTENT')?.value || 'download';
  downloadItem(null,currentFile,title);
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
    if(currentFile===rel){ const taEl=document.getElementById('ta'); if(taEl){ taEl.value=''; taEl.disabled=true; } btns(false); currentFile=''; }
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

let selectedLinkIndex=null;

function renderLinkList(){
  if(!linkList) return;
  linkList.innerHTML='';
  (currentLinks||[]).forEach((l,i)=>{
    const li=document.createElement('li');
    li.dataset.index=i;
    const btn=document.createElement('button');
    btn.className='flex items-center gap-1';
    const titleSpan=document.createElement('span');
    titleSpan.className='flex-1 text-left';
    titleSpan.textContent=l.metadata?.title || l.title || l.target || '';
    const editSpan=document.createElement('span');
    editSpan.innerHTML=icons.edit;
    editSpan.className='text-gray-500 hover:text-blue-600';
    editSpan.addEventListener('click',e=>{
      e.stopPropagation();
      selectedLinkIndex=i;
      openLinkModal(selectedId,{
        id:l.id||'',
        type:l.type||'relation',
        title:l.metadata?.title || l.title || '',
        target:l.target||'',
        direction:l.metadata?.direction || l.direction || null
      });
    });
    btn.addEventListener('click',()=>{ selectedLinkIndex=i; updateLinkSelection(); followLink(l); });
    btn.append(titleSpan,editSpan);
    li.appendChild(btn);
    linkList.appendChild(li);
  });
  updateLinkSelection();
}

function updateLinkSelection(){
  if(!linkList) return;
  Array.from(linkList.children).forEach(li=>{
    li.classList.toggle('selected', Number(li.dataset.index)===selectedLinkIndex);
  });
}

if(linkAdd) linkAdd.addEventListener('click',()=>{ selectedLinkIndex=null; openLinkModal(selectedId); });

on('selectionChanged', e=>{
  if(selectedId===null || !contentNote) return;
  const node=findJsonNode(currentJsonRoot,selectedId);
  if(!node) return;
  contentNote.value=getNodeNote(node);
  currentLinks=node.links||[];
  selectedLinkIndex=null;
  renderLinkList();
  updateMeta();
});
function addChild(id){
  modalPrompt('Enter title for new node','',async t=>{
    if(t===null) return;
    if(currentFile.toLowerCase().endsWith('.json')){
      const parent=findJsonNode(currentJsonRoot,id);
      if(!parent) return;
      const now=new Date().toISOString();
      const ref=(parent.children&&parent.children[0])?parent.children[0]:parent;
      const newItem=newNodeLike(ref,t,now);
      parent.children=parent.children||[];
      parent.children.push(newItem);
      const expanded=getExpanded();
      expanded.add(id);
      await saveCurrentJsonStructure();
      renderTree(cjsf_to_ark(currentJsonRoot));
      restoreExpanded(expanded);
      selectNode(newItem.id,getNodeTitle(newItem),getNodeNote(newItem),newItem.links||[]);
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
      const ref=array[index];
      const newItem=newNodeLike(ref,t,now);
      const targetArray=parent?parent.children:currentJsonRoot;
      targetArray.splice(index+1,0,newItem);
      const expanded=getExpanded();
      if(parent) expanded.add(parent.id);
      await saveCurrentJsonStructure();
      renderTree(cjsf_to_ark(currentJsonRoot));
      restoreExpanded(expanded);
      selectNode(newItem.id,getNodeTitle(newItem),getNodeNote(newItem),newItem.links||[]);
    }else{
      nodeOp('add_sibling',{title:t},id);
    }
  });
}
// Remove a node from the current structure. For JSON documents we update the
// in-memory tree and save immediately. For other formats fall back to the
// existing nodeOp API.
function deleteNode(id){
  modalConfirm('Delete node','Delete this node?',async ok=>{
    if(!ok) return;
    if(currentFile.toLowerCase().endsWith('.json')){
      const info=findJsonParent(currentJsonRoot,id);
      if(!info) return;
      const {parent,index}=info;
      const targetArray=parent?parent.children:currentJsonRoot;
      targetArray.splice(index,1);
      await saveCurrentJsonStructure();
      const expanded=getExpanded();
      renderTree(cjsf_to_ark(currentJsonRoot));
      restoreExpanded(expanded);
      selectedId=null;
      const nodeTitleRow=document.getElementById('nodeTitleRow');
      if(nodeTitleRow) nodeTitleRow.classList.add('hidden');
      if(contentPreview) contentPreview.innerHTML='';
      refreshPreview();
    }else{
      nodeOp('delete',{},id);
    }
  });
}
let renameTargetId=null;
function renameNode(id,oldTitle){
  renameTargetId=id;
  modalPrompt('Rename node',oldTitle,async t=>{
    if(t===null) return;
    if(currentFile.toLowerCase().endsWith('.json')){
      const node=findJsonNode(currentJsonRoot,renameTargetId);
      if(node){
        setNodeTitle(node,t);
        node.modified=new Date().toISOString();
        await saveCurrentJsonStructure();
        const expanded=getExpanded();
        renderTree(cjsf_to_ark(currentJsonRoot));
        restoreExpanded(expanded);
        selectNode(node.id,getNodeTitle(node),getNodeNote(node),node.links||[]);
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
      if(currentJsonDoc) currentJsonDoc[currentJsonRootKey]=currentJsonRoot;
    }
    const tree=isJson? cjsf_to_ark(currentJsonRoot) : (r.tree||[]);
    renderTree(tree);
    if(expanded) restoreExpanded(expanded);
  }catch(e){ treeWrap.textContent='Structure load error.'; }
}
function selectNode(id){
  selectedId=id;
  emit('selectionChanged',{id});
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
    if(currentJsonRootKey===null) currentJsonDoc=null;
    else if(currentJsonDoc) currentJsonDoc[currentJsonRootKey]=currentJsonRoot;
    refreshPreview();
    await saveCurrentJsonStructure();
  }
  const sel=treeWrap.querySelector(`div[data-id="${selectedId}"]`);
  if(sel){ sel.scrollIntoView({block:'nearest'}); }
  const n=nodeMap[selectedId];
  if(n){ selectNode(selectedId,n.t,n.note,n.links||[]); }
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
  modal({title: editing?'Edit Link':'Add Link', body:wrap, okText:editing?'Save Link':'Add Link', extra: editing?{text:'Delete Link', onClick:async()=>{ if(currentFile.toLowerCase().endsWith('.json')){ const node=findJsonNode(currentJsonRoot,id); if(node){ node.links=(node.links||[]).filter(l=>l.id!==existing.id); node.modified=new Date().toISOString(); await saveCurrentJsonStructure(); currentLinks=(node.links||[]).map(l=>({id:l.id||'',type:l.type||'',title:l.metadata?.title||'',target:l.target||'',direction:l.metadata?.direction||null})); const expanded=getExpanded(); renderTree(cjsf_to_ark(currentJsonRoot)); restoreExpanded(expanded); selectNode(id,getNodeTitle(node),getNodeNote(node),currentLinks); } } else { await nodeOp('delete_link',{target:existing.target},id); } renderLinkList(); }}:null, onOk:async()=>{
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
      selectNode(id,getNodeTitle(node),getNodeNote(node),currentLinks);
      renderLinkList();
    }else{
      const link={title,type,target,direction: existing?.direction || 'one-way'};
      if(editing) await nodeOp('delete_link',{target:existing.target},id);
      await nodeOp('add_link',{link},id);
      renderLinkList();
    }
  }});
}
on('documentChanged',()=>{
  const expanded=getExpanded();
  renderTree(cjsf_to_ark(currentJsonRoot));
  restoreExpanded(expanded);
  state.doc=currentJsonRootKey===null?currentJsonRoot:currentJsonDoc;
  refreshPreview();
  if(selectedId){
    const n=findJsonNode(currentJsonRoot,selectedId);
    if(n) selectNode(selectedId,getNodeTitle(n),getNodeNote(n),n.links||[]);
  }
  updateMeta();
});
window.addEventListener('DOMContentLoaded',()=>{
  ['FIND','STRUCTURE','CONTENT','PREVIEW'].forEach(applyMetaBindings);
  nodeTitleInput=document.getElementById('nodeTitle');
  if(nodeTitleInput){
    nodeTitleInput.addEventListener('keydown',e=>{ if(e.key==='Enter'){ e.preventDefault(); saveTitle(); }});
    nodeTitleInput.addEventListener('input',()=>{ clearTimeout(saveTitleTimer); saveTitleTimer=setTimeout(saveTitle,500); });
    nodeTitleInput.addEventListener('blur',saveTitle);
  }

  if(contentNote){
    contentNote.addEventListener('input',()=>{
      if(selectedId===null) return;
      const node=findJsonNode(currentJsonRoot,selectedId);
      if(node) setNodeNote(node, contentNote.value);
    });
  }
});
async function saveTitle(){
  if(selectedId===null || !nodeTitleInput) return;
  const title=nodeTitleInput.value.trim();
  await nodeOp('set_title',{title});
}
init();
</script>
</body>
</html>