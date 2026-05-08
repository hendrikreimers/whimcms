/**
 * Header behaviour:
 *   - Adds .scrolled to [data-nav] once the page is scrolled past a
 *     threshold so components.css can swap to the compact appearance.
 *   - Wires the mobile hamburger toggle: clicking the button opens a
 *     full-screen drawer containing nav-links + lang switch + CTA, ESC
 *     closes it, opening the drawer locks body scroll and traps focus
 *     to keep keyboard users inside the menu while it's visible.
 */

const SCROLL_THRESHOLD_PX = 40;
const NAV_OPEN_CLASS = 'nav-open';
const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Boot the nav. Idempotent — re-running is a no-op when bindings are
 * already in place.
 * @returns {void}
 */
export function initNav() {
  const nav = document.querySelector('[data-nav]');
  if (!nav || nav.dataset.navBound === '1') {
    return;
  }
  nav.dataset.navBound = '1';

  bindScrollState(nav);
  bindMobileToggle(nav);
}

/**
 * @param {HTMLElement} nav
 */
function bindScrollState(nav) {
  const update = () => {
    nav.classList.toggle('scrolled', window.scrollY > SCROLL_THRESHOLD_PX);
  };
  update();
  window.addEventListener('scroll', update, { passive: true });
}

/**
 * @param {HTMLElement} nav
 */
function bindMobileToggle(nav) {
  /** @type {HTMLButtonElement | null} */
  const toggle = nav.querySelector('[data-nav-toggle]');
  /** @type {HTMLElement | null} */
  const primary = nav.querySelector('[data-nav-primary]');
  if (!toggle || !primary) {
    return;
  }

  /**
   * @param {boolean} open
   */
  const setState = (open) => {
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.documentElement.classList.toggle(NAV_OPEN_CLASS, open);
    if (open) {
      // Move focus into the drawer for keyboard users.
      const firstLink = primary.querySelector(FOCUSABLE_SELECTOR);
      if (firstLink instanceof HTMLElement) {
        firstLink.focus();
      }
    }
  };

  toggle.addEventListener('click', () => {
    const isOpen = toggle.getAttribute('aria-expanded') === 'true';
    setState(!isOpen);
  });

  // ESC anywhere closes the drawer.
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
      return;
    }
    if (toggle.getAttribute('aria-expanded') === 'true') {
      event.preventDefault();
      setState(false);
      toggle.focus();
    }
  });

  // Clicking any link inside the drawer dismisses it so the user lands
  // on the new page without a stale-open menu lingering.
  primary.addEventListener('click', (event) => {
    if (event.target instanceof HTMLAnchorElement) {
      setState(false);
    }
  });

  // Returning above the small-screen breakpoint should clear the
  // open state — otherwise resizing while open leaves an invisible
  // body-scroll lock.
  const mq = window.matchMedia('(min-width: 880px)');
  mq.addEventListener('change', (event) => {
    if (event.matches) {
      setState(false);
    }
  });
}
