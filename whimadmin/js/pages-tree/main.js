// WhimAdmin · pages-tree · entry / orchestration.
//
// Wires the read-side (api, render) to the user interactions (click,
// dnd, context-menu) and the write-side (api mutations, refresh).
//
// State held in module scope:
//   api          — fetch wrapper, owns csrf token + tree version
//   tree         — most-recent TreeView from server
//   types        — page-types catalog (loaded once at init)
//   selected     — { lang, section, indexPath } or null
//   layouts      — list of layout names from the server (passed via
//                  data attribute at page render time; we read it
//                  here once)
//
// Re-fetch policy: after every successful mutation we re-fetch the
// tree (cheap, ~10 KB) and re-render. The selected node's address
// might have shifted (a move changed indices) — we try to retain
// selection by slug if it's still present in the new tree, otherwise
// drop selection.

import { TreeApi }                  from './api.js';
import { renderTree, markSelected } from './render.js';
import { renderEditor, renderEditorPlaceholder } from './editor.js';
import { attachDnd }                from './dnd.js';
import { prompt, select, confirm } from './dialogs.js';
import { cssEscape }                from './util.js';

const shell = document.querySelector('[data-pages-tree-shell]');
const treeContainer   = shell.querySelector('[data-pages-tree]');
const editorContainer = shell.querySelector('[data-pages-editor]');
const basePath = shell.dataset.base || '';

const api = new TreeApi(basePath);
let tree = null;
let types = null;
let selected = null;

// Allowed layouts come from the server's /pages/tree/types response
// (key `layouts`). Populated during init() once the types catalogue
// has loaded.
let layouts = ['default'];

// Mutation lock — prevents concurrent mutations from racing each
// other. Each mutation refreshes the tree before releasing the lock,
// so the next mutation always operates on fresh state. UI clicks
// during a pending mutation are silently dropped.
let mutationInFlight = false;

async function init() {
  try {
    types = await api.getTypes();
    if (Array.isArray(types?.layouts) && types.layouts.length > 0) {
      layouts = types.layouts.filter(v => typeof v === 'string');
    }
    // Deep-link via query params: `/pages?lang=en&slug=foo` arms a
    // pre-selection that we resolve in resolveSelectionAfterRefresh
    // once the tree is loaded. Used by the block-editor at
    // /pages/blocks when it redirects back here after a save, so the
    // user lands directly on the page they were editing.
    const url = new URL(window.location.href);
    const preLang = url.searchParams.get('lang');
    const preSlug = url.searchParams.get('slug');
    if (preLang && preSlug) {
      selected = { lang: preLang, section: '', indexPath: '', slug: preSlug };
    }
    await refreshTree();
    attachDnd(treeContainer, { onDrop: handleDrop });
    attachClicks();
    attachContextMenu();
    attachKeyboard();
  } catch (e) {
    treeContainer.replaceChildren();
    const p = document.createElement('p');
    p.className = 'pages-tree-loading';
    p.textContent = `Failed to load tree: ${e.message}`;
    treeContainer.appendChild(p);
  }
}

// ============================================================
// Tree refresh & selection
// ============================================================

async function refreshTree() {
  // Capture the set of expanded tree-node indexPaths (per-language,
  // per-section) before we replace the DOM so the user's drill-down
  // state survives the refresh.
  const expanded = captureExpandedState();
  tree = await api.getTree();
  renderTree(tree, treeContainer);
  restoreExpandedState(expanded);
  // Selection resolution FIRST — after a move the selected node has
  // a new indexPath; resolveSelectionAfterRefresh updates `selected`
  // by tracking the slug. expandPathTo then runs against the new
  // path so the user sees where the moved item landed.
  resolveSelectionAfterRefresh();
  if (selected) {
    expandPathTo(selected);
    await rerenderEditor();
  } else {
    renderEditorPlaceholder(editorContainer);
  }
}

function captureExpandedState() {
  const out = new Set();
  treeContainer.querySelectorAll('.tree-node[data-expanded="true"]').forEach(node => {
    const row = node.querySelector('.tree-node-row');
    if (!row) return;
    out.add(`${row.dataset.lang}::${row.dataset.section}::${row.dataset.indexPath}`);
  });
  return out;
}

