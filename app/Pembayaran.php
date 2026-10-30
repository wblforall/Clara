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


/** "2026-10" → "Okt 2026" (pendek) atau "Oktober 2026" (panjang). */
function pembayaran_nama_bulan(string $periode, bool $panjang = false): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $periode, $m)) return $periode;
    $pendek = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $penuh  = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $b = (int) $m[2];
    return (($panjang ? $penuh[$b] : $pendek[$b]) ?? $m[2]) . ' ' . $m[1];
}

/**
 * Porsi nilai kontrak yang diakui pada SATU bulan, untuk tiap baris tagihan.
 *
 * Kontrak recurring tidak ditagih sekaligus. McD Rp 763.543.440 berjalan 34
 * bulan; yang ditagih Oktober cuma porsi Oktobernya. Selama kolom Tagihan
 * menampilkan nilai kontrak penuh, daftar ini terbaca seolah ada tunggakan
 * ratusan juta padahal belum jatuh tempo.
 *
 * Dasarnya `transaction_allocations` — tabel yang sama yang dipakai Laporan
 * PIC dan Dashboard, jadi porsi di sini selalu sama dengan income bulan itu.
 *
 * Porsi TIDAK dipakai bila jumlah invoice sudah diisi sendiri: begitu invoice
 * terbit dengan angkanya sendiri, angka itulah tagihannya, bukan hasil bagi.
 *
 * Satu kueri untuk seluruh baris di layar, bukan satu kueri per baris.
 */
function pembayaran_porsi_bulan(PDO $pdo, array $rows, string $periode): array
{
    if (!$rows) return $rows;
    $ids = array_values(array_filter(array_unique(array_map(fn($r) => (int) ($r['id'] ?? 0), $rows))));
    $satuBulan = $periode !== '' && $periode !== 'semua';
    $peta = [];
    if ($ids) {
        $tanda = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT transaction_id,
                    COUNT(DISTINCT CASE WHEN amount > 0 THEN period_key END) AS bln,
                    COALESCE(SUM(CASE WHEN period_key = ? THEN amount END), 0) AS porsi
               FROM transaction_allocations
              WHERE transaction_id IN ($tanda)
              GROUP BY transaction_id"
        );
        // Periode "semua" sengaja dicocokkan dengan nilai yang mustahil ada:
        // porsinya memang tidak berlaku kalau bulannya tidak dipilih.
        $st->execute(array_merge([$satuBulan ? $periode : '-'], $ids));
        foreach ($st->fetchAll() as $a) $peta[(int) $a['transaction_id']] = $a;
    }
    foreach ($rows as $i => $r) {
        $a = $peta[(int) ($r['id'] ?? 0)] ?? null;
        $bln   = $a ? (int) $a['bln'] : 0;
        $porsi = $a ? (float) $a['porsi'] : 0.0;
        $pakai = $satuBulan && $bln > 1 && ($r['invoice_amount'] ?? null) === null;
        $rows[$i]['bagi_n']     = $bln;
        $rows[$i]['bagi_porsi'] = $porsi;
        $rows[$i]['bagi_pakai'] = $pakai;
        // Angka yang benar-benar ditagihkan bulan ini — dipakai kartu ringkasan
        // dan ekspor supaya daftar, jumlah, dan unduhan tidak saling berbeda.
        $rows[$i]['tagihan_bulan'] = $pakai ? $porsi : (float) ($r['tagihan'] ?? 0);
    }
    return $rows;
}

/**
 * Nama unit untuk sekumpulan baris transaksi.
 *
 * Tim menyebut unitnya dengan NAMA ("1/FASHION BOOTH LG"), bukan dengan kode
 * ("LG-003") — di berkas mereka memang nama itulah yang tertulis di kolom
 * pertama. Kode tetap ditampilkan kecil di bawahnya supaya yang sudah hafal
 * kode tidak kehilangan pegangan.
 *
 * Satu kueri per modul untuk seluruh baris di layar, bukan satu per baris.
 */
function pembayaran_nama_unit(PDO $pdo, array $rows, int $pid): array
{
    if (!$rows) return $rows;
    $perModul = [];
    foreach ($rows as $r) {
        $m = (string) ($r['module'] ?? 'cl');
        $k = trim((string) ($r['master_code'] ?? ''));
        if ($k !== '') $perModul[$m][$k] = true;
    }
    $sql = [
        'cl'     => "SELECT code, location_name nm FROM master_cl_units WHERE property_id = ? AND code IN (%s)",
        'media'  => "SELECT code, CONCAT_WS(' ', media_type, location, NULLIF(point,'')) nm FROM master_media WHERE property_id = ? AND code IN (%s)",
        'gudang' => "SELECT code, name nm FROM master_gudang WHERE property_id = ? AND code IN (%s)",
    ];
    $nama = [];
    foreach ($perModul as $m => $kode) {
        if (!isset($sql[$m])) continue;
        $k = array_keys($kode);
        $st = $pdo->prepare(sprintf($sql[$m], implode(',', array_fill(0, count($k), '?'))));
        $st->execute(array_merge([$pid], $k));
        foreach ($st->fetchAll() as $u) $nama[$m . '|' . $u['code']] = trim((string) $u['nm']);
    }
    foreach ($rows as $i => $r) {
        $kunci = ($r['module'] ?? 'cl') . '|' . ($r['master_code'] ?? '');
        $rows[$i]['unit_name'] = $nama[$kunci] ?? '';
    }
    return $rows;
}

