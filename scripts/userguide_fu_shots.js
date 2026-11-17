// Tangkapan layar untuk bagian "Form Utilities — satu item, beberapa titik"
// pada User Guide Sales.
//
// Dua gambar, dua sudut pandang:
//   fu-titik-form.png  — formulirnya, dari akun SALES, dengan contoh TVC yang
//                        dipasang di tiga titik berikut tombol "+ titik lain";
//   fu-titik-surat.png — hasil jadinya di surat, diambil dari dokumen yang
//                        sudah disetujui lewat tampilan cetak HTML (?html=1)
//                        supaya tajam dan bisa dipotong pas pada bagiannya.
//
// Yang dipotret BUKAN seluruh layar, melainkan blok "Media Promo" saja —
// panduan ini menjelaskan satu bagian, dan tangkapan selebar halaman penuh
// membuat barisnya terlalu kecil untuk dibaca di PDF.
//
// Isi formulirnya diketik oleh skrip ini, jadi tidak bergantung pada data
// contoh mana pun. Yang bergantung pada data hanya gambar kedua: sebutkan id
// dokumen FU yang sudah disetujui lewat UG_FU_ID bila yang di bawah tidak ada.
//
// Jalankan (tidak ada sandi yang ditulis di berkas ini):
//   CLARA_BASE=http://127.0.0.1:8111/ CLARA_UG_PASS='...' \
//   NODE_PATH=<node_modules> node scripts/userguide_fu_shots.js
//
// Keluaran → scripts/userguide_assets/[nama].png

const fs = require('fs');
const puppeteer = require('puppeteer-core');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE   = process.env.CLARA_BASE || 'http://127.0.0.1:8111/';
const PASS   = process.env.CLARA_UG_PASS || '';
const OUT    = __dirname + '/userguide_assets/';
const sleep  = ms => new Promise(r => setTimeout(r, ms));

const EMAIL_SALES = process.env.UG_EMAIL_SALES || 'zulfikar@ewalk-bsb.com';
const EMAIL_ADMIN = process.env.UG_EMAIL_ADMIN || 'uji.lokal@clara.test';
/** Dokumen FU yang SUDAH disetujui & memuat item ber-beberapa-titik. */
const FU_ID   = process.env.UG_FU_ID   || '40';
const FU_PROP = process.env.UG_FU_PROP || '2';

/** Contoh yang diketik ke formulir — satu item, tiga titik, harga berbeda. */
const CONTOH = [
    ['GF Depan Beach Gate', '77000'],
    ['LG Atrium Utama',     '55000'],
    ['UG Void Timur',       '40000'],
];

async function login(p, email) {
    await p.goto(BASE + '?r=login', { waitUntil: 'networkidle2' });
    const csrf = await p.$eval('input[name="_csrf"]', e => e.value);
    await p.$eval('input[name="email"]',    (e, v) => e.value = v, email);
    await p.$eval('input[name="password"]', (e, v) => e.value = v, PASS);
    await p.$eval('input[name="_csrf"]',    (e, v) => e.value = v, csrf);
    await Promise.all([
        p.click('button[type="submit"], input[type="submit"]'),
        p.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
    ]);
    await sleep(500);
    // Masih di halaman login = sandi salah atau akun terkunci. Berhenti di sini,
    // jangan sampai panduannya terisi tangkapan layar formulir login.
    if (/[?&]r=login/.test(p.url())) throw new Error('Login gagal untuk ' + email);
}