function restoreExpandedState(expanded) {
  if (!expanded || expanded.size === 0) return;
  treeContainer.querySelectorAll('.tree-node').forEach(node => {
    const row = node.querySelector('.tree-node-row');
    if (!row) return;
    const key = `${row.dataset.lang}::${row.dataset.section}::${row.dataset.indexPath}`;
    if (expanded.has(key)) {
      const childList = node.querySelector('.tree-node-children');
      if (childList && childList.children.length > 0) {
        childList.hidden = false;
        node.dataset.expanded = 'true';
      }
    }
  });
}

/** Expand every ancestor of the selected indexPath so it's visible. */
function expandPathTo(sel) {
  if (!sel) return;
  const parts = sel.indexPath.split('/');
  let prefix = '';
  for (let i = 0; i < parts.length - 1; i++) {
    prefix = prefix === '' ? parts[i] : `${prefix}/${parts[i]}`;
    const row = treeContainer.querySelector(
      `.tree-node-row[data-lang="${cssEscape(sel.lang)}"][data-section="${cssEscape(sel.section)}"][data-index-path="${cssEscape(prefix)}"]`,
    );
    if (!row) continue;
    const node = row.closest('.tree-node');
    const childList = node?.querySelector('.tree-node-children');
    if (childList && childList.children.length > 0) {
      childList.hidden = false;
      node.dataset.expanded = 'true';
    }
  }
}


function resolveSelectionAfterRefresh() {
  if (!selected) return;
  // For slug-typed entries: ALWAYS try by-slug first. After a move,
  // the previously-held indexPath may now point to a DIFFERENT item
  // (one that shifted into the old position). Position-first lookup
  // would silently retain a wrong selection and the editor would
  // render with stale values from the wrong node.
  if (selected.slug) {
    const found = findNodeBySlug(selected.lang, selected.slug);
    if (found) {
      selected = { ...found.address, slug: selected.slug };
      return;
    }
    // Slug-typed but slug no longer in tree (deleted, moved to
    // unsorted-and-then-out, etc.) — drop selection.
    selected = null;
    return;
  }
  // Non-slug types (folder/anchor/href) have no stable identifier
  // across moves; fall back to position lookup as best-effort.
  const node = findNode(selected.lang, selected.section, selected.indexPath);
  if (node) {
    selected.slug = node.slug || null;
    return;
  }
  selected = null;
}

function findNode(lang, section, indexPath) {
  const langData = (tree.languages || []).find(l => l.lang === lang);
  if (!langData) return null;
  const sec = langData.sections.find(s => s.key === section);
  if (!sec) return null;
  const parts = indexPath.split('/').map(s => parseInt(s, 10));
  let cur = sec.items;
  let node = null;
  for (let i = 0; i < parts.length; i++) {
    const idx = parts[i];
    if (!cur || !cur[idx]) return null;
    node = cur[idx];
    cur = node.children;
  }
  return node;
}

function findNodeBySlug(lang, slug) {
  const langData = (tree.languages || []).find(l => l.lang === lang);
  if (!langData) return null;
  for (const sec of langData.sections) {
    const found = walkFindSlug(sec.items, slug);
    if (found) return { node: found.node, address: { lang, section: sec.key, indexPath: found.indexPath } };
  }
  return null;
}

function walkFindSlug(items, slug) {
  for (const node of items) {
    if (node.slug === slug) return { node, indexPath: node.indexPath };
    if (node.children?.length) {
      const inner = walkFindSlug(node.children, slug);
      if (inner) return inner;
    }
  }
  return null;
}

