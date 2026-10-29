/**
 * Screenshot nyata untuk tutorial harga (scripts/build_tutorial_dobel.php).
 *
 * Dijalankan di CLARA LOKAL, bukan server: isinya potongan layar formulir dan
 * potongan surat hasilnya, dipotong tepat pada blok yang dibahas tutorialnya.
 * Hasilnya ditaruh di scripts/tutorial_assets/ yang TIDAK di-commit — gambarnya
 * memuat nama penyewa dan nilai kontrak dari basis data.
 *
 * Dua dokumen Form Utilities contoh (id 99950 & 99951) harus sudah ada; satu
 * menampilkan harga item, satu lagi menyembunyikannya. Keduanya dibuat dan
 * dihapus lagi oleh skrip penyiapnya, bukan oleh berkas ini.
 *
 * Pakai:
 *   CLARA_TEST_PASS='...' NODE_PATH=<puppeteer-core>/node_modules \
 *     node scripts/tutorial_screenshot.js
 */
const puppeteer = require('puppeteer-core');
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = 'http://127.0.0.1:8111/';
const OUT = __dirname + '/tutorial_assets/';
const PASS = process.env.CLARA_TEST_PASS;
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function login(page) {
  await page.goto(BASE + '?r=login', { waitUntil: 'networkidle2' });
  const csrf = await page.$eval('input[name="_csrf"]', el => el.value);
  await page.$eval('input[name="email"]', (el, v) => el.value = v, 'claude.test@clara.local');
  await page.$eval('input[name="password"]', (el, v) => el.value = v, PASS);
  await page.$eval('input[name="_csrf"]', (el, v) => el.value = v, csrf);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
  ]);
  await sleep(300);
}

// Potong tepat pada elemen hasil pencarian `cari` (dijalankan di dalam halaman).
async function potong(page, nama, cari, pad = 10) {
  const kotak = await page.evaluate((fn) => {
    const el = (new Function('return ' + fn))()();
    if (!el) return null;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    return { x: r.x + scrollX, y: r.y + scrollY, w: r.width, h: r.height };
  }, cari.toString());
  if (!kotak) { console.log('  GAGAL (elemen tak ketemu): ' + nama); return false; }
  await page.screenshot({
    path: OUT + nama + '.png',
    captureBeyondViewport: true,
    clip: {
      x: Math.max(0, kotak.x - pad), y: Math.max(0, kotak.y - pad),
      width: kotak.w + pad * 2, height: kotak.h + pad * 2,
    },
  });
  console.log('  ok  ' + nama + '.png  (' + Math.round(kotak.w) + '×' + Math.round(kotak.h) + ')');
  return true;
}

