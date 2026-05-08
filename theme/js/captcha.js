/**
 * Proof-of-work captcha solver.
 *
 * The form template renders three hidden inputs plus three data
 * attributes:
 *
 *   <form data-contact-form
 *         data-captcha-salt="<hex>"
 *         data-captcha-difficulty="16"
 *         data-captcha-enabled="1">
 *     <input type="hidden" name="_captcha_token" value="<server-issued>" />
 *     <input type="hidden" name="_captcha_nonce" data-captcha-nonce />
 *
 * On page load we kick off a search for a `nonce` (decimal string) such
 * that the first `<difficulty>` bits of `sha256(salt + nonce)` are zero.
 * The result is written into the nonce input. The submit handler in
 * contact-form.js awaits this promise via `awaitCaptcha()` so a fast
 * submit doesn't outrun the worker.
 *
 * Implementation notes:
 *   - Uses Web Crypto's `crypto.subtle.digest`. Per-call promise overhead
 *     dominates total time but keeps the code dependency-free; native
 *     hashing means each call is microseconds.
 *   - Yields to the event loop every CHUNK iterations so a slow device
 *     stays responsive even at higher difficulty settings.
 *   - Fails open on devices without crypto.subtle (very old browsers) —
 *     the field stays empty, the server responds with the captcha-error
 *     state and the user can retry on a modern browser.
 */

const CHUNK = 256;

/** @type {WeakMap<HTMLFormElement, Promise<void>>} */
const inflight = new WeakMap();

/**
 * Boot all captcha-bearing forms on the page. Idempotent — re-bind is
 * a no-op (form-level guard).
 * @returns {void}
 */
export function initCaptcha() {
  const forms = document.querySelectorAll('form[data-captcha-enabled="1"]');
  forms.forEach((form) => {
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    if (form.dataset.captchaBound === '1') {
      return;
    }
    form.dataset.captchaBound = '1';

    const salt = form.dataset.captchaSalt || '';
    const difficulty = parseInt(form.dataset.captchaDifficulty || '0', 10);
    if (!salt || !Number.isFinite(difficulty) || difficulty <= 0) {
      return;
    }
    const nonceInput = form.querySelector('[data-captcha-nonce]');
    if (!(nonceInput instanceof HTMLInputElement)) {
      return;
    }
    if (typeof crypto === 'undefined' || !crypto.subtle) {
      // No SubtleCrypto — typically a non-secure context (HTTP) or a
      // legacy browser. Mark the form so the submit handler can show a
      // user-facing message instead of letting the request hit the
      // server with an empty nonce (server treats empty nonce as "no
      // strike, just fail" — see ContactController for the matching
      // server-side behaviour).
      form.dataset.captchaUnsupported = '1';
      return;
    }

    const promise = solve(salt, difficulty)
      .then((nonce) => {
        nonceInput.value = nonce;
      })
      .catch(() => {
        // Surfaced as the server-side captcha-error state on submit.
      });
    inflight.set(form, promise);
  });
}

/**
 * Resolve when the form's captcha solution has been written into its
 * nonce input. Used by the submit handler to gate sending.
 * @param {HTMLFormElement} form
 * @returns {Promise<void>}
 */
export function awaitCaptcha(form) {
  return inflight.get(form) || Promise.resolve();
}

/**
 * Brute-force search for a nonce that makes sha256(salt + nonce) start
 * with `difficulty` zero bits. Yields to the event loop every CHUNK
 * iterations so the UI stays responsive.
 *
 * @param {string} salt
 * @param {number} difficulty
 * @returns {Promise<string>}
 */
async function solve(salt, difficulty) {
  const enc = new TextEncoder();
  let n = 0;
  while (true) {
    for (let k = 0; k < CHUNK; k++) {
      const nonce = String(n);
      const buf = enc.encode(salt + nonce);
      const hashBuf = await crypto.subtle.digest('SHA-256', buf);
      if (leadingZeroBits(new Uint8Array(hashBuf)) >= difficulty) {
        return nonce;
      }
      n++;
    }
    // Yield to the event loop — keeps scrolling and other handlers
    // smooth even when the search runs for a couple of seconds.
    await new Promise((r) => setTimeout(r, 0));
  }
}

/**
 * @param {Uint8Array} bytes
 * @returns {number}
 */
function leadingZeroBits(bytes) {
  let count = 0;
  for (const b of bytes) {
    if (b === 0) {
      count += 8;
      continue;
    }
    for (let j = 7; j >= 0; j--) {
      if ((b >> j) & 1) {
        return count + (7 - j);
      }
    }
  }
  return count;
}
