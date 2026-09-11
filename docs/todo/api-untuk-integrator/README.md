# API yang dapat dipakai sistem pelanggan

> **Ini di luar pemindahan satu runtime.** Pemindahan itu mengubah app menjadi modul; pekerjaan di
> halaman ini tidak memindahkan apa pun. Ia membuka permukaan yang sudah ada untuk pemanggil yang
> bukan peramban.

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

### 4. Batas laju, rotasi, dan jejak — prasyarat pintunya dibuka, bukan polesan

Per token, bukan per IP. Pemanggil server-ke-server datang dari satu alamat dan dapat membanjiri
sendiri; dan pelanggan yang tokennya dipakai berlebihan perlu melihat itu pada jejaknya sendiri,
bukan pada telemetri kita.

Ia berada di urutan terakhir karena ia bersandar pada ketiga langkah sebelumnya, **bukan karena ia
boleh menyusul belakangan**. Alasannya ada di bagian terakhir halaman ini, "Kenapa on-prem D365 kehilangan OData": pembatas laju
di sana dikunci pada identifier milik penyedia identitas, dan begitu penyedia identitasnya berganti
di on-prem, pembatasnya ikut hilang — lalu pintunya ditutup.

Keadaan kita berbeda pada penyebabnya, tetapi sama pada akibatnya. Kredensial mesin di sini adalah
baris di database pelanggan, jadi kuncinya tidak pernah hilang. Yang hilang adalah **mata kita**:
pembaruan on-prem dijalankan admin di tempat pelanggan, dan kotak itu tidak kita lihat. Maka
pembatasnya harus **ikut terkirim di dalam image dan menyala sejak awal**, bukan dioperasikan
belakangan oleh kita.

Selama itu belum ada, membuka pintu sinkron ke sistem luar berarti menyerahkan ketersediaan layar
pelanggan kepada kualitas kode integrator: `core-app` di kotak pelanggan **satu container**, dan
permintaan API memakan proses yang sama yang melayani layar.

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
- Pembatas laju per token **menyala di image bawaan tanpa disetel siapa pun**, dan batasnya
  dibuktikan dengan permintaan yang benar-benar ditolak — bukan dengan membaca setelannya.

## Yang sudah dipecahkan orang lain, dan bagaimana

Bacaan dari dokumentasi Dynamics 365 Finance & Operations yang langsung mengenai keputusan di atas.
Dibaca dari sumbernya, bukan dari ingatan. Bagian terakhir memuat satu koreksi atas kesimpulan yang
pernah ditulis di halaman ini sendiri.

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

### 5. Pola dipilih dengan tiga pertanyaan, dan angkanya bukan batas sistem

Halaman *Integration overview* mereka tidak menyuruh memilih pola berdasarkan selera. Ia menyebut
tiga pertanyaan, lalu menjawabnya enam kali dengan tabel yang bentuknya sama:

| Skenario | Real-time? | Volume puncak | Frekuensi | Pola |
| --- | --- | --- | --- | --- |
| Create dan update product information | Yes | 1.000 rec/jam | Unplanned | OData |
| Read status customer order | Yes | 5.000 rec/jam | Unplanned | OData |
| Approve BOM | Yes | 1.000 rec/jam | Unplanned | OData **action** |
| Look up on-hand inventory | Yes | 1.000 rec/jam | Unplanned | Custom service |
| Import sales order volume besar | **No** | 200.000 rec/jam | tiap 5 menit | Batch data API |
| Export purchase order volume besar | **No** | 300.000 rec/jam | tiap jam | Batch data API |

Angkanya sengaja tidak dijadikan aturan:

> "Use these numbers only to gauge the pattern and don't consider them as hard system limits."

Yang perlu ditiru bukan angkanya, melainkan **kebiasaan menuliskan ketiga jawabannya sebelum memilih
pola**. Tabel yang sama, dikosongkan, adalah bentuk yang berguna untuk tiap skenario integrasi kita:

| Keputusan | Jawaban |
| --- | --- |
| Butuh real-time? | |
| Volume puncak | |
| Frekuensi | |

Satu hal yang mengikuti dari pilihan itu dan mudah terlewat: **penanganan galatnya berbeda**. Pola
sinkron mengembalikan sukses atau gagal kepada pemanggil, dan pemanggil yang menanganinya. Pola
asinkron hanya mengembalikan kabar bahwa **penjadwalannya** berhasil; sesudah itu status impor atau
ekspornya tidak didorong ke mana pun, dan pemanggil **harus menanyakannya sendiri**. Membuka pintu
asinkron berarti berutang satu endpoint status, bukan cuma satu endpoint kirim.

### Kenapa on-prem D365 kehilangan OData, dan apa artinya bagi kita

