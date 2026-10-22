<?php
/**
 * Tutorial menutup satu bulan — dibaca sambil mengerjakan, bukan dipelajari.
 *
 * Bedanya dengan build_perbaikan_pic.php: berkas itu dokumen pemeriksaan —
 * lengkap, bertabel, dan memang berat. Yang ini kebalikannya: satu langkah per
 * bagian, kalimat pendek, dan di tiap langkah ada dua hal saja — apa yang
 * ditekan, dan angka berapa yang muncul sesudahnya. Diikuti sampai habis,
 * angka bulan itu cocok.
 *
 * Angka awalnya dibaca langsung dari database, jadi dokumen ini selalu mulai
 * dari keadaan hari ini. Isi langkahnya dari berkas banding, kunci "langkah":
 *
 *   "langkah": {"2026-10": [
 *     {"judul":"...", "hasil": 1500000, "apa":"...",
 *      "isi":["...","..."], "selesai":"...", "awas":"..."}]}
 *
 * hasil = perubahan angkanya (0 kalau langkah itu tidak mengubah apa pun).
 * Penanda *tebal* dipakai untuk nama menu dan tombol.
 *
 * Pakai:
 *   php scripts/build_tutorial_bulan.php --pic="Shinta" --prop=2 \
 *       --banding=berkas.json --bulan=2026-10 --keluar=Tutorial.pdf
 */

/**
 * Susun langkah tutorial dari hasil scripts/cek_semua_pic.php.
 *
 * Tiap golongan selisih punya tindakan yang berbeda, dan perbedaan itulah yang
 * paling sering salah dikerjakan orang: yang nilai kontraknya sudah benar
 * TIDAK boleh diubah nilainya — cukup bulan pengakuannya; yang belum ada harus
 * dibuat lewat menu yang sesuai modulnya; dan yang masa sewanya menyeberang ke
 * bulan berikutnya tidak boleh disentuh sama sekali.
 *
 * Mengembalikan [langkah, sisaWajar, targetSpreadsheet].
 */
