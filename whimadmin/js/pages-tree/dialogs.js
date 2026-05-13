// WhimAdmin · pages-tree · modal helpers.
//
// Three dialog flavours built on top of native <dialog>:
//   prompt(opts)  — input + OK/Cancel; returns the input value or null.
//   select(opts)  — radio list + OK/Cancel; returns the chosen key.
//   confirm(opts) — message + OK/Cancel; returns true/false.
//
// Each helper clones its `<template data-tmpl="dialog-...">` block,
// wires the form, focuses the primary input, and removes the dialog
// from the DOM on close — no leftover state between invocations.
//
// Returns a Promise so callers can `await dialogs.prompt(...)`.

function cloneTmpl(name) {
  const tmpl = document.querySelector(`template[data-tmpl="${name}"]`);
  if (!tmpl) throw new Error(`Missing template: ${name}`);
  return tmpl.content.firstElementChild.cloneNode(true);
}

function setSlot(root, slot, value) {
  const el = root.querySelector(`[data-slot="${slot}"]`);
  if (!el) return;
  el.textContent = value;
  el.hidden = false;
}

export function prompt({ title, message = '', label = 'Value', initial = '', validate }) {
  return new Promise((resolve) => {
    const dialog = cloneTmpl('dialog-prompt');
    document.body.appendChild(dialog);

    setSlot(dialog, 'title', title);
    if (message) setSlot(dialog, 'message', message);
    setSlot(dialog, 'label', label);
    const input = dialog.querySelector('[data-slot="input"]');
    const errEl = dialog.querySelector('[data-slot="error"]');
    input.value = initial;

    const form = dialog.querySelector('form');
    let resolved = false;
    const cleanup = (result) => {
      if (resolved) return;
      resolved = true;
      dialog.close();
      dialog.remove();
      resolve(result);
    };

    form.addEventListener('submit', (e) => {
      const value = (input.value || '').trim();
      const submitter = e.submitter;
      if (submitter && submitter.value === 'cancel') {
        e.preventDefault();
        cleanup(null);
        return;
      }
      if (validate) {
        const msg = validate(value);
        if (msg) {
          e.preventDefault();
          errEl.textContent = msg;
          errEl.hidden = false;
          input.focus();
          input.select();
          return;
        }
      }
      e.preventDefault();
      cleanup(value);
    });
    dialog.addEventListener('cancel', (e) => { e.preventDefault(); cleanup(null); });

    dialog.showModal();
    input.focus();
    input.select();
  });
}

export function select({ title, message = '', options, initial = '' }) {
  // options: [{ value, label, description? }]
  return new Promise((resolve) => {
    const dialog = cloneTmpl('dialog-select');
    document.body.appendChild(dialog);

    setSlot(dialog, 'title', title);
    if (message) setSlot(dialog, 'message', message);

    const optsContainer = dialog.querySelector('[data-slot="options"]');
    let chosen = initial;
    for (const opt of options) {
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'radio';
      input.name = 'tree-dialog-select';
      input.value = opt.value;
      if (opt.value === initial) {
        input.checked = true;
        label.classList.add('is-selected');
      }
      const labelText = document.createElement('span');
      labelText.className = 'opt-label';
      labelText.textContent = opt.label;
      label.append(input, labelText);
      if (opt.description) {
        const desc = document.createElement('span');
        desc.className = 'opt-desc';
        desc.textContent = opt.description;
        label.appendChild(desc);
      }
      input.addEventListener('change', () => {
        chosen = input.value;
        optsContainer.querySelectorAll('label').forEach(l => l.classList.remove('is-selected'));
        label.classList.add('is-selected');
      });
      optsContainer.appendChild(label);
    }

    let resolved = false;
    const cleanup = (result) => {
      if (resolved) return;
      resolved = true;
      dialog.close();
      dialog.remove();
      resolve(result);
    };

    dialog.querySelector('form').addEventListener('submit', (e) => {
      e.preventDefault();
      const submitter = e.submitter;
      cleanup(submitter && submitter.value === 'cancel' ? null : chosen);
    });
    dialog.addEventListener('cancel', (e) => { e.preventDefault(); cleanup(null); });

    dialog.showModal();
    const firstInput = optsContainer.querySelector('input');
    if (firstInput) firstInput.focus();
  });
}

export function confirm({ title = 'Are you sure?', message, okLabel = 'Confirm', danger = true }) {
  return new Promise((resolve) => {
    const dialog = cloneTmpl('dialog-confirm');
    document.body.appendChild(dialog);

    setSlot(dialog, 'title', title);
    setSlot(dialog, 'message', message);
    const okBtn = dialog.querySelector('[data-slot="ok-btn"]');
    okBtn.textContent = okLabel;
    if (!danger) okBtn.classList.remove('is-danger');

    let resolved = false;
    const cleanup = (result) => {
      if (resolved) return;
      resolved = true;
      dialog.close();
      dialog.remove();
      resolve(result);
    };

    dialog.querySelector('form').addEventListener('submit', (e) => {
      e.preventDefault();
      const submitter = e.submitter;
      cleanup(submitter && submitter.value === 'ok');
    });
    dialog.addEventListener('cancel', (e) => { e.preventDefault(); cleanup(false); });

    dialog.showModal();
    okBtn.focus();
  });
}
