// WhimAdmin · pages-tree · API client.
//
// Thin wrapper around the JSON endpoints. Holds the CSRF token and
// the most recently observed tree version; every mutation re-binds
// both from the server's response so the next call carries fresh
// state without an extra GET.

const JSON_HEADERS = {
  'Content-Type': 'application/json',
  'Accept': 'application/json',
};

export class TreeApi {
  constructor(basePath) {
    this.base = basePath.replace(/\/+$/, '');
    this.token = '';
    this.version = '';
  }

  async getTree() {
    const r = await fetch(`${this.base}/pages/tree`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    });
    if (!r.ok) throw new Error(`tree fetch failed: ${r.status}`);
    const data = await r.json();
    if (typeof data.csrfToken === 'string') this.token = data.csrfToken;
    if (typeof data.version === 'string') this.version = data.version;
    return data;
  }

  async getTypes() {
    const r = await fetch(`${this.base}/pages/tree/types`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    });
    if (!r.ok) throw new Error(`types fetch failed: ${r.status}`);
    return r.json();
  }

  /**
   * Fetch detail for one node, including front-matter values for
   * slug-typed entries. Called by the editor when a row is selected
   * so the right-pane form starts populated with real values instead
   * of empty placeholders.
   */
  async getNode(lang, section, indexPath) {
    const qs = new URLSearchParams({ lang, section, indexPath }).toString();
    const r = await fetch(`${this.base}/pages/tree/node?${qs}`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    });
    let data = null;
    try { data = await r.json(); } catch { /* malformed body */ }
    if (!r.ok) {
      const err = new Error(data?.message || `HTTP ${r.status}`);
      err.status = r.status;
      err.code = data?.error || 'http';
      throw err;
    }
    return data || {};
  }

  async post(action, body) {
    const payload = { ...body, treeVersion: this.version };
    const r = await fetch(`${this.base}/pages/tree/${action}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { ...JSON_HEADERS, 'X-CSRF-Token': this.token },
      body: JSON.stringify(payload),
    });
    let data = null;
    try { data = await r.json(); } catch { /* malformed body */ }
    if (!r.ok) {
      const err = new Error(data?.message || `HTTP ${r.status}`);
      err.status = r.status;
      err.code = data?.error || 'http';
      err.currentVersion = data?.currentVersion || null;
      throw err;
    }
    if (typeof data?.treeVersion === 'string') this.version = data.treeVersion;
    return data || {};
  }

  create(args)  { return this.post('create',  args); }
  move(args)    { return this.post('move',    args); }
  rename(args)  { return this.post('rename',  args); }
  retype(args)  { return this.post('retype',  args); }
  remove(args)  { return this.post('delete',  args); }
  save(args)    { return this.post('save',    args); }
}
