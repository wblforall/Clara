// Video pengenalan aplikasi CLARA — bernarasi, beralur, dengan editan gerak.
//
// Tiga hal yang membedakannya dari scripts/userguide_monitoring_video.js:
//
// 1) ADA SUARANYA. Narasinya dibangkitkan mesin suara Bahasa Indonesia bawaan
//    macOS (Damayanti) lewat perintah `say`. Tidak ada berkas suara yang perlu
//    disimpan di repo, dan naskahnya bisa disunting sebagai teks biasa.
//
// 2) SUARA YANG MENENTUKAN TIMING, BUKAN SEBALIKNYA. Tiap kalimat dibangkitkan
//    lebih dulu, diukur durasinya, baru adegannya dijalankan selama itu. Cara
//    sebaliknya — menebak lama adegan lalu memaksa narasi masuk — selalu
//    berakhir dengan kalimat yang terpotong di tengah atau gambar yang diam
//    menunggu suara.
//
// 3) EDITANNYA BERLAPIS. Kartu bab, lower-third, sorotan, kursor, bilah
//    kemajuan, zoom halus, dan transisi sapuan warna — semuanya animasi CSS
//    yang disuntikkan ke halaman, jadi perekamnya cukup satu lintasan.
//
// Jalankan (tidak ada sandi yang ditulis di berkas ini):
//   CLARA_BASE=http://127.0.0.1:8111/ CLARA_UG_PASS='...' \
//   NODE_PATH=<node_modules: puppeteer-core + ffmpeg-static> \
//   node scripts/clara_promo_video.js
//
// Keluaran → ~/Documents/Handout Program/clara/CLARA_Pengenalan.mp4

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFile } = require('child_process');
const puppeteer = require('puppeteer-core');
const ffmpeg = require('ffmpeg-static');

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE   = process.env.CLARA_BASE || 'http://127.0.0.1:8111/';
const PASS   = process.env.CLARA_UG_PASS || '';
const AKUN   = process.env.UG_EMAIL || 'uji.lokal@clara.test';
const OUTDIR = process.env.CLARA_UG_OUT || ((process.env.HOME || '') + '/Documents/Handout Program/clara/');
const SUARA  = process.env.CLARA_TTS_VOICE || 'Damayanti';
const LAJU   = process.env.CLARA_TTS_RATE  || '168';
const NAMA   = 'CLARA_Pengenalan';

const W = 1280, H = 720;
const JEDA_ANTAR = 900;    // hening antar kalimat, supaya tidak terdengar terburu
const AWAL_HENING = 700;   // hening sebelum kalimat pertama
const sleep = ms => new Promise(r => setTimeout(r, ms));
const jalankan = (bin, args) => new Promise((res, rej) =>
  execFile(bin, args, { maxBuffer: 1 << 26 }, (e, so, se) => e ? rej(new Error((se || e.message).slice(-400))) : res(so + se)));