const cariLabel = (teks) => () => {
  for (const l of document.querySelectorAll('label')) {
    if (l.textContent.trim().startsWith(teks)) return l.closest('div');
  }
  return null;
};
const cariJudul = (tag, teks) => () => {
  for (const el of document.querySelectorAll(tag)) {
    if (el.textContent.trim().toLowerCase().includes(teks.toLowerCase())) return el;
  }
  return null;
};

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1340, height: 1100, deviceScaleFactor: 2 });
  await login(page);

  // ── 1. Formulir Surat Penawaran: Dasar Harga Sewa + Rate ──────────────────
  await page.goto(BASE + '?r=offer_form', { waitUntil: 'networkidle2' });
  await sleep(500);
  await page.evaluate(() => {
    const sel = document.getElementById('pricing_type');
    if (sel) { sel.value = 'monthly_area'; sel.dispatchEvent(new Event('change', { bubbles: true })); }
    const luas = document.getElementById('area_sqm');
    if (luas) { luas.value = '4'; luas.dispatchEvent(new Event('input', { bubbles: true })); }
    const r = document.getElementById('unit_rate_fmt');
    if (r) { r.value = '3.600.000'; r.dispatchEvent(new Event('input', { bubbles: true })); }
    const m = document.getElementById('start_date'); if (m) { m.value = '2026-12-01'; m.dispatchEvent(new Event('change', { bubbles: true })); }
    const s = document.getElementById('end_date');   if (s) { s.value = '2026-12-31'; s.dispatchEvent(new Event('change', { bubbles: true })); }
  });
  await sleep(700);
  await potong(page, 'f-dasar', () => {
    const sel = document.getElementById('pricing_type');
    const a = sel && sel.closest('div');
    const b = document.getElementById('unit_rate_fmt');
    const c = b && b.closest('div').parentElement;
    if (!a || !c) return null;
    const r1 = a.getBoundingClientRect(), r2 = c.getBoundingClientRect();
    const box = document.createElement('div');
    box.style.cssText = 'position:absolute;left:' + (r1.left + scrollX) + 'px;top:' + (Math.min(r1.top, r2.top) + scrollY)
      + 'px;width:' + (r2.right - r1.left) + 'px;height:' + (Math.max(r1.bottom, r2.bottom) - Math.min(r1.top, r2.top)) + 'px';
    document.body.appendChild(box);
    return box;
  });

  // ── 2 & 3. Bagian Harga: Harga / Bulan kosong vs terisi ───────────────────
  const blokHarga = () => {
    const h = [...document.querySelectorAll('h3')].find(x => x.id === 'sp-harga');
    if (!h) return null;
    const g = h.nextElementSibling;
    const r = g.getBoundingClientRect();
    const box = document.createElement('div');
    box.style.cssText = 'position:absolute;left:' + (r.left + scrollX) + 'px;top:' + (r.top + scrollY)
      + 'px;width:' + r.width + 'px;height:236px';
    document.body.appendChild(box);
    return box;
  };
  await potong(page, 'f-harga-kosong', blokHarga);
  await page.evaluate(() => {
    const m = document.getElementById('monthly_disp');
    if (m) { m.value = '14.400.000'; m.dispatchEvent(new Event('input', { bubbles: true })); m.dispatchEvent(new Event('blur', { bubbles: true })); }
  });
  await sleep(400);
  await potong(page, 'f-harga-isi', blokHarga);

  // ── 4. Formulir Gudang: tabel Rincian Harga Sewa Gudang ───────────────────
  await page.goto(BASE + '?r=skp_form&module=gudang', { waitUntil: 'networkidle2' });
  await sleep(600);
  await page.evaluate(() => {
    const isi = (sel, v) => { const el = document.querySelector(sel); if (el) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); } };
    isi('input[name="row[0][lokasi]"]', 'B13');
    isi('input[name="row[0][luas]"]', '12');
    isi('input[name="row[0][harga_m2]"]', '110.000');
    isi('input[name="row[0][total]"]', '1.320.000');
  });
  await sleep(300);
  await potong(page, 'f-gudang', () => document.getElementById('sks-rows'));

  // ── 5. Formulir Media: saklar "Sembunyikan harga di surat" ────────────────
  await page.goto(BASE + '?r=skp_form&module=media', { waitUntil: 'networkidle2' });
  await sleep(600);
  await page.evaluate(() => {
    const biaya = ['3.500.000', '3.500.000', '10.000.000'];
    document.querySelectorAll('tr.fu-row[data-grup="utilities"]').forEach((tr, i) => {
      if (i > 2) return;
      const c = tr.querySelector('.fu-cek'); if (c) { c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); }
      const b = tr.querySelector('.fu-biaya'); if (b) { b.value = biaya[i]; b.dispatchEvent(new Event('input', { bubbles: true })); }
      const s = tr.querySelector('input[name$="[satuan]"]'); if (s) { s.value = '/Hari'; s.dispatchEvent(new Event('input', { bubbles: true })); }
    });
  });
  await sleep(400);
  await potong(page, 'f-fu-saklar', () => {
    const h = [...document.querySelectorAll('h3')].find(x => x.textContent.trim().startsWith('Utilities'));
    if (!h) return null;
    const tabel = h.nextElementSibling;
    const box = document.createElement('div');
    const r1 = h.getBoundingClientRect(), r2 = tabel.getBoundingClientRect();
    box.style.cssText = 'position:absolute;left:' + (r1.left + scrollX) + 'px;top:' + (r1.top + scrollY)
      + 'px;width:' + Math.max(r1.width, r2.width) + 'px;height:' + Math.min(265, r2.bottom - r1.top) + 'px';
    document.body.appendChild(box);
    return box;
  });

  // ── 6-9. Hasil cetak surat (HTML) ─────────────────────────────────────────
  const rincianSkp = () => {
    const sec = [...document.querySelectorAll('.sec')].find(x => x.textContent.includes('Rincian Pembayaran'));
    if (!sec) return null;
    const t = sec.nextElementSibling;
    const box = document.createElement('div');
    const r1 = sec.getBoundingClientRect(), r2 = t.getBoundingClientRect();
    box.style.cssText = 'position:absolute;left:' + (r1.left + scrollX) + 'px;top:' + (r1.top + scrollY)
      + 'px;width:' + r2.width + 'px;height:' + (r2.bottom - r1.top) + 'px';
    document.body.appendChild(box);
    return box;
  };
  const tabelUtil = () => {
    const sec = [...document.querySelectorAll('.sec')].find(x => x.textContent.trim() === 'Utilities');
    if (!sec) return null;
    const t = sec.nextElementSibling;
    const box = document.createElement('div');
    const r1 = sec.getBoundingClientRect(), r2 = t.getBoundingClientRect();
    box.style.cssText = 'position:absolute;left:' + (r1.left + scrollX) + 'px;top:' + (r1.top + scrollY)
      + 'px;width:' + r2.width + 'px;height:' + (r2.bottom - r1.top) + 'px';
    document.body.appendChild(box);
    return box;
  };

  const rincianFu = () => {
    const sec = [...document.querySelectorAll('.sec')].find(x => x.textContent.trim() === 'Rincian Biaya');
    if (!sec) return null;
    const t = sec.nextElementSibling;
    const box = document.createElement('div');
    const r1 = sec.getBoundingClientRect(), r2 = t.getBoundingClientRect();
    box.style.cssText = 'position:absolute;left:' + (r1.left + scrollX) + 'px;top:' + (r1.top + scrollY)
      + 'px;width:' + r2.width + 'px;height:' + (r2.bottom - r1.top) + 'px';
    document.body.appendChild(box);
    return box;
  };

  const halaman = [
    ['fu-rincian', 1, '?r=skp_print&id=99950&html=1', rincianFu],
    ['s-12bulan', 1, '?r=skp_print&id=261&html=1', rincianSkp],
    ['fu-tampil', 1, '?r=skp_print&id=99950&html=1', tabelUtil],
    ['fu-sembunyi', 1, '?r=skp_print&id=99951&html=1', tabelUtil],
    ['s-1bulan', 2, '?r=skp_print&id=244&html=1', rincianSkp],
  ];
  let propKini = 1;
  for (const [nama, prop, url, cari] of halaman) {
    if (prop !== propKini) {
      await page.goto(BASE + '?r=switch_property&to=' + prop, { waitUntil: 'networkidle2' });
      propKini = prop;
    }
    await page.goto(BASE + url, { waitUntil: 'networkidle2' });
    await sleep(400);
    await potong(page, nama, cari);
  }

  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
