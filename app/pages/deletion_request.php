<?php

/**
 * Form Pengajuan Penghapusan Data.
 *
 * Satu halaman, tiga wajah — isinya menyesuaikan siapa yang membuka:
 *
 *   PIC         formulir pengajuan + daftar pengajuannya sendiri
 *   Pemutus     pengajuan yang menunggu keputusannya
 *   Superadmin  pengaturan siapa pemutusnya
 *   Semua       rekap per PIC dan riwayat
 *
 * Dibuat satu halaman, bukan tiga menu, karena tiap orang hanya memakai satu
 * bagian; tiga menu hanya memanjangkan sidebar tanpa guna.
 *
 * Alurnya DUA tangan: PIC mengajukan, pemutus menyetujui — dan persetujuan itu
 * langsung menghapus. Dulu ada tangan ketiga (superadmin menekan tombol hapus),
 * tetapi langkah itu tidak menambah pengamanan apa pun: keputusannya sudah
 * diambil, dan yang tersisa hanya penundaan. Pengamanannya dipindah ke arah
 * sebaliknya — tombol Pulihkan, yang mengembalikan dokumen, transaksi dan
 * alokasi income sekaligus.
 *
 * Penghapusannya sendiri memakai mekanisme yang sudah ada: transaksi
 * di-soft-delete (deleted_at) sehingga hilang dari Exhibition/Media/Gudang,
 * dan alokasi bulanannya dilepas sehingga income PIC berkurang persis sebesar
 * nilai dokumennya. Dokumennya tidak dibuang dari basis data — nomornya sudah
 * terbit dan pernah sampai ke client, jadi jejaknya harus tetap bisa dibuka.
 */

require_once dirname(__DIR__) . '/ApprovalLine.php';

/** Siapa pemutus penghapusan di properti ini. */
function _dr_pemutus(PDO $pdo, int $pid): array
{
    $st = $pdo->prepare('SELECT role_name, pic_name FROM deletion_approver WHERE property_id = ?');
    $st->execute([$pid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['role_name' => '', 'pic_name' => null];
}

/**
 * Apakah orang yang sedang masuk berhak memutuskan pengajuan di properti ini?
 *
 * Superadmin & admin selalu boleh — sama seperti perlakuan can() di seluruh
 * aplikasi. Selain itu jabatannya harus cocok dengan yang disetel, dan bila
 * pengaturannya menyebut nama tertentu, harus orang itu.
 */
function _dr_boleh_putus(PDO $pdo, int $pid): bool
{
    if (!can('approve_delete')) return false;
    $peran = current_role();
    if (in_array($peran, ['superadmin', 'admin'], true)) return true;

    $set = _dr_pemutus($pdo, $pid);
    if (($set['role_name'] ?? '') === '') return false;

    $jabatan = ApprovalLine::jabatan($pdo, $pid);
    if ($jabatan === '' || strcasecmp($jabatan, $set['role_name']) !== 0) return false;

    $khusus = trim((string) ($set['pic_name'] ?? ''));
    if ($khusus === '') return true;
    return strcasecmp(ApprovalLine::namaPic($pdo, $pid), $khusus) === 0;
}

/**
 * Dokumen yang boleh DIAJUKAN untuk dihapus oleh orang yang sedang masuk.
 *
 * Patokannya ATAS NAMA SIAPA, bukan siapa yang mengetik. Yang dihapus itu
 * income milik seseorang; yang berhak memintanya hilang adalah pemilik income
 * itu. Kalau patokannya "siapa yang membuat", seorang sales yang kebetulan
 * mengetikkan dokumen untuk rekannya bisa memotong income rekan itu — dan
 * rekannya tidak akan pernah tahu sampai laporannya berkurang.
 *
 * Dokumen yang PIC-nya belum terisi tetap bisa diajukan pembuatnya, karena
 * tidak ada orang lain yang bisa mengurusnya.
 *
 * Superadmin melihat semuanya. Dokumen yang pengajuannya masih berjalan atau
 * sudah dihapus tidak ikut, supaya tidak ada pengajuan ganda untuk satu berkas.
 */
function _dr_dokumen_saya(PDO $pdo, int $pid): array
{
    $peran = current_role();
    $uname = (string) ($_SESSION['user']['name'] ?? '');
    $pic   = ApprovalLine::namaPic($pdo, $pid);
    $semua = in_array($peran, ['superadmin', 'admin'], true);
    $akuPic = $pic !== '' ? $pic : "\0";   // "\0" = tidak mungkin cocok

    // ── A. Dokumen (SKP / SKS / Form Utilities) ──────────────────────────────
    $where = ['s.property_id = ?', 's.deleted_at IS NULL'];
    $par   = [$pid];
    if (!$semua) {
        // Pemilik income sesungguhnya ada di TRANSAKSINYA, bukan hanya di
        // dokumen. Banyak dokumen dibiarkan tanpa pic_name padahal transaksinya
        // jelas atas nama seseorang — kalau hanya kolom dokumen yang dicocokkan,
        // sebagian besar transaksi orang itu tidak pernah muncul di sini.
        $punyaTrx = "EXISTS (SELECT 1 FROM transactions tx
                              WHERE tx.property_id = s.property_id AND tx.deleted_at IS NULL
                                AND (tx.skp_id = s.id OR tx.id = s.transaction_id)
                                AND tx.pic_name = ?)";
        $where[] = "(s.pic_name = ? OR $punyaTrx OR (COALESCE(s.pic_name, '') = '' AND s.created_by = ?))";
        $par[] = $akuPic;
        $par[] = $akuPic;
        $par[] = $uname;
    }
    $where[] = "NOT EXISTS (SELECT 1 FROM deletion_requests d
                             WHERE d.skp_id = s.id AND d.status IN ('menunggu','disetujui','dihapus'))";

    // Kode unit, periode dan NILAI tidak selalu ada di baris dokumennya sendiri.
    // Dokumen Gudang/Media yang berdiri sendiri menyimpannya di sana, tetapi
    // dokumen perpanjangan dan dokumen dari Surat Penawaran menyimpannya di
    // transaksi / penawarannya — kolom di skp_documents dibiarkan kosong.
    //
    // Nilainya DIJUMLAHKAN dari transaksinya, bukan diambil satu: penawaran
    // paket melahirkan beberapa transaksi di bawah satu dokumen, dan yang akan
    // hilang dari income adalah jumlah semuanya.
    $nilaiSql = '(SELECT SUM(COALESCE(NULLIF(t2.final_amount, 0), t2.total_calculated))
                    FROM transactions t2
                   WHERE t2.property_id = s.property_id AND t2.deleted_at IS NULL
                     AND (t2.skp_id = s.id OR t2.id = s.transaction_id))';

    // Nilai kontrak BELUM TENTU sama dengan income yang sedang duduk di laporan.
    // Dokumen draft atau yang belum ditandatangani client memang belum punya
    // baris alokasi sama sekali — menghapusnya tidak mengurangi income siapa pun.
    $incomeSql = '(SELECT COALESCE(SUM(a.amount), 0)
                     FROM transaction_allocations a
                     JOIN transactions t3 ON t3.id = a.transaction_id
                    WHERE t3.property_id = s.property_id AND t3.deleted_at IS NULL
                      AND (t3.skp_id = s.id OR t3.id = s.transaction_id))';

    $sql = 'SELECT s.id, s.skp_no, s.doc_type, s.status, s.pic_name, s.transaction_id,
                   COALESCE(s.module, t.module, o.module)                   AS module,
                   COALESCE(s.master_code, t.master_code, o.master_code)    AS master_code,
                   COALESCE(s.start_date, t.start_date, o.start_date)       AS start_date,
                   COALESCE(s.end_date, t.end_date, o.end_date)             AS end_date,
                   COALESCE(' . $nilaiSql . ', NULLIF(s.total_amount, 0),
                            NULLIF(o.total_calculated, 0), 0)               AS total_amount,
                   ' . $incomeSql . '                                       AS income_aktif,
                   COALESCE(c.company_name, tc.company_name, oc.company_name) AS client_name
              FROM skp_documents s
              LEFT JOIN master_clients c  ON c.id = s.client_id
              LEFT JOIN transactions t    ON t.id = s.transaction_id
              LEFT JOIN master_clients tc ON tc.id = t.client_id
              LEFT JOIN offers o          ON o.id = s.offer_id
              LEFT JOIN master_clients oc ON oc.id = o.client_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY s.id ASC LIMIT 1000';
    $st = $pdo->prepare($sql);
    $st->execute($par);

    $hasil = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['jenis_baris'] = 'dok';
        $r['grup']        = 'dok';
        $r['kunci']       = 's:' . (int) $r['id'];
        $r['trx_id']      = (int) ($r['transaction_id'] ?? 0);
        $hasil[] = $r;
    }

    // ── B. Transaksi yang BELUM punya dokumen sama sekali ────────────────────
    //
    // Inilah sebagian besar data yang sebenarnya perlu bisa dihapus: input
    // langsung dari menu Exhibition/Media/Gudang, tanpa pernah dibuatkan
    // SKP/SKS. Sebelum ini tidak ada satu pun cara mengajukannya — seluruh
    // Gudang, misalnya, tidak pernah muncul karena memang tidak berdokumen.
    $w2  = ['t.property_id = ?', 't.deleted_at IS NULL'];
    $p2  = [$pid];
    if (!$semua) {
        $w2[] = '(t.pic_name = ? OR (COALESCE(t.pic_name, \'\') = \'\' AND t.created_by = ?))';
        $p2[] = $akuPic;
        $p2[] = $uname;
    }
    // Tanpa dokumen: tidak ada skp_documents yang menunjuk transaksi ini, dan
    // transaksi ini tidak menunjuk dokumen mana pun.
    $w2[] = "NOT EXISTS (SELECT 1 FROM skp_documents s2
                          WHERE s2.property_id = t.property_id AND s2.deleted_at IS NULL
                            AND (s2.transaction_id = t.id OR s2.id = t.skp_id))";
    $w2[] = "NOT EXISTS (SELECT 1 FROM deletion_requests d2
                          WHERE d2.skp_id IS NULL AND d2.transaction_id = t.id
                            AND d2.status IN ('menunggu','disetujui','dihapus'))";

    // Diambil TERPISAH per modul, masing-masing dengan batasnya sendiri.
    // Sebelumnya satu batas dipakai bersama dan diurut dari ID terbesar —
    // akibatnya ratusan transaksi Exhibition yang ID-nya baru menenggelamkan
    // seluruh Gudang, yang ID-nya justru lama. Gudang jadi tidak pernah tampil.
    $sql2 = "SELECT t.id AS trx_id, t.module, t.master_code, t.start_date, t.end_date,
                    t.pic_name, COALESCE(NULLIF(t.final_amount, 0), t.total_calculated) AS total_amount,
                    (SELECT COALESCE(SUM(a2.amount), 0) FROM transaction_allocations a2
                      WHERE a2.transaction_id = t.id) AS income_aktif,
                    tc2.company_name AS client_name
               FROM transactions t
               LEFT JOIN master_clients tc2 ON tc2.id = t.client_id
              WHERE " . implode(' AND ', $w2) . " AND t.module = ?
              ORDER BY t.id ASC LIMIT 1000";
    $st2 = $pdo->prepare($sql2);

    $baris2 = [];
    foreach (['cl', 'media', 'gudang'] as $mod) {
        $st2->execute(array_merge($p2, [$mod]));
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['grup'] = $mod; $baris2[] = $r; }
    }

    foreach ($baris2 as $r) {
        $hasil[] = [
            'grup'           => $r['grup'],
            'jenis_baris'    => 'trx',
            'kunci'          => 't:' . (int) $r['trx_id'],
            'id'             => (int) $r['trx_id'],
            'trx_id'         => (int) $r['trx_id'],
            'transaction_id' => (int) $r['trx_id'],
            'skp_no'         => null,
            'doc_type'       => null,
            // Transaksi tanpa dokumen tidak punya status approval — statusnya
            // memang "belum berdokumen", dan itu yang ditulis apa adanya.
            'status'         => 'tanpa_dokumen',
            'module'         => $r['module'],
            'master_code'    => $r['master_code'],
            'start_date'     => $r['start_date'],
            'end_date'       => $r['end_date'],
            'total_amount'   => $r['total_amount'],
            'income_aktif'   => $r['income_aktif'],
            'client_name'    => $r['client_name'],
            'pic_name'       => $r['pic_name'],
        ];
    }

    // Urutan tampil: dokumen dulu, lalu Exhibition, Media, Gudang — masing-masing
    // dari ID terkecil ke terbesar, supaya nomor yang dicari bisa ditelusuri
    // berurutan dan tidak melompat-lompat antar modul.
    $urutanGrup = ['dok' => 0, 'cl' => 1, 'media' => 2, 'gudang' => 3];
    usort($hasil, function (array $a, array $b) use ($urutanGrup) {
        return [$urutanGrup[$a['grup']] ?? 9, (int) $a['id']]
           <=> [$urutanGrup[$b['grup']] ?? 9, (int) $b['id']];
    });
    return $hasil;
}

