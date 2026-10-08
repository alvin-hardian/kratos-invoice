/* Shared Grid.js boot helper. Each list page calls mountGrid() with its config. */
function fmtMoney(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }
function badge(text, cls) { return gridjs.html(badgeHtml(text, cls)); }
function badgeHtml(text, cls) { return '<span class="lbl ' + cls + '">' + escapeHtml(text) + '</span>'; }
function statusBadge(s) {
  var map = {draft: 'badge-draft', issued: 'badge-issued', accepted: 'badge-accepted', paid: 'badge-paid',
    rejected: 'badge-cancelled', cancelled: 'badge-cancelled', partial: 'badge-partial', unpaid: 'badge-unpaid'};
  return badge(s, map[s] || 'badge-draft');
}
function statusHtml(s) {
  var map = {draft: 'badge-draft', issued: 'badge-issued', accepted: 'badge-accepted', paid: 'badge-paid',
    rejected: 'badge-cancelled', cancelled: 'badge-cancelled', partial: 'badge-partial', unpaid: 'badge-unpaid'};
  return badgeHtml(s, map[s] || 'badge-draft');
}
function mountGrid(elId, opts) {
  var el = document.getElementById(elId);
  if (!el || typeof gridjs === 'undefined') return; // no-JS / failed load: server table stays
  var fallback = document.getElementById(elId + '-fallback');
  // Explicit fetch (not Grid.js server-mode) so search/sort/pagination always run client-side.
  fetch(el.getAttribute('data-url'), { headers: { 'Accept': 'application/json' } })
    .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function(rows) {
      var grid = new gridjs.Grid(Object.assign({
        data: rows,
        search: true,
        sort: true,
        pagination: { limit: 15 },
        language: { search: { placeholder: 'Search this table...' }, noRecordsFound: 'No matching records.' },
        style: { table: { width: '100%' } }
      }, opts));
      grid.render(el);
      if (fallback) fallback.style.display = 'none';
    })
    .catch(function() { /* keep server-rendered fallback visible */ });
}
function escapeHtml(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function fmtDateIso(iso) {
  var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
  if (!m) return '-';
  var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return m[3] + ' ' + months[parseInt(m[2], 10) - 1] + ' ' + m[1];
}
