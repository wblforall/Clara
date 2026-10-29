// Spread table helpers — dipakai di form input dan edit transaksi recurring.
// Global vars (spreadOverrides, spreadBaseTotal, dll) didefinisikan di PHP template.

// esc(): escape HTML untuk pembangunan innerHTML dari data DB (anti stored-XSS).
// Sumber tunggal — dipanggil oleh picker transaksi & penawaran (form pakai layout()
// yang memuat file ini). Idempoten agar aman bila skrip dimuat lebih dari sekali.
window.esc = window.esc || function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
    });
};

function parseLocalDate(s) {
    var p = s.split('-'); return new Date(parseInt(p[0]), parseInt(p[1])-1, parseInt(p[2]));
}

function spreadMonths(startVal, endVal, pricingType, cycleRecognition) {
    var BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    var months = [];
    if (pricingType === 'monthly') {
        var cursor = parseLocalDate(startVal);
        var endDate = parseLocalDate(endVal);
        var limit = 120;
        while (cursor <= endDate && limit-- > 0) {
            var nextAnchor = new Date(cursor.getFullYear(), cursor.getMonth()+1, cursor.getDate());
            var cycleEnd = new Date(nextAnchor.getFullYear(), nextAnchor.getMonth(), nextAnchor.getDate()-1);
            if (cycleEnd > endDate) cycleEnd = new Date(endDate);
            var periodDate = (cycleRecognition === 'cycle_end') ? cycleEnd : cursor;
            var y = periodDate.getFullYear(), mo = periodDate.getMonth();
            months.push({ key: y+'-'+String(mo+1).padStart(2,'0'), label: BULAN[mo]+' '+y });
            cursor = new Date(cycleEnd.getFullYear(), cycleEnd.getMonth(), cycleEnd.getDate()+1);
        }
    } else {
        var cur = new Date(startVal.substring(0,7)+'-01'), e = new Date(endVal.substring(0,7)+'-01');
        while (cur <= e) {
            var y = cur.getFullYear(), mo = cur.getMonth();
            months.push({ key: y+'-'+String(mo+1).padStart(2,'0'), label: BULAN[mo]+' '+y });
            cur = new Date(y, mo+1, 1);
        }
    }
    return months;
}

function spreadAmounts(total, months) {
    var overSum = 0, overKeys = {};
    months.forEach(function(m){ if (spreadOverrides[m.key]!==undefined){ overSum+=spreadOverrides[m.key]; overKeys[m.key]=1; } });
    var free = months.filter(function(m){ return !overKeys[m.key]; });
    var rem = total - overSum, n2 = free.length, base = n2>0 ? Math.floor(rem/n2) : 0;
    var out = {}, run = 0;
    free.forEach(function(m,i){ var a=(i===n2-1)?Math.round(rem-run):base; out[m.key]=a; run+=a; });
    months.forEach(function(m){ if(overKeys[m.key]) out[m.key]=spreadOverrides[m.key]; });
    return out;
}

function computeNewBase(total, months) {
    var n = months.length;
    if (!n) return {};
    var base = Math.floor(total / n), out = {}, run = 0;
    months.forEach(function(m, i) {
        var a = (i === n-1) ? Math.round(total - run) : base;
        out[m.key] = a; run += a;
    });
    return out;
}

function useAllNewBase() {
    spreadOverrides = {};
    renderSpreadTable();
}

