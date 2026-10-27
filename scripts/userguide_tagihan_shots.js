// Tangkapan layar untuk User Guide "Tagihan & Pembayaran".
//
// Berbeda dari scripts/userguide_screenshot.js yang login sekali sebagai satu
// akun, panduan ini harus memperlihatkan layar yang SAMA dari tiga sudut
// pandang berbeda — Admin Sales yang mengisi, Sales yang hanya melihat
// barisnya sendiri, dan Manager yang tidak boleh mengubah apa pun. Jadi
// skripnya berpindah akun di tengah jalan.
//
// Jalankan (tidak ada sandi yang ditulis di berkas ini):
//   CLARA_BASE=http://127.0.0.1:8111/ CLARA_UG_PASS='...' \
//   NODE_PATH=<node_modules> node scripts/userguide_tagihan_shots.js
//
// Keluaran → scripts/userguide_assets/[nama].png

const fs = require('fs');
const puppeteer = require('puppeteer-core');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE   = process.env.CLARA_BASE || 'http://127.0.0.1:8111/';
const PASS   = process.env.CLARA_UG_PASS || '';
const OUT    = __dirname + '/userguide_assets/';
const sleep  = ms => new Promise(r => setTimeout(r, ms));

/** id transaksi contoh; boleh ditimpa lewat environment bila datanya berbeda. */
const TRX_ISI    = process.env.UG_TRX_ISI    || '2062';  // sudah ditagih, DP masuk
const TRX_KOSONG = process.env.UG_TRX_KOSONG || '2175';  // belum ditagih sama sekali

/** Daftar bidikan per akun. Urutannya = urutan login, sekali per akun. */
const RENCANA = [
  {
    akun: process.env.UG_EMAIL_ADMIN || 'sampelanlovely@gmail.com',
    siapa: 'Admin Sales',
    bidik: [
      { lebar: 1560, n: 'tg-daftar',      u: 'payments&period=2026-10' },
      { n: 'tg-form-isi',    u: 'payment_form&id=' + TRX_ISI },
      { n: 'tg-form-kosong', u: 'payment_form&id=' + TRX_KOSONG },
      { lebar: 1560, n: 'tg-badge-trx',   u: 'transactions&module=cl' },
      { lebar: 1560, n: 'tg-kalender',    u: 'calendar&month=2026-10' },
    ],
  },
  {
    akun: process.env.UG_EMAIL_SALES || 'zulfikar@ewalk-bsb.com',
    siapa: 'Sales',
    bidik: [
      { lebar: 1560, n: 'tg-sales', u: 'payments&period=2026-10' },
    ],
  },
  {
    akun: process.env.UG_EMAIL_MANAGER || 'frocha.ewalk@gmail.com',
    siapa: 'Manager',
    bidik: [
      { n: 'tg-manager', u: 'payment_form&id=' + TRX_ISI },
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
  // Masih di halaman login = sandi salah atau akun terkunci. Berhenti di sini,
  // jangan sampai seluruh panduan terisi tangkapan layar formulir login.
  if (/[?&]r=login/.test(p.url())) throw new Error('Login gagal untuk ' + email);
}

(async () => {
  if (!PASS) { console.error('CLARA_UG_PASS belum diisi.'); process.exit(2); }
  fs.mkdirSync(OUT, { recursive: true });
  const b = await puppeteer.launch({
    executablePath: CHROME, headless: 'new',
    args: ['--no-sandbox', '--disable-gpu', '--force-device-scale-factor=2'],
  });
  try {
    for (const r of RENCANA) {
      const p = await b.newPage();
      await p.setViewport({ width: 1280, height: 820, deviceScaleFactor: 2 });
      await login(p, r.akun);
      console.log('masuk sebagai', r.siapa, '—', r.akun);
      for (const s of r.bidik) {
        const lebar = s.lebar || 1280;
        await p.setViewport({ width: lebar, height: 820, deviceScaleFactor: 2 });
        await p.goto(BASE + '?r=' + s.u, { waitUntil: 'networkidle2' });
        await sleep(600);
        await p.screenshot({ path: OUT + s.n + '.png', fullPage: false });
        console.log('   OK', s.n);
      }
      await p.close();
    }
  } finally {
    await b.close();
  }
})().catch(e => { console.error('GAGAL:', e.message); process.exit(1); });
