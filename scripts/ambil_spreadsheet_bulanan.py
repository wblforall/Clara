import sys, json
sys.path.insert(0,'/private/tmp/claude-501/-Users-itmall-Projects-Clara/3721791f-f16a-4f17-a66d-8e07cfcd060b/scratchpad')
from xlsx import Buku
BULAN=['JANUARI','FEBRUARI','MARET','APRIL','MEI','JUNI','JULI','AGUSTUS','SEPTEMBER','OKTOBER','NOVEMBER','DESEMBER']
XL="Casual Leasing e-Walk & Pentacity Tahun 2023 - 2026 (12).xlsx"
SHEET={'e-Walk 2026 - 2':1,'Pentacity 2026 - 2':2}
TARGET=sys.argv[1] if len(sys.argv)>1 else 'OKTOBER'
def kolnum(k):
    n=0
    for c in k: n=n*26+(ord(c)-64)
    return n
def angka(v):
    try: return float(v)
    except (TypeError,ValueError): return 0.0

b=Buku(XL); baris={}; ringkas={}
for sheet,pid in SHEET.items():
    rows=list(b.baris(sheet))
    judul=[(i,(sel.get('A') or '').strip().upper()) for i,(r,sel) in enumerate(rows)
           if (sel.get('A') or '').strip().upper() in BULAN]
    for n,(i,bln) in enumerate(judul):
        if bln!=TARGET: continue
        akhir=judul[n+1][0] if n+1<len(judul) else len(rows)
        hdr=rows[i][1]
        akt=[k for k,v in hdr.items() if isinstance(v,str) and v.strip().upper()=='ACTUAL']
        if not akt: continue
        batas=kolnum(akt[0])
        bukan=('ACTUAL','KETERANGAN','FORECAST','RECURRING','EXHIBITION','LUASAN/M','EXHIBITION SCHEDULE')
        picol={k:v.strip() for k,v in hdr.items()
               if isinstance(v,str) and v.strip() and kolnum(k)>batas
               and v.strip().upper() not in bukan and len(v.strip())<=12}
        for r,sel in rows[i+1:akhir]:
            a=(sel.get('A') or '').strip().upper()
            # Blok RINGKASAN di bawah data: mulai dari "TOTAL / REALISASI",
            # isinya target & persentase, bukan transaksi. Dibaca sebagai
            # transaksi, satu orang bisa tercatat punya pendapatan miliaran.
            if a.startswith('TOTAL / REALISASI') or a.startswith('TOTAL/REALISASI'):
                r2,sel2=rows[rows.index((r,sel))+1] if (r,sel) in rows else (None,{})
                for k,pic in picol.items():
                    v=angka(sel2.get(k))
                    if v: ringkas.setdefault((pid,pic),0.0); ringkas[(pid,pic)]+=v
                break
            if a.startswith('TOTAL'): continue              # subtotal lantai/gudang
            unit=(sel.get('B') or '').strip(); tenant=(sel.get('C') or '').strip()
            if not unit and not tenant: continue            # baris tanpa identitas = bukan transaksi
            for k,pic in picol.items():
                v=angka(sel.get(k))
                if v<=0: continue
                baris.setdefault((pid,pic),[]).append(
                    {'ket':' | '.join([x for x in (unit,tenant) if x]),'nilai':round(v),'baris':r,'sheet':sheet})

print('%-10s %-8s %-6s %-20s %-20s %s' % ('PROPERTI','PIC','BARIS','JUMLAH BARIS','RINGKASAN SHEET','COCOK?'))
out={}
for key in sorted(set(list(baris.keys())+list(ringkas.keys()))):
    pid,pic=key
    rs=baris.get(key,[]); jml=sum(x['nilai'] for x in rs); rk=round(ringkas.get(key,0.0))
    sel=jml-rk
    print('%-10d %-8s %-6d %-20s %-20s %s' % (pid,pic,len(rs),
        format(jml,',d').replace(',','.'), format(rk,',d').replace(',','.'),
        'ya' if abs(sel)<1 else 'BEDA '+format(sel,',d').replace(',','.')))
    out['%d|%s'%(pid,pic)]={'pid':pid,'pic':pic,'total_baris':jml,'total_ringkasan':rk,'rincian':rs}
json.dump(out,open('/private/tmp/claude-501/-Users-itmall-Projects-Clara/3721791f-f16a-4f17-a66d-8e07cfcd060b/scratchpad/oktober.json','w'),ensure_ascii=False,indent=1)