// ── naskah ───────────────────────────────────────────────────────────────────
// Satu entri = satu adegan. `kartu` tampil sebagai kartu bab di awal adegan,
// `sorot` menyorot elemen, `cap` tampil di kaki layar bersama narasinya.
const ADEGAN = [
  {
    id: 'buka', rute: null, kartu: null,
    narasi: 'CLARA. Casual Leasing Achievement and Revenue Analytics. Satu aplikasi untuk seluruh perjalanan sewa, dari penawaran sampai pembayaran lunas.',
  },
  {
    id: 'masalah', rute: null, papan: {
      judul: 'Sebelumnya',
      butir: ['Penawaran diketik ulang di Word',
              'Transaksi direkap di banyak berkas Excel',
              'Tagihan diingat masing-masing orang',
              'Tidak ada yang tahu sebuah berkas berhenti di mana'],
    },
    narasi: 'Dulu, satu kesepakatan tercatat di banyak tempat. Penawaran diketik ulang di Word, transaksi direkap di Excel, dan tagihan diingat masing-masing orang.',
  },
  {
    id: 'dashboard', rute: 'dashboard', kartu: ['01', 'Dashboard', 'Angka hari ini, tanpa ada yang merekap'],
    narasi: 'CLARA membuka dengan satu layar. Income bulan berjalan, pencapaian terhadap target, dan perbandingan dengan tahun lalu, semuanya terhitung sendiri.',
    cap: 'Income berjalan · pencapaian target · perbandingan tahun lalu',
    zoom: true,
  },
  {
    id: 'penawaran', rute: 'offers', kartu: ['02', 'Surat Penawaran', 'Terbit dari template, bukan diketik ulang'],
    narasi: 'Surat penawaran tidak lagi diketik ulang. Pilih unit, isi periode dan harga, lalu suratnya terbit lengkap dengan fasilitas, cara pembayaran, dan ketentuannya.',
    cap: 'Template bisa diatur sendiri — per properti, per jenis unit',
    zoom: true,
  },
  {
    id: 'skp', rute: 'skp&module=cl', kartu: ['03', 'SKP & Persetujuan', 'Diparaf berjenjang, diteken klien lewat tautan'],
    narasi: 'Setelah klien setuju, dokumennya dibuat sekali lalu berjalan sendiri. Diparaf berjenjang, disetujui, kemudian ditandatangani klien lewat tautan. Tanpa cetak, tanpa antar berkas.',
    cap: 'Tanda tangan digital + QR validasi di tiap dokumen',
    zoom: true,
  },
  {
    id: 'kontrak', rute: 'contract_requests', kartu: ['04', 'Permintaan Kontrak', 'Satu jalur ke Legal, nomornya kembali sendiri'],
    narasi: 'Permintaan kontrak dikirim ke Legal dari dalam aplikasi, dan nomor kontraknya kembali menempel ke berkas yang sama.',
    zoom: true,
  },
  {
    id: 'transaksi', rute: 'transactions&module=cl', kartu: ['05', 'Transaksi', 'Nilainya dibagi ke tiap bulan sewa'],
    narasi: 'Begitu dokumen diteken, transaksinya terbit otomatis. Nilainya dialokasikan ke tiap bulan masa sewa, sehingga income bulan ini selalu berarti income bulan ini.',
    cap: 'Alokasi per bulan — dasar yang sama dipakai seluruh laporan',
    zoom: true,
  },
  {
    id: 'tagihan', rute: 'payments', kartu: ['06', 'Tagihan & Pembayaran', 'Lunas, kurang bayar, lewat tempo'],
    narasi: 'Invoice dan pembayaran dicatat di satu tempat. Kurang bayar, lewat jatuh tempo, dan sisa tagihan terbaca tanpa perlu dihitung manual.',
    zoom: true,
  },
  {
    id: 'monitoring', rute: 'monitoring&sort=macet&dir=desc', kartu: ['07', 'Monitoring Transaksi', 'Berhenti di mana, giliran siapa'],
    narasi: 'Dan inilah pintu masuknya. Monitoring Transaksi menjawab dua pertanyaan untuk setiap berkas: berhenti di mana, dan sekarang giliran siapa.',
    sorot: '.mon-tile', ke: '.mon-tile',
    cap: 'Lengkap dengan pengingat berangka di menu samping',
  },
  {
    id: 'laporan', rute: 'pic_report', kartu: ['08', 'Pencapaian & Laporan', 'Tersusun sendiri dari data yang sama'],
    narasi: 'Di ujungnya, pencapaian tiap PIC dan ringkasan eksekutif tersusun sendiri dari data yang sama. Bukan dari rekap yang diketik ulang.',
    zoom: true,
  },
  {
    id: 'tutup', rute: null,
    narasi: 'CLARA. Dari penawaran sampai lunas. Satu alur, satu angka, satu kebenaran. PT Wulandari Bangun Laksana.',
  },
];

