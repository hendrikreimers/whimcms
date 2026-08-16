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

import { awaitCaptcha, refreshCaptcha } from './captcha.js';

const ENDPOINT_ACCEPT = 'application/json';

/**
 * The server rejects a CSRF token younger than `csrf.min_age_seconds`
 * (default 3 s — the anti-bot speed limit). After fetching fresh
 * credentials the client therefore has to wait a moment before sending
 * again, otherwise it merely trades a replay error for a token error.
 * Rounded up to leave headroom.
 */
const MIN_TOKEN_AGE_MS = 3200;

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
    showGlobalError(form, describeGlobal(form, 'captcha_unsupported'));
    return;
  }

  clearErrors(form);
  const submitBtn = form.querySelector('[type="submit"]');
  // Swap the label node only — a themed button may carry further
  // children (icon, arrow) that must survive.
  const label = submitBtn ? (submitBtn.querySelector('span') || submitBtn) : null;
  const previousLabel = label ? label.textContent : null;
  if (submitBtn) {
    submitBtn.setAttribute('disabled', 'disabled');
  }
  form.setAttribute('aria-busy', 'true');
  showWorking(form, label);

  try {
    // Wait for the proof-of-work captcha solver to fill the nonce
    // input. Resolves immediately if the search has already finished.
    await awaitCaptcha(form);

    let result = await postOnce(form);
    if (result.ok && result.redirect) {
      window.location.assign(result.redirect);
      return;
    }

    // From here on the captcha we just sent is spent — the server
    // consumes it BEFORE validating the fields, so that one solved
    // proof-of-work cannot be reused to probe the validator. The form,
    // however, stays on screen holding the dead pair: without fresh
    // credentials every further click is a replay, which is exactly
    // what visitors report as "verification failed".
    //
    // When the visitor mistyped something, show that IMMEDIATELY and
    // re-arm afterwards — otherwise the message would sit behind the
    // new token's minimum age. Auto-retrying is pointless here: it
    // would resend the same invalid data and burn a rate-limit slot.
    if (result.errors && Object.keys(result.errors).length > 0) {
      applyErrors(form, result);
      await rearm(form);
      return;
    }

    // Not an input mistake but an expired or spent token: re-arm and
    // retry exactly once. Ideally the visitor notices nothing but a
    // short delay. Only these two codes are worth a second attempt —
    // `blocked`, `rate_limit` and `mail_failed` answer the same way
    // however often we ask.
    const recoverable = result.global_error === 'token' || result.global_error === 'captcha';
    const rearmed = recoverable ? await rearm(form) : false;

    if (rearmed) {
      const second = await postOnce(form);
      if (second.ok && second.redirect) {
        window.location.assign(second.redirect);
        return;
      }
      // That attempt spent its captcha too — show the message, then
      // re-arm once more so a manual click still has a chance.
      applyErrors(form, second);
      await rearm(form);
      return;
    }

    // Show the verdict FIRST. It was already final when the response
    // arrived, so making the visitor sit through a page fetch plus a
    // fresh proof-of-work before they get to read it only makes the form
    // look hung — same reasoning as the field-error branch above.
    applyErrors(form, result);
    // Re-arm afterwards all the same. The form still holds a spent pair,
    // and `mail_failed` in particular means the captcha WAS consumed, so
    // leaving it there would turn the visitor's next click into a replay
    // — and a replay costs a blocklist strike. Do NOT "simplify" this
    // away. Skipped when the re-arm above already ran and failed, since
    // repeating it would only repeat the failure.
    if (!recoverable) {
      await rearm(form);
    }
  } catch (err) {
    // Network error or non-JSON response — fall back to classic POST so
    // the user still gets a path forward. The server then re-renders the
    // page and issues fresh tokens itself, so this module's worst case
    // is an ordinary full-page post.
    //
    // Drop the nonce before that post. From here we cannot tell whether
    // the request reached the server: a non-JSON body (an error page,
    // typically a 500) means it did, and the pair is spent. Resending a
    // spent nonce is scored as a REPLAY — a blocklist strike against a
    // visitor for a fault that was ours. An empty nonce is read as
    // "solver never ran" instead: counted in a sliding window, but not
    // punished. The price of clearing unconditionally is that a genuine
    // network drop, where the submission never arrived, now costs one
    // more click rather than going through — the visitor lands on the
    // re-rendered page with their text intact and fresh credentials.
    // Cheap next to an undeserved 15-minute block.
    setHidden(form, '_captcha_nonce', '');
    form.dataset.noFetch = '1';
    form.submit();
    return;
  } finally {
    form.removeAttribute('aria-busy');
    if (submitBtn) {
      submitBtn.removeAttribute('disabled');
    }
    if (label && previousLabel !== null) {
      label.textContent = previousLabel;
    }
  }
}

