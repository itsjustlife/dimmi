const fs = require('fs');
const path = require('path');

// Build a tree for the entire repository (one directory above `UI`).
// We output the tree into `UI/fileTree.js` and a set of quick summaries in
// `UI/summaries.js` so the browser can load them without needing to fetch
// individual files (which may fail when running from the filesystem).
const ROOT_DIR = path.join(__dirname, '..');
const TREE_OUTPUT_FILE = path.join(__dirname, 'fileTree.js');
const SUMMARY_OUTPUT_FILE = path.join(__dirname, 'summaries.js');

// Directories we don't want to expose in the tree.
const IGNORED = new Set(['.git', 'node_modules', 'UI']);

const summaries = {};

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
      const relativePath = path
        .relative(ROOT_DIR, fullPath)
        .replace(/\\/g, '/');
      if (entry.isDirectory()) {
        const children = buildTree(fullPath);
        summaries[relativePath] = `Folder containing ${children.length} items.`;
        return {
          name: entry.name,
          type: 'folder',
          children,
        };
      } else {
        const text = fs.readFileSync(fullPath, 'utf8');
        const summary = text
          .split(/\n+/)
          .slice(0, 3)
          .join(' ')
          .slice(0, 200);
        summaries[relativePath] = summary;
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
  TREE_OUTPUT_FILE,
  'const fileTree = ' + JSON.stringify(tree, null, 2) + ';\n'
);
fs.writeFileSync(
  SUMMARY_OUTPUT_FILE,
  'const summaries = ' + JSON.stringify(summaries, null, 2) + ';\n'
);
console.log('UI/fileTree.js and UI/summaries.js generated');
