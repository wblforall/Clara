// Screenshot semua layar untuk User Guide CLARA (per peran) — login via akun test.
// Output: scripts/userguide_assets/[nama].png  (d* via fullPage desktop, m* mobile)
// Jalankan: CLARA_TEST_PASS='...' /opt/homebrew/bin/node scripts/userguide_screenshot.js
const fs = require('fs');
const puppeteer = require('puppeteer-core');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = 'http://localhost/clara/public/';
const OUT = __dirname + '/userguide_assets/';
const sleep = ms => new Promise(r => setTimeout(r, ms));

// Desktop (lebar 1280, fullPage)
const desk = [
  { n: 'login',     u: 'login', noLogin: true },
  { n: 'dashboard', u: 'dashboard' },
  // Sales
  { n: 'offers',     u: 'offers' },
  { n: 'offer_form', u: 'offer_form' },
  { n: 'skp',        u: 'skp' },
  { n: 'skp_form',   u: 'skp_form&id=27' },
  { n: 'contract',   u: 'contract_requests' },
  { n: 'trx',        u: 'transactions&module=cl' },
  { n: 'pic',        u: 'pic_report' },
  // Manager
  { n: 'exec',       u: 'exec_dashboard' },
  { n: 'renewals',   u: 'renewals' },
  { n: 'commission', u: 'commission_sim' },
  // Superadmin
  { n: 'users',      u: 'users' },
  { n: 'roles',      u: 'roles' },
  { n: 'audit',      u: 'audit' },
  { n: 'master_cl',  u: 'master&type=cl' },
  { n: 'recurring',  u: 'recurring_candidates' },
  { n: 'lookup',     u: 'lookup_manage' },
];
// Mobile (390x844)
const mob = [
  { n: 'm_home',         u: 'm_home' },
  { n: 'm_offers',       u: 'm_offers' },
  { n: 'm_skp',          u: 'm_skp' },
  { n: 'm_transactions', u: 'm_transactions' },
  { n: 'm_exec',         u: 'm_exec' },
];

async function login(p) {
  await p.goto(BASE + '?r=login', { waitUntil: 'networkidle2' });
  const csrf = await p.$eval('input[name="_csrf"]', e => e.value);
  await p.$eval('input[name="email"]', (e, v) => e.value = v, process.env.CLARA_TEST_EMAIL || 'claude.test@clara.local');
  await p.$eval('input[name="password"]', (e, v) => e.value = v, process.env.CLARA_TEST_PASS || '');
  await p.$eval('input[name="_csrf"]', (e, v) => e.value = v, csrf);
  await Promise.all([
    p.click('button[type="submit"]'),
    p.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
  ]);
  await sleep(500);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  const p = await b.newPage();
  // login page dulu (belum login)
  await p.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });
  await p.goto(BASE + '?r=login', { waitUntil: 'networkidle2' }); await sleep(400);
  await p.screenshot({ path: OUT + 'login.png' }); console.log('OK login');
  await login(p);
  const cookies = await p.cookies();
  // Desktop: viewport (BUKAN fullPage) → rasio konsisten ~3:2, layout rapi di PDF.
  for (const s of desk) {
    if (s.noLogin) continue;
    await p.setViewport({ width: 1280, height: 860, deviceScaleFactor: 1 });
    await p.goto(BASE + '?r=' + s.u, { waitUntil: 'networkidle2' }); await sleep(500);
    await p.screenshot({ path: OUT + s.n + '.png', fullPage: false }); console.log('OK', s.n);
  }
  // Mobile: viewport 1 layar (BUKAN fullPage) → tampil berdampingan dgn desktop (ssPair).
  const pm = await b.newPage();
  await pm.setCookie(...cookies, { name: 'clara_view', value: 'mobile', domain: 'localhost', path: '/' });
  for (const s of mob) {
    await pm.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true });
    await pm.goto(BASE + '?r=' + s.u, { waitUntil: 'networkidle2' }); await sleep(600);
    await pm.screenshot({ path: OUT + s.n + '.png', fullPage: false }); console.log('OK', s.n);
  }
  await b.close();
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
