/**
 * Contact form behaviour.
 *
 * Two modes:
 *   1. Default: classic form POST. The browser does the work, the
 *      server responds with a 303 redirect that lands on the same page
 *      with ?sent=1#contact, and the success state renders server-side.
 *
 *   2. Progressive enhancement (when JS is available): intercept submit,
 *      send the same payload as JSON via fetch(), and patch the DOM with
 *      the structured response. Field errors land inline next to inputs;
 *      success swaps the form for the confirmation message without a
 *      full reload. Falls back transparently to mode 1 on any network
 *      or parse error.
 */

import { awaitCaptcha } from './captcha.js';

const ENDPOINT_ACCEPT = 'application/json';

/**
 * Bind submit handler. Idempotent — re-running is a no-op.
 *
 * Also takes care of the post-PRG focus jump: when the page lands with
 * `?sent=1`, server-rendered DOM contains a confirmation block, but the
 * browser's focus is still at the top of the document. Move focus to
 * the confirmation so screen readers announce it and keyboard users
 * land at the right place.
 * @returns {void}
 */
export function initContactForm() {
  /** @type {HTMLFormElement | null} */
  const form = document.querySelector('[data-contact-form]');
  if (!form || form.dataset.contactBound === '1') {
    return;
  }
  form.dataset.contactBound = '1';

  form.addEventListener('submit', (event) => handleSubmit(event, form));

  // PRG landed: announce the success and focus the message.
  const sent = form.querySelector('[data-contact-sent]');
  if (sent instanceof HTMLElement) {
    sent.focus({ preventScroll: false });
  }
}

/**
 * @param {SubmitEvent} event
 * @param {HTMLFormElement} form
 */