/** Potret satu blok saja: dari judulnya sampai ujung bawah tabel di bawahnya. */
async function potretBlok(p, nama, cariJudul, pilihJudul, pilihTabel) {
    const klip = await p.evaluate((cari, selJudul, selTabel) => {
        const judul = [...document.querySelectorAll(selJudul)]
            .find(e => e.textContent.trim().startsWith(cari));
        if (!judul) return null;
        judul.scrollIntoView({ block: 'center' });
        // Ditelusuri MAJU dari judulnya, bukan dicari di induknya: di formulir
        // ketiga kelompok duduk di induk yang sama, dan pencarian dari induk
        // selalu menjatuhkan pilihan pada tabel kelompok PERTAMA — yang letaknya
        // di atas judul ini, sehingga tinggi potongannya jadi negatif dan
        // gambarnya keluar sebagai garis setinggi beberapa piksel.
        let tabel = null;
        for (let n = judul.nextElementSibling; n && !tabel; n = n.nextElementSibling) {
            tabel = n.matches(selTabel) ? n : n.querySelector(selTabel);
        }
        if (!tabel) return null;
        const a = judul.getBoundingClientRect(), b = tabel.getBoundingClientRect();
        return {
            x: Math.max(0, Math.min(a.left, b.left) + scrollX - 14),
            y: a.top + scrollY - 12,
            width: Math.max(a.width, b.width) + 28,
            height: (b.bottom - a.top) + 24,
        };
    }, cariJudul, pilihJudul, pilihTabel);
    if (!klip) throw new Error('Blok "' + cariJudul + '" tidak ditemukan untuk ' + nama);
    await p.screenshot({ path: OUT + nama + '.png', clip: klip });
    console.log('   OK', nama);
}

(async () => {
    if (!PASS) { console.error('CLARA_UG_PASS belum diisi.'); process.exit(2); }
    fs.mkdirSync(OUT, { recursive: true });
    const b = await puppeteer.launch({
        executablePath: CHROME, headless: 'new',
        args: ['--no-sandbox', '--disable-gpu', '--force-device-scale-factor=2'],
    });
    try {
        // ── 1. Formulir, dari mata sales ─────────────────────────────────────
        const p = await b.newPage();
        await p.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
        await login(p, EMAIL_SALES);
        console.log('masuk sebagai Sales —', EMAIL_SALES);
        await p.goto(BASE + '?r=skp_form&module=media', { waitUntil: 'networkidle2' });
        await sleep(400);

        await p.evaluate(isi => {
            const tr = document.querySelector('tr.fu-row[data-item="tvc"]');
            const ketik = (baris, sel, nilai) => {
                const e = baris.querySelector(sel);
                e.value = nilai;
                e.dispatchEvent(new Event('input', { bubbles: true }));
            };
            ketik(tr, 'input[name$="[lokasi]"]', isi[0][0]);
            ketik(tr, '.fu-biaya', isi[0][1]);
            ketik(tr, 'input[name$="[satuan]"]', '/Titik/Hari');
        }, CONTOH);
        for (let i = 1; i < CONTOH.length; i++) {
            await p.evaluate(() => document.querySelector('tr.fu-row[data-item="tvc"] .fu-tambah-titik').click());
            await sleep(150);
        }
        await p.evaluate(isi => {
            const rows = [...document.querySelectorAll('tr.fu-row[data-item="tvc"]')];
            rows.forEach((tr, i) => {
                if (i === 0 || !isi[i]) return;
                const ketik = (sel, nilai) => {
                    const e = tr.querySelector(sel);
                    e.value = nilai;
                    e.dispatchEvent(new Event('input', { bubbles: true }));
                };
                ketik('input[name$="[lokasi]"]', isi[i][0]);
                ketik('.fu-biaya', isi[i][1]);
            });
            // Kursor dilepas supaya kotak terakhir tidak tersorot biru di gambar.
            document.activeElement && document.activeElement.blur();
        }, CONTOH);
        await sleep(400);
        await potretBlok(p, 'fu-titik-form', 'Media Promo', 'h3', 'table.fu-tabel');
        await p.close();

        // ── 2. Hasilnya di surat ─────────────────────────────────────────────
        const q = await b.newPage();
        await q.setViewport({ width: 1100, height: 900, deviceScaleFactor: 2 });
        await login(q, EMAIL_ADMIN);
        console.log('masuk sebagai Admin —', EMAIL_ADMIN);
        await q.goto(BASE + '?r=switch_property&to=' + FU_PROP, { waitUntil: 'networkidle2' });
        await q.goto(BASE + '?r=skp_print&id=' + FU_ID + '&html=1', { waitUntil: 'networkidle2' });
        await sleep(500);
        await potretBlok(q, 'fu-titik-surat', 'Media Promo', '.sec', 'table.pay');
        await q.close();
    } finally {
        await b.close();
    }
})().catch(e => { console.error('GAGAL:', e.message); process.exit(1); });