/**
 * Lengkapi baris tagihan dengan komponen yang TIDAK termasuk nilai transaksi:
 * biaya listrik dan security deposit.
 *
 * Inilah sebab pembayaran kerap terlihat lebih besar daripada tagihannya. Dari
 * 693 baris berbayar di berkas tim (Des 2025—Sep 2026), 361 dibayar lebih
 * besar, dan 175 di antaranya selisihnya tepat Rp 166.500 — listrik Rp 150.000
 * plus PPN. Listriknya tercetak di surat penawaran dan dokumen konfirmasi,
 * tetapi tidak pernah masuk nilai transaksi, jadi tidak ikut terhitung di
 * kolom Tagihan. Client membayarnya sekalian, maka bayarannya tampak berlebih.
 *
 * Yang dihitung di sini tidak mengubah angka Tagihan — status lunas/sebagian
 * tetap memakai rumus yang sama seperti sebelumnya. Ia hanya memperlihatkan
 * komponennya, supaya selisih yang wajar tidak perlu ditelusuri ulang tiap
 * bulan.
 *
 * Satu kueri untuk seluruh baris di layar, bukan satu kueri per baris.
 */
function pembayaran_lengkapi_komponen(PDO $pdo, array $rows): array
{
    if (!$rows) return $rows;
    require_once __DIR__ . '/pages/skp.php';   // skp_listrik() — rumusnya satu, di sana

    $idTrx = array_values(array_unique(array_map(fn($r) => (int) $r['id'], $rows)));
    $idSkp = array_values(array_filter(array_unique(array_map(fn($r) => (int) ($r['skp_id'] ?? 0), $rows))));
    $tanda = fn(array $a) => implode(',', array_fill(0, count($a), '?'));

    $sql = "SELECT id, transaction_id, electricity_flag, electricity_amount, electricity_monthly,
                   electricity_units, deposit_amount, deposit_paid, ppn_flag
              FROM skp_documents
             WHERE deleted_at IS NULL AND (transaction_id IN (" . $tanda($idTrx) . ")";
    $arg = $idTrx;
    if ($idSkp) { $sql .= " OR id IN (" . $tanda($idSkp) . ")"; $arg = array_merge($arg, $idSkp); }
    $sql .= ") ORDER BY id";
    $st = $pdo->prepare($sql);
    $st->execute($arg);

    $perTrx = []; $perId = [];
    foreach ($st->fetchAll() as $d) {
        $perId[(int) $d['id']] = $d;
        if ((int) $d['transaction_id'] > 0) $perTrx[(int) $d['transaction_id']] = $d;   // terbaru menang
    }

    foreach ($rows as $i => $r) {
        $d = $perTrx[(int) $r['id']] ?? $perId[(int) ($r['skp_id'] ?? 0)] ?? null;
        $listrik = $d ? skp_listrik($d, (string) $r['start_date'], (string) $r['end_date']) : 0.0;
        // Deposit yang sudah disetor di kontrak sebelumnya tidak ditagih ulang.
        $deposit = ($d && empty($d['deposit_paid'])) ? (float) $d['deposit_amount'] : 0.0;
        $sewa    = (float) $r['tagihan'];
        $kenaPpn = !$d || !empty($d['ppn_flag']);
        // PPN sesuai PMK 131/2024: nilai x 11/12 x 12%.
        $ppn     = $kenaPpn ? round(($sewa + $listrik) * 11 / 12 * 0.12) : 0.0;
        $rows[$i] += [
            'k_sewa' => $sewa, 'k_ppn' => $ppn, 'k_listrik' => $listrik, 'k_deposit' => $deposit,
            'k_perkiraan' => $sewa + $ppn + $listrik + $deposit,
        ];
    }
    return $rows;
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
    // Dokumen terakhir = id terbesar di antara (dokumen ber-transaction_id ini) dan
    // (dokumen yang ditunjuk skp_id). transaction_id unik, jadi tiap cabang paling
    // banyak satu baris dan keduanya memakai indeks. Bentuk lama "… OR …
    // ORDER BY id DESC LIMIT 1" memaksa pemindaian tabel per baris: ±350 ms
    // untuk satu bulan Kalender, hasilnya sama persis.
    $skp = fn(string $kol) => "(SELECT s2.$kol FROM skp_documents s2
                     WHERE s2.id = GREATEST(
                         COALESCE((SELECT x.id FROM skp_documents x WHERE x.transaction_id = $t.id AND x.property_id = $t.property_id), 0),
                         COALESCE((SELECT y.id FROM skp_documents y WHERE y.id = $t.skp_id AND y.property_id = $t.property_id), 0)))";
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
function pembayaran_boleh_lihat(PDO $pdo, array $trx): bool
{
    // Melihat dan mengisi dipisah. Manager perlu memeriksa tagihan tanpa boleh
    // mengubah angkanya; yang memegang manage_payments otomatis boleh melihat,
    // supaya tidak ada peran yang harus diberi dua izin sekaligus.
    if (!can('view_payments') && !can('manage_payments')) return false;
    return _pembayaran_dalam_lingkup($pdo, $trx);
}

function pembayaran_boleh_isi(PDO $pdo, array $trx): bool
{
    if (!can('manage_payments')) return false;
    return _pembayaran_dalam_lingkup($pdo, $trx);
}

/** Sales hanya menyentuh barisnya sendiri; peran lain bebas. */
function _pembayaran_dalam_lingkup(PDO $pdo, array $trx): bool
{
    $scope = current_sales_scope($pdo, (int) $trx['property_id']);
    if (!$scope) return true;
    if ($scope['pic'] !== '' && (string) $trx['pic_name'] === $scope['pic']) return true;
    return (string) $trx['created_by'] === $scope['uname'];
}