async function rerenderEditor() {
  if (!selected) {
    renderEditorPlaceholder(editorContainer);
    return;
  }
  const node = findNode(selected.lang, selected.section, selected.indexPath);
  if (!node) {
    renderEditorPlaceholder(editorContainer);
    return;
  }
  selected.slug = node.slug || null;
  markSelected(treeContainer, selected);

  // Fetch fresh node-detail (incl. frontmatter for slug entries) so
  // the editor doesn't render stale meta values after a save.
  let detail = null;
  try {
    const res = await api.getNode(selected.lang, selected.section, selected.indexPath);
    detail = res?.node || null;
  } catch (e) {
    // Non-fatal — the editor still renders with whatever the tree
    // JSON had. Frontmatter fields just start empty.
  }
  // Merge tree-node values with detail's frontmatter (detail wins
  // for any overlapping field).
  const merged = Object.assign({}, node, detail || {});

  renderEditor(editorContainer, merged, types, {
    lang: selected.lang,
    section: selected.section,
    indexPath: selected.indexPath,
    basePath,
    layouts,
  }, {
    onSave:        (values, statusEl) => handleSave(merged, values, statusEl),
    onRetype:      (newType) => handleRetype(merged, newType),
    onOpenContent: (slug) => openContentEditor(slug, selected.lang),
    onOpenHistory: (slug) => openHistoryView(slug, selected.lang),
  });
}

// Both functions take `lang` explicitly so cross-language menu clicks
// resolve to the correct page. Reading `selected.lang` was a stale-
// state bug: a context-menu click on a row in lang B while the
// previously-selected row was in lang A produced a URL with lang=A.
// Callers pass lang explicitly — the row's `data-lang` for menu
// dispatch, or `selected.lang` from the editor-pane callbacks.

function openHistoryView(slug, lang) {
  // History view is rendered by the legacy PagesController for now —
  // restore action there snapshots and writes back to the same .md
  // file the new tree-editor reads. After restore the user navigates
  // back via the page-edit history page's own buttons.
  const url = `${basePath}/pages/history?lang=${encodeURIComponent(lang)}&slug=${encodeURIComponent(slug)}`;
  window.location.href = url;
}

function openContentEditor(slug, lang) {
  const url = `${basePath}/pages/blocks?lang=${encodeURIComponent(lang)}&slug=${encodeURIComponent(slug)}`;
  window.location.href = url;
}

// ============================================================
// Click handlers
// ============================================================

function attachClicks() {
  treeContainer.addEventListener('click', (e) => {
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;

    // Explicit chevron click → expand/collapse only, no select.
    const toggle = target.closest('[data-toggle]');
    if (toggle) {
      e.preventDefault();
      e.stopPropagation();
      toggleNode(toggle.closest('.tree-node'));
      return;
    }

    // Menu trigger handled separately in attachContextMenu.
    if (target.closest('[data-menu-trigger]')) return;

    // Row click: always select. Additionally toggle expand state if
    // the node has children — matches TYPO3 / classic-tree expectation
    // where clicking a folder both opens it and selects it.
    const row = target.closest('.tree-node-row');
    if (!row) return;
    selectRow(row);

    const node = row.closest('.tree-node');
    if (node) {
      const childList = node.querySelector('.tree-node-children');
      if (childList && childList.children.length > 0) {
        toggleNode(node);
      }
    }
  });
}

function toggleNode(node) {
  if (!node) return;
  const childList = node.querySelector('.tree-node-children');
  if (!childList || childList.children.length === 0) return;
  const isExpanded = node.dataset.expanded === 'true';
  childList.hidden = isExpanded;
  node.dataset.expanded = isExpanded ? 'false' : 'true';
}

function selectRow(row) {
  selected = {
    lang: row.dataset.lang,
    section: row.dataset.section,
    indexPath: row.dataset.indexPath,
  };
  markSelected(treeContainer, selected);
  // rerenderEditor is now async (fetches node-detail) but selectRow
  // stays sync — fire-and-forget is fine because re-entrancy is
  // bounded by the click event lifecycle.
  rerenderEditor().catch(() => { /* errors surface in the editor area */ });
}

// ============================================================
// Context menu
// ============================================================

