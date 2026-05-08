/**
 * Email-link rehydrator.
 *
 * Server emits `<span class="js-email" data-u="user" data-d="domain">…</span>`
 * with a non-clickable obfuscated display string ("user [at] domain"
 * etc.) — bots without JS see no `mailto:` and no `@` in HTML, scrapers
 * have to know to look for `data-u` / `data-d` and combine them.
 *
 * On page load this module:
 *   1. Finds every .js-email element
 *   2. Reads user + domain from data attributes
 *   3. Replaces the span with a real <a href="mailto:user@domain">
 *      that shows the unobfuscated address as link text
 *   4. Preserves any other classes the span carried (so styling
 *      like .contact-direct-val stays intact)
 *
 * Idempotent — re-running is a no-op.
 */

const SELECTOR = '.js-email';
const MARKER_CLASS = 'js-email';
const BOUND_FLAG = 'data-email-bound';

/**
 * Hydrate every protected email on the page.
 * @returns {void}
 */
export function initEmail() {
  if (document.documentElement.getAttribute(BOUND_FLAG) === '1') {
    return;
  }
  document.documentElement.setAttribute(BOUND_FLAG, '1');

  const spans = document.querySelectorAll(SELECTOR);
  spans.forEach((node) => hydrate(node));
}

/**
 * @param {Element} span
 */
function hydrate(span) {
  if (!(span instanceof HTMLElement)) {
    return;
  }
  const user = span.dataset.u;
  const domain = span.dataset.d;
  if (!user || !domain) {
    return;
  }
  const address = user + '@' + domain;

  const a = document.createElement('a');
  a.href = 'mailto:' + address;
  a.textContent = address;

  // Keep every visual class except the marker.
  span.classList.forEach((c) => {
    if (c !== MARKER_CLASS) {
      a.classList.add(c);
    }
  });

  // Preserve aria/lang attrs in case any get added later.
  for (const attr of ['lang', 'aria-label', 'title']) {
    const v = span.getAttribute(attr);
    if (v !== null) {
      a.setAttribute(attr, v);
    }
  }

  span.replaceWith(a);
}
