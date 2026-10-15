/* CLARA — angka KPI naik dari nol (JS murni).
   Aman: sekali per pemuatan, teks asli dipulihkan PERSIS di akhir, galat apa pun = teks asli dibiarkan. */
(function () {
    'use strict';
    try {
        if (window.__claraCountUp) return;
        window.__claraCountUp = true;
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        if (!window.requestAnimationFrame || !document.querySelectorAll) return;

        var SEL = '.kpi-value, .combined-kpi-value, .prop-kpi-value, .m-kpi .v';
        var DUR = 800;
        // angka berformat Indonesia: 1.234.567,89 | 1234,5 | 77,0 | 7
        var RE = /\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:,\d+)?/;

        function textNodes(el) {
            var out = [], w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null), n;
            while ((n = w.nextNode())) { if (/\S/.test(n.nodeValue)) out.push(n); }
            return out;
        }

        function group(intStr) { return intStr.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }

        function plan(el) {
            if (el.hasAttribute('data-no-count') || el.hasAttribute('data-countup-pct')) return null;
            if (el.closest('table, form, [data-no-count]')) return null;
            var nodes = textNodes(el), hit = null, i;
            for (i = 0; i < nodes.length; i++) {
                if (/\d/.test(nodes[i].nodeValue)) { if (hit) return null; hit = nodes[i]; }
            }
            if (!hit) return null;
            var txt = hit.nodeValue, m = RE.exec(txt);
            if (!m) return null;
            var s = m.index, e = s + m[0].length;
            // angka harus berdiri sendiri (bukan bagian tanggal/jam/kode seperti 12.10.2026, 10:30, A-12)
            var before = txt.charAt(s - 1), after = txt.charAt(e), after2 = txt.charAt(e + 1);
            if (/[\d.,:\/\-]/.test(before)) return null;
            if (/[.,:\/\-]/.test(after) && /\d/.test(after2)) return null;
            if (/[A-Za-z]/.test(before)) return null;
            var raw = m[0], comma = raw.indexOf(',');
            var intPart = comma >= 0 ? raw.slice(0, comma) : raw;
            var dec = comma >= 0 ? raw.length - comma - 1 : 0;
            var val = parseFloat(intPart.replace(/\./g, '') + (dec ? '.' + raw.slice(comma + 1) : ''));
            if (!isFinite(val) || val <= 0 || val > 9e15) return null;
            // format harus bisa direproduksi persis; kalau tidak, jangan disentuh
            var rebuilt = fmt(val, dec, intPart.indexOf('.') >= 0);
            if (rebuilt !== raw) return null;
            return { node: hit, orig: txt, pre: txt.slice(0, s), post: txt.slice(e), val: val, dec: dec, grp: intPart.indexOf('.') >= 0 };
        }

        function fmt(v, dec, grp) {
            var f = v.toFixed(dec), p = f.split('.');
            return (grp ? group(p[0]) : p[0]) + (dec ? ',' + p[1] : '');
        }

        var jobs = [];
        Array.prototype.forEach.call(document.querySelectorAll(SEL), function (el) {
            try {
                var p = plan(el);
                if (!p) return;
                p.el = el;
                p.h = el.offsetHeight;
                p.saved = { minHeight: el.style.minHeight, fvn: el.style.fontVariantNumeric };
                jobs.push(p);
            } catch (e) { /* biarkan apa adanya */ }
        });
        if (!jobs.length) return;

        var done = false, t0 = 0;
        function finish() {
            if (done) return;
            done = true;
            jobs.forEach(function (j) {
                try {
                    j.node.nodeValue = j.orig;
                    j.el.style.minHeight = j.saved.minHeight;
                    j.el.style.fontVariantNumeric = j.saved.fvn;
                } catch (e) {}
            });
        }

        // keadaan awal (nol) — kunci tinggi agar tata letak tidak bergeser selama animasi
        jobs.forEach(function (j) {
            try {
                j.el.style.minHeight = j.h + 'px';
                j.el.style.fontVariantNumeric = 'tabular-nums';
                j.node.nodeValue = j.pre + fmt(0, j.dec, j.grp) + j.post;
            } catch (e) { finish(); }
        });

        function step(ts) {
            if (done) return;
            try {
                if (!t0) t0 = ts;
                var k = Math.min(1, (ts - t0) / DUR), eased = 1 - Math.pow(1 - k, 3);
                if (k >= 1) { finish(); return; }
                jobs.forEach(function (j) { j.node.nodeValue = j.pre + fmt(j.val * eased, j.dec, j.grp) + j.post; });
                requestAnimationFrame(step);
            } catch (e) { finish(); }
        }
        requestAnimationFrame(step);
        setTimeout(finish, DUR + 1200); // jaring pengaman (mis. tab tersembunyi)
    } catch (e) { /* galat apa pun: teks asli dibiarkan */ }
})();
