<?php
/**
 * Peta alur kerja CLARA — satu berkas yang kalau dibuka langsung terlihat
 * bagaimana sebuah kesepakatan berjalan dari awal sampai masuk laporan.
 *
 * Isinya diambil dari kode, bukan dari ingatan:
 *   - jenis dokumen per modul      → skp_doc_type() di app/pages/skp_modules.php
 *   - syarat SKP lahir             → penjaga di skp_form() app/pages/skp.php
 *   - tahap persetujuan & siapanya → tabel skp_approval_flow (dibaca saat dibangun)
 *   - kapan income dihitung        → _skp_bangun_alokasi(), dipanggil saat TTD client
 *   - batas tautan tanda tangan    → sign_token_expiry_sql() di app/helpers.php
 *   - aturan wajib TTD berlaku     → settings.ttd_wajib_mulai
 *
 * Keluarannya dua: satu berkas HTML yang berdiri sendiri (gambar ditanam
 * sebagai data-URI, jadi bisa dikirim apa adanya) dan satu PDF A4 menyamping
 * bila Chrome tersedia di mesin ini.
 *
 * Pakai:
 *   php scripts/build_alur_clara.php
 *   php scripts/build_alur_clara.php --keluar=/path/folder
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';

$opt = array_merge(['keluar' => $root . '/docs'], getopt('', ['keluar:']));
$keluar = rtrim((string) $opt['keluar'], '/');
@mkdir($keluar, 0777, true);
date_default_timezone_set((string) (env_value('APP_TIMEZONE') ?: 'Asia/Makassar'));

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ── Pengaturan yang hidup di basis data ──────────────────────────────────────
// Nama pemaraf dan tanggal berlakunya aturan tanda tangan diambil dari sistem,
// bukan ditulis tetap di sini — supaya peta ini tidak jadi usang diam-diam
// ketika alurnya diubah lewat halaman Alur Persetujuan.
$tahap = ['skp' => [], 'sks' => [], 'fu' => []];
$ttdMulai = '';
try {
    $pdo = Database::connect();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Nama disebut PER MAL. Digabung jadi satu daftar ("Ocha / Yusri"), pembaca
    // mengira dokumennya diparaf dua orang — padahal tiap mal punya satu.
    $q = $pdo->query("SELECT f.doc_type, f.step_no, f.role_name, f.label, f.pic_name, p.name prop
                        FROM skp_approval_flow f
                        LEFT JOIN properties p ON p.id = f.property_id
                       WHERE f.is_active = 1 ORDER BY f.doc_type, f.step_no, f.property_id");
    $kumpul = [];
    foreach ($q as $r) {
        $n = (int) $r['step_no']; $d = (string) $r['doc_type'];
        $tahap[$d][$n]['label'] = (string) ($r['label'] ?: $r['role_name']);
        $nama = trim((string) $r['pic_name']);
        if ($nama === '') continue;
        $mal = preg_match('/pentacity/i', (string) $r['prop']) ? 'Pentacity'
             : (preg_match('/walk/i', (string) $r['prop']) ? 'e-Walk' : trim((string) $r['prop']));
        $kumpul[$d][$n][$nama][$mal] = true;
    }
    foreach ($kumpul as $d => $perTahap) {
        foreach ($perTahap as $n => $orang) {
            $bagian = [];
            foreach ($orang as $nama => $mals) {
                // Satu orang yang memegang kedua mal tidak perlu disebut dua kali.
                $bagian[] = count($mals) >= 2 ? $nama : $nama . ' (' . array_key_first($mals) . ')';
            }
            $tahap[$d][$n]['orang'] = implode(' · ', $bagian);
        }
    }
    $ttdMulai = (string) ($pdo->query("SELECT value FROM settings WHERE `key`='ttd_wajib_mulai' LIMIT 1")->fetchColumn() ?: '');
} catch (Throwable $e) {
    fwrite(STDERR, "Catatan: basis data tidak terbaca, peta memakai alur bawaan.\n");
}
if (!$tahap['skp']) {
    $tahap['skp'] = [1 => ['label' => 'Asst. Manager', 'orang' => ''], 2 => ['label' => 'Manager', 'orang' => '']];
}
foreach (['sks', 'fu'] as $dt) if (!$tahap[$dt]) $tahap[$dt] = $tahap['skp'];

$tahap1 = $tahap['skp'][1] ?? ['label' => 'Asst. Manager', 'orang' => ''];
$tahap2 = $tahap['skp'][2] ?? ['label' => 'Manager', 'orang' => ''];
$orang1 = $tahap1['orang'] !== '' ? ' (' . $tahap1['orang'] . ')' : '';
$orang2 = $tahap2['orang'] !== '' ? ' (' . $tahap2['orang'] . ')' : '';
// date('F') berbahasa Inggris; dokumen ini berbahasa Indonesia seluruhnya.
$BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
          'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$ttdTgl = '';
if ($ttdMulai !== '' && ($ts = strtotime($ttdMulai))) {
    $ttdTgl = date('j', $ts) . ' ' . $BULAN[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

// ── Potongan kop surat, ditanam sebagai data-URI ─────────────────────────────
// Kop aslinya dibuat untuk halaman tegak selebar 210mm. Dipasang utuh di
// halaman menyamping (297mm) gambarnya akan melar 1,4x dan logonya ikut
// penyok. Jadi kop dipotong jadi blok-blok: pola kiri, logo tengah, pola
// kanan — yang melebar cuma jarak putih di antaranya.
function potong_uri(string $src, int $x, int $w): string
{
    static $cache = [];
    $kunci = "$src|$x|$w";
    if (isset($cache[$kunci])) return $cache[$kunci];
    $im = @imagecreatefromjpeg($src);
    if (!$im) return '';
    $p = imagecreatetruecolor($w, imagesy($im));
    imagecopy($p, $im, 0, 0, $x, 0, $w, imagesy($im));
    ob_start(); imagejpeg($p, null, 92); $bin = (string) ob_get_clean();
    return $cache[$kunci] = 'data:image/jpeg;base64,' . base64_encode($bin);
}
$A       = $root . '/public/assets/';
$kopKiri = potong_uri($A . 'letterhead-head.jpg', 0, 376);
$kopLogo = potong_uri($A . 'letterhead-head.jpg', 410, 420);
$kopKanan= potong_uri($A . 'letterhead-head.jpg', 864, 376);
$kakiKiri= potong_uri($A . 'letterhead-foot.jpg', 40, 560);
$kakiKanan=potong_uri($A . 'letterhead-foot.jpg', 628, 612);

// ── Penolong penyusun bagan ──────────────────────────────────────────────────
/** Satu kotak langkah. $peran mewarnai kotak sesuai siapa yang mengerjakan. */
function kotak(string $peran, string $judul, string $isi = '', string $kelas = ''): string
{
    $b = $isi !== '' ? '<div class="k-isi">' . $isi . '</div>' : '';
    return '<div class="kotak ' . $peran . ' ' . $kelas . '"><div class="k-judul">' . $judul . '</div>' . $b . '</div>';
}
/** Kotak keputusan (belah ketupat terlalu boros ruang; dipakai bentuk sudut). */
function tanya(string $judul, string $isi = ''): string
{
    return kotak('tanya', '❓ ' . $judul, $isi);
}
/** Cabang "kalau tidak" — digantung di bawah langkah yang mendahuluinya. */
function cabang(string $judul, string $isi = ''): string
{
    return '<div class="cabang"><div class="c-judul">' . $judul . '</div><div class="c-isi">' . $isi . '</div></div>';
}
function panahKanan(): string { return '<div class="panah-x"><span></span></div>'; }
function panahBawah(string $teks = ''): string
{
    return '<div class="panah-y">' . ($teks !== '' ? '<em>' . $teks . '</em>' : '') . '</div>';
}
/** Satu fase: kepala bernomor + tumpukan kotak di bawahnya. */
function fase(int $no, string $judul, string $peran, string $isi): string
{
    return '<div class="fase"><div class="f-kepala ' . $peran . '"><b>' . $no . '</b> ' . $judul . '</div>'
         . '<div class="f-isi">' . $isi . '</div></div>';
}

