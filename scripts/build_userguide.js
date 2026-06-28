// Bangun User Guide PDF CLARA per-peran dari docs/PANDUAN_[Peran].md.
// Screenshot disisip via token @@SHOT:nama|caption@@ (desktop) / @@SHOTM:nama|caption@@ (mobile)
// dari scripts/userguide_assets/ (lihat scripts/userguide_screenshot.js).
// Output PDF → ~/Documents/Handout Program/clara/UserGuide_[Peran].pdf
// Jalankan: NODE_PATH=<node_modules dgn puppeteer-core+marked> node scripts/build_userguide.js Sales|Manager|Superadmin|all
const fs = require('fs');
const path = require('path');
const { marked } = require('marked');
const puppeteer = require('puppeteer-core');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const ROOT = path.resolve(__dirname, '..') + '/';
const SHOTS = __dirname + '/userguide_assets/';
const OUTDIR = (process.env.HOME || '/Users/affanridha') + '/Documents/Handout Program/clara/';

// Logo CLARA utk cover (badge putih agar kontras di gradient teal).
const LOGO_FILE = ROOT + 'public/assets/clara-logo.png';
const LOGO_B64 = fs.existsSync(LOGO_FILE) ? fs.readFileSync(LOGO_FILE).toString('base64') : '';
const LOGO_HTML = LOGO_B64
  ? `<img class="cover-logo" src="data:image/png;base64,${LOGO_B64}" alt="CLARA">`
  : '';

const css = `
*{box-sizing:border-box} body{font-family:Helvetica,Arial,sans-serif;color:#0f172a;margin:0;font-size:12.5px;line-height:1.6;background:#fff}
.content{background:#fff;padding:18mm 16mm}
.cover{height:297mm;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;
  background:linear-gradient(135deg,#115E59,#0D9488,#0891B2);color:#fff;page-break-after:always;padding:0 30mm}
.cover .cover-logo{width:200px;height:auto;display:block;margin-bottom:28px}
.cover .brand{font-size:13px;letter-spacing:.35em;text-transform:uppercase;opacity:.9;margin-bottom:18px}
.cover h1{font-size:38px;font-weight:800;margin:0 0 14px;line-height:1.15}
.cover .sub{font-size:15px;opacity:.92;max-width:150mm}
.cover .foot{position:absolute;bottom:24mm;font-size:11px;opacity:.8}
h2{color:#0D9488;font-size:18px;margin:22px 0 8px;border-bottom:2px solid #99F6E4;padding-bottom:5px}
h3{color:#115E59;font-size:14px;margin:16px 0 6px}
p,li{text-align:justify}
table{width:100%;border-collapse:collapse;margin:10px 0;font-size:11.5px}
th,td{border:1px solid #e2e8f0;padding:7px 9px;vertical-align:top;text-align:left}
th{background:#f0fdfa;color:#115E59;font-weight:700}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:11px}
pre{background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:8px;overflow:auto;font-size:10.5px}
pre code{background:none;color:inherit}
blockquote{border-left:4px solid #0D9488;background:#f0fdfa;margin:10px 0;padding:8px 14px;color:#334155;border-radius:0 8px 8px 0}
hr{border:none;border-top:1px solid #e2e8f0;margin:18px 0}
strong{color:#0f172a}
.shot,.shotm{margin:16px 0;text-align:center;page-break-inside:avoid}
.shot img{max-width:100%;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.10)}
.shotm img{max-width:260px;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 4px 14px rgba(0,0,0,.14)}
.shot figcaption,.shotm figcaption{font-size:10px;color:#64748b;margin-top:6px;font-style:italic}
`;

function embedShots(text) {
  return text.replace(/@@SHOT(M)?:([\w-]+)\|([^@]+)@@/g, (m, mob, name, cap) => {
    const f = SHOTS + name + '.png';
    if (!fs.existsSync(f)) { console.warn('  ! shot hilang:', name); return ''; }
    const b64 = fs.readFileSync(f).toString('base64');
    return `\n<figure class="${mob ? 'shotm' : 'shot'}"><img src="data:image/png;base64,${b64}"><figcaption>${cap.trim()}</figcaption></figure>\n`;
  });
}

async function build(role, browser) {
  const key = role.toUpperCase();
  const src = ROOT + `docs/PANDUAN_${key}.md`;
  if (!fs.existsSync(src)) { console.warn('lewati (source tak ada):', src); return; }
  const md = embedShots(fs.readFileSync(src, 'utf8'));
  const h1 = (md.split('\n').find(l => l.startsWith('# ')) || '# User Guide CLARA').replace(/^# /, '');
  const body = marked.parse(md.replace(/^# .*\n/, ''));
  const html = `<!doctype html><html lang="id"><head><meta charset="utf-8"><style>${css}</style></head><body>
    <section class="cover">${LOGO_HTML}<div class="brand">CLARA · Casual Leasing Achievement &amp; Revenue Analytics</div>
    <h1>${h1}</h1><div class="sub">Panduan penggunaan aplikasi CLARA — PT. Wulandari Bangun Laksana Tbk.</div>
    <div class="foot">Dokumen internal • v1.0</div></section>
    <main class="content">${body}</main></body></html>`;
  const out = OUTDIR + `UserGuide_${role}.pdf`;
  const page = await browser.newPage();
  await page.setContent(html, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.pdf({ path: out, format: 'A4', printBackground: true, margin: { top: 0, bottom: 0, left: 0, right: 0 } });
  await page.close();
  console.log(`OK → ${out} (${Math.round(fs.statSync(out).size / 1024)} KB)`);
}

(async () => {
  fs.mkdirSync(OUTDIR, { recursive: true });
  const arg = (process.argv[2] || 'all').toLowerCase();
  const roles = arg === 'all' ? ['Sales', 'Manager', 'Superadmin']
    : [arg.charAt(0).toUpperCase() + arg.slice(1)];
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  for (const r of roles) await build(r, browser);
  await browser.close();
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
