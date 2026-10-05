<?php

// jejakBeku() membekukan bentuk & posisi paraf ke dalam dokumen, jadi kelas
// Paraf harus sudah ada saat itu — bukan baru dimuat ketika dokumen dicetak.
require_once __DIR__ . '/Paraf.php';

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

    /** Tahap yang sedang menunggu, atau null bila rantainya memang kosong. */
    public static function currentStep(array $skp, array $steps): ?array
    {
        if (!$steps) return null;
        $lewat = (int) ($skp['approval_level'] ?? 0);
        // Tingkat yang melebihi jumlah tahap DITAHAN di tahap terakhir, bukan
        // dijadikan null. Dua hal bisa membuatnya melebihi: dokumen lama yang
        // di-backfill ke 99 oleh migrasi, dan Admin yang MEMENDEKKAN rantai
        // (mis. 2 tahap jadi 1) sementara ada dokumen yang sudah diparaf.
        // Kalau dibiarkan null, dokumen itu tidak bisa disetujui maupun
        // ditolak oleh siapa pun — tersangkut di 'submitted' tanpa satu tombol
        // pun, dan hanya bisa dibebaskan lewat database.
        if ($lewat >= count($steps)) return $steps[count($steps) - 1];
        return $steps[$lewat];               // index 0 = tahap ke-1
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
        // Diingat per request — penghitung antrean memanggil ini sekali per dokumen.
        static $ingat = [];
        $kunci = $uid . '@' . $pid;
        if (array_key_exists($kunci, $ingat)) return $ingat[$kunci];
        try {
            // Jabatan di properti ini lebih dulu. Kalau orangnya tidak terdaftar
            // sebagai PIC di sini, dipakai jabatannya di properti lain —
            // e-Walk dan Pentacity dikelola tim Casual Leasing yang sama, dan
            // Manager/Asst. Manager-nya memang satu orang untuk keduanya.
            // Ini tidak membuka akses: properti mana yang boleh dibuka tetap
            // ditentukan user_properties, dan izin approve_skp tetap diperiksa.
            $st = $pdo->prepare("SELECT role_name FROM master_pic
                                  WHERE user_id = ? AND status = 'active'
                                    AND COALESCE(role_name,'') <> ''
                                  ORDER BY (property_id = ?) DESC, property_id ASC, id ASC
                                  LIMIT 1");
            $st->execute([$uid, $pid]);
            return $ingat[$kunci] = trim((string) ($st->fetchColumn() ?: ''));
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
            // Urutan sama seperti jabatan(): properti ini dulu, baru properti
            // lain — supaya tahap yang dikunci ke satu orang tetap cocok walau
            // orangnya hanya terdaftar sebagai PIC di properti sebelah.
            $st = $pdo->prepare("SELECT name FROM master_pic
                                  WHERE user_id = ? AND status = 'active'
                                  ORDER BY (property_id = ?) DESC, property_id ASC, id ASC
                                  LIMIT 1");
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

        return self::cocokJabatan($pdo, $pid, $skp, $steps);
    }

    /**
     * Apakah JABATAN orang ini memang berhak atas tahap yang menunggu?
     *
     * Dipisah dari canAct karena superadmin/admin boleh bertindak atas apa pun —
     * kalau jalan pintas itu ikut dipakai menyusun daftar "giliran saya",
     * daftarnya berisi seluruh dokumen dan kehilangan gunanya.
     */
    public static function cocokJabatan(PDO $pdo, int $pid, array $skp, array $steps, bool $persis = false): bool
    {
        if (!$steps) return false;
        $tahap = self::currentStep($skp, $steps);
        if (!$tahap) return false;

        if ($tahap['pic_name'] !== null) {
            return strcasecmp(self::namaPic($pdo, $pid), $tahap['pic_name']) === 0;
        }
        $jab = self::jabatan($pdo, $pid);
        if ($jab === '') return false;
        if (strcasecmp($jab, $tahap['role_name']) === 0) return true;
        // $persis = daftar antrean. Atasan MASIH boleh memaraf bawahannya
        // (tombolnya tetap muncul lewat canAct), tetapi dokumen itu bukan
        // gilirannya — kalau ikut diantre, Manager melihat tumpukan dokumen
        // yang sebenarnya menunggu Asst. Manager dan angkanya kehilangan arti.
        if ($persis) return false;

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

    /**
     * Catat satu tindakan (paraf / setuju / tolak) ke jejak.
     *
     * $wajib = true dipakai saat pemanggilnya berada di dalam transaksi DB dan
     * jejak itu BAGIAN dari keputusan (mis. paraf tahap antara): kegagalannya
     * harus dilemparkan supaya ikut di-rollback, bukan ditelan jadi catatan log.
     */
    public static function record(
        PDO $pdo, int $pid, int $skpId, int $stepNo, ?string $roleName,
        string $action, string $note = '', bool $wajib = false
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
            if ($wajib) throw $e;
            error_log('skp_approvals gagal dicatat (skp=' . $skpId . '): ' . $e->getMessage());
        }
    }

    /** Jejak persetujuan satu dokumen (urut waktu). */
    public static function history(PDO $pdo, int $skpId): array
    {
        try {
            $st = $pdo->prepare('SELECT step_no, role_name, action, approver_user_id, approver_name, note, created_at
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
        $dari = count($steps);
        // Ditahan di tahap terakhir, sejalan dengan currentStep() — supaya tidak
        // pernah terbaca "tahap 2 dari 1" saat rantainya dipendekkan.
        $ke = min((int) ($skp['approval_level'] ?? 0) + 1, $dari);
        $kata = $ke === $dari ? 'persetujuan akhir' : 'paraf';
        return 'Menunggu ' . $kata . ' ' . $tahap['label'] . ' (tahap ' . $ke . ' dari ' . $dari . ')';
    }

    /**
     * Keterangan yang MENYEBUT NAMA orangnya, bukan hanya jabatannya.
     *
     * "Menunggu paraf Asst. Manager" masih menyisakan pertanyaan: siapa Asst.
     * Manager-nya, dan apakah dia tahu. "Masih menunggu paraf dari Asst.
     * Manager — Yusri Yusuf" langsung menunjuk orang yang harus dihubungi.
     * Kalau jabatan itu dipegang beberapa orang, semuanya disebut; kalau tidak
     * ada seorang pun, itu justru keadaan yang harus diteriakkan, bukan
     * disembunyikan di balik nama jabatan.
     */
    public static function keteranganNama(PDO $pdo, int $pid, array $skp, array $steps): string
    {
        if (($skp['status'] ?? '') !== 'submitted') return '';
        if (!$steps) {
            return 'Masih menunggu persetujuan Manager.';
        }
        $tahap = self::currentStep($skp, $steps);
        if (!$tahap) return 'Masih menunggu persetujuan akhir.';

        $dari = count($steps);
        $ke   = min((int) ($skp['approval_level'] ?? 0) + 1, $dari);
        $kata = $ke === $dari ? 'persetujuan' : 'paraf';

        $nama = self::penungguNama($pdo, $pid, $skp, $steps);
        $siapa = $nama
            ? ' — ' . implode(' atau ', $nama)
            : ' — belum ada orang aktif berjabatan ini di Master PIC';

        // Dipakai role_name, bukan label: kalimatnya menyebut SIAPA yang
        // ditunggu, dan label buatan admin ("Verifikasi Asst. Manager") membuat
        // kalimatnya berbunyi "paraf dari Verifikasi Asst. Manager".
        return 'Masih menunggu ' . $kata . ' dari ' . $tahap['role_name'] . $siapa
             . ' (tahap ' . $ke . ' dari ' . $dari . ').';
    }

    /**
     * Dokumen yang menunggu tindakan ORANG INI — inti perbaikan "sering miss".
     *
     * Tanpa ini pemeriksa harus membuka daftar SKP lalu menebak mana yang
     * gilirannya. Dipakai di Dashboard (panel "Perlu tindakan Anda") dan di
     * tab "Giliran Saya" pada daftar SKP.
     *
     * Mengembalikan daftar id dokumen, supaya pemanggilnya bisa sekaligus
     * menghitung jumlah dan menyaring tabel tanpa query kedua.
     */
    public static function antreanSaya(PDO $pdo, int $pid): array
    {
        if (!function_exists('can') || !can('approve_skp')) return [];
        try {
            $st = $pdo->prepare("SELECT id, status, doc_type, approval_level
                                   FROM skp_documents
                                  WHERE property_id = ? AND status = 'submitted'");
            $st->execute([$pid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $cacheAlur = [];
        $ids = [];
        foreach ($rows as $r) {
            $dt = (string) ($r['doc_type'] ?? 'skp');
            if (!array_key_exists($dt, $cacheAlur)) $cacheAlur[$dt] = self::steps($pdo, $pid, $dt);
            // Tanpa rantai, yang menunggu dokumen ini adalah PEMERIKSA-nya —
            // orang yang benar-benar terdaftar di Master PIC dengan sebuah
            // jabatan (Manager, Asst. Manager, ...). Bukan setiap pemegang izin
            // approve_skp: akun Super Admin dan Admin memegang semua izin tanpa
            // pernah memaraf apa pun, dan kalau ikut dihitung, panel "perlu
            // tindakan Anda" muncul di layar orang yang tidak punya urusan
            // dengan dokumen itu lalu berhenti dipercaya.
            //
            // Syaratnya sengaja TIDAK dibuat "harus ada rantai dulu": sebelum
            // alurnya diatur pun Asst. Manager & Manager memang pemeriksanya,
            // dan menyembunyikan antrean mereka sampai ada konfigurasi justru
            // membuat dokumen menumpuk tanpa ada yang merasa ditunggu.
            if (!$cacheAlur[$dt]) {
                if (self::jabatan($pdo, $pid) !== '') $ids[] = (int) $r['id'];
                continue;
            }
            if (self::cocokJabatan($pdo, $pid, $r, $cacheAlur[$dt], true)) $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    /**
     * Jejak yang dibekukan ke snapshot dokumen saat persetujuan akhir, supaya
     * cetakan & halaman validasi QR tetap menyebut pemaraf yang sama walaupun
     * konfigurasi alurnya kemudian diubah.
     */
    public static function jejakBeku(PDO $pdo, int $skpId, string $docType = 'skp'): array
    {
        $jejak = self::history($pdo, $skpId);
        // Hanya SIKLUS TERAKHIR yang dibekukan. Dokumen yang pernah ditolak lalu
        // diperbaiki menempuh alur dari awal lagi — paraf sebelum penolakan itu
        // menilai dokumen versi lama, jadi tidak boleh ikut tercetak di surat
        // yang diserahkan ke client seolah-olah memeriksa versi yang sekarang.
        // Pemotong putaran: penolakan ATAU perubahan alur ('ulang') sama-sama
        // membatalkan pemeriksaan sebelumnya.
        for ($i = count($jejak) - 1; $i >= 0; $i--) {
            if (in_array($jejak[$i]['action'] ?? '', ['tolak', 'ulang'], true)) {
                $jejak = array_slice($jejak, $i + 1);
                break;
            }
        }
        // Satu tahap bisa diparaf lebih dari sekali dalam putaran yang sama:
        // Manager mengembalikan ke Asst. Manager, lalu Asst. Manager memaraf
        // ulang. Yang dicetak di dokumen adalah paraf TERAKHIR tiap tahap —
        // bukan dua baris untuk orang yang sama.
        $perTahap = [];
        foreach ($jejak as $j) {
            $aksi = (string) ($j['action'] ?? '');
            if (in_array($aksi, ['tolak', 'ulang', 'kembali', 'batal'], true)) continue;
            $baris = [
                'step_no'   => (int) $j['step_no'],
                'role_name' => (string) ($j['role_name'] ?? ''),
                'action'    => $aksi,
                'nama'      => (string) ($j['approver_name'] ?? ''),
                'waktu'     => substr((string) ($j['created_at'] ?? ''), 0, 16),
            ];
            // Bentuk & posisi paraf IKUT DIBEKUKAN, bukan dibaca ulang saat
            // dokumen dicetak. Kalau dibaca ulang, pemeriksa yang menggeser
            // parafnya hari ini akan ikut menggeser paraf di semua dokumen yang
            // sudah terlanjur diserahkan ke client — padahal dokumen itu sudah
            // jadi dan tidak boleh berubah lagi.
            $uidParaf = (int) ($j['approver_user_id'] ?? 0);
            if ($uidParaf > 0 && class_exists('Paraf')) {
                $set = Paraf::ambil($pdo, $uidParaf, $docType);
                if (Paraf::siap($set)) {
                    $baris['paraf'] = [
                        'bentuk'      => $set['bentuk'],
                        'gambar_path' => $set['gambar_path'],
                        'teks'        => $set['teks'],
                        'pos_x'       => $set['pos_x'],
                        'pos_y'       => $set['pos_y'],
                        'lebar'       => $set['lebar'],
                        'tampil_nama' => $set['tampil_nama'],
                    ];
                }
            }
            $perTahap[(int) $j['step_no']] = $baris;
        }
        ksort($perTahap);
        return array_values($perTahap);
    }
    // ─── Permintaan revisi dokumen yang sudah disetujui ────────────────────
    //
    // Dokumen terkunci begitu disetujui — itu disengaja. Tetapi selama client
    // BELUM menandatangani, koreksi yang baru ketahuan lebih masuk akal
    // diperbaiki daripada dokumennya dibatalkan lalu dibuat ulang.

    /** Jabatan yang berwenang menyetujui permintaan revisi di properti ini. */
    public static function revisiTahap(PDO $pdo, int $pid, string $docType = 'skp'): ?array
    {
        try {
            $st = $pdo->prepare("SELECT role_name, pic_name FROM skp_revision_flow
                                  WHERE property_id = ? AND is_active = 1
                                    AND (doc_type IS NULL OR doc_type = '' OR doc_type = ?)
                                  ORDER BY (doc_type IS NULL) ASC, id ASC LIMIT 1");
            $st->execute([$pid, $docType]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;                       // tabel belum ada
        }
        if ($r && trim((string) $r['role_name']) !== '') {
            return [
                'role_name' => trim((string) $r['role_name']),
                'pic_name'  => trim((string) ($r['pic_name'] ?? '')) ?: null,
                'label'     => trim((string) $r['role_name']),
                'asal'      => 'diatur',
            ];
        }
        // Belum diatur → jatuh ke tahap PERTAMA rantai persetujuan (umumnya
        // Asst. Manager), sesuai permintaan: revisi masuk ke Asst. Manager.
        $alur = self::steps($pdo, $pid, $docType);
        if ($alur) {
            return $alur[0] + ['asal' => 'tahap pertama alur persetujuan'];
        }
        return null;                           // tanpa rantai → pemegang approve_skp
    }

    /** Permintaan revisi yang masih menunggu keputusan untuk satu dokumen. */
    public static function revisiPending(PDO $pdo, int $skpId): ?array
    {
        try {
            $st = $pdo->prepare("SELECT * FROM skp_revision_requests
                                  WHERE skp_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
            $st->execute([$skpId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Riwayat permintaan revisi satu dokumen (terbaru dulu). */
    public static function revisiRiwayat(PDO $pdo, int $skpId): array
    {
        try {
            $st = $pdo->prepare('SELECT * FROM skp_revision_requests WHERE skp_id = ? ORDER BY id DESC');
            $st->execute([$skpId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Bolehkah dokumen ini diajukan revisi?
     * Syaratnya: sudah disetujui, BELUM ditandatangani client, dan belum ada
     * permintaan revisi yang menggantung.
     */
    public static function bolehAjukanRevisi(array $skp): bool
    {
        return ($skp['status'] ?? '') === 'approved';
    }

    /** Bolehkah user yang sedang masuk MEMUTUSKAN permintaan revisi di properti ini? */
    public static function bolehPutusRevisi(PDO $pdo, int $pid, string $docType = 'skp', bool $persis = false): bool
    {
        if (!function_exists('can') || !can('approve_skp')) return false;
        $peran = function_exists('current_role') ? current_role() : '';
        if (in_array($peran, ['superadmin', 'admin'], true)) return !$persis;
        $tahap = self::revisiTahap($pdo, $pid, $docType);
        // Tanpa pengaturan, WEWENANG-nya tetap seperti perilaku lama: pemegang
        // approve_skp boleh memutuskan, supaya permintaan revisi tidak buntu.
        // Untuk ANTREAN ($persis) syaratnya lebih ketat — harus pemeriksa yang
        // terdaftar berjabatan di Master PIC, sejalan dengan antreanSaya().
        if (!$tahap) return !$persis || self::jabatan($pdo, $pid) !== '';
        if ($tahap['pic_name'] !== null) {
            return strcasecmp(self::namaPic($pdo, $pid), $tahap['pic_name']) === 0;
        }
        $jab = self::jabatan($pdo, $pid);
        if ($jab === '') return false;
        if (strcasecmp($jab, $tahap['role_name']) === 0) return true;
        // $persis = daftar antrean: hanya jabatan yang ditunjuk yang dihitung,
        // supaya angka "giliran saya" milik atasan tidak ikut membengkak.
        if ($persis) return false;
        // Atasan dalam rantai yang sama boleh mewakili, seperti pada paraf —
        // supaya permintaan revisi tidak menggantung saat yang bersangkutan
        // berhalangan.
        $alur = self::steps($pdo, $pid, $docType);
        $n = count($alur);
        for ($i = 0; $i < $n; $i++) {
            if (strcasecmp($alur[$i]['role_name'], $tahap['role_name']) !== 0) continue;
            for ($k = $i + 1; $k < $n; $k++) {
                if (strcasecmp($jab, $alur[$k]['role_name']) === 0) return true;
            }
            break;
        }
        return false;
    }

    /** Dokumen yang permintaan revisinya menunggu keputusan ORANG INI. */
    public static function antreanRevisiSaya(PDO $pdo, int $pid): array
    {
        if (!function_exists('can') || !can('approve_skp')) return [];
        try {
            $st = $pdo->prepare("SELECT r.skp_id, d.doc_type
                                   FROM skp_revision_requests r
                                   JOIN skp_documents d ON d.id = r.skp_id
                                  WHERE r.property_id = ? AND r.status = 'pending'");
            $st->execute([$pid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $ids = [];
        foreach ($rows as $r) {
            if (self::bolehPutusRevisi($pdo, $pid, (string) ($r['doc_type'] ?? 'skp'), true)) $ids[] = (int) $r['skp_id'];
        }
        return $ids;
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
            // Lintas properti, sejalan dengan jabatan(): Manager & Asst. Manager
            // Casual Leasing sama untuk e-Walk dan Pentacity, tetapi di Master
            // PIC hanya terdaftar di salah satunya. Dibatasi per properti,
            // keterangan "menunggu paraf" jadi tanpa nama di mal yang lain.
            // Diingat per request: daftar SKP memanggil ini sekali per baris,
            // dan jabatan yang ditanyakan hampir selalu itu-itu juga.
            static $ingat = [];
            $kunci = $pid . '/' . $tahap['role_name'];
            if (array_key_exists($kunci, $ingat)) return $ingat[$kunci];
            $st = $pdo->prepare("SELECT DISTINCT name FROM master_pic
                                  WHERE status = 'active' AND role_name = ?
                                  ORDER BY (property_id = ?) DESC, name");
            $st->execute([$tahap['role_name'], $pid]);
            return $ingat[$kunci] = ($st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }
}
