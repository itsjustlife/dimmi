const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const ROOT_DIR = __dirname;

function serveFile(filePath, res) {
  fs.readFile(filePath, (err, data) => {
    if (err) {
      res.writeHead(404);
      res.end('Not found');
      return;
    }
    const ext = path.extname(filePath).toLowerCase();
    const type = {
      '.html': 'text/html',
      '.js': 'application/javascript',
      '.css': 'text/css',
      '.txt': 'text/plain',
    }[ext] || 'application/octet-stream';
    res.writeHead(200, { 'Content-Type': type });
    res.end(data);
  });
}

const server = http.createServer((req, res) => {
  const parsed = url.parse(req.url, true);

  if (req.method === 'POST' && parsed.pathname === '/save') {
    let body = '';
    req.on('data', chunk => (body += chunk));
    req.on('end', () => {
      try {
        const { path: filePath, content } = JSON.parse(body);
        if (!filePath) throw new Error('Missing path');
        const safePath = path
          .normalize(filePath)
          .replace(/^\u0000+/, '')
          .replace(/^((\.\.\/)+)/, '');
        const fullPath = path.join(ROOT_DIR, safePath);
        fs.writeFile(fullPath, content, 'utf8', err => {
          if (err) {
            res.writeHead(500);
            res.end('Error saving file');
          } else {
            res.writeHead(200);
            res.end('Saved');
          }
        });
      } catch (e) {
        res.writeHead(400);
        res.end('Bad request');
      }
    });
    return;
  }

  let filePath = path.join(ROOT_DIR, parsed.pathname);
  if (parsed.pathname === '/') {
    filePath = path.join(ROOT_DIR, 'UI/index.html');
  }
  fs.stat(filePath, (err, stats) => {
    if (err) {
      res.writeHead(404);
      res.end('Not found');
      return;
    }
    if (stats.isDirectory()) {
      filePath = path.join(filePath, 'index.html');
    }
    serveFile(filePath, res);
  });
});

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
  console.log(`Server running at http://localhost:${PORT}/UI/index.html`);
});
