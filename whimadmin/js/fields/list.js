// Dynamic list-item add / remove for the form-renderer's `list` fields.
//
// Server renders a `<template data-list-template>` with one extra item
// containing `__WIDX__` as the array-index placeholder in every
// `name=…` attribute. On + Add: clone the template, substitute the next
// integer index, append to the items container.
//
// On Remove: drop the DOM node; re-index every remaining item so PHP's
// $_POST nests them as a contiguous list.
//
// Kept dependency-free.

const PLACEHOLDER = '__WIDX__';

function nextIndex(itemsEl) {
  let max = -1;
  for (const item of itemsEl.querySelectorAll(':scope > [data-list-item]')) {
    for (const named of item.querySelectorAll('[name]')) {
      const m = named.getAttribute('name').match(/\[(\d+)\]/);
      if (m) max = Math.max(max, parseInt(m[1], 10));
    }
  }
  return max + 1;
}

function reindexItems(itemsEl, listName) {
  // listName is the parent path, e.g. "block[0][attr][items]".
  // Each item's named inputs should have their FIRST [number]
  // segment after listName replaced with the new index.
  const escaped = listName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp('^(' + escaped + ')\\[(\\d+|' + PLACEHOLDER + ')\\]');

  const items = itemsEl.querySelectorAll(':scope > [data-list-item]');
  items.forEach((item, idx) => {
    for (const named of item.querySelectorAll('[name]')) {
      const old = named.getAttribute('name');
      named.setAttribute('name', old.replace(re, '$1[' + idx + ']'));
    }
  });
}

function substitutePlaceholder(node, listName, index) {
  for (const named of node.querySelectorAll('[name]')) {
    const old = named.getAttribute('name');
    named.setAttribute('name', old.split(PLACEHOLDER).join(String(index)));
  }
}

document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLElement)) return;

  // + Add
  if (target.matches('[data-list-add]')) {
    const root = target.closest('[data-list-root]');
    if (!root) return;
    const itemsEl = root.querySelector(':scope > [data-list-items]');
    const tplEl = root.querySelector(':scope > [data-list-template]');
    if (!itemsEl || !tplEl) return;
    const listName = root.getAttribute('data-list-name') || '';
    const index = nextIndex(itemsEl);
    const fragment = tplEl.content ? tplEl.content.cloneNode(true) : null;
    if (!fragment) return;
    // After clone, walk every named element inside the fragment and
    // substitute the placeholder with the new integer index.
    const wrapper = document.createElement('div');
    wrapper.appendChild(fragment);
    substitutePlaceholder(wrapper, listName, index);
    while (wrapper.firstChild) {
      itemsEl.appendChild(wrapper.firstChild);
    }
    return;
  }

  // Remove
  if (target.matches('[data-list-remove]')) {
    const item = target.closest('[data-list-item]');
    if (!item) return;
    const root = item.closest('[data-list-root]');
    if (!root) return;
    const itemsEl = root.querySelector(':scope > [data-list-items]');
    const listName = root.getAttribute('data-list-name') || '';
    item.remove();
    reindexItems(itemsEl, listName);
  }
});
