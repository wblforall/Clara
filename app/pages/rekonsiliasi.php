<?php

/**
 * Rekonsiliasi: menyandingkan spreadsheet kerja tim dengan isi CLARA.
 *
 * Tim Casual Leasing memegang satu berkas Excel yang diperbarui tiap bulan.
 * Selama ini tidak ada cara memeriksa apakah isinya sama dengan yang tercatat
 * di sistem: yang terlihat cuma totalnya meleset, tanpa petunjuk beda di mana.
 * Halaman ini menjawab pertanyaan itu per baris — mana yang belum diinput, mana
 * yang nominalnya beda, mana yang dicatat dua kali, dan mana yang ada di sistem
 * tapi tidak ada di catatan.
 *
 * Istilah hasilnya sengaja sama dengan audit manual yang pernah dibagikan ke
 * tim: cocok / beda nominal / belum diinput / ada di CLARA saja.
 *
 * Berkas yang diunggah TIDAK disimpan di public/uploads — di sana berkas .xlsx
 * bisa diunduh siapa saja tanpa login (lihat public/uploads/.htaccess yang
 * hanya memblokir berkas gambar & pdf). Berkasnya ditaruh di direktori
 * sementara sistem, dipakai selama sesi, lalu dihapus.
 */

require_once dirname(__DIR__) . '/XlsxReader.php';
require_once dirname(__DIR__) . '/ReconCL.php';
require_once dirname(__DIR__) . '/ReconMatch.php';

/** Batas ukuran unggahan: ikut batas PHP, maksimal 12 MB. */
function _rekon_batas_unggah(): array
{
    $ke = function (string $v): int {
        $v = trim($v); $n = (int) $v; $s = strtoupper(substr($v, -1));
        return $n * (['K' => 1024, 'M' => 1048576, 'G' => 1073741824][$s] ?? 1);
    };
    $batas = 12 * 1048576;
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $v = $ke((string) ini_get($k));
        if ($v > 0) $batas = min($batas, $v);
    }
    return [$batas, round($batas / 1048576, 1) . ' MB'];
}

/** Letak berkas unggahan yang sedang dipakai sesi ini (null bila belum ada). */
function _rekon_berkas(): ?array
{
    $b = $_SESSION['rekon_berkas'] ?? null;
    if (!is_array($b) || !is_file($b['path'] ?? '')) return null;
    return $b;
}

function _rekon_hapus_berkas(): void
{
    $b = $_SESSION['rekon_berkas'] ?? null;
    if (is_array($b) && is_file($b['path'] ?? '')) @unlink($b['path']);
    unset($_SESSION['rekon_berkas']);
}

/**
 * Terima unggahan .xlsx.
 *
 * @return string pesan kesalahan ('' bila berhasil)
 */
