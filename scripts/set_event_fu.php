<?php
/**
 * Mengisi "Nama Event" — dan bila perlu membetulkan tanggal pemakaian — pada
 * Form Utilities yang SUDAH TERLANJUR disubmit dan sedang menunggu approval.
 *
 * Nama Event baru ada sejak kolomnya dibuat; dokumen yang diisi sebelumnya
 * tidak punya tempat untuk itu. Mengisinya lewat layar menuntut dokumennya
 * ditolak dulu supaya bisa diedit, lalu menempuh ulang seluruh persetujuannya
 * — padahal yang ditambahkan hanya satu baris keterangan di surat.
 *
 * Tanggal boleh ikut dibetulkan, TETAPI hanya bila jumlah harinya sama persis.
 * Harga item bersatuan "/Titik/Hari" dikalikan jumlah hari masa sewa, jadi
 * menggeser durasinya akan menggeser angka di Rincian Biaya tanpa ada yang
 * memeriksanya — dan dokumen ini sudah di meja manager.
 *
 * Tanpa --terapkan tidak ada satu baris pun yang diubah.
 *
 * Pakai:
 *   php scripts/set_event_fu.php --doc=469
 *   php scripts/set_event_fu.php --doc=469 --event="Event BYD" --terapkan
 *   php scripts/set_event_fu.php --doc=478 --event="Event Gaikindo" \
 *       --mulai=2026-11-23 --akhir=2026-11-29 --terapkan
 *
 * --lokasi= : membetulkan "Lokasi Kegiatan" yang tercetak di surat. Isian itu
 *             terisi otomatis dari nama titik medianya, jadi dokumen yang
 *             memakai lebih dari satu jenis media (mis. T-Banner DAN test
 *             drive) hanya menyebut salah satunya.
 *   php scripts/set_event_fu.php --doc=469 --event="Event BYD" \
 *       --lokasi="T-Banner & Test Drive - PJU 1 A (Depan Dunkin Donuts)" --terapkan
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';

/** Tanggal gaya Indonesia — sama persis dengan skp_tgl() yang dipakai surat.
 *  Ditulis ulang di sini supaya skrip tidak perlu memuat berkas halaman. */
function sef_tgl(string $d): string
{
    $t = strtotime($d);
    if (!$t) return '';
    $bln = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return (int) date('j', $t) . ' ' . $bln[(int) date('n', $t)] . ' ' . date('Y', $t);
}

$arg      = getopt('', ['doc:', 'event:', 'lokasi:', 'mulai:', 'akhir:', 'terapkan']);
$terapkan = array_key_exists('terapkan', $arg);
$docId    = (int) ($arg['doc'] ?? 0);
if ($docId <= 0) { fwrite(STDERR, "Sebutkan dokumennya: --doc=469\n"); exit(2); }

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$st = $pdo->prepare('SELECT id, skp_no, doc_type, status, start_date, end_date, total_amount,
                            transaction_id, snapshot_json, detail_json
                       FROM skp_documents WHERE id = ?');
$st->execute([$docId]);
$doc = $st->fetch();
if (!$doc)                             { fwrite(STDERR, "Dokumen $docId tidak ada.\n"); exit(2); }
if (($doc['doc_type'] ?? '') !== 'fu') { fwrite(STDERR, "Dokumen $docId bukan Form Utilities.\n"); exit(2); }

$det = json_decode((string) $doc['detail_json'], true);
if (!is_array($det)) { fwrite(STDERR, "Dokumen $docId belum punya isian detail.\n"); exit(2); }

$hari = function (string $a, string $b): int {
    $ta = strtotime($a); $tb = strtotime($b);
    return ($ta && $tb && $tb >= $ta) ? (int) round(($tb - $ta) / 86400) + 1 : 0;
};
$hariLama = $hari((string) $doc['start_date'], (string) $doc['end_date']);

echo "\nFORM UTILITIES #{$doc['id']}  " . ($doc['skp_no'] ?: '(nomor belum terbit)')
   . "  status \"{$doc['status']}\"\n" . str_repeat('=', 74) . "\n";
printf("  Masa sewa   : %s s/d %s (%d hari)\n", $doc['start_date'], $doc['end_date'], $hariLama);
printf("  Lokasi      : %s\n", (string) ($det['lokasi_kegiatan'] ?? '-'));
printf("  Tgl di surat: %s\n", (string) ($det['tanggal_pemakaian'] ?? '-'));
printf("  Nama Event  : %s\n", trim((string) ($det['nama_event'] ?? '')) ?: '(belum diisi)');
printf("  Nilai       : Rp %s\n\n", number_format((float) $doc['total_amount'], 0, ',', '.'));

// ── Dokumen yang isinya sudah dibekukan tidak bisa dibetulkan dari sini ─────
if (trim((string) ($doc['snapshot_json'] ?? '')) !== '' || (int) ($doc['transaction_id'] ?? 0) > 0) {
    fwrite(STDERR, "BERHENTI: dokumen ini sudah disetujui — isinya dibekukan sebagai salinan\n"
                 . "(snapshot) dan transaksinya sudah terbit. Perubahan di sini tidak akan\n"
                 . "tercetak di suratnya. Tempuh alur revisi.\n");
    exit(2);
}

$event  = array_key_exists('event', $arg) ? trim((string) $arg['event']) : null;
$lokasi = array_key_exists('lokasi', $arg) ? trim((string) $arg['lokasi']) : null;
$mulai = isset($arg['mulai']) ? trim((string) $arg['mulai']) : '';
$akhir = isset($arg['akhir']) ? trim((string) $arg['akhir']) : '';
$ubahTgl = $mulai !== '' || $akhir !== '';

if ($event === null && $lokasi === null && !$ubahTgl) {
    echo "  Sebutkan yang mau diisi, mis.:\n";
    echo "    php scripts/set_event_fu.php --doc=$docId --event=\"Event BYD\"\n\n";
    exit(0);
}

if ($ubahTgl) {
    foreach (['--mulai' => $mulai, '--akhir' => $akhir] as $nm => $v) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || strtotime($v) === false) {
            fwrite(STDERR, "$nm harus berbentuk YYYY-MM-DD.\n"); exit(2);
        }
    }
    $hariBaru = $hari($mulai, $akhir);
    if ($hariBaru <= 0) { fwrite(STDERR, "Tanggal akhir mendahului tanggal mulai.\n"); exit(2); }
    if ($hariBaru !== $hariLama) {
        fwrite(STDERR, "BERHENTI: jumlah harinya berubah ($hariLama hari → $hariBaru hari).\n"
                     . "Harga item bersatuan \"/Titik/Hari\" dikalikan jumlah hari, jadi Rincian\n"
                     . "Biaya ikut bergeser tanpa diperiksa siapa pun. Betulkan lewat alur revisi.\n");
        exit(2);
    }
    printf("  AKAN DIUBAH\n    Masa sewa : %s s/d %s  →  %s s/d %s  (tetap %d hari)\n",
        $doc['start_date'], $doc['end_date'], $mulai, $akhir, $hariBaru);
}
if (!$ubahTgl) echo "  AKAN DIUBAH\n";
if ($lokasi !== null) printf("    Lokasi    : \"%s\"  →  \"%s\"\n", (string) ($det['lokasi_kegiatan'] ?? ''), $lokasi);
if ($event !== null)  printf("    Nama Event: %s\n", $event !== '' ? '"' . $event . '"' : '(dikosongkan)');
echo "\n";

