# API yang dapat dipakai sistem pelanggan

> **Ini di luar pemindahan satu runtime.** Folder [satu-runtime](../satu-runtime/) adalah rencana
> memindahkan app menjadi modul; pekerjaan di halaman ini tidak memindahkan apa pun. Ia membuka
> permukaan yang sudah ada untuk pemanggil yang bukan peramban.

## Keadaan yang memunculkannya

Seorang pelanggan membeli modul aset saja dan **sudah punya backoffice sendiri**. Ia tidak mau
memakai layar kita untuk semuanya; ia mau sistemnya sendiri membaca dan menulis data aset lewat API.

Setengah dari skenario itu sudah berjalan: image edisi memang hanya memuat modul yang dibeli, dan
itu diperiksa CI. Setengah yang lain belum — dan yang menghalangi bukan arsitekturnya.

## Yang sudah ada, dan pantas dipakai ulang

| Yang sudah ada | Di mana |
| --- | --- |
| API modul ber-versi | `/api/modules/<id module>/v1/...` |
| Kontrak OpenAPI per modul | `modules/<penerbit>/<module>/contracts/openapi.yaml` |
| Izin per sumber daya | kode seperti `management-aset.group-aset.read` pada `app.yaml` |
| Idempotensi | header `Idempotency-Key`, sudah dihormati jalur tulis |
| Penyaringan tenant | `MilikTenant`, menjaga baca **dan** tulis |

Jumlah rutenya dapat dilihat sendiri dengan `php artisan route:list --path=api/modules`; jangan
menyalin angkanya ke dalam dokumen ini, karena ia bertambah tiap modul baru.

## Yang menghalangi

**Seluruh rute API duduk di belakang `web` dan `auth`** — artinya **cookie sesi**. Tidak ada satu pun
paket token terpasang: bukan Sanctum, bukan Passport, bukan JWT. Diperiksa pada `composer.lock`,
bukan dikira-kira. Backoffice yang memanggil dari server ke server tidak punya kredensial untuk
disodorkan.

**Dan ini bagian yang lebih dalam daripada "tinggal pasang paket token".** `App\Support\CurrentWorkspace`
memilih tenant, legal entity, dan unit operasi yang aktif dari **sesi**, dengan jatuh ke "yang
pertama" bila sesinya belum menyebutkan apa pun. Pemanggil server-ke-server tidak punya sesi — dan
yang lebih penting, ia harus **menyebut** ketiganya, bukan mewarisinya dari klik terakhir seseorang
di peramban. Membiarkan jalur token memakai "yang pertama" berarti sebuah token dapat diam-diam
menulis ke legal entity yang salah, dan tidak ada yang gagal.

## Urutan yang disarankan, dan kenapa urutannya begitu

### 1. Satu grup middleware milik Core

Kedua modul hari ini menuliskan middleware-nya sendiri:

- `modules/apperp/human-resources/src/ModuleServiceProvider.php`
- `modules/apperp/management-aset/src/ModuleServiceProvider.php`

Keduanya menulis `Route::middleware(['web', 'auth'])`. Selama bentuknya begitu, setiap perubahan cara
autentikasi menyentuh setiap modul — dan modul ketiga akan menyalin bentuk yang sama.

Sediakan satu grup milik Core, misalnya `api-module`, lalu modul cukup menulis
`Route::middleware('api-module')`. Polanya sudah ada dan sudah dipakai: `konteks-module` didaftarkan
Core di `bootstrap/app.php`, bukan ditulis ulang tiap modul.

**Dikerjakan lebih dulu karena ia yang membuat dua langkah berikutnya tidak menyentuh modul sama
sekali.** Setelah langkah ini, menambah token, mengganti guard, atau menambah batas laju adalah
perubahan di Core saja.

### 2. Konteks yang tidak bersandar pada sesi

`CurrentWorkspace` dan `ResolveModuleContext` butuh jalur yang mengambil tenant, legal entity, dan
unit operasi dari **kredensialnya**, bukan dari sesi.

**Aturannya: sempit dulu, melebar hanya kalau diminta.** Bawaannya satu legal entity — milik
kredensial itu sendiri — dan permintaan yang mau menjangkau lebih dari itu harus **menyebutnya**,
lalu tetap dibatasi kebijakan data yang dipegang penggunanya. Yang tidak boleh adalah bawaan yang
memilihkan: `?? first()` yang ada di `CurrentWorkspace` hari ini menebak untuk peramban, dan tebakan
yang sama untuk pemanggil mesin berarti data tertulis ke entitas yang salah tanpa satu pun kesalahan
terlihat.

Bentuk itu bukan karangan sendiri; ia yang dipakai D365 F&O, dan kalimatnya tegas:

> By default, OData returns only data that belongs to **the user's default company**. To see data
> from outside the user's default company, specify the `?cross-company=true` query option. This
> option returns data from **all companies that the user has access to**.

Perhatikan batas atasnya: melebar pun tidak melewati apa yang penggunanya boleh akses. Menyaring ke
satu perusahaan tertentu dilakukan pada kuerinya —
`?$filter=dataAreaId eq 'usrt'&cross-company=true` — bukan dengan menerbitkan kredensial baru.

Ini langkah yang paling mudah dikerjakan setengah jadi. Penjaganya harus dibuktikan bisa merah:
sebuah permintaan yang menjangkau entitas di luar hak penggunanya tidak boleh berhasil menulis apa
pun.

### 3. Kredensial token

**Sanctum atau JWT — keputusan ini perlu diambil sadar, bukan di tengah implementasi.**

