<?php

/**
 * Pembaca berkas .xlsx seadanya — tanpa pustaka luar.
 *
 * Hosting yang dipakai CLARA tidak punya PhpSpreadsheet dan menambah pustaka
 * sebesar itu hanya untuk membaca satu berkas rekap bulanan tidak sepadan.
 * Yang dibutuhkan cuma tiga hal: daftar sheet, isi sel per baris, dan daftar
 * sel yang digabung (merge) — ketiganya ada di dalam XML milik .xlsx itu
 * sendiri, dan PHP sudah punya ZipArchive + XMLReader.
 *
 * Dibaca secara mengalir (streaming) baris per baris: berkas rekap Casual
 * Leasing berisi ribuan baris × 50 kolom, dan memuatnya sekaligus sebagai
 * DOM pernah membuat prosesnya memakan ratusan MB.
 *
 * TIDAK menangani: rumus (hanya nilai hasilnya yang dibaca), format tanggal,
 * gaya, gambar. Semua itu memang tidak diperlukan.
 */
final class XlsxReader
{
    /** @var string Lokasi berkas .xlsx */
    private string $path;
    /** @var array<int,string> Tabel teks bersama (sharedStrings.xml) */
    private array $teks = [];
    private bool $teksDimuat = false;

    public function __construct(string $path)
    {
        if (!is_file($path)) {
            throw new RuntimeException('Berkas tidak ditemukan: ' . basename($path));
        }
        $this->path = $path;
    }

