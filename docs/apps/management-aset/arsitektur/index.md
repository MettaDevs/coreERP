# Peta modul

Halaman ini untuk developer yang baru pertama membuka `modules/apperp/management-aset/` di dalam
repo CoreERP. Isinya susunan kode dan konvensi yang berlaku di seluruh modul.

## Lapisan

```text
src/                PHP modul: controller, model, service, listener, definisi laporan.
routes/             web.php untuk layar, api.php untuk JSON. Dimuat penyedia layanan modul.
database/           Migration modul, seluruhnya bertabel berawalan `aset_`.
ui/                 Halaman React. Ikut build shell Core; tidak ada iframe.
contracts/          Janji ke kode di luar modul ini.
loadtest/           Stack uji beban lengkap.
app.yaml            Manifest: entry point, permission, privilege, duty, reference nomor.
```

Modul berjalan di dalam runtime Core. Ia tidak punya `artisan`, `Dockerfile`, `.env`, maupun
`vendor/` sendiri — ada penjaga batas yang menolak masing-masing kalau muncul.

Migration dijalankan `php artisan module:migrate management-aset`, bukan saat aplikasi menyala. Di
stack lokal, skrip orkestrasi memanggilnya untuk Anda.

## Konvensi nama tabel

Setiap tabel modul diawali `aset_`. Awalan itulah yang memisahkan datanya dari Core dan modul lain
di dalam satu database tenant yang sama, dan ia wajib tanpa kecuali — termasuk tabel bantu seperti
`aset_processed_core_events`, yang tanpa awalan hampir pasti bertabrakan dengan modul lain yang
menyaring kejadian ganda.

Sesudah awalan, penanda berikutnya menyatakan sifat tabelnya:

| Bentuk | Artinya | Contoh |
| --- | --- | --- |
| `aset_m_` | Master — daftar pilihan yang dipakai berulang | `aset_m_jenis_aset` |
| `aset_tr_` | Transaksi — kejadian yang tercatat | `aset_tr_penempatan_aset` |

::: tip Register dan dokumennya dua tabel berbeda
Model `Aset` menunjuk `aset_tr_aset` — register: satu baris satu aset, selama aset itu hidup.
`aset_tr_penerimaan_aset` adalah dokumen penerimaannya: satu baris satu kedatangan barang,
dan `jumlah` pada barisnya yang menentukan berapa aset lahir darinya.

Sampai 18 September 2026 registerlah yang bernama `aset_tr_penerimaan_aset`, warisan masa
ketika satu-satunya cara aset masuk adalah mengisi layar penerimaan satu per satu. Nama itu
kini dipegang yang berhak atasnya.
:::

Daftar penggantian namanya dibaca dari katalog PostgreSQL, bukan ditulis tangan — lihat
`database/migrations/2026_09_08_130000_prefix_tabel_modul.php`. Daftar yang ditulis tangan akan
tertinggal satu tabel pada hari seseorang menambah migration baru, dan tabel yang tertinggal itu
tidak gagal dengan sendirinya; ia hanya diam sampai bertabrakan.

## Susunan folder PHP

```text
src/
├── Http/Controllers/
│   ├── HalamanModulController.php        satu-satunya penyaji layar
│   ├── MasterDataController.php          base untuk semua master
│   ├── master/                           turunan per master, biasanya beberapa baris
│   └── transaksi/
│       ├── InventarisasiAset/            register aset dan penyusutan
│       ├── PemeliharaanAset/             work order
│       ├── PerencanaanAset/              rencana pengadaan
│       ├── PermintaanPengadaanAset/      permintaan pembelian
│       └── DokumenSiklusAset/            dekomisioning, penjualan, pemusnahan
├── Models/master/ dan Models/transaksi/
├── Reporting/                            definisi dan dataset laporan
├── Listeners/                            penerima event Core
├── Services/                             pembungkus kontrak Core dan kalkulator
└── Support/                              aturan yang dipakai bersama
```

`Services/` berisi pembungkus tipis di atas kontrak Core (`PenerbitNomorAset`, `PersetujuanAset`,
`KalenderFiskalAset`, `DaftarSatuanAset`) dan `DepreciationCalculator`. Keempat pembungkus itu dulu
klien HTTP; yang tersisa dari perannya sekarang hanya menerjemahkan kegagalan Core menjadi kegagalan
yang berarti bagi pemanggil modul. `Support/` berisi aturan murni: `OrganizationScope`,
`WorkOrderStatus`, `AssetAttributeValidator`. `Reporting/` berisi definisi dan dataset laporan yang
dibaca Core lewat kontrak `PenyediaLaporanModul`; layout, render, dan antrean ekspornya milik Core.
Lihat [Laporan dan ekspor](/apps/management-aset/transaction/laporan/).