async function handleSubmit(event, form) {
  // Don't intercept if the user explicitly disabled JS submit (e.g. by
  // setting data-no-fetch). Lets us fall back to classic POST for
  // diagnostics.
  if (form.dataset.noFetch === '1') {
    return;
  }
  event.preventDefault();

  // Captcha solver couldn't run (no SubtleCrypto — old browser, HTTP
  // origin, etc.). Surface that with a clear message instead of letting
  // the submit hit the server with an empty nonce.
  if (form.dataset.captchaUnsupported === '1') {
    showGlobalError(form, describeGlobal('captcha_unsupported'));
    return;
  }

  clearErrors(form);
  const submitBtn = form.querySelector('[type="submit"]');
  const previousLabel = submitBtn ? submitBtn.textContent : null;
  if (submitBtn) {
    submitBtn.setAttribute('disabled', 'disabled');
  }

  try {
    // Wait for the proof-of-work captcha solver to fill the nonce
    // input. Resolves immediately if the search has already finished.
    await awaitCaptcha(form);

    const payload = collectPayload(form);
    const response = await fetch(form.action || window.location.pathname, {
      method: 'POST',
      headers: {
        'Accept': ENDPOINT_ACCEPT,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
    });

    /** @type {{ ok: boolean, redirect?: string|null, errors?: Record<string, string>, global_error?: string|null }} */
    const result = await response.json();

    if (result.ok && result.redirect) {
      window.location.assign(result.redirect);
      return;
    }
    if (!result.ok) {
      applyErrors(form, result);
    }
  } catch (err) {
    // Network error or non-JSON response — fall back to classic POST so
    // the user still gets a path forward.
    form.dataset.noFetch = '1';
    form.submit();
    return;
  } finally {
    if (submitBtn) {
      submitBtn.removeAttribute('disabled');
      if (previousLabel !== null) {
        submitBtn.textContent = previousLabel;
      }
    }
  }
}

/**
 * Read all named inputs of the form into a plain object. Multi-value
 * fields aren't part of this form, so we keep the simple "last wins"
 * shape FormData iteration produces.
 * @param {HTMLFormElement} form
 * @returns {Record<string, string|boolean>}
 */
function collectPayload(form) {
  /** @type {Record<string, string|boolean>} */
  const out = {};
  const data = new FormData(form);
  data.forEach((value, key) => {
    out[key] = typeof value === 'string' ? value : '';
  });
  // Honour the consent checkbox even when unchecked (FormData omits it):
  // an unchecked checkbox is missing → boolean false.
  form.querySelectorAll('input[type="checkbox"]').forEach((el) => {
    const name = el.getAttribute('name');
    if (name) {
      out[name] = el.checked;
    }
  });
  return out;
}

/**
 * Drop all visible error markers and messages so a new submission
 * starts from a clean slate.
 * @param {HTMLFormElement} form
 */
function clearErrors(form) {
  form.querySelectorAll('.field-error').forEach((el) => el.classList.remove('field-error'));
  form.querySelectorAll('.field-error-msg').forEach((el) => el.remove());
  const region = form.querySelector('[data-form-global-region]');
  if (region instanceof HTMLElement) {
    region.textContent = '';
    region.classList.add('contact-form-error-empty');
  }
}

/**
 * Render server-supplied errors next to the corresponding fields, plus
 * the global error banner above the form when present.
 * @param {HTMLFormElement} form
 * @param {{ errors?: Record<string, string>, global_error?: string|null }} result
 */
function applyErrors(form, result) {
  if (result.global_error) {
    showGlobalError(form, describeGlobal(result.global_error));
  }
  if (result.errors) {
    for (const [field, code] of Object.entries(result.errors)) {
      const input = form.querySelector(`[name="${cssEscape(field)}"]`);
      if (!input) {
        continue;
      }
      const wrap = input.closest('.field, .field-consent');
      if (!wrap) {
        continue;
      }
      wrap.classList.add('field-error');
      const msg = document.createElement('span');
      msg.className = 'field-error-msg';
      msg.textContent = describeFieldError(code);
      wrap.appendChild(msg);
    }
    // Move focus to the first error so keyboard users land on it.
    const firstErr = form.querySelector('.field-error input, .field-error textarea, .field-error select');
    if (firstErr) {
      firstErr.focus();
    }
  }
}

/**
 * The server emits short codes. JS doesn't have access to the i18n
 * dictionary, so we render generic English messages as a fallback. For
 * fully-localised inline errors, refresh the page (classic POST does
 * the right thing). Long-term improvement: ship the relevant strings
 * as a `<script type="application/json" data-i18n>` blob — keep the
 * payload tiny and CSP-safe.
 * @param {string} code
 */
function describeFieldError(code) {
  const map = {
    required:       'Required field.',
    too_short:      'Too short.',
    too_long:       'Too long.',
    invalid_email:  'Please enter a valid email address.',
    invalid_phone:  'Please enter a valid phone number.',
    invalid_choice: 'Invalid selection.',
    invalid_format: 'Invalid format.',
  };
  return map[code] || 'Invalid value.';
}

/** @param {string} code */
function describeGlobal(code) {
  const map = {
    token:                'Your session expired. Please reload the page and try again.',
    rate_limit:           'Too many submissions. Please wait a few minutes before trying again.',
    blocked:              'Submissions from your network are temporarily blocked. Please try again later.',
    mail_failed:          'Could not deliver your message right now. Please try again later.',
    captcha:              'Verification failed. Please reload the page and try again.',
    captcha_unsupported:  'Your browser cannot complete the verification step. Please update to a current browser, or open this page over HTTPS.',
  };
  return map[code] || 'Submission failed. Please try again.';
}

/**
 * Place a localised global-error string into the form's live region.
 * Reuses the server-rendered `[data-form-global-region]` element so
 * screen readers don't announce a duplicate insertion.
 *
 * @param {HTMLFormElement} form
 * @param {string} message
 */
function showGlobalError(form, message) {
  const region = form.querySelector('[data-form-global-region]');
  if (region instanceof HTMLElement) {
    region.textContent = message;
    region.classList.remove('contact-form-error-empty');
  }
}

/**
 * Lightweight CSS.escape polyfill for older runtimes. Only the
 * characters used in our field names actually need quoting.
 * @param {string} value
 */
function cssEscape(value) {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
    return CSS.escape(value);
  }
  return value.replace(/[^a-zA-Z0-9_-]/g, (c) => '\\' + c);
}
