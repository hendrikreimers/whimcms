// WhimAdmin · main entry.
//
// Pulls in the field-widget modules (registered as document-level
// click delegates so they pick up dynamically-added items without
// per-element wiring).
import './fields/list.js';
import './fields/markdown.js';
import './blocks-dnd.js';
import './asset-picker.js';

// Pages-tree split-view bootstraps only when its shell element is in
// the DOM — most admin pages won't have it, dynamic-import keeps the
// transfer cost off those pages. ES-module-only, no bundler; the
// import path is resolved by the browser against the current document
// base.
if (document.querySelector('[data-pages-tree-shell]')) {
  import('./pages-tree/main.js');
}

// Generic confirm-on-click for any element with `data-confirm`.
// Avoids inline `onclick=` so the strict CSP stays intact.
document.addEventListener('click', (event) => {
  const el = event.target;
  if (!(el instanceof HTMLElement)) return;
  const msg = el.getAttribute('data-confirm');
  if (!msg) return;
  if (!window.confirm(msg)) {
    event.preventDefault();
    event.stopPropagation();
  }
}, true);

// Auto-trim leading/trailing whitespace on text inputs at submit time
// — defends against accidental copy-paste leading spaces in usernames
// and codes (server validates anyway, this is UX).
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  for (const el of form.querySelectorAll('input[type="text"], input[type="email"], input[name="code"]')) {
    if (el instanceof HTMLInputElement) {
      el.value = el.value.trim();
    }
  }
});
