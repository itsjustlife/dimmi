const fs = require('fs');
const path = require('path');

function walk(dir) {
  return fs.readdirSync(dir).filter(name => !name.startsWith('.') && name !== 'node_modules')
    .map(name => {
      const fullPath = path.join(dir, name);
      const stat = fs.statSync(fullPath);
      if (stat.isDirectory()) {
        return { type: 'folder', name, path: fullPath.replace(process.cwd() + '/', ''), children: walk(fullPath) };
      } else {
        return { type: 'file', name, path: fullPath.replace(process.cwd() + '/', '') };
      }
    });
}

const tree = walk(process.cwd());
fs.writeFileSync('fileTree.json', JSON.stringify(tree, null, 2));
console.log('fileTree.json generated');
