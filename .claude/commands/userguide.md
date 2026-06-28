---
description: Buat, audit, atau update User Guide PDF untuk modul CLARA — screenshot real (puppeteer), konten akurat vs kode, rebuild PDF. Diadaptasi dari skill MIC ke stack CLARA (plain PHP, route ?r=).
---

Kerjakan user guide untuk modul/peran CLARA yang disebutkan di `$ARGUMENTS`.

Tentukan mode kerja dari argumen:
- **buat** / tidak ada → buat user guide baru dari nol
- **audit** → periksa inakurasi isi vs kode aktual, laporkan temuan saja
- **update** / **fix** → audit + perbaiki + retake screenshot + rebuild PDF
- **screenshot** → retake screenshot saja (konten tidak diubah) lalu rebuild
- **rebuild** → hanya rebuild PDF dari source yang sudah ada

CLARA punya 3 guide per peran (lihat [[project-userguide-clara]] bila sudah ada): **Sales**, **Manager**, **Superadmin** — sumber di `docs/PANDUAN_[Sales|Manager|Superadmin].md`. Beda peran = beda izin & data yang tampil (sales hanya lihat data sendiri).

---

## Akun Screenshot

**Akun utama** (superadmin, lihat semua) — [[test-account]]:
- Email: `claude.test@clara.local`
- Password: `ClaudeTest#2026`

Verifikasi aktif sebelum mulai:
```sql
SELECT id, email, role, status FROM users WHERE email = 'claude.test@clara.local';
```
Reset bila perlu (snippet di [[test-account]]).

**Pengecualian — halaman scoped per-sales:** akun di atas superadmin, sehingga melihat SEMUA penawaran/SKP/laporan. Untuk screenshot guide **Sales** yang menampilkan "hanya data sendiri", buat/gunakan akun ber-role `sales` yang tertaut ke Master PIC, lalu login dengan akun itu. Kalau belum ada, beri tahu user atau buat akun test sales sementara (jangan di production).

---

## Struktur File (CLARA)

| File | Lokasi |
|---|---|
| Source guide (markdown) | `docs/PANDUAN_[Peran].md` — peran: Sales/Manager/Superadmin |
| Screenshot script | `scripts/userguide_screenshot.js` (edit array `desk`/`mob`) |
| Build script (MD→PDF) | `scripts/build_userguide.js` |
| Aset screenshot (gitignored) | `scripts/userguide_assets/[nama].png` |
| Output PDF | `/Users/affanridha/Documents/Handout Program/clara/UserGuide_[Peran].pdf` |

**Cara pakai (sudah jadi pipeline):**
```bash
SCRATCH=<scratchpad dgn node_modules: marked + puppeteer-core>
# 1) ambil semua screenshot (login akun test)
CLARA_TEST_PASS='...' NODE_PATH="$SCRATCH/node_modules" node scripts/userguide_screenshot.js
# 2) build PDF (Sales|Manager|Superadmin|all)
NODE_PATH="$SCRATCH/node_modules" node scripts/build_userguide.js all
```
puppeteer-core + marked tak ter-install di repo; pakai `NODE_PATH` ke node_modules scratchpad (`npm i marked puppeteer-core` sekali).

**Sisip screenshot ke konten:** di `docs/PANDUAN_[Peran].md` tulis token pada baris sendiri:
- `@@SHOT:nama|caption@@` — gambar desktop (lebar penuh)
- `@@SHOTM:nama|caption@@` — gambar mobile (sempit, terpusat)

`nama` = nama file di `scripts/userguide_assets/` tanpa `.png`. Build mengganti token jadi `<figure>` base64; token tanpa file → dihapus + warning.

Node: `/opt/homebrew/bin/node` · Chrome: `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome` · puppeteer-core (`npm i puppeteer-core` di scratchpad — TIDAK unduh Chromium). Apache XAMPP harus jalan (`http://localhost/clara/public/`). Lihat [[reference-headless-screenshot]].

---

## Langkah 1 — Audit Konten (wajib untuk mode buat/audit/update)

Spawn **agent Explore** untuk membaca paralel (CLARA = plain PHP, BUKAN MVC):
1. Source guide `docs/PANDUAN_[Peran].md`
2. Handler modul di `app/pages/[modul].php` (mis. `offers.php`, `skp.php`, `transactions.php`)
3. Router `public/index.php` — peta `$pageFiles` + `match($route)` (daftar route & fungsi)
4. `permission_for_route()` & matriks izin (`app/helpers.php` + tabel `role_permissions`) — untuk memastikan fitur yang ditulis memang BISA diakses peran tsb
5. Template terkait (`app/pages/*_template.php`, `*_print*.php`) bila membahas dokumen/PDF

Temukan inakurasi:
- **Field tidak ada di UI** — disebut di guide tapi tak ada di form
- **Field tidak ada di guide** — ada di UI tapi tak ditulis
- **Nama field/label/tombol salah** — beda antara guide & view
- **Alur salah** — langkah tak sesuai flow handler/route
- **Izin salah** — fitur ditulis untuk peran yang sebenarnya TIDAK punya izinnya (cek `permission_for_route` + `role_permissions`)
- **Kalkulasi salah** — formula tak sesuai kode

Laporkan semua temuan sebelum menulis.

---

## Langkah 2 — Update Konten

Baca section yang perlu diubah dulu, lalu `Edit`. Prinsip:
- Teks justify; tiap section: paragraf pengantar → tabel field (jika ada form) → langkah → callout tips/peringatan
- Tabel field kolom: **Nama Field | Wajib | Penjelasan**; field wajib `<b>bold</b>`
- Callout: `callout` (info), `callout warning`, `callout ok`
- Jangan tulis ulang section yang tak berubah
- **Hormati peran:** guide Sales jangan memuat fitur khusus Manager/Superadmin (approve SKP, exec, users, dll)

