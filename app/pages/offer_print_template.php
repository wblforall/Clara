<?php
/** Template cetak Surat Penawaran. Vars: $o (offer+join), $prop, $rp, $h, $letter. */
if (!isset($o)) { http_response_code(400); exit('Konteks tidak valid.'); }
$letter = $letter ?? [];
$letter += function_exists('offer_letter_bawaan') ? offer_letter_bawaan() : ['perihal' => '', 'intro' => '', 'fasilitas' => [], 'payment' => [], 'terms' => []];
$OFFICE_PHONE = '0542-8520555';   // nomor kantor (satu untuk semua properti)
// Isi surat yang bisa disetel per template. Semua punya nilai bawaan yang sama
// persis dengan yang dulu dipaku di berkas ini, jadi surat lama tak berubah.
$JUDUL   = ($letter['judul'] ?? []) + (function_exists('offer_judul_bawaan') ? offer_judul_bawaan() : []);
$BANK    = ($letter['bank'] ?? []) + (function_exists('offer_bank_bawaan') ? offer_bank_bawaan() : []);
$LAYOUT  = ($letter['layout'] ?? 'tabel') === 'rincian' ? 'rincian' : 'tabel';
$PPN_PERSEN  = (float) ($letter['ppn_persen'] ?? 12);
$PPN_RUMUS   = !empty($letter['ppn_rumus']);
$PPN_CATATAN = trim((string) ($letter['ppn_catatan'] ?? ''));
$PAKAI_RINCIAN_BIAYA = !isset($letter['rincian_biaya']) || !empty($letter['rincian_biaya']);
// Penulisan rupiah ikut template. Bawaannya 'polos' — persis seperti sebelumnya,
// jadi surat yang sudah terbit tidak berubah angkanya maupun bentuknya.
$GAYA_UANG = ($letter['gaya_uang'] ?? 'polos') === 'kertas' ? 'kertas' : 'polos';
if (function_exists('offer_rupiah')) {
    $rp = fn($v) => offer_rupiah((float) $v, $GAYA_UANG);
}
// Surat kertas memakai bullet untuk Cara Pembayaran & Ketentuan; aplikasi selama
// ini menomorinya. Keduanya disediakan — bawaannya tetap bernomor.
$TAG_LIST = ($letter['gaya_daftar'] ?? 'nomor') === 'bullet' ? 'ul' : 'ol';
// Judul bagian: tampilan CLARA (hijau, tanpa nomor) atau gaya surat kertas
// (hitam tebal bernomor I, II, III). Bawaannya tampilan CLARA.
$GAYA_JUDUL = ($letter['gaya_judul'] ?? 'aplikasi') === 'romawi' ? 'romawi' : 'aplikasi';
$GAYA_BANK  = ($letter['gaya_bank'] ?? 'kotak') === 'menyatu' ? 'menyatu' : 'kotak';
$_romawiN = 0;
$ROMAWI = function (int $n): string {
    $peta = [10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
    $out = '';
    foreach ($peta as $nilai => $huruf) { while ($n >= $nilai) { $out .= $huruf; $n -= $nilai; } }
    return $out;
};
/** Satu judul bagian, menurut gaya yang dipilih template. */
$JUDUL_BAGIAN = function (string $teks) use (&$_romawiN, $GAYA_JUDUL, $ROMAWI, $h): string {
    if ($GAYA_JUDUL !== 'romawi') return '<div class="sec">' . $h($teks) . '</div>';
    $_romawiN++;
    // Sengaja DIV biasa, bukan tabel: tabel membuat mPDF memutus halaman tepat
    // sesudah judul, sehingga sisa halamannya kosong melompong.
    return '<div class="secrom">' . $ROMAWI($_romawiN) . '.&nbsp;&nbsp;&nbsp;&nbsp;' . $h($teks) . '</div>';
};
// Tarif efektif. 12% dengan rumus PMK 131/2024 = 11% polos — angkanya memang
// sama; yang berbeda cuma kalimat di surat.
$PPN_TARIF = $PPN_RUMUS ? ($PPN_PERSEN / 100) * 11 / 12 : ($PPN_PERSEN / 100);
$PPN_LABEL = 'PPN ' . rtrim(rtrim(number_format($PPN_PERSEN, 2, ',', '.'), '0'), ',') . '%';
$PPN_HINT  = $PPN_RUMUS ? ' <span class="muted" style="font-weight:400">(Nilai × 11/12 × ' . $PPN_PERSEN . '%)</span>' : '';
$propShort = ($prop['key'] ?? '') === 'pentacity' ? 'Pentacity' : 'e-Walk';
$months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$od = $o['offer_date'] ? strtotime($o['offer_date']) : time();
$tanggal = (int) date('d', $od) . ' ' . $months[(int) date('n', $od)] . ' ' . date('Y', $od);
$periode = $o['start_date'] ? (date('d/m/Y', strtotime($o['start_date'])) . ' s/d ' . date('d/m/Y', strtotime($o['end_date']))) : '-';
// Durasi nyata: pakai "hari" bila < 28 hari, selain itu "bulan" (+ hari).
$contractMonths = (int) ($o['contract_months'] ?: 1);
$days = ($o['start_date'] && $o['end_date']) ? ((int) floor((strtotime($o['end_date']) - strtotime($o['start_date'])) / 86400) + 1) : 0;
// Masa sewa ditulis dalam HARI saja — hitungan bulan sengaja tidak ditampilkan
// supaya tidak rancu (mis. 36 hari sempat terbaca "2 bulan").
$durasi = $days > 0 ? ($days . ' hari') : ($contractMonths . ' bulan');
// Perihal dari template per jenis booth (fallback general bila kosong).
$perihal = $letter['perihal'] ?: ('Surat Penawaran Sewa Area Pameran' . ($days > 0 ? ' ' . $days . ' Hari' : ''));
// Rincian biaya
$total    = (float) $o['total_calculated'];
// Biaya listrik (Exhibition): tarif per bulan × jumlah bulan, kena PPN 12% juga.
// Nol untuk penawaran yang tidak mencentangnya — barisnya pun tidak dicetak.
$listrikBln = (float) ($o['electricity_monthly'] ?? 0);
$listrik    = function_exists('offer_listrik') ? offer_listrik($o) : 0.0;
// Bulan listrik dihitung dari lama hari sewa, bukan dari contract_months.
$listrikN   = function_exists('offer_listrik_bulan') ? offer_listrik_bulan($o) : 1;
$dasarPpn = $total + $listrik;
// PPN dihitung per komponen supaya bisa dirinci di surat; jumlahnya dipakai
// sebagai PPN total agar penjumlahan di kertas selalu pas.
// Penyewa berharga bersih: PPN tidak dikenakan & barisnya tidak dicetak.
$kenaPpn    = !isset($o['ppn_flag']) || !empty($o['ppn_flag']);
$ppnSewa    = $kenaPpn ? round($total * $PPN_TARIF) : 0.0;
$ppnListrik = $kenaPpn ? round($listrik * $PPN_TARIF) : 0.0;
$ppn        = $ppnSewa + $ppnListrik;
$afterPpn   = $dasarPpn + $ppn;
// Service Charge ditagih per bulan; PPN-nya dihitung per bulan lalu dikalikan
// jumlah bulan sewa, supaya angka di surat bisa dijumlah ulang persis.
// Jadwal harga bertahap, bila kontrak ini memakainya.
$tahapHarga = $tahapHarga ?? [];
$scBulanan = !empty($o['sc_flag']) ? (float) ($o['sc_monthly'] ?? 0) : 0.0;
$scBulan   = 1;
if ($o['start_date'] && $o['end_date']) {
    $ma = new DateTimeImmutable($o['start_date']);
    $mb = new DateTimeImmutable($o['end_date']);
    $scBulan = (((int) $mb->format('Y') - (int) $ma->format('Y')) * 12)
             + ((int) $mb->format('n') - (int) $ma->format('n'))
             + ((int) $mb->format('j') >= (int) $ma->format('j') ? 1 : 0);
    $scBulan = max(1, $scBulan);
}
$scPpnBulan = $kenaPpn ? round($scBulanan * $PPN_TARIF) : 0.0;
$scPerBulan = $scBulanan + $scPpnBulan;
$scTotal    = $scBulanan > 0 ? $scPerBulan * $scBulan : 0.0;
$deposit  = (float) $o['deposit_amount'];
// Deposit yang sudah disetor di kontrak sebelumnya tetap dicantumkan sebagai
// catatan, tapi tidak ditagih ulang sehingga di luar Grand Total.
$depLunas = !empty($o['deposit_paid']);
$grand    = $afterPpn + $scTotal + ($depLunas ? 0 : $deposit);
$dpBulan  = rtrim(rtrim(number_format((float) $o['dp_months'], 1, ',', ''), '0'), ',');
$depBulan = rtrim(rtrim(number_format((float) $o['deposit_months'], 1, ',', ''), '0'), ',');
// Masa berlaku penawaran: 7 hari sejak tanggal penawaran
$validTs  = strtotime(($o['offer_date'] ?: date('Y-m-d')) . ' +7 days');
$berlaku  = (int) date('d', $validTs) . ' ' . $months[(int) date('n', $validTs)] . ' ' . date('Y', $validTs);
// Kontak pembayaran: fallback ke kantor bila PIC kosong
// Dirapikan jadi 08xx-xxxx-xxxx seperti di surat kertas; kalau PIC belum punya
// nomor, dipakai nomor kantor.
$payWa    = function_exists('offer_telp_rapi') && $o['pic_phone']
          ? offer_telp_rapi((string) $o['pic_phone'])
          : ($o['pic_phone'] ?: $OFFICE_PHONE);
// Daftar yang SENGAJA dikosongkan di template harus tetap kosong. Dulu kosong
// berarti "pakai daftar bawaan dari kode", sehingga surat Foodcourt — yang
// memang tidak punya bagian Ketentuan — tetap mencetak 14 butir ketentuan
// pameran yang tidak ada di kertasnya. Daftar bawaan kini hanya dipakai untuk
// surat lama yang memang belum pernah menyimpan kuncinya.
$ketentuan = array_key_exists('terms', $letter) ? ($letter['terms'] ?? []) : offer_terms();
?>
<?php $PDF_MODE = !empty($PDF_MODE); /* diset oleh offer_print() utk jalur mPDF */ ?>
<?php if (!$PDF_MODE): ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($o['offer_no']) ?> — Surat Penawaran</title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<?php endif; ?>
<style>
<?php if (!$PDF_MODE): ?>
/* ── Mode LAYAR/print-browser: kop via thead/tfoot (tetap utk pratinjau & fallback). ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:Helvetica, Arial, sans-serif;font-size:11px;color:#111;background:#fff}
@page{size:A4 portrait;margin:0}
table.paper{width:100%;border-collapse:collapse}
table.paper>thead>tr>td,table.paper>tfoot>tr>td,table.paper>tbody>tr>td{padding:0}
.sp-top{height:30mm;background:url('assets/letterhead-a4.jpg') no-repeat top center;background-size:100% auto}
.sp-bot{height:36mm;background:url('assets/letterhead-a4.jpg') no-repeat bottom center;background-size:100% auto}
.sheet{padding:0 16mm}
.no-print{position:fixed;top:14px;right:14px;display:flex;gap:8px;z-index:9}
.no-print button{padding:9px 18px;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer}
.btn-print{background:#0D9488;color:#fff}.btn-close{background:#e5e7eb;color:#374151}
@media screen{
  body{background:#f3f4f6}
  table.paper{width:210mm;margin:16px auto;box-shadow:0 4px 24px rgba(0,0,0,.12);background:#fff}
}
@media print{.no-print{display:none}}
@media screen and (max-width:820px){
  body{overflow-x:hidden}
  table.paper{margin:0 !important;transform-origin:top left}
}
.pdf-hint{display:none}
@media screen and (max-width:820px){
  .pdf-hint{display:block;position:fixed;left:10px;right:10px;bottom:12px;z-index:9;
    background:#fffbeb;border:1px solid #fcd34d;color:#92400e;font-size:12px;line-height:1.45;
    padding:9px 13px;border-radius:10px;text-align:center;box-shadow:0 4px 16px rgba(0,0,0,.12)}
}
@media print{.pdf-hint{display:none}}
@media print{ table.paper{transform:none !important} body{height:auto !important} }
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
<?php else: ?>
/* ── Mode PDF (mPDF): kop dipasang via SetHTMLHeader/Footer; di sini CSS KONTEN saja
   (TANPA @page agar tak menimpa margin mPDF; tanpa padding .sheet krn margin sudah diatur). ── */
*{box-sizing:border-box}
body{font-family:Helvetica, Arial, sans-serif;font-size:11px;color:#111}
<?php endif; ?>
.sign{page-break-inside:avoid}
.meta{margin-bottom:10px;line-height:1.7}
.meta b{display:inline-block;min-width:70px}
table.obj{width:100%;border-collapse:collapse;margin:10px 0}
table.obj th,table.obj td{border:1px solid #cbd5e1;padding:6px 8px;text-align:left;font-size:11px}
table.obj th{background:#f1f5f9}
table.rinci{width:100%;border-collapse:collapse;margin:10px 0}
table.rinci td{border:1px solid #334155;padding:4px 7px;font-size:10.5px;vertical-align:top;line-height:1.4}
table.rinci td.no{width:7%;text-align:center;font-weight:700}
table.rinci td.lbl{width:24%}
table.rinci td.sep{width:3%;text-align:center}
table.cost{width:100%;border-collapse:collapse;margin:8px 0}
table.cost td{border:1px solid #e5e7eb;padding:5px 10px;font-size:11px}
table.cost td.lbl{width:62%;color:#374151}
table.cost td.amt{text-align:right;font-weight:600;white-space:nowrap}
table.cost tr.sub td{background:#f8fafc}
table.cost tr.tot td{background:#f1f5f9;font-weight:700}
table.cost tr.grand td{background:#f0fdfa;color:#0f766e;font-weight:800;font-size:11px}
.validbox{display:inline-block;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;border-radius:6px;padding:3px 10px;font-size:11px;font-weight:600;margin-top:4px}
.sec{font-weight:800;margin:14px 0 5px;color:#0D9488;font-size:11px;letter-spacing:.02em;page-break-after:avoid}
.secrom{font-weight:bold;color:#111;font-size:11px;margin:13px 0 4px;page-break-after:avoid}
ul,ol{margin:0 0 0 18px}
li{margin-bottom:3px;line-height:1.45;text-align:justify}
.intro,.closing{text-align:justify}
.pay li{font-size:11px}
.rek{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;margin-top:6px;font-size:11px;line-height:1.7}
.tnc li{font-size:11px;color:#374151;page-break-inside:avoid}
.sign{width:100%;border-collapse:collapse;margin-top:22px;page-break-inside:avoid;table-layout:fixed}
.sign td.col{width:50%;vertical-align:top;padding:0 6px}
.sign .sigarea{height:78px}
.sign .ttd-img{height:58px;width:auto;margin:2px 0}
.sign .nm{font-weight:700;border-top:1px solid #111;display:inline-block;padding-top:3px;min-width:170px}
.qrbox{width:70px;height:70px;margin:6px 0 3px}
.qrbox img,.qrbox svg{width:70px!important;height:70px!important;display:block}
.qrhint{font-size:7.5px;color:#6b7280;margin-bottom:3px}
.muted{color:#6b7280}
</style>
<?php if (!$PDF_MODE): ?>
</head>
<body>
<?php /* Mode pratinjau (di dalam bingkai halaman Template): tombol cetak dan
         petunjuk layar kecil tidak relevan, jadi tidak dicetak. */ ?>
<?php if (empty($PRATINJAU)): ?>
<div class="no-print">
    <button class="btn-print" onclick="window.print()" title="Di HP: pilih 'Simpan sebagai PDF' di dialog cetak">🖨 Simpan PDF / Cetak</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>
<div class="pdf-hint">📄 Ketuk <b>Simpan PDF / Cetak</b> di atas, lalu pilih <b>“Simpan sebagai PDF”</b> sebagai tujuan pada dialog cetak.</div>
<?php endif; ?>
<table class="paper">
<thead><tr><td><div class="sp-top"></div></td></tr></thead>
<tfoot><tr><td><div class="sp-bot"></div></td></tr></tfoot>
<tbody><tr><td>
<?php endif; ?>
<div class="sheet">
    <div style="margin-bottom:8px">Balikpapan, <?= $h($tanggal) ?></div>
    <?php /* Titik dua disejajarkan memakai tabel — di Word baris ini diketik
             dengan tab, dan "Nomor : ..." / "Perihal : ..." memang rata. */ ?>
    <table style="border-collapse:collapse;margin-bottom:10px">
        <tr><td style="padding:0 0 2px;font-weight:bold;width:62px">Nomor</td>
            <td style="padding:0 0 2px">: <?= $h($o['offer_no']) ?></td></tr>
        <tr><td style="padding:0;font-weight:bold">Perihal</td>
            <td style="padding:0">: <?= $h($perihal) ?></td></tr>
    </table>
    <?php
        $addrName  = trim((string)($o['company_name'] ?? '')) ?: '-';
        $addrBrand = trim((string)($o['brand_name'] ?? ''));
        $addrCp    = trim((string)($o['cp_name'] ?? ''));
        // "Up." hanya bila nama kontak berbeda dari nama/brand yang sudah tampil di atas.
        $showUp = $addrCp !== '' && strcasecmp($addrCp, $addrName) !== 0 && strcasecmp($addrCp, $addrBrand) !== 0;
    ?>
    <div class="meta">
        Kepada Yth,<br>
        <strong><?= $h($addrName) ?></strong><?= $addrBrand && strcasecmp($addrBrand, $addrName) !== 0 ? ' — ' . $h($addrBrand) : '' ?><br>
        <?= $showUp ? 'Up. ' . $h($addrCp) . '<br>' : '' ?>
        <?php /* Kota client dipakai bila terisi; "Di Tempat" hanya untuk yang sekota. */ ?>
        Di <?= trim((string) ($o['city'] ?? '')) !== '' ? '&ndash; ' . $h($o['city']) : 'Tempat' ?>
    </div>

    <p class="intro" style="margin:8px 0">Dengan hormat,<br><?= clara_format_bold($letter['intro'] ?: 'Bersama ini kami Management e-Walk dan Pentacity Mall Balikpapan menawarkan space exhibition sebagai berikut:') ?></p>

<?php
    /* Nilai yang boleh disebut kalimat baku template, supaya bullet seperti
       "Masa sewa {hari} hari" tidak perlu diketik ulang tiap surat. */
    $picNama = (string) ($o['pic_name'] ?: '');
    $picWa   = function_exists('offer_telp_rapi') ? offer_telp_rapi($o['pic_phone'] ?? '') : (string) ($o['pic_phone'] ?? '');
    $amtsAwal = ['dp' => $o['dp_amount'] ?: 0, 'deposit' => $deposit, 'total' => $dasarPpn, 'ppn' => $ppn, 'grand' => $grand];
    $isiKet = [
        'hari'       => (string) $days,
        // Masa sewa gaya surat kertas: "13 Bulan 20 Hari".
        'masa_bulan_hari' => function_exists('offer_masa_bulan_hari')
                           ? offer_masa_bulan_hari($o['start_date'] ?? null, $o['end_date'] ?? null) : '',
        // Harga per bulan — beda dengan {harga} yang berisi total periode.
        'harga_bulan' => $rp((float) ($o['unit_rate'] ?? 0)),
        'periode'    => $periode,
        'periode_panjang' => function_exists('offer_periode_panjang')
                           ? offer_periode_panjang($o['start_date'] ?? null, $o['end_date'] ?? null) : $periode,
        'lokasi'     => (string) (($o['location_name'] ?: $o['master_code']) ?? ''),
        'luas'       => trim((string) ($o['ukuran'] ?? '')) !== '' ? (string) $o['ukuran']
                      : ($o['area_sqm'] ? rtrim(rtrim(number_format((float) $o['area_sqm'], 2, ',', '.'), '0'), ',') . ' m²' : ''),
        'harga'      => $rp($total),
        'ppn_persen' => rtrim(rtrim(number_format($PPN_PERSEN, 2, ',', '.'), '0'), ',') . '%',
        'pic'        => $picNama,
        'pic_besar'  => mb_strtoupper($picNama, 'UTF-8'),
        'wa'         => $picWa ?: $OFFICE_PHONE,
        'email'      => (string) ($o['pic_email'] ?? ''),
        'kantor'     => $OFFICE_PHONE,
    ];
?>
<?php $isBundle = !empty($o['is_bundle']) && !empty($items);
    $segLbl = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    $sumDp = $isBundle ? array_sum(array_column($items, 'dp_amount')) : (float) $o['dp_amount']; ?>
    <?php if ($LAYOUT === 'rincian'): ?>
    <?php /* Keluarga Foodcourt: bukan tabel harga, melainkan daftar bernomor
             (Lokasi, Alamat, Area & Ukuran, Periode Sewa, Biaya Sewa, Service
             Charge, Biaya Utilities, Security Deposit, Term of Payment, Jam
             Operasional, Serah Terima, Facilities, Fit Out Periode, Schedule).
             Isi tiap baris milik template; yang berisi {…} diisi data penawaran. */ ?>
    <table class="rinci">
        <?php $noR = 0; foreach (($letter['rincian'] ?? []) as $r):
            $lbl = trim((string) ($r['label'] ?? ''));
            if ($lbl === '') continue;
            $noR++;
            $isi = offer_letter_fill((string) ($r['isi'] ?? ''), $amtsAwal + $isiKet); ?>
        <tr>
            <td class="no"><?= $noR ?>.</td>
            <td class="lbl"><strong><?= $h($lbl) ?></strong></td>
            <td class="sep">:</td>
            <td class="isi"><?= nl2br(clara_format_bold($isi)) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($PPN_CATATAN !== '' && $kenaPpn): ?>
    <div style="margin-top:6px;font-size:10px"><?= $h(offer_letter_fill($PPN_CATATAN, $isiKet)) ?></div>
    <?php endif; ?>
    <?php else: ?>
    <table class="obj">
        <?php $adaLuasItem = $isBundle && array_sum(array_map(fn($x) => (float) ($x['area_sqm'] ?? 0), $items)) > 0; ?>
        <thead><tr><th><?= $h($JUDUL['kolom_lokasi']) ?></th><th><?= ($isBundle && $adaLuasItem) ? 'Luasan / Jenis' : ($isBundle ? 'Jenis' : $h($JUDUL['kolom_luas'])) ?></th><th><?= $h($JUDUL['kolom_harga']) ?></th><th><?= $h($JUDUL['kolom_ket']) ?></th></tr></thead>
        <tbody>
        <?php if ($isBundle): ?>
            <?php foreach ($items as $it): ?>
            <tr>
                <td><?= $h($it['name_snapshot'] ?: $it['master_code']) ?></td>
                <?php /* Dua keterangan, bukan salah satu: paket campuran punya titik
                         media tanpa luas, dan jenisnya tetap perlu terbaca. */ ?>
                <td><?= (float) ($it['area_sqm'] ?? 0) > 0
                        ? $h(rtrim(rtrim(number_format((float) $it['area_sqm'], 2, ',', '.'), '0'), ',')) . ' m² · ' . $h($segLbl[$it['segment']] ?? $it['segment'])
                        : $h($segLbl[$it['segment']] ?? $it['segment']) ?></td>
                <td><?= $rp($it['total_amount']) ?></td>
                <td><?= $h($it['master_code'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <?php
            /* Kolom Keterangan di surat kertas berisi bullet baku milik template
               (masa sewa, periode, status PPN, status listrik) — bukan catatan
               bebas. Catatan bebas penawaran tetap ikut tercetak di bawahnya
               supaya tidak ada isian yang hilang. */
            $ketBullet = [];
            foreach (($letter['ket'] ?? []) as $kb) {
                $kb = trim(offer_letter_fill((string) $kb, $isiKet));
                if ($kb !== '') $ketBullet[] = $kb;
            }
            $ketBebas = trim((string) ($o['keterangan'] ?? ''));
            ?>
            <tr>
                <td><?= $h(($o['location_name'] ?: $o['master_code']) . ($o['floor'] ? ' (Lt. ' . $o['floor'] . ')' : '')) ?></td>
                <?php /* Ukuran apa adanya (mis. "2x3 m2") lebih mudah dibayangkan client
                         daripada hasil kalinya; dipakai bila diisi. */ ?>
                <td><?= trim((string) ($o['ukuran'] ?? '')) !== ''
                        ? $h($o['ukuran'])
                        : ($o['area_sqm'] ? number_format((float)$o['area_sqm'], 2, ',', '.') . ' m²' : '-') ?></td>
                <?php /* Surat kertas menulis harga PER BULAN di kolom ini, bukan
                         total kontrak. Templatenya yang menentukan; bawaannya
                         tetap total periode supaya template lama tak berubah. */ ?>
                <td><?= $rp(($letter['harga_tampil'] ?? 'periode') === 'bulan'
                        ? (float) ($o['unit_rate'] ?? 0) : $total) ?></td>
                <td>
                    <?php if ($ketBullet): ?>
                        <?php foreach ($ketBullet as $kb): ?><div style="line-height:1.45">&#9679; <?= clara_format_bold($kb) ?></div><?php endforeach; ?>
                        <?php if ($ketBebas !== ''): ?><div style="line-height:1.45">&#9679; <?= $h($ketBebas) ?></div><?php endif; ?>
                        <?php if ($PPN_CATATAN !== '' && $kenaPpn): ?>
                        <div style="font-size:8px;font-style:italic;color:#475569;margin-top:2px"><?= $h(offer_letter_fill($PPN_CATATAN, $isiKet)) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?= $h($ketBebas !== '' ? $ketBebas : '-') ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?php /* Kalau bullet Keterangan sudah menyebut masa & periode (seperti surat
             kertas), baris ini cuma pengulangan. */ ?>
    <?php if (!$ketBullet): ?>
    <div style="line-height:1.7;margin-top:4px">
        Masa sewa: <strong><?= $h($durasi) ?></strong> &nbsp;·&nbsp; Periode: <strong><?= $h($periode) ?></strong>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php /* Surat kertas yang selama ini dipakai tidak memuat tabel rincian ini —
             angkanya cukup di kolom harga. Dibiarkan menyala secara bawaan supaya
             surat yang sudah terbit tidak berubah; template bisa mematikannya. */ ?>
    <?php if ($PAKAI_RINCIAN_BIAYA): ?>
    <?= $JUDUL_BAGIAN($JUDUL['rincian_biaya']) ?>
    <?php $tierHarga = $tierHarga ?? []; ?>
    <table class="cost">
        <?php if ($isBundle && $tierHarga && $days > 0): ?>
            <?php /* Harga melekat ke PAKET: dirinci menurut tingkatan luas ×
                     tarif × lama hari, bukan per lokasi — sebab pembagian luasnya
                     memang tidak jatuh di batas lokasi. */ ?>
            <?php $luasTier = 0; foreach ($tierHarga as $t): $luasTier += (float) $t['area_sqm'];
                  $sub = (float) $t['area_sqm'] * (float) $t['rate_per_sqm'] * $days; ?>
            <tr><td class="lbl"><?= $h($t['label'] ?: 'Harga Sewa') ?>
                <span class="muted" style="font-weight:400">(<?= $h(rtrim(rtrim(number_format((float) $t['area_sqm'], 2, ',', '.'), '0'), ',')) ?> m² × <?= $rp($t['rate_per_sqm']) ?> × <?= (int) $days ?> hari)</span></td>
                <td class="amt"><?= $rp($sub) ?></td></tr>
            <?php endforeach; ?>
            <?php $luasUnit = array_sum(array_map(fn($x) => (float) ($x['area_sqm'] ?? 0), $items)); ?>
            <tr><td class="lbl">Total luas dasar perhitungan<?php if ($luasUnit > 0 && abs($luasUnit - $luasTier) >= 0.01): ?>
                <span class="muted" style="font-weight:400">(luas terukur tiap titik <?= $h(rtrim(rtrim(number_format($luasUnit, 2, ',', '.'), '0'), ',')) ?> m²)</span>
                <?php endif; ?></td><td class="amt"><?= $h(rtrim(rtrim(number_format($luasTier, 2, ',', '.'), '0'), ',')) ?> m²</td></tr>
            <tr class="sub"><td class="lbl">Biaya Sewa</td><td class="amt"><?= $rp($total) ?></td></tr>
        <?php elseif ($isBundle): ?>
            <?php foreach ($items as $it): ?>
            <tr><td class="lbl"><?= $h($it['name_snapshot'] ?: $it['master_code']) ?> <span class="muted" style="font-weight:400">(<?= $h($segLbl[$it['segment']] ?? $it['segment']) ?>)</span></td><td class="amt"><?= $rp($it['total_amount']) ?></td></tr>
            <?php endforeach; ?>
            <tr class="sub"><td class="lbl">Subtotal sewa paket</td><td class="amt"><?= $rp($total) ?></td></tr>
        <?php else: ?>
            <tr><td class="lbl">Harga Sewa / Periode</td><td class="amt"><?= $rp($total) ?></td></tr>
            <tr><td class="lbl">Masa sewa</td><td class="amt"><?= $h($durasi) ?></td></tr>
            <tr class="sub"><td class="lbl">Subtotal sewa</td><td class="amt"><?= $rp($total) ?></td></tr>
        <?php endif; ?>
        <?php if ($listrik > 0): ?>
            <?php /* Dirinci per komponen: sewa dan listrik masing-masing dengan
                     PPN-nya sendiri, supaya customer tahu asal tiap angka. */ ?>
            <?php if ($kenaPpn): ?>
            <tr><td class="lbl"><?= $PPN_LABEL ?> Sewa<?= $PPN_HINT ?></td><td class="amt"><?= $rp($ppnSewa) ?></td></tr>
            <tr class="sub"><td class="lbl">Subtotal Sewa + PPN</td><td class="amt"><?= $rp($total + $ppnSewa) ?></td></tr>
            <?php endif; ?>
            <?php if ((float) ($o['electricity_amount'] ?? 0) > 0): ?>
                <?php /* Nominal ditetapkan sendiri oleh sales — tarif per 30 hari
                         tidak ditampilkan supaya tidak rancu. */ ?>
                <tr><td class="lbl">Biaya Listrik <span class="muted" style="font-weight:400">(<?= (int) $days ?> hari)</span></td><td class="amt"><?= $rp($listrik) ?></td></tr>
            <?php else: ?>
                <tr><td class="lbl">Biaya Listrik Per Bulan</td><td class="amt"><?= $rp($listrikBln) ?></td></tr>
                <?php /* Kalau jumlah satuannya dipilih sendiri (tidak mengikuti lama
                         sewa), sebutkan — supaya tidak terbaca sebagai salah hitung. */ ?>
                <tr><td class="lbl">Masa listrik</td><td class="amt"><?= (int) $days ?> hari<?= (int) ($o['electricity_units'] ?? 0) > 0 ? ' · dihitung ' . (int) $listrikN . ' ×' : '' ?></td></tr>
                <?php if ($listrik != $listrikBln): ?>
                <tr><td class="lbl">Total Biaya Listrik</td><td class="amt"><?= $rp($listrik) ?></td></tr>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($kenaPpn): ?>
            <tr><td class="lbl"><?= $PPN_LABEL ?> Listrik<?= $PPN_HINT ?></td><td class="amt"><?= $rp($ppnListrik) ?></td></tr>
            <tr class="sub"><td class="lbl">Subtotal Listrik + PPN</td><td class="amt"><?= $rp($listrik + $ppnListrik) ?></td></tr>
            <?php endif; ?>
        <?php elseif ($kenaPpn): ?>
            <tr><td class="lbl"><?= $PPN_LABEL ?><?= $PPN_HINT ?></td><td class="amt"><?= $rp($ppn) ?></td></tr>
        <?php endif; ?>
        <tr class="tot"><td class="lbl"><?= $kenaPpn ? 'Total setelah PPN' : 'Total Biaya Sewa' ?></td><td class="amt"><?= $rp($afterPpn) ?></td></tr>
        <?php if ($isBundle && $sumDp > 0): ?>
        <tr><td class="lbl">DP / Uang Muka (bagian dari total)</td><td class="amt"><?= $rp($sumDp) ?></td></tr>
        <?php endif; ?>
        <?php /* Harga berjenjang: tiap tahap tercetak agar perubahan harga di
                 tahun berikutnya sudah diketahui client sejak awal. */ ?>
        <?php if ($tahapHarga): ?>
        <tr><td class="lbl"><strong>Jadwal Harga</strong></td><td class="amt"></td></tr>
        <?php foreach ($tahapHarga as $t): ?>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;Mulai <?= $h(date('d/m/Y', strtotime($t['from']))) ?><?= $t['label'] ? ' <span class="muted" style="font-weight:400">(' . $h($t['label']) . ')</span>' : '' ?></td>
            <td class="amt"><?= $rp($t['amount']) ?> <span class="muted" style="font-weight:400">/ bulan</span></td></tr>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($scBulanan > 0): ?>
        <tr><td class="lbl"><strong>Service Charge</strong></td><td class="amt"></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;Biaya SC / bulan</td><td class="amt"><?= $rp($scBulanan) ?></td></tr>
        <?php if ($kenaPpn): ?>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;<?= $PPN_LABEL ?><?= $PPN_HINT ?></td><td class="amt"><?= $rp($scPpnBulan) ?></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;Total SC + PPN / bulan</td><td class="amt"><?= $rp($scPerBulan) ?></td></tr>
        <?php endif; ?>
        <tr class="sub"><td class="lbl">&nbsp;&nbsp;&nbsp;Total Biaya SC <?= (int) $scBulan ?> bulan<?= $kenaPpn ? ' + PPN' : '' ?></td><td class="amt"><?= $rp($scTotal) ?></td></tr>
        <?php endif; ?>
        <tr><td class="lbl"<?= $depLunas ? ' style="color:#166534"' : '' ?>>Security Deposit (dikembalikan 100%)</td>
            <td class="amt"<?= $depLunas ? ' style="color:#166534;font-weight:bold"' : '' ?>><?= $rp($deposit) ?><?= $depLunas ? ' (Sudah Dibayarkan)' : '' ?></td></tr>
        <tr class="grand"><td class="lbl">Grand Total<?= $scBulanan > 0 ? ' (Sewa + Service Charge)' : '' ?> <?= $depLunas ? '(di luar Security Deposit)' : '(pembayaran awal + deposit)' ?></td><td class="amt"><?= $rp($grand) ?></td></tr>
    </table>
    <?php endif; ?>

    <?php
    $facil = array_key_exists('fasilitas', $letter) ? ($letter['fasilitas'] ?? []) : offer_facilities();
    $payList = $letter['payment'] ?: [];
    $amts = ['dp' => $o['dp_amount'] ?: 0, 'deposit' => $deposit, 'total' => $dasarPpn, 'ppn' => $ppn, 'grand' => $grand];
    ?>
    <?php if ($facil): ?>
    <?= $JUDUL_BAGIAN($JUDUL['fasilitas']) ?>
    <ul><?php foreach ($facil as $f): ?><li><?= clara_format_bold($f) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <?php /* "Media promosi yang dapat digunakan" adalah bagian tersendiri di surat
             kertas, bukan salah satu butir Fasilitas. */ ?>
    <?php $mediaList = $letter['media'] ?? []; if ($mediaList): ?>
    <?= $JUDUL_BAGIAN($JUDUL['media']) ?>
    <ul><?php foreach ($mediaList as $m): ?><li><?= clara_format_bold((string) $m) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <?php if ($payList): ?>
    <?= $JUDUL_BAGIAN($JUDUL['pembayaran']) ?>
    <<?= $TAG_LIST ?> class="pay"><?php foreach ($payList as $p): ?><li><?= clara_format_bold(offer_letter_fill((string) $p, $amts + $isiKet)) ?></li><?php endforeach; ?></<?= $TAG_LIST ?>>
    <?php endif; ?>
    <?php if (trim((string) ($BANK['rekening'] ?? '')) !== ''): ?>
        <?php if ($GAYA_BANK === 'menyatu'): ?>
        <?php /* Gaya surat kertas: menempel di bawah Cara Pembayaran, tanpa kotak,
                 masuk ke dalam sedikit mengikuti teks butir di atasnya. */ ?>
        <div style="margin:3px 0 0 18px;line-height:1.5">
            <?= $h($BANK['kalimat']) ?><br>
            <strong><?= $h($BANK['atas_nama']) ?></strong><br>
            <strong><?= $h($BANK['bank']) ?></strong><br>
            <strong>Nomor Rekening <?= $h($BANK['rekening']) ?></strong>
        </div>
        <p style="margin:7px 0 0">Bukti pembayaran di email ke <strong><?= $h($o['pic_email'] ?: '-') ?></strong>
           atau whatsapp ke <strong><?= $h(mb_strtoupper((string) ($o['pic_name'] ?? ''), 'UTF-8')) ?></strong>
           di nomor <strong><?= $h($payWa) ?></strong>.</p>
        <?php else: ?>
        <div class="rek">
            <?= $h($BANK['kalimat']) ?><br>
            <strong><?= $h($BANK['atas_nama']) ?></strong> · <?= $h($BANK['bank']) ?> · No. Rek <strong><?= $h($BANK['rekening']) ?></strong><br>
            Bukti pembayaran dikirim via WhatsApp ke <strong><?= $h($payWa) ?></strong><?= $o['pic_email'] ? ' atau email <strong>' . $h($o['pic_email']) . '</strong>' : '' ?>.
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($ketentuan): ?>
    <?= $JUDUL_BAGIAN($JUDUL['ketentuan']) ?>
    <<?= $TAG_LIST ?> class="tnc"><?php foreach ($ketentuan as $t): ?><li><?= clara_format_bold(offer_letter_fill((string) $t, $amts + $isiKet)) ?></li><?php endforeach; ?></<?= $TAG_LIST ?>>
    <?php endif; ?>
    <?php if (!isset($letter['tampil_berlaku']) || !empty($letter['tampil_berlaku'])): ?>
    <div class="validbox" style="margin-top:8px">Penawaran ini berlaku s/d <?= $h($berlaku) ?></div>
    <?php endif; ?>

    <?php /* Nama & nomor WhatsApp diambil dari Master PIC sales yang bersangkutan,
             bukan satu nomor untuk semua orang. Kalimatnya sendiri milik template. */ ?>
    <p style="margin-top:12px"><?= clara_format_bold(offer_letter_fill((string) ($letter['penutup'] ?: offer_penutup_bawaan()), $isiKet)) ?></p>
    <?php $penutupAkhir = trim((string) ($letter['penutup_akhir'] ?? offer_penutup_akhir_bawaan())); ?>
    <?php if ($penutupAkhir !== ''): ?>
    <p class="closing" style="margin-top:6px"><?= clara_format_bold(offer_letter_fill($penutupAkhir, $isiKet)) ?></p>
    <?php endif; ?>

    <?php
    // QR "Scan untuk validasi" pada TTD sales — sama seperti SKP (via sign_token).
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $vdir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $verifyUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $vdir . '/?r=offer_verify&token=' . ($o['sign_token'] ?? '');
    // QR validasi: buatan aplikasi, tidak ada di surat kertas — template boleh
    // mematikannya. Bawaannya menyala.
    $hasQr = !empty($o['sign_token']) && (!isset($letter['tampil_qr']) || !empty($letter['tampil_qr']));
    ?>
    <?php $custSigned = !empty($o['signed_at']); ?>
    <table class="sign">
    <tr>
        <td class="col">
            <div>Hormat kami,</div>
            <div style="font-weight:600">PT. Wulandari Bangun Laksana, Tbk.</div>
            <div class="sigarea">
                <?php if ($hasQr): ?>
                    <?php if ($PDF_MODE): ?>
                    <div class="qrbox"><?= clara_qr_img($verifyUrl, 18) ?></div><div class="qrhint">Scan untuk validasi</div>
                    <?php else: ?>
                    <div class="qrbox" data-qr="<?= $h($verifyUrl) ?>"></div><div class="qrhint">Scan untuk validasi</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="nm"<?= $hasQr ? ' style="border-top:none;padding-top:0"' : '' ?>><?= $h($o['pic_name'] ?: '-') ?><br><span class="muted" style="font-weight:400"><?= $h(trim((string) ($o['pic_role'] ?? '')) !== '' ? $o['pic_role'] : 'Sales ' . $propShort) ?></span></div>
        </td>
        <td class="col">
            <div>Menyetujui,</div>
            <?php $ttdKanan = array_key_exists('ttd_kanan', $letter) ? trim((string) $letter['ttd_kanan']) : 'Calon Penyewa'; ?>
            <?php if ($ttdKanan !== ''): ?><div style="font-weight:600"><?= $h($ttdKanan) ?></div><?php endif; ?>
            <?php if ($custSigned && !empty($o['signature_data'])): ?>
            <div class="sigarea"><img class="ttd-img" height="58" src="<?= $h($o['signature_data']) ?>" alt="TTD"></div>
            <?php else: ?>
            <?php /* Ruang kosong untuk tanda tangan saat dicetak. mPDF mengabaikan height pada div,
                       tapi menghormatinya pada sel tabel — jadi dipakai tabel 1 sel
                       dengan garis bawah sebagai tempat membubuhkan tanda tangan. */ ?>
            <table style="width:26mm;border-collapse:collapse;margin-bottom:2mm"><tr>
                <td style="height:20mm;border-bottom:1px solid #111;font-size:1pt;color:#ffffff">&nbsp;</td>
            </tr></table>
            <?php endif; ?>
            <?php if ($custSigned && ($o['sign_method'] ?? 'online') === 'wet'): ?>
            <div class="nm"><?= $h($o['sign_name'] ?: ($o['cp_name'] ?? '-')) ?><br><span class="muted" style="font-weight:400">Penanggung Jawab</span><br><span class="muted" style="font-weight:400;font-size:8px"><span style="color:#16a34a">■</span> Ditandatangani <?= $h(substr((string) $o['signed_at'], 0, 16)) ?> &middot; dokumen ber-TTD tersimpan</span></div>
            <?php elseif ($custSigned): ?>
            <div class="nm" style="border-top:none;padding-top:0"><?= $h($o['sign_name'] ?: ($o['cp_name'] ?? '-')) ?><br><span class="muted" style="font-weight:400">Penanggung Jawab</span><br><span class="muted" style="font-weight:400;font-size:8px"><span style="color:#16a34a">■</span> Ditandatangani elektronik <?= $h(substr($o['signed_at'], 0, 16)) ?></span></div>
            <?php else: ?>
            <div class="nm" style="border-top:none;padding-top:0"><?= $h($o['cp_name'] ?? '') ?: '&nbsp;' ?><br><span class="muted" style="font-weight:400">Penanggung Jawab</span></div>
            <?php endif; ?>
        </td>
    </tr>
    </table>
</div>
<?php if ($PDF_MODE) { return; } /* mode PDF: berhenti di sini, HTML konten ditangkap offer_print() */ ?>
</td></tr></tbody>
</table>
<script src="assets/qrcode.min.js"></script>
<script>
(function () {
    if (typeof qrcode !== 'function') return;
    document.querySelectorAll('.qrbox[data-qr]').forEach(function (box) {
        try {
            var qr = qrcode(0, 'M');
            qr.addData(box.getAttribute('data-qr'));
            qr.make();
            box.innerHTML = qr.createSvgTag({ cellSize: 2, margin: 0, scalable: true });
        } catch (e) {}
    });
})();
</script>
<script>
/* Fit-to-width A4 di layar HP saja (≤820px). Hasil cetak/PDF tak terpengaruh. */
(function () {
    var p = document.querySelector('table.paper');
    if (!p) return;
    function clear() { p.style.transform = ''; document.body.style.height = ''; }
    function fit() {
        clear();
        if (window.innerWidth >= 820) return;
        var w = p.offsetWidth; if (!w) return;
        var s = window.innerWidth / w;
        p.style.transform = 'scale(' + s + ')';
        document.body.style.height = (p.offsetHeight * s) + 'px';
    }
    window.addEventListener('resize', fit);
    window.addEventListener('load', fit);
    // PENTING: hapus skala saat cetak/Simpan PDF agar A4 PENUH (tak mengecil),
    // lalu pulihkan tampilan layar setelah dialog ditutup.
    window.addEventListener('beforeprint', clear);
    window.addEventListener('afterprint', fit);
    if (window.matchMedia) {
        var mq = window.matchMedia('print');
        var onmq = function (e) { if (e.matches) clear(); else fit(); };
        if (mq.addEventListener) mq.addEventListener('change', onmq);
        else if (mq.addListener) mq.addListener(onmq);
    }
    fit();
})();
</script>
</body>
</html>
<?php exit; ?>
