const express = require('express');
const session = require('express-session');
const path = require('path');
const fs = require('fs');
const fsp = require('fs').promises;
const multer = require('multer');
const crypto = require('crypto');
const xml2js = require('xml2js');

const USER = process.env.WEBEDITOR_USER || 'admin';
const PASS = process.env.WEBEDITOR_PASS || 'admin';
const TITLE = 'Dimmi WebEditor (Node)';
const ROOT = (() => {
  if (process.env.WEBEDITOR_ROOT) {
    return path.resolve(process.env.WEBEDITOR_ROOT);
  }
  const sibling = path.resolve(__dirname, '..', '..', 'dimmi');
  if (fs.existsSync(sibling) && fs.statSync(sibling).isDirectory()) return sibling;
  return path.resolve(__dirname, '..', '..');
})();
const MAX_EDIT = 5 * 1024 * 1024;
const EDIT_EXTS = ['txt','md','markdown','json','yaml','yml','xml','opml','csv','tsv','ini','conf','py','js','ts','css','html','htm','php'];

const app = express();
app.use(session({
  secret: 'dimmi-webeditor',
  resave: false,
  saveUninitialized: false
}));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

function requireAuth(req, res, next){
  if(!req.session.auth) return res.redirect('/');
  next();
}
function bad(res, code, msg){ res.status(code).json({ok:false, error: msg}); }
function safeAbs(rel){
  rel = (rel || '').replace(/^\/+/, '');
  const abs = path.join(ROOT, rel);
  if(!abs.startsWith(ROOT)) return null;
  return abs;
}
function isText(p){
  const ext = path.extname(p).slice(1).toLowerCase();
  return EDIT_EXTS.includes(ext);
}

app.get('/', (req,res)=>{
  if(!req.session.auth){
    return res.sendFile(path.join(__dirname,'login.html'));
  }
  const htmlPath = path.join(__dirname,'index.html');
  fs.readFile(htmlPath,'utf8',(err,data)=>{
    if(err) return res.status(500).send('missing index.html');
    const token = req.session.csrf || crypto.randomBytes(16).toString('hex');
    req.session.csrf = token;
    res.send(data.replace('{{CSRF}}', token).replace('{{TITLE}}', TITLE));
  });
});

app.post('/login',(req,res)=>{
  const {u,p} = req.body;
  if(u === USER && p === PASS){
    req.session.auth = true;
    req.session.csrf = crypto.randomBytes(16).toString('hex');
    res.redirect('/');
  } else {
    res.sendFile(path.join(__dirname,'login.html'));
  }
});

app.get('/logout',(req,res)=>{
  req.session.destroy(()=>res.redirect('/'));
});

function csrf(req,res,next){
  if(req.method === 'POST'){
    const hdr = req.get('X-CSRF') || '';
    if(hdr !== req.session.csrf) return bad(res,403,'CSRF');
  }
  next();
}
app.use('/api', requireAuth, csrf);

app.get('/api/whereami',(req,res)=>{ res.json({ok:true, root: ROOT}); });

app.get('/api/list', async (req,res)=>{
  const abs = safeAbs(req.query.path || '');
  if(!abs || !fs.existsSync(abs) || !fs.statSync(abs).isDirectory()) return bad(res,400,'Not a directory');
  try{
    const items = await fsp.readdir(abs);
    const out = [];
    for(const name of items){
      if(name === '.' || name === '..') continue;
      const p = path.join(abs,name);
      const stat = await fsp.stat(p);
      out.push({name, rel: path.relative(ROOT,p).split(path.sep).join('/'), type: stat.isDirectory()?'dir':'file', size: stat.isFile()?stat.size:0, mtime: Math.floor(stat.mtimeMs/1000)});
    }
    res.json({ok:true, items: out});
  }catch(e){ bad(res,500,'Cannot list'); }
});

app.get('/api/read', async (req,res)=>{
  const abs = safeAbs(req.query.path || '');
  if(!abs || !fs.existsSync(abs) || !fs.statSync(abs).isFile()) return bad(res,400,'Not a file');
  if(!isText(abs)) return bad(res,400,'Not an editable text file');
  const stat = await fsp.stat(abs);
  if(stat.size > MAX_EDIT) return bad(res,400,'Refusing to open files > 5MB');
  const content = await fsp.readFile(abs,'utf8');
  res.json({ok:true, content});
});

