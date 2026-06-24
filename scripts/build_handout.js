// Bangun HANDOUT_SALES.html (sisip seksi Paket + selaraskan narasi alur terkini) → render PDF A4.
// Idempotent: aman dijalankan ulang. Lihat memory [[reference-headless-screenshot]].
const fs = require('fs');
const puppeteer = require('puppeteer-core');
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const ROOT = '/Applications/XAMPP/xamppfiles/htdocs/clara/';
const ASSETS = ROOT + 'scripts/handout_assets/';
const HTML = ROOT + 'HANDOUT_SALES.html';
const PDF = ROOT + 'HANDOUT_SALES.pdf';

const b64 = f => fs.readFileSync(ASSETS + f).toString('base64');
const img = (f, cap) =>
  `      <figure class='shot desk'><img src='data:image/png;base64,${b64(f)}' alt='${f}'><figcaption>${cap}</figcaption></figure>`;

const section = `<div class="sec">
  <h2>🎁 Penawaran PAKET (gabungan beberapa booth / titik media)</h2>
  <p class="lead">Satu Surat Penawaran bisa memuat <b>beberapa unit sekaligus</b> — multi-booth dan/atau multi-titik media — dengan <b>harga sendiri-sendiri per komponen</b> dan periode yang sama. Hasilnya <b>1 surat, 1 TTD, 1 SKP Paket</b>.</p>
  <ol class="steps">
    <li>Centang <b>"Penawaran Paket"</b> di form.</li>
    <li>Klik <b>+ Tambah komponen</b> untuk tiap unit/titik. Isi <b>Jenis</b> (Exhibition/Media), kode unit/titik, nama, dan <b>harga/bulan, DP, Deposit</b> komponen itu (harga tiap titik boleh beda).</li>
    <li><b>Periode satu</b> untuk seluruh paket (semua komponen tanggalnya sama).</li>
    <li>Simpan → <b>1 Surat Penawaran</b> memuat semua komponen + total paket, <b>1 TTD</b> untuk semua.</li>
  </ol>
${img('d12_offer_form_bundle.png', 'Form "Penawaran Paket" — centang lalu tambah komponen (booth/titik media); harga per komponen')}
  <p class="lead">Saat <b>SKP Paket di-approve</b>, sistem otomatis menerbitkan <b>transaksi terpisah per komponen</b> (Exhibition &amp; Media masing-masing) agar laporan tiap segmen akurat — tapi di laporan <b>dihitung sebagai 1 deal</b>. Unit/titik yang <b>bentrok periode</b> dengan penawaran aktif lain otomatis <b>ditolak</b> (cegah dobel-booking).</p>
${img('d13_offer_view_bundle.png', 'Halaman penawaran paket — daftar komponen + "Transaksi Paket Terbit"; manajer bisa batalkan sebagian (wajib alasan), komponen lain tetap jalan')}
${img('d14_offer_pdf_bundle.png', 'Surat Penawaran Paket — seluruh komponen + Grand Total dalam satu dokumen')}
</div>

`;

