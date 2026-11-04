<?php

/**
 * Renderer PDF surat ber-kop (letterhead) untuk CLARA.
 *
 * Memakai mPDF: kop ATAS & BAWAH dipasang via SetHTMLHeader/SetHTMLFooter
 * (potongan gambar letterhead) sehingga BERULANG di tiap halaman & IDENTIK di
 * semua perangkat (HP/PC) — beda dari window.print() yang bergantung print-engine
 * browser (Chrome Android tak mengulang kop). Dipakai Surat Penawaran, SKP/SKS,
 * dan Formulir Permintaan Kontrak.
 *
 * Strip kop dibuat dari `public/assets/letterhead-a4.jpg` (lihat _clara_pdf_strips).
 */

// Margin konten; dipakai bersama oleh renderer DAN penghitung tinggi isi,
// supaya keduanya tidak pernah memakai angka yang berbeda.
const CLARA_PDF_MARGIN_ATAS  = 34;
const CLARA_PDF_MARGIN_BAWAH = 40;

function _clara_pdf_tempdir(): string
{
    // sys_get_temp_dir() sering TIDAK writable oleh user web server (mis. macOS
    // /var/folders milik user lain). Pakai folder proyek yang sudah pasti writable
    // (uploads), fallback ke system temp bila perlu.
    $candidates = [
        dirname(__DIR__) . '/public/uploads/.mpdf',
        sys_get_temp_dir() . '/clara-mpdf',
    ];
    foreach ($candidates as $d) {
        if (is_dir($d) && is_writable($d)) return $d;
        if (@mkdir($d, 0777, true) && is_writable($d)) return $d;
        if (is_dir($d) && is_writable($d)) return $d;
    }
    return $candidates[0];
}

/**
 * Pastikan strip kop (header/footer) tersedia; regenerate dari letterhead-a4.jpg
 * bila belum ada / lebih tua dari sumbernya. Return [pathHead, pathFoot].
 */
function _clara_pdf_strips(): array
{
    $assets = dirname(__DIR__) . '/public/assets/';
    $src  = $assets . 'letterhead-a4.jpg';
    $head = $assets . 'letterhead-head.jpg';
    $foot = $assets . 'letterhead-foot.jpg';
    $stale = !is_file($head) || !is_file($foot)
        || (is_file($src) && (filemtime($head) < filemtime($src) || filemtime($foot) < filemtime($src)));
    // Hanya regen bila folder writable (di hosting strip sudah ikut di-commit).
    if ($stale && is_file($src) && is_writable($assets)) {
        $im = @imagecreatefromjpeg($src);
        if ($im) {
            $W = imagesx($im); $H = imagesy($im);
            $hh = (int) round(33 / 297 * $H); // kop atas ~33mm
            $fh = (int) round(38 / 297 * $H); // kop bawah ~38mm
            $a = imagecreatetruecolor($W, $hh); imagecopy($a, $im, 0, 0, 0, 0, $W, $hh);
            imagejpeg($a, $head, 92); imagedestroy($a);
            $b = imagecreatetruecolor($W, $fh); imagecopy($b, $im, 0, 0, 0, $H - $fh, $W, $fh);
            imagejpeg($b, $foot, 92); imagedestroy($b);
            imagedestroy($im);
        }
    }
    return [$head, $foot];
}

/**
 * Ukuran kertas renderer: [format mPDF, lebar halaman dalam mm].
 *
 * 'F4' (folio, 215 x 330 mm) dipakai SURAT GUDANG — kertas itulah yang dipakai
 * mencetaknya, sementara A4 membuat tata letaknya nanggung. Nama lain apa pun
 * tetap A4 supaya pemanggil yang tidak menyebut ukuran menghasilkan dokumen
 * yang sama persis seperti sebelumnya.
 */
function clara_pdf_ukuran(string $ukuran): array
{
    return strtoupper(trim($ukuran)) === 'F4' ? [[215, 330], 215, 330] : ['A4', 210, 297];
}

/** Tinggi area isi (mm): tinggi kertas dikurangi margin atas & bawah. */
function clara_pdf_tinggi_isi(string $ukuran): float
{
    return (float) clara_pdf_ukuran($ukuran)[2] - CLARA_PDF_MARGIN_ATAS - CLARA_PDF_MARGIN_BAWAH;
}

/** Bangun instance mPDF ber-kop letterhead berulang + margin konten. */
function clara_letterhead_mpdf(string $ukuran = 'A4'): \Mpdf\Mpdf
{
    [$fmtKertas, $lebarKertas] = clara_pdf_ukuran($ukuran);
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    [$head, $foot] = _clara_pdf_strips();
    // Carlito = pengganti Calibri (metrik sama persis, lisensi OFL). Dipakai
    // cetakan Gudang/Media agar bentuknya sama dengan dokumen kertas yang
    // selama ini dipakai. Font bawaan (DejaVu) tetap default utk dokumen lain.
    $dv = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
    $fd = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
    $mpdf = new \Mpdf\Mpdf([
        'tempDir'       => _clara_pdf_tempdir(),
        'fontDir'       => array_merge($dv, [dirname(__DIR__) . '/app/fonts']),
        'fontdata'      => $fd + ['carlito' => [
            'R'  => 'Carlito-Regular.ttf',
            'B'  => 'Carlito-Bold.ttf',
            'I'  => 'Carlito-Italic.ttf',
            'BI' => 'Carlito-BoldItalic.ttf',
        ]],
        'format'        => $fmtKertas,
        'margin_top'    => CLARA_PDF_MARGIN_ATAS, 'margin_bottom' => CLARA_PDF_MARGIN_BAWAH,
        'margin_left'   => 16, 'margin_right'  => 16,
        'margin_header' => 0,  'margin_footer' => 0,
    ]);
    // Kop FULL-BLEED (tembus tepi kertas): header/footer di mPDF default ikut
    // margin kiri/kanan konten (16mm) → ada border putih. Margin negatif -16mm
    // + lebar gambar = lebar kertas membuat kop menutup penuh, konten tetap inset.
    // Gambar kopnya seproporsi A4; di F4 ia melebar 2,4% sehingga strip jadi
    // ~33,8mm (atas) dan ~38,9mm (bawah) — masih di dalam margin 34mm & 40mm,
    // jadi kop tidak pernah menabrak isi surat.
    $wKop = 'width:' . $lebarKertas . 'mm;display:block';
    if (is_file($head)) $mpdf->SetHTMLHeader('<div style="margin:0 -16mm"><img src="' . $head . '" style="' . $wKop . '"></div>');
    if (is_file($foot)) $mpdf->SetHTMLFooter('<div style="margin:0 -16mm"><img src="' . $foot . '" style="' . $wKop . '"></div>');
    return $mpdf;
}