    /** Berkas ini benar-benar .xlsx (zip berisi xl/workbook.xml)? */
    public function valid(): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true) return false;
        $ada = $zip->locateName('xl/workbook.xml') !== false;
        $zip->close();
        return $ada;
    }

    /**
     * Daftar sheet: [nama => 'worksheets/sheet1.xml'].
     *
     * Urutannya dipertahankan seperti di berkas, karena pengguna mengenali
     * sheet dari urutan tabnya, bukan dari nomor internalnya.
     *
     * @return array<string,string>
     */
    public function sheets(): array
    {
        $wb = $this->isi('xl/workbook.xml');
        $rels = $this->isi('xl/_rels/workbook.xml.rels');
        if ($wb === null || $rels === null) return [];

        $peta = [];
        $xr = @simplexml_load_string($rels);
        foreach ($xr ? $xr->Relationship : [] as $r) {
            $a = $r->attributes();
            $peta[(string) $a['Id']] = ltrim(str_replace('/xl/', '', (string) $a['Target']), '/');
        }

        $out = [];
        $xw = @simplexml_load_string($wb);
        foreach ($xw ? $xw->sheets->sheet : [] as $s) {
            $nama = (string) $s->attributes()['name'];
            $rid  = (string) $s->attributes('r', true)['id'];
            if ($nama !== '' && isset($peta[$rid])) $out[$nama] = $peta[$rid];
        }
        return $out;
    }

    /**
     * Baris demi baris sebuah sheet.
     *
     * @return Generator<int,array<string,string>> nomor baris => [kolom => nilai]
     */
    public function rows(string $target, int $batasBaris = 20000): Generator
    {
        $this->muatTeks();
        $tmp = $this->keTemp('xl/' . ltrim($target, '/'));
        if ($tmp === null) return;

        $r = new XMLReader();
        if (!@$r->open($tmp)) { @unlink($tmp); return; }
        $n = 0;
        try {
            while ($r->read()) {
                if ($r->nodeType !== XMLReader::ELEMENT || $r->name !== 'row') continue;
                if (++$n > $batasBaris) break;
                $xml = $r->readOuterXML();
                if ($xml === '') continue;
                $x = @simplexml_load_string($xml);
                if (!$x) continue;
                $noBaris = (int) $x->attributes()['r'];
                $sel = [];
                foreach ($x->c as $c) {
                    $a   = $c->attributes();
                    $kol = preg_replace('/\d+/', '', (string) $a['r']);
                    $tp  = (string) $a['t'];
                    if ($tp === 'inlineStr') {
                        $v = '';
                        foreach ($c->xpath('.//*[local-name()="t"]') as $t) $v .= (string) $t;
                    } else {
                        $v = isset($c->v) ? (string) $c->v : '';
                        // 's' = nomor urut ke tabel teks bersama; sisanya sudah berupa nilai.
                        if ($tp === 's' && $v !== '') $v = $this->teks[(int) $v] ?? '';
                    }
                    $v = trim((string) $v);
                    if ($v !== '' && $kol !== '') $sel[$kol] = $v;
                }
                if ($sel) yield $noBaris => $sel;
            }
        } finally {
            $r->close();
            @unlink($tmp);
        }
    }

    /**
     * Sel-sel yang digabung, dipetakan dari sel KIRI-ATAS-nya.
     *
     * Dipakai untuk membaca batang jadwal di kalender: satu sewa ditulis
     * sekali lalu digabung sepanjang masa sewanya, jadi kolom awal & akhir
     * gabungan itulah tanggal mulai & selesainya.
     *
     * @return array<string,string> 'D9' => 'AH9'
     */
    public function merges(string $target): array
    {
        $isi = $this->isi('xl/' . ltrim($target, '/'));
        if ($isi === null) return [];
        // Dicari dengan regex, bukan XML parser: blok <mergeCells> ada di ujung
        // berkas dan memuatnya sebagai DOM berarti memuat seluruh sheet.
        if (!preg_match_all('~<mergeCell ref="([A-Z]+\d+):([A-Z]+\d+)"~', $isi, $m, PREG_SET_ORDER)) return [];
        $out = [];
        foreach ($m as $g) $out[$g[1]] = $g[2];
        return $out;
    }

    /** Nomor kolom dari hurufnya: A=1, B=2, ..., AA=27. */
    public static function kolomKeAngka(string $kol): int
    {
        $n = 0;
        for ($i = 0, $p = strlen($kol); $i < $p; $i++) $n = $n * 26 + (ord($kol[$i]) - 64);
        return $n;
    }

    /** Huruf kolom dari nomornya: 1=A, 27=AA. */
    public static function angkaKeKolom(int $n): string
    {
        $s = '';
        while ($n > 0) { $sisa = ($n - 1) % 26; $s = chr(65 + $sisa) . $s; $n = (int) (($n - $sisa) / 26); }
        return $s;
    }

    // ── bagian dalam ────────────────────────────────────────────────────────

    private function muatTeks(): void
    {
        if ($this->teksDimuat) return;
        $this->teksDimuat = true;
        $tmp = $this->keTemp('xl/sharedStrings.xml');
        if ($tmp === null) return;
        $r = new XMLReader();
        if (!@$r->open($tmp)) { @unlink($tmp); return; }
        try {
            while ($r->read()) {
                if ($r->nodeType !== XMLReader::ELEMENT || $r->name !== 'si') continue;
                $x = @simplexml_load_string($r->readOuterXML());
                if (!$x) { $this->teks[] = ''; continue; }
                $t = '';
                foreach ($x->xpath('.//*[local-name()="t"]') as $n) $t .= (string) $n;
                $this->teks[] = $t;
            }
        } finally {
            $r->close();
            @unlink($tmp);
        }
    }

    private function isi(string $nama): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true) return null;
        $isi = $zip->getFromName($nama);
        $zip->close();
        return $isi === false ? null : $isi;
    }

    /**
     * Keluarkan satu berkas dari zip ke berkas sementara.
     *
     * XMLReader butuh jalur berkas untuk membaca mengalir; memberinya string
     * berarti seluruh XML masuk memori lebih dulu — persis yang dihindari.
     */
    private function keTemp(string $nama): ?string
    {
        $isi = $this->isi($nama);
        if ($isi === null) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'clara-xlsx-');
        if ($tmp === false) return null;
        file_put_contents($tmp, $isi);
        return $tmp;
    }
}
