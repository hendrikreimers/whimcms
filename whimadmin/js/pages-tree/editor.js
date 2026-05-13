// WhimAdmin · pages-tree · right-pane editor.

import { cssEscape } from './util.js';
//
// Renders the page-meta form for the currently-selected tree node.
// Form-field types map directly to the four `<template
// data-tmpl="editor-field-...">` blocks defined in the view:
// text/textarea/bool/select. Other types from the page-type schemas
// (slug, url-path, anchor, layout, link) all surface as `text` here
// with optional hints — server-side validation is the canonical gate.
//
// Type switching: the type-switcher emits `change` events; main.js
// listens and triggers the retype API call. Visual switching of the
// fields happens after the server confirms the new type (we re-fetch
// the tree, find the node at its new state, re-render the editor).

function cloneTmpl(name) {
  const tmpl = document.querySelector(`template[data-tmpl="${name}"]`);
  if (!tmpl) throw new Error(`Missing template: ${name}`);
  return tmpl.content.firstElementChild.cloneNode(true);
}

function slot(root, key) {
  return root.querySelector(`[data-slot="${key}"]`);
}

/**
 * Render the editor for one node into the given container element.
 *
 * @param {HTMLElement} container  parent element (will be cleared)
 * @param {object} node            the tree node (from the JSON tree)
 * @param {object} typesCatalog    map of pageTypeId → schema (from /pages/tree/types)
 * @param {object} ctx             { lang, section, indexPath, basePath, layouts }
 * @param {object} callbacks       { onSave, onRetype, onOpenContent }
 */
export function renderEditor(container, node, typesCatalog, ctx, callbacks) {
  container.replaceChildren();

  const root = cloneTmpl('editor');
  const pageType = typesCatalog.types?.[node.type];
  if (!pageType) {
    const p = document.createElement('p');
    p.className = 'pages-editor-placeholder';
    p.textContent = `No schema for page-type '${node.type}'.`;
    container.appendChild(p);
    return;
  }

  // ----- Header -----
  slot(root, 'title').textContent = node.label || pageType.label;
  const subtitle = slot(root, 'subtitle');
  const addr = `${ctx.lang} · ${ctx.section}${ctx.section !== 'unsorted' ? '[' + ctx.indexPath + ']' : ''}`;
  subtitle.textContent = node.slug ? `${node.slug} · ${addr}` : addr;

  // Type switcher
  const switcher = slot(root, 'type-switcher');
  for (const tid of ['slug', 'href', 'anchor', 'folder']) {
    const t = typesCatalog.types?.[tid];
    if (!t) continue;
    const lbl = document.createElement('label');
    const inp = document.createElement('input');
    inp.type = 'radio';
    inp.name = 'page-type';
    inp.value = tid;
    if (tid === node.type) inp.checked = true;
    const span = document.createElement('span');
    span.textContent = t.label;
    lbl.append(inp, span);
    if (tid === node.type) lbl.classList.add('is-selected');
    inp.addEventListener('change', () => {
      if (inp.value !== node.type) callbacks.onRetype(inp.value);
    });
    switcher.appendChild(lbl);
  }

  // Retype is not supported from the unsorted bucket (it represents
  // slug entries with no overlay reference; type is implicit).
  if (ctx.section === 'unsorted') {
    switcher.querySelectorAll('input').forEach(i => i.disabled = true);
    switcher.title = 'Type changes are only available for nodes inside a configured section.';
  }

  // ----- Fields -----
  const fieldsHost = slot(root, 'fields');
  const initialValues = collectInitialValues(node, pageType, ctx);

  // Unsorted notice + overlay-field suppression. Items in the
  // Unsorted bucket have no overlay entry — `overlay:*` saves are
  // dropped server-side. Rendering those fields would let the user
  // tick a checkbox that silently does nothing. Hide them and
  // surface a one-line hint instead.
  if (ctx.section === 'unsorted') {
    const notice = document.createElement('p');
    notice.className = 'muted small editor-unsorted-notice';
    notice.textContent = 'This page has no navigation entry. Drag it into a section in the tree to set a nav label and visibility. The fields below (slug / URL / meta / sitemap) apply regardless.';
    fieldsHost.appendChild(notice);
  }
  const skipOverlay = ctx.section === 'unsorted';

  for (const [name, spec] of Object.entries(pageType.fields)) {
    if (skipOverlay && spec.target && spec.target.startsWith('overlay:')) continue;
    const fieldEl = renderField(name, spec, initialValues[name], ctx);
    if (fieldEl) fieldsHost.appendChild(fieldEl);
  }

  // ----- Slug-only deep-links: content-blocks editor + history -----
  // Both buttons start `hidden` in the template; we unhide selectively:
  //   - content-blocks editor: requires the .md file to exist
  //     (hasMd). A broken slug with no .md would 404 in the legacy
  //     editor — hiding the button avoids the dead end.
  //   - history: independent of .md existence (history-dir survives
  //     a recycle), so available for any slug entry.
  if (node.type === 'slug' && node.slug) {
    if (node.hasMd) {
      const openContentBtn = root.querySelector('[data-action="open-content"]');
      openContentBtn.hidden = false;
      openContentBtn.addEventListener('click', () => callbacks.onOpenContent(node.slug));
    }
    const openHistoryBtn = root.querySelector('[data-action="open-history"]');
    openHistoryBtn.hidden = false;
    openHistoryBtn.addEventListener('click', () => callbacks.onOpenHistory(node.slug));
  }

  // ----- Save submit -----
  const form = root.querySelector('[data-editor-form]');
  const statusEl = slot(root, 'status');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    statusEl.textContent = 'Saving…';
    statusEl.classList.remove('is-error', 'is-success');
    const values = collectFormValues(form, pageType);
    callbacks.onSave(values, statusEl);
  });

  container.appendChild(root);
}