function attachContextMenu() {
  let openMenu = null;
  const closeMenu = () => {
    if (openMenu) { openMenu.remove(); openMenu = null; }
  };

  treeContainer.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-menu-trigger]');
    if (!trigger) {
      closeMenu();
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    closeMenu();
    const row = trigger.closest('.tree-node-row');
    if (!row) return;

    const tmpl = document.querySelector('template[data-tmpl="menu"]');
    const menu = tmpl.content.firstElementChild.cloneNode(true);
    document.body.appendChild(menu);
    openMenu = menu;

    // Position menu below the trigger.
    const rect = trigger.getBoundingClientRect();
    menu.style.top = `${window.scrollY + rect.bottom + 4}px`;
    menu.style.left = `${window.scrollX + rect.left - 160}px`;
    trigger.setAttribute('aria-expanded', 'true');

    // Filter slug-only actions for non-slug entries.
    const isSlug = row.dataset.type === 'slug';
    menu.querySelectorAll('[data-only-slug]').forEach(b => { if (!isSlug) b.hidden = true; });

    // Content-blocks deep-link additionally requires the .md to
    // exist — a slug entry with no .md (e.g. recycler-restored
    // route without content) hides the action.
    if (isSlug && row.dataset.hasMd !== 'yes') {
      menu.querySelector('[data-action="open-content"]')?.setAttribute('hidden', 'hidden');
    }

    // Section-membership constraint: rename / retype don't run in
    // unsorted (the entry has no overlay item to retype).
    const inUnsorted = row.dataset.section === 'unsorted';
    if (inUnsorted) {
      menu.querySelector('[data-action="retype"]')?.setAttribute('hidden', 'hidden');
      menu.querySelector('[data-action="add-child"]')?.setAttribute('hidden', 'hidden');
    }

    menu.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('[data-action]');
      if (!btn) return;
      const action = btn.dataset.action;
      closeMenu();
      trigger.setAttribute('aria-expanded', 'false');
      await dispatchAction(action, row);
    });
  });

  document.addEventListener('click', (e) => {
    if (openMenu && !openMenu.contains(e.target)) closeMenu();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });
}

async function dispatchAction(action, row) {
  const lang = row.dataset.lang;
  const section = row.dataset.section;
  const indexPath = row.dataset.indexPath;
  const type = row.dataset.type;
  const slug = row.dataset.slug || null;

  if (action === 'rename') {
    const value = await prompt({
      title: 'Rename slug',
      message: `This renames the .md file, the routes entry, and the overlay reference. The history directory is renamed too. Current slug: ${slug || '—'}.`,
      label: 'New slug',
      initial: slug || '',
      validate: (v) => /^[a-zA-Z][a-zA-Z0-9_-]{0,40}$/.test(v) ? null : 'A-Z, a-z, 0-9, _ and - only; must start with a letter; max 41 chars.',
    });
    if (value === null || value === slug) return;
    await runMutation(() => api.rename({ lang, section, indexPath, newSlug: value }));
    return;
  }

  if (action === 'retype') {
    const newType = await select({
      title: 'Change page type',
      message: 'Switching from slug to another type recycles the .md and removes the routes entry. Switching to slug creates a fresh stub with disabled=true.',
      options: [
        { value: 'slug',   label: 'Routed page', description: 'Own URL and Markdown content.' },
        { value: 'href',   label: 'External link', description: 'Direct URL, no content.' },
        { value: 'anchor', label: 'In-page anchor', description: 'Fragment on the current page.' },
        { value: 'folder', label: 'Folder', description: 'Grouping container for children.' },
      ],
      initial: type,
    });
    if (!newType || newType === type) return;
    await runMutation(() => api.retype({ lang, section, indexPath, newType }));
    return;
  }

  if (action === 'add-child') {
    const childType = await select({
      title: 'Add child page',
      message: 'The new entry is created hidden by default. You can edit its label and details after creation.',
      options: [
        { value: 'slug',   label: 'Routed page' },
        { value: 'href',   label: 'External link' },
        { value: 'anchor', label: 'In-page anchor' },
        { value: 'folder', label: 'Folder' },
      ],
      initial: 'slug',
    });
    if (!childType) return;
    const children = parseInt(row.closest('.tree-node')?.querySelector('.tree-node-children')?.children.length || 0, 10);
    await runMutation(() => api.create({
      lang, section,
      parentIndexPath: indexPath,
      beforeIndex: children,
      type: childType,
    }));
    return;
  }

  if (action === 'delete') {
    const ok = await confirm({
      title: 'Delete page?',
      message: `This removes the overlay entry${type === 'slug' ? ', moves the .md to the recycler, and clears the routes entry' : ''}. The recycler keeps the .md so you can restore later.`,
      okLabel: 'Delete',
    });
    if (!ok) return;
    await runMutation(() => api.remove({ lang, section, indexPath }));
    return;
  }

  if (action === 'open-content') {
    if (slug) openContentEditor(slug, lang);
    return;
  }

  if (action === 'open-history') {
    if (!slug) return;
    openHistoryView(slug, lang);
    return;
  }
}

