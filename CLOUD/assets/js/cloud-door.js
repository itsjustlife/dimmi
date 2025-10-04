(() => {
  const log = (...args) => console.log('[Door]', ...args);
  const root = document.getElementById('door-grid');
  const breadcrumb = document.getElementById('door-breadcrumb');
  const searchInput = document.getElementById('door-search-input');
  const refreshBtn = document.getElementById('door-refresh');
  const drawer = document.getElementById('door-drawer');
  const drawerClose = document.getElementById('door-drawer-close');
  const drawerTitle = document.getElementById('door-drawer-title');
  const drawerPath = document.getElementById('door-drawer-path');
  const webTab = document.getElementById('door-tab-web');
  const rawTab = document.getElementById('door-tab-raw');
  const saveBtn = document.getElementById('door-save');
  const webView = document.getElementById('door-web-view');
  const rawView = document.getElementById('door-raw-view');

  if (!root || !breadcrumb || !searchInput || !refreshBtn || !drawer || !webView || !rawView || !webTab || !rawTab || !drawerClose || !drawerTitle || !drawerPath) {
    console.warn('[Door] Required elements missing.');
    return;
  }

  const SUPPORTED_EXT = new Set(['json', 'opml', 'md', 'markdown', 'html', 'htm']);

  const state = {
    cwd: '',
    folders: [],
    files: [],
    filteredItems: [],
    focusIndex: -1,
    searchTerm: '',
    drawer: {
      open: false,
      mode: 'web',
      file: null,
      dirty: false,
      rawLoaded: false,
      supportsRaw: false
    }
  };

  const toArray = value => Array.isArray(value) ? value : [];

  function buildListUrl(path = '') {
    const url = new URL(window.location.href);
    url.search = '';
    url.pathname = window.location.pathname;
    url.searchParams.set('mode', 'door');
    url.searchParams.set('fs', 'list');
    if (path) url.searchParams.set('path', path);
    return url.toString();
  }

  function resetFocus() {
    state.focusIndex = state.filteredItems.length ? 0 : -1;
  }

  function applyFilter() {
    const term = state.searchTerm.trim().toLowerCase();
    if (!term) {
      state.filteredItems = [
        ...state.folders.map(item => ({ ...item, kind: 'folder' })),
        ...state.files.map(item => ({ ...item, kind: 'file' }))
      ];
      resetFocus();
      return;
    }
    const predicate = name => name.toLowerCase().includes(term);
    const folders = state.folders.filter(item => predicate(item.name)).map(item => ({ ...item, kind: 'folder' }));
    const files = state.files.filter(item => predicate(item.name)).map(item => ({ ...item, kind: 'file' }));
    state.filteredItems = [...folders, ...files];
    resetFocus();
  }

  function renderBreadcrumb() {
    breadcrumb.innerHTML = '';
    const path = state.cwd;
    const segments = path ? path.split('/') : [];
    const home = document.createElement('button');
    home.type = 'button';
    home.className = 'door-crumb';
    home.textContent = 'Home';
    home.addEventListener('click', () => navigateTo(''));
    breadcrumb.appendChild(home);
    let current = '';
    segments.forEach(segment => {
      current = current ? `${current}/${segment}` : segment;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'door-crumb';
      btn.textContent = segment;
      btn.addEventListener('click', () => navigateTo(current));
      breadcrumb.appendChild(document.createTextNode(' / '));
      breadcrumb.appendChild(btn);
    });
  }

  function renderEmpty(message) {
    root.innerHTML = `<div class="door-empty">${message}</div>`;
  }

  function renderGrid() {
    root.innerHTML = '';
    if (!state.filteredItems.length) {
      renderEmpty('No items here.');
      return;
    }
    const frag = document.createDocumentFragment();
    state.filteredItems.forEach((item, index) => {
      const tile = document.createElement('button');
      tile.type = 'button';
      tile.className = `door-tile door-${item.kind}`;
      tile.dataset.index = String(index);
      tile.dataset.path = item.path;
      tile.setAttribute('aria-label', `${item.kind === 'folder' ? 'Folder' : 'File'} ${item.name}`);
      tile.innerHTML = `<span class="door-tile-icon" aria-hidden="true"></span><span class="door-tile-name">${item.name}</span>`;
      tile.addEventListener('click', () => handleItemActivate(item));
      tile.addEventListener('keydown', event => handleTileKey(event, index));
      frag.appendChild(tile);
    });
    root.appendChild(frag);
    focusCurrentTile();
  }

  function focusCurrentTile() {
    if (state.focusIndex < 0) return;
    const target = root.querySelector(`[data-index="${state.focusIndex}"]`);
    if (target) target.focus();
  }

  function handleTileKey(event, index) {
    if (!['ArrowRight', 'ArrowLeft', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
    event.preventDefault();
    const delta = (event.key === 'ArrowRight' || event.key === 'ArrowDown') ? 1 : -1;
    let next = index + delta;
    if (next < 0) next = state.filteredItems.length - 1;
    if (next >= state.filteredItems.length) next = 0;
    state.focusIndex = next;
    focusCurrentTile();
  }

  function guardDirty(message = 'Discard unsaved changes?') {
    if (!state.drawer.dirty) return true;
    const confirmed = window.confirm(message);
    if (!confirmed) return false;
    state.drawer.dirty = false;
    return true;
  }

  async function navigateTo(path) {
    if (!guardDirty('Discard unsaved edits before leaving this file?')) return;
    closeDrawer(true);
    await loadDirectory(path);
  }

  async function loadDirectory(path = '') {
    try {
      renderEmpty('Loading…');
      const response = await fetch(buildListUrl(path));
      const data = await response.json();
      if (!response.ok || !data || data.ok === false) {
        throw new Error((data && data.error) || response.statusText || 'Failed to list directory');
      }
      state.cwd = data.cwd || '';
      state.folders = toArray(data.folders).sort((a, b) => a.name.localeCompare(b.name));
      state.files = toArray(data.files).sort((a, b) => a.name.localeCompare(b.name));
      state.searchTerm = '';
      searchInput.value = '';
      applyFilter();
      renderBreadcrumb();
      renderGrid();
      log('Loaded directory', state.cwd || '/');
    } catch (err) {
      console.error('[Door] List error', err);
      renderEmpty('Unable to load folder.');
    }
  }

  function showDrawer(file, allowRaw = false) {
    state.drawer.open = true;
    state.drawer.file = file;
    state.drawer.mode = 'web';
    state.drawer.dirty = false;
    state.drawer.rawLoaded = false;
    state.drawer.supportsRaw = !!allowRaw;
    drawer.classList.remove('hidden');
    drawerTitle.textContent = file.name;
    drawerPath.textContent = file.path || '';
    webTab.classList.add('door-tab-active');
    rawTab.classList.remove('door-tab-active');
    if (state.drawer.supportsRaw) {
      rawTab.removeAttribute('aria-disabled');
      rawTab.removeAttribute('disabled');
    } else {
      rawTab.setAttribute('aria-disabled','true');
      rawTab.setAttribute('disabled','disabled');
    }
    webView.classList.remove('hidden');
    rawView.classList.add('hidden');
    rawView.value = '';
    if (saveBtn) saveBtn.classList.toggle('hidden', !state.drawer.supportsRaw);
  }

  function hideDrawer() {
    state.drawer.open = false;
    state.drawer.file = null;
    drawer.classList.add('hidden');
    drawerTitle.textContent = 'Preview';
    drawerPath.textContent = '';
    webView.innerHTML = '';
    rawView.value = '';
    state.drawer.rawLoaded = false;
    state.drawer.supportsRaw = false;
    state.drawer.dirty = false;
    rawTab.removeAttribute('aria-disabled');
    rawTab.removeAttribute('disabled');
  }

  async function openFile(item) {
    const ext = (item.ext || '').toLowerCase();
    if (!SUPPORTED_EXT.has(ext)) {
      if (!guardDirty('Close current preview?')) return;
      showDrawer(item, false);
      webView.innerHTML = '<p class="door-empty">Preview not available for this file type.</p>';
      return;
    }
    if (!guardDirty('Discard unsaved edits?')) return;
    showDrawer(item, true);
    try {
      await window.PreviewService.renderWeb(webView, item.path);
      log('Rendered web preview for', item.path);
    } catch (err) {
      console.error('[Door] renderWeb failed', err);
      webView.innerHTML = `<p class="door-empty">${(err && err.message) || 'Unable to render preview.'}</p>`;
    }
    if (saveBtn) saveBtn.classList.toggle('hidden', !state.drawer.supportsRaw);
  }

  async function ensureRawLoaded() {
    if (!state.drawer.file || state.drawer.rawLoaded || !state.drawer.supportsRaw) return;
    try {
      const text = await window.PreviewService.loadRaw(state.drawer.file.path);
      rawView.value = text;
      state.drawer.rawLoaded = true;
      state.drawer.dirty = false;
      log('Loaded raw content', state.drawer.file.path);
    } catch (err) {
      console.error('[Door] loadRaw failed', err);
      rawView.value = `// ${(err && err.message) || 'Unable to load file.'}`;
      state.drawer.rawLoaded = true;
      state.drawer.dirty = false;
    }
  }

  function activateTab(mode) {
    if (!state.drawer.open) return;
    if (mode === 'web') {
      state.drawer.mode = 'web';
      webTab.classList.add('door-tab-active');
      rawTab.classList.remove('door-tab-active');
    if (state.drawer.supportsRaw) {
      rawTab.removeAttribute('aria-disabled');
      rawTab.removeAttribute('disabled');
    } else {
      rawTab.setAttribute('aria-disabled','true');
      rawTab.setAttribute('disabled','disabled');
    }
      webView.classList.remove('hidden');
      rawView.classList.add('hidden');
    } else {
      if (!state.drawer.supportsRaw) return;
      state.drawer.mode = 'raw';
      rawTab.classList.add('door-tab-active');
      webTab.classList.remove('door-tab-active');
      webView.classList.add('hidden');
      rawView.classList.remove('hidden');
      ensureRawLoaded();
    }
  }

  async function saveRawContent() {
    if (!state.drawer.file || !state.drawer.dirty) return;
    try {
      await window.PreviewService.saveRaw(state.drawer.file.path, rawView.value);
      state.drawer.dirty = false;
      state.drawer.rawLoaded = true;
      log('Saved', state.drawer.file.path);
      if (state.drawer.mode === 'raw') {
        await window.PreviewService.renderWeb(webView, state.drawer.file.path);
      }
    } catch (err) {
      console.error('[Door] saveRaw failed', err);
      window.alert((err && err.message) || 'Failed to save file.');
    }
  }

  function closeDrawer(force = false) {
    if (!state.drawer.open) return;
    if (!force && !guardDirty('Discard unsaved edits?')) return;
    hideDrawer();
  }

  function handleItemActivate(item) {
    if (item.kind === 'folder') {
      navigateTo(item.path);
    } else {
      if (!state.drawer.supportsRaw) return;
      openFile(item);
    }
  }

  function onSearchInput() {
    state.searchTerm = searchInput.value;
    applyFilter();
    renderGrid();
  }

  function onGlobalKeydown(event) {
    if (event.key === 'Escape' && state.drawer.open) {
      event.preventDefault();
      closeDrawer();
    }
  }

  async function handleRefresh() {
    if (!guardDirty('Discard unsaved edits?')) return;
    closeDrawer(true);
    await loadDirectory(state.cwd);
  }

  rawView.addEventListener('input', () => {
    if (!state.drawer.open) return;
    state.drawer.dirty = true;
  });

  if (drawerClose) drawerClose.addEventListener('click', () => closeDrawer());
  if (webTab) webTab.addEventListener('click', () => activateTab('web'));
  if (rawTab) rawTab.addEventListener('click', () => activateTab('raw'));
  if (saveBtn) saveBtn.addEventListener('click', saveRawContent);
  if (refreshBtn) refreshBtn.addEventListener('click', handleRefresh);
  searchInput.addEventListener('input', onSearchInput);
  document.addEventListener('keydown', onGlobalKeydown);
  window.addEventListener('beforeunload', event => {
    if (state.drawer.dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  loadDirectory('');
})();
