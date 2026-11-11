// Video panduan "Monitoring Transaksi" — rekaman layar beralur, tanpa suara.
//
// Kenapa tanpa suara: tidak ada yang merekam narasi di sini, dan video panduan
// bisu tanpa tulisan membuat penonton menebak-nebak sedang melihat apa. Jadi
// tiap adegan membawa KARTU TEKS-nya sendiri di kaki layar, plus sorotan pada
// bagian yang sedang dibicarakan dan kursor buatan yang bergerak sebelum
// mengklik — tiga hal yang membuat rekaman bisu tetap bisa diikuti.
//
// Alurnya mengikuti babnya docs/PANDUAN_MONITORING.md, jadi video dan PDF
// menceritakan hal yang sama dengan urutan yang sama.
//
// Jalankan (tidak ada sandi yang ditulis di berkas ini):
//   CLARA_BASE=http://127.0.0.1:8111/ CLARA_UG_PASS='...' \
//   NODE_PATH=<node_modules: puppeteer-core + ffmpeg-static> \
//   node scripts/userguide_monitoring_video.js
//
// Keluaran → ~/Documents/Handout Program/clara/Video_Monitoring_Transaksi.mp4

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFile } = require('child_process');
const puppeteer = require('puppeteer-core');
const ffmpeg = require('ffmpeg-static');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE   = process.env.CLARA_BASE || 'http://127.0.0.1:8111/';
const PASS   = process.env.CLARA_UG_PASS || '';
const AKUN   = process.env.UG_EMAIL_ADMIN || 'sampelanlovely@gmail.com';
const OUTDIR = process.env.CLARA_UG_OUT || ((process.env.HOME || '') + '/Documents/Handout Program/clara/');
const NAMA   = 'Video_Monitoring_Transaksi';
const MON    = 'monitoring&sort=macet&dir=desc';

// 1280×720: ukuran HD yang wajar untuk dikirim lewat WhatsApp dan ditempel di
// slide. Skala 1 — merekam pada skala 2 melipatempatkan berkasnya tanpa
// menambah apa pun yang bisa dibaca pada layar seukuran ini.
const W = 1280, H = 720;
const sleep = ms => new Promise(r => setTimeout(r, ms));

