(() => {
  const attrEscape = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

  function escapeHtml(str) {
    return (str || '').replace(/[&<>]/g, ch => attrEscape[ch] || ch);
  }

  function escapeAttr(str) {
    return (str || '').replace(/[&<>"']/g, ch => attrEscape[ch] || ch);
  }

  function getExt(path) {
    const idx = (path || '').lastIndexOf('.');
    return idx === -1 ? '' : (path || '').slice(idx + 1).toLowerCase();
  }

  function csrfHeader() {
    const token = window.__csrf || window.__CSRF || '';
    return token ? { 'X-CSRF': token } : {};
  }

  async function fetchJson(url, options = {}) {
    const res = await fetch(url, options);
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data || data.ok === false) {
      const message = (data && data.error) || res.statusText || 'Request failed';
      throw new Error(message);
    }
    return data;
  }

  async function fetchText(url, options = {}) {
    const res = await fetch(url, options);
    if (!res.ok) throw new Error(res.statusText || 'Request failed');
    return res.text();
  }

  function mdLinks(str) {
    return (str || '').replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');
  }

  function createHeading(text) {
    const heading = document.createElement('h1');
    heading.className = 'text-center text-2xl font-bold mb-2';
    heading.textContent = text || 'Preview';
    return heading;
  }

  function renderEmpty(container, message) {
    container.innerHTML = `<p class="text-sm text-gray-500">${escapeHtml(message || 'No preview available.')}</p>`;
  }

  function renderList(container, nodes, helpers = {}) {
    const usedIds = new Set();
    const slug = str => {
      const base = (str || 'section').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'section';
      let id = base;
      let i = 1;
      while (usedIds.has(id)) {
        id = `${base}-${i++}`;
      }
      usedIds.add(id);
      return id;
    };

    const makeTitleEditable = (span, id) => {
      if (!helpers.makeTitleEditable) return;
      helpers.makeTitleEditable(span, id);
    };

    const makeNoteEditable = (div, id, node) => {
      if (!helpers.makeNoteEditable) return;
      helpers.makeNoteEditable(div, id, node);
    };

    const list = document.createElement('div');

    const toc = document.createElement('div');
    toc.className = 'mb-4';
    const tocTitle = document.createElement('div');
    tocTitle.className = 'font-bold mb-2';
    tocTitle.textContent = 'Table of Contents';
    const tocList = document.createElement('ul');
    tocList.className = 'flex flex-wrap gap-4 text-sm';

    nodes.forEach((node, index) => {
      if (!node._id) node._id = slug(node.t || node.title || `section-${index + 1}`);
      const anchor = document.createElement('a');
      anchor.href = `#${node._id}`;
      anchor.textContent = node.t || node.title || `Section ${index + 1}`;
      anchor.className = 'inline-block px-2 py-1 bg-gray-100 rounded hover:bg-gray-200 text-gray-700';
      const li = document.createElement('li');
      li.appendChild(anchor);
      tocList.appendChild(li);
    });

    toc.appendChild(tocTitle);
    toc.appendChild(tocList);
    list.appendChild(toc);
    list.appendChild(document.createElement('hr'));

    const walk = (arr, level) => {
      const ul = document.createElement('ul');
      ul.className = 'list-none space-y-2' + (level ? ' ml-4 pl-4 border-l border-gray-300' : '');
      arr.forEach(node => {
        if (!node) return;
        const li = document.createElement('li');
        li.className = 'mt-2';
        if (level === 0) {
          li.id = node._id || (node._id = slug(node.t || node.title || 'section'));
        }
        const titleButton = document.createElement('button');
        titleButton.type = 'button';
        titleButton.className = 'inline-flex items-center border rounded px-2 py-1 bg-gray-100 hover:bg-gray-200';
        const titleSpan = document.createElement('span');
        titleSpan.textContent = node.t || node.title || '';
        if (level === 0) titleSpan.className = 'font-bold text-lg';
        else if (level === 1) titleSpan.className = 'font-semibold';
        makeTitleEditable(titleSpan, node.id);
        if (node.children && node.children.length) {
          li.className = 'mt-2 border border-gray-300 rounded p-2';
          const caret = document.createElement('span');
          caret.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-4 h-4 text-gray-500 transform transition-transform"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';
          caret.className = 'mr-1';
          titleButton.append(caret, titleSpan);
          const childList = walk(node.children, level + 1);
          childList.style.display = 'none';
          const toggle = () => {
            const open = childList.style.display === 'none';
            childList.style.display = open ? 'block' : 'none';
            const svg = caret.querySelector('svg');
            if (svg) svg.classList.toggle('rotate-90', open);
          };
          let clickCount = 0;
          titleButton.addEventListener('click', () => {
            clickCount++;
            setTimeout(() => {
              if (clickCount === 1) toggle();
              clickCount = 0;
            }, 200);
          });
          li.appendChild(titleButton);
          if (node.note) {
            const noteDiv = document.createElement('div');
            noteDiv.className = 'ml-2 text-gray-600 text-sm';
            noteDiv.innerHTML = mdLinks(escapeHtml(node.note).replace(/\n/g, '<br>'));
            makeNoteEditable(noteDiv, node.id, node);
            li.appendChild(noteDiv);
          }
          if (node.links && node.links.length) {
            const linkDiv = document.createElement('div');
            linkDiv.className = 'ml-2 flex flex-wrap gap-2 text-sm';
            node.links.forEach(link => {
              const anchor = document.createElement('a');
              anchor.textContent = link.title || link.target;
              anchor.href = link.target;
              anchor.dataset.link = JSON.stringify(link);
              anchor.className = 'inline-block px-2 py-1 bg-gray-100 rounded hover:bg-gray-200 text-gray-700';
              linkDiv.appendChild(anchor);
            });
            li.appendChild(linkDiv);
          }
          li.appendChild(childList);
        } else {
          titleButton.appendChild(titleSpan);
          li.appendChild(titleButton);
          if (node.note) {
            const noteDiv = document.createElement('div');
            noteDiv.className = 'ml-2 text-gray-600 text-sm';
            noteDiv.innerHTML = mdLinks(escapeHtml(node.note).replace(/\n/g, '<br>'));
            makeNoteEditable(noteDiv, node.id, node);
            li.appendChild(noteDiv);
          }
          if (node.links && node.links.length) {
            const linkDiv = document.createElement('div');
            linkDiv.className = 'ml-2 flex flex-wrap gap-2 text-sm';
            node.links.forEach(link => {
              const anchor = document.createElement('a');
              anchor.textContent = link.title || link.target;
              anchor.href = link.target;
              anchor.dataset.link = JSON.stringify(link);
              anchor.className = 'inline-block px-2 py-1 bg-gray-100 rounded hover:bg-gray-200 text-gray-700';
              linkDiv.appendChild(anchor);
            });
            li.appendChild(linkDiv);
          }
        }
        ul.appendChild(li);
      });
      return ul;
    };

    list.appendChild(walk(nodes, 0));
    container.innerHTML = '';
    container.appendChild(list);

    if (helpers.attachPreviewLinks) helpers.attachPreviewLinks(container);
  }

  function cjsfToArk(items) {
    function walk(arr) {
      const out = [];
      for (const it of arr || []) {
        if (!it || typeof it !== 'object') continue;
        const title = it.t || it.title || (it.metadata && it.metadata.title) || it.content || it.note || '•';
        const note = it.note || (it.content && (it.title || (it.metadata && it.metadata.title)) ? it.content : '');
        const n = {
          t: title,
          id: it.id || '',
          arkid: it.arkid || it.id || '',
          children: []
        };
        if (note) n.note = note;
        if (Array.isArray(it.links) && it.links.length) n.links = it.links;
        if (Array.isArray(it.children) && it.children.length) n.children = walk(it.children);
        out.push(n);
      }
      return out;
    }
    return walk(items);
  }

  async function fetchStructure(filePath, ext) {
    if (ext === 'json') {
      const data = await fetchJson(`?api=json_tree&file=${encodeURIComponent(filePath)}`);
      const nodes = cjsfToArk(data.root || []);
      return { nodes, title: data.title || '' };
    }
    if (ext === 'opml' || ext === 'xml') {
      const data = await fetchJson(`?api=opml_tree&file=${encodeURIComponent(filePath)}`);
      return { nodes: data.tree || [], title: '' };
    }
    return { nodes: [], title: '' };
  }

  let legacyRawHandler = null;
  let legacyPayload = null;

  async function renderWeb(container, filePath, options = {}) {
    if (arguments.length === 1 && container && typeof container === 'object' && !container.nodeType) {
      // Legacy signature renderWeb(payload)
      legacyPayload = container;
      return;
    }
    if (!container) return;
    const ext = getExt(filePath);
    const helpers = options.helpers || {};
    const docTitle = options.docTitle || '';
    const nodes = Array.isArray(options.nodes) ? options.nodes : null;

    if (['json', 'opml', 'xml'].includes(ext)) {
      try {
        const { nodes: fetchedNodes, title } = nodes ? { nodes, title: docTitle } : await fetchStructure(filePath, ext);
        if (!fetchedNodes.length) {
          renderEmpty(container, 'No preview available.');
          return;
        }
        const heading = createHeading(docTitle || title || filePath.split('/').pop() || 'Preview');
        container.innerHTML = '';
        container.appendChild(heading);
        container.appendChild(document.createElement('hr'));
        renderList(container, fetchedNodes, helpers);
      } catch (err) {
        renderEmpty(container, err.message || 'Unable to render preview.');
      }
      return;
    }

    if (ext === 'md' || ext === 'markdown' || ext === 'txt' || ext === 'html' || ext === 'htm') {
      try {
        const text = await fetchText(`?api=read&path=${encodeURIComponent(filePath)}`).then(res => {
          try {
            const parsed = JSON.parse(res);
            return parsed.content || '';
          } catch {
            return res;
          }
        });
        if (ext === 'html' || ext === 'htm') {
          container.innerHTML = text;
        } else {
          const pre = document.createElement('pre');
          pre.className = 'whitespace-pre-wrap';
          pre.textContent = text;
          container.innerHTML = '';
          container.appendChild(pre);
        }
      } catch (err) {
        renderEmpty(container, err.message || 'Unable to render preview.');
      }
      return;
    }

    renderEmpty(container, 'Preview not supported for this file type.');
  }

  async function loadRaw(filePath) {
    const data = await fetchJson(`?api=read&path=${encodeURIComponent(filePath)}`);
    return data.content || '';
  }

  function renderRawLegacy(doc) {
    legacyPayload = { ...(legacyPayload || {}), doc };
  }

  async function saveRaw(filePath, content) {
    if (typeof filePath === 'function' && content === undefined) {
      legacyRawHandler = filePath;
      return;
    }
    const res = await fetchJson(`?api=write&path=${encodeURIComponent(filePath)}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...csrfHeader()
      },
      body: JSON.stringify({ content })
    });
    return res;
  }

  const listeners = new Set();
  function onDirtyChange(listener) {
    if (typeof listener !== 'function') return () => {};
    listeners.add(listener);
    return () => listeners.delete(listener);
  }

  function notifyDirty(isDirty) {
    listeners.forEach(fn => {
      try { fn(!!isDirty); } catch (err) { console.error(err); }
    });
  }

  const service = {
    renderWeb,
    loadRaw,
    saveRaw,
    onDirtyChange,
    notifyDirty,
    renderRaw: renderRawLegacy
  };

  Object.defineProperty(service, 'legacyRawHandler', {
    get: () => legacyRawHandler,
    set: fn => { legacyRawHandler = typeof fn === 'function' ? fn : null; }
  });

  window.PreviewService = service;
})();
