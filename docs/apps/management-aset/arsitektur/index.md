# Peta modul

Halaman ini untuk developer yang baru pertama membuka repo `app-erp-management-asset`. Isinya susunan kode dan konvensi yang berlaku di seluruh modul.

## Lapisan

```
ui/          React + Vite. Dipasang di dalam iframe Shell Core.
api/         Laravel. Tidak pernah menyentuh database Core.
database/    Migration. Terpisah dari api/ karena dijalankan sendiri saat deploy.
contracts/   Janji ke kode di luar modul ini.
loadtest/    Stack uji beban lengkap beserta stub Core.
app.yaml     Manifest: entry point, permission, privilege, duty, reference nomor.
```

Pemisahan `database/` dari `api/` disengaja: migration dijalankan `deploy/migrate.sh` sebagai langkah tersendiri, bukan saat aplikasi menyala.

## Konvensi nama tabel

Awalan menentukan sifat tabelnya, dan ini konsisten di seluruh modul:

| Awalan | Artinya | Contoh |
| --- | --- | --- |
| `m_` | Master — daftar pilihan yang dipakai berulang | `m_jenis_aset` |
| `t_` | Tabel inti | `t_aset` |
| `tr_` | Transaksi — kejadian yang tercatat | `tr_penempatan_aset` |

::: warning Nama yang membingungkan
Model `Asset` menunjuk tabel `tr_penerimaan_aset`, bukan `t_aset`. Ini peninggalan penggantian nama tabel yang belum dirapikan, dan sering menyulitkan waktu mencari. Kalau Anda menulis kueri mentah, periksa dulu nama tabel di modelnya.
:::

## Susunan folder API

```
api/app/
├── Http/Controllers/
│   ├── MasterDataController.php          base untuk semua master
│   ├── master/                           turunan per master, biasanya beberapa baris
│   └── transaksi/
│       ├── InventarisasiAset/            register aset dan penyusutan
│       ├── PemeliharaanAset/             work order
│       ├── PerencanaanAset/              rencana pengadaan
│       └── DokumenSiklusAset/            dekomisioning, penjualan, pemusnahan
├── Models/master/ dan Models/transaksi/
├── Services/                             klien ke Core dan kalkulator
└── Support/                              aturan yang dipakai bersama
```

Di luar folder itu ada tiga controller yang melayani platform: `ContextController`, `HealthController`, dan `ReferenceDataController`.

`Services/` berisi hal yang berbicara keluar (`NumberSequenceClient`, `WorkflowClient`, `FiscalCalendarClient`, `UnitOfMeasureClient`) dan `DepreciationCalculator`. `Support/` berisi aturan murni: `OrganizationScope`, `WorkOrderStatus`, `AssetAttributeValidator`.

Kalau Anda menaruh aturan bisnis di controller padahal ia dipakai lebih dari satu tempat, ia akan menyimpang. Contoh yang sudah benar: status work order dikumpulkan di `WorkOrderStatus`, bukan disebar sebagai pemeriksaan di tiap endpoint.

## Endpoint yang bukan milik fitur mana pun

Tiga endpoint melayani platform, bukan salah satu fitur:

| Endpoint | Gunanya |
| --- | --- |
| `GET /api/v1/health` | Dipakai Docker dan Core untuk memastikan API hidup. Tidak menyentuh database |
| `GET /api/v1/context` | Menyajikan hak akses efektif pada token: permission, badan hukum, unit kerja, id pengguna |
| `GET /api/v1/reference-data/units-of-measure` | Satuan, diteruskan dari Core |
| `GET /api/v1/reference-data/kelompok-harta-fiskal` | Kelompok pajak milik tenant |

`/context` dipakai UI untuk menentukan tombol apa yang ditampilkan. Perlu ditegaskan: **ia bukan penjaga akses.** Penjaga sebenarnya ada di tiap endpoint; `/context` hanya membuat layar tidak menawarkan hal yang akan ditolak server.

::: tip Kenapa controller, bukan closure
`ContextController` dan `HealthController` ditulis sebagai controller meski isinya pendek. Satu closure di berkas rute membuat `php artisan route:cache` diam-diam berhenti bekerja, dan cache rute itu yang dipakai image produksi.
:::

Kalau layanan satuan Core belum bisa dihubungi, endpoint satuan menjawab **503**, bukan daftar kosong. Daftar kosong akan terbaca sebagai "tidak ada satuan", padahal yang terjadi adalah "belum tahu".

## Yang wajib ada di setiap endpoint baru

1. **Permission sendiri**, bukan menumpang yang sudah ada. Diperiksa dari `coreerp.permissions` pada request.
2. **Batas tenant.** Setiap kueri menyaring `tenant_id`.
3. **Batas organisasi** untuk data aset — lewat `OrganizationScope`, bukan hanya `tenant_id`.
4. **Masuk kontrak.** Ada pemeriksa di CI yang menolak rute tanpa kontrak.
5. **`Idempotency-Key`** kalau endpoint itu membuat sesuatu.

## Halaman arsitektur lainnya

- [Batas tenant dan organisasi](/apps/management-aset/arsitektur/batas-tenant-dan-organisasi) — dua lapis penyaringan dan kenapa keduanya perlu
- [Integrasi dengan Core](/apps/management-aset/arsitektur/integrasi-core) — nomor, workflow, event, penyiapan tenant
- [Kontrak](/apps/management-aset/arsitektur/kontrak) — OpenAPI, AsyncAPI, dan pemeriksa cakupannya
- [Database dan migration](/apps/management-aset/arsitektur/database) — pola kunci gabungan yang menutup kebocoran antar tenant
- [Pengujian](/apps/management-aset/arsitektur/pengujian) — apa yang bisa dan tidak bisa dilihat feature test