$CSS = <<<'CSS'
*{box-sizing:border-box;margin:0;padding:0}
@page{size:A4 landscape;margin:0}
html,body{background:#fff}
body{font-family:"Segoe UI",-apple-system,BlinkMacSystemFont,Helvetica,Arial,sans-serif;
     color:#1F2937;-webkit-print-color-adjust:exact;print-color-adjust:exact}
@media screen{body{background:#E2E8F0}.lembar{box-shadow:0 2mm 6mm rgba(15,23,42,.18)}}
.lembar{position:relative;width:297mm;height:210mm;background:#fff;overflow:hidden;
        page-break-after:always;margin:0 auto 6mm}
.lembar:last-child{page-break-after:auto}
/* Saat dicetak, jarak antar-lembar dan latar abu layar harus hilang — kalau
   tidak, warnanya ikut tercetak sebagai pita abu di tepi bawah halaman. */
@media print{.lembar{margin:0}}
.kop{position:absolute;top:0;left:0;right:0;height:22mm;display:flex;
     justify-content:space-between;align-items:flex-start}
.kop img{height:22mm;display:block}
.kop .tengah{height:22mm;display:flex;align-items:center}
.kop .tengah img{height:17mm}
.kaki{position:absolute;bottom:0;left:0;right:0;height:23mm;display:flex;
      justify-content:space-between;align-items:flex-end}
.kaki img{display:block}
.kaki img.kr{height:23mm}
.kaki img.kn{height:23mm}
.isi{position:absolute;top:21mm;left:11mm;right:11mm;bottom:23mm;display:flex;flex-direction:column}
h1{font-size:17pt;letter-spacing:-.2pt;margin-bottom:.8mm}
h1 small{font-weight:400;font-size:10.5pt;color:#64748B;letter-spacing:0}
.sub{font-size:9.4pt;color:#64748B;margin-bottom:4mm;line-height:1.45}
h2{font-size:11.4pt;color:#0A7267;margin:0 0 2mm;padding-bottom:.9mm;border-bottom:1px solid #99F6E4}

/* ── bagan mendatar ───────────────────────────────────────────────── */
.alur{display:flex;align-items:stretch;gap:0;flex:1}
.fase{flex:1;display:flex;flex-direction:column;min-width:0}
.f-kepala{font-size:8.4pt;font-weight:700;color:#fff;border-radius:3px 3px 0 0;
          padding:1.7mm 2.4mm;letter-spacing:.02em;line-height:1.25;
          min-height:8.8mm;display:flex;align-items:center}
.f-kepala b{flex:0 0 4.4mm;height:4.4mm;line-height:4.4mm;text-align:center;
            background:rgba(255,255,255,.26);border-radius:50%;font-size:7.4pt;margin-right:1.4mm}
.f-isi{flex:1;border:1px solid #E2E8F0;border-top:none;border-radius:0 0 3px 3px;
       padding:2mm;display:flex;flex-direction:column;gap:1.7mm;background:#FCFDFE}
.panah-x{width:5.4mm;display:flex;align-items:center;justify-content:center;flex:0 0 5.4mm}
.panah-x span{width:0;height:0;border-left:2.2mm solid #94A3B8;
              border-top:1.7mm solid transparent;border-bottom:1.7mm solid transparent}
.panah-y{height:3mm;position:relative;display:flex;align-items:center;justify-content:center}
.panah-y:before{content:"";position:absolute;left:50%;top:0;bottom:0;width:0;
                border-left:2px dotted #CBD5E1}
.panah-y em{position:relative;background:#FCFDFE;padding:0 1mm;font-size:7pt;
            font-style:normal;color:#94A3B8}

.kotak{border:1px solid;border-left-width:2.6mm;border-radius:3px;padding:1.7mm 2.1mm;background:#fff}
.k-judul{font-size:8.2pt;font-weight:700;line-height:1.3}
.k-isi{font-size:7.3pt;color:#475569;line-height:1.44;margin-top:.8mm}
.k-isi b{color:#334155}
.kotak.sales  {border-color:#5EEAD4;background:#F0FDFA} .kotak.sales   .k-judul{color:#0F766E}
.kotak.asst   {border-color:#FCD34D;background:#FFFBEB} .kotak.asst    .k-judul{color:#B45309}
.kotak.manager{border-color:#C4B5FD;background:#F5F3FF} .kotak.manager .k-judul{color:#6D28D9}
.kotak.client {border-color:#7DD3FC;background:#F0F9FF} .kotak.client  .k-judul{color:#0369A1}
.kotak.sistem {border-color:#CBD5E1;background:#F8FAFC} .kotak.sistem  .k-judul{color:#475569}
.kotak.legal  {border-color:#F9A8D4;background:#FDF2F8} .kotak.legal   .k-judul{color:#BE185D}
.kotak.tanya  {border-color:#FDBA74;background:#FFF7ED} .kotak.tanya   .k-judul{color:#C2410C}
.kotak.hasil  {border-color:#86EFAC;background:#F0FDF4} .kotak.hasil   .k-judul{color:#15803D}
.kotak.stop   {border-color:#FCA5A5;background:#FEF2F2} .kotak.stop    .k-judul{color:#B91C1C}
.f-kepala.sales{background:#0D9488}.f-kepala.asst{background:#B45309}
.f-kepala.manager{background:#6D28D9}.f-kepala.client{background:#0369A1}
.f-kepala.sistem{background:#475569}.f-kepala.legal{background:#BE185D}

.cabang{border:1px dashed #CBD5E1;border-radius:3px;padding:1.4mm 1.9mm;background:#F8FAFC}
.c-judul{font-size:7.3pt;font-weight:700;color:#64748B;line-height:1.3}
.c-isi{font-size:7pt;color:#64748B;line-height:1.42;margin-top:.6mm}

/* ── kartu & kisi serba guna ──────────────────────────────────────── */
.kisi{display:grid;gap:4mm;flex:1}
.kisi-3{grid-template-columns:repeat(3,1fr)}
.kisi-32{grid-template-columns:repeat(3,1fr);grid-template-rows:repeat(2,minmax(0,1fr));gap:3mm}
.kisi-32 .kartu{padding:2.6mm 3mm;gap:1.5mm}
.kisi-32 .kartu h3{font-size:9.6pt}
.kisi-32 .kartu ul{font-size:7.6pt;line-height:1.46;margin-left:3.8mm}
.kisi-32 .kartu li{margin-bottom:.7mm}
.kisi-32 .kartu .tag{font-size:6.8pt;padding:.5mm 1.5mm}
.kisi-2{grid-template-columns:repeat(2,1fr)}
.kartu{border:1px solid #E2E8F0;border-radius:4px;padding:3.4mm 3.8mm;background:#fff;
       display:flex;flex-direction:column;gap:2mm}
.kartu h3{font-size:10.6pt;margin:0}
.kartu .tag{font-size:7.4pt;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
            padding:.7mm 1.8mm;border-radius:2px;display:inline-block}
.kartu p{font-size:8.4pt;color:#475569;line-height:1.52}
.kartu ul{margin:0 0 0 4.2mm;font-size:8.4pt;color:#475569;line-height:1.52}
.kartu li{margin-bottom:1mm}
.kartu li b{color:#1F2937}
.garis{border-top:1px solid #F1F5F9;margin:.4mm 0}

.pita{display:flex;gap:3mm;margin-bottom:4mm}
.pita .p{flex:1;border-radius:4px;padding:2.6mm 3mm;font-size:8.4pt;line-height:1.5;border:1px solid}
.pita .p b{display:block;font-size:9.2pt;margin-bottom:1mm}

.legenda{display:flex;gap:4.6mm;flex-wrap:wrap;font-size:8pt;color:#475569;
         margin-top:3.4mm;padding-top:2.6mm;border-top:1px solid #F1F5F9}
.legenda span{display:flex;align-items:center;gap:1.2mm}
.legenda i{width:3.4mm;height:3.4mm;border-radius:1px;display:inline-block}

.nomor{position:absolute;right:11mm;bottom:24.4mm;font-size:7.6pt;color:#94A3B8}
CSS;

// ── Susun lembar demi lembar ─────────────────────────────────────────────────
$lembar = [];

/* ══ Lembar 1 — peta besar ══════════════════════════════════════════════ */
ob_start(); ?>
<h1>Alur Kerja CLARA <small>— peta tiga modul</small></h1>
<div class="sub">Tiga modul memakai pola yang sama: <b>siapkan data master &rarr; penawaran &rarr; client setuju &rarr;
    surat konfirmasi &rarr; persetujuan berjenjang &rarr; tanda tangan client &rarr; masuk laporan</b>.
    Yang membedakan hanya nama dokumennya dan boleh-tidaknya melewati Surat Penawaran.</div>

<div class="pita">
    <div class="p" style="border-color:#C4B5FD;background:#F5F3FF">
        <b style="color:#6D28D9">Nomor dokumen terbit &amp; transaksi lahir</b>
        saat <?= $h($tahap2['label']) ?><?= $h($orang2) ?> menekan <b>Setujui</b> &mdash; bukan saat dokumen dibuat,
        bukan pula saat diparaf.</div>
    <div class="p" style="border-color:#86EFAC;background:#F0FDF4">
        <b style="color:#15803D">Income masuk Dashboard &amp; Achievement</b>
        saat <b>client menandatangani</b> surat konfirmasi.<?php if ($ttdTgl): ?>
        Aturan ini mengikat kesepakatan yang dibuat sejak <b><?= $h($ttdTgl) ?></b> &mdash; yang lebih lama
        tetap memakai cara lama.<?php endif; ?></div>
    <div class="p" style="border-color:#FCA5A5;background:#FEF2F2">
        <b style="color:#B91C1C">Tautan tanda tangan berlaku 30 hari</b>
        sejak dokumen disetujui. Lewat dari itu client tidak bisa lagi membukanya.</div>
</div>

<div class="kisi kisi-3">
    <div class="kartu">
        <div><span class="tag" style="background:#CCFBF1;color:#0F766E">Modul 1</span></div>
        <h3>Exhibition</h3>
        <p>Sewa unit pameran di lantai mal. Dokumennya <b>SKP &mdash; Surat Konfirmasi Pameran</b>,
           bernomor <code>SKP/EW/2026/nnn</code> (e-Walk) atau <code>SKP/PC/2026/nnn</code> (Pentacity).</p>
        <div class="garis"></div>
        <ul>
            <li>Master wajib: <b>Master Client</b> + <b>Master Exhibition</b> (kode unit, lantai, luas, tarif)</li>
            <li><b>Wajib lewat Surat Penawaran.</b> SKP baru hanya boleh lahir dari penawaran yang
                sudah ditandatangani client &mdash; status &ldquo;DEAL&rdquo; saja tidak cukup</li>
            <li>Input transaksi langsung dimatikan (mode <i>offer-first</i>); perpanjangan tetap boleh</li>
        </ul>
    </div>
    <div class="kartu">
        <div><span class="tag" style="background:#FEF3C7;color:#92400E">Modul 2</span></div>
        <h3>Gudang</h3>
        <p>Sewa gudang/storage. Dokumennya <b>SKS &mdash; Surat Konfirmasi Sewa Gudang</b>,
           bernomor <code>SKS/EW/2026/nnn</code>.</p>
        <div class="garis"></div>
        <ul>
            <li>Master wajib: <b>Master Client</b> + <b>Master Gudang</b> (kode, lokasi, luas, tarif per m&sup2;/bulan)</li>
            <li><b>Boleh tanpa penawaran.</b> Dokumen bisa dibuat langsung dari menu, isian diketik di
                formulirnya sendiri</li>
            <li>Boleh juga lewat penawaran, atau dibuatkan di atas transaksi yang sudah ada</li>
        </ul>
    </div>
    <div class="kartu">
        <div><span class="tag" style="background:#E0F2FE;color:#0369A1">Modul 3</span></div>
        <h3>Media</h3>
        <p>Sewa titik media &amp; iklan. Dokumennya <b>FU &mdash; Form Utilities</b>,
           bernomor <code>FU/EW/2026/nnn</code>.</p>
        <div class="garis"></div>
        <ul>
            <li>Master wajib: <b>Master Client</b> + <b>Master Media</b> (kode, jenis, titik, ukuran, slot, tarif)</li>
            <li><b>Boleh tanpa penawaran</b>, sama seperti Gudang</li>
            <li>Satu titik media bisa punya beberapa slot per hari &mdash; diatur di masternya</li>
        </ul>
    </div>
</div>

<div class="legenda">
    <span><i style="background:#0D9488"></i> Sales / PIC</span>
    <span><i style="background:#B45309"></i> <?= $h($tahap1['label']) ?><?= $h($orang1) ?></span>
    <span><i style="background:#6D28D9"></i> <?= $h($tahap2['label']) ?><?= $h($orang2) ?></span>
    <span><i style="background:#0369A1"></i> Client</span>
    <span><i style="background:#BE185D"></i> Legal</span>
    <span><i style="background:#475569"></i> Dikerjakan sistem sendiri</span>
    <span><i style="background:#C2410C"></i> Titik keputusan</span>
</div>
<?php $lembar[] = ob_get_clean();

/* ══ Lembar 2 — Exhibition ══════════════════════════════════════════════ */
ob_start(); ?>
<h1>Exhibition <small>&mdash; dari nol sampai masuk laporan</small></h1>
<div class="sub">Jalur baku untuk client baru maupun lama. Kotak bergaris putus-putus adalah
    jalan keluar bila jawabannya &ldquo;belum&rdquo;.</div>
<div class="alur">
<?= fase(1, 'Pastikan data siap', 'sales',
      tanya('Client sudah ada?', 'Cari di <b>Master Data &rarr; Client</b>.')
    . cabang('Belum ada &rarr; daftarkan', 'Master Data &rarr; Client &rarr; <b>Tambah Data</b>. Isi nama perusahaan,
        merek, alamat, NPWP, dan minimal satu kontak PJ.')
    . panahBawah()
    . tanya('Unit sudah ada?', 'Cari kodenya di <b>Master Exhibition</b>.')
    . cabang('Belum ada &rarr; daftarkan', 'Master Data &rarr; Master Exhibition &rarr; <b>Tambah Data</b>:
        kode, lantai, nama lokasi, tipe unit, luas m&sup2;, tarif per hari/m&sup2;.')
) ?>
<?= panahKanan() ?>
<?= fase(2, 'Surat Penawaran', 'sales',
      kotak('sales', 'Buat penawaran', 'Menu <b>Surat Penawaran &rarr; Tambah</b>. Pilih modul Exhibition,
        client, kontak PJ, unit, periode, harga, diskon, DP &amp; deposit.')
    . kotak('sistem', 'Nomor terbit otomatis', 'Nomor penawaran keluar <b>begitu disimpan</b>.
        Status awal <b>Draft</b>.')
    . kotak('sales', 'Periksa &amp; kirim', '<b>Lihat Contoh Surat</b> dulu untuk memastikan isinya,
        baru cetak PDF dan kirim ke client. Ubah status jadi <b>Terkirim</b>, lalu <b>Nego</b> bila ada tawar-menawar.')
) ?>
<?= panahKanan() ?>
<?= fase(3, 'Client menyetujui', 'client',
      kotak('client', 'Pilih satu cara', 'Dua-duanya sah dan hasilnya sama.')
    . kotak('client', 'A &middot; Tanda tangan online', 'Kirim tautan tanda tangan ke client (tersedia tombol WhatsApp).
        Client membuka tautan dan membubuhkan tanda tangan di layar.')
    . kotak('client', 'B &middot; Unggah surat berteken', 'Client menandatangani di atas kertas, hasil pindaiannya
        diunggah di halaman penawaran.')
    . kotak('hasil', '&rarr; Status DEAL', 'Nilainya <b>dikunci</b> &mdash; penawaran tidak bisa diubah lagi.')
    . cabang('Tidak jadi', '<b>Tutup penawaran</b> + isi alasannya. Alasan itu yang dipakai analisa kenapa deal lepas.')
) ?>
<?= panahKanan() ?>
<?= fase(4, 'Buat SKP', 'sales',
      kotak('sales', 'Dari halaman penawaran', 'Tekan <b>&rarr; Buat SKP (Konfirmasi)</b>.')
    . kotak('stop', 'Syaratnya satu', 'Penawaran <b>harus sudah ditandatangani client</b>. Kalau belum,
        tombolnya menolak dan menyuruh kirim tautan tanda tangan dulu.')
    . kotak('sales', 'Lengkapi isian', 'Penanggung jawab, KTP/NPWP/SIUP, status sewa, lampiran berkas,
        dan <b>pembagian PIC</b> bila deal ini dikerjakan lebih dari satu orang.')
    . kotak('sales', 'Ajukan', 'Status jadi <b>Menunggu Approval</b> dan dokumen masuk antrean pemeriksa.')
) ?>
<?= panahKanan() ?>
<?= fase(5, 'Persetujuan dua tahap', 'manager',
      kotak('asst', 'Tahap 1 &middot; ' . $h($tahap1['label']) . $h($orang1), 'Memeriksa isi lalu <b>memaraf</b>.
        Parafnya ikut tercetak di surat, tetapi <b>belum</b> menerbitkan nomor.')
    . panahBawah()
    . kotak('manager', 'Tahap 2 &middot; ' . $h($tahap2['label']) . $h($orang2), 'Menekan <b>Setujui</b> &mdash; tahap terakhir.')
    . kotak('hasil', '&rarr; Nomor SKP terbit', 'Bersamaan itu <b>transaksi lahir</b> dan isinya dibekukan,
        jadi cetakan ulang tidak pernah berubah.')
    . cabang('Kalau ada yang keliru', '<b>Kembalikan</b> satu tahap untuk diperiksa ulang, atau <b>Tolak ke PIC</b>
        supaya isinya diperbaiki dari awal. Lihat lembar terakhir.')
) ?>
<?= panahKanan() ?>
<?= fase(6, 'Tanda tangan &amp; income', 'client',
      kotak('sales', 'Kirim tautan ke client', 'Dari halaman SKP ada tombol WhatsApp berisi tautannya. <b>Berlaku 30 hari.</b>')
    . kotak('client', 'Client menandatangani', 'Online lewat tautan, atau unggah SKP berteken basah.')
    . kotak('hasil', '&rarr; Income dihitung', 'Alokasi bulanan terbentuk. Nilainya baru muncul di
        <b>Dashboard, Laporan PIC, dan Achievement</b> pada titik ini.')
    . kotak('legal', 'Lanjutan (opsional)', '<b>&rarr; Ajukan Kontrak ke Legal.</b> Tombolnya muncul setelah client meneken; berkas tidak bisa dikirim sebelum <b>bukti transfer</b> diunggah.')
) ?>
</div>
<?php $lembar[] = ob_get_clean();

/* ══ Lembar 3 — Gudang & Media ══════════════════════════════════════════ */
ob_start(); ?>
<h1>Gudang &amp; Media <small>&mdash; tiga pintu masuk, satu persetujuan</small></h1>
<div class="sub">Berbeda dari Exhibition, dokumen <b>SKS (Gudang)</b> dan <b>FU (Media)</b> tidak wajib lewat
    Surat Penawaran. Ada tiga cara memulainya; sesudah diajukan, ketiganya menempuh jalur yang sama persis.</div>
<div class="alur">
<?= fase(1, 'Pastikan data siap', 'sales',
      kotak('sales', 'Master Client', 'Client harus sudah terdaftar beserta kontak penanggung jawabnya.')
    . panahBawah()
    . kotak('sales', 'Master Gudang', 'Kode, lokasi, nama gudang, luas m&sup2;, tarif per m&sup2;/bulan.')
    . kotak('sales', 'Master Media', 'Kode, jenis media, titik, ukuran, jumlah slot per hari, tarif.')
    . cabang('Belum terdaftar?', 'Daftarkan dulu lewat <b>Master Data</b>. Tarif di master dipakai sebagai
        angka awal dan masih boleh diubah di dokumennya.')
) ?>
<?= panahKanan() ?>
<?= fase(2, 'Pilih pintu masuk', 'sales',
      kotak('sales', 'A &middot; Langsung buat dokumen', 'Menu <b>SKP Pameran</b> &rarr; tombol
        <b>SKS Gudang</b> atau <b>Form Utilities</b>. Seluruh isian diketik di formulir itu.
        <b>Cara yang paling sering dipakai.</b>')
    . kotak('sales', 'B &middot; Lewat Surat Penawaran', 'Buat penawaran dengan modul Gudang/Media,
        client menandatangani, lalu tekan <b>Buat SKS/Form</b> dari halaman penawaran.
        Dipakai bila client minta penawaran resmi dulu.')
    . kotak('sales', 'C &middot; Dari transaksi yang sudah ada', '<b>&uarr; Dari transaksi yang sudah ada</b> &rarr;
        pilih barisnya. Dipakai untuk transaksi lama yang dokumennya belum pernah dibuat.
        Satu transaksi hanya boleh punya satu dokumen.')
) ?>
<?= panahKanan() ?>
<?= fase(3, 'Isi &amp; ajukan', 'sales',
      kotak('sales', 'Lengkapi formulir', 'Client, kontak PJ, kode unit, periode, luas, tarif, biaya listrik,
        deposit, dan lampiran berkas.')
    . kotak('sales', 'Periksa dulu', '<b>Lihat Contoh Surat</b> memperlihatkan surat yang akan terbit
        &mdash; isinya mengikuti yang sedang diketik, bukan yang terakhir disimpan.')
    . kotak('sales', 'Ajukan', 'Status jadi <b>Menunggu Approval</b>.')
) ?>
<?= panahKanan() ?>
<?= fase(4, 'Persetujuan dua tahap', 'manager',
      kotak('asst', 'Tahap 1 &middot; ' . $h(($tahap['sks'][1]['label'] ?? $tahap1['label'])), 'Memeriksa lalu
        <b>memaraf</b>. Alur ini diatur per jenis dokumen di halaman <b>Alur Persetujuan</b>, jadi SKS dan FU
        boleh punya pemeriksa yang berbeda dari SKP.')
    . panahBawah()
    . kotak('manager', 'Tahap 2 &middot; ' . $h(($tahap['sks'][2]['label'] ?? $tahap2['label'])), 'Menekan <b>Setujui</b>.')
    . kotak('hasil', '&rarr; Nomor terbit', 'Nomor <b>SKS/</b> atau <b>FU/</b> keluar dan
        <b>transaksinya lahir di sini</b> &mdash; pada jalur A transaksi memang belum ada sebelumnya.')
) ?>
<?= panahKanan() ?>
<?= fase(5, 'Tanda tangan &amp; income', 'client',
      kotak('sales', 'Kirim tautan ke client', 'Berlaku <b>30 hari</b> sejak disetujui.')
    . kotak('client', 'Client menandatangani', 'Online lewat tautan, atau unggah dokumen berteken basah.')
    . kotak('hasil', '&rarr; Income dihitung', 'Alokasi bulanan terbentuk dan nilainya masuk laporan.')
    . kotak('legal', 'Lanjutan (opsional)', 'Ajukan Kontrak ke Legal, sama seperti Exhibition.')
    . cabang('Kontrak panjang', 'Sewa gudang berbulan-bulan dipecah per bulan secara otomatis
        (metode <i>spread</i>), jadi tiap bulan kebagian porsinya.')
) ?>
</div>
<?php $lembar[] = ob_get_clean();

/* ══ Lembar 4 — kasus ═══════════════════════════════════════════════════ */
ob_start(); ?>
<h1>Kasus yang sering ditemui</h1>
<div class="sub">Keadaan yang tidak masuk jalur baku, berikut langkah yang benar untuk masing-masing.</div>
<div class="kisi kisi-32">
    <div class="kartu">
        <div><span class="tag" style="background:#CCFBF1;color:#0F766E">Client</span></div>
        <h3>Client belum terdaftar</h3>
        <ul>
            <li><b>Master Data &rarr; Client &rarr; Tambah Data</b>: nama perusahaan, merek, alamat,
                NPWP, jenis usaha</li>
            <li>Tambahkan <b>minimal satu kontak PJ</b> &mdash; nama itulah yang tercetak di surat dan
                dipakai saat tanda tangan</li>
            <li>Sesudah itu client muncul di pilihan Surat Penawaran</li>
        </ul>
        <div class="garis"></div>
        <h3>Client lama, PJ-nya ganti</h3>
        <ul>
            <li>Jangan membuat client baru &mdash; riwayat dan analisanya akan terpecah</li>
            <li>Buka profil client, tambahkan kontak baru, lalu pilih kontak itu di penawaran</li>
        </ul>
    </div>
    <div class="kartu">
        <div><span class="tag" style="background:#DBEAFE;color:#1D4ED8">Perpanjangan</span></div>
        <h3>Kontrak hampir habis</h3>
        <ul>
            <li>Buka <b>Papan Renewal</b>: kontrak yang berakhir dalam <b>30 hari</b> ke depan atau baru
                lewat <b>7 hari</b>; tersisa &le;15 hari ditandai merah</li>
            <li>Catat perkembangannya di kolom status supaya tidak ada yang terlewat</li>
            <li>Tekan <b>+ Perpanjang</b> &rarr; terbuka <b>Surat Penawaran baru</b> berisi data kontrak lama</li>
        </ul>
        <div class="garis"></div>
        <h3>Perpanjangan cepat tanpa penawaran</h3>
        <ul>
            <li>Dari daftar <b>Transaksi</b>, tombol <b>Perpanjang</b> pada baris kontraknya</li>
            <li>Jalur ini membuat transaksi lebih dulu, lalu langsung mengarah ke pembuatan dokumen</li>
            <li>Di formulir SKP, Status Sewa otomatis terisi <b>Perpanjangan</b></li>
        </ul>
    </div>
    <div class="kartu">
        <div><span class="tag" style="background:#FEF3C7;color:#92400E">Bentuk kesepakatan</span></div>
        <h3>Satu client, beberapa unit (paket)</h3>
        <ul>
            <li>Di Surat Penawaran, nyalakan <b>paket</b> lalu tambahkan komponennya satu per satu</li>
            <li>Satu SKP memayungi seluruh komponen; saat disetujui, <b>lahir satu transaksi per komponen</b>
                yang terikat sebagai satu paket</li>
            <li>Jangan membuat beberapa penawaran terpisah untuk satu acara yang sama</li>
        </ul>
        <div class="garis"></div>
        <h3>Satu deal dikerjakan beberapa PIC</h3>
        <ul>
            <li><b>Jangan</b> membuat transaksi sendiri-sendiri per PIC &mdash; nilainya akan terhitung
                berkali-kali di laporan</li>
            <li>Buat <b>satu</b> dokumen, lalu isi <b>Pembagian PIC</b> di dalamnya: nilai kontrak dibagi
                sesuai porsi masing-masing</li>
            <li>Sejak 23 Oktober 2026 sistem menolak transaksi yang unit, client, dan periodenya sama persis
                dengan yang sudah ada</li>
        </ul>
    </div>
    <div class="kartu">
        <div><span class="tag" style="background:#FCE7F3;color:#BE185D">Uang</span></div>
        <h3>Yang diisi di bagian Harga</h3>
        <ul>
            <li><b>Total Kontrak</b> terhitung sendiri dari tarif &times; luas &times; lama sewa</li>
            <li><b>Harga Nego Final</b> diisi hanya bila hasil nego berbeda &mdash; angka inilah yang dipakai</li>
            <li><b>Harga / Bulan</b> boleh kosong. Kosong atau 0 &rarr; barisnya tidak dicetak</li>
            <li><b>Jadwal Harga Bertahap</b> bila tarifnya naik di tengah jalan</li>
            <li><b>Biaya Listrik</b>, <b>PPN 12%</b>, dan <b>Service Charge</b> bisa dimatikan satu per satu;
                yang dimatikan tidak muncul di surat</li>
            <li><b>DP</b> &amp; <b>Deposit</b> dalam bulan atau nominal, plus centang sudah dibayarkan</li>
        </ul>
    </div>
    <div class="kartu">
        <div><span class="tag" style="background:#EDE9FE;color:#6D28D9">Pengakuan</span></div>
        <h3>Kapan nilainya masuk bulan mana</h3>
        <ul>
            <li><b>Otomatis (ikut periode)</b> &mdash; bawaannya. Multi-bulan atau lintas bulan jadi
                <i>Spread</i>, selainnya <i>Sekaligus</i></li>
            <li><b>Sekaligus (anchor)</b> &mdash; seluruh nilainya diakui di satu bulan</li>
            <li><b>Spread per Bulan</b> &mdash; dipecah rata ke tiap bulan masa sewa, dipakai kontrak panjang</li>
            <li><b>Pengakuan per Siklus</b>: bulan awal atau bulan akhir siklus</li>
            <li><b>Prorata ikut bulan kalender</b> &mdash; centang bila kontrak tidak mulai tanggal 1.
                Bulan tak penuh dihitung per hari (11&ndash;30 Nov = 20/30 bulan). Tanpa centang siklusnya
                ikut tanggal mulai (11 &rarr; 10) dan totalnya bisa beda ratusan ribu</li>
        </ul>
    </div>
    <div class="kartu">
        <div><span class="tag" style="background:#FFEDD5;color:#C2410C">Bentrok</span></div>
        <h3>Unit sudah dipakai di periode itu</h3>
        <ul>
            <li>Saat tanggal diisi, formulir <b>langsung memberi tahu</b> bila unit itu sudah terpakai,
                lengkap dengan nama client dan PIC-nya</li>
            <li>Bertindih <b>sebagian</b> boleh jalan terus &mdash; unit bisa berganti penyewa di tengah bulan</li>
            <li>Bertindih <b>sama persis</b> ditolak sistem, dengan nomor transaksi yang sudah ada</li>
            <li>Memeriksa menyeluruh: <b>Transaksi &rarr; &#9888; Cek Overlap</b></li>
            <li><b>Satu transaksi, satu dokumen.</b> Yang sudah punya akan membuka dokumen itu</li>
        </ul>
    </div>
</div>
<?php $lembar[] = ob_get_clean();

/* ══ Lembar 5 — kalau ada yang salah ════════════════════════════════════ */
ob_start(); ?>
<h1>Kalau ada yang perlu dibetulkan</h1>
<div class="sub">Tindakan yang tersedia berbeda-beda menurut sejauh mana dokumennya sudah berjalan.
    Urutannya dari yang paling ringan.</div>
<div class="alur">
<?= fase(1, 'Masih draft', 'sales',
      kotak('sales', 'Belum diajukan', 'Dokumen berstatus <b>Draft</b> masih sepenuhnya milik PIC-nya.')
    . kotak('sales', 'Perbaiki langsung', 'Buka, ubah isinya, simpan. Tidak perlu izin siapa pun
        dan tidak ada jejak persetujuan yang terbuang.')
    . kotak('stop', 'Batalkan draft', 'Kalau memang tidak jadi, buang drafnya dari halaman dokumen.
        Nomor belum terbit, jadi tidak ada urutan yang bolong.')
) ?>
<?= panahKanan() ?>
<?= fase(2, 'Menunggu approval', 'asst',
      kotak('asst', 'Kembalikan satu tahap', 'Dipakai bila yang kurang cuma ketelitian tahap di bawahnya.
        Dokumen turun ke pemeriksa sebelumnya; <b>nilai dan transaksinya tidak diubah</b>.')
    . panahBawah('atau')
    . kotak('stop', 'Tolak ke PIC', 'Dipakai bila isinya memang salah. Status jadi <b>Ditolak</b> berapa pun
        tahapnya saat itu, dan hanya PIC yang bisa memperbaiki lalu mengajukan ulang dari awal.')
    . kotak('sistem', 'Yang ikut terjadi', 'Alokasi income <b>dilepas</b> supaya berhenti terhitung selama
        diperbaiki. Transaksinya sengaja tidak dihapus agar dokumennya masih bisa diperbaiki.')
) ?>
<?= panahKanan() ?>
<?= fase(3, 'Sudah disetujui, belum diteken', 'manager',
      kotak('sales', 'Ajukan permintaan revisi', 'Dari halaman dokumen, sertakan <b>alasan</b>.
        Hanya tersedia selama client belum menandatangani.')
    . panahBawah()
    . kotak('asst', 'Diputus ' . $h($tahap1['label']), 'Kalau disetujui, dokumen terbuka kembali untuk diperbaiki.')
    . kotak('sistem', 'Nomornya tetap sama', 'Revisi memakai <b>nomor yang sama</b>, hanya naik versi.
        Menerbitkan nomor baru akan membuat client memegang dua nomor untuk satu kesepakatan.')
    . cabang('Sesudah diperbaiki', 'Diajukan ulang dan menempuh paraf + persetujuan sekali lagi.
        Tautan tanda tangan yang baru terbit &mdash; kirim ulang ke client.')
) ?>
<?= panahKanan() ?>
<?= fase(4, 'Sudah diteken client', 'client',
      kotak('stop', 'Tidak bisa direvisi lagi', 'Dokumen bertanda tangan client terkunci. Kalau isinya
        memang berubah, <b>terbitkan dokumen baru</b>.')
    . kotak('stop', 'Batalkan kesepakatan', 'Tindakan tersendiri untuk kontrak yang benar-benar batal
        jalan. Berbeda dari &ldquo;tolak karena perlu revisi&rdquo;: ini mematikan transaksinya.')
    . cabang('Tautan kedaluwarsa', 'Lewat 30 hari client tidak bisa lagi membuka tautannya.
        Jalan keluarnya lewat permintaan revisi, yang menerbitkan tautan baru saat disetujui ulang.')
) ?>
<?= panahKanan() ?>
<?= fase(5, 'Menghapus data', 'manager',
      kotak('sales', 'Ajukan hapus', 'Menu <b>Pengajuan Hapus Data</b>. Sertakan alasan yang jelas &mdash;
        alasan itu yang dibaca pemutus.')
    . panahBawah()
    . kotak('manager', 'Diputus penyetuju', 'Status <b>Menunggu</b> &rarr; <b>Disetujui</b> atau ditolak.
        Siapa yang berhak memutus diatur di halaman yang sama.')
    . panahBawah()
    . kotak('sistem', 'Dieksekusi', 'Baris berpindah ke <b>Data Terhapus</b>, bukan hilang.
        Masih <b>bisa dipulihkan</b> bila ternyata keliru.')
    . kotak('sistem', 'Semuanya tercatat', 'Siapa mengajukan, siapa memutus, kapan, dan alasannya
        tersimpan di jejak audit.')
) ?>
</div>
<?php $lembar[] = ob_get_clean();

// ── Rakit berkas HTML ────────────────────────────────────────────────────────
$judul = 'Alur Kerja CLARA';
$html = "<!doctype html>\n<html lang=\"id\"><head><meta charset=\"utf-8\">\n"
      . "<title>$judul</title>\n<style>$CSS</style></head><body>\n";
foreach ($lembar as $i => $isi) {
    $html .= '<div class="lembar">'
        . '<div class="kop">'
        . ($kopKiri ? '<img src="' . $kopKiri . '" alt="">' : '')
        . '<div class="tengah">' . ($kopLogo ? '<img src="' . $kopLogo . '" alt="">' : '') . '</div>'
        . ($kopKanan ? '<img src="' . $kopKanan . '" alt="">' : '')
        . '</div>'
        . '<div class="isi">' . $isi . '</div>'
        . '<div class="nomor">' . ($i + 1) . ' / ' . count($lembar) . '</div>'
        . '<div class="kaki">'
        . ($kakiKiri ? '<img class="kr" src="' . $kakiKiri . '" alt="">' : '')
        . ($kakiKanan ? '<img class="kn" src="' . $kakiKanan . '" alt="">' : '')
        . '</div>'
        . "</div>\n";
}
$html .= "</body></html>\n";

$berkasHtml = $keluar . '/Alur_Kerja_CLARA.html';
file_put_contents($berkasHtml, $html);
printf("HTML  %s (%s KB)\n", $berkasHtml, number_format(filesize($berkasHtml) / 1024, 0));

// ── Cetak jadi PDF lewat Chrome bila ada ─────────────────────────────────────
// mPDF tidak dipakai di sini: bagannya bersandar pada flexbox, dan mPDF tidak
// mengenalnya — hasilnya akan menumpuk jadi satu kolom. Chrome memakai mesin
// yang sama dengan yang dipakai memeriksa tampilannya.
$chrome = null;
foreach ([
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    trim((string) @shell_exec('command -v google-chrome 2>/dev/null')),
    trim((string) @shell_exec('command -v chromium 2>/dev/null')),
] as $c) { if ($c && is_executable($c)) { $chrome = $c; break; } }

if (!$chrome) {
    echo "PDF   dilewati — Chrome tidak ditemukan. Berkas HTML-nya sudah bisa dibuka & dicetak sendiri.\n";
    exit(0);
}
$berkasPdf = $keluar . '/Alur_Kerja_CLARA.pdf';
$profil = sys_get_temp_dir() . '/clara-alur-' . getmypid();
$cmd = escapeshellarg($chrome) . ' --headless --disable-gpu --no-sandbox'
     . ' --user-data-dir=' . escapeshellarg($profil)
     . ' --no-pdf-header-footer --print-to-pdf-no-header'
     . ' --print-to-pdf=' . escapeshellarg($berkasPdf)
     . ' ' . escapeshellarg('file://' . $berkasHtml) . ' 2>&1';
@shell_exec($cmd);
@shell_exec('rm -rf ' . escapeshellarg($profil));
if (is_file($berkasPdf)) {
    printf("PDF   %s (%s KB)\n", $berkasPdf, number_format(filesize($berkasPdf) / 1024, 0));
} else {
    echo "PDF   gagal dibuat — pakai berkas HTML-nya.\n";
    exit(1);
}