/**
 * Jenis alasan penghapusan.
 *
 * Dipilih dari daftar, bukan ditulis bebas, supaya rekap "siapa paling sering
 * bikin dobel" bisa dihitung. Teks alasannya tetap ada dan tetap wajib — itu
 * yang dibaca pemutus; jenis ini hanya untuk menghitung.
 */
function _dr_jenis_alasan(): array
{
    return [
        'dobel'       => 'Dobel / duplikat',
        'salah_input' => 'Salah input data',
        'batal'       => 'Client batal',
        'lainnya'     => 'Lainnya',
    ];
}

/**
 * Income yang SEDANG duduk di laporan untuk tiap dokumen — bukan nilai kontraknya.
 *
 * Dibaca dari baris alokasi, satu-satunya tempat income benar-benar dihitung.
 * Dokumen yang belum ditandatangani client tidak punya baris alokasi sama sekali,
 * jadi hasilnya 0: menghapusnya tidak mengurangi income siapa pun. Angka inilah
 * yang boleh dipakai untuk memperingatkan, bukan nilai kontrak.
 *
 * Menangani dua jenis pengajuan: yang menunjuk dokumen (beserta seluruh
 * transaksi di bawahnya) dan yang menunjuk satu transaksi tanpa dokumen.
 *
 * Mengembalikan [id pengajuan => jumlah rupiah].
 */
function _dr_income_berjalan(PDO $pdo, int $pid, array $baris): array
{
    $out = [];
    $dok = $pdo->prepare(
        "SELECT COALESCE(SUM(a.amount), 0)
           FROM transactions t
           LEFT JOIN transaction_allocations a ON a.transaction_id = t.id
          WHERE t.property_id = ? AND t.deleted_at IS NULL
            AND (t.skp_id = ? OR t.id = (SELECT s.transaction_id FROM skp_documents s WHERE s.id = ?))"
    );
    $trx = $pdo->prepare(
        "SELECT COALESCE(SUM(a.amount), 0) FROM transaction_allocations a
          WHERE a.property_id = ? AND a.transaction_id = ?"
    );
    foreach ($baris as $r) {
        $id    = (int) $r['id'];
        $skpId = (int) ($r['skp_id'] ?? 0);
        if ($skpId > 0) { $dok->execute([$pid, $skpId, $skpId]); $out[$id] = (float) $dok->fetchColumn(); }
        else            { $trx->execute([$pid, (int) ($r['transaction_id'] ?? 0)]); $out[$id] = (float) $trx->fetchColumn(); }
    }
    return $out;
}

/** Nama modul sebagaimana dipakai di sidebar — bukan kode basis datanya. */
function _dr_modul(?string $m): string
{
    return match ((string) $m) {
        'cl'     => 'Exhibition',
        'gudang' => 'Gudang',
        'media'  => 'Media',
        default  => '—',
    };
}

/** Label jenis dokumen untuk ditampilkan. */
function _dr_jenis(?string $docType): string
{
    return match ((string) $docType) { 'sks' => 'SKS', 'fu' => 'FU', default => 'SKP' };
}

/** Penanda kecil jenis alasan, untuk ditempel di depan teks alasannya. */
function _dr_tanda_jenis(?string $jenis): string
{
    $label = _dr_jenis_alasan()[(string) $jenis] ?? 'Lainnya';
    $warna = ((string) $jenis) === 'dobel' ? ['#9a3412', '#ffedd5'] : ['#334155', '#f1f5f9'];
    return '<span style="background:' . $warna[1] . ';color:' . $warna[0]
         . ';padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;margin-right:4px">'
         . h($label) . '</span>';
}

/**
 * Status DOKUMEN SKP-nya (bukan status pengajuan) — ditampilkan di daftar
 * pilihan supaya PIC tahu apa yang sedang dia ajukan: dokumen draft yang belum
 * jalan ke mana-mana berbeda bobotnya dengan dokumen yang sudah diteken client.
 */
function _dr_badge_skp(string $status): string
{
    [$teks, $warna, $latar] = match ($status) {
        'draft'     => ['Draft', '#334155', '#f1f5f9'],
        'submitted' => ['Menunggu Approval', '#92400e', '#fef3c7'],
        'approved'  => ['Disetujui', '#065f46', '#d1fae5'],
        'signed'    => ['Ber-TTD Client', '#1d4ed8', '#dbeafe'],
        'rejected'  => ['Dikembalikan', '#991b1b', '#fee2e2'],
        'tanpa_dokumen' => ['Tanpa dokumen', '#5b21b6', '#ede9fe'],
        default     => [$status !== '' ? $status : '—', '#334155', '#f1f5f9'],
    };
    return '<span class="dr-pil" style="background:' . $latar . ';color:' . $warna . '">' . h($teks) . '</span>';
}

/** Warna & label status pengajuan. */
function _dr_badge(string $status): string
{
    [$teks, $warna, $latar] = match ($status) {
        'menunggu'  => ['Menunggu Keputusan', '#92400e', '#fef3c7'],
        'disetujui' => ['Disetujui — sisa alur lama', '#065f46', '#d1fae5'],
        'batal'     => ['Ditolak', '#991b1b', '#fee2e2'],
        'dihapus'   => ['Sudah Dihapus', '#1e293b', '#e2e8f0'],
        'dipulihkan'=> ['Dipulihkan', '#1d4ed8', '#dbeafe'],
        default     => [$status, '#334155', '#f1f5f9'],
    };
    return '<span style="background:' . $latar . ';color:' . $warna
         . ';padding:2px 8px;border-radius:9px;font-size:11px;font-weight:700;white-space:nowrap">'
         . h($teks) . '</span>';
}

// ─── Halaman ────────────────────────────────────────────────────────────────

