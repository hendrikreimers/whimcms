// Markdown toolbar — wraps / prefixes selection with the safe-subset
// Markdown markers the public-site renderer accepts.
//
// No syntax highlighting, no preview. Body still goes through the
// core's safe-subset Markdown renderer at public-render time.

const COMMANDS = {
  bold:      { wrap: '**' },
  italic:    { wrap: '*' },
  h2:        { prefix: '## ' },
  h3:        { prefix: '### ' },
  h4:        { prefix: '#### ' },
  ul:        { prefix: '- ' },
  code:      { wrap: '`' },
  codeblock: { custom: 'codeblock' },
  link:      { custom: 'link' },
};

function applyWrap(textarea, marker) {
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const before = textarea.value.slice(0, start);
  const sel = textarea.value.slice(start, end);
  const after = textarea.value.slice(end);
  textarea.value = before + marker + sel + marker + after;
  textarea.selectionStart = start + marker.length;
  textarea.selectionEnd = end + marker.length;
  textarea.focus();
}

function applyPrefix(textarea, prefix) {
  const start = textarea.selectionStart;
  let lineStart = start;
  while (lineStart > 0 && textarea.value[lineStart - 1] !== '\n') lineStart--;
  textarea.value = textarea.value.slice(0, lineStart) + prefix + textarea.value.slice(lineStart);
  textarea.selectionStart = textarea.selectionEnd = start + prefix.length;
  textarea.focus();
}

function applyLink(textarea) {
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const sel = textarea.value.slice(start, end) || 'text';
  const replacement = '[' + sel + '](https://)';
  textarea.value = textarea.value.slice(0, start) + replacement + textarea.value.slice(end);
  const cursorAt = start + 2 + sel.length + 2 + 'https://'.length;
  textarea.selectionStart = textarea.selectionEnd = cursorAt;
  textarea.focus();
}

function applyCodeBlock(textarea) {
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const sel = textarea.value.slice(start, end);
  const before = textarea.value.slice(0, start);
  const after  = textarea.value.slice(end);
  const leadNL  = before.length > 0 && !before.endsWith('\n') ? '\n' : '';
  const trailNL = after.length  > 0 && !after.startsWith('\n') ? '\n' : '';
  const innerNL = sel.endsWith('\n') || sel === '' ? '' : '\n';
  const block = leadNL + '```\n' + sel + innerNL + '```\n' + trailNL;
  textarea.value = before + block + after;
  // Cursor between fences when selection was empty.
  const cursorAt = before.length + leadNL.length + 4; // after "```\n"
  textarea.selectionStart = textarea.selectionEnd = cursorAt;
  textarea.focus();
}

document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLElement)) return;
  const btn = target.closest('[data-md-cmd]');
  if (!btn) return;
  const cmd = btn.getAttribute('data-md-cmd');
  const wrap = btn.closest('.field-md-wrap');
  if (!wrap) return;
  const textarea = wrap.querySelector('[data-md-target]');
  if (!(textarea instanceof HTMLTextAreaElement)) return;
  const spec = COMMANDS[cmd || ''];
  if (!spec) return;
  if (spec.wrap)   applyWrap(textarea, spec.wrap);
  if (spec.prefix) applyPrefix(textarea, spec.prefix);
  if (spec.custom === 'link')      applyLink(textarea);
  if (spec.custom === 'codeblock') applyCodeBlock(textarea);
});