| | Sanctum (personal access token) | JWT |
| --- | --- | --- |
| Bawaan Laravel | ya | tidak, paket pihak ketiga |
| Dicabut sebelum kedaluwarsa | ya, hapus barisnya | tidak, butuh daftar hitam tersendiri |
| Lingkup per token | `abilities`, memetakan langsung ke kode izin yang sudah ada | klaim di dalam token, disusun sendiri |
| Tanpa query saat verifikasi | tidak, satu pembacaan tabel | ya |
| On-prem tanpa telemetri vendor | sama-sama cocok | sama-sama cocok |

Yang menentukan bagi produk ini kemungkinan besar **pencabutan**: pelanggan on-prem yang tokennya
bocor harus bisa mematikannya sekarang, bukan menunggu kedaluwarsa. Itu memihak Sanctum. JWT menjadi
menarik bila kelak ada banyak layanan yang memverifikasi tanpa menyentuh database yang sama — keadaan
yang belum ada.

Apa pun yang dipilih: **izin token berpotongan dengan rantai izin yang sudah ada, bukan
menggantikannya.** Sebuah token tidak boleh dapat melakukan sesuatu yang penggunanya sendiri tidak
boleh. Kalau rantai `role → duty → privilege → permission` dapat dilewati token, seluruh model
keamanan tenant kehilangan artinya.

### 4. Batas laju, rotasi, dan jejak

Per token, bukan per IP. Pemanggil server-ke-server datang dari satu alamat dan dapat membanjiri
sendiri; dan pelanggan yang tokennya dipakai berlebihan perlu melihat itu pada jejaknya sendiri,
bukan pada telemetri kita.

## Selesai bila

- Sebuah backoffice di luar dapat membaca dan menulis data modul yang dibeli tenantnya, tanpa
  peramban dan tanpa sesi.
- Kredensialnya membawa satu legal entity bawaan, dan permintaan yang menjangkau lebih dari itu
  harus menyebutnya. Permintaan yang menjangkau entitas **di luar hak penggunanya ditolak**, dan
  penolakan itu diuji — bukan hanya jalur yang berhasil.
- Token tidak dapat melakukan apa pun di luar izin penggunanya, dan itu diuji pada permission yang
  memang tidak dipegang.
- Token dapat dicabut, dan pencabutannya berlaku pada permintaan berikutnya.
- Kontrak OpenAPI modul menyebutkan cara autentikasi yang baru, sehingga integrator tidak perlu
  membaca kode kita untuk tahu caranya.
- Kedua modul tidak menuliskan middleware autentikasi sendiri lagi.

## Yang sudah dipecahkan orang lain, dan bagaimana

Empat hal dari dokumentasi Dynamics 365 Finance & Operations yang langsung mengenai keputusan di
atas. Dibaca dari sumbernya, bukan dari ingatan.

### 1. API datanya CRUD penuh; yang read-only cuma katalognya

Mudah salah baca, karena keduanya ada di halaman yang sama. Endpoint **Metadata** memang hanya
menerima GET — gunanya menjelaskan label dan daftar data entity. API datanya endpoint lain:

> It supports complete CRUD (create, retrieve, update, and delete) functionality that you can use to
> insert and retrieve data from the system.
>
> CRUD support works through HTTP verb support for **POST, PATCH, PUT, and DELETE**.

Jadi "API untuk sistem pelanggan" memang berarti baca **dan** tulis, bukan sekadar jendela laporan.

### 2. Menulis lewat API menjalankan validasi yang sama dengan layar

Dokumen OData mendaftar urutan yang dipanggil tiap operasi tulis: `validateField()` → `defaultRow()`
→ `validateWrite()` → `write()`, dan untuk hapus `validateDelete()` lebih dulu.

Artinya API bukan pintu samping yang melewati aturan bisnis. Itu prinsip yang sama dengan
"izin token berpotongan, tidak menggantikan" di atas — dan di sini ia bukan pendapat, melainkan
bagaimana produk sebesar itu memang dibangun.

### 3. Lingkup perusahaan dipilih per-permintaan, dibatasi hak penggunanya

Sudah dikutip pada langkah 2. Yang perlu digarisbawahi: bawaannya **sempit**, melebarnya **eksplisit**,
dan batas atasnya tetap hak pengguna.

### 4. Sinkron bukan untuk volume besar

OData sinkron dan tidak berbatch; untuk impor besar mereka menyuruh pindah ke batch data API yang
asinkron, dengan ancar-ancar **di atas beberapa ratus ribu record**.

Untuk keadaan yang memunculkan halaman ini — backoffice pelanggan membaca dan menulis data aset
secara wajar — pintu sinkron memang yang benar. Catatan ini ada supaya pintu kedua tidak diminta
terlalu awal, dan supaya ia tidak dilupakan kalau volumenya kelak naik.

### Satu perbedaan yang menguntungkan kita

> For **on-premises** deployments, the only supported API is the Data management package REST API.

Pelanggan on-prem D365 **tidak** mendapat OData sama sekali. Skenario yang memunculkan halaman ini —
pelanggan on-prem yang mau menyambungkan backoffice-nya sendiri — tidak dapat dilayani produk itu di
tempat yang sama. Kalau kita melayaninya, itu bukan mengejar ketertinggalan.

**Sumber.**
[Service endpoints overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/services-home-page) ·
[Open Data Protocol (OData)](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/odata) ·
[Integration between finance and operations apps and third-party services](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/integration-overview)

## Yang sengaja tidak ada di sini

Jalur ini **pernah ada** dalam bentuk lain: bearer token yang ditukar antar bingkai, dibuang pada
F4-06 karena untuk UI dalam satu origin ia memang alat yang salah. Yang hilang bukan desainnya,
melainkan jalur untuk pemanggil yang bukan peramban. Jangan menghidupkan kembali token konteks
antar bingkai; ia memecahkan masalah yang berbeda.
