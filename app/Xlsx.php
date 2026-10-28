<?php
declare(strict_types=1);

/**
 * Penulis berkas Excel (.xlsx) seadanya — tanpa pustaka tambahan.
 *
 * Dibuat karena unduhan CSV tidak bisa membawa lebar kolom, tebal huruf, dan
 * format rupiah; begitu dibuka, semuanya mepet dan angkanya terbaca sebagai
 * teks. Tim membandingkannya langsung dengan berkas Income Marketing mereka,
 * jadi hasilnya harus rapi sejak dibuka, bukan setelah dirapikan sendiri.
 *
 * Yang didukung sengaja sedikit dan secukupnya: judul, baris kepala, lebar
 * kolom, angka rupiah, tanggal sebagai teks, baris beku, dan penyaring kolom.
 * Satu lembar per berkas.
 *
 * Format .xlsx sebenarnya hanya berkas ZIP berisi beberapa XML; yang ditulis di
 * sini bagian minimum yang dibaca Excel, LibreOffice, dan Google Sheets.
 */
final class Xlsx
{
    /** @var array<int,array{0:string,1:int,2:string}> [judul, lebar, jenis] */
    private array $kolom = [];
    private array $baris = [];
    private string $judul = '';
    private string $namaLembar = 'Sheet1';

    public function __construct(string $namaLembar = 'Sheet1')
    {
        // Excel menolak nama lembar > 31 huruf atau mengandung : \ / ? * [ ]
        $this->namaLembar = mb_substr(preg_replace('#[:\\\\/?*\[\]]#', '-', $namaLembar) ?: 'Sheet1', 0, 31);
    }

    /** Judul besar di baris paling atas (boleh kosong). */
    public function judul(string $teks): self { $this->judul = $teks; return $this; }

    /**
     * @param string $jenis 'teks' | 'rupiah' | 'angka'
     * @param int    $lebar lebar kolom dalam satuan huruf
     */
    public function kolom(string $judul, int $lebar = 16, string $jenis = 'teks'): self
    {
        $this->kolom[] = [$judul, $lebar, $jenis];
        return $this;
    }

    public function baris(array $nilai): self { $this->baris[] = $nilai; return $this; }

    /** Kirim berkas ke peramban lalu hentikan skrip. */
    public function unduh(string $namaBerkas): never
    {
        $tmp = tempnam(sys_get_temp_dir(), 'clara_xlsx_');
        $this->tulis($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $namaBerkas) . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public function tulis(string $tujuan): void
    {
        $zip = new ZipArchive();
        if ($zip->open($tujuan, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Tidak bisa membuat berkas Excel.');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->relsUtama());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relsWorkbook());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();
    }

    // ─── Bagian XML ──────────────────────────────────────────────────────────

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function relsUtama(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $this->x($this->namaLembar) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function relsWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * Gaya: 0 biasa, 1 judul besar, 2 kepala kolom, 3 rupiah, 4 teks rata tengah.
     * numFmtId 164 = "Rp #.##0" dengan pemisah ribuan titik (format Indonesia
     * ditulis eksplisit supaya tidak bergantung pada setelan komputer pembaca).
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;Rp&quot;\ #,##0;[Red]\-&quot;Rp&quot;\ #,##0"/></numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="13"/><color rgb="FF0F2A3F"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F2A3F"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD7DEE8"/></left><right style="thin"><color rgb="FFD7DEE8"/></right>'
            . '<top style="thin"><color rgb="FFD7DEE8"/></top><bottom style="thin"><color rgb="FFD7DEE8"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"><alignment vertical="center" wrapText="0"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" applyNumberFormat="1" applyBorder="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function sheet(): string
    {
        $nKol = count($this->kolom);
        $barisJudul = $this->judul !== '' ? 1 : 0;
        $barisKepala = $barisJudul + 1;

        $cols = '<cols>';
        foreach ($this->kolom as $i => [, $lebar]) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $lebar . '" customWidth="1"/>';
        }
        $cols .= '</cols>';

        $isi = '';
        if ($barisJudul) {
            $isi .= '<row r="1" ht="20" customHeight="1">'
                 . '<c r="A1" s="1" t="inlineStr"><is><t>' . $this->x($this->judul) . '</t></is></c></row>';
        }
        $isi .= '<row r="' . $barisKepala . '" ht="30" customHeight="1">';
        foreach ($this->kolom as $i => [$judul]) {
            $isi .= '<c r="' . $this->sel($i, $barisKepala) . '" s="2" t="inlineStr"><is><t>' . $this->x($judul) . '</t></is></c>';
        }
        $isi .= '</row>';

        $rn = $barisKepala;
        foreach ($this->baris as $b) {
            $rn++;
            $isi .= '<row r="' . $rn . '">';
            foreach ($this->kolom as $i => [, , $jenis]) {
                $v = $b[$i] ?? '';
                $ref = $this->sel($i, $rn);
                if ($jenis === 'rupiah' || $jenis === 'angka') {
                    if ($v === '' || $v === null) { $isi .= '<c r="' . $ref . '" s="' . ($jenis === 'rupiah' ? 3 : 0) . '"/>'; continue; }
                    $isi .= '<c r="' . $ref . '" s="' . ($jenis === 'rupiah' ? 3 : 0) . '"><v>' . (0 + $v) . '</v></c>';
                } else {
                    if ($v === '' || $v === null) { $isi .= '<c r="' . $ref . '" s="' . ($jenis === 'tengah' ? 4 : 0) . '"/>'; continue; }
                    $isi .= '<c r="' . $ref . '" s="' . ($jenis === 'tengah' ? 4 : 0) . '" t="inlineStr"><is><t xml:space="preserve">'
                          . $this->x((string) $v) . '</t></is></c>';
                }
            }
            $isi .= '</row>';
        }

        $akhir = $this->sel(max(0, $nKol - 1), max($rn, $barisKepala));
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // Baris kepala dibekukan supaya judul kolom tetap terlihat saat digulir.
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            . '<pane ySplit="' . $barisKepala . '" topLeftCell="A' . ($barisKepala + 1) . '" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . $isi . '</sheetData>'
            . '<autoFilter ref="' . $this->sel(0, $barisKepala) . ':' . $akhir . '"/>'
            . '</worksheet>';
    }

    private function sel(int $kolom, int $baris): string
    {
        $s = '';
        $n = $kolom + 1;
        while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); }
        return $s . $baris;
    }

    private function x(string $v): string
    {
        // Karakter kontrol membuat Excel menolak berkasnya; dibuang lebih dulu.
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '';
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
