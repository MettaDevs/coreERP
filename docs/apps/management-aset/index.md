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
| Bentuk | Module di runtime Core |
| Folder | `modules/apperp/management-aset/` |
| Namespace PHP | `Modules\Apperp\ManagementAset\` |
| Awalan tabel | `aset_` |
| Jalur layar | `/management-aset/<id entri menu>` |

## Domain yang dimiliki

**Milik app ini** — master data aset, register aset, penyusutan, setup dan pelaksanaan maintenance, serta dokumen siklus aset. Daftar master yang berlaku ada di `routes/api.php` pada array `$masters`; jumlahnya berubah seiring modul tumbuh, jadi angkanya tidak disalin ke sini.

Klasifikasi aset memakai **dua sumbu yang saling lepas**, mengikuti model Dynamics 365 F&O: **group aset** membawa perlakuan uang (penyusutan, kelompok harta fiskal, pembebanan), **jenis aset** membawa perlakuan teknis (atribut, pekerjaan maintenance). Keduanya ditunjuk langsung dari aset, dan tidak ada yang menyaring yang lain. Rantai lama `group → kategori → jenis → entitas` sudah dibongkar.

Klasifikasi itu **struktur domain app**, bukan organization hierarchy Core. Karena itu ia memang memakai foreign key permanen pada tabelnya sendiri; larangan `parent_id` permanen berlaku untuk identitas organization di Core, bukan untuk klasifikasi seperti ini.

### Halaman untuk developer

Semuanya ditulis untuk orang yang akan menyentuh kodenya: apa yang disimpan, aturan apa yang dijaga kode, dan **kenapa** aturannya begitu.

**Mulai dari sini kalau baru pertama membuka foldernya** — [Peta modul](/apps/management-aset/arsitektur/).

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
| [Laporan dan ekspor](/apps/management-aset/transaction/laporan/) | Dataset dan layout bawaan yang diminta Core untuk dicetak |
| [Dokumen siklus aset](/apps/management-aset/transaction/siklus-aset/) | Dekomisioning, penjualan, pemusnahan |
| [Monitoring dan layar kosong](/apps/management-aset/transaction/monitoring/) | Ringkasan aset, dan dua layar setup yang sengaja belum berisi |

Kalau menambah halaman baru, ikuti [Pola dokumen fitur](/apps/management-aset/pola-dokumen).

**Bukan milik app ini** — identity, tenant membership, security role, scope organisasi, dan penerbitan nomor. Semuanya milik Core dan diterima lewat kontrak di `App\Support\Modules\Contracts`, bukan lewat jaringan.

## Kontrak

| | |
| --- | --- |
| OpenAPI | `contracts/openapi.yaml` — gabungan, sumbernya `contracts/src/` |
| AsyncAPI | `contracts/asyncapi.yaml` |
| Prefix rute JSON | `/api/modules/management-aset/v1/...` |
| Master data | `GET /api/modules/management-aset/v1/{resource}` |

Rute itu hanya dipanggil halaman modul ini sendiri, di dalam proses dan repo yang sama; kontraknya
dipertahankan sebagai catatan, bukan sebagai janji ke pemanggil luar. Pemeriksa cakupannya sudah
dihapus — alasannya di [Kontrak](/apps/management-aset/arsitektur/kontrak).

Semua master memakai bentuk yang sama: `kode` (diterbitkan Number Sequence Core, read-only), `nama`, `keterangan`, dan penanda `aktif`. Data selalu dibatasi tenant lewat trait `MilikTenant`.

**Reference nomor** — daftar lengkapnya di `app.yaml` bagian `number_sequences.references`; jumlahnya bertambah tiap kali ada master baru, jadi jangan menyalin angkanya ke sini. Dokumen dekomisioning memakai `management-aset.dekomisioning-aset` dengan prefix `DKMA`. Admin tenant mengaktifkan dan mengatur formatnya lewat **Nomor dokumen** di Control Plane.

**Workflow** — manifest mendaftarkan tipe **Verifikasi usulan pemusnahan aset**. Admin tenant memilih approver dan mengaktifkan versinya di Core. Modul mengonsumsi keputusannya lewat event `KeputusanWorkflowDiambil` yang dipancarkan di dalam transaksi keputusan Core; setelah `approved` diterapkan, aset menjadi `decommissioned` dan baru boleh dijual atau dimusnahkan.

## Struktur kode

Semuanya relatif terhadap `modules/apperp/management-aset/`.

| Path | Isinya |
| --- | --- |
| `src/Http/Controllers/MasterDataController.php` | Base controller — hak akses per resource, batas tenant, idempotency, penerbitan nomor, validasi induk, penjagaan arsip |
| `src/Http/Controllers/HalamanModulController.php` | Satu-satunya penyaji layar; membaca daftar menu dari manifest |
| `src/Http/Controllers/master/` | Controller khusus tiap master |
| `src/Models/master/` | Model master |
| `ui/Pages/` | Halaman React, ikut build shell Core |
| `tests/Feature/` | Test feature, dijalankan bersama test Core |
| `loadtest/` | Stack load test |

Test feature menjaga hal yang tidak boleh regresi: induk lintas tenant tertolak, daftar tidak pernah memuat baris tenant lain, hak satu master tidak merembet ke master lain, induk beranak yang belum diarsipkan tidak dapat diarsipkan, dan `kode` selalu berasal dari Core.

## Status terhadap gate

| Gate | Status | Bukti |
| --- | --- | --- |
| Gate penemuan | ✅ Lewat | Modul terdaftar di katalog dengan manifest lengkap |
| Migration PostgreSQL | ✅ Ada | `php artisan module:migrate management-aset` |
| Penjaga batas | ✅ Hijau | `apps/core/tests/Feature/Boundary/`, ikut tiap `php artisan test` |
| Test feature | ✅ Ada | `tests/Feature/`, berjalan pada PostgreSQL bersama test Core |
| **Gate concurrency** | ✅ Lewat | 1000 VU pada 128 tenant, empat instance runtime Core di belakang nginx, PostgreSQL asli — diukur ulang 10 September 2026 pada runtime satu proses |
| Scope organisasi | ⏳ Belum | Baru menerapkan batas tenant dan permission; scope organisasi menunggu contract Core |
| Upgrade release | ⏳ Belum tersedia | Versi `0.1.0`; menaikkan versi butuh compatibility matrix, backup, dan rollback terverifikasi |

**Hasil load test pada 1000 VU (10 September 2026):** nol pelanggaran lintas tenant, nol nomor ganda dari 63.861 nomor terbit, nol eskalasi hak dari 73 probe, nol error 5xx aplikasi. Skenario perlombaan tautan: nol himpunan gabungan dari 3.920 pembacaan balik. SLO latensi terpenuhi sampai 12 request serentak pada laptop 12 core; di atas itu yang bertambah antrean, bukan hasil.

Pengukuran ulang ini mengubah jawaban atas pertanyaan **apa yang jenuh lebih dulu**: sekarang CPU PHP, bukan database. Dengan PgBouncer session pooling, 1000 pengguna serentak hanya memakai 39 koneksi PostgreSQL, sementara keempat instance API menghabiskan hampir seluruh 12 vCPU mesin.

::: warning Dua permukaan belum ikut diukur
Skenario penyusutan dan work order belum dipindahkan ke harness baru; keduanya berhenti dengan galat
bila dijalankan, bukan hijau diam-diam. Kedua permukaan itu berstatus belum terverifikasi di bawah
beban. Lihat [Pengujian](/apps/management-aset/arsitektur/pengujian).
:::

## Menjalankan

Bagian dari stack lokal. Dari folder orkestrasi:

```powershell
.\start.ps1 -Build
```

Modul tidak punya alamat sendiri. Layarnya dibuka lewat shell Core di `http://localhost:8000` pada
jalur `/management-aset/<id entri menu>`, setelah modul dipasang untuk tenant yang sedang dibuka.
Datanya ada di database Core, pada tabel berawalan `aset_`.

