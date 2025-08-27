const fs = require('fs');
const path = require('path');

// Build a tree for the entire repository (one directory above `UI`).
// We output the tree into `UI/fileTree.js` so the browser can load it.
const ROOT_DIR = path.join(__dirname, '..');
const OUTPUT_FILE = path.join(__dirname, 'fileTree.js');

// Directories we don't want to expose in the tree.
const IGNORED = new Set(['.git', 'node_modules', 'UI']);

function buildTree(dir) {
  return fs
    .readdirSync(dir, { withFileTypes: true })
    .filter(
      (entry) => !entry.name.startsWith('.') && !IGNORED.has(entry.name)
    )
    .map((entry) => {
      const fullPath = path.join(dir, entry.name);
      // Paths need to be relative to the repository root so the browser
      // can fetch them using "../" from the UI directory.
      const relativePath = path.relative(ROOT_DIR, fullPath).replace(/\\/g, '/');
      if (entry.isDirectory()) {
        return {
          name: entry.name,
          type: 'folder',
          children: buildTree(fullPath),
        };
      } else {
        return {
          name: entry.name,
          type: 'file',
          path: relativePath,
        };
      }
    });
}

const tree = buildTree(ROOT_DIR);
fs.writeFileSync(
  OUTPUT_FILE,
  'const fileTree = ' + JSON.stringify(tree, null, 2) + ';\n'
);
console.log('UI/fileTree.js generated');