if (!$terapkan) {
    echo "  SIMULASI — belum ada yang diubah. Tambahkan --terapkan untuk menyimpan.\n\n";
    exit(1);
}

if ($event !== null)  $det['nama_event'] = mb_substr($event, 0, 160);
if ($lokasi !== null) $det['lokasi_kegiatan'] = mb_substr($lokasi, 0, 190);
if ($ubahTgl) {
    // Tanggal yang TERCETAK disusun ulang dengan pembentuk yang sama dengan
    // formulirnya, supaya surat dan sistem tidak pernah menyebut dua tanggal.
    $det['tanggal_pemakaian'] = sef_tgl($mulai) . ' – ' . sef_tgl($akhir);
}

$sql = 'UPDATE skp_documents SET detail_json = :dj';
$par = [':dj' => json_encode($det, JSON_UNESCAPED_UNICODE), ':id' => $docId];
if ($ubahTgl) { $sql .= ', start_date = :m, end_date = :a'; $par[':m'] = $mulai; $par[':a'] = $akhir; }
$sql .= ' WHERE id = :id';
$pdo->prepare($sql)->execute($par);

audit($pdo, 'update', 'skp_documents', (string) $docId,
    array_filter(['nama_event' => $event, 'lokasi_kegiatan' => $lokasi, 'start_date' => $ubahTgl ? $mulai : null,
                  'end_date' => $ubahTgl ? $akhir : null], fn($v) => $v !== null),
    ['start_date' => $doc['start_date'], 'end_date' => $doc['end_date'],
     'nama_event' => (string) (json_decode((string) $doc['detail_json'], true)['nama_event'] ?? '')], 'skp');

// Dibaca ULANG dari basis data — yang dilaporkan hasil akhirnya, bukan niatnya.
$st->execute([$docId]);
$u  = $st->fetch();
$ud = json_decode((string) $u['detail_json'], true);
printf("  TERSIMPAN — dibaca ulang:\n    Lokasi      : %s\n    Masa sewa   : %s s/d %s\n    Tgl di surat: %s\n    Nama Event  : %s\n",
    (string) ($ud['lokasi_kegiatan'] ?? '-'),
    $u['start_date'], $u['end_date'],
    (string) ($ud['tanggal_pemakaian'] ?? '-'),
    trim((string) ($ud['nama_event'] ?? '')) ?: '(kosong)');
echo "  Buka pratinjau suratnya untuk memastikan.\n\n";
