(() => {
  const bootstrap = window.__DOOR_BOOTSTRAP__ || {};
  const DOOR_READY = !!bootstrap.ready;
  const DOOR = {
    base: bootstrap.base || 'cloud.php',
    csrf: bootstrap.csrf || '',
    url(route, params) {
      const search = new URLSearchParams();
      search.set('mode', 'door');
      if (route) search.set('door', route);
      if (params) {
        Object.entries(params).forEach(([key, value]) => {
          if (value === undefined || value === null) return;
          search.set(key, value);
        });
      }
      const qs = search.toString();
      return `${this.base}${qs ? `?${qs}` : ''}`;
    }
  };

  const state = { currentId: null, breadcrumb: [], links: [], index: [] };
  const statusEl = document.getElementById('door-status');
  const titleInput = document.getElementById('door-room-title');
  const noteInput = document.getElementById('door-room-note');
  const saveBtn = document.getElementById('door-save');
  const addChildBtn = document.getElementById('door-add-child');
  const deleteBtn = document.getElementById('door-delete');
  const linksList = document.getElementById('door-links-list');
  const linkForm = document.getElementById('door-link-form');
  const linkTarget = document.getElementById('door-link-target');
  const linkLabel = document.getElementById('door-link-label');

  if (statusEl) statusEl.textContent = bootstrap.status || '';

  let preventNavigation = false;
  if (window.PreviewService && typeof window.PreviewService.onDirtyChange === 'function') {
    window.PreviewService.onDirtyChange(isDirty => {
      preventNavigation = !!isDirty;
    });
  }

  function confirmNavigation() {
    if (!preventNavigation) return Promise.resolve(true);
    if (!window.confirm('Unsaved changes detected. Continue and lose them?')) return Promise.resolve(false);
    preventNavigation = false;
    return Promise.resolve(true);
  }

  function showStatus(message, isError) {
    if (!statusEl) return;
    statusEl.textContent = message || '';
    statusEl.classList.toggle('error', !!isError);
  }

  function renderLinks() {
    if (!linksList) return;
    linksList.innerHTML = '';
    if (!Array.isArray(state.links) || state.links.length === 0) {
      const empty = document.createElement('li');
      empty.textContent = 'No teleport links yet.';
      empty.style.opacity = '0.7';
      linksList.appendChild(empty);
      return;
    }
    state.links.forEach((link, index) => {
      const li = document.createElement('li');
      const go = document.createElement('button');
      go.type = 'button';
      go.textContent = link.title || link.target;
      go.className = 'door-node-link';
      go.addEventListener('click', async () => {
        if (!(await confirmNavigation())) return;
        loadRoom(link.target);
      });
      li.appendChild(go);
      const meta = document.createElement('span');
      meta.style.flex = '1';
      meta.style.fontSize = '0.85rem';
      meta.style.color = '#94a3b8';
      meta.textContent = link.type || '';
      li.appendChild(meta);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'door-link-remove';
      remove.textContent = 'Remove';
      remove.addEventListener('click', async () => {
        state.links.splice(index, 1);
        renderLinks();
        try {
          await saveCurrent();
        } catch (err) {
          console.error(err);
        }
      });
      li.appendChild(remove);
      linksList.appendChild(li);
    });
  }

  function sanitizeLinks() {
    if (!Array.isArray(state.links)) return [];
    return state.links
      .filter(link => link && link.target)
      .map(link => ({
        target: link.target,
        title: link.title || '',
        type: link.type || ''
      }));
  }

  async function request(route, { method = 'GET', params = null, body = null } = {}) {
    const options = { method, headers: {} };
    let payload = body;
    if (body && typeof body !== 'string') {
      payload = JSON.stringify(body);
      options.headers['Content-Type'] = 'application/json';
    }
    if (method !== 'GET') options.headers['X-CSRF'] = DOOR.csrf;
    if (payload) options.body = payload;
    const res = await fetch(DOOR.url(route, params), options);
    const jsonType = (res.headers.get('content-type') || '').includes('application/json');
    if (!jsonType) {
      const text = await res.text();
      throw new Error(text || 'Unexpected response');
    }
    const data = await res.json();
    if (!res.ok || (data && data.ok === false)) throw new Error((data && data.error) || 'Request failed');
    return data;
  }

  async function loadTemplate(slotId, name) {
    const slot = document.getElementById(slotId);
    if (!slot) return;
    const res = await fetch(DOOR.url('template', { name }));
    if (!res.ok) {
      slot.textContent = 'Template load failed';
      return;
    }
    slot.innerHTML = await res.text();
  }

  function renderBreadcrumb(items) {
    const wrap = document.getElementById('door-breadcrumb');
    if (!wrap) return;
    wrap.innerHTML = '';
    (items || []).forEach((item, index) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = item.title || 'Untitled';
      if (index === (items.length - 1)) {
        btn.disabled = true;
        btn.style.opacity = '0.7';
      }
      btn.addEventListener('click', async () => {
        if (!(await confirmNavigation())) return;
        loadRoom(item.id);
      });
      wrap.appendChild(btn);
      if (index < items.length - 1) {
        const sep = document.createElement('span');
        sep.textContent = '›';
        sep.style.opacity = '0.6';
        wrap.appendChild(sep);
      }
    });
  }

  function renderRail(links) {
    const wrap = document.getElementById('door-rail');
    if (!wrap) return;
    wrap.innerHTML = '';
    if (!Array.isArray(links) || links.length === 0) {
      const span = document.createElement('span');
      span.textContent = 'No teleport links found.';
      span.style.opacity = '0.7';
      wrap.appendChild(span);
      return;
    }
    links.forEach(link => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = link.title || link.target;
      btn.className = 'door-node-link';
      btn.addEventListener('click', async () => {
        if (!(await confirmNavigation())) return;
        loadRoom(link.target);
      });
      wrap.appendChild(btn);
    });
  }

  function escapeHtml(str) {
    return (str || '').replace(/[&<>]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch] || ch));
  }

  function renderGrid(children) {
    const grid = document.getElementById('door-grid');
    if (!grid) return;
    grid.innerHTML = '';
    (children || []).forEach(child => {
      const tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'door-tile';
      tile.innerHTML = `<span>${escapeHtml(child.title || 'Untitled')}</span>`;
      tile.addEventListener('click', async () => {
        if (!(await confirmNavigation())) return;
        loadRoom(child.id);
      });
      grid.appendChild(tile);
    });
    const add = document.createElement('button');
    add.type = 'button';
    add.className = 'door-tile door-add';
    add.textContent = '+';
    add.addEventListener('click', () => addChildPrompt());
    grid.appendChild(add);
  }

  function renderSearchResults(results) {
    const list = document.getElementById('door-search-results');
    if (!list) return;
    list.innerHTML = '';
    results.forEach(item => {
      const li = document.createElement('li');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = item.title || item.id;
      btn.addEventListener('click', async () => {
        if (!(await confirmNavigation())) return;
        loadRoom(item.id);
        list.innerHTML = '';
      });
      li.appendChild(btn);
      list.appendChild(li);
    });
  }

  function initSearch() {
    const form = document.getElementById('door-search');
    if (!form) return;
    form.addEventListener('submit', e => {
      e.preventDefault();
      const input = document.getElementById('door-search-input');
      const query = (input && input.value ? input.value : '').toLowerCase().trim();
      if (!query) {
        renderSearchResults([]);
        return;
      }
      const results = state.index.filter(item => {
        return (item.title || '').toLowerCase().includes(query) || (item.id || '').toLowerCase().includes(query);
      }).slice(0, 20);
      renderSearchResults(results);
    });
  }

  function currentParentId() {
    if (!Array.isArray(state.breadcrumb) || state.breadcrumb.length < 2) return null;
    return state.breadcrumb[state.breadcrumb.length - 2].id || null;
  }

  async function loadRoom(id) {
    try {
      showStatus('Loading...', false);
      const data = await request('data', { params: { id } });
      if (!data.node && data.rootId && data.rootId !== id) {
        await loadRoom(data.rootId);
        return;
      }
      state.currentId = data.node ? data.node.id : null;
      state.breadcrumb = data.breadcrumb || [];
      state.links = (data.links || []).map(link => ({
        target: link.target,
        title: link.title || '',
        type: link.type || ''
      }));
      state.index = data.allNodes || [];
      renderBreadcrumb(state.breadcrumb);
      renderRail(data.links || []);
      renderGrid(data.children || []);
      renderLinks();
      if (titleInput) titleInput.value = data.node ? (data.node.title || '') : '';
      if (noteInput) noteInput.value = data.node ? (data.node.note || '') : '';
      if (deleteBtn) deleteBtn.disabled = !currentParentId();
      showStatus('Loaded ' + (data.node ? (data.node.title || 'room') : 'room'), false);
      if (window.PreviewService && typeof window.PreviewService.renderWeb === 'function') {
        const nodes = Array.isArray(data.children) ? data.children : [];
        window.PreviewService.renderWeb({
          title: data.node ? (data.node.title || '') : '',
          note: data.node ? (data.node.note || '') : '',
          links: data.links || [],
          nodes
        });
        window.PreviewService.renderRaw(data.node || {});
      }
    } catch (err) {
      console.error(err);
      showStatus(err.message, true);
    }
  }

  async function saveCurrent() {
    if (!state.currentId) {
      showStatus('Select a room before saving.', true);
      return;
    }
    try {
      showStatus('Saving...', false);
      await request('update', {
        method: 'POST',
        body: {
          id: state.currentId,
          title: titleInput ? (titleInput.value || '') : '',
          note: noteInput ? (noteInput.value || '') : '',
          links: sanitizeLinks()
        }
      });
      await loadRoom(state.currentId);
      showStatus('Saved.', false);
    } catch (err) {
      console.error(err);
      showStatus(err.message, true);
    }
  }

  async function addChildPrompt() {
    if (!state.currentId) {
      showStatus('Load a room before adding a child.', true);
      return;
    }
    const title = window.prompt('Name for the new room?', 'New Room');
    if (title === null) return;
    try {
      showStatus('Creating room...', false);
      await request('create', {
        method: 'POST',
        body: {
          parent: state.currentId,
          title,
          note: ''
        }
      });
      await loadRoom(state.currentId);
      showStatus('Room created.', false);
    } catch (err) {
      console.error(err);
      showStatus(err.message, true);
    }
  }

  async function deleteCurrent() {
    if (!state.currentId) {
      showStatus('No room selected.', true);
      return;
    }
    if (!window.confirm('Delete this room and its children?')) return;
    try {
      showStatus('Deleting...', false);
      const data = await request('delete', { method: 'POST', body: { id: state.currentId } });
      const next = data.next || currentParentId();
      await loadRoom(next || '');
      showStatus('Room deleted.', false);
    } catch (err) {
      console.error(err);
      showStatus(err.message, true);
    }
  }

  if (saveBtn) saveBtn.addEventListener('click', () => saveCurrent());
  if (addChildBtn) addChildBtn.addEventListener('click', () => addChildPrompt());
  if (deleteBtn) deleteBtn.addEventListener('click', () => deleteCurrent());

  if (linkForm) {
    linkForm.addEventListener('submit', async e => {
      e.preventDefault();
      const target = (linkTarget && linkTarget.value ? linkTarget.value : '').trim();
      if (!target) {
        showStatus('Enter a target room ID for the link.', true);
        return;
      }
      const label = (linkLabel && linkLabel.value ? linkLabel.value : '').trim();
      state.links = state.links || [];
      state.links.push({ target, title: label });
      if (linkTarget) linkTarget.value = '';
      if (linkLabel) linkLabel.value = '';
      renderLinks();
      try {
        await saveCurrent();
      } catch (err) {
        console.error(err);
      }
    });
  }

  Promise.all([
    loadTemplate('door-grid-wrap', 'grid'),
    loadTemplate('door-crumb-wrap', 'breadcrumb'),
    loadTemplate('door-rail-wrap', 'rail'),
    loadTemplate('door-search-wrap', 'search')
  ])
    .then(() => {
      initSearch();
      if (DOOR_READY) loadRoom('');
      else showStatus('Door storage unavailable.', true);
    })
    .catch(err => {
      console.error(err);
      showStatus('Failed to initialize DOOR: ' + err.message, true);
    });
})();
