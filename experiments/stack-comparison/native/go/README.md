# Pembanding native Go

Implementasi eksperimen untuk source `6b3cf5846fd4442c3e895cacf2ba58275f9dd75e`.
Runtime memakai `net/http`, `pgxpool`, dan `shopspring/decimal`.
Schema, trigger audit, ledger, dan data acuan berasal dari snapshot PHP yang sama.
Tidak ada panggilan PHP, replay query, migration pengganti, atau stored procedure untuk menjalankan alur bisnis.

## Menjalankan profil

Siapkan clone database eksperimen lewat koordinator. Jangan memakai database pengembangan bersama.
Jalankan dari folder ini pada Windows.

```powershell
./run.ps1 -EnvironmentFile '<path absolut runtime.env>' -Database erp_compare_go
```

Script hanya membaca konfigurasi privat dan membangun executable sementara. Tidak membuat atau mereset database.
Alamat bawaan adalah `127.0.0.1:18642`.
Linux dapat memakai `go build -o native-go .` atau Dockerfile dalam folder ini.
Dockerfile belum dibangun pada sesi implementasi.

Konfigurasi memakai `APP_KEY` dan `DATABASE_URL`, atau `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, dan `DB_DATABASE`.
`DB_POOL_SIZE` bawaannya 10 dan `LISTEN_ADDR` mengatur alamat HTTP.
Sesi memakai database JSON/base64 dengan cookie `laravel-session`, `XSRF-TOKEN`, AES-256-CBC, MAC, serta prefix nama cookie Laravel.
`SESSION_COOKIE`, `SESSION_LIFETIME`, dan `CACHE_PREFIX` mengikuti konfigurasi yang diberikan.
Driver sesi selain database atau sesi database terenkripsi ditolak saat startup.

## Perilaku yang dijalankan

Profil utama menjalankan receipt list, create, draft show, PATCH penggantian detail penuh, preview, complete, completed show, generated assets, asset list, serta show setiap aset.
Default organisasi, izin recursive role/duty/privilege, masa berlaku assignment, dan policy organisasi dibaca ulang pada setiap request.
Pilihan dalam sesi tidak memberi izin.

Nomor header commit sebelum transaksi header.
Nomor aset, claim versi, status, asset, placement, book, ledger trigger, atribut, dan finance posting berada dalam transaksi completion yang sama.
Numbering memakai konfigurasi database, contextual scope hash, counter lock, preallocation atau reusable direct number, collision check, issue, audit, serta savepoint.
PATCH memakai DELETE detail fisik sesuai perilaku source ini.
Version memakai trigger database dan dapat naik dua kali.

Harga dikalikan jumlah sebagai desimal eksak, dibulatkan HALF_UP, lalu remainder dialokasikan ke unit awal.
Nilai desimal dari PostgreSQL memakai scale kolom, termasuk nol.
Book mengikuti matrix aktif, posted book, ambang kapitalisasi, profil utama dan alternatif, useful life, serta convention.
Definisi dan nilai atribut dibaca serta dikunci walau input atribut kosong.
Completion selalu membaca source document attachments dengan lock, termasuk ketika hasilnya kosong.

Posting menyusun input dari grup dan unit dimensi, membaca effective account mapping, vendor, perusahaan, currency precision, hierarchy, nearest BU, dan pengaturan feed.
Hasil evaluasinya dapat `pending`, `held`, atau `manual`.
Publikasi menyimpan `finance_postings`, `finance_posting_lines`, dan event `published` melalui savepoint.
Input hash, payload snapshot, journal balance, serta idempotency berasal dari logika Go.

Daftar memakai PostgreSQL `simple` full-text prefix atau phrase search, Unicode NFC, filter sebelum limit, null-last sort, numeric sort, ID tie-breaker, dan cursor Laravel JSON/base64url.
Strategi query dan cache berbeda dari Eloquent. Hasil eksperimen mengukur stack lengkap, bukan selisih runtime Go murni.

## Pemeriksaan

```powershell
go test ./...
go vet ./...
$goFiles = (Get-ChildItem -File -Filter '*.go').FullName
gofmt -l $goFiles
python conformance.py --base http://127.0.0.1:18642 --fixture '<fixture.json>' --env '<runtime.env>' --output '<output privat.json>'
```

Test opsional `TestPostgresNumericScaleReadback` memakai `NATIVE_GO_READONLY_TEST=1` dan variabel koneksi standar `PGHOST`, `PGPORT`, `PGUSER`, `PGPASSWORD`, `PGDATABASE`.
Test tersebut hanya menjalankan SELECT literal numeric.

Pada database pengembangan terisolasi, 12 request profil dan 31 probe HTTP lulus sebelum perbaikan terakhir pada format nol, limiter login, dan preview blocker.
Oracle SQL koordinator meluluskan 24 pemeriksaan pada hasil pertama.
Perbaikan terakhir lulus unit test, `go vet`, dan formatter.
HTTP differential terakhir harus diulang koordinator dari clone bersih. Jangan menganggap compile sebagai bukti parity HTTP final.
Tidak ada hasil kapasitas atau klaim benchmark dalam folder ini.

## Batas yang belum diverifikasi

Ini implementasi satu profil purchase pending, bukan seluruh module Management Aset.
Branch grant, opening balance, linked purchase request, lampiran tidak kosong, cancellation, model dan condition eksplisit, planning lookup, fiscal-reset numbering, continuous numbering, operating-unit numbering, serta convention penyusutan selain none/full-month/mid-month belum dicakup dan ditolak ketika dicapai.
Password 2FA, remembered login, key rotation, Redis session, environment dedicated database, dan lisensi yang diwajibkan belum diimplementasikan.
Profil memakai alamat lokal tanpa environment base domain dan tanpa lisensi wajib.
Cookie bootstrap menyediakan sesi API; HTML halaman login PHP tidak direplikasi.
Transliterasi limiter pada alamat email non-ASCII belum diverifikasi.
Gate all-native conformance, query-accounting, PHP-off smoke, dan benchmark server tetap dimiliki koordinator.
