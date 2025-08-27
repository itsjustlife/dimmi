const sidebar = document.getElementById('sidebar');
document.getElementById('dimmiBtn').addEventListener('click', () => {
  sidebar.classList.toggle('open');
});

if (typeof fileTree !== 'undefined') {
  buildTree(fileTree, document.getElementById('fileTree'));
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
        showSummary(node.path);
      });
    }
  });
}

function showSummary(path) {
  const summary = (typeof summaries !== 'undefined' && summaries[path]) || 'No summary available.';
  const safeSummary = summary.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
  let link = '';
  if (path && !path.endsWith('/')) {
    const fullPath = '../' + path;
    link = '<p><a href="' + fullPath + '" target="_blank">Open file</a></p>';
  }
  document.getElementById('content').innerHTML =
    '<h2>' + path + '</h2>' +
    '<p class="summary">' + safeSummary + '</p>' +
    link;
}