/**
 * Render HTML jadi PDF ber-kop, TANPA mengirimkannya.
 *
 * Mengembalikan [isi PDF, jumlah halaman, keterpakaian halaman terakhir 0..1].
 * Dua angka terakhir dipakai pemanggil untuk menilai hasilnya sendiri — mis.
 * mendeteksi surat "nanggung" yang halaman terakhirnya cuma berisi tanda
 * tangan — lalu mencoba bentuk lain tanpa perlu merender dua kali.
 */
function clara_pdf_buat(string $html, array $overlay = [], string $cap = '', string $ukuran = 'A4'): array
{
    $mpdf = clara_letterhead_mpdf($ukuran);
    // Cap melintang untuk dokumen yang BELUM sah (mis. pratinjau sebelum
    // persetujuan selesai). Dibuat cukup pudar supaya isinya tetap terbaca,
    // tetapi cukup jelas supaya cetakannya tidak pernah bisa disodorkan
    // sebagai surat asli. Harus dipasang sebelum WriteHTML agar ikut di
    // setiap halaman, termasuk halaman yang terbentuk belakangan.
    if ($cap !== '') {
        $mpdf->SetWatermarkText($cap);
        $mpdf->showWatermarkText = true;
        $mpdf->watermarkTextAlpha = 0.10;
        $mpdf->watermark_font = 'DejaVuSans';
    }
    $mpdf->WriteHTML($html);
    // Paraf yang posisinya dikunci dalam milimeter. WriteFixedPosHTML menaruh
    // potongan HTML pada koordinat halaman apa adanya — berbeda dari CSS
    // position:absolute yang dukungannya di mPDF terbatas dan mudah meleset.
    // Ditulis SETELAH isi dokumen supaya berada di lapisan paling atas.
    foreach ($overlay as $po) {
        try {
            $mpdf->WriteFixedPosHTML(
                (string) $po['html'],
                (float) $po['x'], (float) $po['y'],
                (float) $po['w'], 24.0, 'visible'
            );
        } catch (\Throwable $e) {
            error_log('paraf gagal ditempel di PDF: ' . $e->getMessage());
        }
    }
    // Dibaca SEBELUM Output() — sesudahnya dokumennya sudah ditutup.
    $halaman = max(1, (int) $mpdf->page);
    $tinggi  = clara_pdf_tinggi_isi($ukuran);
    $terpakai = $tinggi > 0
        ? max(0.0, min(1.0, ((float) $mpdf->y - CLARA_PDF_MARGIN_ATAS) / $tinggi))
        : 1.0;
    return [$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN), $halaman, $terpakai];
}

/**
 * Kirim isi PDF ke browser. INLINE → tampil di viewer PDF (preview + bisa
 * simpan/bagikan) di HP & PC. Memanggil exit().
 */
function clara_kirim_pdf(string $pdf, string $filename): void
{
    $safe = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $filename) ?: 'dokumen';
    while (ob_get_level() > 0) ob_end_clean();
    // Header sendiri (bukan dari mPDF) → ANTI-CACHE. mPDF default kirim
    // Cache-Control: public + Last-Modified tetap → Chrome Android bisa
    // menyajikan PDF LAMA yang ter-cache. no-store memaksa selalu ambil terbaru.
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $safe . '.pdf"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

/**
 * Render HTML (boleh memuat <style>) menjadi PDF ber-kop lalu kirim ke browser.
 * Bentuk ringkas dari clara_pdf_buat() + clara_kirim_pdf(). Memanggil exit().
 */
function clara_render_letterhead_pdf(string $html, string $filename, array $overlay = [], string $cap = '', string $ukuran = 'A4'): void
{
    [$pdf] = clara_pdf_buat($html, $overlay, $cap, $ukuran);
    clara_kirim_pdf($pdf, $filename);
}

/**
 * QR code sebagai <img> data-URI PNG (dirender server-side; tak butuh JS).
 * $sizeMm = lebar/tinggi tampil di dokumen.
 */
function clara_qr_img(string $data, float $sizeMm = 22): string
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    try {
        $qr  = new \Mpdf\QrCode\QrCode($data);
        $png = (new \Mpdf\QrCode\Output\Png())->output($qr, 240, [255, 255, 255], [17, 17, 17]);
        return '<img src="data:image/png;base64,' . base64_encode($png) . '" '
             . 'style="width:' . $sizeMm . 'mm;height:' . $sizeMm . 'mm">';
    } catch (\Throwable $e) {
        return '';
    }
}
