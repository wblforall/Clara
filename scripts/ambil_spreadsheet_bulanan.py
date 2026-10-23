#!/usr/bin/env python3
"""Baca satu bulan dari spreadsheet Casual Leasing, per PIC.

Spreadsheet adalah patokan. Skrip ini hanya MEMBACA — tidak menilai, tidak
memperbaiki, tidak menyimpulkan ada yang salah di sana.

Yang perlu dipahami tentang bentuk spreadsheet-nya, karena inilah yang dulu
membuat angka salah baca:

  * Satu sheet memuat dua belas blok bulan berurutan. Judul bulan ada di kolom A.
  * Di tiap blok ada beberapa sub-blok (per lantai, lalu gudang, lalu media).
    Masing-masing ditutup baris "TOTAL ..." — baris itu RINGKASAN, bukan transaksi.
  * Sesudah sub-blok terakhir ada blok "TOTAL / REALISASI <BULAN>" berisi target,
    persentase, dan selisih per PIC. Dibaca sebagai transaksi, satu orang bisa
    tercatat berpendapatan miliaran. Pembacaan berhenti di situ.
  * Kolom PIC letaknya BERBEDA antar properti (e-Walk mulai AL, Pentacity mulai
    AM karena ada kolom LUASAN/m). Karena itu kolomnya dicari dari baris judul
    tiap blok, bukan dipatok.
  * Nilai milik seseorang = isi KOLOM MILIKNYA, bukan kolom ACTUAL. ACTUAL itu
    nilai satu baris seluruhnya; satu baris bisa dibagi dua atau tiga orang.

Keluarannya memuat, per baris: sheet, nomor baris, unit, penyewa, nilai bagian
orang itu, nilai ACTUAL baris, dan siapa saja yang berbagi baris tersebut —
supaya panduan bisa menyebut "ini baris patungan, bagian Anda sekian".

Pakai:
  python3 scripts/ambil_spreadsheet_bulanan.py "berkas.xlsx" OKTOBER keluar.json
"""

import json
import re
import sys
import zipfile
from xml.etree import ElementTree as ET

NS = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
NSR = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'

BULAN = ['JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI',
         'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER']
NOMOR = {b: i + 1 for i, b in enumerate(BULAN)}

# Sheet -> property_id di CLARA.
SHEET = {'e-Walk 2026 - 2': 1, 'Pentacity 2026 - 2': 2,
         'e-Walk 2026 - 1': 1, 'Pentacity 2026 - 1': 2}

# Singkatan di spreadsheet -> nama PIC di master_pic CLARA.
PETA_PIC = {
    (1, 'MUSI'): 'Muslimin', (1, 'MUSLIMIN'): 'Muslimin',
    (1, 'SITA'): 'Sita', (1, 'ZUL'): 'Zulfikar', (1, 'ICHA'): 'Icha',
    (2, 'SAGAP'): 'Sagap', (2, 'ALFA'): 'Alfa', (2, 'EVAN'): 'Evan',
    (2, 'SINTA'): 'Shinta', (2, 'SHINTA'): 'Shinta',
}

# Judul kolom yang BUKAN nama orang.
BUKAN_PIC = {'ACTUAL', 'KETERANGAN', 'FORECAST', 'RECURRING', 'EXHIBITION',
             'LUASAN/M', 'EXHIBITION SCHEDULE', 'NO', 'TOTAL', 'INVOICE', 'PIC'}


