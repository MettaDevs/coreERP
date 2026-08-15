# Management Aset

App bisnis pertama. Mengelola entitas, kategori, dan maintenance aset perusahaan.

## Identitas

| | |
| --- | --- |
| ID manifest | `management-aset` |
| Publisher | `apperp` |
| Versi | `0.1.0` — release pengembangan |
| Kind | `business-app` |
| Butuh Core | `^0.1` |
| Repository | `app-erp-management-**asset**` |
| Nama folder lokal | `app-erp-management-**aset**` |
| Database | `management_aset` |
| UI entry | `/apps-content/management-aset/` |

::: warning
Nama repository dan nama folder berbeda satu huruf. `compose.yaml` build dari `../app-erp-management-aset`, jadi `git clone` tanpa menyebut nama folder akan menghasilkan folder yang tidak ditemukan build.
:::

## Domain yang dimiliki

**Milik app ini** — master data aset, register aset, penyusutan, setup dan pelaksanaan maintenance, serta dokumen siklus aset. Daftar master yang berlaku ada di `api/routes/api.php` pada array `$masters`; jumlahnya berubah seiring modul tumbuh, jadi angkanya tidak disalin ke sini.

Klasifikasi aset memakai **dua sumbu yang saling lepas**, mengikuti model Dynamics 365 F&O: **group aset** membawa perlakuan uang (penyusutan, kelompok harta fiskal, pembebanan), **jenis aset** membawa perlakuan teknis (atribut, pekerjaan maintenance). Keduanya ditunjuk langsung dari aset, dan tidak ada yang menyaring yang lain. Rantai lama `group → kategori → jenis → entitas` sudah dibongkar.

Klasifikasi itu **struktur domain app**, bukan organization hierarchy Core. Karena itu ia memang memakai foreign key permanen pada tabelnya sendiri; larangan `parent_id` permanen berlaku untuk identitas organization di Core, bukan untuk klasifikasi seperti ini.

### Halaman untuk developer

Semuanya ditulis untuk orang yang akan menyentuh kodenya: apa yang disimpan, aturan apa yang dijaga kode, dan **kenapa** aturannya begitu.

**Mulai dari sini kalau baru pertama membuka repo** — [Peta modul](/apps/management-aset/arsitektur/).

| Arsitektur | Isi |
| --- | --- |
| [Peta modul](/apps/management-aset/arsitektur/) | Lapisan, susunan folder, konvensi nama tabel |
| [Batas tenant dan organisasi](/apps/management-aset/arsitektur/batas-tenant-dan-organisasi) | Dua lapis penyaringan dan kenapa keduanya perlu |
| [Integrasi dengan Core](/apps/management-aset/arsitektur/integrasi-core) | Nomor, workflow, event, penyiapan tenant |
| [Kontrak](/apps/management-aset/arsitektur/kontrak) | OpenAPI, AsyncAPI, pemeriksa cakupan |
| [Database dan migration](/apps/management-aset/arsitektur/database) | Pola kunci gabungan antar tenant |
| [Pengujian](/apps/management-aset/arsitektur/pengujian) | Apa yang tidak bisa dilihat feature test |

| Master | Isi |
| --- | --- |
| [Master data](/apps/management-aset/master/) | Perilaku yang dipakai bersama semua master |
| [Group aset](/apps/management-aset/master/groupaset/) | Sumbu uang: pajak, penyusutan, pembebanan |
| [Jenis aset dan atribut](/apps/management-aset/master/jenisaset/) | Sumbu teknis: atribut dan pekerjaan |
| [Pabrikan dan model](/apps/management-aset/master/katalog-model/) | Katalog dan aturan kombinasinya |
| [Lokasi dan dimensi keuangan](/apps/management-aset/master/lokasi/) | Di mana barangnya, dan siapa yang menanggung biayanya |
| [Penyusutan: profil, buku, matriks](/apps/management-aset/master/depresiasi/) | Tiga lapis yang sering tertukar |
| [Setup maintenance](/apps/management-aset/master/maintenance/) | Tipe pekerjaan, varian, template checklist |
| [Master work order](/apps/management-aset/master/work-order/) | Tipe, tingkat layanan, sebab, tindakan, keahlian |