app.post('/api/write', async (req,res)=>{
  const abs = safeAbs(req.query.path || '');
  if(!abs || !fs.existsSync(abs) || !fs.statSync(abs).isFile()) return bad(res,400,'Not a file');
  if(!isText(abs)) return bad(res,400,'Not an editable text file');
  try{
    await fsp.writeFile(abs, req.body.content || '', 'utf8');
    res.json({ok:true});
  }catch(e){ bad(res,500,'Write failed'); }
});

app.post('/api/mkdir', async (req,res)=>{
  const name = (req.body.name||'').replace(/\/+$/,'').trim();
  if(!name) return bad(res,400,'Missing name');
  const abs = safeAbs(path.join(req.query.path||'', name));
  if(!abs) return bad(res,400,'Invalid target');
  if(fs.existsSync(abs)) return bad(res,400,'Exists already');
  try{ await fsp.mkdir(abs,{recursive:true}); res.json({ok:true}); }catch(e){ bad(res,500,'mkdir failed'); }
});

app.post('/api/newfile', async (req,res)=>{
  const name = (req.body.name||'').replace(/\/+$/,'').trim();
  if(!name) return bad(res,400,'Missing name');
  const abs = safeAbs(path.join(req.query.path||'', name));
  if(!abs) return bad(res,400,'Invalid target');
  if(fs.existsSync(abs)) return bad(res,400,'Exists already');
  try{
    let content='';
    if(name.toLowerCase().endsWith('.json')){
      content=JSON.stringify({
        schemaVersion:'1.0.0',
        id:crypto.randomUUID(),
        metadata:{title:'New File Title'},
        root:[]
      }, null, 2);
    }
    await fsp.writeFile(abs,content);
    res.json({ok:true});
  }catch(e){ bad(res,500,'newfile failed'); }
});

app.post('/api/delete', async (req,res)=>{
  const abs = safeAbs(req.query.path || '');
  if(!abs || !fs.existsSync(abs)) return bad(res,400,'Not found');
  try{
    const stat = await fsp.stat(abs);
    if(stat.isDirectory()) await fsp.rmdir(abs); else await fsp.unlink(abs);
    res.json({ok:true});
  }catch(e){ bad(res,500,'delete failed'); }
});

app.post('/api/rename', async (req,res)=>{
  const from = safeAbs(req.query.path || '');
  const to = safeAbs(req.body.to || req.body.name || '');
  if(!from || !to) return bad(res,400,'Invalid path');
  try{ await fsp.rename(from,to); res.json({ok:true}); }catch(e){ bad(res,500,'rename failed'); }
});

const storage = multer.diskStorage({
  destination: function(req,file,cb){
    const abs = safeAbs(req.query.path || '');
    if(!abs) return cb(new Error('bad path'));
    fs.mkdir(abs,{recursive:true}, (err)=>cb(err,abs));
  },
  filename: function(req,file,cb){ cb(null, file.originalname); }
});
const upload = multer({storage});

app.post('/api/upload', upload.single('file'), (req,res)=>{
  res.json({ok:true});
});

app.get('/api/opml_tree', async (req,res)=>{
  const abs = safeAbs(req.query.file || '');
  if(!abs || !fs.existsSync(abs)) return bad(res,400,'Not found');
  try{
    const xml = await fsp.readFile(abs,'utf8');
    const parsed = await xml2js.parseStringPromise(xml);
    const outlines = parsed.opml && parsed.opml.body && parsed.opml.body[0] && parsed.opml.body[0].outline || [];
    function walk(nodes){
      return nodes.map(n=>({
        t: n.$?.text || '',
        note: n.$?._note,
        children: n.outline?walk(n.outline):[]
      }));
    }
    const tree = walk(outlines);
    res.json({ok:true, tree});
  }catch(e){ bad(res,400,'OPML parse error'); }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, ()=> console.log('WebEditor Node listening on', PORT));