---

## Langkah 3 — Retake Screenshot (CLARA)

Edit array `shots` di `scripts/handout_screenshot.js`, atau tulis script baru di scratchpad. Login CLARA pakai CSRF (pola dari script existing):

```js
const puppeteer = require('puppeteer-core');
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = 'http://localhost/clara/public/';
const OUT  = '/Applications/XAMPP/xamppfiles/htdocs/clara/scripts/handout_assets/';
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function login(page, email, pass) {
  await page.goto(BASE + '?r=login', { waitUntil: 'networkidle2' });
  const csrf = await page.$eval('input[name="_csrf"]', el => el.value);
  await page.$eval('input[name="email"]',   (el,v)=>el.value=v, email);
  await page.$eval('input[name="password"]',(el,v)=>el.value=v, pass);
  await page.$eval('input[name="_csrf"]',   (el,v)=>el.value=v, csrf);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(()=>{}),
  ]);
  await sleep(500);
}
// Halaman: route lewat ?r=NAMA&param=val. Desktop setViewport ~1280×N.
```

Jalankan: `/opt/homebrew/bin/node scripts/handout_screenshot.js` (set `CLARA_TEST_PASS` di env, jangan hardcode password).

**Mobile:** CLARA pakai cookie `clara_view=mobile` atau query `?view=mobile`. Set viewport ~390×844 + `page.setCookie({name:'clara_view',value:'mobile',domain:'localhost',path:'/'})` SETELAH login (pakai cookie sesi yang sama; jangan login ulang di viewport mobile).

**Klik elemen (CLARA UI custom, BUKAN Bootstrap — tak ada `data-bs-target`/`.nav-tabs`):** klik via teks dengan `page.evaluate`:
```js
await page.evaluate((label) => {
  for (const b of document.querySelectorAll('a,button')) {
    if (b.textContent.trim().includes(label)) { b.click(); return; }
  }
}, 'Buat Penawaran');
await sleep(600);
```
Untuk dokumen/PDF: render route `*_print` (mis. `?r=offer_print&id=N`, `?r=skp_print&id=N`) di puppeteer lalu screenshot, atau buka PDF mPDF-nya.

---

## Langkah 4 — Verifikasi Screenshot

Buka **setiap** PNG yang dipakai dengan `Read`. Ciri rusak:
- Tampil **form login CLARA** (gradient teal + tombol "Masuk") alih-alih konten
- Ukuran file sangat kecil untuk halaman yang seharusnya berisi tabel/kartu
- Navbar tak menampilkan nama user yang benar

Cek semua, termasuk mobile (`m*`). Yang rusak → script terpisah, jalankan ulang, cek lagi. Untuk guide Sales pastikan navbar menampilkan akun **sales** (bukan superadmin) bila ingin tampak scoped.

---

## Langkah 5 — Rebuild PDF

CLARA membangun handout via puppeteer print (HTML→PDF A4). Acuan: `scripts/build_handout.js` (saat ini khusus `HANDOUT_SALES`). Untuk guide per-peran, generalisasi script (parameter peran → pilih `docs/PANDUAN_[Peran].md` + set screenshot + output `UserGuide_[Peran].pdf`), lalu:
```bash
/opt/homebrew/bin/node scripts/build_handout.js
```
Output diharapkan PDF A4 dengan cover + konten + screenshot tersisip (base64).

---

## Checklist Sebelum Selesai

- [ ] Tidak ada screenshot (desktop & `m*` mobile) yang menampilkan halaman login — **cek satu per satu dengan Read**
- [ ] Untuk guide Sales: navbar menampilkan akun sales; halaman tampak scoped (data sendiri)
- [ ] Semua field/tombol/alur sesuai handler & route (`app/pages/*.php`, `public/index.php`)
- [ ] Tidak ada fitur lintas-peran (guide Sales tak memuat approve/admin) — silang-cek `permission_for_route` + `role_permissions`
- [ ] ID entitas pada screenshot benar-benar ada di DB (cek `SELECT id FROM offers/skp_documents ... LIMIT 5`)
- [ ] PDF terbuild tanpa error & ukuran wajar

---

## Catatan Teknis Penting

**Sesi 30 menit** — login akan kedaluwarsa bila idle; ambil semua screenshot dalam satu run.

**Rate-limit login** — CLARA mengunci 5 menit setelah 5 gagal login. Pastikan password benar (env `CLARA_TEST_PASS`).

**Berkas unggahan (KTP/NPWP/scan) ber-gerbang** — disajikan via `?r=file&p=...` (butuh sesi/login atau token). Screenshot halaman yang menampilkannya harus login dulu; mPDF kontrak menyisipkan lampiran dari filesystem (sudah ditangani di `$imgSrc`).

**Jangan akhiri guide dengan page-break** — [[feedback-userguide-pb]]: `<div class="pb"></div>` (page-break-after) di AKHIR section terakhir bikin halaman kosong di PDF (Chrome menambah halaman setelah break tanpa konten). Pakai `pb` hanya di tengah dokumen.

**ID entitas untuk screenshot** — jangan hardcode; cek dulu:
```sql
SELECT id FROM offers ORDER BY id DESC LIMIT 5;
SELECT id FROM skp_documents WHERE status IN ('approved','signed') LIMIT 5;
```
Bila halaman kosong/redirect, pilih ID yang berdata.

---

## Referensi Script CLARA yang Ada

- `scripts/handout_screenshot.js` — login + screenshot per-route (edit array `shots`)
- `scripts/build_handout.js` — rakit HTML + screenshot (base64) → PDF A4
- `scripts/handout_assets/` — screenshot existing (`d*` desktop, `m*` mobile)
