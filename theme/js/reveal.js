/**
 * Reveal-on-scroll. Elements marked with the .reveal class start invisible
 * (opacity 0, translateY); when they enter the viewport, the .in class is
 * added and CSS transitions the element into view.
 *
 * Falls back to revealing everything immediately when IntersectionObserver
 * isn't available, and forces any still-hidden elements into view after a
 * 1.5 s safety timeout — the same guarantee the original React hook gave.
 */

const VIEWPORT_THRESHOLD = 0.08;
const ROOT_MARGIN = '0px 0px -40px 0px';
const SAFETY_FALLBACK_MS = 1500;

/**
 * Attach the IntersectionObserver to all current .reveal elements.
 * Idempotent: already-revealed elements are skipped.
 * @returns {void}
 */
export function initReveal() {
  /** @type {NodeListOf<HTMLElement>} */
  const elements = document.querySelectorAll('.reveal:not(.in)');
  if (elements.length === 0) {
    return;
  }

  // Old browsers / disabled APIs: just show everything.
  if (typeof IntersectionObserver === 'undefined') {
    elements.forEach((el) => el.classList.add('in'));
    return;
  }

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          observer.unobserve(entry.target);
        }
      }
    },
    { threshold: VIEWPORT_THRESHOLD, rootMargin: ROOT_MARGIN }
  );

  elements.forEach((el) => observer.observe(el));

  // Safety: an element that never gets observed (zero-height, off-screen
  // until layout settles, etc.) should still appear. After the fallback,
  // force-reveal any holdouts and detach the observer.
  window.setTimeout(() => {
    document.querySelectorAll('.reveal:not(.in)').forEach((el) => {
      el.classList.add('in');
    });
    observer.disconnect();
  }, SAFETY_FALLBACK_MS);
}