// ============================================================
// Save + retype + drop handlers
// ============================================================

async function handleSave(node, values, statusEl) {
  if (mutationInFlight) {
    statusEl.textContent = 'Another change is in flight — try again in a moment.';
    statusEl.classList.add('is-error');
    return;
  }
  mutationInFlight = true;
  try {
    const result = await api.save({
      lang: selected.lang,
      section: selected.section,
      indexPath: selected.indexPath,
      type: node.type,
      values,
    });
    // Re-arm the selected slug from the server's response BEFORE the
    // tree refresh runs. saveImpl can have renamed the slug as a side
    // effect (when the form's slug field carries a new value); the
    // refresh-time selection resolver looks up by slug, so without
    // this re-arm it would search for the OLD slug, fail to find it
    // in the refreshed tree, and silently drop the selection (UI
    // pops back to the placeholder until the user re-clicks the
    // page in the tree).
    if (typeof result?.slug === 'string') {
      selected.slug = result.slug;
    }
    if (typeof result?.indexPath === 'string') {
      selected.indexPath = result.indexPath;
    }
    statusEl.textContent = 'Saved.';
    statusEl.classList.add('is-success');
    await refreshTree();
  } catch (e) {
    statusEl.textContent = formatError(e);
    statusEl.classList.add('is-error');
    if (e.code === 'tree-version-conflict') {
      api.version = e.currentVersion || api.version;
      await refreshTree();
    }
  } finally {
    mutationInFlight = false;
  }
}

async function handleRetype(node, newType) {
  await runMutation(() => api.retype({
    lang: selected.lang,
    section: selected.section,
    indexPath: selected.indexPath,
    newType,
  }));
}

async function handleDrop(source, target) {
  if (source.kind === 'new') {
    const type = await select({
      title: 'New page type',
      message: 'Pick the page type for the new entry. Defaults to “Routed page”; you can change it later.',
      options: [
        { value: 'slug',   label: 'Routed page' },
        { value: 'href',   label: 'External link' },
        { value: 'anchor', label: 'In-page anchor' },
        { value: 'folder', label: 'Folder' },
      ],
      initial: 'slug',
    });
    if (!type) return;
    // Custom flow (not via runMutation) so we can read the create
    // response and pre-arm `selected` with the new slug. Mirrors
    // runMutation's lock + error handling.
    if (mutationInFlight) return;
    mutationInFlight = true;
    try {
      const result = await api.create({
        lang: target.lang,
        section: target.section,
        parentIndexPath: target.parentIndexPath,
        beforeIndex: target.beforeIndex,
        type,
      });
      // Auto-select the new entry. Slug-typed → resolveSelectionAfterRefresh
      // tracks it by slug; non-slug → falls back to indexPath which
      // matches the freshly inserted position.
      selected = {
        lang: target.lang,
        section: target.section,
        indexPath: result?.indexPath || '',
        slug: result?.slug || null,
      };
      await refreshTree();
    } catch (e) {
      if (e.code === 'tree-version-conflict') {
        api.version = e.currentVersion || api.version;
        await refreshTree();
        flashGlobalError('The tree was modified — view reloaded. Try again.');
      } else {
        flashGlobalError(formatError(e));
      }
    } finally {
      mutationInFlight = false;
    }
    return;
  }
  if (source.kind === 'move') {
    // Pre-arm selection BEFORE the mutation runs so the move's
    // URL-auto-prefix (deriveParentSegment server-side) shows in
    // the right-pane editor immediately after the refresh.
    // resolveSelectionAfterRefresh uses `slug` to find the moved
    // item's new indexPath — without this pre-arm the editor would
    // keep whatever was selected before the drag (often a different
    // item) and a stale URL value could be re-submitted by the next
    // Save.
    if (source.slug) {
      selected = {
        lang: source.lang,
        section: source.section,
        indexPath: source.indexPath,
        slug: source.slug,
      };
    }
    await runMutation(() => api.move({
      lang: source.lang,
      fromSection: source.section,
      fromIndexPath: source.indexPath,
      toSection: target.section,
      toParentIndexPath: target.parentIndexPath,
      toBeforeIndex: target.beforeIndex,
    }));
  }
}