| Transaksi | Isi |
| --- | --- |
| [Perencanaan aset](/apps/management-aset/transaction/perencanaan-aset/) | Rencana pengadaan, sebelum barang ada |
| [Register aset](/apps/management-aset/transaction/register-aset/) | Catatan satu barang, sejak diterima sampai dilepas |
| [Penempatan dan mutasi](/apps/management-aset/transaction/penempatan/) | Perpindahan dan kenapa riwayatnya tidak ditimpa |
| [Proses penyusutan](/apps/management-aset/transaction/penyusutan/) | Proposal, finalisasi, pembalikan |
| [Pemeliharaan aset](/apps/management-aset/transaction/pemeliharaan-aset/) | Work order dan mesin statusnya |
| [Dokumen siklus aset](/apps/management-aset/transaction/siklus-aset/) | Dekomisioning, penjualan, pemusnahan |
| [Monitoring dan layar kosong](/apps/management-aset/transaction/monitoring/) | Ringkasan aset, dan dua layar setup yang sengaja belum berisi |

Kalau menambah halaman baru, ikuti [Pola dokumen fitur](/apps/management-aset/pola-dokumen).

**Bukan milik app ini** — identity, tenant membership, security role, scope organisasi, dan penerbitan nomor. Semuanya milik Core dan diterima lewat token konteks bertanda tangan.

## Kontrak

| | |
| --- | --- |
| OpenAPI | `contracts/openapi.yaml` |
| AsyncAPI | `contracts/asyncapi.yaml` |
| Health | `GET /api/v1/health` |
| Master data | `GET /api/v1/{resource}` |

Semua master memakai bentuk yang sama: `kode` (diterbitkan Number Sequence Core, read-only), `nama`, `keterangan`, dan penanda `aktif`. Data selalu dibatasi tenant pada token konteks.

**Reference nomor** — daftar lengkapnya di `app.yaml` bagian `number_sequences.references`; jumlahnya bertambah tiap kali ada master baru, jadi jangan menyalin angkanya ke sini. Dokumen dekomisioning memakai `management-aset.dekomisioning-aset` dengan prefix `DKMA`. Admin tenant mengaktifkan dan mengatur formatnya lewat **Nomor dokumen** di Control Plane.

**Workflow** — manifest mendaftarkan tipe **Verifikasi usulan pemusnahan aset**. Admin tenant memilih approver dan mengaktifkan versinya di Core. App mengonsumsi keputusan lewat event bertanda tangan `core.workflow.decision.v2`; setelah `approved` diterima, aset menjadi `decommissioned` dan baru boleh dijual atau dimusnahkan.

## Struktur kode

| Path | Isinya |
| --- | --- |
| `api/app/Http/Controllers/MasterDataController.php` | Base controller — hak akses per resource, batas tenant, idempotency, penerbitan nomor, validasi induk, penjagaan arsip |
| `api/app/Http/Controllers/master/` | Controller khusus tiap master |
| `api/app/Models/master/` | Model master |
| `ui/src/master/` | UI master |
| `api/tests/Feature` | Test feature |
| `loadtest/` | Stack load test lengkap |

Test feature menjaga hal yang tidak boleh regresi: induk lintas tenant tertolak, hak satu master tidak merembet ke master lain, induk beranak yang belum diarsipkan tidak dapat diarsipkan, dan `kode` selalu berasal dari Core.

## Status terhadap gate

