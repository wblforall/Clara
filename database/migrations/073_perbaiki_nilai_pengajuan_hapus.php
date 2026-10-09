<?php
/**
 * Perbaiki nilai & keterangan pada pengajuan penghapusan yang terlanjur kosong.
 *
 * Saat dibuat, pengajuan menyalin keterangan dokumennya — nomor, client, unit,
 * periode, nilai — supaya riwayat tetap terbaca setelah dokumennya hilang.
 * Salinan itu diambil dari kolom di `skp_documents`, padahal untuk dokumen
 * perpanjangan dan dokumen dari Surat Penawaran kolom tersebut memang dibiarkan
 * kosong: nilainya hidup di transaksi / penawarannya. Akibatnya daftar pilihan
 * dan rekap menampilkan "Rp 0" dan unit "-".
 *
 * Kuerinya sudah diperbaiki untuk pengajuan baru. Yang lama diisi di sini.
 *
 * Transaksi yang sudah di-soft-delete IKUT dijumlahkan — pengajuan berstatus
 * 'dihapus' justru transaksinya sudah ditandai terhapus, dan nilai itulah yang
 * harus tercatat sebagai "income yang hilang".
 */

$kosong = (int) $pdo->query(
    "SELECT COUNT(*) FROM deletion_requests
      WHERE COALESCE(nilai, 0) = 0 OR COALESCE(master_code, '') = '' OR COALESCE(periode, '') = ''"
)->fetchColumn();

if ($kosong === 0) {
    echo "     tidak ada pengajuan yang perlu diperbaiki\n";
    return;
}

$baris = $pdo->query(
    "SELECT d.id, d.skp_id, d.property_id, d.nilai, d.master_code, d.periode, d.client_name,
            s.master_code  AS s_kode,  s.start_date AS s_mulai, s.end_date AS s_selesai,
            s.total_amount AS s_nilai, s.transaction_id, s.offer_id,
            t.master_code  AS t_kode,  t.start_date AS t_mulai, t.end_date AS t_selesai,
            o.master_code  AS o_kode,  o.start_date AS o_mulai, o.end_date AS o_selesai,
            o.total_calculated AS o_nilai,
            COALESCE(sc.company_name, tc.company_name, oc.company_name) AS nama_client,
            (SELECT SUM(COALESCE(NULLIF(t2.final_amount, 0), t2.total_calculated))
               FROM transactions t2
              WHERE t2.property_id = s.property_id
                AND (t2.skp_id = s.id OR t2.id = s.transaction_id)) AS nilai_trx
       FROM deletion_requests d
       JOIN skp_documents s      ON s.id = d.skp_id
       LEFT JOIN transactions t  ON t.id = s.transaction_id
       LEFT JOIN offers o        ON o.id = s.offer_id
       LEFT JOIN master_clients sc ON sc.id = s.client_id
       LEFT JOIN master_clients tc ON tc.id = t.client_id
       LEFT JOIN master_clients oc ON oc.id = o.client_id
      WHERE COALESCE(d.nilai, 0) = 0 OR COALESCE(d.master_code, '') = '' OR COALESCE(d.periode, '') = ''"
)->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare('UPDATE deletion_requests
                         SET nilai = ?, master_code = ?, periode = ?, client_name = ?
                       WHERE id = ?');
$n = 0;
foreach ($baris as $b) {
    $nilai = (float) ($b['nilai_trx'] ?: ($b['s_nilai'] ?: ($b['o_nilai'] ?: 0)));
    if ($nilai <= 0) $nilai = (float) $b['nilai'];          // tidak ada sumber → biarkan apa adanya

    $kode = $b['master_code'] ?: ($b['s_kode'] ?: ($b['t_kode'] ?: $b['o_kode']));

    $periode = $b['periode'];
    if (trim((string) $periode) === '') {
        $mulai   = $b['s_mulai'] ?: ($b['t_mulai'] ?: $b['o_mulai']);
        $selesai = $b['s_selesai'] ?: ($b['t_selesai'] ?: $b['o_selesai']);
        $periode = ($mulai && $selesai)
            ? date('d/m/Y', strtotime((string) $mulai)) . ' s/d ' . date('d/m/Y', strtotime((string) $selesai))
            : '';
    }

    $client = $b['client_name'] ?: $b['nama_client'];

    $upd->execute([$nilai, $kode ?: null, $periode ?: '', $client ?: null, (int) $b['id']]);
    $n += $upd->rowCount();
}
echo "     $n pengajuan diperbaiki nilai/unit/periodenya (dari $kosong yang kosong)\n";