// ── lapisan bantu yang disuntikkan ke tiap halaman ───────────────────────────
const LAPIS = () => {
  if (window.__ug) return;
  const s = document.createElement('style');
  s.textContent = `
  #ug-cap{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;
    background:linear-gradient(to top,rgba(8,22,30,.97) 62%,rgba(8,22,30,0));
    color:#fff;padding:26px 40px 22px;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;
    opacity:0;transition:opacity .35s ease;pointer-events:none}
  #ug-cap.on{opacity:1}
  #ug-cap b{display:block;font-size:21px;font-weight:800;letter-spacing:-.01em;margin-bottom:3px}
  #ug-cap span{display:block;font-size:14.5px;color:#9FD8D2;line-height:1.5}
  #ug-sorot{position:absolute;z-index:2147482000;border:3px solid #14B8A6;border-radius:12px;
    box-shadow:0 0 0 9999px rgba(8,22,30,.42);transition:all .5s cubic-bezier(.4,0,.2,1);
    opacity:0;pointer-events:none}
  #ug-sorot.on{opacity:1}
  #ug-kursor{position:fixed;z-index:2147483600;width:26px;height:26px;margin:-13px 0 0 -13px;
    border-radius:50%;background:rgba(20,184,166,.32);border:2px solid #0D9488;
    transition:all .62s cubic-bezier(.4,0,.2,1);opacity:0;pointer-events:none}
  #ug-kursor.on{opacity:1}
  #ug-kursor.tekan{transform:scale(.62);background:rgba(20,184,166,.66)}
  #ug-judul{position:fixed;inset:0;z-index:2147483500;display:flex;flex-direction:column;
    align-items:center;justify-content:center;text-align:center;
    background:linear-gradient(150deg,#115E59 0%,#0D9488 45%,#0891B2 100%);color:#fff;
    font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;opacity:0;transition:opacity .6s ease;
    pointer-events:none}
  #ug-judul.on{opacity:1}
  #ug-judul .k{font-size:10px;letter-spacing:.24em;text-transform:uppercase;color:rgba(255,255,255,.62);margin-bottom:14px}
  #ug-judul .j{font-size:42px;font-weight:800;line-height:1.14}
  #ug-judul .j em{color:#A7F3D0;font-style:normal}
  #ug-judul .s{font-size:16px;color:rgba(255,255,255,.8);margin-top:14px;font-weight:300;line-height:1.6}`;
  document.head.appendChild(s);

  const buat = (id, html) => {
    const d = document.createElement('div'); d.id = id;
    if (html) d.innerHTML = html;
    document.body.appendChild(d); return d;
  };
  const cap    = buat('ug-cap', '<b></b><span></span>');
  const sorot  = buat('ug-sorot');
  const kursor = buat('ug-kursor');
  const judul  = buat('ug-judul', '<div class="k"></div><div class="j"></div><div class="s"></div>');

  window.__ug = {
    cap(a, b) {
      cap.querySelector('b').textContent = a || '';
      cap.querySelector('span').textContent = b || '';
      cap.classList.toggle('on', !!(a || b));
    },
    tutupCap() { cap.classList.remove('on'); },
    judul(k, j, s) {
      judul.querySelector('.k').textContent = k;
      judul.querySelector('.j').innerHTML = j;
      judul.querySelector('.s').textContent = s || '';
      judul.classList.add('on');
    },
    tutupJudul() { judul.classList.remove('on'); },
    sorot(sel, pad) {
      const el = typeof sel === 'string' ? document.querySelector(sel) : sel;
      if (!el) { sorot.classList.remove('on'); return false; }
      const p = pad == null ? 8 : pad;
      const r = el.getBoundingClientRect();
      sorot.style.left   = (r.left + scrollX - p) + 'px';
      sorot.style.top    = (r.top + scrollY - p) + 'px';
      sorot.style.width  = (r.width + p * 2) + 'px';
      sorot.style.height = (r.height + p * 2) + 'px';
      sorot.classList.add('on');
      return true;
    },
    lepasSorot() { sorot.classList.remove('on'); },
    kursorKe(sel) {
      const el = typeof sel === 'string' ? document.querySelector(sel) : sel;
      if (!el) return false;
      const r = el.getBoundingClientRect();
      kursor.style.left = (r.left + r.width / 2) + 'px';
      kursor.style.top  = (r.top + r.height / 2) + 'px';
      kursor.classList.add('on');
      return true;
    },
    tekan() {
      kursor.classList.add('tekan');
      setTimeout(() => kursor.classList.remove('tekan'), 260);
    },
    lepasKursor() { kursor.classList.remove('on'); },
    keBawah(y) { window.scrollTo({ top: y, behavior: 'smooth' }); },
    keElemen(sel, off) {
      const el = document.querySelector(sel);
      if (!el) return false;
      window.scrollTo({ top: el.getBoundingClientRect().top + scrollY - (off == null ? 90 : off), behavior: 'smooth' });
      return true;
    },
  };
};

async function login(p) {
  await p.goto(BASE + '?r=login', { waitUntil: 'networkidle2' });
  const csrf = await p.$eval('input[name="_csrf"]', e => e.value);
  await p.$eval('input[name="email"]',    (e, v) => e.value = v, AKUN);
  await p.$eval('input[name="password"]', (e, v) => e.value = v, PASS);
  await p.$eval('input[name="_csrf"]',    (e, v) => e.value = v, csrf);
  await Promise.all([
    p.click('button[type="submit"], input[type="submit"]'),
    p.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
  ]);
  if (/[?&]r=login/.test(p.url())) throw new Error('Login gagal untuk ' + AKUN);
}

