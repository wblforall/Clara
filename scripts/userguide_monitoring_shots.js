// Tangkapan layar untuk User Guide "Monitoring Transaksi".
//
// Beda dari skrip panduan lain: sebagian besar bidikannya adalah ELEMEN, bukan
// seluruh layar. Panduan ini memasang gambarnya berdampingan dengan penjelasan,
// jadi tangkapan selebar 1560px yang dikecilkan ke selebar halaman A4 akan
// terbaca sebagai kabut abu-abu. Memotret panel saringan, deretan ubin, atau
// satu baris tabel secara terpisah membuat tiap gambar tetap terbaca pada
// ukuran kecil — dan tidak ada halaman yang isinya cuma gambar.
//
// Halaman ini juga memperlihatkan dirinya berbeda menurut siapa yang masuk
// (PIC hanya melihat berkasnya sendiri, kotak PIC-nya terkunci), jadi skripnya
// berpindah akun di tengah jalan.
//
// Jalankan (tidak ada sandi yang ditulis di berkas ini):
//   CLARA_BASE=http://127.0.0.1:8111/ CLARA_UG_PASS='...' \
//   NODE_PATH=<node_modules> node scripts/userguide_monitoring_shots.js
//
// Keluaran → scripts/userguide_assets/mn-*.png

const fs = require('fs');
const puppeteer = require('puppeteer-core');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE   = process.env.CLARA_BASE || 'http://127.0.0.1:8111/';
const PASS   = process.env.CLARA_UG_PASS || '';
const OUT    = __dirname + '/userguide_assets/';
const sleep  = ms => new Promise(r => setTimeout(r, ms));

const MON = 'monitoring&sort=macet&dir=desc';

/**
 * Satu bidikan.
 *   n      nama berkas (tanpa .png)
 *   u      route sesudah "?r="
 *   el     selektor elemen yang dipotret; kosong = seluruh viewport
 *   maxH   tinggi maksimum hasil potongan (px CSS) — daftar panjang dipenggal
 *          supaya gambarnya tidak jadi pita tinggi kurus
 *   lebar  lebar viewport
 *   siap   fungsi tambahan yang dijalankan di halaman sebelum memotret
 */
const RENCANA = [
  {
    akun: process.env.UG_EMAIL_ADMIN || 'sampelanlovely@gmail.com',
    siapa: 'Admin Sales',
    bidik: [
      { n: 'mn-penuh',    u: MON, lebar: 1320, maxH: 900 },
      { n: 'mn-saringan', u: MON, lebar: 1500, el: 'form.mon-filter' },
      { n: 'mn-ubin',     u: MON, lebar: 1500, el: '.mon-tile' },
      { n: 'mn-papan',    u: MON, lebar: 1500, el: 'details.panel', maxH: 330 },
      { n: 'mn-tabel',    u: MON, lebar: 1500, el: 'div.table-wrap', maxH: 300 },
      { n: 'mn-badge',    u: MON, lebar: 1500, el: 'NAVGRUP' },
      // Rincian dipecah dua. Satu potret selebar 1.700 px yang dikecilkan ke
      // lebar halaman A4 menyusut jadi sepersepuluh ukuran aslinya — hurufnya
      // tinggal 1 mm dan tidak terbaca di kertas.
      { n: 'mn-rinci-kiri',  u: MON, lebar: 2060, el: 'BUKA_KIRI',  maxH: 430 },
      { n: 'mn-rinci-kanan', u: MON, lebar: 2060, el: 'BUKA_KANAN', maxH: 430 },
      { n: 'mn-banding',  u: MON + '&period=2026-10&mode=jalan', lebar: 1500, el: 'PANELBANDING' },
    ],
  },
  {
    akun: process.env.UG_EMAIL_SALES || 'zulfikar@ewalk-bsb.com',
    siapa: 'Sales / PIC',
    bidik: [
      { n: 'mn-sales-saring', u: MON, lebar: 1500, el: 'form.mon-filter' },
      { n: 'mn-sales-ubin',   u: MON, lebar: 1500, el: '.mon-tile' },
    ],
  },
  {
    akun: process.env.UG_EMAIL_ASMEN || 'frocha.ewalk@gmail.com',
    siapa: 'Asisten Manager',
    bidik: [
      { n: 'mn-asmen-papan', u: MON, lebar: 1500, el: 'details.panel', maxH: 330 },
    ],
  },
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
  // Masih di halaman login = sandi salah atau akun terkunci. Berhenti sekarang,
  // jangan sampai panduannya terisi tangkapan layar formulir login.
  if (/[?&]r=login/.test(p.url())) throw new Error('Login gagal untuk ' + email);
}

