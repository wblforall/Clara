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

/** Bangun instance mPDF A4 dengan kop letterhead berulang + margin konten. */
function clara_letterhead_mpdf(): \Mpdf\Mpdf
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    [$head, $foot] = _clara_pdf_strips();
    $mpdf = new \Mpdf\Mpdf([
        'tempDir'       => _clara_pdf_tempdir(),
        'format'        => 'A4',
        'margin_top'    => 34, 'margin_bottom' => 40,
        'margin_left'   => 16, 'margin_right'  => 16,
        'margin_header' => 0,  'margin_footer' => 0,
    ]);
    // Kop FULL-BLEED (tembus tepi A4): header/footer di mPDF default ikut margin
    // kiri/kanan konten (16mm) → ada border putih. Margin negatif -16mm + lebar
    // 210mm membuat gambar kop menutup penuh lebar halaman, konten tetap inset.
    if (is_file($head)) $mpdf->SetHTMLHeader('<div style="margin:0 -16mm"><img src="' . $head . '" style="width:210mm;display:block"></div>');
    if (is_file($foot)) $mpdf->SetHTMLFooter('<div style="margin:0 -16mm"><img src="' . $foot . '" style="width:210mm;display:block"></div>');
    return $mpdf;
}

/**
 * Render HTML (boleh memuat <style>) menjadi PDF ber-kop lalu kirim ke browser.
 * INLINE → tampil di viewer PDF (preview + bisa simpan/bagikan) di HP & PC.
 * Memanggil exit().
 */
function clara_render_letterhead_pdf(string $html, string $filename): void
{
    $mpdf = clara_letterhead_mpdf();
    $mpdf->WriteHTML($html);
    $pdf  = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
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
