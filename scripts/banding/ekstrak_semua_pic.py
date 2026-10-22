# Tarik ACTUAL per PIC dari spreadsheet Casual Leasing — SEMUA kolom PIC
# sekaligus, bukan satu nama per jalan.
#
# Tiap blok bulan punya baris penanda "ACTUAL PIC". Nama PIC-nya ada dua baris
# di atasnya, di kolom-kolom sesudah penanda itu. Nilai per penyewa dibaca dari
# kolom PIC yang sama, di baris-baris antara akhir blok sebelumnya dan baris
# penanda ini.
#
# Keluarannya satu JSON: {pic: {"bulan": {...}, "rincian": {...}}}
import openpyxl, re, json, sys

berkas, keluar = sys.argv[1], sys.argv[2]
sheets = sys.argv[3].split(',')
wb = openpyxl.load_workbook(berkas, data_only=True)

BLN = {'JAN':'01','FEB':'02','MAR':'03','APR':'04','MEI':'05','JUN':'06',
       'JUL':'07','AGS':'08','SEP':'09','OKT':'10','NOV':'11','DES':'12'}
# "GRAND TOTAL", bukan "GRAND" — ada penyewa bernama GRAND CITY.
BUANG = ('TOTAL','ACTUAL','TARGET','SELISIH','PROYEKSI','KETERANGAN','PIC')

def layak(t):
    k = t.strip().upper()
    return bool(k) and not any(b in k for b in BUANG)

hasil = {}
for sh in sheets:
    ws = wb[sh]
    blok = []
    for r in range(1, ws.max_row + 1):
        for c in range(1, ws.max_column + 1):
            v = ws.cell(r, c).value
            if not (isinstance(v, str) and v.strip().upper() == 'ACTUAL PIC'):
                continue
            # Nama PIC: dua baris di atas, kolom-kolom sesudah penanda.
            pics = []
            for i in range(1, 8):
                nm = ws.cell(r - 2, c + i).value
                if isinstance(nm, str) and nm.strip() and layak(nm):
                    pics.append((nm.strip(), c + i))
            if not pics:
                continue
            bulan = None
            for rr in range(r - 9, r + 3):
                for cc in range(1, ws.max_column + 1):
                    x = ws.cell(rr, cc).value
                    if isinstance(x, str) and re.match(r'^Target\s+([A-Za-z]{3,9})\s*\d{2,4}$', x.strip()):
                        bulan = re.match(r'^Target\s+([A-Za-z]{3,9})', x.strip()).group(1).upper()
                        break
                if bulan:
                    break
            blok.append((bulan, r, pics))

    for i, (bulan, r, pics) in enumerate(blok):
        if not bulan or bulan not in BLN:
            continue
        key = '2026-' + BLN[bulan]
        awal = blok[i - 1][1] + 6 if i > 0 else 1
        batas = r
        for rr in range(r - 1, max(awal, r - 16), -1):
            if any(isinstance(ws.cell(rr, c).value, str)
                   and ws.cell(rr, c).value.strip().upper() == 'ACTUAL'
                   for c in range(1, ws.max_column + 1)):
                batas = rr
                break
        for nama, kol in pics:
            total = ws.cell(r, kol).value
            rows = []
            for rr in range(awal, batas):
                v = ws.cell(rr, kol).value
                if isinstance(v, (int, float)) and v > 0:
                    ket = [str(ws.cell(rr, c).value).strip() for c in range(1, 10)
                           if isinstance(ws.cell(rr, c).value, str) and str(ws.cell(rr, c).value).strip()]
                    teks = ' | '.join(ket[:2])[:60]
                    if layak(teks):
                        rows.append({'ket': teks, 'nilai': float(v)})
            d = hasil.setdefault(nama, {'bulan': {}, 'rincian': {}})
            d['bulan'][key] = float(total or 0)
            if rows:
                d['rincian'][key] = rows

json.dump(hasil, open(keluar, 'w'), ensure_ascii=False, indent=1)
for pic in sorted(hasil):
    d = hasil[pic]
    okt = d['bulan'].get('2026-10', 0)
    n = len(d['rincian'].get('2026-10', []))
    cocok = round(sum(x['nilai'] for x in d['rincian'].get('2026-10', []))) == round(okt)
    print(f'  {pic:<12} Okt {okt:>16,.0f}  {n:>3} baris  {"COCOK" if cocok else "rincian != total"}')