class Buku:
    def __init__(self, path):
        self.z = zipfile.ZipFile(path)
        rels = ET.fromstring(self.z.read('xl/_rels/workbook.xml.rels'))
        rel = {r.get('Id'): r.get('Target') for r in rels}
        wb = ET.fromstring(self.z.read('xl/workbook.xml'))
        self.sheets = [(s.get('name'), rel[s.get(NSR + 'id')]) for s in wb.iter(NS + 'sheet')]
        self.sst = []
        try:
            sst = ET.fromstring(self.z.read('xl/sharedStrings.xml'))
            for si in sst.iter(NS + 'si'):
                self.sst.append(''.join(t.text or '' for t in si.iter(NS + 't')))
        except KeyError:
            pass

    def baris(self, nama):
        tgt = dict(self.sheets)[nama]
        if not tgt.startswith('xl/'):
            tgt = 'xl/' + tgt.lstrip('/')
        for row in ET.fromstring(self.z.read(tgt)).iter(NS + 'row'):
            sel = {}
            for c in row.iter(NS + 'c'):
                kol = re.match(r'[A-Z]+', c.get('r')).group(0)
                t, v, inl = c.get('t'), c.find(NS + 'v'), c.find(NS + 'is')
                if t == 's' and v is not None:
                    nilai = self.sst[int(v.text)]
                elif t == 'inlineStr' and inl is not None:
                    nilai = ''.join(x.text or '' for x in inl.iter(NS + 't'))
                elif v is not None:
                    nilai = v.text
                else:
                    nilai = None
                if nilai not in (None, ''):
                    sel[kol] = nilai
            yield int(row.get('r')), sel


def kolnum(k):
    n = 0
    for c in k:
        n = n * 26 + (ord(c) - 64)
    return n


