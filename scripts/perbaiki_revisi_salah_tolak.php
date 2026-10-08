<?php

/**
 * CLARA — memperbaiki permintaan revisi yang tercatat Ditolak padahal disetujui.
 *
 * Sampai perbaikan tanggal 8 Okt 2026, penjaga klik-ganda di halaman mematikan
 * tombol submit SEBELUM peramban menyusun data kiriman. Tombol yang sudah mati
 * tidak ikut terkirim, sehingga penanda `keputusan=setuju` lenyap dan sisi
 * server membacanya sebagai tolak. Pemeriksa menekan "Setujui Revisi", yang
 * tercatat "Ditolak".
 *
 * Skrip ini mendaftar permintaan yang kemungkinan jadi korban, lalu — bila
 * diminta — memperbaikinya dengan BENAR: statusnya diubah menjadi disetujui
 * DAN dokumennya dibuka kembali untuk PIC, persis seperti yang seharusnya
 * terjadi dulu. Membalik status saja tidak cukup; dokumennya akan tetap
 * terkunci dan PIC tidak bisa memperbaikinya.
 *
 * Yang dilewati: permintaan yang ditutup otomatis oleh sistem saat client
 * menandatangani (decided_by = 'sistem') — itu memang penolakan yang benar.
 *
 * Laporan saja:
 *   php scripts/perbaiki_revisi_salah_tolak.php
 *
 * Perbaiki satu permintaan:
 *   php scripts/perbaiki_revisi_salah_tolak.php --id=12 --terapkan
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

function prs_main(PDO $pdo, array $argv): int
{
    $id = 0; $terapkan = false;
    foreach ($argv as $a) {
        if ($a === '--terapkan') $terapkan = true;
        elseif (str_starts_with($a, '--id=')) $id = (int) substr($a, 5);
    }

    $sql = "SELECT r.id, r.skp_id, r.alasan, r.requested_by, r.requested_at,
                   r.decided_by, r.decided_at, r.decision_note,
                   s.skp_no, s.status AS status_dok, s.revisi_ke, s.property_id
              FROM skp_revision_requests r
              JOIN skp_documents s ON s.id = r.skp_id
             WHERE r.status = 'rejected'
               AND COALESCE(r.decided_by,'') <> 'sistem'
             ORDER BY r.decided_at DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) { echo "Tidak ada permintaan revisi berstatus Ditolak oleh orang.\n"; return 0; }

    echo "PERMINTAAN REVISI BERSTATUS DITOLAK\n";
    echo "Sebagian mungkin sebenarnya DISETUJUI — tombolnya dulu salah terkirim.\n"
       . "Tanyakan ke pemutusnya sebelum memperbaiki; jangan diubah borongan.\n\n";
    printf("  %-5s %-22s %-14s %-26s %-12s %s\n", 'ID', 'DOKUMEN', 'DIPUTUS OLEH', 'ALASAN', 'STATUS DOK', 'WAKTU');
    echo '  ' . str_repeat('-', 104) . "\n";
    foreach ($rows as $r) {
        printf("  %-5d %-22s %-14s %-26s %-12s %s\n", (int) $r['id'],
            mb_substr((string) ($r['skp_no'] ?: 'draft #' . $r['skp_id']), 0, 22),
            mb_substr((string) $r['decided_by'], 0, 14),
            mb_substr((string) $r['alasan'], 0, 26),
            (string) $r['status_dok'], (string) $r['decided_at']);
    }
    echo "\n";

    if (!$id) {
        echo "Sebutkan yang mana: --id=<ID di kolom pertama> --terapkan\n";
        return 1;
    }

    $pilih = null;
    foreach ($rows as $r) if ((int) $r['id'] === $id) $pilih = $r;
    if (!$pilih) { fwrite(STDERR, "ID $id tidak ada di daftar di atas.\n"); return 2; }

    // Menyetujui revisi berarti MEMBUKA KEMBALI dokumennya. Itu hanya masuk akal
    // bila dokumennya masih berstatus 'approved' — kalau sudah ditandatangani
    // client, revisi memang tidak boleh lagi.
    if ((string) $pilih['status_dok'] !== 'approved') {
        fwrite(STDERR, "Dokumen {$pilih['skp_no']} berstatus '{$pilih['status_dok']}', bukan 'approved'.\n"
                     . "Revisi hanya bisa disetujui untuk dokumen yang sudah disetujui dan belum diteken client.\n");
        return 2;
    }

    $revBaru = (int) $pilih['revisi_ke'] + 1;
    echo "AKAN DIPERBAIKI\n";
    printf("  Permintaan #%d pada %s\n", $id, $pilih['skp_no'] ?: 'draft #' . $pilih['skp_id']);
    echo "  1. Status permintaan : Ditolak  →  Disetujui\n";
    printf("  2. Dokumen           : approved  →  draft (revisi ke-%d)\n", $revBaru);
    echo "  3. Tautan tanda tangan lama dimatikan\n";
    echo "  4. Jejak persetujuan ditambah catatan 'revisi'\n\n";

    if (!$terapkan) { echo "Belum ada yang diubah. Tambahkan --terapkan.\n"; return 1; }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE skp_revision_requests
                          SET status = 'approved',
                              decision_note = TRIM(CONCAT(COALESCE(decision_note,''),
                                  ' [dikoreksi: tombol Setujui dulu salah terkirim sebagai Tolak]'))
                        WHERE id = ? AND status = 'rejected'")->execute([$id]);

        $pdo->prepare("UPDATE skp_documents
                          SET status = 'draft', approval_level = 0, revisi_ke = ?,
                              sign_token = NULL, sign_token_expires_at = NULL, reject_note = ?
                        WHERE id = ? AND property_id = ? AND status = 'approved'")
            ->execute([$revBaru,
                       'REVISI #' . $revBaru . ': ' . mb_substr((string) $pilih['alasan'], 0, 300),
                       (int) $pilih['skp_id'], (int) $pilih['property_id']]);

        $pdo->prepare("INSERT INTO skp_approvals
                          (property_id, skp_id, step_no, role_name, action, approver_name, note, created_at)
                       VALUES (?,?,0,NULL,'revisi',?,?,NOW())")
            ->execute([(int) $pilih['property_id'], (int) $pilih['skp_id'],
                       (string) $pilih['decided_by'],
                       'Revisi disetujui (koreksi): ' . $pilih['alasan']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "GAGAL, semua dibatalkan: " . $e->getMessage() . "\n");
        return 1;
    }

    echo "Selesai. Dokumen dibuka kembali untuk PIC — setelah diperbaiki, harus\n"
       . "menempuh alur persetujuan dari awal lagi.\n";
    return 0;
}

if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(prs_main(Database::connect(), $argv));
}