function renderSpreadTable() {
    var spreadDiv = document.getElementById('kalkulasi-spread');
    if (!spreadDiv || !spreadBaseStart || !spreadBaseEnd) return;
    var months = spreadMonths(spreadBaseStart, spreadBaseEnd, spreadBasePricing, spreadBaseCycle);
    if (!months.length) { spreadDiv.style.display='none'; return; }
    var newBase = computeNewBase(spreadBaseTotal, months);
    var amts    = spreadAmounts(spreadBaseTotal, months);
    var grand   = months.reduce(function(s,m){ return s+(amts[m.key]||0); }, 0);

    var hasDiff = months.some(function(m){
        return spreadOverrides[m.key]!==undefined && Math.abs(spreadOverrides[m.key]-(newBase[m.key]||0))>1;
    });

    var rows = '';
    months.forEach(function(m) {
        var locked  = spreadOverrides[m.key]!==undefined;
        var amt     = amts[m.key]||0;
        var newAmt  = newBase[m.key]||0;
        var isDiff  = locked && Math.abs(amt - newAmt) > 1;
        var badge   = locked ? '<span style="font-size:10px;background:#dbeafe;color:#1d4ed8;padding:1px 5px;border-radius:3px;margin-left:4px;font-weight:600">KHUSUS</span>' : '';
        var rst     = locked ? '<button type="button" onclick="clearSpreadOvr(\''+m.key+'\')" style="font-size:10px;padding:1px 5px;margin-left:4px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:3px;cursor:pointer">Reset</button>' : '';
        var ibg     = locked ? 'background:#dbeafe;font-weight:700;' : '';
        var diffCell = isDiff
            ? '<td style="padding:3px 0 3px 10px;font-size:11px;color:#6b7280;white-space:nowrap">'
              + '→ baru: <strong style="color:#0369a1">'+newAmt.toLocaleString('id-ID')+'</strong>'
              + ' <button type="button" onclick="clearSpreadOvr(\''+m.key+'\')" style="font-size:10px;padding:1px 6px;margin-left:2px;background:#0ea5e9;color:#fff;border:none;border-radius:3px;cursor:pointer">Pakai</button>'
              + '</td>'
            : '<td></td>';
        rows += '<tr data-row="'+m.key+'">'
              + '<td data-sel="label" style="padding:3px 8px 3px 0;color:#374151;white-space:nowrap">'+m.label+badge+rst+'</td>'
              + '<td style="padding:3px 0;text-align:right"><input type="text" inputmode="numeric" data-period="'+m.key+'" '
              + 'data-asal="'+amt.toLocaleString('id-ID')+'" value="'+amt.toLocaleString('id-ID')+'" '
              + 'style="text-align:right;width:140px;font-size:13px;'+ibg+'" '
              + 'onchange="setSpreadOvr(\''+m.key+'\',this.value)" oninput="fmtSpreadInp(this)"></td>'
              + diffCell.replace('<td', '<td data-sel="diff"')
              + '</tr>';
    });

    var newTotal = months.reduce(function(s,m){ return s+(newBase[m.key]||0); }, 0);
    var header = '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">'
        + '<span data-sel="judul" style="font-weight:700;color:#0369a1">Spread ('+months.length+' bulan) | Total: Rp '+grand.toLocaleString('id-ID')+'</span>'
        + '<button type="button" data-sel="pakai-semua" onclick="useAllNewBase()" style="font-size:11px;padding:2px 10px;background:#0ea5e9;color:#fff;border:none;border-radius:4px;cursor:pointer'
        + (hasDiff ? '' : ';display:none') + '">Pakai Semua Baru (Rp '+newTotal.toLocaleString('id-ID')+')</button>'
        + '</div>';

    spreadDiv.innerHTML = header + '<table style="border-collapse:collapse;background:transparent;width:100%">'+rows+'</table>';
    spreadDiv.style.display = 'block';
    syncOvrInputs();
}

/* Memformat ribuan sambil diketik TANPA melemparkan kursor ke ujung. Dulu
   nilainya ditulis ulang apa adanya, sehingga menyisipkan satu angka di tengah
   langsung memindahkan kursor ke paling kanan. */
function fmtSpreadInp(inp) {
    var r = inp.value.replace(/\D/g, '');
    var baru = r ? parseInt(r, 10).toLocaleString('id-ID') : '';
    if (baru === inp.value) return;
    var kanan = inp.value.length - (inp.selectionStart || 0);
    inp.value = baru;
    try { var pos = Math.max(0, baru.length - kanan); inp.setSelectionRange(pos, pos); } catch (e) {}
}

/* Mengubah nominal satu bulan TIDAK BOLEH membangun ulang seluruh tabel.
   Dulu setSpreadOvr memanggil renderSpreadTable(), dan karena ia menimpa
   innerHTML, kotak yang sedang diklik ikut terhapus di tengah klik: fokusnya
   terlempar entah ke mana — sering mendarat di kolom tanggal sehingga kalender
   terbuka sendiri padahal yang diisi nominal. Sekarang hanya penanda, warna,
   dan angka totalnya yang disegarkan; kotaknya sendiri tidak disentuh. */
function setSpreadOvr(k, v) {
    var r = String(v).replace(/\D/g, '');
    if (!r) { clearSpreadOvr(k); return; }
    spreadOverrides[k] = parseInt(r, 10);
    segarkanSpread();
}
function clearSpreadOvr(k) {
    delete spreadOverrides[k];
    var inp = document.querySelector('#kalkulasi-spread input[data-period="' + k + '"]');
    if (inp) {
        var amts = spreadAmounts(spreadBaseTotal, spreadMonths(spreadBaseStart, spreadBaseEnd, spreadBasePricing, spreadBaseCycle));
        inp.value = (amts[k] || 0).toLocaleString('id-ID');
        inp.setAttribute('data-asal', inp.value);   // kembali jadi angka otomatis
    }
    segarkanSpread();
}

