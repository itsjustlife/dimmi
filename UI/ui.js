const sidebar = document.getElementById('sidebar');
document.getElementById('dimmiBtn').addEventListener('click', () => {
  sidebar.classList.toggle('open');
});

if (typeof fileTree !== 'undefined') {
  buildTree(fileTree, document.getElementById('fileTree'));
  showSummary('Start.txt', true);
} else {
  document.getElementById('content').innerHTML = '<p>Unable to load file tree.</p>';
}

function buildTree(nodes, container, currentPath = '') {
  nodes.forEach(node => {
    const item = document.createElement('div');
    item.textContent = node.name;
    item.className = 'item ' + node.type;
    container.appendChild(item);

    if (node.type === 'folder') {
      const childrenContainer = document.createElement('div');
      childrenContainer.className = 'children';
      const folderPath = currentPath + node.name;
      item.addEventListener('click', e => {
        e.stopPropagation();
        const open = childrenContainer.classList.toggle('open');
        item.classList.toggle('open', open);
        showSummary(folderPath);
      });
      container.appendChild(childrenContainer);
      buildTree(node.children, childrenContainer, folderPath + '/');
    } else {
      item.addEventListener('click', e => {
        e.stopPropagation();
        showSummary(node.path, true);
      });
    }
  });
}
async function showSummary(path, isFile = false) {
  const safe = (str) =>
    str.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
  const summary = summaries && summaries[path] ? summaries[path] : 'No summary available.';
  const content = document.getElementById('content');
  content.innerHTML = `<h2>${safe(path)}</h2><p class="summary">${safe(summary)}</p>`;
  if (isFile) {
    try {
      const res = await fetch('../' + encodeURI(path));
      if (res.ok) {
        const text = await res.text();
        const editor = document.createElement('textarea');
        editor.id = 'editor';
        editor.className = 'file-viewer';
        editor.value = text;
        const saveBtn = document.createElement('button');
        saveBtn.id = 'saveBtn';
        saveBtn.textContent = 'Save';
        content.appendChild(editor);
        content.appendChild(saveBtn);
        saveBtn.addEventListener('click', async () => {
          const body = JSON.stringify({ path, content: editor.value });
          const resp = await fetch('/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body,
          });
          alert(resp.ok ? 'Saved' : 'Save failed');
        });
      } else {
        content.innerHTML += '<p>Unable to load file content.</p>';
      }
    } catch (err) {
      content.innerHTML += '<p>Unable to load file content.</p>';
    }
  }
}
