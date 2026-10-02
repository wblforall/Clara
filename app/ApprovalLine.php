<?php

/**
 * Rantai persetujuan dokumen (SKP / SKS / Form Utilities).
 *
 * Sebelum ada aplikasi, SKP diperiksa & diparaf Asst. Manager dulu, baru naik ke
 * Manager. Di aplikasi langkah tengah itu hilang sehingga koreksi sering
 * terlewat. Kelas ini mengembalikannya — dan membuatnya bisa diatur, bukan
 * ditanam di kode.
 *
 * Dua keputusan penting:
 *
 * 1. Rantainya berbasis JABATAN (master_pic.role_name), bukan nama orang.
 *    Saat orangnya berganti, cukup ubah jabatan di Master PIC; alurnya tetap.
 *    Boleh dikunci ke satu nama tertentu lewat kolom pic_name bila memang perlu.
 *
 * 2. TIDAK ada nilai status baru. Dokumen tetap 'submitted' selama masih di
 *    dalam rantai; yang berubah hanya skp_documents.approval_level. Puluhan
 *    query di aplikasi ini membaca status secara harfiah — menambah nilai baru
 *    akan membuat dokumen hilang diam-diam dari daftar & laporan.
 *
 * Properti yang belum diatur rantainya berjalan persis seperti sebelumnya:
 * satu tahap, langsung ke pemegang izin approve_skp.
 */
final class ApprovalLine
{
    /** Tahap-tahap rantai untuk satu properti & jenis dokumen (urut). */
    public static function steps(PDO $pdo, int $pid, string $docType = 'skp'): array
    {
        try {
            // Baris khusus jenis dokumen menang atas baris umum (doc_type NULL).
            $st = $pdo->prepare(
                "SELECT step_no, role_name, label, pic_name, doc_type
                   FROM skp_approval_flow
                  WHERE property_id = ? AND is_active = 1
                    AND (doc_type IS NULL OR doc_type = '' OR doc_type = ?)
                  ORDER BY step_no ASC, (doc_type IS NULL) ASC, id ASC"
            );
            $st->execute([$pid, $docType]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];                        // tabel belum ada → perilaku lama
        }
        $out = [];
        foreach ($rows as $r) {
            $n = (int) $r['step_no'];
            if ($n <= 0) continue;
            // Baris khusus doc_type sudah diurut lebih dulu, jadi yang pertama menang.
            if (isset($out[$n])) continue;
            $out[$n] = [
                'step_no'   => $n,
                'role_name' => (string) $r['role_name'],
                'label'     => trim((string) ($r['label'] ?? '')) ?: (string) $r['role_name'],
                'pic_name'  => trim((string) ($r['pic_name'] ?? '')) ?: null,
            ];
        }
        ksort($out);
        // Nomor tahap dirapatkan jadi 1..N supaya lubang penomoran di tabel
        // konfigurasi tidak membuat dokumen tersangkut selamanya.
        return array_values($out);
    }

    /** Tahap yang sedang menunggu, atau null bila seluruh rantai sudah lewat. */
    public static function currentStep(array $skp, array $steps): ?array
    {
        if (!$steps) return null;
        $lewat = (int) ($skp['approval_level'] ?? 0);
        return $steps[$lewat] ?? null;        // index 0 = tahap ke-1
    }

    /** Apakah tahap yang sedang menunggu adalah tahap TERAKHIR? */
    public static function isFinalStep(array $skp, array $steps): bool
    {
        if (!$steps) return true;             // tanpa rantai → sekali setuju, selesai
        return ((int) ($skp['approval_level'] ?? 0)) >= count($steps) - 1;
    }