async function runMutation(fn) {
  if (mutationInFlight) return; // ignore overlapping clicks
  mutationInFlight = true;
  try {
    await fn();
    await refreshTree();
  } catch (e) {
    if (e.code === 'tree-version-conflict') {
      api.version = e.currentVersion || api.version;
      await refreshTree();
      flashGlobalError('The tree was modified by another tab — view reloaded. Try again.');
      return;
    }
    flashGlobalError(formatError(e));
  } finally {
    mutationInFlight = false;
  }
}

function formatError(e) {
  if (e.code === 'csrf') return 'Session expired. Please reload the page.';
  if (e.message) return e.message;
  return 'Unknown error.';
}

// One outstanding banner timeout at most; re-flashing extends the
// visibility window rather than stacking timeouts.
let bannerTimeout = null;

function flashGlobalError(msg) {
  if (bannerTimeout !== null) {
    clearTimeout(bannerTimeout);
    bannerTimeout = null;
  }
  let banner = shell.querySelector('.pages-tree-banner');
  if (!banner) {
    banner = document.createElement('div');
    banner.className = 'pages-tree-banner';
    banner.setAttribute('role', 'alert');
    const text = document.createElement('span');
    text.className = 'pages-tree-banner-msg';
    const close = document.createElement('button');
    close.className = 'pages-tree-banner-close';
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.textContent = '×';
    close.addEventListener('click', () => {
      if (bannerTimeout !== null) { clearTimeout(bannerTimeout); bannerTimeout = null; }
      banner.remove();
    });
    banner.append(text, close);
    shell.querySelector('.pages-tree-side').prepend(banner);
  }
  banner.querySelector('.pages-tree-banner-msg').textContent = msg;
  // 15 seconds — long enough to read structural-failure messages
  // without forcing the user to react under pressure.
  bannerTimeout = setTimeout(() => { banner.remove(); bannerTimeout = null; }, 15000);
}

// ============================================================
// Keyboard
// ============================================================

function attachKeyboard() {
  treeContainer.addEventListener('keydown', (e) => {
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;
    const row = target.closest('.tree-node-row');
    if (!row) return;

    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      selectRow(row);
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      focusAdjacentRow(row, +1);
      return;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      focusAdjacentRow(row, -1);
      return;
    }
    if (e.key === 'ArrowRight') {
      const node = row.closest('.tree-node');
      if (node?.dataset.expanded === 'false') {
        const toggle = row.querySelector('[data-toggle]');
        if (toggle && !toggle.hidden) toggle.click();
      }
      return;
    }
    if (e.key === 'ArrowLeft') {
      const node = row.closest('.tree-node');
      if (node?.dataset.expanded === 'true') {
        const toggle = row.querySelector('[data-toggle]');
        if (toggle && !toggle.hidden) toggle.click();
      }
      return;
    }
  });
}

function focusAdjacentRow(row, dir) {
  const all = Array.from(treeContainer.querySelectorAll('.tree-node-row'));
  const visible = all.filter(r => isVisible(r));
  const idx = visible.indexOf(row);
  if (idx < 0) return;
  const next = visible[idx + dir];
  if (next) next.focus();
}

function isVisible(el) {
  return el.offsetParent !== null;
}

init();
