<?php
declare(strict_types=1);

/**
 * Pustaka bersama untuk Invoice & Pembayaran (halaman Tagihan) dan Kalender
 * Pameran. Dipisah dari halamannya karena dua-duanya membutuhkan rumus status
 * yang SAMA — dua salinan rumus yang beda sedikit akan membuat kalender
 * menunjukkan "lunas" sementara daftar tagihan menunjukkan "sebagian".
 */

/** Label & warna tiap status pembayaran. Urutan = urutan di saringan. */
function pembayaran_status_daftar(): array
{
    return [
        'belum_invoice' => ['Belum ada invoice', '#64748B', '#F1F5F9'],
        'belum_bayar'   => ['Belum dibayar',     '#B91C1C', '#FEE2E2'],
        'sebagian'      => ['Sebagian',          '#B45309', '#FEF3C7'],
        'lunas'         => ['Lunas',             '#047857', '#D1FAE5'],
        'tanpa_tagihan' => ['Tanpa tagihan',     '#475569', '#E2E8F0'],
    ];
}

/**
 * Fragmen SELECT yang menghitung tagihan, terbayar, status, dan lewat-tempo.
 * Status TIDAK disimpan: selalu dihitung dari invoice + baris pembayaran.
 *
 *   tagihan  = invoice_amount bila diisi, kalau tidak nilai transaksi
 *   lunas    = terbayar >= tagihan (selisih di bawah Rp1 diabaikan)
 *   lewat    = belum lunas dan jatuh tempo sudah lewat
 *
 * @param string $t alias tabel transactions
 */
function pembayaran_select_sql(string $t = 't'): string
{
    $tagihan = "COALESCE($t.invoice_amount, $t.final_amount)";
    $bayar   = "(SELECT COALESCE(SUM(py.amount),0) FROM transaction_payments py
                  WHERE py.transaction_id = $t.id AND py.deleted_at IS NULL)";
    $adaInv  = "($t.invoice_no IS NOT NULL AND $t.invoice_no <> '' OR $t.invoice_date IS NOT NULL OR $t.invoice_amount IS NOT NULL)";
    $status  = "CASE
                    WHEN $tagihan <= 0 AND $bayar <= 0 THEN 'tanpa_tagihan'
                    WHEN $bayar >= $tagihan - 0.5 THEN 'lunas'
                    WHEN $bayar > 0 THEN 'sebagian'
                    WHEN $adaInv THEN 'belum_bayar'
                    ELSE 'belum_invoice'
                END";
    return "$tagihan AS tagihan, $bayar AS terbayar, $status AS bayar_status,
            ($t.invoice_due IS NOT NULL AND $t.invoice_due < CURDATE() AND $bayar < $tagihan - 0.5) AS bayar_lewat";
}

/** Lencana HTML untuk satu status; $lewat menambahkan tanda "lewat tempo". */
function pembayaran_badge(string $status, bool $lewat = false): string
{
    $d = pembayaran_status_daftar()[$status] ?? [$status, '#475569', '#E2E8F0'];
    $h = '<span style="display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap;color:'
       . $d[1] . ';background:' . $d[2] . '">' . h($d[0]) . '</span>';
    if ($lewat && in_array($status, ['belum_bayar', 'sebagian'], true)) {
        $h .= ' <span style="display:inline-block;padding:2px 7px;border-radius:99px;font-size:10px;font-weight:700;color:#fff;background:#DC2626">Lewat tempo</span>';
    }
    return $h;
}

/**
 * Syarat transaksi yang BOLEH tampil — sejajar dengan daftar Exhibition/Media/
 * Gudang (app/pages/transactions.php): dokumen ditolak atau sedang direvisi
 * disembunyikan, dan kesepakatan baru baru tampil setelah diteken klien.
 * Disalin ke sini, bukan dikutip, supaya halaman tagihan dan kalender tidak
 * menampilkan baris yang di daftar transaksi sengaja disembunyikan.
 *
 * @return array{0: string[], 1: array<string,string>} [daftar syarat, parameter]
 */
function transaksi_tampil_kondisi(PDO $pdo, string $t = 't'): array
{
    $skp = fn(string $kol) => "(SELECT s2.$kol FROM skp_documents s2
                     WHERE s2.property_id = $t.property_id
                       AND (s2.transaction_id = $t.id OR s2.id = $t.skp_id)
                     ORDER BY s2.id DESC LIMIT 1)";
    $where  = ["$t.deleted_at IS NULL", "COALESCE(" . $skp('status') . ", '') <> 'rejected'",
               "NOT (COALESCE(" . $skp('status') . ", '') = 'draft' AND COALESCE(" . $skp('revisi_ke') . ", 0) > 0)"];
    $params = [];
    $ttdMulai = ttd_wajib_mulai($pdo);
    if ($ttdMulai !== '') {
        $where[] = "(COALESCE(" . $skp('status') . ", '') IN ('', 'signed') OR DATE($t.created_at) < :ttd_mulai)";
        $params[':ttd_mulai'] = $ttdMulai;
    }
    return [$where, $params];
}

/**
 * Apakah pengguna ini boleh mengisi pembayaran untuk transaksi ini?
 * Sales hanya miliknya sendiri (PIC-nya, atau yang ia buat); peran lain bebas.
 */
function pembayaran_boleh_isi(PDO $pdo, array $trx): bool
{
    if (!can('manage_payments')) return false;
    $scope = current_sales_scope($pdo, (int) $trx['property_id']);
    if (!$scope) return true;
    if ($scope['pic'] !== '' && (string) $trx['pic_name'] === $scope['pic']) return true;
    return (string) $trx['created_by'] === $scope['uname'];
}
