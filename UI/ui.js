const sidebar = document.getElementById('sidebar');
document.getElementById('dimmiBtn').addEventListener('click', () => {
  sidebar.classList.toggle('open');
});

if (typeof fileTree !== 'undefined') {
  buildTree(fileTree, document.getElementById('fileTree'));
} else {
  document.getElementById('content').innerHTML = '<p>Unable to load file tree.</p>';
}

function buildTree(nodes, container) {
  nodes.forEach(node => {
    const item = document.createElement('div');
    item.textContent = node.name;
    item.className = 'item ' + node.type;
    container.appendChild(item);

    if (node.type === 'folder') {
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
        loadFile(node.path);
      });
    }
  });
}

function loadFile(path) {
  const fullPath = '../' + path;
  fetch(fullPath)
    .then(res => res.text())
    .then(text => {
      const summary = text.split(/\n+/).slice(0,3).join(' ').slice(0,200);
      const safeSummary = summary.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
      document.getElementById('content').innerHTML =
        '<h2>' + path + '</h2>' +
        '<p class="summary">' + safeSummary + '</p>' +
        '<p><a href="' + fullPath + '" target="_blank">Open raw file</a></p>' +
        '<iframe class="file-viewer" src="' + fullPath + '"></iframe>';
    })
    .catch(() => {
      document.getElementById('content').innerHTML =
        '<h2>' + path + '</h2><p>Simulated feature: unable to load file.</p>';
    });
}
