// WhimAdmin · pages-tree · drag-and-drop.
//
// HTML5 native drag-and-drop on .tree-node-row plus the "+ New page"
// source button. Drop zones:
//
//   - before the hovered row (top half) → reorder above
//   - after the hovered row (bottom half) → reorder below
//   - INTO the hovered row, when the source is a different node AND
//     the target is type=folder OR has children (= becomes a child)
//   - onto an empty section's summary → append at end of section
//
// Cross-language drops are blocked (the controller rejects them
// anyway; we don't show a drop indicator to make that obvious).
// Drops involving the unsorted bucket are also blocked (see
// PagesTreeMutationController docs).
//
// IMPORTANT: We track the current drag source in a module-scope
// variable rather than via `e.dataTransfer.getData()`. Firefox
// blocks getData() during dragover/dragenter events for cross-
// origin protection — even though our drag is same-document, the
// security check fires anyway. Using a module variable also lets
// us cross-check cross-language at hover time without round-
// tripping through the transfer object.

// Stable token put on dataTransfer just so the browser recognises
// this as a drop-acceptable drag (Firefox needs at least one type
// set during dragstart for drop targets to receive events).
const DRAG_MARKER = 'application/x-whimadmin-tree';

// Module-scope state for in-flight drag.
let currentDrag = null;

export function attachDnd(treeContainer, callbacks) {
  // ---------- Source: any tree-node-row ----------
  treeContainer.addEventListener('dragstart', (e) => {
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;
    const row = target.closest('.tree-node-row');
    if (!row) return;
    currentDrag = {
      kind: 'move',
      lang: row.dataset.lang,
      section: row.dataset.section,
      indexPath: row.dataset.indexPath,
      // Slug — only set for slug-typed nodes (data-slug attribute is
      // absent on folder/anchor/href rows). The drop handler uses
      // this to pre-arm `selected` so the right-pane editor follows
      // the moved item to its new position without a re-click.
      slug: row.dataset.slug || null,
    };
    // dataTransfer marker — Firefox requires SOMETHING set during
    // dragstart for drop targets to receive events. The actual data
    // round-trip is unused; we rely on `currentDrag`.
    try { e.dataTransfer.setData(DRAG_MARKER, '1'); } catch {}
    e.dataTransfer.effectAllowed = 'move';
    row.classList.add('is-dragging');
  });

  treeContainer.addEventListener('dragend', () => {
    treeContainer.querySelectorAll('.is-dragging, .is-dropzone-before, .is-dropzone-after, .is-dropzone-into, .tree-section-summary.is-dropzone')
      .forEach(el => el.classList.remove('is-dragging', 'is-dropzone-before', 'is-dropzone-after', 'is-dropzone-into', 'is-dropzone'));
    currentDrag = null;
  });

  // ---------- Source: the "+ New page" button ----------
  const newBtn = document.querySelector('[data-new-page]');
  if (newBtn) {
    newBtn.addEventListener('dragstart', (e) => {
      currentDrag = { kind: 'new' };
      try { e.dataTransfer.setData(DRAG_MARKER, '1'); } catch {}
      e.dataTransfer.effectAllowed = 'copy';
      newBtn.classList.add('is-dragging');
    });
    newBtn.addEventListener('dragend', () => {
      newBtn.classList.remove('is-dragging');
      // Don't clear currentDrag here — the matching `drop` (in tree
      // container) may still fire AFTER dragend. The treeContainer's
      // dragend handler clears it for move-type drags; for new-page
      // drags ending without a drop, we still want a cleanup.
      // Schedule a microtask cleanup so a same-tick drop wins.
      setTimeout(() => { if (currentDrag?.kind === 'new') currentDrag = null; }, 0);
    });
  }

  // ---------- dragover: drop-zone preview ----------
  treeContainer.addEventListener('dragover', (e) => {
    if (!currentDrag) return; // ignore drags we didn't originate
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;

    // Clear all prior zone hints first; we'll re-add for the current hover.
    treeContainer.querySelectorAll('.is-dropzone-before, .is-dropzone-after, .is-dropzone-into, .tree-section-summary.is-dropzone')
      .forEach(el => el.classList.remove('is-dropzone-before', 'is-dropzone-after', 'is-dropzone-into', 'is-dropzone'));

    const row = target.closest('.tree-node-row');
    if (row) {
      // Block cross-language moves.
      if (currentDrag.kind === 'move' && currentDrag.lang !== row.dataset.lang) return;
      // Block reorder INSIDE unsorted — its order is derived from
      // routes.php, not editor-controlled. Drops INTO unsorted from
      // a regular section ARE allowed (demote from nav) and drops
      // FROM unsorted to a regular section are allowed (promote into
      // nav). The TreeMutator validates server-side too.
      if (currentDrag.kind === 'move'
          && currentDrag.section === 'unsorted'
          && row.dataset.section === 'unsorted') return;
      if (currentDrag.kind === 'new' && row.dataset.section === 'unsorted') {
        // Creating a brand-new entry directly in unsorted has no
        // sensible meaning (unsorted is route-derived). Refuse.
        return;
      }
      // Block dropping onto the same node OR a descendant of the
      // source (descendant drop = circular containment).
      if (currentDrag.kind === 'move'
          && currentDrag.section === row.dataset.section) {
        if (currentDrag.indexPath === row.dataset.indexPath) return;
        if (row.dataset.indexPath.startsWith(currentDrag.indexPath + '/')) return;
      }

      const rect = row.getBoundingClientRect();
      const y = e.clientY - rect.top;
      const zone = decideZone(y, rect.height, true);
      e.preventDefault();
      e.dataTransfer.dropEffect = currentDrag.kind === 'new' ? 'copy' : 'move';
      row.classList.add(`is-dropzone-${zone}`);
      return;
    }

    // Section summary → append at end of section.
    const sectionSummary = target.closest('.tree-section-summary');
    if (sectionSummary) {
      const section = sectionSummary.closest('.tree-section');
      if (!section) return;
      // Drop onto unsorted-section-summary = "remove from nav" for
      // section→unsorted moves. New-page drops or unsorted→unsorted
      // reorders are refused.
      if (section.dataset.sectionKey === 'unsorted') {
        if (currentDrag.kind === 'new') return;
        if (currentDrag.kind === 'move' && currentDrag.section === 'unsorted') return;
      }
      if (currentDrag.kind === 'move' && currentDrag.lang !== section.dataset.lang) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = currentDrag.kind === 'new' ? 'copy' : 'move';
      sectionSummary.classList.add('is-dropzone');
    }
  });

  // ---------- drop: dispatch the mutation ----------
  treeContainer.addEventListener('drop', async (e) => {
    e.preventDefault();
    const src = currentDrag;
    // Reset zone hints + module state regardless of outcome.
    treeContainer.querySelectorAll('.is-dropzone-before, .is-dropzone-after, .is-dropzone-into, .tree-section-summary.is-dropzone')
      .forEach(el => el.classList.remove('is-dropzone-before', 'is-dropzone-after', 'is-dropzone-into', 'is-dropzone'));
    currentDrag = null;
    if (!src) return;

    const target = e.target;
    if (!(target instanceof HTMLElement)) return;

    const row = target.closest('.tree-node-row');
    if (row) {
      if (src.kind === 'move' && src.lang !== row.dataset.lang) return;
      // Reorder INSIDE unsorted is meaningless; new-page-INTO-unsorted
      // has no schema entry. Section→unsorted and unsorted→section
      // moves are allowed and handled server-side.
      if (src.kind === 'move' && src.section === 'unsorted' && row.dataset.section === 'unsorted') return;
      if (src.kind === 'new' && row.dataset.section === 'unsorted') return;
      if (src.kind === 'move' && src.section === row.dataset.section) {
        if (src.indexPath === row.dataset.indexPath) return;
        if (row.dataset.indexPath.startsWith(src.indexPath + '/')) return;
      }
      const rect = row.getBoundingClientRect();
      const y = e.clientY - rect.top;
      const zone = decideZone(y, rect.height, true);
      const tgt = describeDropTarget(row, zone);
      await callbacks.onDrop(src, tgt);
      return;
    }
    const sectionSummary = target.closest('.tree-section-summary');
    if (sectionSummary) {
      const section = sectionSummary.closest('.tree-section');
      if (!section) return;
      // Drop onto the unsorted section summary = "remove from nav".
      // New-page drops + unsorted→unsorted reorders are refused.
      if (section.dataset.sectionKey === 'unsorted') {
        if (src.kind === 'new') return;
        if (src.kind === 'move' && src.section === 'unsorted') return;
      }
      if (src.kind === 'move' && src.lang !== section.dataset.lang) return;
      const lang = section.dataset.lang;
      const sectionKey = section.dataset.sectionKey;
      const itemsUl = section.querySelector('.tree-section-items');
      const childCount = itemsUl ? itemsUl.children.length : 0;
      await callbacks.onDrop(src, {
        lang,
        section: sectionKey,
        parentIndexPath: '',
        beforeIndex: childCount,
      });
    }
  });
}

