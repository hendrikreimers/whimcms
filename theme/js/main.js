/**
 * Entry module loaded by every page. Wires up shared behaviour
 * (header scroll state, reveal-on-scroll animation) and lazily
 * initialises the contact form when its host element is present.
 *
 * Pure ES module, no globals. All event listeners are attached via
 * addEventListener so the CSP can disallow inline handlers.
 */

import { initNav } from './nav.js';
import { initReveal } from './reveal.js';
import { initContactForm } from './contact-form.js';
import { initLightbox } from './lightbox.js';
import { initEmail } from './email.js';
import { initCaptcha } from './captcha.js';

/**
 * Boot the page. Idempotent — safe to call multiple times because each
 * sub-module guards its own state.
 * @returns {void}
 */
function boot() {
  initNav();
  initReveal();
  initContactForm();
  initLightbox();
  initEmail();
  // Captcha must run before contact-form so awaitCaptcha() finds the
  // promise on the form when a fast user submits.
  initCaptcha();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