(async () => {
  if (!PASS) { console.error('CLARA_UG_PASS belum diisi.'); process.exit(2); }
  fs.mkdirSync(OUTDIR, { recursive: true });
  const webm = path.join(os.tmpdir(), NAMA + '.webm');
  const mp4  = path.join(OUTDIR, NAMA + '.mp4');

  const b = await puppeteer.launch({
    executablePath: CHROME, headless: 'new',
    args: ['--no-sandbox', '--disable-gpu', `--window-size=${W},${H}`],
  });
  const p = await b.newPage();
  await p.setViewport({ width: W, height: H, deviceScaleFactor: 1 });
  await login(p);

  // Lapisan bantu dipasang ulang tiap kali halaman berganti; suntikan ke
  // dokumen lama ikut hilang saat navigasi.
  const pasang = async () => { await p.evaluate(LAPIS); };
  const ug = async (fn, ...a) => p.evaluate(fn, ...a);
  const buka = async (u) => {
    await p.goto(BASE + '?r=' + u, { waitUntil: 'networkidle2' });
    await pasang();
  };

  await buka(MON);
  const rec = await p.screencast({ path: webm, ffmpegPath: ffmpeg });
  const adegan = [];
  const catat = n => { adegan.push(n); console.log('   ·', n); };

  try {
    // ── 1. Kartu judul ──────────────────────────────────────────────────────
    await ug(() => __ug.judul('Panduan CLARA',
      'Monitoring<br><em>Transaksi</em>',
      'Berkas ini berhenti di mana, giliran siapa, dan sudah berapa yang tuntas'));
    await sleep(4200);
    await ug(() => __ug.tutupJudul());
    await sleep(900);
    catat('kartu judul');

    // ── 2. Lencana pengingat ────────────────────────────────────────────────
    await ug(() => __ug.sorot('aside.sidebar a[href*="r=monitoring"]', 6));
    await ug(() => __ug.cap('Pengingat ada di menu samping',
      'Angka merahnya = berkas yang menunggu tindakan Anda. Terlihat di halaman mana pun.'));
    await sleep(5200);
    catat('lencana');

    // ── 3. Enam ubin ringkasan ──────────────────────────────────────────────
    await ug(() => __ug.keElemen('.mon-tile', 110));
    await sleep(900);
    await ug(() => __ug.sorot('.mon-tile', 10));
    await ug(() => __ug.cap('Pencapaian Anda, enam angka',
      'Dokumen · Income diakui · Tuntas · Masih menggantung · Giliran saya · Lewat jatuh tempo.'));
    await sleep(5400);
    catat('ubin ringkasan');

    // ── 4. Papan pencapaian per PIC ─────────────────────────────────────────
    await ug(() => __ug.keElemen('details.panel', 90));
    await sleep(950);
    await ug(() => __ug.sorot('details.panel', 8));
    await ug(() => __ug.cap('Pencapaian per PIC',
      'Baca kolom "Giliran dia" — itu yang benar-benar ada di tangan PIC. "Menggantung" belum tentu kelalaiannya.'));
    await sleep(5800);
    catat('papan PIC');

    // ── 5. Tabel: tahap & giliran ───────────────────────────────────────────
    await ug(() => __ug.keElemen('div.table-wrap:last-of-type', 90));
    await sleep(950);
    await ug(() => { const t = document.querySelectorAll('div.table-wrap'); __ug.sorot(t[t.length - 1], 6); });
    await ug(() => __ug.cap('Satu baris = satu kesepakatan',
      'Kolom "Tahap sekarang" menjawab berhenti di mana, "Giliran" menjawab menunggu siapa, "Macet" menjawab sudah berapa hari.'));
    await sleep(6200);
    catat('tabel dokumen');

    // ── 6. Membuka rincian satu berkas ──────────────────────────────────────
    await ug(() => __ug.lepasSorot());
    const bisaBuka = await p.evaluate(() => {
      const t = [...document.querySelectorAll('button.mon-buka')];
      for (const x of t) {
        const baris = x.closest('tr'), rinci = baris && baris.nextElementSibling;
        if (rinci && rinci.classList.contains('mon-rinci')) {
          window.__ugTombol = x;
          // Barisnya tidak punya id, jadi digulir lewat kotaknya sendiri —
          // bukan lewat selektor '#' yang kosong dan membuat querySelector
          // melempar di tengah rekaman.
          window.scrollTo({ top: baris.getBoundingClientRect().top + scrollY - 150, behavior: 'smooth' });
          return true;
        }
      }
      return false;
    });
    if (!bisaBuka) throw new Error('tidak ada baris yang bisa dibuka');
    await sleep(900);
    await ug(() => __ug.kursorKe(window.__ugTombol));
    await ug(() => __ug.cap('Tekan "+" untuk rinciannya', ''));
    await sleep(1700);
    await ug(() => { __ug.tekan(); window.__ugTombol.click(); });
    await sleep(1100);
    await ug(() => { __ug.lepasKursor(); __ug.keElemen('tr.mon-rinci:not([style*="display: none"])', 120); });
    await sleep(950);
    await ug(() => __ug.cap('Perjalanan dokumen, lengkap dengan tanggalnya',
      'Dibuat · diajukan · disetujui · diteken klien · transaksi · kontrak · invoice · lunas. Jeda terpanjang langsung kelihatan.'));
    await sleep(6400);
    catat('rincian berkas');

    // ── 7. Tindak lanjut ────────────────────────────────────────────────────
    // Panel ketiga ada di luar tepi kanan layar 1280 px — tabelnya memang
    // lebih lebar dari layarnya. Digeser mendatar dulu, kalau tidak adegan ini
    // membicarakan sesuatu yang tidak kelihatan oleh penontonnya.
    await ug(() => {
      const r = document.querySelector('tr.mon-rinci:not([style*="display: none"])');
      const wrap = r && r.closest('.table-wrap');
      if (wrap) wrap.scrollTo({ left: wrap.scrollWidth, behavior: 'smooth' });
    });
    await sleep(1300);
    await ug(() => {
      const f = document.querySelector('tr.mon-rinci:not([style*="display: none"]) form[action*="monitoring_tindak"]');
      if (f) { __ug.sorot(f.parentElement, 10); }
    });
    await ug(() => __ug.cap('Catat siapa yang sudah Anda kejar',
      '"Sudah saya kejar" · "Catatan saja" · "Tunda sampai…" · "Kecualikan". Yang dicatat usaha Anda, bukan status dokumennya.'));
    await sleep(6400);
    catat('tindak lanjut');

    // ── 8. Hanya giliran saya ───────────────────────────────────────────────
    await ug(() => { __ug.lepasSorot(); __ug.cap('Saring ke yang bisa Anda kerjakan sendiri', ''); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    await sleep(1400);
    await ug(() => __ug.kursorKe('input[name="saya"]'));
    await sleep(1500);
    await ug(() => __ug.tekan());
    await Promise.all([
      p.evaluate(() => { const c = document.querySelector('input[name="saya"]'); c.checked = true; c.form.submit(); }),
      p.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
    ]);
    await pasang();
    await sleep(700);
    await ug(() => __ug.sorot('.mon-tile', 10));
    await ug(() => __ug.cap('"Hanya giliran saya"',
      'Sisanya sedang di Approver, Legal, atau Admin Sales — mengejar diri sendiri untuk itu hanya buang waktu.'));
    await sleep(5600);
    catat('saringan giliran saya');

    // ── 9. Cocok dengan Dashboard ───────────────────────────────────────────
    await buka(MON + '&period=2026-10&mode=jalan');
    await sleep(700);
    const adaBanding = await ug(() => {
      const d = [...document.querySelectorAll('div.panel')]
        .find(x => /Cocok dengan Dashboard/.test(x.textContent || ''));
      if (!d) return false;
      __ug.keElemen('div.panel', 150);
      setTimeout(() => __ug.sorot(d, 8), 600);
      return true;
    });
    if (adaBanding) {
      await ug(() => __ug.cap('Angkanya bisa dicocokkan dengan Dashboard',
        'Pilih satu bulan, dan CLARA sendiri yang menyebutkan selisihnya beserta sebabnya.'));
      await sleep(6200);
      catat('pencocokan Dashboard');
    }

    // ── 10. Penutup ─────────────────────────────────────────────────────────
    await ug(() => { __ug.lepasSorot(); __ug.tutupCap(); });
    await sleep(700);
    await ug(() => __ug.judul('Selesai',
      'Monitoring<br><em>Transaksi</em>',
      'Rincian lengkapnya ada di PDF “Panduan CLARA — Monitoring Transaksi”'));
    await sleep(4000);
    catat('penutup');
  } finally {
    await rec.stop();
    await b.close();
  }

  // WebM hasil rekaman diubah ke MP4/H.264: itu yang bisa diputar langsung di
  // WhatsApp, PowerPoint, dan Quick Look tanpa dikonversi lagi oleh penerimanya.
  await new Promise((res, rej) => execFile(ffmpeg, [
    '-y', '-i', webm,
    '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'medium', '-crf', '23',
    '-movflags', '+faststart', '-r', '25', mp4,
  ], (e, so, se) => e ? rej(new Error(se.slice(-500))) : res()));
  fs.unlinkSync(webm);

  const kb = Math.round(fs.statSync(mp4).size / 1024);
  console.log(`OK → ${mp4} (${kb} KB, ${adegan.length} adegan)`);
})().catch(e => { console.error('GAGAL:', e.message); process.exit(1); });
