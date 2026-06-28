// Bangun User Guide PDF CLARA per-peran dari docs/PANDUAN_[Peran].md.
// Pola mengikuti MIC: cover full-bleed + konten ber-margin (footer nomor halaman),
// digabung via pdf-lib. Screenshot disisip via token di markdown:
//   @@PAIR:desk|mob|caption@@   → desktop + mobile berdampingan
//   @@SHOT:nama|caption@@       → satu gambar desktop (lebar penuh)
//   @@SHOTM:nama|caption@@      → satu gambar mobile (sempit, terpusat)
//   @@PB@@                      → page-break (mulai halaman baru)
// Aset PNG: scripts/userguide_assets/ (lihat scripts/userguide_screenshot.js).
// Output → ~/Documents/Handout Program/clara/UserGuide_[Peran].pdf
// Jalankan: NODE_PATH=<node_modules: marked+puppeteer-core+pdf-lib> node scripts/build_userguide.js all|Sales|Manager|Superadmin
const fs = require('fs');
const path = require('path');
const { marked } = require('marked');
const puppeteer = require('puppeteer-core');
const { PDFDocument } = require('pdf-lib');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const ROOT = path.resolve(__dirname, '..') + '/';
const SHOTS = __dirname + '/userguide_assets/';
const OUTDIR = (process.env.HOME || '/Users/affanridha') + '/Documents/Handout Program/clara/';
const LOGO = fs.existsSync(ROOT + 'public/assets/clara-logo.png')
  ? 'data:image/png;base64,' + fs.readFileSync(ROOT + 'public/assets/clara-logo.png').toString('base64') : '';

const META = {
  Sales:      { label: 'Tim Sales (CL)', sub: 'Panduan operasional harian — Penawaran, SKP,<br>Permintaan Kontrak, dan pemantauan kinerja.' },
  Manager:    { label: 'Manajer CL',     sub: 'Panduan persetujuan & pengawasan — approve SKP,<br>kontrak Legal, laporan tim, dan ringkasan eksekutif.' },
  Superadmin: { label: 'Superadmin',     sub: 'Panduan administrasi sistem — pengguna & hak akses,<br>audit, master data, dan konfigurasi.' },
};

// ── helper gambar ────────────────────────────────────────────────────────────
function imgData(name) {
  const f = SHOTS + name + '.png';
  return fs.existsSync(f) ? 'data:image/png;base64,' + fs.readFileSync(f).toString('base64') : null;
}
function ssWrap(name, cap, cls) {
  const d = imgData(name);
  if (!d) { console.warn('  ! shot hilang:', name); return `<div class="ss-missing">[screenshot ${name} tidak tersedia]</div>`; }
  return `<div class="ss-wrap ${cls || ''}"><img src="${d}" alt="${cap || ''}">${cap ? `<div class="ss-cap">${cap}</div>` : ''}</div>`;
}
function embed(text) {
  return text
    .replace(/@@PAIR:([\w-]+)\|([\w-]+)\|([^@]*)@@/g, (m, d, mo, cap) =>
      `\n<div class="ss-pair"><div class="ss-d"><div class="ss-lbl">Tampilan Desktop</div>${ssWrap(d, '', 'pair')}</div>` +
      `<div class="ss-m"><div class="ss-lbl">Tampilan Mobile</div>${ssWrap(mo, '', 'mob')}</div></div>` +
      (cap.trim() ? `<div class="ss-paircap">${cap.trim()}</div>` : '') + `\n`)
    .replace(/@@SHOTM:([\w-]+)\|([^@]*)@@/g, (m, n, cap) => `\n<div class="ss-single mob-single">${ssWrap(n, cap.trim(), 'mob')}</div>\n`)
    .replace(/@@SHOT:([\w-]+)\|([^@]*)@@/g, (m, n, cap) => `\n<div class="ss-single">${ssWrap(n, cap.trim())}</div>\n`)
    .replace(/@@PB@@/g, '\n<div class="pb"></div>\n');
}

