<?php
/**
 * Setel Security Deposit pada satu dokumen SKP — termasuk penanda
 * "sudah dibayarkan" (deposit tercetak di surat, tapi TIDAK menambah
 * Grand Total). Dipakai untuk dokumen yang sudah terlanjur disubmit
 * sehingga formulirnya terkunci.
 *
 *   php scripts/set_deposit_skp.php                      # daftar dokumen menunggu approval
 *   php scripts/set_deposit_skp.php 12 7500000 lunas     # simulasi (tidak mengubah apa pun)
 *   php scripts/set_deposit_skp.php 12 7500000 lunas --apply
 *   php scripts/set_deposit_skp.php 12 7500000 tagih --apply   # ikut Grand Total
 *
 * Dokumen yang SUDAH disetujui/ditandatangani ditolak: nilainya sudah
 * terkunci di snapshot. Untuk itu, tolak dulu dokumennya lewat aplikasi,
 * perbaiki di formulir, lalu submit & setujui ulang.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/app/Database.php';
$pdo = Database::connect();

$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

// Tanpa argumen: tampilkan kandidatnya saja.
if (!isset($argv[1])) {
    echo "Dokumen SKP yang masih bisa disetel (draft / menunggu approval):\n\n";
    $q = $pdo->query(
        "SELECT s.id, s.status, s.deposit_amount, s.deposit_paid, s.created_by,
                COALESCE(t.master_code, o.master_code) kode,
                COALESCE(tc.company_name, oc.company_name) client,
                COALESCE(s.pic_name, o.pic_name, t.pic_name) pic
           FROM skp_documents s
           LEFT JOIN transactions t ON t.id = s.transaction_id
           LEFT JOIN offers o ON o.id = s.offer_id
           LEFT JOIN master_clients tc ON tc.id = t.client_id
           LEFT JOIN master_clients oc ON oc.id = o.client_id
          WHERE s.status IN ('draft','submitted')
          ORDER BY s.id DESC LIMIT 30"
    );
    printf("%-5s %-12s %-10s %-26s %-11s %-14s %s\n", 'ID', 'STATUS', 'KODE', 'CLIENT', 'PIC', 'DEPOSIT', 'LUNAS?');
    foreach ($q as $r) {
        printf("%-5s %-12s %-10s %-26s %-11s %-14s %s\n", $r['id'], $r['status'], $r['kode'] ?: '-',
            mb_substr((string) $r['client'], 0, 26), $r['pic'] ?: '-', $rp($r['deposit_amount']),
            $r['deposit_paid'] ? 'ya' : 'tidak');
    }
    echo "\nPakai: php scripts/set_deposit_skp.php <id> <nominal> <lunas|tagih> [--apply]\n";
    exit;
}

$id      = (int) $argv[1];
$nominal = (float) preg_replace('/\D/', '', (string) ($argv[2] ?? '0'));
$mode    = strtolower((string) ($argv[3] ?? 'lunas'));
$apply   = in_array('--apply', $argv, true);
if (!in_array($mode, ['lunas', 'tagih'], true)) { fwrite(STDERR, "Mode harus 'lunas' atau 'tagih'.\n"); exit(1); }
$lunas = $mode === 'lunas' ? 1 : 0;

$st = $pdo->prepare(
    "SELECT s.*, COALESCE(t.master_code, o.master_code) kode,
            COALESCE(tc.company_name, oc.company_name) client,
            COALESCE(s.pic_name, o.pic_name, t.pic_name) pic,
            COALESCE(t.final_amount, t.total_calculated, o.override_amount, o.total_calculated) nilai
       FROM skp_documents s
       LEFT JOIN transactions t ON t.id = s.transaction_id
       LEFT JOIN offers o ON o.id = s.offer_id
       LEFT JOIN master_clients tc ON tc.id = t.client_id
       LEFT JOIN master_clients oc ON oc.id = o.client_id
      WHERE s.id = ?"
);
$st->execute([$id]);
$skp = $st->fetch();
if (!$skp) { fwrite(STDERR, "SKP id $id tidak ada.\n"); exit(1); }

echo "Dokumen   : #{$skp['id']} " . ($skp['skp_no'] ?: '(nomor belum terbit)') . "\n";
echo "Client    : {$skp['client']} · {$skp['kode']} · PIC {$skp['pic']}\n";
echo "Status    : {$skp['status']}\n";
echo "Nilai sewa: " . $rp($skp['nilai']) . "\n";
echo "Deposit   : " . $rp($skp['deposit_amount']) . ($skp['deposit_paid'] ? ' (sudah dibayarkan)' : ' (ditagih)') . "\n";
echo "Jadi      : " . $rp($nominal) . ($lunas ? ' (sudah dibayarkan — DI LUAR Grand Total)' : ' (ditagih — IKUT Grand Total)') . "\n\n";

if (!in_array($skp['status'], ['draft', 'submitted'], true)) {
    fwrite(STDERR, "DITOLAK: status '{$skp['status']}' — nilainya sudah terkunci di snapshot.\n"
        . "Tolak dulu dokumennya di aplikasi, perbaiki di formulir, lalu submit & setujui ulang.\n");
    exit(1);
}
if (!$apply) { echo "SIMULASI — tidak ada yang diubah. Tambahkan --apply untuk menyimpan.\n"; exit; }

$pdo->prepare('UPDATE skp_documents SET deposit_amount = ?, deposit_paid = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
    ->execute([$nominal, $lunas, $id]);

$cek = $pdo->prepare('SELECT deposit_amount, deposit_paid FROM skp_documents WHERE id = ?');
$cek->execute([$id]);
$now = $cek->fetch();
echo "TERSIMPAN → deposit " . $rp($now['deposit_amount']) . ($now['deposit_paid'] ? ' (sudah dibayarkan)' : ' (ditagih)') . "\n";
echo "Langkah berikutnya: manager menyetujui dokumen ini; angka di atas ikut terkunci ke PDF.\n";