// Selaraskan narasi alur dgn perilaku terkini (hardening). [old, new] — idempotent.
const fixes = [
  [
    '<li>Ubah status: <b>Tandai Terkirim → Tandai Nego</b>. Revisi lewat <b>Edit</b> (tercatat sebagai riwayat nego).</li>',
    '<li>Saat tawar-menawar, tandai <b>Nego</b>. Status <b>otomatis jadi “Terkirim”</b> begitu customer membuka link TTD — tak perlu ditandai manual. Revisi lewat <b>Edit</b> (tercatat sebagai riwayat nego); penawaran <b>Nego pun tetap bisa langsung di-TTD</b> (cukup kirim ulang link).</li>',
  ],
  [
    '<li><b>DEAL</b> terjadi saat: <b>(A)</b> customer <b>TTD online</b> → otomatis DEAL &amp; nilai terkunci, atau <b>(B)</b> Anda klik <b>Tandai DEAL</b>.</li>',
    '<li><b>DEAL</b> terjadi saat: <b>(A)</b> customer <b>TTD online</b> → otomatis DEAL &amp; nilai terkunci, atau <b>(B)</b> Anda klik <b>Tandai DEAL</b> (juga <b>mengunci salinan nilai</b>, sama seperti TTD). TTD online <b>hanya untuk penawaran yang sudah dibagikan</b> (Terkirim) dan <b>belum lewat 7 hari</b>.</li>',
  ],
  [
    'Customer membuka link → melihat <b>seluruh isi surat</b> (biaya, fasilitas, cara bayar, ketentuan lengkap) → tanda tangan pakai jari/mouse. Setelah TTD, penawaran otomatis <b>DEAL</b>.</p>',
    'Customer membuka link → melihat <b>seluruh isi surat</b> (biaya, fasilitas, cara bayar, ketentuan lengkap) → tanda tangan pakai jari/mouse. Begitu link <b>dibuka</b>, status penawaran otomatis menjadi <b>Terkirim</b>; setelah TTD → otomatis <b>DEAL</b>. Bila dibuka <b>setelah 7 hari</b>, TTD ditolak (kedaluwarsa).</p>',
  ],
  [
    '<li>Panel <b>“Persetujuan &amp; TTD Customer”</b>: <b>Salin Link</b> / <b>Salin Pesan</b> / <b>Kirim via WhatsApp</b>. Di Mobile: tombol <b>📤 Bagikan</b>.</li>',
    '<li>Panel <b>“Persetujuan &amp; TTD Customer”</b>: <b>Salin Link</b> / <b>Salin Pesan</b> / <b>Kirim via WhatsApp</b>. Di Mobile: tombol <b>📤 Bagikan</b>. <i>Status otomatis “Terkirim” saat customer membuka link.</i></li>',
  ],
  [
    '<li>Lengkapi (mis. unggah Akta / Surat Kuasa) → <b>kirim ke Legal</b> → tunggu badge <b>“Disetujui Legal”</b>.</li>',
    '<li>Lengkapi (mis. unggah Akta / Surat Kuasa) → <b>kirim ke Legal</b> (link berlaku <b>14 hari</b>) → Legal menyetujui (login akun-nya) → tunggu badge <b>“Disetujui Legal”</b>.</li>',
  ],
  [
    '<div class="warn">⏳ Masa berlaku penawaran <b>7 hari</b>. &nbsp; PPN tertulis 12% (beban efektif <b>11%</b>).</div>',
    '<div class="warn">⏳ Masa berlaku penawaran <b>7 hari</b> (dipaksa sistem — lewat itu TTD ditolak). &nbsp; PPN tertulis 12% (beban efektif <b>11%</b>).</div>',
  ],
  [
    '<tr><td>Masa berlaku</td><td>Penawaran <b>7 hari</b>.</td></tr>',
    '<tr><td>Masa berlaku</td><td>Penawaran <b>7 hari</b> — <b>dipaksa sistem</b>: lewat itu TTD online ditolak (kedaluwarsa).</td></tr>' +
      '\n    <tr><td>Status otomatis</td><td>Saat customer <b>membuka link TTD</b>, status auto jadi <b>Terkirim</b> (dari Draft/Nego).</td></tr>' +
      '\n    <tr><td>Penawaran Paket</td><td>1 surat bisa banyak komponen (booth/titik media) → 1 TTD → 1 SKP → transaksi per komponen, <b>dihitung 1 deal</b>.</td></tr>' +
      '\n    <tr><td>Batal sebagian</td><td>Komponen paket bisa dibatalkan <b>manajer</b> (wajib alasan); komponen lain tetap jalan.</td></tr>' +
      '\n    <tr><td>Link Legal</td><td>Link Permintaan Kontrak ke Legal berlaku <b>14 hari</b>.</td></tr>',
  ],
];

let html = fs.readFileSync(HTML, 'utf8');

// 1) sisip seksi paket (sekali)
if (!html.includes('Penawaran PAKET (gabungan')) {
  const anchor = '<div class="sec">\n  <h2>Untuk Admin';
  const i = html.indexOf(anchor);
  if (i < 0) throw new Error('anchor "Untuk Admin" tak ditemukan');
  html = html.slice(0, i) + section + html.slice(i);
  console.log('+ seksi paket disisipkan');
} else {
  console.log('= seksi paket sudah ada');
}

// 2) selaraskan narasi
let applied = 0;
for (const [oldS, newS] of fixes) {
  if (html.includes(oldS)) { html = html.replace(oldS, newS); applied++; }
}
console.log('+ fixes diterapkan: ' + applied + '/' + fixes.length);

fs.writeFileSync(HTML, html);

(async () => {
  const b = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--disable-gpu'] });
  const p = await b.newPage();
  await p.goto('file://' + HTML, { waitUntil: 'networkidle2' });
  await p.pdf({ path: PDF, format: 'A4', printBackground: true, margin: { top: 0, bottom: 0, left: 0, right: 0 } });
  await b.close();
  console.log('PDF dibuat: ' + PDF);
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