function langkah_dari_sandingan(string $berkas, string $picXlsx, string $bln): array
{
    $h = json_decode((string) @file_get_contents($berkas), true);
    if (!is_array($h)) return [[], [], null];
    $d = null;
    foreach ($h as $nama => $isi) {
        if (mb_strtolower((string) $nama) === mb_strtolower($picXlsx)
            || mb_strtolower((string) ($isi['pic_clara'] ?? '')) === mb_strtolower($picXlsx)) { $d = $isi; break; }
    }
    if ($d === null) return [[], [], null];

    $fmt   = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $modul = ['cl' => 'Exhibition', 'gudang' => 'Gudang', 'media' => 'Media'];
    $L = [];

    // 1. Nilai kontraknya sudah benar, hanya bulan pengakuannya yang terbagi.
    foreach ($d['beda'] ?? [] as $b) {
        if (($b['sebab'] ?? '') !== 'pengakuan') continue;
        $L[] = [
            'judul' => 'Betulkan bulan pengakuan ' . $b['nama'],
            'hasil' => (float) $b['selisih'],
            'apa'   => 'Nilai kontraknya *sudah benar* (' . $fmt($b['nilai_kontrak']) . '). Yang salah cuma pembagiannya: '
                     . 'CLARA menyebarnya ke beberapa bulan sehingga ' . $bln . ' hanya kebagian ' . $fmt($b['nilai_clara'])
                     . ', sedangkan spreadsheet membukukan utuh di bulan mulainya.',
            'isi'   => [
                'Buka menu *Input → ' . ($modul[$b['modul']] ?? $b['modul']) . '*.',
                'Cari *' . $b['nama'] . '* (kode unit *' . $b['kode'] . '*, transaksi *#' . $b['trx'] . '*), tekan *Edit*.',
                'Di dropdown *Nilai Diakui di Bulan*, ganti dari *Spread per Bulan (Recurring)* jadi *Bulan Awal (bulan mulai)*.',
                '*Jangan ubah nilainya.* Nilai kontraknya sudah benar.',
                'Tekan *Simpan*.'],
            'awas'  => 'Setelah diubah, bulan-bulan berikutnya tidak lagi kebagian nilai kontrak ini — memang begitu seharusnya kalau spreadsheet membukukannya utuh di bulan mulai.',
            'selesai' => 'Di *Analisa → Laporan PIC* bulan ini, *' . $b['nama'] . '* bernilai ' . $fmt($b['nilai_ss']) . '.',
        ];
    }

    // 2. Belum ada di CLARA — jalurnya berbeda per modul.
    foreach ($d['kurang'] ?? [] as $k) {
        $mod  = $k['modul'] ?? null;
        $kode = $k['kode'] ?? null;
        $unit = $kode ? ('*' . $kode . '*' . (empty($k['unit_pasti']) ? ' (dugaan — pastikan dulu di *Master Data*)' : '')) : 'unit sesuai spreadsheet';
        if ($mod === 'cl' || $mod === null) {
            $isi = [
                'Buka menu *Input → Surat Penawaran*, tekan *+ Buat Penawaran Exhibition*.',
                'Isi client dan unit ' . $unit . ', PIC *' . ($d['pic_clara'] ?? '') . '*, dan periodenya sesuai kontrak.',
                'Isi harganya sampai *Grand Total* di kotak Ringkasan Biaya tertulis tepat ' . $fmt($k['nilai']) . '.',
                'Simpan, buka *Preview*, tekan *Tandai DEAL*, lalu buat SKP-nya.',
                'Alurnya: paraf Asst. Manager → disetujui Manager → dikirim ke klien untuk tanda tangan.'];
            $awas = 'Income baru terhitung *setelah klien menandatangani*. Sebelum itu barisnya sudah kelihatan tetapi nilainya Rp 0 — itu bukan error.';
        } else {
            $isi = [
                'Buka menu *Input → SKP Pameran*, tekan *+ Buat Dokumen*.',
                'Pilih *' . ($mod === 'gudang' ? 'SKS Gudang' : 'Form Utilities') . '*.',
                'Isi client dan unit ' . $unit . ', PIC *' . ($d['pic_clara'] ?? '') . '*, periodenya sesuai kontrak.',
                'Isi nilainya ' . $fmt($k['nilai']) . ', lalu Simpan.',
                'Ajukan sampai disetujui Manager, lalu kirim ke klien untuk tanda tangan.'];
            $awas = 'Gudang dan Media tidak lewat Surat Penawaran. Income tetap baru terhitung setelah klien menandatangani.';
        }
        $L[] = [
            'judul' => 'Input ' . mb_substr((string) $k['ket'], 0, 48),
            'hasil' => (float) $k['nilai'],
            'apa'   => 'Baris ini ada di spreadsheet ' . $bln . ' tetapi belum ada di CLARA.',
            'isi'   => $isi, 'awas' => $awas,
            'selesai' => 'Di *Analisa → Laporan PIC* bulan ini muncul baris senilai ' . $fmt($k['nilai']) . '.',
        ];
    }

    // 3. Nilai kontraknya sendiri berbeda — harus dipastikan dulu, bukan
    //    langsung diubah. Yang benar bisa CLARA, bisa spreadsheet.
    foreach ($d['beda'] ?? [] as $b) {
        if (($b['sebab'] ?? '') === 'pengakuan') continue;
        $L[] = [
            'judul' => 'Pastikan nilai ' . $b['nama'],
            'hasil' => (float) $b['selisih'],
            'apa'   => 'Spreadsheet menulis ' . $fmt($b['nilai_ss']) . ', CLARA ' . $fmt($b['nilai_clara'])
                     . '. Nilai kontrak di CLARA memang ' . $fmt($b['nilai_kontrak']) . ' — jadi bukan soal pembagian bulan, angkanya yang berbeda.',
            'isi'   => [
                'Buka surat penawaran / SKP *' . $b['nama'] . '* dan lihat nilai yang disepakati.',
                'Kalau *spreadsheet yang benar*: buka *Input → ' . ($modul[$b['modul']] ?? $b['modul'])
                    . '*, cari *#' . $b['trx'] . '* (' . $b['kode'] . '), tekan *Edit*, betulkan nilainya jadi ' . $fmt($b['nilai_ss']) . ', Simpan.',
                'Kalau *CLARA yang benar*: jangan ubah apa pun di CLARA — selnya di spreadsheet yang perlu dibetulkan.'],
            'awas'  => 'Jangan diubah sebelum melihat dokumennya. Selisihnya ' . $fmt(abs((float) $b['selisih']))
                     . ', dan menyamakan angka ke dokumen yang salah membuat laporan ikut salah.',
            'selesai' => 'Nilai di CLARA dan di spreadsheet sama, dan keduanya sama dengan dokumen kontraknya.',
        ];
    }

    // 4. Ada di CLARA, tidak ada di spreadsheet — kecuali yang memang menyeberang bulan.
    $sisa = [];
    foreach ($d['lebih'] ?? [] as $c) {
        if (($c['ada_bulan_depan'] ?? null) === true) { $sisa[] = $c; continue; }
        $L[] = [
            'judul' => 'Pastikan ' . $c['nama'] . ' (' . $c['kode'] . ')',
            'hasil' => -(float) $c['nilai'],
            'apa'   => 'Ada di CLARA (' . $fmt($c['nilai']) . ', ' . $c['mulai'] . ' s/d ' . $c['selesai']
                     . ') tetapi tidak ada di spreadsheet ' . $bln . ($c['lintas_bulan'] ? ', dan di bulan berikutnya pun tidak ada.' : '.'),
            'isi'   => [
                'Pastikan dulu: apakah kontrak ini memang ada?',
                'Kalau *tidak ada*: buka *Input → Pengajuan Hapus Data*, pilih transaksi *#' . $c['trx'] . '*, tulis alasannya, ajukan.',
                'Kalau *ada*: jangan hapus apa pun — selnya di spreadsheet yang perlu diisi.'],
            'awas'  => 'Jangan dihapus sebelum yakin. Kalau ternyata kontraknya benar ada, angka akhirmu lebih tinggi '
                     . $fmt($c['nilai']) . ' dari spreadsheet, dan itu yang benar.',
            'selesai' => 'Baris *' . $c['nama'] . '* sudah tidak muncul lagi di Laporan PIC bulan ini.',
        ];
    }
    return [$L, $sisa, (float) ($d['spreadsheet'] ?? 0)];
}

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/pdf.php';

