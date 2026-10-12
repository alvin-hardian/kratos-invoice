/* Shared DataTables boot helper. Each list page calls mountDataTable() with its columns. */
function fmtMoney(n) { return 'Rp' + Math.round(n).toLocaleString('id-ID'); }
function badgeHtml(text, cls) { return '<span class="lbl ' + cls + '">' + escapeHtml(text) + '</span>'; }
function statusHtml(s) {
  var map = {draft: 'badge-draft', issued: 'badge-issued', accepted: 'badge-accepted', paid: 'badge-paid',
    rejected: 'badge-cancelled', cancelled: 'badge-cancelled', partial: 'badge-partial', unpaid: 'badge-unpaid'};
  return badgeHtml(s, map[s] || 'badge-draft');
}
/* Legacy Grid.js shims (no-ops now that Grid.js is removed). */
function badge(text, cls) { return badgeHtml(text, cls); }
function statusBadge(s) { return statusHtml(s); }
function moneyCell(d, type) {
  if (type === 'sort' || type === 'type') return Number(d) || 0;
  return '<span class="num">' + fmtMoney(d) + '</span>';
}
function dateCell(d, type) {
  if (type === 'sort' || type === 'type') return d || '';
  return fmtDateIso(d);
}
function mountDataTable(elId, columns, opts) {
  var el = document.getElementById(elId);
  if (!el || typeof DataTable === 'undefined') return; // no-JS / failed load: server table stays
  var fallback = document.getElementById(elId + '-fallback');
  fetch(el.getAttribute('data-url'), { headers: { 'Accept': 'application/json' } })
    .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function(rows) {
      new DataTable(el, Object.assign({
        data: rows,
        columns: columns,
        paging: true,
        pageLength: 15,
        lengthChange: false,
        searching: true,
        ordering: true,
        order: [],
        orderMulti: true,
        language: { search: 'Search this table:', searchPlaceholder: 'Search this table...', emptyTable: 'No matching records.', zeroRecords: 'No matching records.' },
        autoWidth: false
      }, opts || {}));
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
