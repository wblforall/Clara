<?php

/**
 * mPDF yang MENCATAT seberapa penuh setiap halaman, bukan hanya halaman
 * terakhir.
 *
 * mPDF sendiri hanya menyimpan posisi tulis saat ini ($y) — begitu selesai,
 * yang bisa dibaca tinggal halaman paling akhir. Padahal penyesuai halaman di
 * skp.php perlu tahu isi SEMUA halaman untuk menilai apakah pembagiannya
 * seimbang: pembagian yang membuat halaman kedua berisi tetapi halaman
 * pertama tinggal separuh bukan perbaikan, dan tanpa angka halaman pertama
 * hal itu tidak terdeteksi.
 *
 * Setiap pergantian halaman di mPDF — baik otomatis karena penuh maupun
 * karena page-break — lewat AddPage(). Di situlah posisi akhir isi halaman
 * yang baru saja ditutup dicatat, SEBELUM kop halaman berikutnya digambar.
 */
final class ClaraMpdf extends \Mpdf\Mpdf
{
    /** Posisi akhir isi (mm dari tepi atas kertas), per nomor halaman. */
    public array $isiHalaman = [];

    public function AddPage(...$arg)
    {
        if ($this->page >= 1) $this->isiHalaman[$this->page] = (float) $this->y;
        return parent::AddPage(...$arg);
    }
}
