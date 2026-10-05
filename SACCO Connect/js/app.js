/* ============================================================
   app.js — shared UI behavior across all dashboard pages
   ============================================================ */

document.addEventListener('DOMContentLoaded', function () {

  /* ---------- Mobile sidebar toggle ---------- */
  var menuToggle = document.querySelector('.menu-toggle');
  var sidebar = document.querySelector('.sidebar');
  var backdrop = document.querySelector('.sidebar-backdrop');

  function openSidebar() {
    if (sidebar) sidebar.classList.add('open');
    if (backdrop) backdrop.classList.add('show');
  }
  function closeSidebar() {
    if (sidebar) sidebar.classList.remove('open');
    if (backdrop) backdrop.classList.remove('show');
  }
  if (menuToggle) menuToggle.addEventListener('click', openSidebar);
  if (backdrop) backdrop.addEventListener('click', closeSidebar);

  /* ---------- Highlight active nav link based on current page ---------- */
  var currentPage = window.location.pathname.split('/').pop() || 'dashboard.html';
  document.querySelectorAll('.sidebar-nav a').forEach(function (link) {
    if (link.getAttribute('href') === currentPage) {
      link.classList.add('active');
    }
  });

  /* ---------- Logout confirmation ---------- */
  document.querySelectorAll('[data-logout]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      if (!confirm('Log out of your account?')) {
        e.preventDefault();
      }
    });
  });

  /* ---------- Simple client-side table search filter ---------- */
  document.querySelectorAll('[data-table-search]').forEach(function (input) {
    input.addEventListener('input', function () {
      var term = this.value.toLowerCase();
      var tableId = this.getAttribute('data-table-search');
      var table = document.getElementById(tableId);
      if (!table) return;
      table.querySelectorAll('tbody tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().indexOf(term) > -1 ? '' : 'none';
      });
    });
  });

  /* ---------- Online / offline sync indicator ----------
     Reflects the browser's connection state. When this system is deployed
     on a local server (XAMPP/WAMP) accessed over LAN, "online" here means
     reachable on the local network, not necessarily the public internet. */
  function updateSyncIndicator() {
    var indicator = document.querySelector('.sync-indicator');
    if (!indicator) return;
    if (navigator.onLine) {
      indicator.classList.remove('offline');
      indicator.innerHTML = '<span class="dot"></span> Connected &amp; synced';
    } else {
      indicator.classList.add('offline');
      indicator.innerHTML = '<span class="dot"></span> Offline — recording locally';
    }
  }
  updateSyncIndicator();
  window.addEventListener('online', updateSyncIndicator);
  window.addEventListener('offline', updateSyncIndicator);

});
