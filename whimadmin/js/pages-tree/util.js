// WhimAdmin · pages-tree · shared utilities.
//
// Extracted from render.js + main.js to keep the call-sites tight
// and remove the prior duplicate definitions. Browsers since 2017
// ship CSS.escape natively; the fallback is only invoked in test
// harnesses or very old environments.

export function cssEscape(s) {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
    return CSS.escape(s);
  }
  return String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
}
