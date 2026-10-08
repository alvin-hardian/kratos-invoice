/* Minimal UI primitives: collapsible sidebar, mobile drawer, toasts, promise modal confirm. */
(function () {
  var SIDEBAR_KEY = 'kratos-sidebar-collapsed';

  function isDesktop() {
    return window.matchMedia && window.matchMedia('(min-width: 768px)').matches;
  }
  function applySidebar(collapsed) {
    var sb = document.getElementById('sidebar');
    if (!sb) return;
    sb.classList.toggle('w-60', !collapsed);
    sb.classList.toggle('w-16', collapsed);
    sb.querySelectorAll('.nav-label').forEach(function (el) { el.classList.toggle('hidden', collapsed); });
    try { localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
  }
  function closeTopLayer() {
    var drawer = document.getElementById('drawer');
    if (drawer && !drawer.classList.contains('hidden')) { drawer.classList.add('hidden'); return true; }
    return false;
  }
  // restore persisted desktop collapsed state
  try {
    if (isDesktop() && localStorage.getItem(SIDEBAR_KEY) === '1') applySidebar(true);
  } catch (e) { /* ignore */ }

  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-nav-toggle]')) {
      if (isDesktop()) {
        var sb = document.getElementById('sidebar');
        applySidebar(sb ? sb.classList.contains('w-60') : false);
      } else {
        var dr = document.getElementById('drawer');
        if (dr) dr.classList.remove('hidden');
      }
      return;
    }
    // legacy hooks (kept working)
    var open = ev.target.closest('[data-drawer-open]');
    var close = ev.target.closest('[data-drawer-close]');
    var drawer = document.getElementById('drawer');
    if (drawer) {
      if (open) drawer.classList.remove('hidden');
      if (close) drawer.classList.add('hidden');
    }
    var mOpen = ev.target.closest('[data-modal-open]');
    if (mOpen) {
      var m = document.getElementById(mOpen.getAttribute('data-modal-open'));
      if (m) { m.classList.remove('hidden'); m.classList.add('flex'); }
    }
    var mClose = ev.target.closest('[data-modal-close]');
    if (mClose) {
      var box = mClose.closest('.fixed');
      if (box) { box.classList.add('hidden'); box.classList.remove('flex'); }
    }
  });

  // Escape closes drawer first, then any open modal.
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    if (closeTopLayer()) return;
    document.querySelectorAll('#modal-root > .fixed').forEach(function (w) { w.remove(); });
  });

  window.toast = function (msg, type) {
    var box = document.getElementById('toasts');
    if (!box) return;
    var el = document.createElement('div');
    var danger = type === 'danger';
    el.className = 'rounded-lg border px-4 py-2.5 text-sm shadow-lg ' +
      (danger ? 'border-red-200 bg-red-600 text-white' : 'border-emerald-200 bg-emerald-600 text-white');
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(function () { el.remove(); }, 4000);
  };

  // Promise-based confirm dialog (API-compatible with previous UIkit.modal.confirm usage).
  window.confirmDialog = function (message) {
    return new Promise(function (resolve, reject) {
      var root = document.getElementById('modal-root');
      var wrap = document.createElement('div');
      wrap.className = 'fixed inset-0 z-50 flex items-center justify-center';
      wrap.innerHTML =
        '<div class="absolute inset-0 bg-slate-900/60"></div>' +
        '<div class="card relative w-full max-w-sm p-5">' +
        '<p class="text-sm text-slate-700">' + message.replace(/</g, '&lt;') + '</p>' +
        '<div class="mt-4 flex justify-end gap-2">' +
        '<button class="btn btn-default btn-sm" data-m="no">Cancel</button>' +
        '<button class="btn btn-danger btn-sm" data-m="yes">Delete</button>' +
        '</div></div>';
      root.appendChild(wrap);
      function done(ok) { wrap.remove(); if (ok) resolve(); else reject(new Error('cancelled')); }
      wrap.querySelector('[data-m="yes"]').onclick = function () { done(true); };
      wrap.querySelector('[data-m="no"]').onclick = function () { done(false); };
      wrap.firstElementChild.onclick = function () { done(false); };
    });
  };
})();