// ── lapisan editan yang disuntikkan ke tiap halaman ──────────────────────────
const LAPIS = () => {
  if (window.__pv) return;
  const s = document.createElement('style');
  s.textContent = `
  @keyframes pvMasuk{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:none}}
  @keyframes pvRenggang{from{opacity:0;letter-spacing:.42em}to{opacity:1;letter-spacing:.02em}}
  #pv-bar{position:fixed;top:0;left:0;height:3px;z-index:2147483640;width:0%;
    background:linear-gradient(90deg,#2DD4BF,#0891B2);transition:width .9s linear;box-shadow:0 0 12px rgba(45,212,191,.8)}
  #pv-sapu{position:fixed;inset:0;z-index:2147483620;pointer-events:none;
    background:linear-gradient(120deg,#0B4F4A 0%,#0D9488 52%,#0891B2 100%);
    transform:translateX(-101%);transition:transform .52s cubic-bezier(.76,0,.24,1)}
  #pv-sapu.tutup{transform:translateX(0)}
  #pv-sapu.buka{transform:translateX(101%)}
  #pv-kartu{position:fixed;inset:0;z-index:2147483560;display:flex;flex-direction:column;
    align-items:center;justify-content:center;text-align:center;pointer-events:none;
    background:radial-gradient(120% 120% at 50% 0%,rgba(11,79,74,.97),rgba(8,22,30,.97));
    color:#fff;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;opacity:0;transition:opacity .45s ease}
  #pv-kartu.on{opacity:1}
  #pv-kartu .no{font-size:13px;font-weight:800;letter-spacing:.34em;color:#5EEAD4;margin-bottom:16px;
    animation:pvRenggang .8s both}
  #pv-kartu .jd{font-size:46px;font-weight:800;line-height:1.1;animation:pvMasuk .75s .1s both}
  #pv-kartu .sb{font-size:16.5px;color:rgba(255,255,255,.76);margin-top:14px;font-weight:300;
    animation:pvMasuk .75s .22s both}
  #pv-kartu .gr{width:54px;height:2px;background:#5EEAD4;border-radius:2px;margin-top:22px;
    animation:pvMasuk .75s .32s both}
  #pv-judul{position:fixed;inset:0;z-index:2147483560;display:flex;flex-direction:column;
    align-items:center;justify-content:center;text-align:center;pointer-events:none;
    background:linear-gradient(150deg,#0B4F4A 0%,#0D9488 48%,#0891B2 100%);
    color:#fff;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;opacity:0;transition:opacity .6s ease}
  #pv-judul.on{opacity:1}
  #pv-judul img{width:188px;margin-bottom:26px;animation:pvMasuk .9s both}
  #pv-judul .t1{font-size:52px;font-weight:800;letter-spacing:.06em;animation:pvRenggang 1.1s .15s both}
  #pv-judul .t2{font-size:13.5px;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.66);
    margin-top:10px;animation:pvMasuk .9s .4s both}
  #pv-judul .t3{font-size:17px;color:rgba(255,255,255,.86);margin-top:22px;font-weight:300;line-height:1.6;
    max-width:720px;animation:pvMasuk .9s .62s both}
  #pv-papan{position:fixed;inset:0;z-index:2147483555;display:flex;flex-direction:column;
    align-items:center;justify-content:center;pointer-events:none;background:#081620;color:#fff;
    font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;opacity:0;transition:opacity .45s ease}
  #pv-papan.on{opacity:1}
  #pv-papan h3{font-size:14px;letter-spacing:.26em;text-transform:uppercase;color:#64748B;margin-bottom:28px;
    animation:pvRenggang .8s both}
  #pv-papan li{list-style:none;font-size:23px;font-weight:600;color:#CBD5E1;margin:0 0 16px;
    padding-left:34px;position:relative;opacity:0}
  #pv-papan li.on{animation:pvMasuk .6s both}
  #pv-papan li:before{content:'✕';position:absolute;left:0;color:#F87171;font-weight:800}
  #pv-low{position:fixed;left:40px;bottom:46px;z-index:2147483500;pointer-events:none;
    font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;opacity:0;transform:translateX(-16px);
    transition:opacity .45s ease,transform .45s ease}
  #pv-low.on{opacity:1;transform:none}
  #pv-low .bx{display:inline-flex;align-items:center;gap:12px;background:rgba(8,22,30,.9);
    border-left:3px solid #2DD4BF;border-radius:0 10px 10px 0;padding:11px 20px 11px 16px;
    box-shadow:0 10px 32px rgba(0,0,0,.4)}
  #pv-low .n{font-size:12px;font-weight:800;color:#5EEAD4;letter-spacing:.14em}
  #pv-low .t{font-size:17px;font-weight:700;color:#fff}
  #pv-cap{position:fixed;left:0;right:0;bottom:0;z-index:2147483480;pointer-events:none;
    background:linear-gradient(to top,rgba(8,22,30,.95) 55%,rgba(8,22,30,0));
    padding:40px 40px 20px;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;
    color:#9FD8D2;font-size:15px;text-align:center;opacity:0;transition:opacity .4s ease}
  #pv-cap.on{opacity:1}
  #pv-sorot{position:absolute;z-index:2147483400;border:3px solid #2DD4BF;border-radius:14px;
    box-shadow:0 0 0 9999px rgba(8,22,30,.5);opacity:0;pointer-events:none;
    transition:all .6s cubic-bezier(.4,0,.2,1)}
  #pv-sorot.on{opacity:1}
  .pv-zoom{transition:transform 9s linear;transform-origin:50% 22%}
  /* PEMAKSA FRAME. Chrome hanya mengirimkan gambar ke perekam saat ada yang
     berubah di layar; selama adegan diam, perekamnya tidak menerima apa-apa
     dan rekaman dua menit menyusut jadi satu setengah menit — narasinya lalu
     tidak lagi jatuh di adegan yang benar. Titik 2 piksel yang berdenyut
     tipis di pojok memaksa Chrome menggambar ulang tiap frame. */
  @keyframes pvTik{to{transform:translateZ(0) rotate(360deg)}}
  #pv-tik{position:fixed;right:0;bottom:0;width:2px;height:2px;border-radius:50%;
    background:rgba(13,148,136,.05);z-index:2147483647;pointer-events:none;will-change:transform;
    animation:pvTik 2s linear infinite}`;
  document.head.appendChild(s);

  const buat = (id, html) => {
    const d = document.createElement('div'); d.id = id;
    if (html) d.innerHTML = html;
    document.body.appendChild(d); return d;
  };
  const bar   = buat('pv-bar');
  const sapu  = buat('pv-sapu');
  const kartu = buat('pv-kartu', '<div class="no"></div><div class="jd"></div><div class="sb"></div><div class="gr"></div>');
  const judul = buat('pv-judul', '<img alt=""><div class="t1"></div><div class="t2"></div><div class="t3"></div>');
  const papan = buat('pv-papan', '<h3></h3><ul></ul>');
  const low   = buat('pv-low', '<div class="bx"><span class="n"></span><span class="t"></span></div>');
  const cap   = buat('pv-cap');
  const sorot = buat('pv-sorot');
  buat('pv-tik');

  window.__pv = {
    bar(p) { bar.style.width = Math.max(0, Math.min(100, p)) + '%'; },
    sapuTutup() { sapu.classList.remove('buka'); sapu.classList.add('tutup'); },
    sapuBuka()  { sapu.classList.remove('tutup'); sapu.classList.add('buka'); },
    judul(logo, t1, t2, t3) {
      const img = judul.querySelector('img');
      if (logo) { img.src = logo; img.style.display = ''; } else { img.style.display = 'none'; }
      judul.querySelector('.t1').textContent = t1;
      judul.querySelector('.t2').textContent = t2 || '';
      judul.querySelector('.t3').textContent = t3 || '';
      judul.classList.add('on');
    },
    tutupJudul() { judul.classList.remove('on'); },
    kartu(no, jd, sb) {
      kartu.querySelector('.no').textContent = no;
      kartu.querySelector('.jd').textContent = jd;
      kartu.querySelector('.sb').textContent = sb || '';
      // Animasi diputar ulang dengan memasang ulang simpulnya.
      kartu.innerHTML = kartu.innerHTML;
      kartu.classList.add('on');
    },
    tutupKartu() { kartu.classList.remove('on'); },
    papan(judulTeks, butir) {
      papan.querySelector('h3').textContent = judulTeks;
      const ul = papan.querySelector('ul');
      ul.innerHTML = butir.map(b => `<li>${b}</li>`).join('');
      papan.classList.add('on');
      [...ul.children].forEach((li, i) => setTimeout(() => li.classList.add('on'), 420 + i * 560));
    },
    tutupPapan() { papan.classList.remove('on'); },
    low(no, t) {
      low.querySelector('.n').textContent = no;
      low.querySelector('.t').textContent = t;
      low.classList.add('on');
    },
    tutupLow() { low.classList.remove('on'); },
    cap(t) { cap.textContent = t || ''; cap.classList.toggle('on', !!t); },
    sorot(sel, pad) {
      const el = typeof sel === 'string' ? document.querySelector(sel) : sel;
      if (!el) { sorot.classList.remove('on'); return false; }
      const p = pad == null ? 10 : pad;
      const r = el.getBoundingClientRect();
      sorot.style.left = (r.left + scrollX - p) + 'px';
      sorot.style.top  = (r.top + scrollY - p) + 'px';
      sorot.style.width  = (r.width + p * 2) + 'px';
      sorot.style.height = (r.height + p * 2) + 'px';
      sorot.classList.add('on');
      return true;
    },
    lepasSorot() { sorot.classList.remove('on'); },
    // Zoom lambat ala Ken Burns. Dipasang pada pembungkus aplikasi saja, bukan
    // pada <body>, supaya lapisan editan yang menempel di body tidak ikut
    // membesar bersamanya.
    zoom(ke) {
      const app = document.querySelector('.app') || document.body.firstElementChild;
      if (!app) return;
      app.classList.add('pv-zoom');
      requestAnimationFrame(() => { app.style.transform = 'scale(' + ke + ')'; });
    },
    lepasZoom() {
      const app = document.querySelector('.app') || document.body.firstElementChild;
      if (!app) return;
      app.style.transition = 'none'; app.style.transform = 'none';
      requestAnimationFrame(() => { app.style.transition = ''; });
    },
    keElemen(sel, off) {
      const el = document.querySelector(sel);
      if (!el) return false;
      window.scrollTo({ top: el.getBoundingClientRect().top + scrollY - (off == null ? 100 : off), behavior: 'smooth' });
      return true;
    },
  };
};

