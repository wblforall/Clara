#!/bin/bash
# Rekonsiliasi pendapatan per PIC — dari spreadsheet sampai PDF, sekali jalan.
#
# Tiga langkah yang dulu dijalankan satu per satu dan gampang tertukar urutannya:
# baca spreadsheet, sandingkan tiap bulan dengan database, lalu cetak PDF per
# orang. Dijadikan satu di sini supaya hasilnya bisa diulang persis — terutama
# sesudah database diperbarui, karena daftar perbaikan yang dibuat dari salinan
# lama akan menyebut hal yang sudah terlanjur dibetulkan.
#
# MURNI MEMBACA. Tidak ada satu pun perintah di sini yang mengubah database.
#
# Pakai:
#   scripts/rekon_pic_jalankan.sh "Casual Leasing ... .xlsx" [keluar-dir] [bulan]
#
# Contoh:
#   scripts/rekon_pic_jalankan.sh \
#       "Casual Leasing e-Walk & Pentacity Tahun 2023 - 2026.xlsx" \
#       lokal/rekonsiliasi 2026-01..2026-10
set -euo pipefail

XLSX="${1:?berkas spreadsheet belum disebut}"
KELUAR="${2:-lokal/rekonsiliasi}"
RENTANG="${3:-2026-01..2026-10}"

AKAR="$(cd "$(dirname "$0")/.." && pwd)"
KERJA="$(mktemp -d)"
trap 'rm -rf "$KERJA"' EXIT

cd "$AKAR"

# PIC e-Walk dan Pentacity DIPISAH sebelum disandingkan. Digabung, pencocokan
# nama bisa mengawinkan "SITA" (e-Walk) dengan "Shinta" (Pentacity) — dua orang
# berbeda yang namanya kebetulan berdekatan, dan seluruh angkanya jadi salah.
EWALK='MUSLIMIN,MUSI,SITA,ZUL,ICHA'
PENTA='SAGAP,ALFA,EVAN,SINTA,SHINTA'

# openpyxl tidak ikut repo. Diperiksa di sini supaya kegagalannya terbaca
# sebagai "paketnya belum dipasang", bukan sebagai jejak galat Python.
if ! python3 -c "import openpyxl" 2>/dev/null; then
    echo "openpyxl belum terpasang. Pasang dulu:  python3 -m pip install openpyxl" >&2
    exit 3
fi

echo "1/3  Membaca spreadsheet ..."
python3 scripts/banding/ekstrak_semua_pic.py "$XLSX" "$KERJA/sheet_pic.json" \
    'e-Walk 2026 - 1,e-Walk 2026 - 2,Pentacity 2026 - 1,Pentacity 2026 - 2' >/dev/null

python3 - "$KERJA/sheet_pic.json" "$KERJA" "$EWALK" "$PENTA" <<'PY'
import json, sys
berkas, kerja, ew, pc = sys.argv[1:5]
d = json.load(open(berkas))
for nama, daftar in (('ss_ewalk', ew), ('ss_penta', pc)):
    pilih = {k: v for k, v in d.items() if k.upper() in set(daftar.split(','))}
    json.dump(pilih, open(f'{kerja}/{nama}.json', 'w'), ensure_ascii=False)
    print(f'     {nama}: {", ".join(sorted(pilih))}')
PY

echo "2/3  Menyandingkan tiap bulan dengan database ..."
mkdir -p "$KERJA/banding"
AWAL="${RENTANG%%..*}"; AKHIR="${RENTANG##*..}"
BLN="$AWAL"
while [[ "$BLN" < "$AKHIR" || "$BLN" == "$AKHIR" ]]; do
    php scripts/cek_semua_pic.php --banding="$KERJA/ss_ewalk.json" --prop=1 \
        --bulan="$BLN" --json="$KERJA/banding/ew-$BLN.json" >/dev/null
    php scripts/cek_semua_pic.php --banding="$KERJA/ss_penta.json" --prop=2 \
        --bulan="$BLN" --json="$KERJA/banding/pc-$BLN.json" >/dev/null
    BLN="$(date -j -f '%Y-%m-%d' -v+1m "$BLN-01" '+%Y-%m' 2>/dev/null \
        || date -d "$BLN-01 +1 month" '+%Y-%m')"
done
echo "     $(ls "$KERJA/banding" | wc -l | tr -d ' ') berkas sandingan"

echo "3/3  Mencetak PDF per PIC ..."
mkdir -p "$KELUAR"
php scripts/build_rekon_pic_tahunan.php \
    --banding-dir="$KERJA/banding" --sheet="$KERJA/sheet_pic.json" \
    --bulan="$RENTANG" --keluar-dir="$KELUAR" \
    --sumber-db="database yang sedang dipakai, $(date '+%-d %B %Y')" \
    --sumber-xlsx="$(basename "$XLSX")"