| Gate | Status | Bukti |
| --- | --- | --- |
| Gate penemuan | ✅ Lewat | App terdaftar di katalog dengan manifest lengkap |
| Migration PostgreSQL | ✅ Ada | `deploy/migrate.sh` tersedia dan dibawa image API |
| Kontrak | ✅ Ada | `contracts/openapi.yaml`, `contracts/asyncapi.yaml` |
| Test feature | ✅ Ada | `api/tests/Feature` |
| **Gate concurrency** | ✅ **Lewat** | 1000 VU pada 128 tenant, empat instance API di belakang nginx, PostgreSQL asli |
| Scope organisasi | ⏳ Belum | Baru menerapkan batas tenant dan permission; scope organisasi menunggu contract Core |
| Upgrade release | ⏳ Belum tersedia | Versi `0.1.0`; menaikkan versi butuh compatibility matrix, backup, dan rollback terverifikasi |

**Hasil load test pada 1000 VU:** nol pelanggaran lintas tenant, nol nomor ganda dari 4.342 nomor terbit, nol eskalasi hak, nol error 5xx aplikasi. SLO latensi terpenuhi sampai 16 request serentak pada laptop 12 core; di atas itu yang bertambah antrean, bukan hasil.

Load test ini menemukan bahwa **penanganan koneksi database jenuh lebih dulu daripada kode modul** — tanpa koneksi persisten, PostgreSQL membakar 5,5 core hanya untuk fork proses baru tiap request. Karena itu tersedia `DB_PERSISTENT` pada `api/config/database.php`, default mati, dinyalakan pada deployment dengan worker proses tetap.

::: warning Gap yang diketahui
`deploy/compose.fragment.yaml` hanya mendefinisikan `management-aset-api` dan `management-aset-ui` — belum ada service database di dalamnya. Statusnya terlacak di [backlog lifecycle dan deployment](/todo/general/03-lifecycle-dan-deployment).
:::

## Menjalankan

Bagian dari stack lokal. Dari folder `erp-dev`:

```powershell
.\start.ps1 -Build
```

| Layanan | Alamat |
| --- | --- |
| API | `localhost:18091` |
| UI | `localhost:18092` |
| Database | `localhost:5544` — `management_aset` |

Diakses lewat shell Core di `http://localhost:8000`.

### Setup terhadap Core

1. Daftarkan `app.yaml` lewat alur publish sampai installation registry menyatakan release `ready`. Kirim ulang registrasi setiap kali permission, duty, atau reference nomor bertambah.
2. Buat service credential untuk `management-aset`, isi `COREERP_SERVICE_TOKEN` pada API.
3. Pakai nilai `COREERP_APP_CONTEXT_SIGNING_KEY` yang sama pada Core dan API Aset.
4. Aktifkan semua reference nomor pada **Nomor dokumen** di Control Plane. Reference yang belum aktif membuat pembuatan record gagal dengan 503, bukan diam-diam memakai nomor buatan sendiri.
5. Jalankan migration API dan build UI.

## Dokumen terkait

**Di repository app** — `README.md` (master data, workflow, struktur kode), `docs/rancangan-scope-data-aset.md` (rancangan pemisahan data per organisasi), `loadtest/README.md` (cara menjalankan, hasil terukur, batas kejujurannya).

::: tip Tautan usang di README app
`README.md` app menyebut pola folder tercatat di `docs/agent.md`, tetapi berkas itu tidak ada di repository. Perlu diperbaiki atau dihapus dari README.
:::

**Aturan platform yang berlaku:**

- [Standar module](/dev/02-module-standard) — kontrak app
- [Identity dan access](/dev/09-identity-and-access) — permission dan scope
- [Number sequence](/dev/14-number-sequences) — penerbitan `kode`
- [Load dan concurrency testing](/dev/20-load-and-concurrency-testing) — gate yang sudah dilewati app ini
- [Backlog app management aset](/todo/general/06-app-management-aset) — temuan audit yang menunggu review

## Lihat juga

- [Katalog app](/apps/)
- [Human Resources](/apps/human-resources/) — app bisnis kedua
- [Membangun app baru](/apps/membangun-app-baru) — jalur yang dilewati app ini