// ── CSS konten (tema teal CLARA) ─────────────────────────────────────────────
const CSS = `
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;font-size:10.5pt;line-height:1.62;color:#1f2937;-webkit-print-color-adjust:exact;print-color-adjust:exact}
h1{font-size:16pt;font-weight:800;color:#115E59;margin:2px 0 10px}
h2{font-size:13pt;font-weight:800;color:#0D9488;margin:0 0 6px;padding-bottom:5px;border-bottom:2px solid #99F6E4;page-break-after:avoid}
h3{font-size:10.5pt;font-weight:700;color:#115E59;margin:13px 0 5px;padding-left:8px;border-left:3px solid #2DD4BF;page-break-after:avoid}
p{text-align:justify;margin:0 0 8px;color:#374151}
ul,ol{margin:5px 0 10px;padding-left:20px}
li{margin-bottom:4px;color:#374151}
strong{font-weight:700;color:#111827}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:9pt;font-family:Menlo,Consolas,monospace}
pre{background:#0f172a;color:#e2e8f0;padding:11px 14px;border-radius:8px;overflow:hidden;font-size:8.5pt;margin:8px 0;page-break-inside:avoid;white-space:pre-wrap}
pre code{background:none;color:inherit;padding:0}
blockquote{border-left:3px solid #0D9488;background:#f0fdfa;margin:9px 0;padding:8px 13px;color:#334155;border-radius:0 7px 7px 0;font-size:10pt;page-break-inside:avoid}
hr{border:none;border-top:1px solid #e5e7eb;margin:16px 0}
table{width:100%;border-collapse:collapse;margin:8px 0;font-size:9.5pt;page-break-inside:avoid}
th{background:#115E59;color:#fff;padding:6px 9px;text-align:left;font-size:9pt;font-weight:600}
td{padding:6px 9px;border-bottom:1px solid #eef2f6;vertical-align:top;color:#374151}
tr:nth-child(even) td{background:#f8fafc}
.pb{page-break-after:always}
/* Screenshot */
.ss-single{margin:10px 0;page-break-inside:avoid;text-align:center}
.ss-pair{display:flex;gap:11px;margin:10px 0;page-break-inside:avoid;align-items:flex-start}
.ss-pair .ss-d{flex:1.6;min-width:0}.ss-pair .ss-m{flex:1;min-width:0}
.ss-lbl{font-size:7pt;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;text-align:center;margin-bottom:4px}
.ss-wrap{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;box-shadow:0 2px 9px rgba(0,0,0,.09);display:inline-block;max-width:100%}
.ss-wrap img{width:100%;display:block}
.ss-wrap.pair img{max-height:78mm;object-fit:cover;object-position:top}
.ss-wrap.mob img{max-height:120mm;object-fit:cover;object-position:top}
.ss-single .ss-wrap{display:inline-block;max-width:100%}
.ss-single .ss-wrap img{max-height:135mm;object-fit:contain}
.mob-single .ss-wrap{max-width:62mm}
.ss-cap{background:#f8fafc;border-top:1px solid #e2e8f0;padding:4px 9px;font-size:7.5pt;color:#64748b;font-style:italic;text-align:center}
.ss-paircap{font-size:7.5pt;color:#64748b;font-style:italic;text-align:center;margin-top:-4px}
.ss-missing{background:#f3f4f6;border-radius:8px;color:#cbd5e1;font-size:8pt;padding:18px;text-align:center;margin:10px 0}
`;