    /** Jabatan (role_name) user yang sedang masuk, pada properti ini. */
    public static function jabatan(PDO $pdo, int $pid): string
    {
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        if (!$uid) return '';
        try {
            $st = $pdo->prepare("SELECT role_name FROM master_pic
                                  WHERE user_id = ? AND property_id = ? AND status = 'active' LIMIT 1");
            $st->execute([$uid, $pid]);
            return trim((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Nama PIC user yang sedang masuk, pada properti ini (kosong bila tidak tertaut). */
    public static function namaPic(PDO $pdo, int $pid): string
    {
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        if (!$uid) return '';
        try {
            $st = $pdo->prepare("SELECT name FROM master_pic
                                  WHERE user_id = ? AND property_id = ? AND status = 'active' LIMIT 1");
            $st->execute([$uid, $pid]);
            return trim((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Bolehkah user yang sedang masuk bertindak pada tahap yang menunggu?
     *
     * Yang boleh:
     *  - jabatannya persis sama dengan jabatan tahap itu (dan, bila tahapnya
     *    dikunci ke satu nama, namanya cocok);
     *  - jabatannya ada di tahap YANG LEBIH TINGGI pada rantai yang sama —
     *    atasan boleh mewakili bawahannya supaya dokumen tidak tersangkut saat
     *    yang bersangkutan berhalangan. Siapa mewakili siapa tercatat di jejak;
     *  - superadmin / admin, karena memang pemilik sistem.
     *
     * Tetap wajib punya izin approve_skp seperti sebelumnya.
     */
    public static function canAct(PDO $pdo, int $pid, array $skp, array $steps): bool
    {
        if (!function_exists('can') || !can('approve_skp')) return false;
        if (!$steps) return true;                              // perilaku lama
        $tahap = self::currentStep($skp, $steps);
        if (!$tahap) return false;                             // rantai sudah habis
        $peran = function_exists('current_role') ? current_role() : '';
        if ($peran === 'superadmin' || $peran === 'admin') return true;

        $jab = self::jabatan($pdo, $pid);
        if ($jab === '') return false;

        if ($tahap['pic_name'] !== null) {
            return strcasecmp(self::namaPic($pdo, $pid), $tahap['pic_name']) === 0;
        }
        if (strcasecmp($jab, $tahap['role_name']) === 0) return true;

        // Atasan (tahap SETELAH tahap ini) boleh mewakili. Indeksnya diambil
        // dari approval_level, bukan dicari ulang — dua tahap boleh saja
        // berjabatan sama, dan pencarian nilai akan salah menunjuk yang pertama.
        $idx = (int) ($skp['approval_level'] ?? 0);
        for ($i = $idx + 1; $i < count($steps); $i++) {
            if (strcasecmp($jab, $steps[$i]['role_name']) === 0) return true;
        }
        return false;
    }

    /** Apakah user ini bertindak mewakili tahap yang bukan jabatannya? */
    public static function mewakili(PDO $pdo, int $pid, array $skp, array $steps): bool
    {
        $tahap = self::currentStep($skp, $steps);
        if (!$tahap) return false;
        if ($tahap['pic_name'] !== null) return false;
        return strcasecmp(self::jabatan($pdo, $pid), $tahap['role_name']) !== 0;
    }

    /** Catat satu tindakan (paraf / setuju / tolak) ke jejak. */
    public static function record(
        PDO $pdo, int $pid, int $skpId, int $stepNo, ?string $roleName,
        string $action, string $note = ''
    ): void {
        try {
            $pdo->prepare(
                'INSERT INTO skp_approvals
                 (property_id, skp_id, step_no, role_name, action, approver_user_id, approver_name, note)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $pid, $skpId, $stepNo, $roleName, $action,
                (int) ($_SESSION['user']['id'] ?? 0) ?: null,
                $_SESSION['user']['name'] ?? 'system',
                $note !== '' ? mb_substr($note, 0, 500) : null,
            ]);
        } catch (Throwable $e) {
            error_log('skp_approvals gagal dicatat (skp=' . $skpId . '): ' . $e->getMessage());
        }
    }

    /** Jejak persetujuan satu dokumen (urut waktu). */
    public static function history(PDO $pdo, int $skpId): array
    {
        try {
            $st = $pdo->prepare('SELECT step_no, role_name, action, approver_name, note, created_at
                                   FROM skp_approvals WHERE skp_id = ? ORDER BY id ASC');
            $st->execute([$skpId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Keterangan singkat status approval untuk ditampilkan di daftar & form,
     * mis. "Menunggu paraf Asst. Manager (tahap 1 dari 2)".
     */
    public static function keterangan(array $skp, array $steps): string
    {
        if (($skp['status'] ?? '') !== 'submitted') return '';
        if (!$steps) return 'Menunggu persetujuan manager';
        $tahap = self::currentStep($skp, $steps);
        if (!$tahap) return 'Menunggu persetujuan akhir';
        $ke = (int) ($skp['approval_level'] ?? 0) + 1;
        $dari = count($steps);
        $kata = $ke === $dari ? 'persetujuan akhir' : 'paraf';
        return 'Menunggu ' . $kata . ' ' . $tahap['label'] . ' (tahap ' . $ke . ' dari ' . $dari . ')';
    }

    /**
     * Daftar nama orang yang sedang ditunggu parafnya — dipakai untuk memberi
     * tahu siapa yang harus bertindak, memakai data Master PIC yang sudah ada.
     */
    public static function penungguNama(PDO $pdo, int $pid, array $skp, array $steps): array
    {
        $tahap = self::currentStep($skp, $steps);
        if (!$tahap) return [];
        if ($tahap['pic_name'] !== null) return [$tahap['pic_name']];
        try {
            $st = $pdo->prepare("SELECT name FROM master_pic
                                  WHERE property_id = ? AND status = 'active' AND role_name = ?
                                  ORDER BY name");
            $st->execute([$pid, $tahap['role_name']]);
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