/* Menyegarkan tampilan tanpa mengganti satu pun kotak isian. */
function segarkanSpread() {
    var spreadDiv = document.getElementById('kalkulasi-spread');
    if (!spreadDiv || !spreadBaseStart || !spreadBaseEnd) return;
    var months  = spreadMonths(spreadBaseStart, spreadBaseEnd, spreadBasePricing, spreadBaseCycle);
    var newBase = computeNewBase(spreadBaseTotal, months);
    var amts    = spreadAmounts(spreadBaseTotal, months);
    var grand   = months.reduce(function (s, m) { return s + (amts[m.key] || 0); }, 0);
    var hasDiff = false;

    months.forEach(function (m) {
        var tr = spreadDiv.querySelector('tr[data-row="' + m.key + '"]');
        if (!tr) return;
        var locked = spreadOverrides[m.key] !== undefined;
        var newAmt = newBase[m.key] || 0;
        var isDiff = locked && Math.abs((amts[m.key] || 0) - newAmt) > 1;
        if (isDiff) hasDiff = true;

        var inp = tr.querySelector('input[data-period]');
        if (inp) {
            inp.style.background = locked ? '#dbeafe' : '';
            inp.style.fontWeight = locked ? '700' : '';
        }
        var lbl = tr.querySelector('[data-sel="label"]');
        if (lbl) {
            lbl.innerHTML = m.label
                + (locked ? '<span style="font-size:10px;background:#dbeafe;color:#1d4ed8;padding:1px 5px;border-radius:3px;margin-left:4px;font-weight:600">KHUSUS</span>' : '')
                + (locked ? '<button type="button" onclick="clearSpreadOvr(\'' + m.key + '\')" style="font-size:10px;padding:1px 5px;margin-left:4px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:3px;cursor:pointer">Reset</button>' : '');
        }
        var dif = tr.querySelector('[data-sel="diff"]');
        if (dif) {
            dif.innerHTML = isDiff
                ? '→ baru: <strong style="color:#0369a1">' + newAmt.toLocaleString('id-ID') + '</strong>'
                  + ' <button type="button" onclick="clearSpreadOvr(\'' + m.key + '\')" style="font-size:10px;padding:1px 6px;margin-left:2px;background:#0ea5e9;color:#fff;border:none;border-radius:3px;cursor:pointer">Pakai</button>'
                : '';
        }
    });

    var judul = spreadDiv.querySelector('[data-sel="judul"]');
    if (judul) judul.textContent = 'Spread (' + months.length + ' bulan) | Total: Rp ' + grand.toLocaleString('id-ID');
    var tombol = spreadDiv.querySelector('[data-sel="pakai-semua"]');
    if (tombol) {
        var newTotal = months.reduce(function (s, m) { return s + (newBase[m.key] || 0); }, 0);
        tombol.style.display = hasDiff ? '' : 'none';
        tombol.textContent = 'Pakai Semua Baru (Rp ' + newTotal.toLocaleString('id-ID') + ')';
    }
    syncOvrInputs();
}

/* Hanya bulan yang nominalnya BERBEDA dari pembagian otomatis yang dianggap
   "ditentukan sendiri". Dulu setiap kotak yang terbaca ikut dicatat sebagai
   nilai khusus — jadi menyimpan formulir sekali saja sudah memaku SELURUH
   bulan, dan aturan bulan pengakuan tidak lagi punya satu pun bulan untuk
   diatur. Tabel yang sedang disembunyikan (pengakuan bukan "dibagi rata")
   tidak dibaca sama sekali. */
function flushSpreadInputs() {
    var spreadDiv = document.getElementById('kalkulasi-spread');
    if (!spreadDiv) return;
    if (spreadDiv.style.display === 'none') { spreadOverrides = {}; return; }
    spreadDiv.querySelectorAll('input[data-period]').forEach(function(inp) {
        var k = inp.getAttribute('data-period');
        var raw = inp.value.trim();
        if (!raw || raw.charAt(0) === '-') return;
        var r = raw.replace(/\D/g,'');
        if (!r) return;
        // Kotak yang isinya masih sama dengan yang dirender tidak pernah
        // diketik orang — jangan dicatat sebagai nilai khusus. Yang sudah
        // tercatat (karena diketik, atau memang tersimpan) tetap dipertahankan.
        var asal = (inp.getAttribute('data-asal') || '').replace(/\D/g,'');
        if (asal !== '' && asal === r && spreadOverrides[k] === undefined) return;
        spreadOverrides[k] = parseInt(r, 10);
    });
}

/* Nominal per bulan ikut terkirim walau tabelnya tidak pernah disentuh.
   Dulu input tersembunyinya hanya dibuat saat tabel dirender ulang — jadi
   membuka Edit lalu langsung Simpan mengirim formulir TANPA nominal per bulan,
   dan seluruh angka khusus yang sudah tersimpan terhapus diam-diam. */
document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.querySelector) return;
    if (!document.getElementById('kalkulasi-spread')) return;
    if (typeof spreadOverrides === 'undefined' || !Object.keys(spreadOverrides).length) return;
    if (!f.querySelector('[name="recognition_month"], [name="pricing_type"]')) return;
    syncOvrInputs();
}, true);

function syncOvrInputs() {
    flushSpreadInputs();
    var form = document.querySelector('form');
    if (!form) return;
    form.querySelectorAll('input[name^="month_overrides["]').forEach(function(el){ el.remove(); });
    Object.keys(spreadOverrides).forEach(function(k) {
        var inp = document.createElement('input'); inp.type = 'hidden';
        inp.name = 'month_overrides['+k+']'; inp.value = spreadOverrides[k]; form.appendChild(inp);
    });
}