function decideZone(y, height, canDropInto) {
  // Three-band split when into-drop is allowed: top 25% before,
  // bottom 25% after, middle 50% into. Otherwise split at midpoint.
  if (canDropInto) {
    if (y < height * 0.25) return 'before';
    if (y > height * 0.75) return 'after';
    return 'into';
  }
  return y < height / 2 ? 'before' : 'after';
}

function describeDropTarget(row, zone) {
  const lang = row.dataset.lang;
  const section = row.dataset.section;
  const indexPath = row.dataset.indexPath;
  if (zone === 'into') {
    return {
      lang,
      section,
      parentIndexPath: indexPath,
      beforeIndex: childCountOf(row),
    };
  }
  // before/after: same parent as the target, index relative to target.
  const lastSlash = indexPath.lastIndexOf('/');
  const parentIndexPath = lastSlash === -1 ? '' : indexPath.slice(0, lastSlash);
  const leaf = lastSlash === -1
    ? parseInt(indexPath, 10)
    : parseInt(indexPath.slice(lastSlash + 1), 10);
  return {
    lang,
    section,
    parentIndexPath,
    beforeIndex: zone === 'before' ? leaf : leaf + 1,
  };
}

function childCountOf(row) {
  const node = row.closest('.tree-node');
  const children = node?.querySelector('.tree-node-children');
  return children ? children.children.length : 0;
}