Kalau Anda menaruh aturan bisnis di controller padahal ia dipakai lebih dari satu tempat, ia akan menyimpang. Contoh yang sudah benar: status work order dikumpulkan di `WorkOrderStatus`, bukan disebar sebagai pemeriksaan di tiap endpoint.

## Layar dirender modul, bukan iframe

`routes/web.php` memasang satu rute untuk seluruh layar:

```php
Route::get('{view}/{sisa?}', HalamanModulController::class)->where('sisa', '.*');
```

Tiga hal yang perlu diketahui:

- **Nama jalurnya sama dengan id entri menu pada `app.yaml`**, dan itu bukan kebetulan. Core
  menyusun tautan sidebar dengan aturan `/<id module>/<id entri menu>` dari manifest yang sama, jadi
  mengganti salah satu tanpa yang lain membuat menunya mendarat di 404.
  `HalamanModulController` membaca daftar itu dari manifest apa adanya, sehingga tidak ada daftar
  kedua yang bisa menyimpang.
- **`{sisa?}` harus `.*`.** Ia menampung ruas sesudah id menu — `/pemeliharaan-aset/01JQ…/ubah` —
  yang dulu ditulis sesudah tanda pagar. Bawaan Laravel berhenti pada garis miring pertama.
- **`konteks-module:management-aset` dipasang di grup rute modul**, bukan sebagai middleware global.
  Middleware itu menerima id modul sebagai parameter, dan middleware global tidak tahu ia sedang
  melayani modul yang mana.

Halaman React-nya ada di `ui/Pages/` dan ikut build shell. Ia hanya boleh mengimpor `@apperp/ui`,
React, `@inertiajs/react`, dan berkasnya sendiri. Impor `@/...` milik shell **akan** berhasil
dibangun — folder ini ikut build yang sama — dan justru itu bahayanya: modul berhenti bisa dicabut,
dan tidak ada satu pun langkah yang gagal saat itu terjadi.

## Endpoint yang bukan milik fitur mana pun

| Endpoint | Gunanya |
| --- | --- |
| `GET v1/health` | Memastikan rute modul terpasang. Tidak menyentuh database |
| `GET v1/reference-data/units-of-measure` | Satuan, diteruskan dari Core |
| `GET v1/reference-data/kelompok-harta-fiskal` | Kelompok pajak milik tenant |

Dua endpoint yang dulu ada di sini sudah tidak ada, dan sebaiknya tidak dicari:

- **`v1/context` dibuang.** Izin dan konteks dikirim bersama halaman oleh `HalamanModulController`,
  jadi layar tidak lagi menunggu satu perjalanan jaringan sebelum tahu tombol mana yang boleh
  tampil.
- **Tiga rute `internal/v1/laporan` dibuang.** Mesin laporan Core membaca definisi, layout bawaan,
  dan dataset modul lewat `Reporting\PenyediaLaporan` di dalam proses yang sama.

Kalau layanan satuan Core belum bisa dijawab, endpoint satuan menjawab galat, bukan daftar kosong. Daftar kosong akan terbaca sebagai "tidak ada satuan", padahal yang terjadi adalah "belum tahu".

## Yang wajib ada di setiap endpoint baru

1. **Permission sendiri**, bukan menumpang yang sudah ada. Diperiksa lewat kontrak `KonteksPermintaan`.
2. **Batas tenant.** Modelnya memakai trait `MilikTenant`, dan penyaringan tenant **tidak** ditulis ulang dengan tangan pada model yang sudah memakainya — query yang menyaring sendiri tetap benar walau traitnya dicabut, sehingga penjaganya berhenti terukur.
3. **Batas organisasi** untuk data aset — lewat `OrganizationScope`, bukan hanya `tenant_id`.
4. **Rute di dalam grup ber-`konteks-module`.** Ada penjaga batas yang menolak rute modul tanpa middleware itu.
5. **`Idempotency-Key`** kalau endpoint itu membuat sesuatu.

## Halaman arsitektur lainnya

- [Batas tenant dan organisasi](/apps/management-aset/arsitektur/batas-tenant-dan-organisasi) — dua lapis penyaringan dan kenapa keduanya perlu
- [Integrasi dengan Core](/apps/management-aset/arsitektur/integrasi-core) — nomor, workflow, event, penyiapan tenant
- [Kontrak](/apps/management-aset/arsitektur/kontrak) — apa yang masih perlu dikontrakkan dan apa yang tidak
- [Database dan migration](/apps/management-aset/arsitektur/database) — pola kunci gabungan yang menutup kebocoran antar tenant
- [Pengujian](/apps/management-aset/arsitektur/pengujian) — apa yang bisa dan tidak bisa dilihat feature test