function collectInitialValues(node, pageType, ctx) {
  // Map each schema field's target back to the node's existing value.
  //
  // Tree JSON only carries: label, hidden, slug, url, href, anchor,
  // disabled. Frontmatter scalars (meta.title, meta.description,
  // layout, the .md-level hidden flag) come from the node-detail
  // endpoint (GET /pages/tree/node), attached as `node.frontmatter`
  // by main.js before this renders. Falling back to '' for missing
  // values is the right default — PageMetaFormDecoder treats empty
  // strings as "no change" on the frontmatter target.
  const fm = node.frontmatter || {};
  const out = {};
  for (const [name, spec] of Object.entries(pageType.fields)) {
    const ns = spec.target.split(':', 1)[0];
    const key = spec.target.slice(ns.length + 1);
    if (ns === 'overlay') {
      if (key === 'label')  out[name] = node.label;
      if (key === 'hidden') out[name] = !!node.hidden;
      if (key === 'href')   out[name] = node.href;
      if (key === 'anchor') out[name] = node.anchor;
      if (key === 'slug')   out[name] = node.slug;
    } else if (ns === 'routes') {
      if (key === 'slug') out[name] = node.slug;
      if (key === 'url')  out[name] = node.url;
    } else if (ns === 'frontmatter') {
      if (key === 'disabled') {
        // The .md's `disabled` ships as a string ('true'/'') from the
        // node-detail endpoint, or as a bool from the tree.
        const v = fm.disabled;
        if (typeof v === 'string') out[name] = v === 'true' || v === 'yes' || v === '1';
        else                       out[name] = !!node.disabled;
      } else if (key === 'hidden') {
        const v = fm.hidden;
        out[name] = typeof v === 'string' ? (v === 'true' || v === 'yes' || v === '1') : false;
      } else if (key === 'layout') {
        out[name] = fm.layout || '';
      } else if (key === 'meta.title') {
        out[name] = fm.meta_title || '';
      } else if (key === 'meta.description') {
        out[name] = fm.meta_description || '';
      } else {
        out[name] = '';
      }
    }
  }
  return out;
}

function renderField(name, spec, initial, ctx) {
  const type = spec.type;
  let el;
  switch (type) {
    case 'textarea':
      el = cloneTmpl('editor-field-textarea');
      break;
    case 'bool':
      el = cloneTmpl('editor-field-bool');
      break;
    case 'select':
      el = cloneTmpl('editor-field-select');
      break;
    case 'layout':
      // Layout is rendered as a select fed from ctx.layouts
      el = cloneTmpl('editor-field-select');
      break;
    default:
      el = cloneTmpl('editor-field-text');
      break;
  }
  slot(el, 'label').textContent = spec.label || spec.name;
  const input = slot(el, 'input');
  input.name = name;

  if (type === 'bool') {
    input.checked = !!initial;
  } else if (type === 'select' || type === 'layout') {
    const opts = type === 'layout' ? (ctx.layouts || []) : (spec.extra?.options || []);
    for (const opt of opts) {
      const o = document.createElement('option');
      o.value = opt;
      o.textContent = opt;
      input.appendChild(o);
    }
    input.value = initial || '';
  } else {
    input.value = initial != null ? String(initial) : '';
    if (type === 'slug' || type === 'url-path' || type === 'anchor') {
      const hint = slot(el, 'hint');
      hint.textContent = type === 'slug'
        ? 'A-Z, a-z, 0-9, _ and - only; max 41 chars.'
        : type === 'url-path'
          ? 'URL path segment (no leading or trailing /). Slashes inside are allowed for nested URLs.'
          : 'Anchor id without the leading #.';
      hint.hidden = false;
    }
  }
  return el;
}

function collectFormValues(form, pageType) {
  const out = {};
  for (const [name, spec] of Object.entries(pageType.fields)) {
    const input = form.querySelector(`[name="${cssEscape(name)}"]`);
    if (!input) continue;
    if (spec.type === 'bool') {
      out[name] = input.checked ? 'true' : 'false';
    } else {
      out[name] = input.value;
    }
  }
  return out;
}

export function renderEditorPlaceholder(container, message = null) {
  container.replaceChildren();
  // Re-build the static placeholder DOM that the shell view ships
  // with — once a real editor has rendered we've replaced it, and a
  // subsequent placeholder needs reconstruction.
  const p = document.createElement('div');
  p.className = 'pages-editor-placeholder';
  const a = document.createElement('p');
  a.textContent = message || 'Select a page from the tree to edit its meta.';
  p.appendChild(a);
  if (!message) {
    const b = document.createElement('p');
    b.className = 'muted small';
    b.textContent = 'Drag pages within or between sections to reorder. Drop the "+ New page" button onto a section to create a new entry.';
    p.appendChild(b);
  }
  container.appendChild(p);
}

