/**
 * Vanilla lightbox.
 *
 * Behaviour:
 *   - Any anchor with `data-lightbox="1"` becomes a click trigger.
 *     The full-resolution variant is read from `data-full`, falling
 *     back to the anchor's `href`.
 *   - Triggers inside a common ancestor with `data-lightbox-group`
 *     navigate together (prev/next within the group). Without a
 *     group, the trigger is standalone.
 *   - Keyboard: Escape closes, ArrowLeft / ArrowRight cycle.
 *   - Click on the backdrop closes; click on the image is ignored so
 *     users don't dismiss while inspecting.
 *   - Body scroll is locked while open and the previously-focused
 *     element regains focus on close (basic focus management).
 *
 * No external dependencies, CSP-strict (no inline handlers, no eval).
 */

const TRIGGER_SELECTOR = 'a[data-lightbox="1"]';

/** @typedef {{ src: string, caption: string, alt: string }} LightboxItem */

let overlay = null;
let imgEl = null;
let captionEl = null;
let prevBtn = null;
let nextBtn = null;
let closeBtn = null;
/** @type {LightboxItem[]} */
let currentGroup = [];
let currentIndex = 0;
/** @type {Element | null} */
let lastFocused = null;

/**
 * Wire the document-level click delegate. Idempotent — guards against
 * double-bind if main.js calls it more than once.
 * @returns {void}
 */
export function initLightbox() {
  if (document.documentElement.dataset.lightboxBound === '1') {
    return;
  }
  document.documentElement.dataset.lightboxBound = '1';
  document.addEventListener('click', onDocumentClick);
}

/**
 * @param {MouseEvent} event
 */
function onDocumentClick(event) {
  const target = event.target instanceof Element ? event.target.closest(TRIGGER_SELECTOR) : null;
  if (!(target instanceof HTMLAnchorElement)) {
    return;
  }
  event.preventDefault();
  const group = collectGroup(target);
  const index = group.findIndex((g) => g.trigger === target);
  if (index < 0 || group.length === 0) {
    return;
  }
  open(group.map((g) => g.item), index, target);
}

/**
 * Build the navigation group: every lightbox trigger inside the same
 * `[data-lightbox-group]` ancestor. When no group ancestor exists, the
 * single trigger is its own group.
 *
 * @param {HTMLAnchorElement} trigger
 * @returns {{ trigger: HTMLAnchorElement, item: LightboxItem }[]}
 */
function collectGroup(trigger) {
  const groupRoot = trigger.closest('[data-lightbox-group]');
  const triggers = groupRoot
    ? Array.from(groupRoot.querySelectorAll(TRIGGER_SELECTOR))
    : [trigger];
  return triggers
    .filter((t) => t instanceof HTMLAnchorElement)
    .map((t) => /** @type {HTMLAnchorElement} */ (t))
    .map((t) => ({
      trigger: t,
      item: {
        src: t.dataset.full || t.href,
        caption: t.dataset.caption || '',
        alt: t.querySelector('img')?.alt || '',
      },
    }));
}

/**
 * @param {LightboxItem[]} group
 * @param {number} index
 * @param {Element} returnFocusTo
 */
function open(group, index, returnFocusTo) {
  currentGroup = group;
  currentIndex = index;
  lastFocused = returnFocusTo;

  if (overlay === null) {
    buildOverlay();
  }
  show(currentGroup[currentIndex]);

  // Lock scroll
  document.documentElement.classList.add('lightbox-open');

  // Bind keyboard once per session — overlay sticks around so we
  // remove the listener on close.
  document.addEventListener('keydown', onKeydown);

  if (overlay) {
    overlay.hidden = false;
    // Move focus into the overlay for keyboard users.
    closeBtn?.focus();
  }
}

function close() {
  if (overlay) {
    overlay.hidden = true;
  }
  document.documentElement.classList.remove('lightbox-open');
  document.removeEventListener('keydown', onKeydown);
  if (lastFocused instanceof HTMLElement) {
    lastFocused.focus();
  }
  currentGroup = [];
  currentIndex = 0;
}

/** @param {LightboxItem} item */
function show(item) {
  if (!imgEl || !captionEl) {
    return;
  }
  imgEl.src = item.src;
  imgEl.alt = item.alt;
  captionEl.textContent = item.caption;
  captionEl.hidden = item.caption === '';

  const hasMultiple = currentGroup.length > 1;
  if (prevBtn) prevBtn.hidden = !hasMultiple;
  if (nextBtn) nextBtn.hidden = !hasMultiple;
}

function navigate(delta) {
  if (currentGroup.length < 2) {
    return;
  }
  currentIndex = (currentIndex + delta + currentGroup.length) % currentGroup.length;
  show(currentGroup[currentIndex]);
}

/** @param {KeyboardEvent} e */
function onKeydown(e) {
  if (e.key === 'Escape') {
    e.preventDefault();
    close();
    return;
  }
  if (e.key === 'ArrowRight') {
    e.preventDefault();
    navigate(1);
    return;
  }
  if (e.key === 'ArrowLeft') {
    e.preventDefault();
    navigate(-1);
  }
}

/**
 * Build the overlay DOM lazily on first open. Reusing the same nodes
 * across opens keeps memory and layout-thrash low.
 */
function buildOverlay() {
  overlay = document.createElement('div');
  overlay.className = 'lightbox';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-modal', 'true');
  overlay.setAttribute('aria-label', 'Image viewer');
  overlay.hidden = true;

  const stage = document.createElement('div');
  stage.className = 'lightbox-stage';

  imgEl = document.createElement('img');
  imgEl.className = 'lightbox-img';
  imgEl.alt = '';
  stage.appendChild(imgEl);

  captionEl = document.createElement('div');
  captionEl.className = 'lightbox-caption';
  captionEl.hidden = true;
  stage.appendChild(captionEl);

  prevBtn = document.createElement('button');
  prevBtn.type = 'button';
  prevBtn.className = 'lightbox-nav lightbox-nav-prev';
  prevBtn.setAttribute('aria-label', 'Previous image');
  prevBtn.textContent = '‹';
  prevBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    navigate(-1);
  });

  nextBtn = document.createElement('button');
  nextBtn.type = 'button';
  nextBtn.className = 'lightbox-nav lightbox-nav-next';
  nextBtn.setAttribute('aria-label', 'Next image');
  nextBtn.textContent = '›';
  nextBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    navigate(1);
  });

  closeBtn = document.createElement('button');
  closeBtn.type = 'button';
  closeBtn.className = 'lightbox-close';
  closeBtn.setAttribute('aria-label', 'Close image viewer');
  closeBtn.textContent = '×';
  closeBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    close();
  });

  // Backdrop click closes; clicks bubbling up from the image/buttons
  // don't because we stop propagation in their handlers above.
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay || e.target === stage) {
      close();
    }
  });

  // Don't let clicks on the image itself bubble to the backdrop.
  imgEl.addEventListener('click', (e) => e.stopPropagation());

  overlay.appendChild(closeBtn);
  overlay.appendChild(prevBtn);
  overlay.appendChild(stage);
  overlay.appendChild(nextBtn);

  document.body.appendChild(overlay);
}