/** Kotak elemen di halaman, sudah termasuk gulir. Null bila elemennya tak ada. */
async function kotak(p, sel) {
  return await p.evaluate((s) => {
    let el = null;
    if (s === 'NAVGRUP') {
      const a = document.querySelector('aside.sidebar a[href*="r=monitoring"]');
      el = a ? a.closest('.nav-group') || a.closest('.nav-item') : null;
    } else if (s === 'BUKA' || s === 'BUKA_KIRI' || s === 'BUKA_KANAN') {
      const baris = document.querySelector('tr.mon-rinci[data-tampil="1"]');
      if (!baris) return null;
      if (s === 'BUKA') { el = baris; }
      else {
        // Tiga panel rincian berdampingan dalam satu kisi. Dua panel kiri
        // (perjalanan + berkas) dan panel kanan (tindak lanjut) dipotret
        // terpisah supaya tiap gambar cukup besar untuk dibaca di kertas.
        const kol = [...baris.querySelectorAll(':scope > td > div > div')];
        if (kol.length < 3) { el = baris; }
        else {
          const a = kol[0].getBoundingClientRect();
          const b = kol[1].getBoundingClientRect();
          const c = kol[2].getBoundingClientRect();
          const pad = 14;
          if (s === 'BUKA_KIRI') {
            return { x: a.left + scrollX - pad, y: a.top + scrollY - pad,
                     width: (b.right - a.left) + pad * 2,
                     height: Math.max(a.height, b.height) + pad * 2 };
          }
          return { x: c.left + scrollX - pad, y: c.top + scrollY - pad,
                   width: c.width + pad * 2, height: c.height + pad * 2 };
        }
      }
    } else if (s === 'PANELBANDING') {
      el = [...document.querySelectorAll('div.panel')]
        .find(d => /Cocok dengan Dashboard|Mau mencocokkan/.test(d.textContent || ''));
    } else {
      const semua = [...document.querySelectorAll(s)];
      el = semua.length ? semua[semua.length - 1] : null;
    }
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { x: r.left + scrollX, y: r.top + scrollY, width: r.width, height: r.height };
  }, sel);
}

(async () => {
  if (!PASS) { console.error('CLARA_UG_PASS belum diisi.'); process.exit(2); }
  fs.mkdirSync(OUT, { recursive: true });
  const b = await puppeteer.launch({
    executablePath: CHROME, headless: 'new',
    args: ['--no-sandbox', '--disable-gpu', '--force-device-scale-factor=2'],
  });
  let gagal = 0;
  try {
    for (const r of RENCANA) {
      const p = await b.newPage();
      await p.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
      await login(p, r.akun);
      console.log('masuk sebagai', r.siapa, '—', r.akun);

      for (const s of r.bidik) {
        await p.setViewport({ width: s.lebar || 1400, height: s.el ? 1000 : (s.maxH || 820), deviceScaleFactor: 2 });
        await p.goto(BASE + '?r=' + s.u, { waitUntil: 'networkidle2' });
        await sleep(650);

        // Rincian satu dokumen hanya ada setelah tombol "+" ditekan. Dipilih
        // baris yang MEMANG sedang menggantung, bukan baris pertama apa adanya:
        // baris tuntas tidak punya formulir tindak lanjut untuk diperlihatkan.
        if (String(s.el || '').startsWith('BUKA')) {
          const ada = await p.evaluate(() => {
            const tombol = [...document.querySelectorAll('button.mon-buka')];
            for (const t of tombol) {
              const baris = t.closest('tr');
              const rinci = baris && baris.nextElementSibling;
              if (!rinci || !rinci.classList.contains('mon-rinci')) continue;
              t.click();
              if (rinci.style.display === 'none') continue;
              rinci.setAttribute('data-tampil', '1');
              rinci.scrollIntoView({ block: 'center' });
              return true;
            }
            return false;
          });
          if (!ada) { console.warn('   ! tidak ada baris yang bisa dibuka:', s.n); gagal++; continue; }
          await sleep(400);
        }

        if (!s.el) {
          await p.screenshot({ path: OUT + s.n + '.png', fullPage: false });
          console.log('   OK', s.n, '(viewport)');
          continue;
        }

        const k = await kotak(p, s.el);
        if (!k || k.width < 20 || k.height < 20) {
          console.warn('   ! elemen tak ditemukan:', s.n, s.el); gagal++; continue;
        }
        await p.screenshot({
          path: OUT + s.n + '.png',
          clip: { x: k.x, y: k.y, width: k.width, height: Math.min(k.height, s.maxH || k.height) },
          captureBeyondViewport: true,
        });
        console.log('   OK', s.n, `(${Math.round(k.width)}×${Math.round(Math.min(k.height, s.maxH || k.height))})`);
      }
      await p.close();
    }
  } finally {
    await b.close();
  }
  if (gagal) { console.error(gagal + ' bidikan gagal.'); process.exit(1); }
})().catch(e => { console.error('GAGAL:', e.message); process.exit(1); });