$opt = array_merge(['pic' => '', 'prop' => '2', 'banding' => '', 'bulan' => '', 'keluar' => '',
                    'hasil' => '', 'pic-xlsx' => ''],
    getopt('', ['pic:', 'prop:', 'banding:', 'bulan:', 'keluar:', 'hasil:', 'pic-xlsx:']));
if ($opt['pic'] === '' || $opt['bulan'] === '' || ($opt['banding'] === '' && $opt['hasil'] === '')) {
    fwrite(STDERR, "Pakai: --pic=\"Nama\" --bulan=2026-10 (--banding=berkas.json | --hasil=sandingan.json --pic-xlsx=NAMA) [--prop=2] [--keluar=x.pdf]\n");
    exit(2);
}
$pic = (string) $opt['pic'];
$pid = (int) $opt['prop'];
$bln = (string) $opt['bulan'];
$ss = $opt['banding'] !== ''
    ? json_decode((string) file_get_contents((string) $opt['banding']), true)
    : [];
if (!is_array($ss)) { fwrite(STDERR, "Berkas banding tidak terbaca.\n"); exit(2); }

$langkah = $ss['langkah'][$bln] ?? [];
$sisaWajar = [];
$targetSS  = null;
if (!$langkah && $opt['hasil'] !== '') {
    [$langkah, $sisaWajar, $targetSS] = langkah_dari_sandingan(
        (string) $opt['hasil'], (string) ($opt['pic-xlsx'] ?: $pic), $bln);
}
if (!$langkah) {
    fwrite(STDERR, "Tidak ada langkah untuk $bln — tidak ada yang perlu dikerjakan, atau berkasnya salah.\n");
    exit(1);
}

if ($opt['keluar'] === '') {
    $opt['keluar'] = $root . '/docs/Tutorial_' . str_replace('-', '_', $bln) . '_'
        . preg_replace('/[^A-Za-z0-9]+/', '_', $pic) . '.pdf';
}