/**
 * Send one submission and return the parsed JSON response. The payload
 * is re-read from the form on every call so that credentials refreshed
 * in between actually go out.
 *
 * @param {HTMLFormElement} form
 * @returns {Promise<{ ok: boolean, redirect?: string|null, errors?: Record<string, string>, global_error?: string|null }>}
 */
async function postOnce(form) {
  const response = await fetch(form.action || window.location.pathname, {
    method: 'POST',
    headers: {
      'Accept': ENDPOINT_ACCEPT,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(collectPayload(form)),
    credentials: 'same-origin',
  });
  return await response.json();
}

/**
 * Fetch fresh credentials without reloading the page and without
 * losing what the visitor typed.
 *
 * There is no endpoint that issues tokens — but every rendered page
 * carries a set. So we re-fetch the same page, parse it, and copy
 * `_token` / `_captcha_token` / salt / difficulty into the live form,
 * then solve the proof-of-work for the new salt.
 *
 * `cache: 'no-store'` is essential: a cached copy would hand back the
 * very token that just died.
 *
 * @param {HTMLFormElement} form
 * @returns {Promise<boolean>} true when the form can be submitted again
 */
async function rearm(form) {
  try {
    const response = await fetch(pageUrl(form), {
      headers: { 'Accept': 'text/html' },
      credentials: 'same-origin',
      cache: 'no-store',
    });
    if (!response.ok) {
      return false;
    }
    const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
    const fresh = doc.querySelector('[data-contact-form]');
    if (!fresh) {
      return false;
    }

    const token = hiddenValue(fresh, '_token');
    const captchaToken = hiddenValue(fresh, '_captcha_token');
    if (!token || !captchaToken) {
      return false;
    }
    setHidden(form, '_token', token);
    setHidden(form, '_captcha_token', captchaToken);

    // The new token's minimum-age clock starts here — deliberately
    // measured late, since the server issued it while rendering, a few
    // hundred milliseconds earlier. Better to wait a moment too long
    // than to miss the window by a hair.
    const issuedAt = Date.now();

    let armed = true;
    if (form.dataset.captchaEnabled === '1') {
      armed = await refreshCaptcha(
        form,
        fresh.getAttribute('data-captcha-salt') || '',
        parseInt(fresh.getAttribute('data-captcha-difficulty') || '0', 10),
      );
    }

    // The wait runs on EVERY path where tokens were replaced above —
    // including a failed re-solve. Otherwise a brand-new token would sit
    // in the form with the button already released, and the next click
    // would run into "invalid token".
    const age = Date.now() - issuedAt;
    if (age < MIN_TOKEN_AGE_MS) {
      await sleep(MIN_TOKEN_AGE_MS - age);
    }
    return armed;
  } catch (err) {
    return false;
  }
}

/**
 * The form's own page URL without the fragment — `action` carries
 * `#contact`, and a fragment has no business in a fetch.
 * @param {HTMLFormElement} form
 */
function pageUrl(form) {
  const raw = form.getAttribute('action') || window.location.href;
  const url = new URL(raw, window.location.href);
  url.hash = '';
  return url.toString();
}

/**
 * @param {ParentNode} scope
 * @param {string} name
 * @returns {string}
 */
function hiddenValue(scope, name) {
  const el = scope.querySelector(`input[name="${name}"]`);
  const value = el ? el.getAttribute('value') : null;
  return typeof value === 'string' ? value : '';
}

/**
 * @param {HTMLFormElement} form
 * @param {string} name
 * @param {string} value
 */
function setHidden(form, name, value) {
  const el = form.querySelector(`input[name="${name}"]`);
  if (el instanceof HTMLInputElement) {
    el.value = value;
  }
}

/** @param {number} ms */
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Put the submit button into a "working" state. Without it the button
 * just sits there disabled while the proof-of-work runs — on slow
 * devices, and after a tab switch (browsers throttle the solver's
 * timers), the visitor stares at a dead control for seconds and
 * concludes the form is broken.
 *
 * @param {HTMLFormElement} form
 * @param {Element|null} label
 */
function showWorking(form, label) {
  if (!label) {
    return;
  }
  const text = form.getAttribute('data-l-checking');
  if (text) {
    label.textContent = text;
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
    showGlobalError(form, describeGlobal(form, result.global_error));
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
      msg.textContent = describeFieldError(form, code);
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
 * The server emits short codes. The translated strings live in
 * `<paths.i18n>/<lang>.json` and are handed to this module by the form
 * partial as `data-l-*` attributes — the same pattern the layout uses
 * for its a11y labels. When they are absent (older template, third-party
 * theme) the built-in English text applies, so this module stays
 * self-sufficient.
 *
 * @param {HTMLFormElement} form
 * @param {string} attr      data attribute holding the translated text
 * @param {string} fallback  built-in English text
 * @returns {string}
 */
function localised(form, attr, fallback) {
  const value = attr ? form.getAttribute(attr) : null;
  return value !== null && value.trim() !== '' ? value : fallback;
}

/**
 * @param {HTMLFormElement} form
 * @param {string} code
 */
function describeFieldError(form, code) {
  const attrs = {
    required:       'data-l-err-required',
    too_short:      'data-l-err-too-short',
    too_long:       'data-l-err-too-long',
    invalid_email:  'data-l-err-invalid-email',
    invalid_phone:  'data-l-err-invalid-phone',
    invalid_choice: 'data-l-err-invalid-choice',
    invalid_format: 'data-l-err-invalid-format',
  };
  const fallbacks = {
    required:       'Required field.',
    too_short:      'Too short.',
    too_long:       'Too long.',
    invalid_email:  'Please enter a valid email address.',
    invalid_phone:  'Please enter a valid phone number.',
    invalid_choice: 'Invalid selection.',
    invalid_format: 'Invalid format.',
  };
  return localised(form, attrs[code], fallbacks[code] || 'Invalid value.');
}

/**
 * @param {HTMLFormElement} form
 * @param {string} code
 */
function describeGlobal(form, code) {
  const attrs = {
    token:                'data-l-glob-token',
    rate_limit:           'data-l-glob-rate-limit',
    blocked:              'data-l-glob-blocked',
    mail_failed:          'data-l-glob-mail-failed',
    captcha:              'data-l-glob-captcha',
    captcha_unsupported:  'data-l-glob-captcha-unsupported',
  };
  const fallbacks = {
    token:                'Your session expired. Please reload the page and try again.',
    rate_limit:           'Too many submissions. Please wait a few minutes before trying again.',
    blocked:              'Submissions from your network are temporarily blocked. Please try again later.',
    mail_failed:          'Could not deliver your message right now. Please try again later.',
    captcha:              'Verification failed. Please reload the page and try again.',
    captcha_unsupported:  'Your browser cannot complete the verification step. Please update to a current browser, or open this page over HTTPS.',
  };
  return localised(form, attrs[code], fallbacks[code] || 'Submission failed. Please try again.');
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