### Setup terhadap Core

1. Daftarkan manifest dengan `app:register-manifest management-aset`. Kirim ulang registrasi setiap kali permission, duty, atau reference nomor bertambah.
2. Jalankan `php artisan module:migrate management-aset`.
3. Pasang modul untuk tenant dengan `php artisan module:install management-aset <id tenant>`.
4. Aktifkan semua reference nomor pada **Nomor dokumen** di Control Plane. Reference yang belum aktif membuat pembuatan record gagal dengan pesan yang menyebut sebabnya, bukan diam-diam memakai nomor buatan sendiri.

Tidak ada lagi service credential, `COREERP_SERVICE_TOKEN`, maupun kunci penandatangan bersama untuk
disamakan. Ketiganya milik app yang berjalan sebagai proses terpisah.

## Dokumen terkait

**Di dalam folder modul** — `README.md` (master data, workflow, struktur kode), `loadtest/README.md` (cara menjalankan, hasil terukur, batas kejujurannya).

**Aturan platform yang berlaku:**

- [Standar module](/dev/02-module-standard) — kontrak app
- [Identity dan access](/dev/09-identity-and-access) — permission dan scope
- [Number sequence](/dev/14-number-sequences) — penerbitan `kode`
- [Load dan concurrency testing](/dev/20-load-and-concurrency-testing) — gate yang sudah dilewati app ini
- [Backlog app management aset](/todo/general/06-app-management-aset) — temuan audit yang menunggu review

## Lihat juga

- [Katalog app](/apps/)
- [Human Resources](/apps/human-resources/) — modul bisnis kedua
- [Membangun modul baru](/apps/membangun-app-baru) — jalur yang dilewati modul ini