date_default_timezone_set((string) (env_value('APP_TIMEZONE') ?: 'Asia/Makassar'));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tb = fn($s) => preg_replace('/\*(.+?)\*/u', '<b>$1</b>', htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'));
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$q = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM transaction_allocations
                     WHERE property_id = ? AND pic_name = ? AND period_key = ?');
$q->execute([$pid, $pic, $bln]);
$awal = (float) $q->fetchColumn();

$namaBulan = ['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
              '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
              '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'];
$blnTeks = ($namaBulan[substr($bln, 5, 2)] ?? $bln) . ' ' . substr($bln, 0, 4);

// Angka berjalan: tiap langkah tahu angka sebelum dan sesudahnya, sehingga
// orang bisa berhenti di tengah dan tetap tahu posisinya.
$jalan = $awal; $titik = [];
foreach ($langkah as $l) {
    $sebelum = $jalan;
    $jalan  += (float) ($l['hasil'] ?? 0);
    $titik[] = ['sebelum' => $sebelum, 'sesudah' => $jalan];
}
$akhir = $jalan;

ob_start(); ?>
<style>
    body { font-family: sans-serif; font-size: 11.5pt; line-height: 1.6; color: #1e293b; }
    h1 { font-size: 21pt; margin: 0 0 2px; color: #0D9488; }
    h2 { font-size: 15pt; margin: 0 0 3px; color: #0D9488; }
    .sub { color: #64748b; font-size: 10pt; margin-bottom: 16px; }
    .angka { border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px 14px; margin: 12px 0; background: #f8fafc; }
    .angka b { font-size: 15pt; }
    .daftar { margin: 14px 0; width: 100%; border-collapse: collapse; }
    .daftar td { border-bottom: 1px solid #e2e8f0; padding: 8px 0; vertical-align: top; }

    .lang { border-left: 4px solid #0D9488; padding: 2px 0 2px 13px; margin-bottom: 10px; }
    .hasil { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 9px 13px; margin: 11px 0; }
    .awas  { background: #fffbeb; border: 1px solid #fcd34d; padding: 9px 13px; margin: 11px 0; }
    .tidak { background: #fef2f2; border: 1px solid #fecaca; padding: 9px 13px; margin: 11px 0; }
    ol { margin: 6px 0 0 20px; padding: 0; } li { margin-bottom: 7px; }
    .muted { color: #64748b; } .hijau { color: #047857; } .merah { color: #b91c1c; }
    table { width: 100%; border-collapse: collapse; font-size: 11pt; }
    td { padding: 5px 0; }
    td.n { text-align: right; white-space: nowrap; }
</style>

<h1>Cara Menutup <?= $h($blnTeks) ?></h1>
<div class="sub"><b><?= $h($pic) ?></b> &middot; dibuat <?= date('d/m/Y H:i') ?> &middot;
    ikuti dari atas ke bawah, satu per satu.</div>

<div class="angka">
<table>
    <tr><td>Angka kamu sekarang di CLARA</td><td class="n"><b><?= $rp($awal) ?></b></td></tr>
    <tr><td class="hijau">Sesudah semua langkah selesai</td><td class="n hijau"><b><?= $rp($akhir) ?></b></td></tr>
</table>
</div>
<?php if ($sisaWajar && $targetSS !== null): ?>
<p class="muted" style="margin:-6px 0 10px">Angka itu <b>lebih besar</b> dari spreadsheet
(<?= $rp($targetSS) ?>), dan memang seharusnya begitu &mdash; penjelasannya di halaman terakhir.</p>
<?php endif; ?>
<p style="margin:0">Ada <b><?= count($langkah) ?> langkah</b>. Kerjakan berurutan.
Setiap langkah menyebutkan angka yang harus muncul sesudahnya &mdash; kalau tidak cocok,
berhenti di situ dan tanyakan, jangan lanjut.</p>

<table class="daftar">
<?php foreach ($langkah as $i => $l): ?>
    <tr>
        <td style="padding-left:4px"><b><?= $i + 1 ?>.</b> <?= $tb($l['judul'] ?? '') ?>
            <span class="muted">&mdash; jadi <?= $rp($titik[$i]['sesudah']) ?></span></td>
    </tr>
<?php endforeach; ?>
</table>

<?php foreach ($langkah as $i => $l):
    $ubah = (float) ($l['hasil'] ?? 0); ?>
<pagebreak>
<h2>Langkah <?= $i + 1 ?> &mdash; <?= $tb($l['judul'] ?? '') ?></h2>
<div class="sub" style="margin-bottom:10px"><?= $tb($l['apa'] ?? '') ?></div>

<div class="lang">
<ol>
<?php foreach (($l['isi'] ?? []) as $x): ?>
    <li><?= $tb($x) ?></li>
<?php endforeach; ?>
</ol>
</div>

<?php if (!empty($l['awas'])): ?>
<div class="<?= $ubah == 0.0 ? 'tidak' : 'awas' ?>"><?= $tb($l['awas']) ?></div>
<?php endif; ?>

<div class="hasil">
<table>
    <tr><td>Angka sebelum langkah ini</td><td class="n"><?= $rp($titik[$i]['sebelum']) ?></td></tr>
    <?php if ($ubah != 0.0): ?>
    <tr><td>Perubahan dari langkah ini</td>
        <td class="n <?= $ubah < 0 ? 'merah' : 'hijau' ?>"><?= ($ubah < 0 ? '&minus;' : '+')
            . number_format(abs($ubah), 0, ',', '.') ?></td></tr>
    <?php else: ?>
    <tr><td colspan="2" class="muted">Langkah ini tidak mengubah angka &mdash; gunanya supaya
        kamu tidak menambah sesuatu yang salah.</td></tr>
    <?php endif; ?>
    <tr><td><b>Angka sesudah langkah ini</b></td>
        <td class="n"><b><?= $rp($titik[$i]['sesudah']) ?></b></td></tr>
</table>
<?php if (!empty($l['selesai'])): ?>
<div style="margin-top:7px"><b>Selesai kalau:</b> <?= $tb($l['selesai']) ?></div>
<?php endif; ?>
</div>
<?php endforeach; ?>

<pagebreak>
<h2>Sudah selesai?</h2>
<p>Buka <b>Analisa &rarr; Laporan PIC</b>, pilih <b><?= $h($blnTeks) ?></b>, lihat bagian namamu.</p>
<div class="angka">
<table>
    <tr><td>Yang harus tertulis di situ</td><td class="n"><b><?= $rp($akhir) ?></b></td></tr>
    <?php if ($targetSS !== null): ?>
    <tr><td class="muted">Spreadsheet <?= $h($blnTeks) ?></td><td class="n muted"><?= $rp($targetSS) ?></td></tr>
    <?php endif; ?>
</table>
</div>
<?php if ($sisaWajar):
    $jmlSisa = array_sum(array_map(fn($c) => (float) $c['nilai'], $sisaWajar)); ?>
<div class="awas">
<p style="margin:0 0 5px"><b>Angkamu akan lebih besar <?= $rp($jmlSisa) ?> dari spreadsheet &mdash; dan itu benar.</b></p>
<p style="margin:0 0 5px">Kontrak berikut masa sewanya menyeberang ke bulan depan. CLARA membukukan seluruh
nilainya di bulan <b>mulai</b>, sedangkan spreadsheet menaruhnya di bulan berikutnya. Nilainya tidak hilang
dan tidak dobel &mdash; hanya beda bulan. <b>Jangan diapa-apakan.</b></p>
<ul style="margin:0 0 0 16px">
<?php foreach ($sisaWajar as $c): ?>
    <li><b><?= $h($c['nama']) ?></b> &middot; <?= $h($c['kode']) ?> &middot; <?= $rp($c['nilai']) ?>
        <span class="muted">(<?= $h($c['mulai']) ?> s/d <?= $h($c['selesai']) ?>)</span></li>
<?php endforeach; ?>
</ul>
</div>
<?php else: ?>
<p>Kalau angkanya sudah sama, <?= $h($blnTeks) ?> selesai dan tidak ada lagi yang perlu diinput.</p>
<?php endif; ?>

<h2 style="margin-top:18px">Kalau angkanya belum sama</h2>
<ol>
    <li>Cek langkah mana yang angkanya tidak cocok &mdash; kerjakan ulang yang itu saja.</li>
    <li>Kalau sebuah kontrak sudah kamu input tapi nilainya Rp 0 di laporan: klien belum
        menandatangani SKP-nya. Cek di <b>Input &rarr; SKP Pameran</b>, tab <b>Perlu TTD</b>.
        Itu bukan error &mdash; nilainya masuk begitu diteken.</li>
    <li>Kalau sewanya menyeberang bulan (misalnya 26 Okt&ndash;25 Nov), CLARA membukukan
        seluruhnya di bulan <b>mulai</b>. Kalau spreadsheet menaruhnya di bulan berikutnya,
        selisihnya di situ &mdash; bukan income yang hilang.</li>
    <li>Masih belum cocok juga? Minta dokumen ini dicetak ulang &mdash; angkanya ikut data terbaru.</li>
</ol>
<?php
$html = (string) ob_get_clean();
$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Cara Menutup ' . $blnTeks . ' — ' . $pic);
$mpdf->WriteHTML($html);
@mkdir(dirname($opt['keluar']), 0777, true);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);
echo "  Dokumen dibuat: {$opt['keluar']}\n";