function _rekon_terima_unggahan(): string
{
    [$batas, $batasTeks] = _rekon_batas_unggah();
    $f = $_FILES['berkas'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Berkas belum dipilih.';
    if (($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE)
        return 'Berkas terlalu besar — maksimal ' . $batasTeks . '.';
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) return 'Unggahan gagal (kode ' . (int) $f['error'] . '). Coba ulangi.';
    if ((int) $f['size'] <= 0) return 'Berkas kosong (0 byte).';
    if ((int) $f['size'] > $batas) return 'Berkas ' . round($f['size'] / 1048576, 1) . ' MB, melebihi batas ' . $batasTeks . '.';

    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') return 'Jenis berkas harus .xlsx. Di Excel: Simpan Sebagai → Excel Workbook (.xlsx).';

    $tujuan = tempnam(sys_get_temp_dir(), 'clara-rekon-');
    if ($tujuan === false) return 'Sistem tidak bisa menyiapkan berkas sementara. Hubungi admin.';
    if (!@move_uploaded_file($f['tmp_name'], $tujuan)) {
        @unlink($tujuan);
        return 'Berkas gagal dipindahkan ke tempat sementara. Coba ulangi.';
    }
    $x = new XlsxReader($tujuan);
    if (!$x->valid()) { @unlink($tujuan); return 'Berkas ini bukan Excel .xlsx yang bisa dibaca (mungkin .xls lama atau rusak).'; }

    _rekon_hapus_berkas();
    $_SESSION['rekon_berkas'] = ['path' => $tujuan, 'nama' => basename((string) $f['name']), 'waktu' => time()];
    return '';
}

/**
 * Baca satu sheet jadi baris siap banding.
 *
 * @return array{baris:array,bulanAda:array,sales:array,peringatan:array,jenis:string}
 */
function _rekon_baca(XlsxReader $x, string $target, string $jenis, int $tahun, string $periode): array
{
    if ($jenis === 'monitoring') {
        $h = ReconCL::bacaMonitoring($x, $target);
        $bulan = $h['bulan'] ?: $periode;
        $baris = [];
        foreach ($h['baris'] as $b) {
            if ($b['nilai'] == 0.0) continue;
            $baris[] = ['klien' => $b['klien'], 'brand' => $b['brand'], 'kode' => '', 'nilai' => $b['nilai'],
                        'mulai' => $b['mulai'], 'selesai' => $b['selesai'], 'pic' => $b['sales'],
                        'rujukan' => 'baris ' . $b['baris']];
        }
        return ['baris' => $baris, 'bulanAda' => [$bulan], 'sales' => $h['sales'],
                'peringatan' => $h['peringatan'], 'jenis' => 'monitoring'];
    }

    $h = ReconCL::bacaKalender($x, $target, $tahun);
    $baris = [];
    foreach ($h['baris'] as $b) {
        if ($periode !== '' && $b['bulan'] !== $periode) continue;
        // Satu baris kalender bisa memuat nominal untuk beberapa PIC sekaligus
        // (sewa yang dibagi). Tiap porsi jadi barisnya sendiri supaya
        // pembandingnya setara dengan baris alokasi di CLARA.
        foreach ($b['per_pic'] as $pic => $v) {
            if ($v == 0.0) continue;
            $baris[] = ['klien' => $b['tenant'], 'brand' => $b['tenant'], 'kode' => $b['unit'], 'nilai' => $v,
                        'mulai' => $b['mulai'], 'selesai' => $b['selesai'], 'pic' => $pic,
                        'rujukan' => 'baris ' . $b['baris']];
        }
    }
    return ['baris' => $baris, 'bulanAda' => $h['bulanAda'], 'sales' => $h['sales'],
            'total_sheet' => $h['total_sheet'][$periode] ?? null,
            'peringatan' => $h['peringatan'], 'jenis' => 'kalender'];
}

/** Daftar sheet + jenisnya (hasilnya dipakai dua kali: pilihan & pembacaan). */
function _rekon_daftar_sheet(XlsxReader $x): array
{
    $out = [];
    foreach ($x->sheets() as $nama => $target) {
        $awal = []; $n = 0;
        foreach ($x->rows($target, 14) as $sel) { $awal[] = $sel; if (++$n >= 14) break; }
        $jenis = ReconCL::jenisSheet($awal);
        if ($jenis === '' || $jenis === 'rekap') continue;    // rekap tidak per transaksi
        $out[$nama] = ['target' => $target, 'jenis' => $jenis];
    }
    return $out;
}

/** Tahun yang tertulis pada nama sheet (untuk sheet kalender). */
function _rekon_tahun_sheet(string $nama): int
{
    return preg_match('/(20\d{2})/', $nama, $m) ? (int) $m[1] : (int) date('Y');
}

/**
 * Saring hasil pencocokan untuk SATU PIC saja.
 *
 * Pencocokannya tetap dijalankan atas SELURUH baris lebih dulu, baru hasilnya
 * disaring. Kalau disaring sebelum dicocokkan, sewa yang di CLARA terlanjur
 * tercatat atas nama orang lain tidak akan pernah ketemu pasangannya — ia malah
 * muncul sebagai "belum diinput" di satu orang dan "hanya di CLARA" di orang
 * lain, dan dua-duanya salah.
 *
 * Baris "beda PIC" sengaja ikut bila SALAH SATU sisinya milik PIC ini: yang
 * kehilangan income dan yang kelebihan sama-sama perlu melihatnya.
 */
function _rekon_saring_pic(array $hasil, string $pic): array
{
    if ($pic === '') return $hasil;
    $sama = fn($a) => ReconCL::normal((string) ($a ?? '')) === ReconCL::normal($pic);

    foreach (['cocok', 'nilai', 'pic', 'kontrak'] as $k) {
        $hasil[$k] = array_values(array_filter($hasil[$k], fn($b) => $sama($b['excel']['pic'] ?? '') || $sama($b['clara']['pic'] ?? '')));
    }
    // Baris gabungan punya BEBERAPA transaksi CLARA di satu kelompok.
    $hasil['gabungan'] = array_values(array_filter($hasil['gabungan'], function ($g) use ($sama) {
        if ($sama($g['excel']['pic'] ?? '')) return true;
        foreach ($g['clara'] as $c) if ($sama($c['pic'] ?? '')) return true;
        return false;
    }));
    foreach (['input', 'lebih'] as $k) {
        $hasil[$k] = array_values(array_filter($hasil[$k], fn($b) => $sama($b['pic'] ?? '')));
    }
    foreach (['dobel_excel', 'dobel_clara'] as $k) {
        // Satu kelompok dobel bisa berisi baris milik BEBERAPA PIC (unit yang
        // sama diinput dua orang). Dulu yang diperiksa hanya baris pertama,
        // sehingga kelompok yang baris pertamanya milik orang lain hilang dari
        // layar PIC ini — padahal justru dia yang kelebihan income.
        $hasil[$k] = array_values(array_filter($hasil[$k], function ($d) use ($sama) {
            foreach ($d['baris'] as $b) if ($sama($b['pic'] ?? '')) return true;
            return false;
        }));
    }

    $R = $hasil['ringkas'];
    $R['n_cocok']    = count($hasil['cocok']);
    $R['n_gabungan'] = count($hasil['gabungan']);
    $R['n_kontrak']  = count($hasil['kontrak']);
    $R['rp_kontrak'] = array_sum(array_column($hasil['kontrak'], 'selisih'));
    $R['n_nilai'] = count($hasil['nilai']);
    $R['n_pic']   = count($hasil['pic']);
    $R['n_input'] = count($hasil['input']);
    $R['n_lebih'] = count($hasil['lebih']);
    $R['rp_nilai'] = array_sum(array_column($hasil['nilai'], 'selisih'));
    $R['rp_input'] = array_sum(array_column($hasil['input'], 'nilai'));
    $R['rp_lebih'] = array_sum(array_column($hasil['lebih'], 'nilai'));
    $hasil['ringkas'] = $R;
    return $hasil;
}

/**
 * Langkah perbaikan untuk tiap jenis temuan.
 *
 * Ditulis memakai nama menu dan tulisan tombol yang BENAR-BENAR ada di layar
 * (sudah dicocokkan satu per satu dengan halamannya), supaya orang yang
 * membacanya tidak perlu bertanya "yang mana tombolnya". Langkah yang menyebut
 * tombol yang tidak ada lebih buruk daripada tidak ada langkah sama sekali.
 *
 * @return array{judul:string,arti:string,langkah:array<int,string>,catatan:string}
 */
function _rekon_langkah(string $jenis): array
{
    $peta = [
        'nilai' => [
            'judul' => 'Nominalnya beda',
            'arti'  => 'Penyewa yang sama ketemu di kedua sisi, tapi angkanya tidak sama. Salah satu salah ketik — belum tentu CLARA yang salah.',
            'langkah' => [
                'Tekan <strong>Buka transaksi</strong> di baris yang mau dibetulkan — langsung ke halaman <em>Edit Transaksi</em>. (Kotak pencarian di daftar transaksi tidak bisa mencari nomor, jadi tombol ini memang jalan tercepat.)',
                'Bandingkan dulu dengan dokumennya: <strong>SKP</strong>/invoice yang ditandatangani client. Yang benar adalah yang tertulis di dokumen, bukan yang paling besar.',
                'Kalau yang salah di CLARA: betulkan kotak <strong>Override Aktual</strong> — itulah kotak yang dipakai hampir semua transaksi di sistem ini, bukan kotak “Rate”.',
                '<strong>Khusus sewa bulanan/recurring:</strong> kotak nominal <strong>per bulan</strong> sudah terisi angka lama begitu form dibuka, dan angka itu MENANG atas Override Aktual. Setelah mengubah Override, tekan <strong>Kalkulasi Total</strong> lalu <strong>Pakai Semua Baru</strong> — kalau tidak, income tidak bergeser sama sekali meski sudah disimpan.',
                'Kalau yang beda hanya pada SATU bulan (mis. sewa mulai di tengah bulan), cukup betulkan kotak bulan itu saja.',
                'Simpan, lalu periksa hasilnya di menu <strong>Laporan PIC</strong> pada bulan yang sama.',
                'Kalau yang salah justru di Excel: betulkan angkanya di spreadsheet tim, CLARA tidak perlu disentuh.',
            ],
            'catatan' => 'Urutan yang menang: kotak per bulan → Override Aktual → Rate. Kalau dokumennya sudah BER-TTD client, nilainya terkunci: yang bisa dilakukan hanya “Jadwal Harga → Terapkan” untuk bulan yang belum lewat, atau hapus dokumennya lalu terbitkan ulang.',
        ],
        'input' => [
            'judul' => 'Ada di Excel, belum ada di CLARA',
            'arti'  => 'Sewanya tercatat di spreadsheet tapi belum ada di sistem — atau sudah ada tapi belum ditandatangani client.',
            'langkah' => [
                '<strong>Periksa dulu:</strong> buka menu <strong>SKP Pameran</strong> dan cari nama penyewanya. Kalau dokumennya sudah ada tapi belum ber-TTD client, <u>jangan dibuat ulang</u> — income memang baru diakui saat client menandatangani, bukan saat manager menyetujui. Kejar tanda tangannya.',
                'Kalau benar-benar belum ada, siapkan clientnya lebih dulu: menu <strong>Master Client</strong>, ketik namanya di pencarian. <em>Client tidak bisa ditambahkan dari dalam formulir penawaran</em>, jadi harus dari sini.',
                'Client belum terdaftar? Tekan <strong>Tambah Client</strong>, isi <strong>Nama Perusahaan</strong>, <strong>Provinsi</strong>, dan <strong>Kota</strong> (tiga ini wajib, sisanya boleh menyusul), lalu Simpan. Kontak orangnya ditambahkan lewat <strong>+ Tambah Kontak</strong> yang baru muncul SESUDAH client tersimpan.',
                '<strong>Kalau ini sewa Exhibition:</strong> buka <strong>Surat Penawaran</strong> → <strong>+ Buat Penawaran Exhibition</strong>. Di kolom <strong>Client / Perusahaan</strong>, ketik nama atau brand-nya lalu pilih dari daftar yang muncul. Isi unit, periode, dan harganya, lalu Simpan.',
                'Setelah client setuju, tandai penawarannya <strong>DEAL</strong>, lalu tekan <strong>→ Buat SKP (Konfirmasi)</strong>.',
                '<strong>Kalau ini sewa Gudang atau Media:</strong> jangan lewat Surat Penawaran. Buka <strong>SKP Pameran</strong> → <strong>+ Buat Dokumen ▾</strong> → pilih <strong>SKS Gudang</strong> atau <strong>Form Utilities</strong> (Media).',
                'Lengkapi dokumennya, lalu tekan <strong>Simpan &amp; Submit untuk Approval</strong>.',
                'Dokumen diparaf <strong>Asst. Manager</strong>, lalu disetujui <strong>Manager</strong>.',
                'Terakhir, minta <strong>tanda tangan client</strong>. Begitu ditandatangani, nilainya otomatis masuk ke income bulan yang bersangkutan.',
            ],
            'catatan' => 'Input transaksi langsung sudah dimatikan (mode offer-first) — satu-satunya pengecualian adalah tombol Perpanjang pada kontrak yang sudah ada. Selama belum ber-TTD, wajar kalau transaksinya sudah terlihat tapi income-nya masih nol.',
        ],
        'lebih' => [
            'judul' => 'Ada di CLARA, tidak ada di Excel',
            'arti'  => 'Sistem punya, spreadsheet tidak. Belum tentu salah — bisa jadi Excel yang belum diperbarui.',
            'langkah' => [
                'Tekan <strong>Buka transaksi</strong> untuk melihat isinya: client, unit, periode, dan nilainya.',
                'Cocokkan dengan dokumen fisik. <strong>Kalau sewanya memang ada</strong> → tambahkan barisnya di spreadsheet tim, selesai; CLARA sudah benar.',
                'Kalau ternyata <strong>kembar</strong> dengan baris lain (unit, periode, dan nilai sama) → lihat bagian “Dugaan dobel” di bawah, lalu ajukan penghapusan.',
                'Kalau sewanya <strong>batal</strong> atau tidak pernah terjadi → ajukan penghapusan lewat <strong>Input → Pengajuan Hapus Data</strong> dengan jenis alasan <strong>Client batal</strong>.',
            ],
            'catatan' => 'Jangan langsung menghapus hanya karena tidak ada di Excel. Spreadsheet juga bisa tertinggal.',
        ],
        'pic' => [
            'judul' => 'Tercatat atas nama PIC berbeda',
            'arti'  => 'Nilainya sama, tapi sales-nya tidak sama. Satu baris salah orang mengubah pencapaian DUA orang sekaligus.',
            'langkah' => [
                'Pastikan dulu siapa yang benar — lihat siapa yang menandatangani Surat Penawaran/SKP-nya.',
                'Tekan <strong>Buka transaksi</strong>, ubah kolom <strong>PIC Dealing</strong> ke nama yang benar, lalu Simpan.',
                'Periksa hasilnya di menu <strong>Laporan PIC</strong> pada bulan yang sama — namanya harus sudah berpindah.',
                '<strong>Kalau di Laporan PIC belum berpindah juga</strong>, berarti transaksinya terkunci (dokumennya sudah ber-TTD) dan alokasinya tidak ikut ditulis ulang. Minta <strong>Manager</strong> membuka transaksi itu, mengosongkan panel <strong>Pembagian Income per PIC</strong>, lalu menekan <strong>Simpan Pembagian</strong> — itu yang menulis ulang nama PIC di laporan.',
            ],
            'catatan' => 'Pembagian ke beberapa PIC butuh izin Approve SKP (sales tidak punya) dan jumlahnya WAJIB pas dengan nilai kontrak — kalau tidak pas, pembagiannya diabaikan diam-diam tanpa pesan kesalahan.',
        ],
        'dobel' => [
            'judul' => 'Dugaan dobel',
            'arti'  => 'Penyewa, unit, nominal, dan bulan sama persis lebih dari sekali. Belum tentu salah — bisa dua periode berbeda.',
            'langkah' => [
                'Buka kedua transaksinya lewat tombol <strong>Buka transaksi</strong> dan bandingkan <strong>tanggal mulai dan selesainya</strong>. Kalau periodenya berbeda, itu dua sewa yang sah — abaikan.',
                'Kalau benar-benar kembar, tentukan mana yang <strong>dipertahankan</strong>: yang dokumennya paling lengkap (berlencana <em>Ber-TTD Client</em>) selalu disisakan; yang <em>Draft</em> atau <em>Dikembalikan</em> yang dihapus.',
                'Catat <strong>nama client</strong> dan <strong>kode unit</strong>-nya (mis. “Resita Cecilia Achwan”, “UG-005”).',
                'Buka sidebar grup <strong>Input</strong> → menu <strong>Pengajuan Hapus Data</strong>.',
                'Gulir ke panel <strong>Ajukan penghapusan</strong>, lalu ketik <strong>nama client</strong> atau <strong>kode unit</strong> tadi di kotak <em>“Cari nomor SKP / transaksi, client, atau unit…”</em>.',
                '<strong>Jangan mengetik nomor transaksi</strong> (mis. 2094) untuk data yang sudah berdokumen — kolom yang dicari memuat nomor SKP, bukan nomor transaksi, jadi hasilnya akan kosong padahal datanya ada.',
                'Centang baris yang mau dihapus. Boleh beberapa sekaligus dalam satu pengajuan.',
                'Pilih <strong>Jenis alasan</strong> → <strong>Dobel / duplikat</strong>.',
                'Tulis <strong>Alasan dihapus</strong> dengan jelas, sebutkan kembarannya — mis. “dobel dengan dokumen SKP/EW/2026/067, unit dan periode sama persis”.',
                'Tekan <strong>Kirim Pengajuan</strong>, lalu beri tahu <strong>Manager</strong> untuk menekan <strong>✓ Setujui &amp; Hapus</strong>.',
                'Begitu disetujui, datanya langsung terhapus saat itu juga dan income bulan itu ikut turun.',
            ],
            'catatan' => 'Sales hanya melihat data atas namanya sendiri di halaman itu. Kalau keliru hapus, masih ada tombol ↩ Pulihkan di panel Riwayat — tapi yang bisa menekannya hanya Manager/superadmin, bukan yang mengajukan.',
        ],
    ];
    return $peta[$jenis] ?? ['judul' => '', 'arti' => '', 'langkah' => [], 'catatan' => ''];
}

function rekonsiliasi_page(PDO $pdo): void
{
    require_permission('view_pic_report');
    $pid = current_property_id();
    $prop = current_property();
    $pesan = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (post('aksi') === 'hapus') { _rekon_hapus_berkas(); redirect_to('rekon'); }
        $pesan = _rekon_terima_unggahan();
        if ($pesan === '') redirect_to('rekon');
    }

    $berkas = _rekon_berkas();
    $x = $berkas ? new XlsxReader($berkas['path']) : null;
    $sheets = $x ? _rekon_daftar_sheet($x) : [];

    // Sheet & bulan yang sedang dilihat. Bawaannya sheet pertama yang namanya
    // memuat nama properti aktif — tim menamai tabnya "e-Walk 2026 - 2".
    $pilihSheet = (string) getv('sheet', '');
    if ($sheets && ($pilihSheet === '' || !isset($sheets[$pilihSheet]))) {
        $kunci = ($prop['key'] ?? '') === 'pentacity' ? 'pentacity' : 'walk';
        foreach ($sheets as $nama => $s) {
            if (stripos($nama, $kunci) !== false) { $pilihSheet = $nama; break; }
        }
        if ($pilihSheet === '') $pilihSheet = array_key_first($sheets);
    }

    $hasil = null; $perPic = []; $peta = []; $isi = null; $periode = (string) getv('periode', '');
    $pilihPic = ''; $daftarPic = [];
    if ($x && $pilihSheet !== '' && isset($sheets[$pilihSheet])) {
        $s = $sheets[$pilihSheet];
        $tahun = _rekon_tahun_sheet($pilihSheet);
        // Dibaca dua kali: sekali untuk tahu bulan apa saja yang ada di sheet,
        // sekali lagi untuk bulan yang dipilih. Berkasnya kecil (±0,3 detik).
        $semua = _rekon_baca($x, $s['target'], $s['jenis'], $tahun, '');
        $bulanAda = $semua['bulanAda'];
        if ($periode === '' || !in_array($periode, $bulanAda, true)) {
            // Bawaannya bulan terakhir yang CLARA-nya sudah berisi — bulan yang
            // belum berjalan pasti "selisih", dan itu bukan temuan.
            $periode = $bulanAda ? end($bulanAda) : '';
            foreach (array_reverse($bulanAda) as $bl) {
                $c = $pdo->prepare('SELECT COUNT(*) FROM transaction_allocations WHERE property_id=? AND period_key=? AND amount<>0');
                $c->execute([$pid, $bl]);
                if ((int) $c->fetchColumn() > 0) { $periode = $bl; break; }
            }
        }
        $isi = _rekon_baca($x, $s['target'], $s['jenis'], $tahun, $periode);
        $clara = ReconCL::dariClara($pdo, $pid, $periode);

        // Nama PIC dipadankan dulu (MUSI → Muslimin), kalau tidak setiap baris
        // akan terbaca "beda PIC" dan laporannya penuh selisih palsu.
        $peta = ReconCL::petakanPic(
            array_values(array_unique(array_column($isi['baris'], 'pic'))),
            array_values(array_unique(array_column($clara, 'pic')))
        );
        foreach ($isi['baris'] as $i => $b) {
            if (($peta[$b['pic']] ?? '') !== '') $isi['baris'][$i]['pic'] = $peta[$b['pic']];
        }
        $hasil  = ReconMatch::cocokkan($isi['baris'], $clara);
        $perPic = ReconMatch::perPic($isi['baris'], $clara);

        // Efek "kontrak panjang" per PIC, dihitung SEBELUM penyaringan PIC.
        // Tanpa ini, tabel rekap menyebut selisih kotor — angka yang membuat
        // orang mengejar 141 juta padahal yang benar-benar perlu dikerjakan
        // cuma 5 juta, sisanya beda cara mencatat.
        foreach ($hasil['kontrak'] as $kk) {
            $nm = ReconCL::normal((string) ($kk['clara']['pic'] ?: ($kk['excel']['pic'] ?? '')));
            if ($nm === '') continue;
            $perPic[$nm]['kontrak'] = ($perPic[$nm]['kontrak'] ?? 0) + (float) $kk['selisih'];
        }
        foreach ($perPic as $kk => $vv) {
            $perPic[$kk]['kontrak'] = (float) ($vv['kontrak'] ?? 0);
            $perPic[$kk]['kerja']   = $vv['selisih'] - $perPic[$kk]['kontrak'];
        }

        // Pilihan PIC: daftarnya diambil dari kedua sisi, supaya PIC yang hanya
        // ada di salah satunya (mis. sudah tidak aktif) tetap bisa diperiksa.
        $daftarPic = [];
        foreach ($perPic as $pp) $daftarPic[$pp['pic']] = $pp;
        $pilihPic = (string) getv('pic', '');
        if ($pilihPic !== '' && !isset($daftarPic[$pilihPic])) $pilihPic = '';
        if ($pilihPic !== '') {
            $hasil = _rekon_saring_pic($hasil, $pilihPic);
            $angkaPic = $daftarPic[$pilihPic];
            // Angka ringkasan ikut jadi milik PIC itu saja; kalau tidak, kartu
            // di atas akan menyebut angka setim sementara tabel di bawahnya
            // hanya memuat satu orang — dua angka yang bertengkar di satu layar.
            $hasil['ringkas']['total_excel'] = $angkaPic['excel'];
            $hasil['ringkas']['total_clara'] = $angkaPic['clara'];
            $hasil['ringkas']['selisih']     = $angkaPic['selisih'];
            $hasil['ringkas']['n_excel']     = $angkaPic['n_excel'];
            $hasil['ringkas']['n_clara']     = $angkaPic['n_clara'];
        }
    }

    layout('Rekonsiliasi Excel', function () use ($berkas, $sheets, $pilihSheet, $periode, $isi, $hasil, $perPic, $peta, $pesan, $prop, $pilihPic, $daftarPic) {
        $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $bulanNama = function (string $p): string {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) return $p;
            return ReconCL::BULAN[(int) $m[2] - 1] . ' ' . $m[1];
        };
        ?>
        <style>
        .rk-kartu{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin-bottom:14px}
        .rk-kartu .k{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px}
        .rk-kartu .k b{display:block;font-size:19px;margin-top:3px}
        .rk-kartu .k span{font-size:12px;color:var(--muted)}
        .rk-ok{color:#0f766e}.rk-kurang{color:#b45309}.rk-lebih{color:#be123c}
        .rk-pil{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:700}
        .rk-nol{background:#ecfdf5;color:#047857}.rk-beda{background:#fef3c7;color:#92400e}
        .rk-form{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
        .rk-rinci summary{cursor:pointer;font-weight:700;padding:9px 0}
        .rk-rinci table{width:100%;font-size:13px}
        .rk-langkah{background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #0D9488;border-radius:10px;padding:10px 14px;margin:6px 0 10px}
        .rk-langkah .jd{font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#0f766e;margin-bottom:4px}
        .rk-langkah ol{margin:0 0 0 18px;padding:0}
        .rk-langkah li{margin-bottom:5px;line-height:1.55;font-size:12.5px}
        .rk-langkah .ct{margin-top:7px;font-size:12px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 9px}
        @media(max-width:768px){.rk-form{flex-direction:column;align-items:stretch}}
        </style>

        <?php if ($pesan !== ''): ?>
        <div class="panel" style="border-left:4px solid #dc2626;background:#fef2f2"><strong>Gagal:</strong> <?= h($pesan) ?></div>
        <?php endif; ?>

        <div class="panel">
            <h3 style="margin-top:0">1. Berkas Excel tim</h3>
            <?php if (!$berkas): ?>
            <p class="help">Unggah berkas rekap Casual Leasing (.xlsx). Berkasnya hanya dibaca untuk
               dibandingkan, tidak disimpan ke dalam aplikasi, dan hilang saat Anda keluar.</p>
            <form method="post" enctype="multipart/form-data" class="rk-form" style="margin-top:8px">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <div><input type="file" name="berkas" accept=".xlsx" required></div>
                <button class="btn" type="submit">Unggah &amp; Bandingkan</button>
            </form>
            <?php else: ?>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                <div>📄 <strong><?= h($berkas['nama']) ?></strong>
                     <span class="help">· diunggah <?= h(date('d/m/Y H:i', (int) $berkas['waktu'])) ?></span></div>
                <form method="post" style="margin:0">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="aksi" value="hapus">
                    <button class="btn" type="submit" style="background:#e2e8f0;color:#334155">Ganti berkas</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($berkas && $sheets): ?>
        <div class="panel">
            <h3 style="margin-top:0">2. Sheet &amp; bulan</h3>
            <form method="get" class="rk-form">
                <input type="hidden" name="r" value="rekon">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700">Sheet</label>
                    <select name="sheet" onchange="this.form.submit()">
                        <?php foreach ($sheets as $nama => $s): ?>
                        <option value="<?= h($nama) ?>" <?= $nama === $pilihSheet ? 'selected' : '' ?>>
                            <?= h($nama) ?> — <?= h($s['jenis']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700">Bulan</label>
                    <select name="periode" onchange="this.form.submit()">
                        <?php foreach (($isi['bulanAda'] ?? []) as $bl): ?>
                        <option value="<?= h($bl) ?>" <?= $bl === $periode ? 'selected' : '' ?>><?= h($bulanNama($bl)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700">PIC</label>
                    <select name="pic" onchange="this.form.submit()">
                        <option value="">Semua PIC (seluruh tim)</option>
                        <?php foreach ($daftarPic as $nm => $pp): ?>
                        <option value="<?= h($nm) ?>" <?= $nm === $pilihPic ? 'selected' : '' ?>>
                            <?php $kj = (float) ($pp['kerja'] ?? $pp['selisih']); ?>
                            <?= h($nm) ?><?= abs($kj) < 1 ? ' — sudah cocok' : ' — perlu dikerjakan ' . $rp($kj) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="help">Properti aktif: <strong><?= h($prop['name'] ?? '-') ?></strong></div>
            </form>
            <?php if ($peta): ?>
            <div class="help" style="margin-top:8px">Nama PIC dipadankan otomatis:
                <?php foreach ($peta as $e => $c): ?>
                <span class="rk-pil <?= $c !== '' ? 'rk-nol' : 'rk-beda' ?>"><?= h($e) ?> → <?= h($c !== '' ? $c : 'belum cocok') ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php
            /* Pemeriksaan mandiri: sheet punya baris "TOTAL / REALISASI" sendiri
               di kaki tiap bulan. Hasil baca sistem dijumlahkan lalu dibandingkan
               dengan baris itu. Kalau berbeda, berarti ada baris yang terlewat
               atau terhitung dua kali — dan itu harus kelihatan, bukan lolos jadi
               "selisih" yang menuduh CLARA. Pemeriksaan ini pernah menemukan dua
               hal sekaligus: salah baca di sistem, dan subtotal yang keliru di
               spreadsheet-nya sendiri. */
            $ts = $isi['total_sheet'] ?? null;
            if ($ts && ($ts['actual'] ?? 0) > 0):
                $bacaActual = 0.0;
                foreach (($isi['baris'] ?? []) as $b) $bacaActual += 0;   // nominal per PIC, bukan ACTUAL
                $jmlPicSheet = array_sum($ts['pic'] ?? []);
                $jmlPicBaca  = array_sum(array_column($isi['baris'] ?? [], 'nilai'));
                $cocokTotal  = $jmlPicSheet > 0 ? abs($jmlPicSheet - $jmlPicBaca) < 1 : null;
            ?>
            <div class="help" style="margin-top:6px">
                Pemeriksaan pembacaan:
                jumlah baris yang terbaca <strong><?= $rp($jmlPicBaca) ?></strong>
                <?php if ($jmlPicSheet > 0): ?>
                · baris TOTAL di sheet <strong><?= $rp($jmlPicSheet) ?></strong>
                <span class="rk-pil <?= $cocokTotal ? 'rk-nol' : 'rk-beda' ?>">
                    <?= $cocokTotal ? 'cocok' : 'beda ' . $rp($jmlPicBaca - $jmlPicSheet) ?></span>
                <?php if ($cocokTotal === false): ?>
                <br>Periksa dulu spreadsheet-nya: ada baris yang tidak ikut terjumlah di sana, atau sebaliknya.
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php foreach (($isi['peringatan'] ?? []) as $p): ?>
            <div class="help" style="color:#b45309">⚠ <?= h($p) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($hasil): $R = $hasil['ringkas']; ?>
        <?php if ($pilihPic !== ''): ?>
        <div class="panel" style="border-left:4px solid #0D9488;margin-bottom:12px">
            <h3 style="margin:0">Berkas kerja <?= h($pilihPic) ?> — <?= h($bulanNama($periode)) ?></h3>
            <p class="help" style="margin:4px 0 0">Seluruh angka di bawah ini hanya milik <?= h($pilihPic) ?>.
               Ganti ke <em>Semua PIC</em> di atas untuk melihat satu tim.</p>
        </div>
        <?php endif; ?>
        <div class="rk-kartu">
            <div class="k"><span>Menurut Excel</span><b><?= $rp($R['total_excel']) ?></b>
                <span><?= (int) $R['n_excel'] ?> baris</span></div>
            <?php
            /* Angka CLARA adalah income yang DIAKUI bulan ini. Untuk sewa jangka
               panjang, spreadsheet menulis nilai kontrak utuh di bulan mulainya,
               jadi dua angka ini tidak setara. "Setara" di bawah menyamakan
               dasarnya supaya bisa dibandingkan langsung tanpa salah sangka. */
            $setara = $R['total_clara'] - (float) ($R['rp_kontrak'] ?? 0);
            ?>
            <div class="k"><span>Tercatat di CLARA</span><b><?= $rp($R['total_clara']) ?></b>
                <span><?= (int) $R['n_clara'] ?> baris<?php if (abs((float) ($R['rp_kontrak'] ?? 0)) >= 1): ?>
                    <br>setara <strong><?= $rp($setara) ?></strong> bila kontrak panjang dihitung utuh seperti di Excel
                <?php endif; ?></span></div>
            <?php
            /* Angka yang DITONJOLKAN adalah yang benar-benar perlu dikerjakan:
               selisih kotor dikurangi kontrak panjang, yang di spreadsheet
               ditulis utuh sekali sementara CLARA mengakuinya per bulan.
               Selisih kotornya tetap ditulis di bawahnya, supaya tidak ada
               angka yang disembunyikan. */
            $rpKontrak  = (float) ($R['rp_kontrak'] ?? 0);
            $perluKerja = $R['selisih'] - $rpKontrak;
            ?>
            <div class="k" style="<?= abs($rpKontrak) >= 1 ? 'border-color:#0D9488;border-width:2px' : '' ?>">
                <span><?= abs($rpKontrak) >= 1 ? 'Selisih yang perlu dikerjakan' : 'Selisih' ?></span>
                <b class="<?= abs($perluKerja) < 1 ? 'rk-ok' : ($perluKerja < 0 ? 'rk-kurang' : 'rk-lebih') ?>">
                    <?= abs($perluKerja) < 1 ? 'Rp 0 — beres' : $rp($perluKerja) ?></b>
                <span><?php if (abs($rpKontrak) >= 1): ?>
                    selisih kotor <?= $rp($R['selisih']) ?>, <?= $rp(abs($rpKontrak)) ?> di antaranya kontrak panjang
                <?php else: ?>
                    <?= $R['selisih'] < 0 ? 'CLARA lebih kecil' : ($R['selisih'] > 0 ? 'CLARA lebih besar' : 'tidak ada beda') ?>
                <?php endif; ?></span></div>
            <div class="k"><span>Baris sudah cocok</span><b class="rk-ok"><?= (int) $R['n_cocok'] + (int) ($R['n_gabungan'] ?? 0) ?></b>
                <span>dari <?= (int) $R['n_excel'] ?> baris Excel</span></div>
        </div>

        <?php if ((int) $R['n_clara'] === 0): ?>
        <div class="panel" style="border-left:4px solid #b45309;background:#fffbeb">
            <strong>CLARA belum punya catatan apa pun untuk <?= h($bulanNama($periode)) ?>.</strong>
            <p class="help" style="margin:4px 0 0">Seluruh isi spreadsheet akan terbaca sebagai &ldquo;belum diinput&rdquo;,
            dan itu bukan temuan. Biasanya karena bulannya di luar jangkauan data CLARA — pilih bulan lain,
            atau pastikan properti yang aktif sudah benar.</p>
        </div>
        <?php endif; ?>

        <div class="panel">
            <h3 style="margin-top:0">Rekap per PIC — <?= h($bulanNama($periode)) ?></h3>
            <p class="help" style="margin:0 0 6px">Kolom terakhir sudah dikurangi selisih <strong>kontrak panjang</strong>
               — sewa yang di spreadsheet ditulis utuh sekali, sedangkan CLARA mengakuinya per bulan.
               Itu beda cara mencatat, bukan data yang hilang.</p>
            <table>
                <thead><tr><th>PIC</th><th style="text-align:right">Menurut Excel</th>
                    <th style="text-align:right">Tercatat di CLARA</th>
                    <th style="text-align:right">Perlu dikerjakan</th></tr></thead>
                <tbody>
                <?php foreach ($perPic as $p): ?>
                <tr>
                    <td data-label="PIC">
                        <?php /* Nama PIC jadi tautan: inilah cara tercepat masuk ke berkas
                                 kerja satu orang — tanpa harus turun ke pemilih di atas. */ ?>
                        <a href="?r=rekon&amp;sheet=<?= urlencode($pilihSheet) ?>&amp;periode=<?= urlencode($periode) ?>&amp;pic=<?= urlencode($p['pic']) ?>"
                           style="font-weight:700;<?= $p['pic'] === $pilihPic ? 'color:#0f766e' : '' ?>"><?= h($p['pic']) ?></a>
                        <span class="help">(<?= (int) $p['n_excel'] ?> / <?= (int) $p['n_clara'] ?> baris)</span></td>
                    <td data-label="Excel" style="text-align:right"><?= $rp($p['excel']) ?></td>
                    <td data-label="CLARA" style="text-align:right"><?= $rp($p['clara']) ?></td>
                    <?php $kerja = (float) ($p['kerja'] ?? $p['selisih']); $kon = (float) ($p['kontrak'] ?? 0); ?>
                    <td data-label="Selisih" style="text-align:right">
                        <span class="rk-pil <?= abs($kerja) < 1 ? 'rk-nol' : 'rk-beda' ?>">
                            <?= abs($kerja) < 1 ? 'sama' : $rp($kerja) ?></span>
                        <?php if (abs($kon) >= 1): ?>
                        <div class="help" style="margin-top:3px">kotor <?= $rp($p['selisih']) ?> ·
                            <?= $rp(abs($kon)) ?> kontrak panjang</div>
                        <?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h3 style="margin-top:0">Rincian selisih</h3>
            <p class="help">Angka di Excel dibandingkan dengan nilai yang DIAKUI CLARA pada bulan ini
               (tabel alokasi) — sumber yang sama dengan Laporan PIC, bukan hitungan baru.</p>

            <?php /* Dua bagian ini BUKAN temuan — justru penjelasan kenapa angka
                     yang kelihatan berbeda sebenarnya sudah benar. Ditaruh paling
                     atas supaya orang tidak terlanjur mengejar yang tidak salah. */ ?>
            <?php if (!empty($hasil['gabungan'])): ?>
            <details class="rk-rinci">
                <summary style="color:#0f766e">Sudah cocok, hanya terpecah — <?= count($hasil['gabungan']) ?> unit</summary>
                <p class="help" style="margin:0 0 6px">Satu baris kalender di Excel berisi satu UNIT untuk sebulan,
                   sedangkan di CLARA unit itu dipakai beberapa penyewa bergantian dalam bulan yang sama.
                   Jumlahnya sudah sama persis — <strong>tidak ada yang perlu dikerjakan</strong>.</p>
                <table>
                    <thead><tr><th>Unit di Excel</th><th style="text-align:right">Nilai di Excel</th>
                        <th>Terdiri dari transaksi CLARA</th><th style="text-align:right">Jumlah</th></tr></thead>
                    <tbody>
                    <?php foreach ($hasil['gabungan'] as $g): ?>
                    <tr>
                        <td data-label="Unit"><strong><?= h($g['excel']['kode'] ?: $g['excel']['klien']) ?></strong>
                            <span class="help"><?= h($g['excel']['rujukan']) ?></span></td>
                        <td data-label="Excel" style="text-align:right"><?= $rp($g['excel']['nilai']) ?></td>
                        <td data-label="Transaksi">
                            <?php foreach ($g['clara'] as $c): ?>
                            <div><?= h($c['klien']) ?> · <?= $rp($c['nilai']) ?>
                                <a href="?r=transaction_edit&amp;id=<?= (int) ltrim($c['rujukan'], '#') ?>" target="_blank"
                                   class="help" style="text-decoration:underline"><?= h($c['rujukan']) ?></a></div>
                            <?php endforeach; ?>
                        </td>
                        <td data-label="Jumlah" style="text-align:right">
                            <span class="rk-pil rk-nol"><?= $rp($g['jumlah']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endif; ?>

            <?php if (!empty($hasil['kontrak'])): ?>
            <details class="rk-rinci">
                <summary style="color:#0f766e">Kontrak panjang — beda cara mencatat, bukan selisih (<?= count($hasil['kontrak']) ?> baris)</summary>
                <p class="help" style="margin:0 0 6px">Spreadsheet menulis <strong>nilai kontrak utuh</strong> sekali di bulan
                   kontraknya mulai. CLARA mengakuinya <strong>sebulan demi sebulan</strong> sesuai masa sewa.
                   Keduanya benar — <strong>jangan diinput ulang dan jangan dihapus</strong>.</p>
                <table>
                    <thead><tr><th>Penyewa</th><th>Masa kontrak</th>
                        <th style="text-align:right">Nilai kontrak (Excel)</th>
                        <th style="text-align:right">Diakui bulan ini</th><th>Tindakan</th></tr></thead>
                    <tbody>
                    <?php foreach ($hasil['kontrak'] as $k): ?>
                    <tr>
                        <td data-label="Penyewa"><?= h($k['clara']['klien']) ?>
                            <?php if (($k['clara']['brand'] ?? '') !== ''): ?><span class="help"><?= h($k['clara']['brand']) ?></span><?php endif; ?></td>
                        <td data-label="Masa"><?= h(($k['clara']['mulai'] ?? '-') . ' s/d ' . ($k['clara']['selesai'] ?? '-')) ?></td>
                        <td data-label="Nilai kontrak" style="text-align:right"><?= $rp($k['excel']['nilai']) ?></td>
                        <td data-label="Bulan ini" style="text-align:right"><?= $rp($k['clara']['nilai']) ?></td>
                        <td data-label="Tindakan">
                            <a class="btn light" style="font-size:11.5px;padding:3px 9px" target="_blank"
                               href="?r=transaction_edit&amp;id=<?= (int) ltrim($k['clara']['rujukan'], '#') ?>">Buka transaksi</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endif; ?>

            <?php
            $bagian = [
                ['nilai', 'Nominalnya beda', (int) $R['n_nilai'], $rp($R['rp_nilai']),
                 'Penyewa yang sama ketemu di kedua sisi, tapi angkanya tidak sama. Salah satu salah ketik.'],
                ['input', 'Ada di Excel, belum ada di CLARA', (int) $R['n_input'], $rp($R['rp_input']),
                 'Kemungkinan belum diinput, atau dokumennya belum ditandatangani client (income baru diakui setelah TTD).'],
                ['lebih', 'Ada di CLARA, tidak ada di Excel', (int) $R['n_lebih'], $rp($R['rp_lebih']),
                 'Perlu dicek: bisa jadi Excel-nya yang belum diperbarui, atau transaksinya dobel.'],
                ['pic', 'Tercatat atas nama PIC berbeda', (int) $R['n_pic'], '',
                 'Nilainya sama, tapi PIC-nya tidak sama — mengubah pencapaian dua orang sekaligus.'],
            ];
            foreach ($bagian as [$kunci, $judul, $n, $nilai, $ket]):
                $baris = $hasil[$kunci] ?? [];
                if (!$baris) continue;
            ?>
            <details class="rk-rinci" <?= $kunci === 'nilai' ? 'open' : '' ?>>
                <summary><?= h($judul) ?> — <?= $n ?> baris<?= $nilai !== '' ? ' (' . $nilai . ')' : '' ?></summary>
                <?php $L = _rekon_langkah($kunci); ?>
                <p class="help" style="margin:0 0 6px"><?= h($L['arti'] ?: $ket) ?></p>
                <?php if ($L['langkah']): ?>
                <div class="rk-langkah">
                    <div class="jd">Yang harus dikerjakan</div>
                    <ol><?php foreach ($L['langkah'] as $lk): ?><li><?= $lk ?></li><?php endforeach; ?></ol>
                    <?php if ($L['catatan'] !== ''): ?>
                    <div class="ct">⚠ <?= $L['catatan'] ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <table>
                    <thead><tr><th>Penyewa</th><th>Unit</th><th>PIC</th>
                        <th style="text-align:right">Excel</th><th style="text-align:right">CLARA</th>
                        <th style="text-align:right">Selisih</th><th>Letak</th><th>Tindakan</th></tr></thead>
                    <tbody>
                    <?php foreach ($baris as $b):
                        $e = $b['excel'] ?? ($kunci === 'input' ? $b : null);
                        $c = $b['clara'] ?? ($kunci === 'lebih' ? $b : null);
                        $sel = isset($b['selisih']) ? (float) $b['selisih']
                             : ($kunci === 'input' ? -(float) $b['nilai'] : (float) $b['nilai']);
                    ?>
                    <tr>
                        <td data-label="Penyewa"><?= h($e['klien'] ?? $c['klien'] ?? '-') ?>
                            <?php if (($c['brand'] ?? '') !== '' && ($c['brand'] ?? '') !== ($c['klien'] ?? '')): ?>
                            <span class="help"><?= h($c['brand']) ?></span><?php endif; ?></td>
                        <td data-label="Unit"><?= h($e['kode'] ?? $c['kode'] ?? '-') ?></td>
                        <td data-label="PIC"><?= h($e['pic'] ?? $c['pic'] ?? '-') ?>
                            <?php if ($kunci === 'pic'): ?><span class="help">→ <?= h($c['pic'] ?? '-') ?></span><?php endif; ?></td>
                        <td data-label="Excel" style="text-align:right"><?= $e ? $rp($e['nilai']) : '—' ?></td>
                        <td data-label="CLARA" style="text-align:right"><?= $c ? $rp($c['nilai']) : '—' ?></td>
                        <td data-label="Selisih" style="text-align:right"><?= $rp($sel) ?></td>
                        <td data-label="Letak"><span class="help"><?= h(($e['rujukan'] ?? '') . ($e && $c ? ' / ' : '') . ($c['rujukan'] ?? '')) ?></span></td>
                        <?php
                        /* Tautan langsung ke layar tempat baris ini diperbaiki.
                           Nomor transaksi CLARA disimpan sebagai "#1776" pada
                           rujukan, jadi tinggal diambil angkanya. */
                        $trxId = preg_match('/#(\d+)/', (string) ($c['rujukan'] ?? ''), $mm) ? (int) $mm[1] : 0;
                        ?>
                        <td data-label="Tindakan">
                            <?php if ($trxId): ?>
                            <a class="btn light" style="font-size:11.5px;padding:3px 9px" target="_blank"
                               href="?r=transaction_edit&amp;id=<?= $trxId ?>">Buka transaksi</a>
                            <?php elseif ($kunci === 'input'): ?>
                            <a class="btn light" style="font-size:11.5px;padding:3px 9px" target="_blank"
                               href="?r=clients">Cek client</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endforeach; ?>

            <?php foreach ([['dobel_excel', 'Dugaan dobel di Excel'], ['dobel_clara', 'Dugaan dobel di CLARA']] as [$k, $judul]):
                if (empty($hasil[$k])) continue; ?>
            <details class="rk-rinci">
                <summary><?= h($judul) ?> — <?= count($hasil[$k]) ?> kelompok</summary>
                <?php $L = _rekon_langkah('dobel'); ?>
                <p class="help" style="margin:0 0 6px"><?= h($L['arti']) ?></p>
                <div class="rk-langkah">
                    <div class="jd">Yang harus dikerjakan</div>
                    <ol><?php foreach ($L['langkah'] as $lk): ?><li><?= $lk ?></li><?php endforeach; ?></ol>
                    <div class="ct">⚠ <?= $L['catatan'] ?></div>
                    <div style="margin-top:8px"><a class="btn" href="?r=deletion_request" target="_blank">Buka Pengajuan Hapus Data</a></div>
                </div>
                <table>
                    <thead><tr><th>Penyewa</th><th>Unit</th><th>Nominal</th><th>Berulang</th><th style="text-align:right">Kelebihan bila dobel</th></tr></thead>
                    <tbody>
                    <?php foreach ($hasil[$k] as $d): ?>
                    <tr>
                        <td data-label="Penyewa"><?= h($d['baris'][0]['klien']) ?></td>
                        <td data-label="Unit"><?= h($d['baris'][0]['kode'] ?: '-') ?></td>
                        <td data-label="Nominal"><?= $rp($d['baris'][0]['nilai']) ?></td>
                        <td data-label="Berulang"><?= (int) $d['jumlah'] ?> ×
                            <span class="help"><?= h(implode(', ', array_column($d['baris'], 'rujukan'))) ?></span></td>
                        <td data-label="Kelebihan" style="text-align:right"><?= $rp($d['kelebihan']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endforeach; ?>

            <?php if (!$R['n_nilai'] && !$R['n_input'] && !$R['n_lebih'] && !$R['n_pic']): ?>
            <p style="color:#047857;font-weight:700">✓ Seluruh baris cocok. Tidak ada yang perlu diperbaiki di bulan ini.</p>
            <?php endif; ?>
        </div>
        <?php elseif ($berkas && !$sheets): ?>
        <div class="panel">Tidak ada sheet yang dikenali sebagai kalender pameran atau monitoring.
            Pastikan berkasnya rekap Casual Leasing, bukan berkas lain.</div>
        <?php endif; ?>
        <?php
    });
}
