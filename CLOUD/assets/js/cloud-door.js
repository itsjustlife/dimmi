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
    },
    classicUrl(params) {
      const search = new URLSearchParams();
      search.set('mode', 'classic');
      if (params) {
        Object.entries(params).forEach(([key, value]) => {
          if (value === undefined || value === null || value === '') return;
          search.set(key, value);
        });
      }
      const qs = search.toString();
      return `${this.base}${qs ? `?${qs}` : ''}`;
    }
  };

  const state = { currentId: null, breadcrumb: [], links: [], index: [] };
  const searchState = {
    wrap: null,
    panel: null,
    input: null,
    container: null,
    open: false,
    timer: null,
    token: 0,
    lastQuery: '',
    results: { nodes: [], files: [] }
  };
  const attachState = {
    overlay: null,
    dialog: null,
    body: null,
    typeButtons: new Map(),
    panes: new Map(),
    type: 'relation',
    labelInput: null,
    urlInput: null,
    pathInputs: { file: null, folder: null, structure: null },
    relationSearch: null,
    relationList: null,
    relationEmpty: null,
    selectedRelation: null,
    structurePreview: null,
    structurePreviewPath: '',
    structurePreviewToken: 0,
    structurePreviewTimer: null,
    submitBtn: null,
    cancelBtn: null,
    keyHandler: null,
    editIndex: null,
    editingLink: null,
    submitting: false,
    browser: {
      wrap: null,
      list: null,
      pathLabel: null,
      mode: null,
      path: '',
      upBtn: null,
      closeBtn: null,
      selectBtn: null
    }
  };
  const drawerState = {
    root: null,
    toggle: null,
    close: null,
    scrim: null,
    tabs: { web: null, raw: null },
    save: null,
    status: null,
    open: false,
    toastTimer: null,
    initialized: false,
    lastDirty: false
  };
  let keyboardBound = false;
  let previewScriptPromise = null;
  let detachDirtyWatcher = null;
  let preventNavigation = false;
  const statusEl = document.getElementById('door-status');
  const titleInput = document.getElementById('door-room-title');
  const noteInput = document.getElementById('door-room-note');
  const saveBtn = document.getElementById('door-save');
  const addChildBtn = document.getElementById('door-add-child');
  const deleteBtn = document.getElementById('door-delete');
  const linksList = document.getElementById('door-links-list');
  const attachBtn = document.getElementById('door-attach');
  const childDialogState = {
    wrap: document.getElementById('door-child-dialog'),
    content: document.querySelector('#door-child-dialog .door-child-dialog-content'),
    form: document.getElementById('door-child-form'),
    input: document.getElementById('door-child-name'),
    cancelBtn: document.getElementById('door-child-cancel'),
    createBtn: document.getElementById('door-child-create'),
    open: false,
    keyHandler: null,
    lastFocus: null
  };

  if (statusEl) statusEl.textContent = bootstrap.status || '';

  function showDrawerToast(message, variant = 'info') {
    ensureDrawer();
    if (!drawerState.status) return;
    drawerState.status.textContent = message || '';
    drawerState.status.classList.remove('is-info', 'is-warn', 'is-error', 'is-muted');
    if (!message) {
      drawerState.status.classList.add('is-muted');
      return;
    }
    const map = { info: 'is-info', warn: 'is-warn', error: 'is-error' };
    drawerState.status.classList.add(map[variant] || 'is-info');
    clearTimeout(drawerState.toastTimer);
    drawerState.toastTimer = setTimeout(() => {
      drawerState.status.classList.add('is-muted');
    }, 4000);
  }

  function bindPreviewService() {
    if (!window.PreviewService || typeof window.PreviewService.onDirtyChange !== 'function') return;
    if (detachDirtyWatcher) detachDirtyWatcher();
    detachDirtyWatcher = window.PreviewService.onDirtyChange(isDirty => {
      preventNavigation = !!isDirty;
      const dirty = !!isDirty;
      if (drawerState.lastDirty === dirty) return;
      drawerState.lastDirty = dirty;
      if (dirty) showDrawerToast('Unsaved RAW changes. Save before leaving.', 'warn');
      else showDrawerToast('Preview synced with room.', 'info');
    });
  }

  async function ensurePreviewService() {
    if (window.PreviewService) {
      bindPreviewService();
      return;
    }
    if (!previewScriptPromise) {
      previewScriptPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'assets/js/cloud-classic.js';
        script.async = true;
        script.addEventListener('load', () => {
          bindPreviewService();
          resolve();
        });
        script.addEventListener('error', () => {
          reject(new Error('Preview tools failed to load'));
        });
        document.head.appendChild(script);
      });
    }
    try {
      await previewScriptPromise;
    } catch (err) {
      previewScriptPromise = null;
      showDrawerToast(err.message || 'Preview tools unavailable.', 'error');
    }
  }

  function ensureDrawer() {
    if (!drawerState.root) {
      let root = document.getElementById('door-drawer');
      if (!root) {
        root = document.createElement('aside');
        root.id = 'door-drawer';
        root.className = 'door-drawer';
        root.setAttribute('aria-hidden', 'true');
        root.setAttribute('tabindex', '-1');
        root.innerHTML = `
          <div class="door-drawer-inner">
            <div class="door-drawer-header">
              <h2 class="door-drawer-title">Preview</h2>
              <div class="door-drawer-tabs" role="tablist">
                <button id="preview-web-btn" type="button" class="door-drawer-tab" role="tab" aria-selected="true">Web</button>
                <button id="preview-raw-btn" type="button" class="door-drawer-tab" role="tab" aria-selected="false">Raw</button>
                <button id="preview-save" type="button" class="door-drawer-save hidden">Save</button>
              </div>
              <button type="button" class="door-drawer-close" data-door-drawer-close aria-label="Close preview">×</button>
            </div>
            <div class="door-drawer-status is-muted" role="status" aria-live="polite"></div>
            <div class="door-drawer-body">
              <div id="preview-web" class="door-preview-web"></div>
              <textarea id="preview-raw" class="door-preview-raw hidden" spellcheck="false"></textarea>
            </div>
          </div>
        `;
        document.body.appendChild(root);
      }
      drawerState.root = root;
      drawerState.close = root.querySelector('[data-door-drawer-close]');
      drawerState.tabs.web = root.querySelector('#preview-web-btn');
      drawerState.tabs.raw = root.querySelector('#preview-raw-btn');
      drawerState.save = root.querySelector('#preview-save');
      drawerState.status = root.querySelector('.door-drawer-status');
    }
    if (!drawerState.scrim) {
      let scrim = document.getElementById('door-drawer-scrim');
      if (!scrim) {
        scrim = document.createElement('div');
        scrim.id = 'door-drawer-scrim';
        scrim.className = 'door-drawer-scrim';
        document.body.appendChild(scrim);
      }
      drawerState.scrim = scrim;
    }
    if (!drawerState.toggle) {
      let toggle = document.getElementById('door-drawer-toggle');
      if (!toggle) {
        toggle = document.createElement('button');
        toggle.id = 'door-drawer-toggle';
        toggle.type = 'button';
        toggle.className = 'door-drawer-toggle';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.textContent = 'Preview';
        const header = document.querySelector('header');
        if (header) header.appendChild(toggle);
        else document.body.appendChild(toggle);
      }
      drawerState.toggle = toggle;
    }
  }

  function toggleDrawer(force) {
    ensureDrawer();
    const open = force === undefined ? !drawerState.open : !!force;
    drawerState.open = open;
    if (drawerState.root) {
      drawerState.root.classList.toggle('active', open);
      drawerState.root.setAttribute('aria-hidden', open ? 'false' : 'true');
      if (open) drawerState.root.focus();
    }
    if (drawerState.scrim) drawerState.scrim.classList.toggle('active', open);
    if (drawerState.toggle) drawerState.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('door-drawer-open', open);
  }

  function initDrawer() {
    ensureDrawer();
    if (drawerState.initialized) return;
    drawerState.initialized = true;
    if (drawerState.toggle) drawerState.toggle.addEventListener('click', () => toggleDrawer());
    if (drawerState.close) drawerState.close.addEventListener('click', () => toggleDrawer(false));
    if (drawerState.scrim) drawerState.scrim.addEventListener('click', () => toggleDrawer(false));
    if (drawerState.root) {
      drawerState.root.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
          event.preventDefault();
          toggleDrawer(false);
        }
      });
    }
  }

  function initKeyboardShortcuts() {
    if (keyboardBound) return;
    keyboardBound = true;
    window.addEventListener('keydown', event => {
      if (event.defaultPrevented) return;
      const active = document.activeElement;
      const tag = active && active.tagName ? active.tagName.toLowerCase() : '';
      const typing = tag === 'input' || tag === 'textarea' || (active && active.isContentEditable);
      if (!typing && event.key === '/') {
        event.preventDefault();
        toggleSearchPanel(true);
        if (searchState.input) {
          searchState.input.focus();
          searchState.input.select();
        }
        return;
      }
      if (event.key === 'Escape') {
        if (searchState.open) {
          event.preventDefault();
          toggleSearchPanel(false);
          return;
        }
        if (drawerState.open) {
          event.preventDefault();
          toggleDrawer(false);
          return;
        }
      }
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        saveCurrent();
      }
    });
  }

  function normalizeClassicPath(path) {
    if (!path) return '';
    const cleaned = String(path)
      .replace(/\/g, '/');
    const parts = cleaned.split('/').filter(Boolean);
    return parts.join('/');
  }

  function openClassic(params) {
    const url = DOOR.classicUrl(params);
    if (!url) return;
    window.open(url, '_blank', 'noopener');
  }

  function classicParamsForLink(link) {
    if (!link) return null;
    const type = (link.type || '').toLowerCase();
    const target = link.target || '';
    if (!target) return null;
    if (!type || type === 'relation' || type === 'node') {
      return { selectedId: target };
    }
    if (type === 'folder') {
      const base = normalizeClassicPath(target);
      return base ? { selectedPath: `${base}/` } : null;
    }
    if (type === 'file' || type === 'structure') {
      const path = normalizeClassicPath(target);
      return path ? { selectedPath: path } : null;
    }
    return null;
  }

  function installTileMenu(wrap, items, { label = '⋮', title = 'More actions' } = {}) {
    if (!wrap || !Array.isArray(items) || !items.length) return () => {};
    const menu = document.createElement('div');
    menu.className = 'door-tile-menu';
    items.forEach(item => {
      if (!item || typeof item.action !== 'function') return;
      const entry = document.createElement('button');
      entry.type = 'button';
      entry.className = 'door-tile-menu-item';
      entry.textContent = item.label || 'Action';
      entry.addEventListener('click', event => {
        event.stopPropagation();
        closeMenu();
        try {
          item.action();
        } catch (err) {
          console.error(err);
        }
      });
      menu.appendChild(entry);
    });
    if (!menu.childNodes.length) return () => {};
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'door-tile-action door-tile-more';
    toggle.innerHTML = label;
    toggle.title = title;
    toggle.setAttribute('aria-haspopup', 'true');
    toggle.setAttribute('aria-expanded', 'false');
    wrap.appendChild(toggle);
    wrap.appendChild(menu);

    const outsideHandler = event => {
      if (!wrap.contains(event.target)) closeMenu();
    };

    const keyHandler = event => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        closeMenu();
      }
    };

    function closeMenu() {
      if (!menu.classList.contains('active')) return;
      menu.classList.remove('active');
      toggle.setAttribute('aria-expanded', 'false');
      document.removeEventListener('click', outsideHandler);
      document.removeEventListener('keydown', keyHandler);
    }

    function openMenu() {
      menu.classList.add('active');
      toggle.setAttribute('aria-expanded', 'true');
      document.addEventListener('click', outsideHandler);
      document.addEventListener('keydown', keyHandler);
      const first = menu.querySelector('.door-tile-menu-item');
      if (first && typeof first.focus === 'function') {
        requestAnimationFrame(() => first.focus());
      }
    }

    toggle.addEventListener('click', event => {
      event.stopPropagation();
      if (menu.classList.contains('active')) closeMenu();
      else openMenu();
    });

    menu.addEventListener('click', event => event.stopPropagation());

    return closeMenu;
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

  function toggleSearchPanel(force) {
    if (!searchState.wrap || !searchState.panel) return;
    const open = force === undefined ? !searchState.open : !!force;
    searchState.open = open;
    searchState.panel.classList.toggle('active', open);
    searchState.wrap.style.display = open ? '' : 'none';
    if (open && searchState.input) {
      searchState.input.focus();
      searchState.input.select();
    }
  }

  function setSearchMessage(message) {
    if (!searchState.container) return;
    searchState.container.innerHTML = '';
    const p = document.createElement('p');
    p.className = 'door-search-empty';
    p.textContent = message;
    searchState.container.appendChild(p);
  }

  function makeSearchChip(label, variant) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'door-chip' + (variant === 'file' ? ' door-chip-file' : '');
    btn.textContent = label;
    return btn;
  }

  function renderSearchResults(results) {
    if (!searchState.container) return;
    searchState.container.innerHTML = '';
    const nodes = Array.isArray(results?.nodes) ? results.nodes : [];
    const files = Array.isArray(results?.files) ? results.files : [];
    if (!nodes.length && !files.length) {
      if (searchState.lastQuery) {
        setSearchMessage(`No matches for "${searchState.lastQuery}".`);
      } else {
        setSearchMessage('Type to search rooms and files.');
      }
      return;
    }
    if (nodes.length) {
      const section = document.createElement('div');
      section.className = 'door-search-section';
      const heading = document.createElement('div');
      heading.className = 'door-search-heading';
      heading.textContent = 'Rooms';
      section.appendChild(heading);
      const chips = document.createElement('div');
      chips.className = 'door-search-chips';
      nodes.forEach(node => {
        const title = node.title || node.id || 'Room';
        const chip = makeSearchChip(title);
        chip.title = node.id || title;
        chip.addEventListener('click', async () => {
          if (!(await confirmNavigation())) return;
          try {
            await loadRoom(node.id || '');
          } finally {
            toggleSearchPanel(false);
          }
        });
        chips.appendChild(chip);
      });
      section.appendChild(chips);
      searchState.container.appendChild(section);
    }
    if (files.length) {
      const section = document.createElement('div');
      section.className = 'door-search-section';
      const heading = document.createElement('div');
      heading.className = 'door-search-heading';
      heading.textContent = 'Files';
      section.appendChild(heading);
      const chips = document.createElement('div');
      chips.className = 'door-search-chips';
      files.forEach(file => {
        const name = file.name || file.path || 'File';
        const chip = makeSearchChip(name, 'file');
        const rel = file.path || name;
        chip.title = rel;
        chip.addEventListener('click', async () => {
          if (!(await confirmNavigation())) return;
          try {
            const openDirFn = typeof window.openDir === 'function' ? window.openDir : null;
            const openFileFn = typeof window.openFile === 'function' ? window.openFile : null;
            if (openDirFn) {
              const parts = rel.split('/');
              parts.pop();
              const dir = parts.join('/');
              await openDirFn(dir);
            }
            if (openFileFn) {
              await openFileFn(rel, name, 0, 0);
            }
          } catch (err) {
            console.error(err);
            showStatus(err.message || 'Failed to open file.', true);
          } finally {
            toggleSearchPanel(false);
          }
        });
        chips.appendChild(chip);
      });
      section.appendChild(chips);
      searchState.container.appendChild(section);
    }
  }

  async function performSearch(query) {
    const nextQuery = (query || '').trim();
    searchState.lastQuery = nextQuery;
    if (!nextQuery) {
      searchState.results = { nodes: [], files: [] };
      renderSearchResults(searchState.results);
      return;
    }
    const currentToken = ++searchState.token;
    setSearchMessage('Searching…');
    try {
      const url = DOOR.url('search', {
        q: nextQuery,
        node_limit: '8',
        file_limit: '6'
      });
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const contentType = res.headers.get('content-type') || '';
      const isJson = contentType.includes('application/json');
      const data = isJson ? await res.json() : null;
      if (!res.ok || !data || data.ok === false) {
        const error = data && data.error ? data.error : 'Search failed';
        throw new Error(error);
      }
      if (currentToken !== searchState.token) return;
      searchState.results = {
        nodes: Array.isArray(data.nodes) ? data.nodes.slice(0, 8) : [],
        files: Array.isArray(data.files) ? data.files.slice(0, 6) : []
      };
      renderSearchResults(searchState.results);
    } catch (err) {
      if (currentToken !== searchState.token) return;
      searchState.results = { nodes: [], files: [] };
      setSearchMessage(err.message || 'Search failed.');
      showStatus(err.message || 'Search failed.', true);
    }
  }

  function scheduleSearch() {
    if (!searchState.input) return;
    clearTimeout(searchState.timer);
    searchState.timer = setTimeout(() => performSearch(searchState.input.value), 250);
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
      if (!link) return;
      const li = document.createElement('li');
      const go = document.createElement('button');
      go.type = 'button';
      const buttonLabel = link.label || link.title || link.target || 'Teleport';
      go.textContent = buttonLabel;
      go.className = 'door-node-link';
      go.addEventListener('click', async () => {
        await followLink(link);
      });
      li.appendChild(go);
      const meta = document.createElement('div');
      meta.className = 'door-link-meta';
      const typeLine = document.createElement('span');
      const typeKey = (link.type || 'relation').toLowerCase() === 'node' ? 'relation' : (link.type || 'relation');
      typeLine.textContent = typeKey.toUpperCase();
      const targetLine = document.createElement('span');
      targetLine.textContent = link.target || '';
      meta.appendChild(typeLine);
      meta.appendChild(targetLine);
      li.appendChild(meta);
      const actions = document.createElement('div');
      actions.className = 'door-link-actions';
      const classicParams = classicParamsForLink(link);
      if (classicParams) {
        const classicBtn = document.createElement('button');
        classicBtn.type = 'button';
        classicBtn.className = 'door-link-classic';
        classicBtn.textContent = 'Classic';
        classicBtn.title = 'Open in Classic mode';
        classicBtn.addEventListener('click', event => {
          event.stopPropagation();
          openClassic(classicParams);
        });
        actions.appendChild(classicBtn);
      }
      const edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'door-link-edit';
      edit.textContent = 'Edit';
      edit.addEventListener('click', () => openAttachWizard({ linkIndex: index, link }));
      actions.appendChild(edit);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'door-link-remove';
      remove.textContent = 'Remove';
      remove.addEventListener('click', async () => {
        const previous = Array.isArray(state.links) ? state.links.slice() : [];
        state.links.splice(index, 1);
        renderLinks();
        try {
          await saveCurrent();
        } catch (err) {
          console.error(err);
          state.links = previous;
          renderLinks();
          showStatus(err.message || 'Failed to remove link.', true);
        }
      });
      actions.appendChild(remove);
      li.appendChild(actions);
      linksList.appendChild(li);
    });
  }

  function sanitizeLinks() {
    if (!Array.isArray(state.links)) return [];
    return state.links
      .filter(link => link && link.target)
      .map(link => {
        const clean = { ...link };
        const target = link.target != null ? String(link.target) : '';
        clean.target = target;
        if ('id' in link) clean.id = link.id != null ? String(link.id) : '';
        if ('label' in link) clean.label = link.label != null ? String(link.label) : '';
        if ('title' in link) clean.title = link.title != null ? String(link.title) : '';
        else clean.title = typeof clean.title === 'string' ? clean.title : '';
        if (!clean.label) clean.label = clean.title || target;
        if (!clean.title && typeof clean.label === 'string' && clean.label) clean.title = clean.label;
        if (!clean.id) clean.id = target;
        clean.type = link.type != null ? String(link.type) : '';
        return clean;
      });
  }

  function normalizeLinksForSave(links) {
    if (!Array.isArray(links)) return [];
    const seen = new Set();
    return links
      .map(link => {
        if (!link || typeof link !== 'object') return null;
        const rawTarget = link.target != null ? String(link.target) : '';
        const target = rawTarget.trim();
        if (!target) return null;
        const idSource = link.id != null ? String(link.id) : '';
        const cleanId = (idSource || target).trim();
        if (!cleanId || seen.has(cleanId)) return null;
        seen.add(cleanId);
        const title = link.title != null ? String(link.title) : '';
        const label = link.label != null ? String(link.label) : '';
        const type = link.type != null ? String(link.type) : '';
        const clean = { id: cleanId, target };
        const resolvedLabel = label || title || target;
        clean.label = resolvedLabel;
        if (title) clean.title = title;
        if (type) clean.type = type;
        return clean;
      })
      .filter(Boolean);
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
      const crumbWrap = document.createElement('span');
      crumbWrap.className = 'door-crumb';
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
      crumbWrap.appendChild(btn);
      if (item && item.id) {
        const classicBtn = document.createElement('button');
        classicBtn.type = 'button';
        classicBtn.className = 'door-crumb-classic';
        classicBtn.title = 'Open in Classic';
        classicBtn.textContent = 'Classic';
        classicBtn.addEventListener('click', event => {
          event.stopPropagation();
          openClassic({ selectedId: item.id });
        });
        crumbWrap.appendChild(classicBtn);
      }
      wrap.appendChild(crumbWrap);
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
      btn.textContent = (link && (link.label || link.title)) ? (link.label || link.title) : (link.target || '');
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
    const searchTile = document.createElement('button');
    searchTile.type = 'button';
    searchTile.className = 'door-tile door-search';
    searchTile.setAttribute('aria-label', 'Search rooms and files');
    searchTile.innerHTML = '<span>🔍 Search</span>';
    searchTile.addEventListener('click', () => {
      toggleSearchPanel(true);
      if (searchState.lastQuery) {
        performSearch(searchState.lastQuery);
      } else {
        renderSearchResults(searchState.results);
      }
    });
    grid.appendChild(searchTile);
    (children || []).forEach(child => {
      const wrap = document.createElement('div');
      wrap.className = 'door-tile-wrap';
      let closeTileMenu = () => {};
      const tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'door-tile';
      tile.innerHTML = `<span>${escapeHtml(child.title || 'Untitled')}</span>`;
      tile.addEventListener('click', async () => {
        if (!(await confirmNavigation())) return;
        closeTileMenu();
        loadRoom(child.id);
      });
      wrap.appendChild(tile);
      const menuItems = [
        {
          label: 'Attach teleport',
          action: () => openAttachWizard({ relationTarget: child.id, defaultType: 'relation', defaultLabel: child.title || '' })
        }
      ];
      if (child && child.id) {
        menuItems.push({
          label: 'Open in Classic',
          action: () => openClassic({ selectedId: child.id })
        });
      }
      closeTileMenu = installTileMenu(wrap, menuItems, {
        label: 'Attach',
        title: 'Tile actions'
      });
      grid.appendChild(wrap);
    });
    const add = document.createElement('button');
    add.type = 'button';
    add.className = 'door-tile door-add';
    add.textContent = '+';
    add.addEventListener('click', () => addChildPrompt());
    grid.appendChild(add);
  }

  function initSearch() {
    searchState.wrap = document.getElementById('door-search-wrap');
    if (!searchState.wrap) return;
    searchState.panel = searchState.wrap.querySelector('.door-search-panel');
    searchState.input = document.getElementById('door-search-input');
    searchState.container = document.getElementById('door-search-quick');
    const form = document.getElementById('door-search');
    if (searchState.wrap) searchState.wrap.style.display = 'none';
    if (searchState.panel) searchState.panel.classList.remove('active');
    if (searchState.container) {
      renderSearchResults(searchState.results);
    }
    if (form) {
      form.addEventListener('submit', e => {
        e.preventDefault();
        performSearch(searchState.input ? searchState.input.value : '');
      });
    }
    if (searchState.input) {
      searchState.input.addEventListener('input', () => scheduleSearch());
    }
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
      state.breadcrumb = Array.isArray(data.breadcrumb)
        ? data.breadcrumb.map(item => ({
          id: item && item.id ? String(item.id) : (data.node && data.node.id) || '',
          title: item && item.title ? String(item.title) : ((item && item.id) || 'Room')
        }))
        : [];
      state.links = (data.links || []).map(link => {
        if (!link) return null;
        const clean = { ...link };
        const target = link.target != null ? String(link.target) : '';
        if (!target) return null;
        clean.target = target;
        if (link.id != null) clean.id = String(link.id);
        if (link.label != null) clean.label = String(link.label);
        if (!clean.label) clean.label = (clean.title && String(clean.title)) || target;
        if (link.title != null) clean.title = String(link.title);
        else if (typeof clean.title !== 'string') clean.title = '';
        if (!clean.title && typeof clean.label === 'string' && clean.label) clean.title = clean.label;
        if (!clean.id) clean.id = target;
        clean.type = link.type != null ? String(link.type) : '';
        return clean;
      }).filter(Boolean);
      const nodesIndex = [];
      if (Array.isArray(data.allNodes)) {
        data.allNodes.forEach(node => {
          if (!node || typeof node !== 'object') return;
          const idVal = node.id != null ? String(node.id) : '';
          if (!idVal) return;
          nodesIndex.push({ id: idVal, title: node.title != null ? String(node.title) : idVal });
        });
      } else if (data.allNodes && typeof data.allNodes === 'object') {
        Object.entries(data.allNodes).forEach(([nodeId, nodeTitle]) => {
          if (!nodeId) return;
          nodesIndex.push({ id: String(nodeId), title: nodeTitle != null ? String(nodeTitle) : String(nodeId) });
        });
      }
      state.index = nodesIndex;
      renderBreadcrumb(state.breadcrumb);
      renderRail(state.links);
      renderGrid(data.children || []);
      renderLinks();
      if (titleInput) titleInput.value = data.node ? (data.node.title || '') : '';
      if (noteInput) noteInput.value = data.node ? (data.node.note || '') : '';
      if (deleteBtn) deleteBtn.disabled = !currentParentId();
      showStatus('Loaded ' + (data.node ? (data.node.title || 'room') : 'room'), false);
      await ensurePreviewService();
      if (window.PreviewService && typeof window.PreviewService.renderWeb === 'function') {
        const nodes = Array.isArray(data.children) ? data.children : [];
        window.PreviewService.renderWeb({
          doc: data.node || {},
          docTitle: data.node ? (data.node.title || '') : '',
          links: data.links || [],
          nodes,
          jsonMode: true,
          helpers: {
            followLink: followLink,
            openDir: typeof window.openDir === 'function' ? window.openDir : null,
            openFile: typeof window.openFile === 'function' ? window.openFile : null,
            findNode(idValue) {
              if (!idValue) return null;
              return (state.index || []).find(node => node && node.id === idValue) || null;
            },
            setNodeTitle(node, value) {
              if (node) node.title = value;
            },
            setNodeNote(node, value) {
              if (node) node.note = value;
            },
            saveStructure: () => {}
          }
        });
        window.PreviewService.renderRaw(data.node || {});
        window.PreviewService.saveRaw(async parsed => {
          if (!parsed || typeof parsed !== 'object') return;
          const nextId = parsed.id ? String(parsed.id) : (state.currentId || '');
          if (!nextId) return;
          try {
            showDrawerToast('Saving RAW…', 'info');
            await request('update', {
              method: 'POST',
              body: {
                id: nextId,
                title: parsed.title != null ? String(parsed.title) : '',
                note: parsed.note != null ? String(parsed.note) : '',
                links: normalizeLinksForSave(parsed.links)
              }
            });
            await loadRoom(nextId);
            showDrawerToast('RAW saved.', 'info');
          } catch (err) {
            console.error(err);
            showDrawerToast(err.message || 'RAW save failed.', 'error');
            if (state.currentId) await loadRoom(state.currentId);
          }
        });
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

  function openAddChildDialog() {
    if (!childDialogState.wrap || !childDialogState.content || childDialogState.open) return;
    childDialogState.lastFocus = document.activeElement && typeof document.activeElement.focus === 'function'
      ? document.activeElement
      : null;
    if (childDialogState.form && typeof childDialogState.form.reset === 'function') {
      childDialogState.form.reset();
    }
    childDialogState.wrap.hidden = false;
    childDialogState.wrap.classList.add('active');
    childDialogState.open = true;
    childDialogState.keyHandler = event => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeAddChildDialog();
      }
    };
    document.addEventListener('keydown', childDialogState.keyHandler);
    requestAnimationFrame(() => {
      if (childDialogState.input && typeof childDialogState.input.focus === 'function') {
        childDialogState.input.focus();
        if (typeof childDialogState.input.select === 'function') {
          childDialogState.input.select();
        }
      }
    });
  }

  function closeAddChildDialog() {
    if (!childDialogState.wrap || !childDialogState.open) return;
    childDialogState.wrap.classList.remove('active');
    childDialogState.wrap.hidden = true;
    childDialogState.open = false;
    setChildDialogBusy(false);
    if (childDialogState.form && typeof childDialogState.form.reset === 'function') {
      childDialogState.form.reset();
    }
    if (childDialogState.keyHandler) {
      document.removeEventListener('keydown', childDialogState.keyHandler);
      childDialogState.keyHandler = null;
    }
    if (childDialogState.lastFocus && typeof childDialogState.lastFocus.focus === 'function') {
      requestAnimationFrame(() => {
        if (childDialogState.lastFocus && typeof childDialogState.lastFocus.focus === 'function') {
          childDialogState.lastFocus.focus();
        }
      });
    }
    childDialogState.lastFocus = null;
  }

  function setChildDialogBusy(isBusy) {
    const busy = !!isBusy;
    if (childDialogState.createBtn) childDialogState.createBtn.disabled = busy;
    if (childDialogState.cancelBtn) childDialogState.cancelBtn.disabled = busy;
    if (childDialogState.content) {
      if (busy) childDialogState.content.setAttribute('aria-busy', 'true');
      else childDialogState.content.removeAttribute('aria-busy');
    }
  }

  async function submitChildDialog() {
    if (!state.currentId) {
      showStatus('Load a room before adding a child.', true);
      closeAddChildDialog();
      return;
    }
    const title = childDialogState.input ? childDialogState.input.value.trim() : '';
    if (!title) {
      showStatus('Enter a room name to create a room.', true);
      if (childDialogState.input && typeof childDialogState.input.focus === 'function') {
        childDialogState.input.focus();
        if (typeof childDialogState.input.select === 'function') childDialogState.input.select();
      }
      return;
    }
    setChildDialogBusy(true);
    try {
      showStatus('Creating room...', false);
      await request('create', {
        method: 'POST',
        body: {
          parentId: state.currentId,
          parent: state.currentId,
          title,
          note: ''
        }
      });
      await loadRoom(state.currentId);
      closeAddChildDialog();
      showStatus('Room created.', false);
    } catch (err) {
      console.error(err);
      setChildDialogBusy(false);
      showStatus(err.message, true);
    }
  }

  function addChildPrompt() {
    if (!state.currentId) {
      showStatus('Load a room before adding a child.', true);
      return;
    }
    openAddChildDialog();
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

  function basename(path) {
    if (!path) return '';
    const parts = String(path).split('/').filter(Boolean);
    if (!parts.length) return String(path);
    return parts[parts.length - 1];
  }

  function findNodeTitle(id) {
    if (!id) return '';
    const nodes = Array.isArray(state.index) ? state.index : [];
    const found = nodes.find(node => node && node.id === id);
    return (found && (found.title || '')) || '';
  }

  function resetAttachInputs() {
    if (attachState.labelInput) attachState.labelInput.value = '';
    if (attachState.urlInput) attachState.urlInput.value = '';
    Object.values(attachState.pathInputs).forEach(input => {
      if (input) input.value = '';
    });
    attachState.selectedRelation = null;
    if (attachState.relationSearch) attachState.relationSearch.value = '';
    if (attachState.relationList) attachState.relationList.innerHTML = '';
    if (attachState.relationEmpty) {
      attachState.relationEmpty.style.display = '';
      attachState.relationEmpty.textContent = 'No rooms available.';
    }
    if (attachState.structurePreview) attachState.structurePreview.textContent = 'Choose a structure file to preview.';
    attachState.structurePreviewPath = '';
    attachState.structurePreviewToken = 0;
    clearTimeout(attachState.structurePreviewTimer);
    attachState.structurePreviewTimer = null;
    closePathBrowser();
  }

  function ensureAttachUi() {
    if (attachState.overlay) return;
    const overlay = document.createElement('div');
    overlay.className = 'door-attach-overlay';
    const dialog = document.createElement('div');
    dialog.className = 'door-attach-dialog';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.tabIndex = -1;
    const body = document.createElement('div');
    body.className = 'door-attach-body';
    dialog.appendChild(body);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    attachState.overlay = overlay;
    attachState.dialog = dialog;
    attachState.body = body;
    attachState.keyHandler = event => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeAttachWizard();
      }
    };

    overlay.addEventListener('click', event => {
      if (event.target === overlay) closeAttachWizard();
    });

    const heading = document.createElement('h2');
    heading.textContent = 'Attach teleport';
    body.appendChild(heading);

    const intro = document.createElement('p');
    intro.className = 'door-attach-hint';
    intro.textContent = 'Choose what to connect to this room.';
    body.appendChild(intro);

    const typeList = document.createElement('div');
    typeList.className = 'door-attach-types';
    body.appendChild(typeList);

    const typeOptions = [
      { key: 'file', title: 'File', hint: 'Link to a file from FIND' },
      { key: 'folder', title: 'Folder', hint: 'Jump into a directory' },
      { key: 'url', title: 'URL', hint: 'Open an external link' },
      { key: 'structure', title: 'Structure', hint: 'Preview OPML/JSON structures' },
      { key: 'relation', title: 'Relation', hint: 'Teleport to another room' }
    ];
    typeOptions.forEach(option => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'door-attach-type';
      btn.dataset.type = option.key;
      btn.innerHTML = `<strong>${option.title}</strong><span>${option.hint}</span>`;
      btn.addEventListener('click', () => setAttachType(option.key));
      typeList.appendChild(btn);
      attachState.typeButtons.set(option.key, btn);
    });

    const labelField = document.createElement('div');
    labelField.className = 'door-attach-field';
    const labelLabel = document.createElement('label');
    labelLabel.textContent = 'Label';
    const labelInput = document.createElement('input');
    labelInput.placeholder = 'Optional label';
    labelInput.addEventListener('input', () => updateAttachSubmitState());
    labelField.appendChild(labelLabel);
    labelField.appendChild(labelInput);
    body.appendChild(labelField);
    attachState.labelInput = labelInput;

    const makePane = (key, render) => {
      const pane = document.createElement('div');
      pane.className = 'door-attach-pane';
      pane.dataset.type = key;
      render(pane);
      attachState.panes.set(key, pane);
      body.appendChild(pane);
    };

    makePane('file', pane => {
      const field = document.createElement('div');
      field.className = 'door-attach-field';
      const label = document.createElement('label');
      label.textContent = 'File path';
      const wrap = document.createElement('div');
      wrap.className = 'door-attach-path';
      const input = document.createElement('input');
      input.placeholder = 'path/to/file.ext';
      input.addEventListener('input', () => updateAttachSubmitState());
      const browse = document.createElement('button');
      browse.type = 'button';
      browse.textContent = 'Browse';
      browse.addEventListener('click', () => openPathBrowser('file', input.value));
      wrap.appendChild(input);
      wrap.appendChild(browse);
      field.appendChild(label);
      field.appendChild(wrap);
      pane.appendChild(field);
      const hint = document.createElement('div');
      hint.className = 'door-attach-hint';
      hint.textContent = 'Select a file using the FIND picker or paste a path.';
      pane.appendChild(hint);
      attachState.pathInputs.file = input;
    });

    makePane('folder', pane => {
      const field = document.createElement('div');
      field.className = 'door-attach-field';
      const label = document.createElement('label');
      label.textContent = 'Folder path';
      const wrap = document.createElement('div');
      wrap.className = 'door-attach-path';
      const input = document.createElement('input');
      input.placeholder = 'path/to/folder';
      input.addEventListener('input', () => updateAttachSubmitState());
      const browse = document.createElement('button');
      browse.type = 'button';
      browse.textContent = 'Browse';
      browse.addEventListener('click', () => openPathBrowser('folder', input.value));
      wrap.appendChild(input);
      wrap.appendChild(browse);
      field.appendChild(label);
      field.appendChild(wrap);
      pane.appendChild(field);
      const hint = document.createElement('div');
      hint.className = 'door-attach-hint';
      hint.textContent = 'Pick a directory to teleport into.';
      pane.appendChild(hint);
      attachState.pathInputs.folder = input;
    });

    makePane('url', pane => {
      const field = document.createElement('div');
      field.className = 'door-attach-field';
      const label = document.createElement('label');
      label.textContent = 'URL';
      const input = document.createElement('input');
      input.type = 'url';
      input.placeholder = 'https://example.com';
      input.addEventListener('input', () => updateAttachSubmitState());
      field.appendChild(label);
      field.appendChild(input);
      pane.appendChild(field);
      attachState.urlInput = input;
    });

    makePane('structure', pane => {
      const field = document.createElement('div');
      field.className = 'door-attach-field';
      const label = document.createElement('label');
      label.textContent = 'Structure file';
      const wrap = document.createElement('div');
      wrap.className = 'door-attach-path';
      const input = document.createElement('input');
      input.placeholder = 'path/to/structure.json';
      input.addEventListener('input', () => {
        updateAttachSubmitState();
        scheduleStructurePreview();
      });
      const browse = document.createElement('button');
      browse.type = 'button';
      browse.textContent = 'Browse';
      browse.addEventListener('click', () => openPathBrowser('structure', input.value));
      wrap.appendChild(input);
      wrap.appendChild(browse);
      field.appendChild(label);
      field.appendChild(wrap);
      pane.appendChild(field);
      const preview = document.createElement('pre');
      preview.className = 'door-attach-structure-preview';
      preview.textContent = 'Choose a structure file to preview.';
      pane.appendChild(preview);
      attachState.pathInputs.structure = input;
      attachState.structurePreview = preview;
    });

    makePane('relation', pane => {
      const field = document.createElement('div');
      field.className = 'door-attach-field';
      const label = document.createElement('label');
      label.textContent = 'Find a room';
      const input = document.createElement('input');
      input.placeholder = 'Search rooms…';
      input.addEventListener('input', () => updateRelationList());
      field.appendChild(label);
      field.appendChild(input);
      pane.appendChild(field);
      const listWrap = document.createElement('div');
      listWrap.className = 'door-attach-relations';
      const list = document.createElement('div');
      list.className = 'door-attach-relations-list';
      const empty = document.createElement('div');
      empty.className = 'door-attach-relations-empty';
      empty.textContent = 'No rooms available.';
      listWrap.appendChild(list);
      listWrap.appendChild(empty);
      pane.appendChild(listWrap);
      attachState.relationSearch = input;
      attachState.relationList = list;
      attachState.relationEmpty = empty;
    });

    const browser = document.createElement('div');
    browser.className = 'door-attach-browser';
    const browserHeader = document.createElement('div');
    browserHeader.className = 'door-attach-browser-header';
    const browserPath = document.createElement('div');
    browserPath.className = 'door-attach-browser-path';
    browserHeader.appendChild(browserPath);
    const browserControls = document.createElement('div');
    browserControls.className = 'door-attach-browser-controls';
    const upBtn = document.createElement('button');
    upBtn.type = 'button';
    upBtn.textContent = 'Up';
    upBtn.addEventListener('click', () => {
      const current = attachState.browser.path || '';
      if (!current) return;
      const parts = current.split('/').filter(Boolean);
      parts.pop();
      loadBrowserPath(parts.join('/'));
    });
    const selectBtn = document.createElement('button');
    selectBtn.type = 'button';
    selectBtn.textContent = 'Use this folder';
    selectBtn.addEventListener('click', () => {
      if (attachState.browser.mode !== 'folder') return;
      setPathForMode('folder', attachState.browser.path || '');
      closePathBrowser();
    });
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.textContent = 'Close';
    closeBtn.addEventListener('click', () => closePathBrowser());
    browserControls.appendChild(upBtn);
    browserControls.appendChild(selectBtn);
    browserControls.appendChild(closeBtn);
    browserHeader.appendChild(browserControls);
    const browserList = document.createElement('ul');
    browserList.className = 'door-attach-browser-list';
    browser.appendChild(browserHeader);
    browser.appendChild(browserList);
    body.appendChild(browser);

    attachState.browser.wrap = browser;
    attachState.browser.list = browserList;
    attachState.browser.pathLabel = browserPath;
    attachState.browser.upBtn = upBtn;
    attachState.browser.closeBtn = closeBtn;
    attachState.browser.selectBtn = selectBtn;

    const actions = document.createElement('div');
    actions.className = 'door-attach-actions';
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'door-attach-cancel';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', () => closeAttachWizard());
    const submitBtn = document.createElement('button');
    submitBtn.type = 'button';
    submitBtn.className = 'door-attach-submit';
    submitBtn.textContent = 'Save link';
    submitBtn.disabled = true;
    submitBtn.addEventListener('click', () => handleAttachSubmit());
    actions.appendChild(cancelBtn);
    actions.appendChild(submitBtn);
    dialog.appendChild(actions);

    attachState.cancelBtn = cancelBtn;
    attachState.submitBtn = submitBtn;
  }

  function setAttachType(type) {
    ensureAttachUi();
    const key = (type || '').toLowerCase() === 'node' ? 'relation' : (type || '').toLowerCase();
    const activeKey = attachState.panes.has(key) ? key : 'relation';
    attachState.type = activeKey;
    attachState.typeButtons.forEach((btn, btnKey) => {
      btn.classList.toggle('active', btnKey === activeKey);
    });
    attachState.panes.forEach((pane, paneKey) => {
      pane.classList.toggle('active', paneKey === activeKey);
    });
    if (activeKey === 'relation') updateRelationList();
    if (activeKey === 'structure') updateStructurePreview(true);
    updateAttachSubmitState();
  }

  function getAttachTarget() {
    const type = attachState.type;
    if (type === 'relation') return attachState.selectedRelation || '';
    if (type === 'url') return attachState.urlInput ? attachState.urlInput.value.trim() : '';
    if (type === 'folder') return attachState.pathInputs.folder ? attachState.pathInputs.folder.value.trim() : '';
    if (type === 'structure') return attachState.pathInputs.structure ? attachState.pathInputs.structure.value.trim() : '';
    return attachState.pathInputs.file ? attachState.pathInputs.file.value.trim() : '';
  }

  function updateAttachSubmitState() {
    if (!attachState.submitBtn) return;
    if (attachState.submitting) {
      attachState.submitBtn.disabled = true;
      return;
    }
    attachState.submitBtn.disabled = !getAttachTarget();
  }

  function updateRelationList() {
    if (!attachState.relationList) return;
    const nodes = Array.isArray(state.index) ? state.index : [];
    const query = (attachState.relationSearch ? attachState.relationSearch.value : '').trim().toLowerCase();
    const matches = nodes
      .filter(node => {
        if (!node) return false;
        if (!query) return true;
        const title = (node.title || '').toLowerCase();
        const id = (node.id || '').toLowerCase();
        return title.includes(query) || id.includes(query);
      })
      .slice(0, 80);
    attachState.relationList.innerHTML = '';
    if (!matches.length) {
      if (attachState.relationEmpty) {
        attachState.relationEmpty.style.display = '';
        attachState.relationEmpty.textContent = nodes.length ? 'No rooms match your search.' : 'No rooms available.';
      }
    } else {
      if (attachState.relationEmpty) attachState.relationEmpty.style.display = 'none';
      matches.forEach(node => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = node.title || node.id || 'Room';
        if (node.id === attachState.selectedRelation) btn.classList.add('active');
        btn.addEventListener('click', () => {
          attachState.selectedRelation = node.id || '';
          if (attachState.labelInput && !attachState.labelInput.value.trim()) {
            attachState.labelInput.value = node.title || '';
          }
          updateRelationList();
          updateAttachSubmitState();
        });
        attachState.relationList.appendChild(btn);
      });
    }
    updateAttachSubmitState();
  }

  function scheduleStructurePreview() {
    clearTimeout(attachState.structurePreviewTimer);
    attachState.structurePreviewTimer = setTimeout(() => updateStructurePreview(true), 250);
  }

  async function updateStructurePreview(force) {
    if (!attachState.structurePreview || attachState.type !== 'structure') return;
    const input = attachState.pathInputs.structure;
    const path = input ? input.value.trim() : '';
    if (!path) {
      attachState.structurePreview.textContent = 'Choose a structure file to preview.';
      attachState.structurePreviewPath = '';
      return;
    }
    if (!force && attachState.structurePreviewPath === path) return;
    attachState.structurePreviewPath = path;
    const token = ++attachState.structurePreviewToken;
    attachState.structurePreview.textContent = 'Loading preview…';
    try {
      const params = new URLSearchParams();
      params.set('api', 'read');
      params.set('path', path);
      const res = await fetch(`${DOOR.base}?${params.toString()}`, { headers: { 'Accept': 'application/json' } });
      const contentType = res.headers.get('content-type') || '';
      const data = contentType.includes('application/json') ? await res.json() : null;
      if (token !== attachState.structurePreviewToken) return;
      if (!res.ok || !data || data.ok === false) {
        const message = (data && data.error) || 'Unable to load preview.';
        throw new Error(message);
      }
      const content = typeof data.content === 'string' ? data.content : '';
      const trimmed = content.length > 2000 ? `${content.slice(0, 2000)}\n…` : content;
      attachState.structurePreview.textContent = trimmed || 'File is empty.';
    } catch (err) {
      if (token !== attachState.structurePreviewToken) return;
      attachState.structurePreview.textContent = err.message || 'Unable to load preview.';
    }
  }

  function closePathBrowser() {
    if (!attachState.browser.wrap) return;
    attachState.browser.wrap.classList.remove('active');
    attachState.browser.mode = null;
    attachState.browser.path = '';
    if (attachState.browser.list) attachState.browser.list.innerHTML = '';
  }

  function setPathForMode(mode, value) {
    const normalized = (value || '').replace(/\\/g, '/').replace(/\/+/g, '/').replace(/\/$/, '');
    if (mode === 'folder') {
      if (attachState.pathInputs.folder) attachState.pathInputs.folder.value = normalized;
    } else if (mode === 'structure') {
      if (attachState.pathInputs.structure) attachState.pathInputs.structure.value = normalized;
    } else {
      if (attachState.pathInputs.file) attachState.pathInputs.file.value = normalized;
    }
    if (attachState.labelInput && !attachState.labelInput.value.trim()) {
      const name = basename(normalized);
      if (name) attachState.labelInput.value = name;
    }
    if (mode === 'structure') updateStructurePreview(true);
    updateAttachSubmitState();
  }

  async function loadBrowserPath(path) {
    if (!attachState.browser.wrap) return;
    const normalized = (path || '').replace(/\\/g, '/').replace(/\/+/g, '/').replace(/\/$/, '');
    attachState.browser.path = normalized;
    if (attachState.browser.pathLabel) attachState.browser.pathLabel.textContent = normalized ? `/${normalized}` : '/';
    if (attachState.browser.upBtn) attachState.browser.upBtn.disabled = !normalized;
    if (attachState.browser.list) {
      attachState.browser.list.innerHTML = '';
      const loading = document.createElement('div');
      loading.className = 'door-attach-browser-empty';
      loading.textContent = 'Loading…';
      attachState.browser.list.appendChild(loading);
    }
    try {
      const params = new URLSearchParams();
      params.set('api', 'list');
      if (normalized) params.set('path', normalized);
      const res = await fetch(`${DOOR.base}?${params.toString()}`, { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      if (!res.ok || !data || data.ok === false) {
        const message = (data && data.error) || 'Unable to read directory.';
        throw new Error(message);
      }
      renderBrowserEntries(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      console.error(err);
      if (attachState.browser.list) {
        attachState.browser.list.innerHTML = '';
        const errorEl = document.createElement('div');
        errorEl.className = 'door-attach-browser-empty';
        errorEl.textContent = err.message || 'Unable to read directory.';
        attachState.browser.list.appendChild(errorEl);
      }
    }
  }

  function renderBrowserEntries(items) {
    if (!attachState.browser.list) return;
    const mode = attachState.browser.mode || 'file';
    attachState.browser.list.innerHTML = '';
    const dirs = [];
    const files = [];
    items.forEach(item => {
      if (!item) return;
      if (item.type === 'dir') dirs.push(item);
      else if (item.type === 'file') files.push(item);
    });
    dirs.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    files.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    const showFiles = mode !== 'folder';
    if (!dirs.length && (!showFiles || !files.length)) {
      const empty = document.createElement('div');
      empty.className = 'door-attach-browser-empty';
      empty.textContent = 'Nothing found in this directory.';
      attachState.browser.list.appendChild(empty);
      return;
    }
    dirs.forEach(entry => {
      const li = document.createElement('li');
      const openBtn = document.createElement('button');
      openBtn.type = 'button';
      openBtn.textContent = `📁 ${entry.name || ''}`;
      openBtn.addEventListener('click', () => loadBrowserPath(entry.rel || entry.name || ''));
      li.appendChild(openBtn);
      if (mode === 'folder') {
        const choose = document.createElement('button');
        choose.type = 'button';
        choose.className = 'door-attach-browser-choose';
        choose.textContent = 'Select';
        choose.addEventListener('click', () => {
          setPathForMode('folder', entry.rel || entry.name || '');
          closePathBrowser();
        });
        li.appendChild(choose);
      }
      attachState.browser.list.appendChild(li);
    });
    if (showFiles) {
      files.forEach(entry => {
        const li = document.createElement('li');
        const choose = document.createElement('button');
        choose.type = 'button';
        choose.textContent = `📄 ${entry.name || ''}`;
        choose.addEventListener('click', () => {
          const modeKey = mode === 'structure' ? 'structure' : 'file';
          setPathForMode(modeKey, entry.rel || entry.name || '');
          closePathBrowser();
        });
        li.appendChild(choose);
        attachState.browser.list.appendChild(li);
      });
    }
  }

  function openPathBrowser(mode, startValue) {
    ensureAttachUi();
    if (!attachState.browser.wrap) return;
    attachState.browser.mode = mode;
    attachState.browser.wrap.classList.add('active');
    if (attachState.browser.selectBtn) attachState.browser.selectBtn.style.display = mode === 'folder' ? '' : 'none';
    let startPath = '';
    if (typeof startValue === 'string' && startValue.trim()) {
      startPath = startValue.trim();
    } else if (mode === 'folder' && attachState.pathInputs.folder && attachState.pathInputs.folder.value) {
      startPath = attachState.pathInputs.folder.value;
    } else if (mode === 'structure' && attachState.pathInputs.structure && attachState.pathInputs.structure.value) {
      startPath = attachState.pathInputs.structure.value;
    } else if (attachState.pathInputs.file && attachState.pathInputs.file.value) {
      startPath = attachState.pathInputs.file.value;
    }
    startPath = startPath.replace(/\\/g, '/');
    if (mode !== 'folder') {
      const parts = startPath.split('/').filter(Boolean);
      if (parts.length) parts.pop();
      startPath = parts.join('/');
    }
    loadBrowserPath(startPath);
  }

  async function handleAttachSubmit() {
    if (attachState.submitting) return;
    if (!state.currentId) {
      showStatus('Load a room before attaching.', true);
      return;
    }
    let target = getAttachTarget();
    if (!target) {
      updateAttachSubmitState();
      return;
    }
    target = target.replace(/\\/g, '/');
    let type = attachState.type || 'relation';
    if (type === 'node') type = 'relation';
    let label = attachState.labelInput ? attachState.labelInput.value.trim() : '';
    if (!label) {
      if (type === 'relation') label = findNodeTitle(target) || target;
      else if (type === 'url') label = target;
      else label = basename(target);
    }
    const existing = (Array.isArray(state.links) && attachState.editIndex !== null && attachState.editIndex >= 0 && attachState.editIndex < state.links.length)
      ? { ...state.links[attachState.editIndex] }
      : {};
    const newLink = {
      ...existing,
      target,
      type,
      title: label || ''
    };
    if ('id' in existing && existing.id != null) newLink.id = existing.id;
    if ('label' in existing || label) newLink.label = label || '';
    else if (newLink.label !== undefined && !label) delete newLink.label;
    const previous = Array.isArray(state.links) ? state.links.slice() : [];
    const next = previous.slice();
    if (attachState.editIndex !== null && attachState.editIndex >= 0 && attachState.editIndex < next.length) {
      next[attachState.editIndex] = newLink;
    } else {
      next.push(newLink);
    }
    attachState.submitting = true;
    updateAttachSubmitState();
    try {
      state.links = next;
      renderLinks();
      await saveCurrent();
      closeAttachWizard();
    } catch (err) {
      console.error(err);
      state.links = previous;
      renderLinks();
      showStatus(err.message || 'Failed to save link.', true);
    } finally {
      attachState.submitting = false;
      updateAttachSubmitState();
    }
  }

  function openAttachWizard(options = {}) {
    if (!state.currentId) {
      showStatus('Load a room before attaching.', true);
      return;
    }
    ensureAttachUi();
    resetAttachInputs();
    attachState.editIndex = typeof options.linkIndex === 'number' ? options.linkIndex : null;
    attachState.editingLink = options.link || null;
    attachState.submitting = false;
    const link = options.link || null;
    if (attachState.labelInput) {
      let initialLabel = options.defaultLabel || '';
      if (link) {
        if ('label' in link) initialLabel = link.label != null ? String(link.label) : '';
        else initialLabel = link.title != null ? String(link.title) : '';
      }
      attachState.labelInput.value = initialLabel || '';
    }
    if (attachState.urlInput) attachState.urlInput.value = link && link.type === 'url' ? (link.target || '') : '';
    if (attachState.pathInputs.file) attachState.pathInputs.file.value = link && link.type === 'file' ? (link.target || '') : '';
    if (attachState.pathInputs.folder) attachState.pathInputs.folder.value = link && link.type === 'folder' ? (link.target || '') : '';
    if (attachState.pathInputs.structure) attachState.pathInputs.structure.value = link && link.type === 'structure' ? (link.target || '') : '';
    let selectedRelation = null;
    if (link && (link.type === 'relation' || link.type === 'node' || !link.type)) selectedRelation = link.target || '';
    if (!selectedRelation && options.relationTarget) selectedRelation = options.relationTarget;
    attachState.selectedRelation = selectedRelation || null;
    let initialType = (link && link.type) || options.defaultType || (options.relationTarget ? 'relation' : 'relation');
    if (initialType === 'node') initialType = 'relation';
    if (!attachState.panes.has(initialType || '')) initialType = 'relation';
    setAttachType(initialType);
    if (attachState.labelInput && !attachState.labelInput.value.trim() && attachState.selectedRelation) {
      const title = findNodeTitle(attachState.selectedRelation);
      if (title) attachState.labelInput.value = title;
    }
    if (attachState.structurePreview && attachState.pathInputs.structure && attachState.pathInputs.structure.value) {
      updateStructurePreview(true);
    }
    attachState.overlay.classList.add('active');
    updateAttachSubmitState();
    if (attachState.keyHandler) document.addEventListener('keydown', attachState.keyHandler);
    requestAnimationFrame(() => {
      if (attachState.dialog) attachState.dialog.focus();
      if (attachState.labelInput) attachState.labelInput.focus();
    });
  }

  function closeAttachWizard() {
    if (!attachState.overlay) return;
    attachState.overlay.classList.remove('active');
    document.removeEventListener('keydown', attachState.keyHandler);
    attachState.editIndex = null;
    attachState.editingLink = null;
    attachState.submitting = false;
    resetAttachInputs();
    updateAttachSubmitState();
  }

  async function followLink(link) {
    if (!link) return;
    const type = (link.type || '').toLowerCase();
    const target = link.target || '';
    if (!target) return;
    try {
      if (type === 'relation' || type === 'node' || !type) {
        if (!(await confirmNavigation())) return;
        await loadRoom(target);
        return;
      }
      if (type === 'folder') {
        const openDirFn = typeof window.openDir === 'function' ? window.openDir : null;
        if (openDirFn) {
          await openDirFn(target);
        } else {
          window.open(`${DOOR.base}?pane=find&path=${encodeURIComponent(target)}`, '_blank');
        }
        return;
      }
      if (type === 'file' || type === 'structure') {
        const openDirFn = typeof window.openDir === 'function' ? window.openDir : null;
        const openFileFn = typeof window.openFile === 'function' ? window.openFile : null;
        if (openDirFn) {
          const parts = target.split('/');
          parts.pop();
          await openDirFn(parts.join('/'));
        }
        if (openFileFn) {
          const name = basename(target) || 'file';
          await openFileFn(target, name, 0, 0);
        } else {
          window.open(`${DOOR.base}?api=get_file&path=${encodeURIComponent(target)}`, '_blank');
        }
        return;
      }
      if (type === 'url') {
        window.open(target, '_blank', 'noopener');
        return;
      }
      if (!(await confirmNavigation())) return;
      await loadRoom(target);
    } catch (err) {
      console.error(err);
      showStatus(err.message || 'Unable to open link.', true);
    }
  }

  if (childDialogState.wrap) {
    childDialogState.wrap.addEventListener('click', event => {
      if (event.target !== childDialogState.wrap) return;
      if (childDialogState.createBtn && childDialogState.createBtn.disabled) return;
      closeAddChildDialog();
    });
  }
  if (childDialogState.cancelBtn) {
    childDialogState.cancelBtn.addEventListener('click', () => closeAddChildDialog());
  }
  if (childDialogState.form) {
    childDialogState.form.addEventListener('submit', event => {
      event.preventDefault();
      submitChildDialog();
    });
  }

  if (saveBtn) saveBtn.addEventListener('click', () => saveCurrent());
  if (addChildBtn) addChildBtn.addEventListener('click', () => addChildPrompt());
  if (deleteBtn) deleteBtn.addEventListener('click', () => deleteCurrent());
  if (attachBtn) attachBtn.addEventListener('click', () => openAttachWizard({ defaultType: 'relation' }));

  async function mountShell() {
    try {
      await Promise.all([
        loadTemplate('door-grid-wrap', 'grid'),
        loadTemplate('door-crumb-wrap', 'breadcrumb'),
        loadTemplate('door-rail-wrap', 'rail'),
        loadTemplate('door-search-wrap', 'search')
      ]);
      ensureDrawer();
      await ensurePreviewService();
      initDrawer();
      initSearch();
      initKeyboardShortcuts();
      if (DOOR_READY) await loadRoom('');
      else showStatus('Door storage unavailable.', true);
    } catch (err) {
      console.error(err);
      showStatus('Failed to initialize DOOR: ' + (err.message || err), true);
    }
  }

  mountShell();
})();
