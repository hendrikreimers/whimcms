// WhimAdmin · pages-tree · tree rendering.

import { cssEscape } from './util.js';
//
// Pure rendering — no event wiring. Clones the per-template blocks
// from the view's `<template data-tmpl="...">` definitions and stamps
// data via textContent / setAttribute. Dynamic content NEVER reaches
// innerHTML; this matches the strict CSP and prevents XSS even if a
// future overlay validation regression sneaks a control byte through.
//
// Each rendered tree-node row carries data-* attributes addressing
// it uniquely (`data-lang`, `data-section`, `data-index-path`,
// `data-type`, `data-slug` when applicable). DnD and click handlers
// in main.js read those attributes to dispatch operations.

function cloneTmpl(name) {
  const tmpl = document.querySelector(`template[data-tmpl="${name}"]`);
  if (!tmpl) throw new Error(`Missing template: ${name}`);
  return tmpl.content.firstElementChild.cloneNode(true);
}

function slot(root, key) {
  return root.querySelector(`[data-slot="${key}"]`);
}

const TYPE_BADGES = {
  slug:   { letter: 'P', title: 'Routed page' },
  href:   { letter: 'L', title: 'External link' },
  anchor: { letter: '#', title: 'In-page anchor' },
  folder: { letter: 'F', title: 'Folder' },
};

export function renderTree(treeData, container) {
  container.replaceChildren();
  for (const lang of treeData.languages || []) {
    container.appendChild(renderLanguage(lang));
  }
  if (!treeData.languages || treeData.languages.length === 0) {
    const p = document.createElement('p');
    p.className = 'pages-tree-loading';
    p.textContent = 'No languages configured.';
    container.appendChild(p);
  }
}

function renderLanguage(lang) {
  const el = cloneTmpl('lang');
  slot(el, 'label').textContent = lang.lang.toUpperCase();
  if (lang.isDefault) {
    const badge = slot(el, 'default-badge');
    badge.hidden = false;
  }
  const sections = slot(el, 'sections');
  for (const section of lang.sections || []) {
    sections.appendChild(renderSection(lang.lang, section));
  }
  return el;
}

function renderSection(langCode, section) {
  const el = cloneTmpl('section');
  el.setAttribute('data-section-key', section.key);
  el.setAttribute('data-lang', langCode);
  // Unsorted starts collapsed (it's a holding bucket, not the
  // primary editing surface).
  if (section.isUnsorted) {
    el.removeAttribute('open');
    slot(el, 'unsorted-badge').hidden = false;
  }
  slot(el, 'label').textContent = section.label || section.key;
  const items = slot(el, 'items');
  // First-load collapse: sections are expanded, items themselves are
  // collapsed (children hidden by default).
  for (let i = 0; i < section.items.length; i++) {
    items.appendChild(renderNode(langCode, section.key, section.items[i]));
  }
  return el;
}

function renderNode(langCode, sectionKey, node) {
  const el = cloneTmpl('node');
  el.setAttribute('data-lang', langCode);
  el.setAttribute('data-section', sectionKey);
  el.setAttribute('data-index-path', node.indexPath);
  el.setAttribute('data-type', node.type);
  if (node.slug)   el.setAttribute('data-slug',   node.slug);
  if (node.url !== null && node.url !== undefined) {
    el.setAttribute('data-url', node.url);
  }
  if (node.href)   el.setAttribute('data-href',   node.href);
  if (node.anchor) el.setAttribute('data-anchor', node.anchor);

  const row = el.querySelector('.tree-node-row');
  row.setAttribute('data-lang', langCode);
  row.setAttribute('data-section', sectionKey);
  row.setAttribute('data-index-path', node.indexPath);
  row.setAttribute('data-type', node.type);
  // `data-slug` mirrored onto the row too — every click / DnD / menu
  // handler reaches the row first via `target.closest('.tree-node-row')`
  // and reads `row.dataset.slug` directly. Without this mirror the
  // context-menu's "Edit content blocks" and "History" actions silently
  // return (their `if (slug)` / `if (!slug) return;` guards fail when
  // the attribute lives only on the parent <li>). DnD silently falls
  // through to indexPath-based selection recovery, so that path
  // worked accidentally even with the missing attribute — the menu
  // path didn't.
  if (node.slug) row.setAttribute('data-slug', node.slug);
  if (node.type === 'slug') {
    // Used by the context menu to gate the "Edit content blocks"
    // action — a slug entry with no .md file (e.g. recycler-restored
    // route without content) shouldn't offer the deep-link.
    row.setAttribute('data-has-md', node.hasMd ? 'yes' : 'no');
  }

  const badge = slot(el, 'type-badge');
  const meta = TYPE_BADGES[node.type] || TYPE_BADGES.folder;
  badge.textContent = meta.letter;
  badge.title = meta.title;
  badge.setAttribute('data-type', node.type);

  const label = slot(el, 'label');
  label.textContent = node.label || '(unnamed)';
  if ((node.label || '').startsWith('[NEW_PAGE]')) {
    label.classList.add('is-placeholder');
  }

  const flags = slot(el, 'flags');
  if (node.hidden) {
    const tag = document.createElement('span');
    tag.className = 'tree-node-flag is-hidden';
    tag.textContent = 'hidden';
    tag.title = 'Hidden in navigation';
    flags.appendChild(tag);
  }
  if (node.disabled) {
    const tag = document.createElement('span');
    tag.className = 'tree-node-flag is-disabled';
    tag.textContent = 'disabled';
    tag.title = 'Disabled — excluded from sitemap';
    flags.appendChild(tag);
  }
  if (Array.isArray(node.warnings) && node.warnings.length > 0) {
    const tag = document.createElement('span');
    tag.className = 'tree-node-flag is-warning';
    tag.textContent = '!';
    tag.title = 'Warnings: ' + node.warnings.join(', ');
    flags.appendChild(tag);
  }

  const children = slot(el, 'children');
  const toggle = el.querySelector('[data-toggle]');
  if (node.children && node.children.length > 0) {
    toggle.hidden = false;
    toggle.textContent = '▸';
    el.setAttribute('data-expanded', 'false');
    children.hidden = true;
    for (const child of node.children) {
      children.appendChild(renderNode(langCode, sectionKey, child));
    }
  } else {
    toggle.hidden = true;
    children.hidden = true;
  }

  return el;
}

/**
 * Re-apply the selected-state to whichever row addresses
 * `(lang, section, indexPath)`. Other rows have it stripped.
 */
export function markSelected(treeContainer, sel) {
  treeContainer.querySelectorAll('.tree-node-row.is-selected')
    .forEach(r => r.classList.remove('is-selected'));
  if (!sel) return;
  const row = treeContainer.querySelector(
    `.tree-node-row[data-lang="${cssEscape(sel.lang)}"][data-section="${cssEscape(sel.section)}"][data-index-path="${cssEscape(sel.indexPath)}"]`,
  );
  if (row) row.classList.add('is-selected');
}

