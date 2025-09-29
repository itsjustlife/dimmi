(() => {
  const webButton = document.getElementById('preview-web-btn');
  const rawButton = document.getElementById('preview-raw-btn');
  const saveButton = document.getElementById('preview-save');
  const webContainer = document.getElementById('preview-web');
  const rawContainer = document.getElementById('preview-raw');

  const noop = () => {};
  if (!webContainer || !rawContainer || !webButton || !rawButton) {
    window.PreviewService = {
      renderWeb: noop,
      renderRaw: noop,
      saveRaw: noop,
      onDirtyChange: () => noop
    };
    return;
  }

  let previewMode = 'web';
  let dirty = false;
  let rawHandler = null;
  let currentDoc = null;
  let currentPayload = null;
  const dirtyListeners = new Set();

  const attrEscape = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

  function escapeHtml(str) {
    return (str || '').replace(/[&<>]/g, ch => attrEscape[ch] || ch);
  }

  function escapeAttr(str) {
    return (str || '').replace(/[&<>"']/g, ch => attrEscape[ch] || ch);
  }

  function notifyDirty() {
    dirtyListeners.forEach(listener => {
      try {
        listener(dirty);
      } catch (err) {
        console.error(err);
      }
    });
  }

  function setDirty(next) {
    dirty = !!next;
    notifyDirty();
  }

  function ensureButtons() {
    if (previewMode === 'web') {
      webButton.classList.add('bg-gray-200');
      rawButton.classList.remove('bg-gray-200');
      webContainer.classList.remove('hidden');
      rawContainer.classList.add('hidden');
      if (saveButton) saveButton.classList.add('hidden');
    } else {
      rawButton.classList.add('bg-gray-200');
      webButton.classList.remove('bg-gray-200');
      webContainer.classList.add('hidden');
      rawContainer.classList.remove('hidden');
      if (saveButton) saveButton.classList.remove('hidden');
    }
  }

  function makeTitleEditable(span, id, payload, target) {
    if (!payload || !payload.jsonMode) return;
    const helpers = payload.helpers || {};
    const findNode = helpers.findNode || (() => null);
    const setNodeTitle = helpers.setNodeTitle || (() => {});
    const saveStructure = helpers.saveStructure || (() => {});
    const el = target || span;
    const startEdit = event => {
      event.stopPropagation();
      event.preventDefault();
      const input = document.createElement('input');
      input.type = 'text';
      input.value = span.textContent || '';
      span.replaceWith(input);
      input.focus();
      const finish = () => {
        const val = input.value;
        span.textContent = val;
        input.replaceWith(span);
        const node = findNode(id);
        if (node) setNodeTitle(node, val);
        saveStructure();
      };
      input.addEventListener('blur', finish);
      input.addEventListener('keydown', e => {
        if (e.key === 'Enter') finish();
      });
    };
    el.addEventListener('dblclick', startEdit);
    let timer;
    el.addEventListener('mousedown', () => {
      timer = setTimeout(startEdit, 500);
    });
    el.addEventListener('mouseup', () => clearTimeout(timer));
    el.addEventListener('mouseleave', () => clearTimeout(timer));
    el.addEventListener('touchstart', () => {
      timer = setTimeout(startEdit, 500);
    }, { passive: true });
    el.addEventListener('touchend', () => clearTimeout(timer));
    el.addEventListener('touchcancel', () => clearTimeout(timer));
  }

  function makeNoteEditable(div, id, node, payload) {
    if (!payload || !payload.jsonMode) return;
    const helpers = payload.helpers || {};
    const findNode = helpers.findNode || (() => null);
    const setNodeNote = helpers.setNodeNote || (() => {});
    const saveStructure = helpers.saveStructure || (() => {});
    div.addEventListener('click', () => {
      const textarea = document.createElement('textarea');
      textarea.value = node.note || '';
      textarea.className = div.className;
      div.replaceWith(textarea);
      textarea.focus();
      const finish = () => {
        const val = textarea.value;
        const found = findNode(id);
        if (found) setNodeNote(found, val);
        node.note = val;
        const newDiv = document.createElement('div');
        newDiv.className = textarea.className;
        newDiv.innerHTML = (escapeHtml(val)).replace(/\n/g, '<br>');
        makeNoteEditable(newDiv, id, node, payload);
        textarea.replaceWith(newDiv);
        attachPreviewLinks(payload);
        saveStructure();
      };
      textarea.addEventListener('blur', finish);
    });
  }

  function mdLinks(str) {
    return (str || '').replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');
  }

  function attachPreviewLinks(payload) {
    if (!webContainer) return;
    const helpers = (payload && payload.helpers) || {};
    const followLink = helpers.followLink || (() => {});
    const openDir = helpers.openDir || (() => {});
    const openFile = helpers.openFile || (() => {});

    const process = root => {
      root.querySelectorAll('a').forEach(a => {
        if (!a.dataset.link) a.dataset.link = a.getAttribute('href') || '';
      });
    };
    process(webContainer);
    if (attachPreviewLinks.initialized) return;
    attachPreviewLinks.initialized = true;

    const handler = e => {
      const anchor = e.target.closest('a');
      if (!anchor || !webContainer.contains(anchor)) return;
      e.preventDefault();
      const data = anchor.dataset.link;
      if (data) {
        try {
          followLink(JSON.parse(data));
          return;
        } catch (err) {
          console.error(err);
        }
      }
      const href = anchor.getAttribute('href') || '';
      if (/^https?:\/\//i.test(href)) window.open(href, '_blank');
      else if (href.endsWith('/')) openDir(href.replace(/\/$/, ''));
      else openFile(href, href.split('/').pop(), 0, 0);
    };
    webContainer.addEventListener('click', handler);

    new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(node => {
          if (node.nodeType !== 1) return;
          if (node.tagName === 'A' && !node.dataset.link) {
            node.dataset.link = node.getAttribute('href') || '';
          }
          node.querySelectorAll && node.querySelectorAll('a').forEach(anchor => {
            if (!anchor.dataset.link) anchor.dataset.link = anchor.getAttribute('href') || '';
          });
        });
      });
    }).observe(webContainer, { childList: true, subtree: true });
  }

  function performRenderWeb(payload) {
    currentPayload = payload || {};
    const nodes = (payload && payload.nodes) || [];
    const doc = payload && payload.doc;
    const jsonMode = !!(payload && payload.jsonMode);
    const helpers = (payload && payload.helpers) || {};

    webContainer.innerHTML = '';
    if (!nodes || nodes.length === 0) {
      webContainer.innerHTML = '<p class="text-sm text-gray-500">No preview available.</p>';
      return;
    }

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

    const heading = document.createElement('h1');
    heading.className = 'text-center text-2xl font-bold mb-2';
    heading.textContent = payload && payload.docTitle ? payload.docTitle : (doc && doc.metadata && doc.metadata.title) || 'Preview';
    webContainer.appendChild(heading);
    webContainer.appendChild(document.createElement('hr'));

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
    webContainer.appendChild(toc);
    webContainer.appendChild(document.createElement('hr'));

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
        makeTitleEditable(titleSpan, node.id, payload, titleButton);
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
            makeNoteEditable(noteDiv, node.id, node, payload);
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
            makeNoteEditable(noteDiv, node.id, node, payload);
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

    webContainer.appendChild(walk(nodes, 0));
    attachPreviewLinks(payload);
  }

  function performRenderRaw(doc) {
    currentDoc = doc;
    if (!rawContainer) return;
    if (!doc) {
      rawContainer.value = '';
      return;
    }
    try {
      rawContainer.value = JSON.stringify(doc, null, 2);
    } catch (err) {
      rawContainer.value = '';
    }
  }

  webButton.addEventListener('click', () => {
    previewMode = 'web';
    ensureButtons();
  });

  rawButton.addEventListener('click', () => {
    previewMode = 'raw';
    ensureButtons();
    if (currentDoc) performRenderRaw(currentDoc);
  });

  rawContainer.addEventListener('input', () => {
    if (previewMode === 'raw') setDirty(true);
  });

  if (saveButton) {
    saveButton.addEventListener('click', async () => {
      if (previewMode !== 'raw' || !rawHandler) return;
      try {
        const parsed = JSON.parse(rawContainer.value);
        await rawHandler(parsed);
        currentDoc = parsed;
        setDirty(false);
        webButton.click();
      } catch (err) {
        window.alert('Invalid JSON');
      }
    });
  }

    ensureButtons();

  window.PreviewService = {
    renderWeb(payload) {
      performRenderWeb(payload);
    },
    renderRaw(doc) {
      performRenderRaw(doc);
    },
    saveRaw(handler) {
      rawHandler = typeof handler === 'function' ? handler : null;
    },
    onDirtyChange(listener) {
      if (typeof listener !== 'function') return () => {};
      dirtyListeners.add(listener);
      return () => dirtyListeners.delete(listener);
    }
  };
})();
