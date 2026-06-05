// Spread table helpers — dipakai di form input dan edit transaksi recurring.
// Global vars (spreadOverrides, spreadBaseTotal, dll) didefinisikan di PHP template.

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
        rows += '<tr>'
              + '<td style="padding:3px 8px 3px 0;color:#374151;white-space:nowrap">'+m.label+badge+rst+'</td>'
              + '<td style="padding:3px 0;text-align:right"><input type="text" inputmode="numeric" data-period="'+m.key+'" value="'+amt.toLocaleString('id-ID')+'" '
              + 'style="text-align:right;width:140px;font-size:13px;'+ibg+'" '
              + 'onchange="setSpreadOvr(\''+m.key+'\',this.value)" oninput="fmtSpreadInp(this)"></td>'
              + diffCell
              + '</tr>';
    });

    var header = '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">'
        + '<span style="font-weight:700;color:#0369a1">Spread ('+months.length+' bulan) | Total: Rp '+grand.toLocaleString('id-ID')+'</span>';
    if (hasDiff) {
        var newTotal = months.reduce(function(s,m){ return s+(newBase[m.key]||0); }, 0);
        header += '<button type="button" onclick="useAllNewBase()" style="font-size:11px;padding:2px 10px;background:#0ea5e9;color:#fff;border:none;border-radius:4px;cursor:pointer">'
            + 'Pakai Semua Baru (Rp '+newTotal.toLocaleString('id-ID')+')</button>';
    }
    header += '</div>';

    spreadDiv.innerHTML = header + '<table style="border-collapse:collapse;background:transparent;width:100%">'+rows+'</table>';
    spreadDiv.style.display = 'block';
    syncOvrInputs();
}

function fmtSpreadInp(inp) { var r=inp.value.replace(/\D/g,''); inp.value=r?parseInt(r,10).toLocaleString('id-ID'):''; }
function setSpreadOvr(k,v) { var r=String(v).replace(/\D/g,''); if(!r){clearSpreadOvr(k);return;} spreadOverrides[k]=parseInt(r,10); renderSpreadTable(); }
function clearSpreadOvr(k) { delete spreadOverrides[k]; renderSpreadTable(); }

function flushSpreadInputs() {
    var spreadDiv = document.getElementById('kalkulasi-spread');
    if (!spreadDiv) return;
    spreadDiv.querySelectorAll('input[data-period]').forEach(function(inp) {
        var k = inp.getAttribute('data-period');
        var raw = inp.value.trim();
        if (!raw || raw.charAt(0) === '-') return;
        var r = raw.replace(/\D/g,'');
        if (r) spreadOverrides[k] = parseInt(r, 10);
    });
}

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