def num(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return 0.0


def baca(xlsx, bulan):
    bulan = bulan.upper()
    buku = Buku(xlsx)
    ada = {n for n, _ in buku.sheets}
    hasil = {}     # (pid, nama_pic) -> {'rincian': [...], 'ringkasan': float}
    tanpaPic = []  # baris bernilai yang tidak dimiliki sales mana pun
    tahun = None   # diambil dari NAMA SHEET, bukan nama berkas
    for sheet, pid in SHEET.items():
        if sheet not in ada:
            continue
        th = re.search(r'(20\d\d)', sheet)
        if th:
            tahun = th.group(1)
        rows = list(buku.baris(sheet))
        judul = [(i, (sel.get('A') or '').strip().upper())
                 for i, (r, sel) in enumerate(rows)
                 if (sel.get('A') or '').strip().upper() in BULAN]
        for n, (i, bln) in enumerate(judul):
            if bln != bulan:
                continue
            akhir = judul[n + 1][0] if n + 1 < len(judul) else len(rows)
            hdr = rows[i][1]
            akt = [k for k, v in hdr.items()
                   if isinstance(v, str) and v.strip().upper() == 'ACTUAL']
            if not akt:
                continue
            kAkt = akt[0]
            batas = kolnum(kAkt)
            picol = {k: v.strip() for k, v in hdr.items()
                     if isinstance(v, str) and v.strip() and kolnum(k) > batas
                     and v.strip().upper() not in BUKAN_PIC and len(v.strip()) <= 12}
            for j in range(i + 1, akhir):
                r, sel = rows[j]
                a = (sel.get('A') or '').strip().upper()
                if a.startswith('TOTAL / REALISASI') or a.startswith('TOTAL/REALISASI'):
                    # Baris SESUDAHNYA memuat realisasi per PIC — itu angka resmi
                    # spreadsheet, dipakai sebagai alat uji pembacaan di bawah.
                    # Nilainya ada di baris TEPAT SESUDAH judul ringkasan.
                    if j + 1 < len(rows):
                        ring = rows[j + 1][1]
                        for k, sing in picol.items():
                            nama = PETA_PIC.get((pid, sing.upper()))
                            if not nama:
                                continue
                            v = num(ring.get(k))
                            if v:
                                hasil.setdefault((pid, nama), {'rincian': [], 'ringkasan': 0.0})
                                hasil[(pid, nama)]['ringkasan'] += v
                    break
                if a.startswith('TOTAL'):
                    continue
                unit = (sel.get('B') or '').strip()
                penyewa = (sel.get('C') or '').strip()
                if not unit and not penyewa:
                    continue
                aktual = num(sel.get(kAkt))
                bagian = {}
                for k, sing in picol.items():
                    v = num(sel.get(k))
                    if v > 0:
                        bagian[sing] = v
                if not bagian:
                    if aktual > 0:
                        tanpaPic.append({'properti': pid, 'sheet': sheet, 'baris': r,
                                         'unit': unit, 'penyewa': penyewa, 'nilai': round(aktual)})
                    continue
                pemilik = sorted(PETA_PIC.get((pid, s.upper()), s) for s in bagian)
                for sing, v in bagian.items():
                    nama = PETA_PIC.get((pid, sing.upper()))
                    if not nama:
                        continue
                    hasil.setdefault((pid, nama), {'rincian': [], 'ringkasan': 0.0})
                    hasil[(pid, nama)]['rincian'].append({
                        'sheet': sheet, 'baris': r, 'unit': unit, 'penyewa': penyewa,
                        'ket': ' | '.join([x for x in (unit, penyewa) if x]),
                        'nilai': round(v), 'aktual_baris': round(aktual),
                        'pemilik': pemilik, 'patungan': len(bagian) > 1,
                    })
    return hasil, tanpaPic, tahun


def main():
    if len(sys.argv) < 4:
        sys.exit('Pakai: ambil_spreadsheet_bulanan.py "berkas.xlsx" OKTOBER keluar.json')
    xlsx, bulan, keluar = sys.argv[1], sys.argv[2].upper(), sys.argv[3]
    hasil, tanpaPic, tahun = baca(xlsx, bulan)
    if not hasil:
        sys.exit('Bulan %s tidak ditemukan di sheet mana pun.' % bulan)

    # Tahun dari NAMA SHEET ("e-Walk 2026 - 2"), bukan dari nama berkas —
    # nama berkasnya memuat rentang "Tahun 2023 - 2026" dan akan terbaca 2023.
    periode = '%s-%02d' % (tahun or '2026', NOMOR[bulan])

    print('PEMBACAAN SPREADSHEET — %s (%s)' % (bulan, periode))
    print('  sumber: %s\n' % xlsx)
    print('  %-10s %-10s %6s  %-18s %-18s %s'
          % ('PROPERTI', 'PIC', 'BARIS', 'JUMLAH BARIS', 'RINGKASAN SHEET', 'UJI'))
    keluaran = {}
    semuaCocok = True
    for (pid, nama), d in sorted(hasil.items()):
        jml = sum(x['nilai'] for x in d['rincian'])
        ring = round(d['ringkasan'])
        cocok = abs(jml - ring) < 1
        semuaCocok = semuaCocok and cocok
        print('  %-10d %-10s %6d  %-18s %-18s %s'
              % (pid, nama, len(d['rincian']),
                 '{:,}'.format(jml).replace(',', '.'),
                 '{:,}'.format(ring).replace(',', '.'),
                 'cocok' if cocok else 'BEDA {:,}'.format(jml - ring).replace(',', '.')))
        keluaran['%d|%s' % (pid, nama)] = {
            'properti': pid, 'pic': nama, 'periode': periode, 'bulan': bulan,
            'sumber': xlsx,
            'total': ring, 'total_baris': jml, 'cocok': cocok,
            'rincian': sorted(d['rincian'], key=lambda x: -x['nilai']),
        }

    if tanpaPic:
        print('\n  Baris bernilai TANPA PIC sales (%d) — milik unit sendiri, bukan milik siapa pun:'
              % len(tanpaPic))
        for t in tanpaPic:
            print('     properti %d baris %-5d Rp %-16s %s'
                  % (t['properti'], t['baris'],
                     '{:,}'.format(t['nilai']).replace(',', '.'),
                     (t['unit'] + ' | ' + t['penyewa']).strip(' |')))

    json.dump({'periode': periode, 'bulan': bulan, 'sumber': xlsx,
               'pic': keluaran, 'tanpa_pic': tanpaPic},
              open(keluar, 'w'), ensure_ascii=False, indent=1)
    print('\n  Ditulis: %s' % keluar)
    if not semuaCocok:
        print('  PERHATIAN: ada PIC yang jumlah barisnya tidak sama dengan ringkasan')
        print('  sheet. Pembacaan dihentikan di sini — angka yang tidak terbukti')
        print('  tidak boleh dipakai membuat panduan perbaikan.')
        sys.exit(1)
    print('  Seluruh PIC: jumlah baris = ringkasan sheet. Pembacaan terbukti benar.')


if __name__ == '__main__':
    main()