// ── narasi ───────────────────────────────────────────────────────────────────
async function buatNarasi(dir) {
  fs.mkdirSync(dir, { recursive: true });
  const klip = [];
  for (let i = 0; i < ADEGAN.length; i++) {
    const f = path.join(dir, `n${String(i).padStart(2, '0')}.aiff`);
    await jalankan('/usr/bin/say', ['-v', SUARA, '-r', LAJU, '-o', f, ADEGAN[i].narasi]);
    const keluaran = await jalankan(ffmpeg, ['-i', f]).catch(e => e.message);
    const m = keluaran.match(/Duration:\s*(\d+):(\d+):(\d+\.\d+)/);
    if (!m) throw new Error('durasi narasi tidak terbaca: ' + ADEGAN[i].id);
    const detik = (+m[1]) * 3600 + (+m[2]) * 60 + parseFloat(m[3]);
    klip.push({ berkas: f, ms: Math.round(detik * 1000) });
    console.log(`   narasi ${ADEGAN[i].id}: ${detik.toFixed(1)} dtk`);
  }
  return klip;
}

(async () => {
  if (!PASS) { console.error('CLARA_UG_PASS belum diisi.'); process.exit(2); }
  fs.mkdirSync(OUTDIR, { recursive: true });
  const kerja = fs.mkdtempSync(path.join(os.tmpdir(), 'clara-promo-'));
  const webm  = path.join(kerja, 'rekaman.webm');
  const wav   = path.join(kerja, 'narasi.wav');
  const mp4   = path.join(OUTDIR, NAMA + '.mp4');

  console.log('1/4 membangkitkan narasi…');
  const klip = await buatNarasi(path.join(kerja, 'suara'));
  const total = AWAL_HENING + klip.reduce((a, k) => a + k.ms + JEDA_ANTAR, 0);
  console.log(`   total ${(total / 1000).toFixed(1)} detik`);

  console.log('2/4 merekam layar…');
  const b = await puppeteer.launch({
    executablePath: CHROME, headless: 'new',
    args: ['--no-sandbox', '--disable-gpu', `--window-size=${W},${H}`],
  });
  const p = await b.newPage();
  await p.setViewport({ width: W, height: H, deviceScaleFactor: 1 });

  // login
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

  const logo = fs.existsSync(path.resolve(__dirname, '../public/assets/clara-logo.png'))
    ? 'data:image/png;base64,' + fs.readFileSync(path.resolve(__dirname, '../public/assets/clara-logo.png')).toString('base64')
    : '';

  await p.goto(BASE + '?r=dashboard', { waitUntil: 'networkidle2' });
  await p.evaluate(LAPIS);

  const rec = await p.screencast({ path: webm, ffmpegPath: ffmpeg });
  // Letak narasi diambil dari waktu NYATA tiap adegan dimulai, bukan dari
  // jumlah durasi yang direncanakan. Satu pemuatan halaman yang kebetulan
  // lambat akan menggeser semua adegan sesudahnya, dan narasi yang ditaruh
  // menurut rencana jadi mendahului gambarnya.
  const t0 = Date.now();
  const mulaiAdegan = [];
  let rekamSelesai = 0;
  await sleep(AWAL_HENING);

  try {
    for (let i = 0; i < ADEGAN.length; i++) {
      const a = ADEGAN[i], lama = klip[i].ms;
      const sisa = () => lama - (Date.now() - mulai);
      const mulai = Date.now();
      mulaiAdegan.push(mulai - t0);

      // Pindah halaman selalu disembunyikan di balik sapuan warna — tanpa itu
      // penonton melihat layar putih sepersekian detik tiap ganti menu.
      if (a.rute) {
        await p.evaluate(() => __pv.sapuTutup());
        await sleep(520);
        await p.goto(BASE + '?r=' + a.rute, { waitUntil: 'networkidle2' });
        await p.evaluate(LAPIS);
        await p.evaluate(() => { __pv.sapuTutup(); });
        await sleep(120);
      }
      await p.evaluate((pct) => __pv.bar(pct), Math.round((i + 1) / ADEGAN.length * 100));

      if (a.id === 'buka') {
        await p.evaluate((l) => __pv.judul(l, 'CLARA', 'Casual Leasing Achievement & Revenue Analytics',
          'Satu aplikasi untuk seluruh perjalanan sewa — dari penawaran sampai pembayaran lunas'), logo);
        await sleep(Math.max(600, sisa()));
        await p.evaluate(() => __pv.tutupJudul());
        await sleep(JEDA_ANTAR); continue;
      }
      if (a.id === 'tutup') {
        await p.evaluate(() => { __pv.lepasSorot(); __pv.tutupLow(); __pv.cap(''); });
        await p.evaluate((l) => __pv.judul(l, 'CLARA', 'PT. Wulandari Bangun Laksana Tbk.',
          'Dari penawaran sampai lunas — satu alur, satu angka, satu kebenaran'), logo);
        await sleep(Math.max(600, sisa()));
        await sleep(JEDA_ANTAR); continue;
      }
      if (a.papan) {
        await p.evaluate((j, bt) => __pv.papan(j, bt), a.papan.judul, a.papan.butir);
        await sleep(Math.max(600, sisa()));
        await p.evaluate(() => __pv.tutupPapan());
        await sleep(JEDA_ANTAR); continue;
      }

      // Adegan halaman: kartu bab → sapuan dibuka → lower-third → isi
      if (a.kartu) {
        await p.evaluate((n, j, s) => __pv.kartu(n, j, s), ...a.kartu);
        await sleep(1700);
        await p.evaluate(() => { __pv.tutupKartu(); __pv.sapuBuka(); });
        await sleep(420);
        await p.evaluate((n, t) => __pv.low(n, t), a.kartu[0], a.kartu[1]);
      } else {
        await p.evaluate(() => __pv.sapuBuka());
        await sleep(420);
      }
      if (a.cap) await p.evaluate((t) => __pv.cap(t), a.cap);
      if (a.ke)  { await p.evaluate((s) => __pv.keElemen(s, 110), a.ke); await sleep(700); }
      if (a.sorot) await p.evaluate((s) => __pv.sorot(s, 10), a.sorot);
      if (a.zoom) await p.evaluate(() => __pv.zoom(1.035));

      await sleep(Math.max(400, sisa()));
      await p.evaluate(() => { __pv.cap(''); __pv.lepasSorot(); __pv.lepasZoom(); });
      await sleep(JEDA_ANTAR);
      console.log('   ·', a.id);
    }
  } finally {
    rekamSelesai = Date.now();
    await rec.stop();
    await b.close();
  }

  // ── rakit audio: tiap klip ditaruh tepat di detik mulainya ─────────────────
  console.log('3/4 merakit narasi…');
  const masuk = [];
  const tapis = [];
  klip.forEach((k, i) => {
    const t = Math.max(0, mulaiAdegan[i] != null ? mulaiAdegan[i] : AWAL_HENING);
    masuk.push('-i', k.berkas);
    tapis.push(`[${i}:a]adelay=${t}|${t},aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo[a${i}]`);
  });
  await jalankan(ffmpeg, ['-y', ...masuk, '-filter_complex',
    tapis.join(';') + ';' + klip.map((_, i) => `[a${i}]`).join('') + `amix=inputs=${klip.length}:normalize=0[out]`,
    '-map', '[out]', '-ar', '44100', '-ac', '2', wav]);

  // Perekam puppeteer menulis frame pada laju TETAP, sementara Chrome
  // mengirimkannya lebih lambat dari itu. Akibatnya rekaman dua menit tersimpan
  // sebagai berkas satu menit yang gerakannya kelewat cepat, dan narasinya
  // melenceng makin jauh di tiap adegan. Durasinya diukur, lalu dikembalikan ke
  // lama yang sebenarnya — satu peregangan merata, karena frame-nya mengalir
  // dengan laju yang sama sepanjang rekaman.
  // WebM keluaran pipa tidak membawa Duration di kepalanya, jadi durasinya
  // diukur dengan melewatkan seluruh streamnya sekali dan membaca cap waktu
  // terakhir yang dilaporkan ffmpeg.
  const infoWebm = await jalankan(ffmpeg, ['-i', webm, '-f', 'null', '-']).catch(e => e.message);
  const semuaCap = [...infoWebm.matchAll(/time=\s*(\d+):(\d+):(\d+\.\d+)/g)];
  const mw = semuaCap.length ? semuaCap[semuaCap.length - 1] : null;
  const lamaWebm = mw ? ((+mw[1]) * 3600 + (+mw[2]) * 60 + parseFloat(mw[3])) : 0;
  const lamaNyata = (rekamSelesai - t0) / 1000;
  const regang = (lamaWebm > 1 && lamaNyata > 1) ? (lamaNyata / lamaWebm) : 1;
  console.log(`   rekaman ${lamaWebm.toFixed(1)} dtk → sebenarnya ${lamaNyata.toFixed(1)} dtk (×${regang.toFixed(2)})`);

  console.log('4/4 menggabung video + suara…');
  await jalankan(ffmpeg, ['-y', '-i', webm, '-i', wav,
    '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'slow', '-crf', '21',
    // tpad menahan frame terakhir bila rekamannya berakhir lebih dulu dari
    // narasinya; -shortest dulu memotong kalimat penutup di tengah.
    '-vf', `setpts=${regang.toFixed(6)}*PTS,fade=t=in:st=0:d=0.6,tpad=stop_mode=clone:stop_duration=4`, '-r', '30',
    '-c:a', 'aac', '-b:a', '160k',
    '-movflags', '+faststart', mp4]);

  const kb = Math.round(fs.statSync(mp4).size / 1024);
  console.log(`OK → ${mp4} (${kb} KB)`);
  fs.rmSync(kerja, { recursive: true, force: true });
})().catch(e => { console.error('GAGAL:', e.message); process.exit(1); });
