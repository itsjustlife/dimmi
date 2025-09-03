// Sidebar toggle using the shared "DIMMI" menu button.
const sidebar = document.getElementById('sidebar');
document.getElementById('dimmiBtn').addEventListener('click', () => {
  sidebar.classList.toggle('open');
});

const fileTreeEl = document.getElementById('fileTree');
const editor = document.getElementById('editor');
const saveBtn = document.getElementById('saveBtn');
const loginBtn = document.getElementById('loginBtn');
let currentFile = null;
let token = null;

// Fetch the directory tree from the server and build the sidebar list.
async function loadTree() {
  try {
    const res = await fetch('/api/list?path=.');
    const root = await res.json();
    fileTreeEl.innerHTML = '';
    buildTree(root.children, fileTreeEl);
  } catch {
    fileTreeEl.textContent = 'Failed to load file tree.';
  }
}

// Recursively render folders and files.
function buildTree(nodes, container) {
  nodes.forEach(node => {
    const item = document.createElement('div');
    item.textContent = node.name;
    item.className = 'item ' + node.type;
    container.appendChild(item);

    if (node.type === 'directory') {
      const childrenContainer = document.createElement('div');
      childrenContainer.className = 'children';
      item.addEventListener('click', e => {
        e.stopPropagation();
        const open = childrenContainer.classList.toggle('open');
        item.classList.toggle('open', open);
      });
      container.appendChild(childrenContainer);
      buildTree(node.children, childrenContainer);
    } else {
      item.addEventListener('click', e => {
        e.stopPropagation();
        openFile(node.path);
      });
    }
  });
}

// Load a file's contents into the editor.
async function openFile(path) {
  try {
    const res = await fetch('/api/file?path=' + encodeURIComponent(path));
    if (!res.ok) throw new Error();
    const data = await res.json();
    currentFile = data.path;
    editor.value = data.content;
  } catch {
    alert('Could not open file.');
  }
}

// Send the edited text back to the server.
saveBtn.addEventListener('click', async () => {
  if (!currentFile) {
    alert('No file selected.');
    return;
  }
  if (!token) {
    alert('Please login first.');
    return;
  }
  try {
    const res = await fetch('/api/file', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': token
      },
      body: JSON.stringify({ path: currentFile, content: editor.value })
    });
    alert(res.ok ? 'Saved!' : 'Save failed.');
  } catch {
    alert('Save failed.');
  }
});

// Handle user login and store the returned token.
loginBtn.addEventListener('click', async () => {
  const username = document.getElementById('username').value;
  const password = document.getElementById('password').value;
  try {
    const res = await fetch('/api/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password })
    });
    if (!res.ok) throw new Error();
    const data = await res.json();
    token = data.token;
    alert('Login successful');
  } catch {
    alert('Login failed');
  }
});

loadTree();
