// Screenshot halaman CLARA yang ter-login via puppeteer-core + Chrome existing.
// Usage: node shot.js
const puppeteer = require('puppeteer-core');
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = 'http://localhost/clara/public/';
const OUT = '/Applications/XAMPP/xamppfiles/htdocs/clara/scripts/handout_assets/';

const shots = [
  { name: 'd12_offer_form_bundle', url: 'offer_form&bundle=1', w: 1280, h: 1400 },
  { name: 'd13_offer_view_bundle', url: 'offer_view&id=42',    w: 1280, h: 1600 },
];

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--disable-gpu'],
  });
  const page = await browser.newPage();
  // 1) login (ambil CSRF dari form)
  await page.goto(BASE + '?r=login', { waitUntil: 'networkidle2' });
  const csrf = await page.$eval('input[name="_csrf"]', el => el.value);
  await page.$eval('input[name="email"]', (el, v) => el.value = v, process.env.CLARA_TEST_EMAIL || 'claude.test@clara.local');
  await page.$eval('input[name="password"]', (el, v) => el.value = v, process.env.CLARA_TEST_PASS || '');
  await Promise.all([
    page.$eval('input[name="_csrf"]', (el, v) => el.value = v, csrf),
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
  ]);
  // 2) tiap halaman
  for (const s of shots) {
    await page.setViewport({ width: s.w, height: s.h, deviceScaleFactor: 1 });
    await page.goto(BASE + '?r=' + s.url, { waitUntil: 'networkidle2' });
    await new Promise(r => setTimeout(r, 400));
    await page.screenshot({ path: OUT + s.name + '.png', fullPage: true });
    console.log('OK ' + s.name);
  }
  await browser.close();
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