Bagian ini ada karena kesimpulan pertama yang ditulis di halaman ini **salah**, dan salahnya ke arah
yang menyenangkan diri sendiri: ia berbunyi bahwa pelanggan on-prem D365 tidak dapat dilayani produk
itu, jadi melayaninya membuat kita unggul. Yang benar lebih sempit, dan lebih berguna.

**Yang memang tertulis** adalah pernyataan dukungan, bukan pernyataan kemampuan:

> "For **on-premises** deployments, the only supported API is the Data management package REST API."

**Mekanismenya justru bekerja, dan Microsoft sendiri yang menerbitkan caranya.** Artikel arsip mereka
menjalankan contoh `OdataConsoleApplication` ke instance **on-premises**, memakai AD FS server
application beserta shared secret, lalu mendaftarkan client id itu di tabel yang sama seperti di
cloud (`System administration > Setup > Azure Active Directory applications`). Jadi yang dicabut
adalah dukungannya, bukan kemampuannya.

Sebabnya tidak dijelaskan Microsoft di mana pun yang ditemukan — halaman auth on-prem, halaman
service endpoints, maupun halaman on-premises overview semuanya diam. Tetapi tiga fakta yang mereka
tulis sendiri berbaris rapi:

1. Autentikasi API-nya bersandar pada Entra ID, sampai ke prasyaratnya: *"You must have an Azure
   subscription and admin access to Microsoft Entra ID."*
2. Pembatas laju yang menjaga server mereka **dikunci pada identifier Entra**. Kunci throttle-nya
   disusun dari *"the object ID of the Microsoft Entra user principal"*, atau — untuk kredensial
   mesin — *"the object ID of the application in Microsoft Entra ID"*.
3. On-prem menukar Entra dengan AD FS. Entra di sana hanya dipakai Lifecycle Services dan Azure
   DevOps, bukan aplikasinya.

Lalu kalimat ini menutupnya:

> "Service protection API limits are available only for the finance and operations apps online
> service … They aren't available for on-premises or development environments."

**Kesimpulan berikut ini milik halaman ini, bukan kalimat Microsoft:** token on-prem mereka tetap
tervalidasi, tetapi jaring pengamannya ikut hilang bersama penyedia identitasnya — dan satu-satunya
pola yang mereka izinkan tersisa, batch data API, kebetulan pola yang memang tidak membutuhkan
pembatas laju karena ia menjadwalkan batch job alih-alih mengeksekusi di dalam permintaan. Bacaan itu
didukung satu detail lain: DIXF dan recurring integrations terdaftar sebagai **dikecualikan** dari
throttling.

#### Apa yang benar-benar berbeda pada kita

**Kuncinya tidak pernah hilang.** Kredensial mesin di sistem ini adalah baris di database pelanggan,
bukan objek di direktori luar. Pelanggan on-prem dan pelanggan SaaS memakai kunci yang bentuknya
sama, karena tidak ada bagian dari identitas yang berada di luar kotak. Pemetaan `kredensial → satu
user` yang mereka pakai tetap pantas ditiru; yang tidak perlu ditiru adalah ketergantungannya pada
direktori luar.

**Yang tidak boleh kita klaim.** Pengaman mereka dua lapis: per-user, dan berbasis resource dengan
ambang CPU serta memori di **beberapa web server**. Di kotak pelanggan kita `core-app` cuma satu
container, jadi lapisan kedua tidak punya tempat untuk berdiri. Dan sama seperti mereka, kita tidak
melihat kotak itu. Karena itu pembatas lajunya menjadi prasyarat, bukan langkah terakhir; itulah yang dijelaskan
langkah 4 di atas.

**Sumber.**
[Service endpoints overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/services-home-page) ·
[Open Data Protocol (OData)](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/odata) ·
[Integration between finance and operations apps and third-party services](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/integration-overview) ·
[Service protection API limits](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/service-protection-api-limits) ·
[Authentication in Dynamics 365 Finance + Operations (on-premises)](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/deployment/authentication-onprem) ·
[On-premises deployment overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/deployment/on-premises-overview) ·
[Authenticate with Dynamics 365 for Finance and Operations web services in on-premises](https://learn.microsoft.com/en-us/archive/blogs/axsa/authenticate-with-dynamics-365-for-finance-and-operations-web-services-in-on-premise)

## Yang sengaja tidak ada di sini

Jalur ini **pernah ada** dalam bentuk lain: bearer token yang ditukar antar bingkai, dibuang pada
F4-06 karena untuk UI dalam satu origin ia memang alat yang salah. Yang hilang bukan desainnya,
melainkan jalur untuk pemanggil yang bukan peramban. Jangan menghidupkan kembali token konteks
antar bingkai; ia memecahkan masalah yang berbeda.
