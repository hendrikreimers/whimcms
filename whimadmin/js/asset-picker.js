// Asset picker modal — visual browser over the same path list the
// `<datalist id="asset-paths">` autocomplete already provides.
//
// On click of any `[data-asset-picker]` button: open a modal showing
// thumbnails of every image asset, with a search filter. Clicking a
// thumb fills the sibling text input with the canonical
// `/assets/<rel>` path and closes the modal.
//
// The datalist's `data-site-root` attribute (set server-side from
// `Request::siteRoot()`) provides the URL prefix for sub-mounted
// installs (`/sub/assets/...` instead of `/assets/...`).

let modalEl = null;
let currentTarget = null;

function ensureModal() {
  if (modalEl) return modalEl;
  modalEl = document.createElement('div');
  modalEl.className = 'asset-picker-modal';
  modalEl.setAttribute('hidden', '');
  modalEl.innerHTML = `
    <div class="asset-picker-backdrop" data-asset-picker-close></div>
    <div class="asset-picker-dialog" role="dialog" aria-modal="true" aria-label="Pick an asset">
      <div class="asset-picker-header">
        <h2>Pick an asset</h2>
        <button class="asset-picker-close" type="button" data-asset-picker-close aria-label="Close">×</button>
      </div>
      <div class="asset-picker-search">
        <input type="search" placeholder="Filter by path…" autocomplete="off" data-asset-picker-filter>
      </div>
      <div class="asset-picker-grid" data-asset-picker-grid></div>
      <div class="asset-picker-empty muted" hidden>No assets uploaded yet. Visit the Assets section to upload.</div>
    </div>
  `;
  document.body.appendChild(modalEl);
  return modalEl;
}

function openPicker(targetInput) {
  if (!targetInput) return;
  const datalist = document.getElementById('asset-paths');
  if (!datalist) return;
  const siteRoot = datalist.getAttribute('data-site-root') || '';
  const paths = Array.from(datalist.querySelectorAll('option'))
    .map((o) => o.getAttribute('value') || '')
    .filter((p) => p !== '');

  const modal = ensureModal();
  const grid  = modal.querySelector('[data-asset-picker-grid]');
  const empty = modal.querySelector('.asset-picker-empty');
  const filter = modal.querySelector('[data-asset-picker-filter]');
  if (!grid || !filter || !empty) return;

  grid.textContent = '';
  if (paths.length === 0) {
    empty.removeAttribute('hidden');
  } else {
    empty.setAttribute('hidden', '');
    for (const p of paths) {
      const tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'asset-picker-tile';
      tile.dataset.assetPickerPath = p;
      tile.title = p;
      const img = document.createElement('img');
      img.alt = '';
      img.loading = 'lazy';
      img.src = siteRoot + p;
      const label = document.createElement('span');
      label.className = 'asset-picker-tile-label';
      label.textContent = p.replace(/^\/assets\//, '');
      tile.appendChild(img);
      tile.appendChild(label);
      grid.appendChild(tile);
    }
  }

  filter.value = '';
  applyFilter('');
  modal.removeAttribute('hidden');
  currentTarget = targetInput;
  filter.focus();
}

function closePicker() {
  if (!modalEl) return;
  modalEl.setAttribute('hidden', '');
  currentTarget = null;
}

function applyFilter(needle) {
  if (!modalEl) return;
  const grid = modalEl.querySelector('[data-asset-picker-grid]');
  if (!grid) return;
  const lower = needle.toLowerCase();
  for (const tile of grid.querySelectorAll('.asset-picker-tile')) {
    const path = tile.getAttribute('data-asset-picker-path') || '';
    tile.style.display = path.toLowerCase().includes(lower) ? '' : 'none';
  }
}

document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLElement)) return;

  // Browse button → open
  const trigger = target.closest('[data-asset-picker]');
  if (trigger) {
    const wrap = trigger.closest('.field-image-wrap');
    if (!wrap) return;
    const input = wrap.querySelector('input[type="text"]');
    if (input instanceof HTMLInputElement) openPicker(input);
    return;
  }

  // Tile click → fill + close
  const tile = target.closest('[data-asset-picker-path]');
  if (tile && currentTarget) {
    const path = tile.getAttribute('data-asset-picker-path') || '';
    currentTarget.value = path;
    currentTarget.dispatchEvent(new Event('change', { bubbles: true }));
    closePicker();
    return;
  }

  // Close affordances
  if (target.closest('[data-asset-picker-close]')) {
    closePicker();
  }
});

document.addEventListener('input', (event) => {
  const t = event.target;
  if (!(t instanceof HTMLInputElement)) return;
  if (!t.matches('[data-asset-picker-filter]')) return;
  applyFilter(t.value);
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && modalEl && !modalEl.hasAttribute('hidden')) {
    closePicker();
  }
});
