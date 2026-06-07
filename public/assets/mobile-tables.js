/*
 * mobile-tables.js — ubah tabel data jadi kartu bertumpuk di layar HP.
 *
 * Bekerja otomatis untuk SEMUA tabel di dalam .main yang punya <thead>:
 * label kolom (th) dipakai sebagai judul tiap baris (td::before). Baris
 * grup/subtotal (punya colspan) & tfoot dibiarkan full-width. Tabel tanpa
 * <thead> dibungkus agar tetap bisa di-scroll samping (tidak menjebol layar).
 */
(function () {
  var MQ = window.matchMedia('(max-width: 768px)');

  function wrapForScroll(table) {
    var p = table.parentElement;
    if (!p || p.classList.contains('table-wrap') || table.dataset.mcWrapped) return;
    var wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    p.insertBefore(wrap, table);
    wrap.appendChild(table);
    table.dataset.mcWrapped = '1';
  }

  function rowHasColspan(tr) {
    for (var i = 0; i < tr.children.length; i++) {
      if ((tr.children[i].colSpan || 1) > 1) return true;
    }
    return false;
  }

  function labelize() {
    if (!MQ.matches) return; // hanya di mobile; CSS sudah men-scope class .mobile-cards ke media query

    document.querySelectorAll('.main table').forEach(function (table) {
      if (table.dataset.mcDone) return;

      // Ambil baris header daun (thead tr terakhir).
      var headRows = table.querySelectorAll('thead tr');
      var headRow = headRows.length ? headRows[headRows.length - 1] : null;
      var ths = headRow ? headRow.querySelectorAll('th') : [];

      if (!ths.length) {
        wrapForScroll(table);   // tidak ada header → cukup pastikan bisa scroll
        table.dataset.mcDone = '1';
        return;
      }

      var labels = Array.prototype.map.call(ths, function (th) {
        return th.textContent.trim();
      });

      table.querySelectorAll('tbody tr').forEach(function (tr) {
        if (rowHasColspan(tr)) { tr.classList.add('mc-full'); return; }
        var cells = tr.children;
        for (var j = 0; j < cells.length; j++) {
          if (cells[j].tagName === 'TD' && labels[j]) {
            cells[j].setAttribute('data-label', labels[j]);
          }
        }
      });

      table.classList.add('mobile-cards');
      var p = table.parentElement;
      if (p && p.classList.contains('table-wrap')) p.classList.add('mc-wrap');
      table.dataset.mcDone = '1';
    });
  }

  function run() { try { labelize(); } catch (e) {} }

  if (document.readyState !== 'loading') run();
  else document.addEventListener('DOMContentLoaded', run);

  if (MQ.addEventListener) MQ.addEventListener('change', run);
  else if (MQ.addListener) MQ.addListener(run);
})();
