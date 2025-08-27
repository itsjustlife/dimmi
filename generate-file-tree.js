const fs = require('fs');
const path = require('path');

const ROOT_DIR = path.join(__dirname, 'Art');

function buildTree(dir) {
  return fs.readdirSync(dir, { withFileTypes: true })
    .filter(entry => !entry.name.startsWith('.'))
    .map(entry => {
      const fullPath = path.join(dir, entry.name);
      const relativePath = path.relative(__dirname, fullPath).replace(/\\/g, '/');
      if (entry.isDirectory()) {
        return {
          name: entry.name,
          type: 'folder',
          children: buildTree(fullPath)
        };
      } else {
        return {
          name: entry.name,
          type: 'file',
          path: relativePath
        };
      }
    });
}

const tree = [{
  name: 'Art',
  type: 'folder',
  children: buildTree(ROOT_DIR)
}];

fs.writeFileSync(path.join(__dirname, 'fileTree.json'), JSON.stringify(tree, null, 2));
console.log('fileTree.json generated');