function deletion_request_page(PDO $pdo): void
{
    require_permission('request_delete');
    $pid   = current_property_id();
    $peran = current_role();
    $bolehPutus = _dr_boleh_putus($pdo, $pid);
    $bolehHapus = can('manage_deleted');
    $uid   = (int) ($_SESSION['user']['id'] ?? 0);

    $set = _dr_pemutus($pdo, $pid);
    $dokumen = _dr_dokumen_saya($pdo, $pid);

    $ambil = function (string $where, array $par) use ($pdo): array {
        $st = $pdo->prepare('SELECT * FROM deletion_requests WHERE ' . $where . ' ORDER BY id DESC LIMIT 200');
        $st->execute($par);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $punyaSaya = $ambil('property_id = ? AND requested_user_id = ?', [$pid, $uid]);
    $perluPutus = $bolehPutus ? $ambil("property_id = ? AND status = 'menunggu'", [$pid]) : [];
    $siapHapus  = $bolehHapus ? $ambil("property_id = ? AND status = 'disetujui'", [$pid]) : [];
    $riwayat    = $ambil("property_id = ? AND status IN ('dihapus','batal','dipulihkan')", [$pid]);
    // Income yang benar-benar berjalan untuk tiap pengajuan yang menunggu —
    // dibaca sekarang, bukan dari salinan saat diajukan, karena di antara
    // pengajuan dan keputusan dokumennya bisa saja baru ditandatangani client.
    $incomeKini = _dr_income_berjalan($pdo, $pid, $perluPutus);
    // Boleh memulihkan: pemutus atau pemegang manage_deleted — aturan yang sama
    // dengan yang diperiksa deletion_request_restore().
    $bolehPulih = $bolehHapus || $bolehPutus;

    // Rekap per PIC — dilihat SEMUA orang yang bisa membuka halaman ini, bukan
    // hanya superadmin. Terbuka begitu justru gunanya: orang tahu catatannya
    // sendiri terlihat rekan sekerja, dan itu menekan kebiasaan asal input
    // lebih efektif daripada teguran.
    $rk = $pdo->prepare(
        "SELECT COALESCE(NULLIF(TRIM(pic_name),''),'(tanpa PIC)') AS pic,
                COUNT(*)                                                   AS total,
                SUM(jenis = 'dobel')                                       AS dobel,
                SUM(jenis = 'salah_input')                                 AS salah_input,
                SUM(jenis = 'batal')                                       AS batal,
                SUM(jenis = 'lainnya')                                     AS lainnya,
                SUM(status = 'dihapus')                                    AS jadi_dihapus,
                SUM(status = 'batal')                                      AS ditolak,
                COALESCE(SUM(CASE WHEN status = 'dihapus' THEN nilai END), 0) AS nilai_dihapus
           FROM deletion_requests
          WHERE property_id = ?
          GROUP BY pic
          ORDER BY dobel DESC, total DESC, nilai_dihapus DESC"
    );
    $rk->execute([$pid]);
    $rekap = $rk->fetchAll(PDO::FETCH_ASSOC);

    // Angka ringkasan untuk dashboard kecil di atas halaman. Dihitung satu kueri
    // supaya membuka halaman ini tidak menambah beban yang terasa.
    $rs = $pdo->prepare(
        "SELECT COUNT(DISTINCT CASE WHEN status = 'menunggu'
                    THEN COALESCE(NULLIF(batch_no, ''), CONCAT('x', id)) END)      AS n_menunggu,
                SUM(status = 'menunggu')                                            AS dok_menunggu,
                SUM(status = 'dihapus')                                             AS dok_dihapus,
                SUM(status = 'dipulihkan')                                          AS dok_pulih,
                SUM(status = 'batal')                                               AS dok_tolak,
                COALESCE(SUM(CASE WHEN status = 'dihapus' THEN rupiah_dilepas END), 0) AS nilai_hilang,
                COALESCE(SUM(CASE WHEN status = 'dihapus' THEN nilai END), 0)       AS kontrak_hilang
           FROM deletion_requests WHERE property_id = ?"
    );
    $rs->execute([$pid]);
    $stat = $rs->fetch(PDO::FETCH_ASSOC) ?: [];

    // Jabatan yang bisa dipilih sebagai pemutus — diambil dari Master PIC aktif,
    // sama seperti halaman Alur Approval Dokumen.
    $jabatan = [];
    if ($bolehHapus) {
        $jq = $pdo->prepare("SELECT DISTINCT role_name FROM master_pic
                              WHERE property_id = ? AND status = 'active'
                                AND COALESCE(role_name,'') <> '' ORDER BY role_name");
        $jq->execute([$pid]);
        $jabatan = $jq->fetchAll(PDO::FETCH_COLUMN);
    }

    layout('Pengajuan Hapus Data', function () use (
        $dokumen, $punyaSaya, $perluPutus, $siapHapus, $riwayat, $rekap,
        $bolehPutus, $bolehHapus, $bolehPulih, $set, $jabatan, $stat, $incomeKini
    ) {
        $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $tgl = function (?string $t): string {
            $t = trim((string) $t);
            return $t !== '' ? date('d/m/y H:i', strtotime($t)) : '—';
        };
        // Sel dokumen dipakai beberapa tabel: nomor di atas, client & unit di
        // bawahnya. data-urut diisi nilai mentah supaya pengurutan kolom tidak
        // ikut format tampilan.
        $selDok = function (array $d): string {
            $no = (string) ($d['doc_no'] ?? ($d['skp_no'] ?? ''));
            if ($no === '') {
                $no = (int) ($d['skp_id'] ?? 0) > 0
                    ? 'draft #' . (int) $d['skp_id']
                    : 'Transaksi #' . (int) ($d['transaction_id'] ?? $d['id']);
            }
            return '<td data-urut="' . h($no) . '"><b>' . h($no) . '</b>'
                 . '<div class="muted" style="font-size:11px">' . h($d['client_name'] ?: '—') . '</div></td>';
        };
        ?>
        <style>
        /* Gaya khusus halaman ini. Memakai token aplikasi (--line, --ink, --muted,
           --primary, --r) supaya tetap satu bahasa dengan halaman lain — tidak
           ada warna atau radius baru yang diperkenalkan di luar palet grafik. */

        /* ── Grafik "siapa paling sering" ──────────────────────────────────
           Empat warna kategori, urutannya tetap — tidak pernah diputar ulang
           saat jumlah seri berubah. Lolos pemeriksaan keterbacaan buta warna
           (ΔE terburuk 9,1 protan). Kontras tiga di antaranya di bawah 3:1
           terhadap latar putih, jadi legenda dan tabel rinciannya WAJIB ada —
           warna tidak pernah jadi satu-satunya penanda.
           Aplikasi ini tidak punya mode gelap, jadi tidak ada langkah gelapnya. */
        .panel, .dr-graf { --dr-dobel:#2a78d6; --dr-salah_input:#eb6834; --dr-batal:#1baf7a; --dr-lainnya:#eda100 }
        .dr-graf i, .dr-seg { background-clip:padding-box }
        .dr-grow  { display:flex; align-items:center; gap:12px; margin-bottom:9px }
        .dr-nama  { width:150px; flex:none; font-size:12.5px; font-weight:600; color:var(--ink2);
                    text-align:right; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
        .dr-track { display:flex; gap:2px; height:20px; min-width:3px;
                    transition:filter .12s ease }
        .dr-grow:hover .dr-track { filter:saturate(1.15) }
        .dr-seg   { min-width:3px; border-radius:2px }
        .dr-track > .dr-seg:first-child { border-radius:5px 2px 2px 5px }
        .dr-track > .dr-seg:last-child  { border-radius:2px 5px 5px 2px }
        .dr-track > .dr-seg:only-child  { border-radius:5px }
        .dr-tot   { font-size:13px; font-weight:800; color:var(--ink); min-width:24px }
        .dr-legenda { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:14px }
        .dr-legenda span { display:inline-flex; align-items:center; gap:7px; font-size:12px; color:var(--ink2) }
        .dr-legenda i { width:11px; height:11px; border-radius:3px; display:inline-block; flex:none }

        /* ── Kepala bagian: judul + alat bantu di satu baris ──────────────── */
        .dr-kepala { display:flex; gap:12px; align-items:baseline; justify-content:space-between;
                     flex-wrap:wrap; margin-bottom:6px }
        .dr-kepala h3 { margin:0 }

        /* ── Baris alat: pencarian, saringan, jumlah ──────────────────────── */
        .dr-bar  { display:flex; gap:10px; align-items:center; flex-wrap:wrap;
                   background:#F8FAFC; border:1px solid var(--line); border-radius:var(--r);
                   padding:9px 11px; margin-bottom:10px }
        .dr-bar input, .dr-bar select { height:36px; padding:0 11px }
        .dr-cari { width:100%; max-width:300px }
        .dr-hitung { font-size:12px; color:var(--ink2); font-weight:600; white-space:nowrap }
        .dr-petunjuk { font-size:11.5px; color:var(--muted); margin-left:auto; white-space:nowrap }

        /* ── Tabel ─────────────────────────────────────────────────────────── */
        /* Di layar sempit tabelnya DIGULIR mendatar, bukan diremas: sembilan kolom
           dalam 360px membuat tiap sel pecah jadi beberapa baris dan angkanya
           tidak bisa dibandingkan lagi. */
        .dr-tabel { min-width:900px }
        .dr-tabel td, .dr-tabel th { padding:9px 12px }
        .dr-tabel th[data-sort] { cursor:pointer; user-select:none; transition:background .12s ease }
        .dr-tabel th[data-sort]:hover { background:#E7EDF5 }
        .dr-tabel tbody tr:hover td { background:#F7FAFC }
        /* Baris yang dicentang diberi warna & garis kiri: saat menggulir daftar
           panjang, pilihan yang sudah dibuat harus tetap terlihat sekilas. */
        .dr-tabel tr:has(.dr-pick:checked) td { background:#FEF2F2 }
        .dr-tabel tr:has(.dr-pick:checked) td:first-child { box-shadow:inset 3px 0 0 #dc2626 }
        .dr-tabel tr:has(.dr-pick:checked):hover td { background:#FEE7E7 }
        /* .dr-pick dipakai JS untuk menghitung pilihan, jadi kotak "pilih semua"
           memakai kelas lain meski tampilannya sama. */
        .dr-pick, .dr-kotak { width:16px; height:16px; margin:0; cursor:pointer; accent-color:#dc2626 }

        /* Pemisah golongan: menempel di atas saat digulir supaya selalu jelas
           sedang berada di bagian mana. */
        .dr-judul-grup td { background:#EDF1F7; font-size:11px; font-weight:800; color:#334155;
                            text-transform:uppercase; letter-spacing:.05em; padding:8px 12px;
                            position:sticky; top:0; z-index:2;
                            border-top:1px solid var(--line); border-bottom:1px solid #DCE3EC }
        .dr-judul-grup:first-child td { border-top:none }
        .dr-judul-grup:hover td { background:#EDF1F7 }

        /* Kotak gulir daftar: tinggi lega, dan bayangan tipis di bawah sebagai
           tanda masih ada isi di bawahnya. */
        .dr-gulir { max-height:430px; overflow:auto; position:relative }
        .dr-gulir::after { content:''; position:sticky; bottom:0; display:block; height:22px;
                           background:linear-gradient(to top, rgba(255,255,255,.95), rgba(255,255,255,0));
                           pointer-events:none; margin-top:-22px }

        /* ── Ringkasan pilihan: kotak, bukan teks lepas ───────────────────── */
        .dr-ringkas { margin-top:10px; border-radius:var(--r); padding:11px 13px; font-size:12.5px;
                      line-height:1.6; border:1px solid transparent }
        .dr-ringkas:empty { display:none }
        .dr-ringkas.ada  { background:#FEF2F2; border-color:#FECACA; color:#991b1b }
        .dr-ringkas.nol  { background:#F8FAFC; border-color:var(--line); color:var(--ink2) }

        /* ── Kaki formulir: tindakan utama dipisah garis ──────────────────── */
        .dr-kaki { display:flex; gap:12px; align-items:center; flex-wrap:wrap;
                   border-top:1px solid var(--line); margin-top:15px; padding-top:14px }

        /* Grid isian. Kelas .grid2 yang dipakai sebelumnya tidak pernah ada di
           app.css, jadi semua isian menumpuk selebar panel. */
        .dr-isian  { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,2fr); gap:16px; margin-top:14px }
        .dr-isian2 { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:16px }
        .dr-isian2 .wide { grid-column:1 / -1 }
        @media (max-width: 760px) {
            .dr-isian, .dr-isian2 { grid-template-columns:1fr }
        }

        /* Keterangan panjang di bawah tabel: dibuat tenang supaya tidak bersaing
           dengan angka dan tombol di sekitarnya. */
        .dr-nota { font-size:11.5px; line-height:1.65; color:var(--muted); margin:8px 0 0 }
        .dr-nota strong { color:var(--ink2); font-weight:700 }

        .dr-kosong { padding:16px 2px; color:var(--muted); font-size:12.5px }
        .dr-pil { display:inline-block; padding:2px 8px; border-radius:9px; font-size:10.5px;
                  font-weight:700; white-space:nowrap; line-height:1.5 }
        .dr-jejak { font-size:11px; line-height:1.65; max-width:340px }
        .dr-jejak b { color:var(--ink2) }

        @media (max-width: 820px) {
            .dr-cari { max-width:none }
            .dr-petunjuk { display:none }
            .dr-nama { width:100px }
        }
        /* Di bawah 768px aplikasi mengubah tabel jadi kartu bertumpuk
           (mobile-tables.js). Kotak gulir setinggi 430px jadi tidak masuk akal:
           satu kartu saja hampir setinggi itu. Dibiarkan mengalir di halaman. */
        @media (max-width: 768px) {
            .dr-gulir { max-height:none; overflow:visible }
            .dr-gulir::after { display:none }
            .dr-judul-grup td { position:static }
        }
        </style>

        <?php /* ── 1. Dashboard kecil + rekap per PIC ─────────────────────── */ ?>
        <div class="grid grid-4" style="margin-bottom:14px">
            <div class="card">
                <div class="kpi-label">Menunggu Keputusan</div>
                <div class="kpi-value"><?= (int) ($stat['n_menunggu'] ?? 0) ?></div>
                <div class="help" style="margin-top:3px"><?= (int) ($stat['dok_menunggu'] ?? 0) ?> dokumen</div>
            </div>
            <div class="card">
                <div class="kpi-label">Sudah Dihapus</div>
                <div class="kpi-value"><?= (int) ($stat['dok_dihapus'] ?? 0) ?></div>
                <div class="help" style="margin-top:3px"><?= (int) ($stat['dok_tolak'] ?? 0) ?> pengajuan ditolak</div>
            </div>
            <div class="card">
                <div class="kpi-label">Dipulihkan</div>
                <div class="kpi-value"><?= (int) ($stat['dok_pulih'] ?? 0) ?></div>
                <div class="help" style="margin-top:3px">dikembalikan setelah terlanjur dihapus</div>
            </div>
            <div class="card">
                <div class="kpi-label">Income Yang Hilang</div>
                <div class="kpi-value" style="color:#991b1b"><?= h(money($stat['nilai_hilang'] ?? 0)) ?></div>
                <?php /* Yang dihitung di sini adalah alokasi yang benar-benar dilepas,
                         bukan nilai kontraknya. Dokumen yang belum pernah masuk laporan
                         tidak menambah angka ini sepeser pun. */ ?>
                <div class="help" style="margin-top:3px">benar-benar lepas dari laporan<?php
                    $kh = (float) ($stat['kontrak_hilang'] ?? 0);
                    if ($kh > (float) ($stat['nilai_hilang'] ?? 0)): ?><br>nilai kontrak <?= h(money($kh)) ?><?php endif; ?></div>
            </div>
        </div>

        <div class="panel">
            <h3 style="margin-top:0">Siapa yang paling sering mengajukan hapus</h3>
            <?php if (!$rekap): ?>
                <p class="dr-kosong" style="margin:0">Belum ada pengajuan sama sekali.</p>
            <?php else: ?>
            <?php
            // Diurutkan dari yang paling banyak mengajukan. Yang dibaca orang
            // pertama kali adalah peringkat dan sebabnya — rincian angkanya
            // disediakan di bawah bagi yang memang mencarinya.
            $urut = $rekap;
            usort($urut, fn($a, $b) => (int) $b['total'] <=> (int) $a['total']);
            $jenisUrut = ['dobel' => 'Dobel / duplikat', 'salah_input' => 'Salah input',
                          'batal' => 'Client batal',     'lainnya' => 'Lainnya'];
            $puncak    = $urut[0];
            $maxTotal  = max(1, (int) $puncak['total']);
            // Alasan terbanyak si puncak — itulah kalimat pembukanya.
            $alasanTop = 'lainnya'; $nTop = -1;
            foreach ($jenisUrut as $k => $_) if ((int) $puncak[$k] > $nTop) { $nTop = (int) $puncak[$k]; $alasanTop = $k; }
            $tampil = array_slice($urut, 0, 8);
            ?>
            <p style="margin:0 0 12px;font-size:13.5px;color:var(--ink2)">
                Paling sering: <strong style="color:var(--ink)"><?= h($puncak['pic']) ?></strong>
                &mdash; <strong><?= (int) $puncak['total'] ?> pengajuan</strong>,
                terbanyak karena <strong><?= h(mb_strtolower($jenisUrut[$alasanTop], 'UTF-8')) ?></strong>
                (<?= $nTop ?>&times;).
            </p>

            <?php /* Legenda selalu ada: warna saja tidak boleh jadi satu-satunya
                     penanda identitas, dan tiga dari empat warna ini kontrasnya
                     di bawah 3:1 terhadap latar putih. */ ?>
            <div class="dr-legenda">
                <?php foreach ($jenisUrut as $k => $label): ?>
                <span><i style="background:var(--dr-<?= h($k) ?>)"></i><?= h($label) ?></span>
                <?php endforeach; ?>
            </div>

            <div class="dr-graf">
                <?php foreach ($tampil as $r): $tot = max(1, (int) $r['total']); ?>
                <div class="dr-grow">
                    <div class="dr-nama" title="<?= h($r['pic']) ?>"><?= h($r['pic']) ?></div>
                    <div class="dr-track" style="width:<?= round((int) $r['total'] / $maxTotal * 100, 1) ?>%">
                        <?php foreach ($jenisUrut as $k => $label): $n = (int) $r[$k]; if (!$n) continue; ?>
                        <div class="dr-seg" style="flex:<?= $n ?>;background:var(--dr-<?= h($k) ?>)"
                             title="<?= h($r['pic']) ?> — <?= h($label) ?>: <?= $n ?> pengajuan"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="dr-tot"><?= (int) $r['total'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($urut) > count($tampil)): ?>
            <p class="help" style="margin:8px 0 0"><?= count($urut) - count($tampil) ?> PIC lain dengan pengajuan lebih sedikit
               tidak digambar &mdash; semuanya ada di rincian di bawah.</p>
            <?php endif; ?>

            <?php /* Tabel lengkapnya tetap ada, tinggal dibuka. Grafik menjawab
                     "siapa dan kenapa"; yang butuh angka per kolom membuka ini. */ ?>
            <details style="margin-top:12px">
                <summary style="cursor:pointer;font-size:12.5px;font-weight:700;color:var(--ink2)">
                    Lihat angka lengkapnya (<?= count($rekap) ?> PIC)</summary>
                <p class="help" style="margin:8px 0 7px">
                    Dihitung dari <strong>jenis alasan</strong> yang dipilih saat mengajukan, bukan dari tebakan atas teksnya.
                    Klik judul kolom untuk mengurutkan. Terlihat oleh semua orang.
                </p>
                <div class="table-wrap" data-dr-tabel>
                    <table class="dr-tabel">
                        <thead><tr>
                            <th data-sort="text">PIC <span class="dr-arr"></span></th>
                            <th data-sort="num" style="text-align:right">Dobel <span class="dr-arr"></span></th>
                            <th data-sort="num" style="text-align:right">Salah input <span class="dr-arr"></span></th>
                            <th data-sort="num" style="text-align:right">Client batal <span class="dr-arr"></span></th>
                            <th data-sort="num" style="text-align:right">Lainnya <span class="dr-arr"></span></th>
                            <th data-sort="num" style="text-align:right">Total <span class="dr-arr"></span></th>
                            <th data-sort="num" style="text-align:right">Jadi dihapus <span class="dr-arr"></span></th>
                            <th data-sort="num" style="text-align:right">Ditolak <span class="dr-arr"></span></th>
                            <th data-sort="num" style="text-align:right">Nilai kontrak dihapus <span class="dr-arr"></span></th>
                        </tr></thead>
                        <tbody>
                        <?php
                        $t = ['dobel'=>0,'salah_input'=>0,'batal'=>0,'lainnya'=>0,'total'=>0,
                              'jadi_dihapus'=>0,'ditolak'=>0,'nilai_dihapus'=>0];
                        foreach ($rekap as $r): foreach ($t as $k => $_) $t[$k] += (float) $r[$k]; ?>
                            <tr>
                                <td><?= h($r['pic']) ?></td>
                                <td style="text-align:right"><?= (int) $r['dobel'] ?></td>
                                <td style="text-align:right"><?= (int) $r['salah_input'] ?></td>
                                <td style="text-align:right"><?= (int) $r['batal'] ?></td>
                                <td style="text-align:right"><?= (int) $r['lainnya'] ?></td>
                                <td style="text-align:right;font-weight:700"><?= (int) $r['total'] ?></td>
                                <td style="text-align:right"><?= (int) $r['jadi_dihapus'] ?></td>
                                <td style="text-align:right"><?= (int) $r['ditolak'] ?></td>
                                <td style="text-align:right;white-space:nowrap" data-urut="<?= (float) $r['nilai_dihapus'] ?>"><?= h(money($r['nilai_dihapus'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr style="background:#f1f5f9;font-weight:700">
                            <td>TOTAL</td>
                            <td style="text-align:right"><?= (int) $t['dobel'] ?></td>
                            <td style="text-align:right"><?= (int) $t['salah_input'] ?></td>
                            <td style="text-align:right"><?= (int) $t['batal'] ?></td>
                            <td style="text-align:right"><?= (int) $t['lainnya'] ?></td>
                            <td style="text-align:right"><?= (int) $t['total'] ?></td>
                            <td style="text-align:right"><?= (int) $t['jadi_dihapus'] ?></td>
                            <td style="text-align:right"><?= (int) $t['ditolak'] ?></td>
                            <td style="text-align:right;white-space:nowrap"><?= h(money($t['nilai_dihapus'])) ?></td>
                        </tr></tfoot>
                    </table>
                </div>
                <p class="help" style="margin:8px 0 0">
                    Angka <strong>Ditolak</strong> juga perlu dibaca: pengajuan yang sering ditolak berarti
                    alasannya kurang kuat, bukan datanya yang salah.
                </p>
            </details>
            <?php endif; ?>
        </div>

        <?php /* ── 2. Pemutus: yang menunggu keputusan ─────────────────────── */ ?>
        <?php if ($bolehPutus): ?>
        <?php
        // Dikelompokkan per pengajuan, bukan per dokumen: satu pengajuan boleh
        // berisi beberapa dokumen dengan satu alasan, dan keputusannya satu.
        $grup = [];
        foreach ($perluPutus as $d) {
            $k = trim((string) ($d['batch_no'] ?? '')) !== '' ? 'b' . $d['batch_no'] : 'x' . $d['id'];
            $grup[$k][] = $d;
        }
        ?>
        <div class="panel" style="border:1px solid #fcd34d;background:#fffbeb">
            <h3 style="margin-top:0;color:#92400e">Menunggu keputusan Anda<?= $grup ? ' (' . count($grup) . ')' : '' ?></h3>
            <p class="help" style="margin:0 0 9px;color:#92400e">
                <strong>Menyetujui = data langsung terhapus.</strong> Tidak ada langkah berikutnya.
                Perhatikan kolom <strong>Income Berjalan</strong>: itulah yang benar-benar hilang dari laporan.
                Dokumen yang belum ditandatangani client tertulis <em>belum masuk</em> &mdash; menghapusnya
                tidak memotong income siapa pun, hanya membersihkan dokumennya.
            </p>
            <?php if (!$grup): ?>
                <p class="dr-kosong" style="margin:0">Tidak ada yang menunggu.</p>
            <?php else: ?>
            <?php foreach ($grup as $rows): $u = $rows[0];
                $nilaiGrup  = array_sum(array_map(fn($r) => (float) $r['nilai'], $rows));
                $incomeGrup = array_sum(array_map(fn($r) => (float) ($incomeKini[(int) $r['id']] ?? 0), $rows)); ?>
            <div style="border:1px solid #fde68a;background:#fff;border-radius:9px;padding:11px;margin-bottom:10px">
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:baseline;justify-content:space-between">
                    <div style="font-size:12px;color:#92400e">
                        <?php if (count($rows) > 1): ?>
                        <strong><?= count($rows) ?> dokumen dalam satu pengajuan</strong>
                        <span class="muted">(batch <?= h((string) $u['batch_no']) ?>)</span>
                        <?php else: ?>
                        <strong>1 dokumen</strong>
                        <?php endif; ?>
                        &middot; diajukan <strong><?= h($u['requested_by']) ?></strong> &middot; <?= h($tgl($u['requested_at'])) ?>
                    </div>
                    <div style="text-align:right">
                        <div style="font-size:13px;font-weight:800;color:<?= $incomeGrup > 0 ? '#991b1b' : '#475569' ?>">
                            Income berkurang <?= h(money($incomeGrup)) ?></div>
                        <div class="help" style="margin:0">nilai kontrak <?= h(money($nilaiGrup)) ?></div>
                    </div>
                </div>
                <div style="margin:7px 0 9px;font-size:12px">
                    <?= _dr_tanda_jenis($u['jenis'] ?? '') ?><?= h($u['alasan']) ?>
                </div>
                <div class="table-wrap" style="margin:0 0 9px">
                    <table class="dr-tabel">
                        <thead><tr><th>Modul</th><th>Dokumen</th><th>Unit</th><th>Periode</th>
                            <th style="text-align:right">Nilai Kontrak</th>
                            <th style="text-align:right">Income Berjalan</th><th>PIC</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $d): $inc = (float) ($incomeKini[(int) $d['id']] ?? 0); ?>
                            <tr>
                                <td><?= h(_dr_modul($d['module'] ?? null)) ?></td>
                                <?= $selDok($d) ?>
                                <td><?= h($d['master_code'] ?: '—') ?></td>
                                <td style="white-space:nowrap"><?= h($d['periode'] ?: '—') ?></td>
                                <td style="text-align:right;white-space:nowrap"><?= h(money($d['nilai'])) ?></td>
                                <td style="text-align:right;white-space:nowrap">
                                    <?php if ($inc > 0): ?><strong style="color:#991b1b"><?= h(money($inc)) ?></strong>
                                    <?php else: ?><span class="muted" style="font-size:11.5px">belum masuk</span><?php endif; ?>
                                </td>
                                <td><?= h($d['pic_name'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <form method="post" action="?r=deletion_request_decide" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <input name="catatan" placeholder="Catatan (opsional)" style="width:200px">
                    <button type="submit" name="putusan" value="setuju" class="btn warn" style="background:#991b1b"
                            onclick="return confirm('SETUJUI dan HAPUS SEKARANG?\n\n· <?= count($rows) ?> dokumen\n· Nilai kontrak Rp <?= h(number_format($nilaiGrup, 0, ',', '.')) ?>\n· Income PIC berkurang <?= $incomeGrup > 0 ? 'Rp ' . h(number_format($incomeGrup, 0, ',', '.')) : 'Rp 0 — belum pernah masuk laporan' ?>\n· Hilang dari Exhibition/Media/Gudang\n\nMasih bisa dipulihkan lewat tombol Pulihkan di Riwayat.')">
                        ✓ Setujui &amp; Hapus<?= count($rows) > 1 ? ' (' . count($rows) . ')' : '' ?>
                    </button>
                    <button type="submit" name="putusan" value="tolak"
                            onclick="return confirm('Tolak pengajuan ini?\n\nDatanya tidak jadi dihapus.')">✗ Tolak</button>
                </form>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php /* ── 3. Formulir pengajuan ───────────────────────────────────── */ ?>
        <div class="panel">
            <h3 style="margin-top:0">Ajukan penghapusan</h3>
            <p class="help" style="margin:0 0 4px">
                Data yang salah input tidak dihapus sendiri. Ajukan di sini, lalu <strong>pemutus</strong> menyetujui
                atau menolak &mdash; supaya ada jejak siapa meminta apa dan kenapa.
                <strong>Begitu pemutus menyetujui, datanya langsung terhapus:</strong> hilang dari Exhibition / Media / Gudang
                dan income PIC berkurang sebesar nilai dokumennya. Keliru? Ada tombol <strong>Pulihkan</strong> di Riwayat.
            </p>
            <?php if (!$dokumen): ?>
                <p class="dr-kosong" style="margin:0">Tidak ada data milik Anda yang bisa diajukan.
                   Yang pengajuannya sedang berjalan atau sudah dihapus tidak muncul di sini.</p>
            <?php else: ?>
            <form method="post" action="?r=deletion_request_save">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                <label style="display:block;margin-bottom:5px">Data yang mau dihapus <span style="color:#b91c1c">*</span></label>
                <?php
                // Jumlah per modul untuk label saringan — angka di tombolnya
                // membuat orang tahu ada berapa tanpa harus menekannya dulu.
                $perModul = ['' => count($dokumen), 'cl' => 0, 'media' => 0, 'gudang' => 0];
                foreach ($dokumen as $d) {
                    $m = (string) ($d['module'] ?? '');
                    if (isset($perModul[$m])) $perModul[$m]++;
                }
                ?>
                <div class="dr-bar">
                    <input class="dr-cari" data-cari placeholder="Cari nomor SKP / transaksi, client, atau unit…">
                    <select id="dr-modul" style="width:auto;min-width:150px">
                        <option value="">Semua modul (<?= (int) $perModul[''] ?>)</option>
                        <option value="cl">Exhibition (<?= (int) $perModul['cl'] ?>)</option>
                        <option value="media">Media (<?= (int) $perModul['media'] ?>)</option>
                        <option value="gudang">Gudang (<?= (int) $perModul['gudang'] ?>)</option>
                    </select>
                    <span class="dr-hitung" data-hitung><?= count($dokumen) ?> data</span>
                    <span class="dr-petunjuk">Klik judul kolom untuk mengurutkan &mdash; di dalam golongannya</span>
                </div>
                <?php /* Tabel berkolom, bukan satu baris teks panjang: nomor, client, unit,
                         periode dan nilai masing-masing punya kolomnya sendiri supaya bisa
                         dibandingkan sekilas dan diurutkan. */ ?>
                <div class="table-wrap dr-gulir" data-dr-tabel>
                    <table class="dr-tabel">
                        <thead><tr>
                            <th style="width:34px"><input type="checkbox" id="dr-semua" class="dr-kotak" title="Pilih semua yang tampil"></th>
                            <th data-sort="text">Modul <span class="dr-arr"></span></th>
                            <th data-sort="text">Dokumen <span class="dr-arr"></span></th>
                            <th data-sort="text">Client <span class="dr-arr"></span></th>
                            <th data-sort="text">Unit <span class="dr-arr"></span></th>
                            <th data-sort="text">Periode <span class="dr-arr"></span></th>
                            <th data-sort="num" style="text-align:right">Nilai Kontrak <span class="dr-arr"></span></th>
                            <th data-sort="num" style="text-align:right">Income Berjalan <span class="dr-arr"></span></th>
                            <th data-sort="text">Status <span class="dr-arr"></span></th>
                        </tr></thead>
                        <tbody>
                        <?php
                        // Baris pemisah dicetak saat golongannya berganti. Urutannya
                        // tetap: dokumen, lalu Exhibition, Media, Gudang — masing-masing
                        // menaik menurut ID.
                        $judulGrup = [
                            'dok'    => 'Berdasarkan dokumen SKP / SKS / Form Utilities',
                            'cl'     => 'Exhibition — berdasarkan ID transaksi (belum berdokumen)',
                            'media'  => 'Media — berdasarkan ID transaksi (belum berdokumen)',
                            'gudang' => 'Gudang — berdasarkan ID transaksi (belum berdokumen)',
                        ];
                        $jumlahGrup = array_count_values(array_map(fn($x) => (string) ($x['grup'] ?? ''), $dokumen));
                        $grupKini = null; $noGrup = -1;
                        ?>
                        <?php foreach ($dokumen as $d):
                            $periode = ($d['start_date'] && $d['end_date'])
                                ? date('d/m/y', strtotime($d['start_date'])) . ' – ' . date('d/m/y', strtotime($d['end_date']))
                                : '—';
                            $inc = (float) ($d['income_aktif'] ?? 0);
                            $g   = (string) ($d['grup'] ?? 'dok');
                            if ($g !== $grupKini): $grupKini = $g; $noGrup++; ?>
                            <tr class="dr-judul-grup" data-grup-judul="<?= (int) $noGrup ?>">
                                <td colspan="9">
                                    <?= h($judulGrup[$g] ?? $g) ?>
                                    <span class="muted" style="font-weight:400">&middot; <?= (int) ($jumlahGrup[$g] ?? 0) ?> data</span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr data-grup="<?= (int) $noGrup ?>" data-modul="<?= h((string) ($d['module'] ?? '')) ?>">
                                <td><input type="checkbox" name="pilih[]" value="<?= h((string) $d['kunci']) ?>"
                                           class="dr-pick" data-nilai="<?= (float) $d['total_amount'] ?>"
                                           data-income="<?= $inc ?>"></td>
                                <td><?= h(_dr_modul($d['module'] ?? null)) ?></td>
                                <?php /* Transaksi tanpa dokumen dikenali dari nomor transaksinya —
                                         itu satu-satunya nomor yang dimilikinya. */ ?>
                                <td data-urut="<?= h((string) ($d['skp_no'] ?: $d['id'])) ?>">
                                    <?php if (($d['jenis_baris'] ?? '') === 'trx'): ?>
                                    <b>Transaksi #<?= (int) $d['trx_id'] ?></b>
                                    <?php else: ?>
                                    <b><?= h(_dr_jenis($d['doc_type'])) ?> <?= h($d['skp_no'] ?: 'draft #' . $d['id']) ?></b>
                                    <?php endif; ?></td>
                                <td><?= h($d['client_name'] ?: '—') ?></td>
                                <td><?= h($d['master_code'] ?: '—') ?></td>
                                <td style="white-space:nowrap" data-urut="<?= h((string) $d['start_date']) ?>"><?= h($periode) ?></td>
                                <td style="text-align:right;white-space:nowrap" data-urut="<?= (float) $d['total_amount'] ?>"><?= h(money($d['total_amount'])) ?></td>
                                <?php /* Angka inilah yang benar-benar akan hilang dari laporan. Dokumen
                                         yang belum diteken client belum punya alokasi sama sekali — nol,
                                         dan itu harus terlihat jelas supaya tidak ada yang mengira
                                         income-nya ikut terpotong. */ ?>
                                <td style="text-align:right;white-space:nowrap" data-urut="<?= $inc ?>">
                                    <?php if ($inc > 0): ?>
                                        <strong style="color:#991b1b"><?= h(money($inc)) ?></strong>
                                    <?php else: ?>
                                        <span class="muted" style="font-size:11.5px">belum masuk</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= _dr_badge_skp((string) $d['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="dr-nota">Hanya data <strong>atas nama Anda sebagai PIC</strong>.
                    Milik rekan tidak muncul &mdash; income-nya milik dia, jadi dia yang mengajukan.
                    Boleh pilih <strong>beberapa sekaligus</strong> bila alasannya sama; pemutus memutuskannya sekali.
                    Baris berlabel <strong>Tanpa dokumen</strong> adalah transaksi yang diinput langsung dari menu
                    Exhibition / Media / Gudang dan belum pernah dibuatkan SKP/SKS &mdash; sekarang ikut bisa diajukan.</p>
                <div id="dr-ringkas" class="dr-ringkas"></div>

                <div class="dr-isian">
                    <div>
                        <label>Jenis alasan <span style="color:#b91c1c">*</span></label>
                        <select name="jenis" required>
                            <?php foreach (_dr_jenis_alasan() as $k => $v): ?>
                            <option value="<?= h($k) ?>"><?= h($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="help">Dipakai untuk rekap di atas &mdash; pilih yang paling mendekati.</span>
                    </div>
                    <div class="wide">
                        <label>Alasan dihapus <span style="color:#b91c1c">*</span></label>
                        <textarea name="alasan" rows="3" required
                                  placeholder="Jelaskan kenapa data ini harus dihapus — mis. salah input client, dobel dengan dokumen lain, client batal sebelum dokumen terbit."></textarea>
                        <span class="help">Alasan ini yang dibaca pemutus. Tanpa alasan yang jelas, pengajuan biasanya ditolak.</span>
                    </div>
                </div>
                <div class="dr-kaki">
                    <button type="submit" id="dr-kirim">Kirim Pengajuan</button>
                    <span class="help" style="margin:0">Akan diputuskan oleh
                        <strong><?= h($set['role_name'] ?: 'belum disetel') ?><?= $set['pic_name'] ? ' — ' . h($set['pic_name']) : '' ?></strong>.</span>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <?php /* ── 4. Pengajuan saya ───────────────────────────────────────── */ ?>
        <div class="panel">
            <div class="dr-kepala">
                <h3>Pengajuan saya<?= $punyaSaya ? ' (' . count($punyaSaya) . ')' : '' ?></h3>
                <?php if ($punyaSaya): ?>
                <input class="dr-cari" data-cari placeholder="Cari dokumen atau alasan…" style="max-width:240px">
                <?php endif; ?>
            </div>
            <?php if (!$punyaSaya): ?>
                <p class="dr-kosong" style="margin:0">Belum ada.</p>
            <?php else: ?>
            <div class="table-wrap" data-dr-tabel>
                <table class="dr-tabel">
                    <thead><tr>
                        <th data-sort="text">Dokumen <span class="dr-arr"></span></th>
                        <th data-sort="text">Unit <span class="dr-arr"></span></th>
                        <th data-sort="text">Periode <span class="dr-arr"></span></th>
                        <th data-sort="num" style="text-align:right">Nilai <span class="dr-arr"></span></th>
                        <th data-sort="text">Status <span class="dr-arr"></span></th>
                        <th data-sort="text">Diajukan <span class="dr-arr">▼</span></th>
                        <th>Alasan / Catatan</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($punyaSaya as $d): ?>
                        <tr>
                            <?= $selDok($d) ?>
                            <td><?= h($d['master_code'] ?: '—') ?></td>
                            <td style="white-space:nowrap"><?= h($d['periode'] ?: '—') ?></td>
                            <td style="text-align:right;white-space:nowrap" data-urut="<?= (float) $d['nilai'] ?>"><?= h(money($d['nilai'])) ?></td>
                            <td data-urut="<?= h($d['status']) ?>"><?= _dr_badge($d['status']) ?></td>
                            <td style="white-space:nowrap" data-urut="<?= h((string) $d['requested_at']) ?>"><?= h($tgl($d['requested_at'])) ?></td>
                            <td style="font-size:11.5px;max-width:300px;line-height:1.6">
                                <?= _dr_tanda_jenis($d['jenis'] ?? '') ?><?= h($d['alasan']) ?>
                                <?php if (trim((string) ($d['batch_no'] ?? '')) !== ''): ?>
                                <div class="muted" style="font-size:10.5px;margin-top:2px">satu pengajuan dengan dokumen lain &middot; batch <?= h((string) $d['batch_no']) ?></div>
                                <?php endif; ?>
                                <?php if ($d['decision_note']): ?>
                                <div style="color:#991b1b;margin-top:3px">Catatan pemutus: <?= h($d['decision_note']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php /* Pengingat WhatsApp. Aplikasi ini tidak punya pengirim WA otomatis,
                                         jadi dipakai cara yang sudah ada di Permintaan Kontrak: tautan
                                         wa.me dengan pesan siap kirim, kontaknya dipilih sendiri. */ ?>
                                <?php if ($d['status'] === 'menunggu'):
                                    $waPesan = rawurlencode(
                                        'Mohon bantu putuskan pengajuan penghapusan data di CLARA.' . "\n\n"
                                        . 'Dokumen: ' . ($d['doc_no'] ?: 'draft #' . $d['skp_id']) . "\n"
                                        . 'Client: ' . ($d['client_name'] ?: '-') . "\n"
                                        . 'Nilai: ' . money($d['nilai']) . "\n"
                                        . 'Alasan: ' . $d['alasan'] . "\n\n"
                                        . 'Buka menu Pengajuan Hapus Data di CLARA. Terima kasih.'
                                    ); ?>
                                <a class="btn light" target="_blank" rel="noopener"
                                   style="padding:2px 7px;font-size:10.5px;border-color:#bbf7d0;color:#15803d;white-space:nowrap"
                                   href="https://wa.me/?text=<?= $waPesan ?>">Ingatkan</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php /* ── 5. Sisa alur lama ───────────────────────────────────────── */ ?>
        <?php /* Alur sekarang menghapus langsung saat pemutus menyetujui, jadi status
                 'disetujui' tidak terbit lagi. Panel ini hanya muncul kalau masih ada
                 baris lama yang tertinggal — supaya tidak ada pengajuan yang
                 menggantung tanpa penyelesaian. */ ?>
        <?php if ($bolehHapus && $siapHapus): ?>
        <div class="panel" style="border:1px solid #fecaca;background:#fef2f2">
            <h3 style="margin-top:0;color:#991b1b">Sisa pengajuan alur lama (<?= count($siapHapus) ?>)</h3>
            <p class="help" style="margin:0 0 8px">
                Pengajuan ini sudah disetujui di alur lama tetapi belum pernah dieksekusi.
                Alur baru menghapus langsung saat disetujui, jadi daftar ini akan habis dan tidak terisi lagi.
            </p>
            <div class="table-wrap">
                <table class="dr-tabel">
                    <thead><tr><th>Dokumen</th><th>Unit</th><th>Periode</th><th style="text-align:right">Nilai</th><th>Alasan</th><th>Tindakan</th></tr></thead>
                    <tbody>
                    <?php foreach ($siapHapus as $d): ?>
                        <tr>
                            <?= $selDok($d) ?>
                            <td><?= h($d['master_code'] ?: '—') ?></td>
                            <td style="white-space:nowrap"><?= h($d['periode'] ?: '—') ?></td>
                            <td style="text-align:right;white-space:nowrap"><?= h(money($d['nilai'])) ?></td>
                            <td style="font-size:11.5px"><?= _dr_tanda_jenis($d['jenis'] ?? '') ?><?= h($d['alasan']) ?>
                                <div class="muted" style="font-size:10.5px;margin-top:2px">
                                    disetujui <?= h($d['decided_by']) ?> &middot; <?= h($tgl($d['decided_at'])) ?></div>
                            </td>
                            <td>
                                <form method="post" action="?r=deletion_request_execute">
                                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <button type="submit" class="btn warn" style="background:#991b1b"
                                            onclick="return confirm('HAPUS data ini sekarang?\n\n· Income PIC berkurang <?= h(number_format((float) $d['nilai'], 0, ',', '.')) ?>\n· Hilang dari Exhibition/Media/Gudang\n\nMasih bisa dipulihkan lewat tombol Pulihkan di Riwayat.')">Hapus Sekarang</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php /* ── 6. Siapa pemutusnya ─────────────────────────────────────── */ ?>
        <?php if ($bolehHapus): ?>
        <div class="panel">
            <h3 style="margin-top:0">Siapa yang memutuskan</h3>
            <form method="post" action="?r=deletion_approver_save" class="dr-isian2">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <div>
                    <label>Jabatan pemutus</label>
                    <select name="role_name" required>
                        <?php foreach ($jabatan as $j): ?>
                        <option value="<?= h($j) ?>" <?= ($set['role_name'] ?? '') === $j ? 'selected' : '' ?>><?= h($j) ?></option>
                        <?php endforeach; ?>
                        <?php if (($set['role_name'] ?? '') !== '' && !in_array($set['role_name'], $jabatan, true)): ?>
                        <option value="<?= h($set['role_name']) ?>" selected><?= h($set['role_name']) ?> &mdash; sudah tidak ada di Master PIC aktif</option>
                        <?php endif; ?>
                    </select>
                    <span class="help">Ditulis sebagai jabatan, bukan nama &mdash; jadi tidak perlu diubah saat orangnya berganti.</span>
                </div>
                <div>
                    <label>Khusus orang <span class="muted">(opsional)</span></label>
                    <input name="pic_name" value="<?= h($set['pic_name'] ?? '') ?>" placeholder="Kosongkan = semua yang berjabatan itu">
                    <span class="help">Isi bila hanya satu orang tertentu yang boleh memutuskan.</span>
                </div>
                <div class="wide"><button type="submit">Simpan Pemutus</button></div>
            </form>
        </div>
        <?php endif; ?>

        <?php /* ── 7. Riwayat (paling bawah) ───────────────────────────────── */ ?>
        <div class="panel">
            <div class="dr-kepala">
                <h3>Riwayat<?= $riwayat ? ' (' . count($riwayat) . ')' : '' ?></h3>
                <?php if ($riwayat): ?>
                <input class="dr-cari" data-cari placeholder="Cari dokumen, client, PIC, alasan…">
                <?php endif; ?>
            </div>
            <p class="help" style="margin:0 0 9px">
                Semua yang sudah dihapus, ditolak, atau dipulihkan. Jejaknya tidak pernah dibuang.
            </p>
            <?php if (!$riwayat): ?>
                <p class="dr-kosong" style="margin:0">Belum ada data yang dihapus, ditolak, atau dipulihkan.</p>
            <?php else: ?>
            <div class="table-wrap" data-dr-tabel>
                <table class="dr-tabel">
                    <thead><tr>
                        <th data-sort="text">Dokumen <span class="dr-arr"></span></th>
                        <th data-sort="text">Unit <span class="dr-arr"></span></th>
                        <th data-sort="text">Periode <span class="dr-arr"></span></th>
                        <th data-sort="num" style="text-align:right">Nilai <span class="dr-arr"></span></th>
                        <th data-sort="text">PIC <span class="dr-arr"></span></th>
                        <th data-sort="text">Status <span class="dr-arr"></span></th>
                        <th>Jejak</th>
                        <?php if ($bolehPulih): ?><th>Tindakan</th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($riwayat as $d): ?>
                        <tr>
                            <?= $selDok($d) ?>
                            <td><?= h($d['master_code'] ?: '—') ?></td>
                            <td style="white-space:nowrap"><?= h($d['periode'] ?: '—') ?></td>
                            <td style="text-align:right;white-space:nowrap" data-urut="<?= (float) $d['nilai'] ?>"><?= h(money($d['nilai'])) ?></td>
                            <td><?= h($d['pic_name'] ?: '—') ?></td>
                            <td data-urut="<?= h($d['status']) ?>"><?= _dr_badge($d['status']) ?></td>
                            <td class="dr-jejak">
                                <?= _dr_tanda_jenis($d['jenis'] ?? '') ?><?= h($d['alasan']) ?><br>
                                <span class="muted">Diajukan <b><?= h($d['requested_by']) ?></b> &middot; <?= h($tgl($d['requested_at'])) ?></span>
                                <?php if ($d['decided_by']): ?><br>
                                <span class="muted">Diputus <b><?= h($d['decided_by']) ?></b> &middot; <?= h($tgl($d['decided_at'])) ?>
                                <?= $d['decision_note'] ? '&middot; ' . h($d['decision_note']) : '' ?></span>
                                <?php endif; ?>
                                <?php if ($d['executed_by']): ?><br>
                                <span style="color:#991b1b">Dihapus <b><?= h($d['executed_by']) ?></b> &middot; <?= h($tgl($d['executed_at'])) ?>
                                &middot; <?= (float) ($d['rupiah_dilepas'] ?? 0) > 0
                                    ? 'income lepas ' . h(money($d['rupiah_dilepas'])) . ' (' . (int) $d['alokasi_dilepas'] . ' baris)'
                                    : 'tidak ada income yang lepas — belum pernah masuk laporan' ?></span>
                                <?php endif; ?>
                                <?php if (!empty($d['restored_by'])): ?><br>
                                <span style="color:#1d4ed8">Dipulihkan <b><?= h($d['restored_by']) ?></b> &middot; <?= h($tgl($d['restored_at'])) ?>
                                &middot; <?= (float) ($d['rupiah_pulih'] ?? 0) > 0
                                    ? 'income kembali ' . h(money($d['rupiah_pulih'])) . ' (' . (int) ($d['alokasi_pulih'] ?? 0) . ' baris)'
                                    : 'income belum kembali — menunggu TTD client' ?>
                                <?= !empty($d['restore_note']) ? '&middot; ' . h($d['restore_note']) : '' ?></span>
                                <?php endif; ?>
                            </td>
                            <?php if ($bolehPulih): ?>
                            <td>
                                <?php /* Hanya yang masih berstatus terhapus. Yang ditolak tidak pernah
                                         dihapus, jadi tidak ada yang perlu dipulihkan; yang sudah
                                         dipulihkan tidak boleh dipulihkan dua kali. */ ?>
                                <?php if ($d['status'] === 'dihapus'): ?>
                                <form method="post" action="?r=deletion_request_restore" style="display:flex;gap:4px;flex-wrap:wrap">
                                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <input name="catatan" placeholder="Alasan pulih" style="width:120px;font-size:11px">
                                    <button type="submit" class="btn light" style="border-color:#bfdbfe;color:#1d4ed8;white-space:nowrap"
                                            onclick="return confirm('Pulihkan data ini?\n\n· Transaksinya kembali muncul di Exhibition/Media/Gudang\n· Income kembali masuk laporan — kecuali dokumennya belum ditandatangani client, maka menunggu TTD dulu\n\nJejak penghapusan ini tetap tersimpan.')">↩ Pulihkan</button>
                                </form>
                                <?php else: ?>
                                <span class="muted" style="font-size:11px">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        /* Pencarian + pengurutan kolom untuk tiap tabel bertanda data-dr-tabel.
           Ditulis sendiri, tanpa pustaka: halaman ini hanya butuh dua hal itu,
           dan memuat pustaka tabel hanya untuk ini tidak sepadan. */
        (function () {
            function pasang(wrap) {
                var tbl = wrap.querySelector('table');
                if (!tbl || !tbl.tBodies.length) return;
                var tbody = tbl.tBodies[0];
                // Baris pemisah golongan bukan data: dikeluarkan dari daftar yang
                // disaring & diurutkan, lalu disembunyikan sendiri saat seluruh
                // isinya tersaring habis.
                var semua  = Array.prototype.slice.call(tbody.rows);
                var judul  = semua.filter(function (r) { return r.classList.contains('dr-judul-grup'); });
                var baris  = semua.filter(function (r) { return !r.classList.contains('dr-judul-grup'); });

                // Kotak cari boleh berada di luar wrap (di header panel), jadi
                // dicari dulu di dalam, lalu ke panel terdekat.
                var panel = wrap.closest('.panel') || wrap.parentNode;
                var cari  = wrap.querySelector('[data-cari]') || (panel && panel.querySelector('[data-cari]'));
                var info  = panel && panel.querySelector('[data-hitung]');

                // Saringan modul hanya ada di tabel pilihan dokumen; tabel lain
                // memakai pencarian teks saja.
                var modul = panel && panel.querySelector('#dr-modul');
                function saring() {
                    var q = cari ? (cari.value || '').toLowerCase().trim() : '';
                    var m = modul ? modul.value : '';
                    var n = 0;
                    baris.forEach(function (r) {
                        var cocokTeks  = !q || r.textContent.toLowerCase().indexOf(q) !== -1;
                        var cocokModul = !m || r.getAttribute('data-modul') === m;
                        var tampil = cocokTeks && cocokModul;
                        r.style.display = tampil ? '' : 'none';
                        if (tampil) n++;
                    });
                    // Judul golongan ikut hilang kalau tidak ada lagi isinya.
                    judul.forEach(function (h) {
                        var g = h.getAttribute('data-grup-judul');
                        var ada = baris.some(function (r) {
                            return r.getAttribute('data-grup') === g && r.style.display !== 'none';
                        });
                        h.style.display = ada ? '' : 'none';
                    });
                    if (info) info.textContent = n + ' dari ' + baris.length + ' data';
                }
                if (cari)  cari.addEventListener('input', saring);
                if (modul) modul.addEventListener('change', saring);

                function nilai(r, i, tipe) {
                    var c = r.cells[i];
                    if (!c) return tipe === 'num' ? 0 : '';
                    var v = c.getAttribute('data-urut');
                    if (v === null) v = c.textContent.trim();
                    return tipe === 'num' ? (parseFloat(v) || 0) : v;
                }
                tbl.querySelectorAll('th[data-sort]').forEach(function (th) {
                    var i = Array.prototype.indexOf.call(th.parentNode.children, th);
                    th.addEventListener('click', function () {
                        var tipe = th.getAttribute('data-sort');
                        var arah = th.getAttribute('data-arah') === 'naik' ? 'turun' : 'naik';
                        var k = arah === 'naik' ? 1 : -1;
                        // Diurutkan DI DALAM golongannya masing-masing. Mengurutkan
                        // seluruh tabel akan membubarkan pemisah Exhibition / Media /
                        // Gudang, padahal pemisah itulah gunanya.
                        baris.sort(function (a, b) {
                            var ga = parseInt(a.getAttribute('data-grup') || 0, 10);
                            var gb = parseInt(b.getAttribute('data-grup') || 0, 10);
                            if (ga !== gb) return ga - gb;
                            var x = nilai(a, i, tipe), y = nilai(b, i, tipe);
                            if (tipe === 'num') return (x - y) * k;
                            return String(x).localeCompare(String(y), 'id') * k;
                        });
                        if (judul.length) {
                            // Tiap judul ditaruh kembali tepat sebelum baris pertama
                            // golongannya.
                            judul.forEach(function (h) { tbody.appendChild(h); });
                            baris.forEach(function (r) { tbody.appendChild(r); });
                            judul.forEach(function (h) {
                                var g = h.getAttribute('data-grup-judul');
                                var pertama = baris.find(function (r) { return r.getAttribute('data-grup') === g; });
                                if (pertama) tbody.insertBefore(h, pertama);
                            });
                        } else {
                            baris.forEach(function (r) { tbody.appendChild(r); });
                        }
                        tbl.querySelectorAll('th[data-sort]').forEach(function (o) {
                            o.removeAttribute('data-arah');
                            var s = o.querySelector('.dr-arr'); if (s) s.textContent = '';
                        });
                        th.setAttribute('data-arah', arah);
                        var s = th.querySelector('.dr-arr'); if (s) s.textContent = arah === 'naik' ? ' ▲' : ' ▼';
                    });
                });
            }
            document.querySelectorAll('[data-dr-tabel]').forEach(pasang);

            /* Ringkasan pilihan + konfirmasi yang menyebut angkanya. Menghapus
               income orang lewat satu klik tanpa melihat totalnya adalah cara
               termudah menghapus lebih banyak dari yang dimaksud. */
            var pick  = document.querySelectorAll('.dr-pick');
            var ring  = document.getElementById('dr-ringkas');
            var kirim = document.getElementById('dr-kirim');
            var semua = document.getElementById('dr-semua');
            if (!pick.length || !ring || !kirim) return;

            function rp(n) { return 'Rp ' + n.toLocaleString('id-ID'); }
            function hitung() {
                var n = 0, jml = 0, inc = 0, belum = 0;
                pick.forEach(function (c) {
                    if (!c.checked) return;
                    n++;
                    jml += parseFloat(c.dataset.nilai || 0);
                    var i = parseFloat(c.dataset.income || 0);
                    inc += i;
                    if (i <= 0) belum++;
                });
                if (!n) { ring.innerHTML = ''; ring.className = 'dr-ringkas'; return { n: 0, jml: 0, inc: 0, belum: 0 }; }
                // Dua angka, dan keduanya disebut. Menyebut nilai kontrak saja
                // membuat orang mengira income-nya terpotong sebesar itu; padahal
                // dokumen yang belum diteken client memang belum pernah masuk.
                var t = '<b>' + n + ' data dipilih</b> — nilai kontrak ' + rp(jml) + '.<br>';
                if (inc > 0) {
                    t += 'Yang benar-benar hilang dari income: <b>' + rp(inc) + '</b>';
                    t += belum ? ' (' + belum + ' lainnya belum pernah masuk laporan, jadi tidak mengurangi apa pun).' : '.';
                } else {
                    t += '<b>Tidak ada income yang berkurang</b> — dokumen ini belum pernah masuk laporan '
                       + '(belum ditandatangani client), jadi menghapusnya tidak memotong income siapa pun.';
                }
                ring.innerHTML = t;
                // Merah hanya bila income memang berkurang; kalau tidak, nada netral —
                // kotak merah untuk sesuatu yang tidak memotong apa pun itu menakut-nakuti.
                ring.className = 'dr-ringkas ' + (inc > 0 ? 'ada' : 'nol');
                return { n: n, jml: jml, inc: inc, belum: belum };
            }
            pick.forEach(function (c) { c.addEventListener('change', hitung); });
            if (semua) semua.addEventListener('change', function () {
                // Hanya baris yang sedang tampil — kalau sedang disaring, "pilih
                // semua" yang diam-diam mencentang baris tersembunyi berbahaya.
                pick.forEach(function (c) {
                    var tr = c.closest('tr');
                    if (tr && tr.style.display !== 'none') c.checked = semua.checked;
                });
                hitung();
            });
            kirim.closest('form').addEventListener('submit', function (e) {
                var x = hitung();
                if (!x.n) { e.preventDefault(); alert('Pilih dulu data yang mau dihapus.'); return; }
                var pesan = 'Kirim pengajuan penghapusan ' + x.n + ' dokumen?\n\n'
                          + 'Nilai kontrak: ' + rp(x.jml) + '\n'
                          + (x.inc > 0
                                ? 'Income yang akan berkurang: ' + rp(x.inc)
                                : 'Income yang akan berkurang: Rp 0 — belum pernah masuk laporan')
                          + '\n\nData BELUM dihapus sekarang — masih menunggu persetujuan pemutus.';
                if (!confirm(pesan)) e.preventDefault();
            });
            hitung();
        })();
        </script>
        <?php
    });
}

// ─── Tindakan ───────────────────────────────────────────────────────────────

/**
 * Nomor batch untuk satu pengajuan berisi beberapa dokumen.
 *
 * Dipakai supaya pemutus memutuskan sekali untuk seluruh dokumen yang diajukan
 * bersamaan, sementara barisnya tetap satu per dokumen — rekap per PIC
 * menghitung dokumen, bukan pengajuan, jadi barisnya tidak boleh digabung.
 */
function _dr_batch_baru(): string
{
    return date('ymdHis') . substr((string) random_int(10, 99), 0, 2);
}

/** PIC mengirim pengajuan — boleh beberapa dokumen sekaligus. */
function deletion_request_save(PDO $pdo): void
{
    require_permission('request_delete');
    verify_csrf();
    $pid    = current_property_id();
    $alasan = trim((string) post('alasan'));
    $jenis  = array_key_exists((string) post('jenis'), _dr_jenis_alasan()) ? (string) post('jenis') : 'lainnya';
    $uname  = (string) ($_SESSION['user']['name'] ?? 'system');

    // pilih[] berisi kunci "s:<id dokumen>" atau "t:<id transaksi>" — satu daftar
    // untuk dua jenis baris. skp_id[] lama tetap diterima supaya tautan atau
    // halaman yang belum dimuat ulang tidak mendadak berhenti bekerja.
    $minta = $_POST['pilih'] ?? [];
    if (!is_array($minta)) $minta = [$minta];
    foreach ((array) ($_POST['skp_id'] ?? []) as $lamaId) {
        if ((int) $lamaId > 0) $minta[] = 's:' . (int) $lamaId;
    }
    $minta = array_values(array_unique(array_filter(array_map('strval', $minta))));

    if ($alasan === '') { flash('Alasan penghapusan wajib diisi.'); redirect_to('deletion_request'); }
    if (!$minta)        { flash('Pilih dulu data yang mau dihapus.'); redirect_to('deletion_request'); }

    // Dokumennya harus benar-benar boleh diajukan orang ini. Diperiksa ulang di
    // sini, bukan percaya isian formulir — isian formulir bisa diubah.
    $boleh = [];
    foreach (_dr_dokumen_saya($pdo, $pid) as $d) $boleh[(string) $d['kunci']] = $d;

    $sah = $tolak = [];
    foreach ($minta as $kunci) {
        if (isset($boleh[$kunci])) $sah[] = $boleh[$kunci];
        else                       $tolak[] = $kunci;
    }
    if (!$sah) {
        flash('Data itu tidak bisa Anda ajukan — bukan milik Anda, atau pengajuannya sudah ada.');
        redirect_to('deletion_request');
    }

    // Nomor batch hanya diberikan bila memang lebih dari satu dokumen; pengajuan
    // tunggal tetap tanpa batch, sama seperti baris-baris sebelumnya.
    $batch = count($sah) > 1 ? _dr_batch_baru() : null;

    $ins = $pdo->prepare('INSERT INTO deletion_requests
        (property_id, skp_id, transaction_id, doc_no, doc_type, module, client_name, master_code,
         periode, nilai, pic_name, alasan, jenis, status, batch_no, requested_by, requested_user_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,\'menunggu\',?,?,?)');

    $ids = [];
    $nilaiTotal = 0.0;
    foreach ($sah as $dok) {
        $periode = ($dok['start_date'] ?? '') && ($dok['end_date'] ?? '')
            ? date('d/m/Y', strtotime($dok['start_date'])) . ' s/d ' . date('d/m/Y', strtotime($dok['end_date']))
            : '';
        // Transaksi tanpa dokumen: skp_id dibiarkan NULL dan yang dicatat
        // transaction_id-nya. Nomor dokumennya tidak dikarang — ditulis apa
        // adanya sebagai nomor transaksi, supaya riwayat tetap bisa dilacak.
        $trxOnly = ($dok['jenis_baris'] ?? 'dok') === 'trx';
        $ins->execute([
            $pid,
            $trxOnly ? null : (int) $dok['id'],
            $dok['transaction_id'] ?: null,
            $trxOnly ? ('Transaksi #' . (int) $dok['trx_id']) : ($dok['skp_no'] ?: null),
            $dok['doc_type'] ?: null, $dok['module'] ?: null,
            $dok['client_name'] ?: null, $dok['master_code'] ?: null,
            $periode, (float) ($dok['total_amount'] ?? 0), $dok['pic_name'] ?: null,
            $alasan, $jenis, $batch, $uname, (int) ($_SESSION['user']['id'] ?? 0),
        ]);
        $ids[] = (int) $pdo->lastInsertId();
        $nilaiTotal += (float) ($dok['total_amount'] ?? 0);
    }
    audit($pdo, 'create', 'deletion_requests', implode(',', $ids),
        ['dokumen' => count($ids), 'batch_no' => $batch, 'alasan' => $alasan, 'nilai' => $nilaiTotal]);

    $set = _dr_pemutus($pdo, $pid);
    $pesan = count($ids) === 1
        ? 'Pengajuan terkirim ke ' . ($set['role_name'] ?: 'pemutus') . '. Datanya BELUM dihapus.'
        : count($ids) . ' dokumen diajukan dalam satu pengajuan (batch ' . $batch . ') ke '
          . ($set['role_name'] ?: 'pemutus') . '. Datanya BELUM dihapus.';
    if ($tolak) $pesan .= ' ' . count($tolak) . ' data dilewati karena bukan milik Anda atau sudah pernah diajukan.';
    flash($pesan);
    redirect_to('deletion_request');
}

/**
 * Hapus satu pengajuan — dipakai bersama oleh persetujuan pemutus dan oleh
 * panel sisa alur lama.
 *
 * HARUS dipanggil dari dalam transaksi basis data. Tiga hal terjadi bersamaan
 * dan harus bersamaan: alokasi dilepas, transaksi ditandai terhapus, dan
 * dokumennya ditandai terhapus. Kalau transaksinya hilang tetapi alokasinya
 * tertinggal, ada angka di laporan yang transaksinya tak bisa ditemukan di mana
 * pun.
 *
 * Mengembalikan [jumlah transaksi, jumlah baris alokasi, rupiah yang dilepas].
 */
function _dr_hapus_satu(PDO $pdo, int $pid, array $d, string $uname): array
{
    $skpId = (int) ($d['skp_id'] ?? 0);
    $id    = (int) $d['id'];

    if ($skpId > 0) {
        // Semua transaksi yang dipayungi dokumen ini — penawaran paket bisa
        // melahirkan beberapa transaksi dari satu dokumen.
        $tq = $pdo->prepare('SELECT id FROM transactions
                              WHERE property_id = ? AND deleted_at IS NULL
                                AND (skp_id = ? OR id = (SELECT s.transaction_id FROM skp_documents s WHERE s.id = ?))');
        $tq->execute([$pid, $skpId, $skpId]);
    } else {
        // Pengajuan atas transaksi yang tidak berdokumen — hanya transaksi itu.
        $tq = $pdo->prepare('SELECT id FROM transactions
                              WHERE property_id = ? AND deleted_at IS NULL AND id = ?');
        $tq->execute([$pid, (int) ($d['transaction_id'] ?? 0)]);
    }
    $trxIds = array_map('intval', $tq->fetchAll(PDO::FETCH_COLUMN));

    $nAlok = 0;
    $rpAlok = 0.0;
    foreach ($trxIds as $tid) {
        // Jumlahnya dibaca SEBELUM dihapus — sesudahnya tidak ada lagi yang bisa
        // dijumlah, padahal angka itu yang dilaporkan ke pengguna dan disimpan
        // di riwayat sebagai "sekian income lepas".
        $sum = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM transaction_allocations
                               WHERE transaction_id = ? AND property_id = ?');
        $sum->execute([$tid, $pid]);
        $rpAlok += (float) $sum->fetchColumn();

        $del = $pdo->prepare('DELETE FROM transaction_allocations WHERE transaction_id = ? AND property_id = ?');
        $del->execute([$tid, $pid]);
        $nAlok += $del->rowCount();
        $pdo->prepare('UPDATE transactions SET deleted_at = NOW(), deleted_by = ?, cancel_reason = ?
                        WHERE id = ? AND property_id = ?')
            ->execute([$uname, 'Pengajuan hapus #' . $id . ': ' . $d['alasan'], $tid, $pid]);
    }

    // Dokumennya ditandai terhapus, bukan dibuang. Nomornya sudah terbit dan
    // pernah sampai ke client — jejaknya harus tetap bisa dibuka.
    if ($skpId > 0) {
        $pdo->prepare('UPDATE skp_documents SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND property_id = ?')
            ->execute([$uname, $skpId, $pid]);
    }

    return [count($trxIds), $nAlok, $rpAlok];
}

/**
 * Pemutus menyetujui atau menolak — dan persetujuan LANGSUNG menghapus.
 *
 * Tidak ada lagi langkah superadmin menekan tombol kedua: keputusannya sudah
 * diambil di sini, dan langkah tambahan itu hanya menunda tanpa menambah
 * pengamanan. Yang menggantinya adalah tombol Pulihkan di Riwayat — kalau
 * keputusannya keliru, dikembalikan, bukan dicegah dari awal.
 */
function deletion_request_decide(PDO $pdo): void
{
    require_permission('approve_delete');
    verify_csrf();
    $pid = current_property_id();
    if (!_dr_boleh_putus($pdo, $pid)) { flash('Anda bukan pemutus pengajuan penghapusan di properti ini.'); redirect_to('deletion_request'); }

    $id      = (int) post('id');
    $setuju  = post('putusan') === 'setuju';
    $catatan = trim((string) post('catatan'));
    $uname   = (string) ($_SESSION['user']['name'] ?? 'system');
    $jab     = ApprovalLine::jabatan($pdo, $pid);

    $st = $pdo->prepare("SELECT * FROM deletion_requests WHERE id = ? AND property_id = ? AND status = 'menunggu'");
    $st->execute([$id, $pid]);
    $awal = $st->fetch(PDO::FETCH_ASSOC);
    if (!$awal) { flash('Pengajuan itu sudah diputuskan orang lain.'); redirect_to('deletion_request'); }

    // Satu pengajuan bisa berisi beberapa dokumen. Keputusannya berlaku untuk
    // seluruh batch — pemutus membaca satu alasan, jadi memutus sebagian saja
    // akan meninggalkan dokumen yang tak pernah dijawab.
    $batch = trim((string) ($awal['batch_no'] ?? ''));
    if ($batch !== '') {
        $bq = $pdo->prepare("SELECT * FROM deletion_requests
                              WHERE property_id = ? AND batch_no = ? AND status = 'menunggu' ORDER BY id");
        $bq->execute([$pid, $batch]);
        $rows = $bq->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = [$awal];
    }

    if (!$setuju) {
        $upd = $pdo->prepare("UPDATE deletion_requests
                                 SET status = 'batal', decided_by = ?, decided_role = ?,
                                     decided_at = NOW(), decision_note = ?
                               WHERE id = ? AND property_id = ? AND status = 'menunggu'");
        $n = 0;
        foreach ($rows as $r) { $upd->execute([$uname, $jab ?: null, $catatan ?: null, (int) $r['id'], $pid]); $n += $upd->rowCount(); }
        audit($pdo, 'reject', 'deletion_requests', (string) $id, ['dokumen' => $n, 'catatan' => $catatan]);
        flash('Pengajuan ditolak (' . $n . ' dokumen). Datanya tidak jadi dihapus.');
        redirect_to('deletion_request');
    }

    $pdo->beginTransaction();
    try {
        $tutup = $pdo->prepare("UPDATE deletion_requests
                                   SET status = 'dihapus', decided_by = ?, decided_role = ?, decided_at = NOW(),
                                       decision_note = ?, executed_by = ?, executed_at = NOW(),
                                       alokasi_dilepas = ?, rupiah_dilepas = ?
                                 WHERE id = ? AND property_id = ? AND status = 'menunggu'");
        $nDok = $nTrx = $nAlok = 0; $rpAlok = 0.0;
        foreach ($rows as $r) {
            [$t, $a, $rp] = _dr_hapus_satu($pdo, $pid, $r, $uname);
            $rpAlok += $rp;
            $tutup->execute([$uname, $jab ?: null, $catatan ?: null, $uname, $a, $rp, (int) $r['id'], $pid]);
            // rowCount 0 = keduluan orang lain; batalkan seluruhnya daripada
            // menghapus data yang keputusannya tidak tercatat.
            if ($tutup->rowCount() === 0) throw new RuntimeException('Pengajuan #' . (int) $r['id'] . ' sudah diputuskan orang lain.');
            $nDok++; $nTrx += $t; $nAlok += $a;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('Gagal menghapus: ' . $e->getMessage() . ' Tidak ada perubahan disimpan.');
        redirect_to('deletion_request');
    }

    audit($pdo, 'delete', 'deletion_requests', (string) $id,
        ['dokumen' => $nDok, 'batch_no' => $batch ?: null, 'transaksi' => $nTrx,
         'alokasi_dilepas' => $nAlok, 'rupiah_dilepas' => $rpAlok]);
    flash('Disetujui dan langsung dihapus: ' . $nDok . ' dokumen, ' . $nTrx . ' transaksi keluar dari laporan. '
        . ($rpAlok > 0
            ? 'Income berkurang Rp ' . number_format($rpAlok, 0, ',', '.') . ' (' . $nAlok . ' baris).'
            : 'Income tidak berkurang — dokumen ini memang belum pernah masuk laporan.')
        . ' Keliru? Ada tombol Pulihkan di Riwayat.');
    redirect_to('deletion_request');
}

/**
 * Bangun kembali alokasi income satu transaksi yang baru dipulihkan.
 *
 * Mengikuti aturan alur yang berlaku: income baru masuk laporan setelah client
 * menandatangani. Jadi dokumen yang dipulihkan tetapi belum ber-TTD sengaja
 * dibiarkan tanpa alokasi — dibangun nanti saat tanda tangan masuk. Transaksi
 * lama (dibuat sebelum aturan itu berlaku) tidak ikut aturan tersebut: sebelum
 * dihapus nilainya memang duduk di laporan, jadi dikembalikan apa adanya.
 *
 * Mengembalikan jumlah baris alokasi yang dibuat.
 */
function _dr_bangun_income(PDO $pdo, int $pid, array $trx, string $statusDok): int
{
    require_once dirname(__DIR__) . '/AllocationService.php';
    $wajibTtd = ttd_wajib_untuk($pdo, (string) ($trx['created_at'] ?? ''));
    if ($wajibTtd && $statusDok !== 'signed') return 0;

    // anchor_cycle: seluruh nilai diakui pada satu periode saja — sama persis
    // dengan perilaku saat transaksinya dibuat.
    if (($trx['billing_method'] ?? '') !== 'spread') $trx['recognition_period'] = $trx['period_key'];
    AllocationService::saveAllocations($pdo, (int) $trx['id'], $trx);

    $c = $pdo->prepare('SELECT COUNT(*) FROM transaction_allocations WHERE transaction_id = ? AND property_id = ?');
    $c->execute([(int) $trx['id'], $pid]);
    return (int) $c->fetchColumn();
}

/**
 * Pulihkan data yang sudah dihapus.
 *
 * Bisa dilakukan karena penghapusannya memang hanya penandaan: barisnya tidak
 * pernah dibuang. Yang dikembalikan tiga-tiganya — dokumen, transaksi, dan
 * alokasi income — supaya tidak ada separuh data yang hidup sendiri.
 *
 * Boleh dilakukan oleh pemutus (yang memutuskan penghapusannya) dan oleh
 * superadmin. Keduanya tercatat di riwayat, dan pengajuannya tidak dibuang:
 * statusnya menjadi 'dipulihkan' supaya rekap tetap menyimpan bahwa pernah ada
 * permintaan hapus untuk dokumen itu.
 */
function deletion_request_restore(PDO $pdo): void
{
    verify_csrf();
    $pid   = current_property_id();
    $id    = (int) post('id');
    $uname = (string) ($_SESSION['user']['name'] ?? 'system');
    $nota  = trim((string) post('catatan'));

    if (!can('manage_deleted') && !_dr_boleh_putus($pdo, $pid)) {
        flash('Hanya pemutus pengajuan atau superadmin yang bisa memulihkan data.');
        redirect_to('deletion_request');
    }

    $st = $pdo->prepare("SELECT * FROM deletion_requests WHERE id = ? AND property_id = ? AND status = 'dihapus'");
    $st->execute([$id, $pid]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) { flash('Pengajuan tidak ditemukan, atau datanya tidak dalam keadaan terhapus.'); redirect_to('deletion_request'); }

    $skpId = (int) ($d['skp_id'] ?? 0);
    $trxId = (int) ($d['transaction_id'] ?? 0);

    // Data yang sudah dipakai pengajuan lain yang masih berjalan tidak boleh
    // dipulihkan diam-diam — nanti ada dua pengajuan hidup untuk satu berkas
    // dan keputusan keduanya saling membatalkan.
    if ($skpId > 0) {
        $bentrok = $pdo->prepare("SELECT id FROM deletion_requests
                                   WHERE property_id = ? AND skp_id = ? AND id <> ? AND status = 'menunggu' LIMIT 1");
        $bentrok->execute([$pid, $skpId, $id]);
    } else {
        $bentrok = $pdo->prepare("SELECT id FROM deletion_requests
                                   WHERE property_id = ? AND skp_id IS NULL AND transaction_id = ?
                                     AND id <> ? AND status = 'menunggu' LIMIT 1");
        $bentrok->execute([$pid, $trxId, $id]);
    }
    if ($lain = $bentrok->fetchColumn()) {
        flash('Data ini punya pengajuan lain yang masih menunggu keputusan (#' . (int) $lain . '). Putuskan dulu yang itu.');
        redirect_to('deletion_request');
    }

    $pdo->beginTransaction();
    try {
        // Tanpa dokumen tidak ada status TTD yang bisa dibaca. Transaksi seperti
        // itu memang tidak pernah melewati alur tanda tangan, jadi alokasinya
        // dibangun apa adanya — persis keadaan sebelum dihapus.
        $statusDok = 'signed';
        if ($skpId > 0) {
            $sq = $pdo->prepare('SELECT status FROM skp_documents WHERE id = ? AND property_id = ?');
            $sq->execute([$skpId, $pid]);
            $statusDok = (string) ($sq->fetchColumn() ?: '');
        }

        // Transaksi yang ikut terhapus oleh pengajuan INI — dikenali dari
        // cancel_reason yang ditulis saat penghapusan, supaya transaksi yang
        // sudah dibatalkan lebih dulu karena sebab lain tidak ikut dihidupkan.
        $tanda = 'Pengajuan hapus #' . $id . ':%';
        if ($skpId > 0) {
            $tq = $pdo->prepare('SELECT * FROM transactions
                                  WHERE property_id = ? AND deleted_at IS NOT NULL AND cancel_reason LIKE ?
                                    AND (skp_id = ? OR id = (SELECT s.transaction_id FROM skp_documents s WHERE s.id = ?))');
            $tq->execute([$pid, $tanda, $skpId, $skpId]);
        } else {
            $tq = $pdo->prepare('SELECT * FROM transactions
                                  WHERE property_id = ? AND deleted_at IS NOT NULL AND cancel_reason LIKE ? AND id = ?');
            $tq->execute([$pid, $tanda, $trxId]);
        }
        $trxs = $tq->fetchAll(PDO::FETCH_ASSOC);

        $nAlok = 0; $rpPulih = 0.0;
        foreach ($trxs as $t) {
            $pdo->prepare('UPDATE transactions SET deleted_at = NULL, deleted_by = NULL, cancel_reason = NULL
                            WHERE id = ? AND property_id = ?')
                ->execute([(int) $t['id'], $pid]);
            $t['deleted_at'] = null;
            $nAlok += _dr_bangun_income($pdo, $pid, $t, $statusDok);
            $jm = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM transaction_allocations
                                  WHERE transaction_id = ? AND property_id = ?');
            $jm->execute([(int) $t['id'], $pid]);
            $rpPulih += (float) $jm->fetchColumn();
        }

        if ($skpId > 0) {
            $pdo->prepare('UPDATE skp_documents SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND property_id = ?')
                ->execute([$skpId, $pid]);
        }

        $upd = $pdo->prepare("UPDATE deletion_requests
                                 SET status = 'dipulihkan', restored_by = ?, restored_at = NOW(),
                                     restore_note = ?, alokasi_pulih = ?, rupiah_pulih = ?
                               WHERE id = ? AND property_id = ? AND status = 'dihapus'");
        $upd->execute([$uname, $nota ?: null, $nAlok, $rpPulih, $id, $pid]);
        if ($upd->rowCount() === 0) throw new RuntimeException('Pengajuan ini sudah dipulihkan orang lain.');

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('Gagal memulihkan: ' . $e->getMessage() . ' Tidak ada perubahan disimpan.');
        redirect_to('deletion_request');
    }

    audit($pdo, 'restore', 'deletion_requests', (string) $id,
        ['skp_id' => $skpId, 'transaksi' => count($trxs), 'alokasi_pulih' => $nAlok]);

    $pesan = 'Data dipulihkan: ' . count($trxs) . ' transaksi kembali ke Exhibition/Media/Gudang.';
    $pesan .= $rpPulih > 0
        ? ' Income kembali masuk laporan Rp ' . number_format($rpPulih, 0, ',', '.') . ' (' . $nAlok . ' baris).'
        : ' Income BELUM masuk laporan karena dokumennya belum ditandatangani client — akan masuk setelah TTD.';
    flash($pesan);
    redirect_to('deletion_request');
}

/**
 * Sisa alur lama: pengajuan yang sudah disetujui tetapi belum pernah
 * dieksekusi. Alur sekarang menghapus langsung saat disetujui, jadi status ini
 * tidak terbit lagi — fungsinya tetap ada hanya untuk menuntaskan baris lama.
 */
function deletion_request_execute(PDO $pdo): void
{
    require_permission('manage_deleted');
    verify_csrf();
    $pid   = current_property_id();
    $id    = (int) post('id');
    $uname = (string) ($_SESSION['user']['name'] ?? 'system');

    $st = $pdo->prepare("SELECT * FROM deletion_requests WHERE id = ? AND property_id = ? AND status = 'disetujui'");
    $st->execute([$id, $pid]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) { flash('Pengajuan tidak ditemukan atau sudah tidak dalam keadaan menunggu dieksekusi.'); redirect_to('deletion_request'); }

    $pdo->beginTransaction();
    try {
        [$nTrx, $nAlok, $rpAlok] = _dr_hapus_satu($pdo, $pid, $d, $uname);
        $upd = $pdo->prepare("UPDATE deletion_requests
                                 SET status = 'dihapus', executed_by = ?, executed_at = NOW(),
                                     alokasi_dilepas = ?, rupiah_dilepas = ?
                               WHERE id = ? AND property_id = ? AND status = 'disetujui'");
        $upd->execute([$uname, $nAlok, $rpAlok, $id, $pid]);
        if ($upd->rowCount() === 0) throw new RuntimeException('Pengajuan ini sudah dieksekusi orang lain.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('Gagal menghapus: ' . $e->getMessage() . ' Tidak ada perubahan disimpan.');
        redirect_to('deletion_request');
    }

    audit($pdo, 'delete', 'deletion_requests', (string) $id,
        ['skp_id' => (int) $d['skp_id'], 'transaksi' => $nTrx, 'alokasi_dilepas' => $nAlok]);
    flash('Data dihapus. ' . $nTrx . ' transaksi keluar dari laporan. '
        . ($rpAlok > 0 ? 'Income berkurang Rp ' . number_format($rpAlok, 0, ',', '.') . ' (' . $nAlok . ' baris).'
                       : 'Income tidak berkurang — belum pernah masuk laporan.'));
    redirect_to('deletion_request');
}

/** Superadmin menyetel siapa pemutusnya. */
function deletion_approver_save(PDO $pdo): void
{
    require_permission('manage_deleted');
    verify_csrf();
    $pid  = current_property_id();
    $role = trim((string) post('role_name'));
    $pic  = trim((string) post('pic_name'));
    if ($role === '') { flash('Jabatan pemutus wajib dipilih.'); redirect_to('deletion_request'); }

    $pdo->prepare('INSERT INTO deletion_approver (property_id, role_name, pic_name, updated_by)
                   VALUES (?,?,?,?)
                   ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), pic_name = VALUES(pic_name),
                                           updated_by = VALUES(updated_by)')
        ->execute([$pid, $role, $pic !== '' ? $pic : null, (string) ($_SESSION['user']['name'] ?? 'system')]);

    audit($pdo, 'update', 'deletion_approver', (string) $pid, ['role_name' => $role, 'pic_name' => $pic]);
    flash('Pemutus pengajuan penghapusan disimpan: ' . $role . ($pic !== '' ? ' — ' . $pic : '') . '.');
    redirect_to('deletion_request');
}