function coverHtml(role) {
  const m = META[role] || { label: role, sub: '' };
  return `<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
  *{margin:0;padding:0;box-sizing:border-box}@page{size:A4;margin:0}
  body{width:210mm;height:297mm;overflow:hidden;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .c{width:210mm;height:297mm;background:linear-gradient(150deg,#115E59 0%,#0D9488 45%,#0891B2 100%);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:0 24mm;position:relative}
  .logo{width:210px;margin:0 auto 26px;display:block}
  .lbl{font-size:8pt;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.6);margin-bottom:12px}
  h1{font-size:32pt;font-weight:800;line-height:1.12;margin-bottom:8px}
  h1 em{color:#A7F3D0;font-style:normal}
  .sub{font-size:11pt;color:rgba(255,255,255,.78);font-weight:300;margin-bottom:34px;line-height:1.55}
  .bar{width:50px;height:2px;background:#A7F3D0;border-radius:1px;margin:0 auto 26px}
  .metas{display:flex;gap:11px;justify-content:center}
  .mbox{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.16);border-radius:9px;padding:10px 18px;min-width:84px}
  .mval{font-size:13pt;font-weight:700;line-height:1;margin-bottom:3px}
  .mlbl{font-size:6.5pt;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.55)}
  .foot{position:absolute;bottom:18px;left:0;right:0;text-align:center;font-size:7pt;color:rgba(255,255,255,.35)}
  </style></head><body><div class="c">
    ${LOGO ? `<img class="logo" src="${LOGO}" alt="CLARA">` : ''}
    <div class="lbl">User Guide · Casual Leasing Achievement &amp; Revenue Analytics</div>
    <h1>Panduan CLARA<br><em>${m.label}</em></h1>
    <p class="sub">${m.sub}</p>
    <div class="bar"></div>
    <div class="metas">
      <div class="mbox"><div class="mval">v1.0</div><div class="mlbl">Versi</div></div>
      <div class="mbox"><div class="mval">2026</div><div class="mlbl">Edisi</div></div>
    </div>
    <div class="foot">PT. Wulandari Bangun Laksana Tbk. · Divisi IT · Dokumen Internal</div>
  </div></body></html>`;
}

const footer = role =>
  `<div style="width:100%;padding:0 18mm;display:flex;align-items:center;justify-content:space-between;font-family:Helvetica,Arial,sans-serif;font-size:7pt;color:#94a3b8;border-top:1px solid #e5e7eb;box-sizing:border-box;height:100%">
    <span style="font-weight:700;color:#115E59;font-size:7.5pt">CLARA — Panduan ${role}</span>
    <span>PT. Wulandari Bangun Laksana Tbk. · Dokumen Internal</span>
    <span style="background:#0D9488;color:#fff;padding:1px 9px;border-radius:9px;font-size:7pt">Hal. <span class="pageNumber"></span> / <span class="totalPages"></span></span>
  </div>`;

async function build(role, browser) {
  const key = role.toUpperCase();
  const src = ROOT + `docs/PANDUAN_${key}.md`;
  if (!fs.existsSync(src)) { console.warn('lewati (source tak ada):', src); return; }
  let md = fs.readFileSync(src, 'utf8').replace(/^# .*\n/, ''); // judul → cover, buang dari konten
  const bodyHtml = marked.parse(embed(md));

  // cover (full-bleed)
  const cp = await browser.newPage();
  await cp.setContent(coverHtml(role), { waitUntil: 'load', timeout: 120000 });
  const coverBuf = await cp.pdf({ format: 'A4', printBackground: true, margin: { top: 0, right: 0, bottom: 0, left: 0 } });
  await cp.close();

  // konten (ber-margin + footer nomor halaman)
  const pp = await browser.newPage();
  await pp.setContent(`<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>${CSS}</style></head><body>${bodyHtml}</body></html>`, { waitUntil: 'load', timeout: 120000 });
  const contentBuf = await pp.pdf({
    format: 'A4', printBackground: true, displayHeaderFooter: true,
    headerTemplate: '<span></span>', footerTemplate: footer(role),
    margin: { top: '13mm', right: '18mm', bottom: '16mm', left: '18mm' },
  });
  await pp.close();

  // gabung
  const out = await PDFDocument.create();
  const cDoc = await PDFDocument.load(coverBuf);
  const dDoc = await PDFDocument.load(contentBuf);
  (await out.copyPages(cDoc, [0])).forEach(p => out.addPage(p));
  (await out.copyPages(dDoc, dDoc.getPageIndices())).forEach(p => out.addPage(p));
  const bytes = await out.save();
  const dest = OUTDIR + `UserGuide_${role}.pdf`;
  fs.writeFileSync(dest, bytes);
  console.log(`OK → ${dest} (${Math.round(bytes.length / 1024)} KB, ${out.getPageCount()} hal)`);
}

(async () => {
  fs.mkdirSync(OUTDIR, { recursive: true });
  const arg = (process.argv[2] || 'all').toLowerCase();
  const roles = arg === 'all' ? ['Sales', 'Manager', 'Superadmin'] : [arg.charAt(0).toUpperCase() + arg.slice(1)];
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  for (const r of roles) await build(r, browser);
  await browser.close();
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
