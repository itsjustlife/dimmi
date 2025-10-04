(() => {
  const webButton = document.getElementById('preview-web-btn');
  const rawButton = document.getElementById('preview-raw-btn');
  const saveButton = document.getElementById('preview-save');
  const webContainer = document.getElementById('preview-web');
  const rawContainer = document.getElementById('preview-raw');

  if (!webContainer || !rawContainer || !webButton || !rawButton) {
    console.warn('[Classic] Preview elements missing.');
    return;
  }

  let mode = 'web';
  let currentPath = '';
  let currentOptions = {};
  let isDirty = false;

  function setMode(next) {
    mode = next;
    if (mode === 'web') {
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

  function notifyDirty(flag) {
    isDirty = !!flag;
    if (window.PreviewService && typeof window.PreviewService.notifyDirty === 'function') {
      window.PreviewService.notifyDirty(isDirty);
    }
  }

  async function showWeb() {
    if (!window.PreviewService || typeof window.PreviewService.renderWeb !== 'function') return;
    if (!currentPath) {
      webContainer.innerHTML = '<p class="text-sm text-gray-500">No file selected.</p>';
      return;
    }
    const opts = currentOptions || {};
    try {
      await window.PreviewService.renderWeb(webContainer, currentPath, opts);
    } catch (err) {
      webContainer.innerHTML = `<p class="text-sm text-red-600">${(err && err.message) || 'Preview failed.'}</p>`;
    }
  }

  async function showRaw() {
    if (!window.PreviewService || typeof window.PreviewService.loadRaw !== 'function') return;
    if (!currentPath) {
      rawContainer.value = '';
      notifyDirty(false);
      return;
    }
    try {
      const text = await window.PreviewService.loadRaw(currentPath);
      rawContainer.value = text;
      notifyDirty(false);
    } catch (err) {
      rawContainer.value = `// ${ (err && err.message) || 'Unable to load file.' }`;
      notifyDirty(false);
    }
  }

  async function saveRaw() {
    if (!window.PreviewService || typeof window.PreviewService.saveRaw !== 'function') return;
    if (!currentPath) return;
    try {
      await window.PreviewService.saveRaw(currentPath, rawContainer.value);
      notifyDirty(false);
    } catch (err) {
      window.alert((err && err.message) || 'Save failed.');
    }
  }

  webButton.addEventListener('click', () => {
    setMode('web');
    showWeb();
  });

  rawButton.addEventListener('click', () => {
    setMode('raw');
    showRaw();
  });

  rawContainer.addEventListener('input', () => {
    if (mode === 'raw') notifyDirty(true);
  });

  if (saveButton) {
    saveButton.addEventListener('click', saveRaw);
  }

  const api = {
    setFile(path, options = {}) {
      currentPath = path || '';
      currentOptions = options || {};
      if (mode === 'web') showWeb();
      else showRaw();
    },
    refreshWeb(options = {}) {
      currentOptions = { ...currentOptions, ...options };
      if (mode === 'web') showWeb();
    },
    loadRaw: showRaw,
    isDirty: () => isDirty,
    getMode: () => mode,
    notifyDirty,
    setMode(modeName) {
      setMode(modeName === 'raw' ? 'raw' : 'web');
      if (mode === 'web') showWeb();
      else showRaw();
    }
  };

  window.ClassicPreview = api;
  setMode('web');
})();
