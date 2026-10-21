# Ekstrak ACTUAL PIC + rincian per bulan dari spreadsheet Casual Leasing.
# Dipisahkan jadi berkas sendiri supaya bisa dijalankan ulang saat
# spreadsheetnya diperbarui, tanpa menulis ulang penyaringnya.
import openpyxl, re, json, sys
f = sys.argv[1]; pic_cari = sys.argv[2]; keluar = sys.argv[3]
sheets = sys.argv[4].split(',')
wb = openpyxl.load_workbook(f, data_only=True)
BLN={'JAN':'01','FEB':'02','MAR':'03','APR':'04','MEI':'05','JUN':'06',
     'JUL':'07','AGS':'08','SEP':'09','OKT':'10','NOV':'11','DES':'12'}
PIC=set()  # tidak perlu daftar nama: batas blok ringkasan sudah memotongnya
# "GRAND TOTAL", bukan "GRAND" — ada penyewa bernama GRAND CITY.
BUANG=('TOTAL','ACTUAL','TARGET','SELISIH','PROYEKSI','KETERANGAN')
def layak(t):
    k=t.strip().upper()
    return bool(k) and k not in PIC and not any(b in k for b in BUANG)
hasil={'sumber':f.split('/')[-1],'pic':pic_cari,'bulan':{},'rincian':{}}
for sh in sheets:
    ws=wb[sh]; blok=[]
    for r in range(1,ws.max_row+1):
        for c in range(1,ws.max_column+1):
            v=ws.cell(r,c).value
            if isinstance(v,str) and v.strip().upper()=='ACTUAL PIC':
                kol=next((c+i for i in range(1,6)
                     if isinstance(ws.cell(r-2,c+i).value,str)
                     and pic_cari[:4].upper() in ws.cell(r-2,c+i).value.upper()), None)
                if not kol: continue
                bulan=None
                for rr in range(r-9,r+3):
                    for cc in range(1,ws.max_column+1):
                        x=ws.cell(rr,cc).value
                        if isinstance(x,str) and re.match(r'^Target\s+([A-Za-z]{3,9})\s*\d{2}$',x.strip()):
                            bulan=re.match(r'^Target\s+([A-Za-z]{3,9})',x.strip()).group(1).upper(); break
                    if bulan: break
                blok.append((bulan,r,kol,ws.cell(r,kol).value))
    for i,(bulan,r,kol,val) in enumerate(blok):
        if not bulan or bulan not in BLN: continue
        key='2026-'+BLN[bulan]; hasil['bulan'][key]=float(val or 0)
        awal = blok[i-1][1]+6 if i>0 else 1
        batas = r
        for rr in range(r-1, max(awal, r-16), -1):
            if any(isinstance(ws.cell(rr,c).value,str) and ws.cell(rr,c).value.strip().upper()=='ACTUAL'
                   for c in range(1,ws.max_column+1)):
                batas = rr; break
        rows=[]
        for rr in range(awal,batas):
            v=ws.cell(rr,kol).value
            if isinstance(v,(int,float)) and v>0:
                ket=[str(ws.cell(rr,c).value).strip() for c in range(1,10)
                     if isinstance(ws.cell(rr,c).value,str) and str(ws.cell(rr,c).value).strip()]
                teks=' | '.join(ket[:2])[:60]
                if layak(teks): rows.append({'ket':teks,'nilai':float(v)})
        if rows: hasil['rincian'][key]=rows
json.dump(hasil, open(keluar,'w'), ensure_ascii=False, indent=1)
for k in sorted(hasil['bulan']):
    rs=hasil['rincian'].get(k,[]); tot=sum(x['nilai'] for x in rs)
    ok='COCOK' if round(tot)==round(hasil['bulan'][k]) else f'beda {tot-hasil["bulan"][k]:,.0f}'
    print(f'  {k}  {len(rs):>3} baris  {tot:>15,.0f} vs {hasil["bulan"][k]:>15,.0f}  {ok}')
