# Pengujian

Halaman ini untuk developer. Isinya apa yang bisa dan **tidak bisa** dilihat tiap jenis pengujian di modul ini.

## Feature test

```bash
cd api && php artisan test
```

Berjalan satu permintaan pada satu proses terhadap SQLite.

### Di mana menambah test

| Berkas | Menjaga |
| --- | --- |
| `AssetRegisterTest` | Pembuatan aset, idempotency, kolom yang disalin dari group |
| `AssetLifecycleTest` | Perpindahan status hidup aset |
| `AssetLocationTest` | Lokasi dan pemetaan dimensi keuangan |
| `AssetAttributeTest` | Atribut per jenis aset dan penguncian tipe data |
| `AssetPlanningTest` | Rencana pengadaan dan penanda versinya |
| `ModelAsetTest` | Aturan kombinasi jenis, pabrikan, dan model |
| `MasterDataAsetTest` | Perilaku bersama semua master |
| `ProfilPenyusutanTest`, `DepreciationBookTest` | Profil dan buku |
| `DepreciationTest`, `DepreciationEndToEndTest` | Proposal, finalisasi, pembalikan |
| `DepreciationCalculatorTest` | Hitungan murni, tanpa HTTP |
| `DepreciationScaleTest` | Perilaku pada jumlah data besar |
| `MaintenanceSetupTest` | Setup maintenance dan penautannya |
| `WorkOrderTest`, `WorkOrderExecutionTest` | Dokumen work order dan pengisian checklist |
| `IndonesiaStarterProvisioningTest` | Penyiapan tenant, termasuk pengulangannya |
| `NumberSequenceFailureTest` | Perilaku saat Core menolak atau tidak bisa dihubungi |

`InteractsWithCoreErpContext` adalah trait yang menyusun token konteks palsu untuk test. Pakai itu, jangan membuat token sendiri — kalau bentuk token berubah, satu tempat yang perlu disesuaikan.

### Yang dijaganya

- Induk lintas tenant tertolak.
- Hak satu master tidak merembet ke master lain.
- Induk yang masih beranak tidak bisa diarsipkan.
- `kode` selalu berasal dari Core.
- Transisi status work order mengikuti grafiknya.
- Penyiapan data awal idempoten.

## Yang tidak bisa dilihat feature test

Ini bagian yang penting, dan mudah dilupakan.

Feature test **secara struktural** tidak bisa melihat:

- koneksi database habis,
- nomor ganda,
- batas tenant yang bocor hanya ketika permintaan saling menyela,
- kunci idempotency yang berlomba dengan dirinya sendiri,
- dan penggantian sekumpulan baris yang saling menimpa.

Bukan karena testnya kurang lengkap, melainkan karena ia menjalankan satu permintaan pada satu waktu. Menambah test feature tidak akan pernah menutup celah ini.

Contoh nyata: endpoint penautan maintenance menghapus lalu menyisipkan ulang tanpa mengunci baris pemiliknya. Dua penulis bisa menghasilkan gabungan dua daftar. Seluruh 181 test tetap hijau.

## Load test

```bash
cd loadtest
node mint-tenants.mjs
docker run … grafana/k6:0.55.0 run /scripts/master-data.js
```

Menjalankan API sungguhan di belakang load balancer, dengan PostgreSQL asli dan stub Core yang mencatat setiap nomor yang diterbitkan.

| Skrip | Yang diuji |
| --- | --- |
| `master-data.js` | CRUD master, idempotency, batas tenant, eskalasi hak |
| `depreciation.js` | Proposal dan finalisasi penyusutan beserta pengulangannya |
| `maintenance.js` | Setup maintenance, dan balapan penggantian kaitan |

### Profil

| Profil | Menjawab |
| --- | --- |
| `saturation` | Apakah tetap benar di bawah beban penuh |
| `latency` | Berapa lama pada tingkat yang masih memenuhi SLO |
| `link-race`, `attribute-race` | Apakah dua penulis yang berebut baris yang sama saling merusak |

### Skenario balapan harus benar-benar berebut

Menyebar pengguna merata ke seluruh tenant adalah bentuk yang benar untuk `saturation`, dan **salah** untuk balapan: dua penulis nyaris tidak pernah bertemu, hasilnya hijau, dan cacatnya lolos.

Karena itu profil balapan memusatkan: banyak pengguna, sedikit record, menulis nilai yang sengaja bertabrakan, lalu membaca balik dan memastikan hasilnya sama dengan salah satu nilai yang dikirim — bukan campuran keduanya.

### Permukaan baru butuh skenarionya sendiri

Modul yang skenarionya hanya mencakup master lama tetapi tidak yang baru berstatus **belum terverifikasi** untuk bagian yang berubah.

Periksa daftar resource di skrip terhadap rute yang ada. Nama yang kebetulan terdengar mirip bukan cakupan — `item-checklist-maintenance` adalah master lama, bukan setup maintenance yang baru.

## Gate sebelum menyebut modul selesai

Ini aturan platform, bukan pilihan modul. Rinciannya di [Load dan concurrency testing](/dev/20-load-and-concurrency-testing).

Yang harus nol, dan tidak bergantung pada kecepatan mesin:

- error 5xx aplikasi,
- `kode` atau `creation_key` ganda dalam satu tenant,
- baris anak yang induknya milik tenant lain,
- pembacaan atau penulisan yang melewati batas tenant,
- permission satu resource yang memberi akses ke resource sebelah,
- dua permintaan dengan `Idempotency-Key` sama menghasilkan dua record.

Diperiksa dengan SQL langsung ke database setelah run, **bukan** lewat API. API adalah yang sedang diuji; jawabannya tidak bisa dipakai untuk menilai dirinya sendiri.

## Hasil yang sudah tercatat

Modul ini sudah melewati gate concurrency: 1000 pengguna serentak pada 128 tenant, empat instance API, PostgreSQL asli. Nol pelanggaran lintas tenant, nol nomor ganda dari 4.342 nomor terbit, nol eskalasi hak.

Temuan pentingnya bukan angka throughput, melainkan **apa yang jenuh lebih dulu**: penanganan koneksi database, bukan kode modul. Tanpa koneksi persisten, PostgreSQL menghabiskan 5,5 core hanya untuk membuat proses baru tiap permintaan. Karena itu ada `DB_PERSISTENT` di `api/config/database.php`, default mati, dinyalakan pada deployment dengan worker tetap.

## Halaman terkait

- [Load dan concurrency testing](/dev/20-load-and-concurrency-testing) — gate platform
- [Database dan migration](/apps/management-aset/arsitektur/database) — batasan yang diandalkan pengujian
- `loadtest/README.md` di repo app — cara menjalankan dan batas kejujuran hasilnya
