// Drag-and-drop reordering for content blocks on the page-edit form.
//
// HTML5 drag-and-drop API. Listens at the document level so dynamically-
// added blocks (after server round-trip) work without re-registration.
//
// On drop: the dragged <details class="block-edit"> is moved before /
// after the drop target based on mouse Y. After every reorder, all
// blocks are RENUMBERED — every `name="block[<old>][...]"` rewritten
// to use the new visual index, every action button value (`remove:<N>`,
// `move-up:<N>`, …) updated. Without that, clicking Remove on a
// dragged-but-not-yet-saved block would target the original-order
// index server-side and remove the wrong block.
//
// Works because PHP `$_POST['block']` preserves DOM submission order,
// and our `FormDecoder` iterates without re-sorting (so DOM order is
// the persisted order on save).

let draggedEl = null;

function isBlockTarget(node) {
  return node instanceof HTMLElement ? node.closest('.block-edit') : null;
}

function clearDropMarkers() {
  for (const el of document.querySelectorAll('.block-edit.drop-before, .block-edit.drop-after')) {
    el.classList.remove('drop-before', 'drop-after');
  }
}

document.addEventListener('dragstart', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLElement)) return;
  // Defence-in-depth: only the .block-drag-handle inside the summary
  // carries draggable="true" today, so dragstart should never fire
  // from a form control. If a future change adds draggable elsewhere,
  // this check keeps text selection in inputs working.
  if (target.closest('input, textarea, select, button, [data-list-item]')) {
    return;
  }
  const block = isBlockTarget(target);
  if (!block) return;
  draggedEl = block;
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
    // Some browsers (Firefox) require non-empty dataTransfer to start a drag.
    event.dataTransfer.setData('text/plain', block.dataset.blockIndex || '');
  }
  block.classList.add('block-dragging');
});

document.addEventListener('dragover', (event) => {
  if (!draggedEl) return;
  const target = isBlockTarget(event.target);
  if (!target || target === draggedEl) return;
  event.preventDefault();
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';

  clearDropMarkers();
  const rect = target.getBoundingClientRect();
  const before = event.clientY < rect.top + rect.height / 2;
  target.classList.add(before ? 'drop-before' : 'drop-after');
});

document.addEventListener('dragleave', (event) => {
  // Only clear if leaving a block (not a child element).
  const target = isBlockTarget(event.target);
  if (target && target !== event.relatedTarget && !target.contains(event.relatedTarget)) {
    target.classList.remove('drop-before', 'drop-after');
  }
});

document.addEventListener('drop', (event) => {
  // Capture the source BEFORE cleanup — `cleanupDrag()` nulls the
  // module-level `draggedEl`, and `insertBefore(null, …)` is a
  // no-op / TypeError. Without this the drop did nothing visible.
  const dragged = draggedEl;
  if (!dragged) return;
  const target = isBlockTarget(event.target);
  if (!target || target === dragged) {
    cleanupDrag();
    return;
  }
  event.preventDefault();
  const rect = target.getBoundingClientRect();
  const before = event.clientY < rect.top + rect.height / 2;
  if (target.parentNode) {
    if (before) {
      target.parentNode.insertBefore(dragged, target);
    } else {
      target.parentNode.insertBefore(dragged, target.nextSibling);
    }
  }
  renumberBlocks();
  cleanupDrag();
});

document.addEventListener('dragend', cleanupDrag);

function cleanupDrag() {
  if (draggedEl) {
    draggedEl.classList.remove('block-dragging');
    draggedEl = null;
  }
  clearDropMarkers();
}

/**
 * Walk every `.block-edit` inside `.blocks`, assign sequential indices
 * 0..N-1 based on current DOM order, and rewrite each block's input
 * names + action button values to reflect the new index.
 */
function renumberBlocks() {
  const container = document.querySelector('.blocks');
  if (!container) return;
  const blocks = Array.from(container.querySelectorAll(':scope > .block-edit'));
  blocks.forEach((block, newIdx) => {
    const oldIdx = block.dataset.blockIndex;
    if (oldIdx === String(newIdx)) return;
    block.dataset.blockIndex = String(newIdx);
    const oldPrefix = 'block[' + oldIdx + ']';
    const newPrefix = 'block[' + newIdx + ']';
    // Rewrite every form input/button name that starts with the old block prefix.
    for (const el of block.querySelectorAll('[name]')) {
      const old = el.getAttribute('name') || '';
      if (old.startsWith(oldPrefix)) {
        el.setAttribute('name', newPrefix + old.slice(oldPrefix.length));
      }
    }
    // Rewrite action button values like "remove:5", "move-up:5".
    for (const btn of block.querySelectorAll('button[name="action"][value]')) {
      const v = btn.getAttribute('value') || '';
      const m = v.match(/^([a-z][a-z-]*):\d+$/);
      if (m) {
        btn.setAttribute('value', m[1] + ':' + newIdx);
      }
    }
  });
}
