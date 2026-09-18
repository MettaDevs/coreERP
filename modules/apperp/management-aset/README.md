# App ERP Management Aset

## Contoh alur dekomisioning

Pengguna membuat dokumen **Dekomisioning aset** untuk aset yang masih aktif. Aplikasi meminta persetujuan CoreERP. Setelah keputusan `approved` diterima melalui event bertanda tangan `core.workflow.decision.v2`, dokumen disetujui dan aset menjadi tidak aktif (`decommissioned`). Baru setelah itu aset boleh dijual atau dimusnahkan. Nomor dokumen memakai referensi `management-aset.dekomisioning-aset` (prefix `DKMA`).

Management Aset adalah app bisnis mandiri dengan API, UI, database, migration, dan contract sendiri.

## Master data

Dua belas master tersedia pada `GET /api/v1/{resource}`. Semuanya memakai bentuk yang sama: `kode` (diterbitkan Number Sequence Core, read-only), `nama`, dan `keterangan`, ditambah penanda `aktif`. Data selalu dibatasi oleh tenant pada token konteks yang ditandatangani Core.

Rancangan pemisahan data per organisasi untuk datatable dan transaksi ada di [docs/rancangan-scope-data-aset.md](docs/rancangan-scope-data-aset.md). Saat ini aplikasi baru menerapkan batas tenant dan permission; scope organisasi masih menunggu contract CoreERP.

| No. | Master | Resource | Tabel | Induk |
| --- | --- | --- | --- | --- |
| 1 | Group aset | `group-aset` | `m_group_aset` | — |
| 2 | Jenis aset | `jenis-aset` | `m_jenis_aset` | — |
| 3 | Model aset | `model-aset` | `m_model_aset` | pabrikan aset (wajib), jenis aset (opsional) |
| 4 | Kondisi aset | `kondisi-aset` | `m_kondisi_aset` | — |
| 5 | Pabrikan dan model | `pabrikan-aset` | `m_pabrikan_aset` | Model dikelola pada grid pabrikan di layar gabungan |
| 6 | Tipe lokasi aset | `tipe-lokasi-aset` | `m_tipe_lokasi_aset` | — |
| 7 | Lokasi aset | `lokasi-aset` | `m_lokasi_aset` | lokasi aset (opsional, menunjuk dirinya sendiri), tipe lokasi (opsional) |
| 8 | Item checklist maintenance | `item-checklist-maintenance` | `m_item_checklist_maintenance` | — |
| 9 | Analisa maintenance | `analisa-maintenance` | `m_analisa_maintenance` | — |
| 10 | Profil penyusutan | `profil-penyusutan` | `m_profil_penyusutan` | — |
| 11 | Buku penyusutan | `buku-penyusutan` | `m_buku_penyusutan` | — |
| 12 | Tipe atribut | `tipe-atribut` | `m_tipe_atribut` | — |

### Setup maintenance v1

Setup maintenance mengikuti pola dua-pane master-detail Dynamics 365 F&O. Layar yang
tersedia adalah **Jenis pekerjaan maintenance**, **Default jenis pekerjaan maintenance**,
**Variabel checklist**, dan **Template checklist**. Dari detail jenis pekerjaan, pengguna
dapat mengatur varian, skill/sertifikat, serta relasinya dengan jenis aset; dari detail
variabel dan template, pengguna dapat mengatur pilihan dan baris checklist.

Baris template dapat berupa header, teks, pengukuran, variabel, atau template lain. Untuk
pengukuran, satuan dipilih dari CoreERP; batas minimum dan maksimum opsional ikut disalin ke
work order dan menghasilkan gagal bila angka yang diisi berada di luar rentang.

Transaksi maintenance seperti maintenance request, work order, maintenance plan,
scheduling, dan fault belum termasuk dalam versi ini. Tombol Forecast, Tools, dan Work
description juga belum ditampilkan karena model bisnisnya belum tersedia.

Tenant baru menerima seed starter Indonesia secara otomatis. Untuk tenant yang sudah ada,
jalankan perintah berikut dari container API tanpa mengganti data custom yang sudah dibuat:

```text
php artisan management-aset:seed-maintenance --tenant=<tenant_id> --template-key=id:maintenance:starter:v1
```

Starter tidak mengisi **Sebab kerusakan** dan **Tindakan perbaikan** karena keduanya
merupakan kosakata operasional milik tenant. Pada kedua master tersebut, aktifkan
**Minta keterangan saat dipilih** untuk pilihan seperti **Lainnya**. Saat pilihan itu
dipakai, mekanik wajib menuliskan rincian pada baris pekerjaan.

Starter juga menyediakan katalog awal Indonesia–Asia untuk layar **Pabrikan dan model**:
68 pabrikan dan 209 model/seri yang umum dipakai pada kendaraan, alat berat, forklift,
otomasi, mesin, TI, HVAC, pompa, laboratorium, perkantoran, pertanian, dan energi surya.
Katalog ini memakai sub-template `id:manufacturer-models:indonesia-asia:v1`, bersifat
idempoten melalui `creation_key`, dan tidak mengisi `jenis_aset_id` atau `model_number`.
Gunakan sebagai data awal development; setelah model dipakai aset, arsipkan melalui API
sesuai aturan referensi yang berlaku.

## Siklus hidup register aset

Aset dibaca lengkap beserta nilai atributnya lewat `GET /api/v1/aset/{id}`, dan dikoreksi lewat `PATCH /api/v1/aset/{id}` (permission `management-aset.aset.update`). Dua hal sengaja tidak dapat diubah di sana:

- **Group aset**, karena buku penyusutan sudah dibentuk dari matriks group pada saat aset diterima. Menggantinya membuat buku yang berjalan tidak lagi cocok dengan groupnya.
- **Nilai perolehan dan residu**, begitu buku aset sudah punya periode penyusutan. Balikkan periodenya lebih dahulu.

Mengubah `placed_in_service_on` menghitung ulang `depreciation_start_on` tiap buku sesuai konvensinya masing-masing, tetapi hanya selama belum ada periode berjalan.

Membuat dokumen **Penjualan aset** atau **Pemusnahan aset** melepas asetnya: `lifecycle_state` menjadi `disposed` dan seluruh buku asetnya ditutup (`status = closed`, `closed_on` diisi tanggal dokumen). Buku yang tertutup tidak lagi menerima proposal penyusutan. Ini murni subledger; tidak ada jurnal yang dibuat.

## Mutasi aset

Mutasi adalah **dokumen**, bukan aksi pada satu aset: satu berita acara serah terima
memuat beberapa aset yang berpindah bersama, karena memang begitu barang berpindah tangan
di lapangan. Nomornya memakai reference `management-aset.mutasi-aset` (prefix `MUTA`) per
badan hukum.

Alurnya dua langkah. `POST /api/v1/mutasi-aset` membuat **draf** — nomor terbit, tetapi
tidak satu aset pun berpindah. `POST /api/v1/mutasi-aset/{id}/selesaikan` yang benar-benar
memindahkannya: untuk tiap baris ia membekukan keadaan asal, menambah satu baris riwayat
penempatan yang menyebut dokumennya, lalu memperbarui lokasi, unit penanggung jawab, dan
dimensi keuangan asetnya. Dokumen yang sudah selesai tidak dapat disunting maupun
diarsipkan; koreksi dikerjakan dengan mutasi balik, bukan dengan menyunting bukti.

Menyusun dokumen dan memindahkan aset adalah dua wewenang terpisah. Yang pertama
`management-aset.mutasi-aset.{read,create,update,archive}`; yang kedua
`management-aset.aset.mutate`. Juru tulis boleh menyiapkan berkasnya tanpa berwenang
menyelesaikan serah terimanya.

### Apa yang dipindahkan, dan apa yang tidak

| Sumbu | Padanan Dynamics 365 | Di modul ini |
| --- | --- | --- |
| Fisik dan tanggung jawab | `Install asset at location` (Asset Management) | Dibangun |
| Dimensi keuangan per buku | `Transfer fixed assets` (Fixed assets) | Tidak dibangun |

Keduanya terpisah di F&O karena yang kedua **menerbitkan jurnal**: ia memindahkan saldo
antar akun, dan karena tiap buku memposting ke lapisan sendiri, tiap buku memerlukan
dimensinya sendiri. Modul ini tidak menjurnal sama sekali, sehingga dimensi per buku tidak
memiliki arti di sini. Ketika Finance mulai menjurnal dari export penyusutan, di situlah ia
menempel — satu tabel dimensi per buku aset, diisi dokumen ini, tanpa membongkar yang sudah
ada. `financial_dimension_org_unit_id` tingkat aset tetap diperbarui: ia label pembebanan
yang sudah dipelihara jalur penerimaan sejak awal, dan membiarkannya basi membuat data
lebih salah, bukan lebih sedikit.

`lifecycle_state` aset sengaja **tidak** disentuh. Di F&O, memasang aset pada functional
location dan mengubah lifecycle state adalah dua tindakan terpisah pada action pane yang
sama; menggabungkannya membuat aset yang dimutasi ke gudang penyimpanan ikut berstatus
dipakai.

### Nama, bukan ID

Tidak ada layar maupun dokumen cetak yang menampilkan ULID unit kerja atau ULID pengguna.
Jawaban API memulangkan id **dan** namanya berpasangan — `tujuan_org_unit_nama`,
`diserahkan_oleh_nama`, `diterima_oleh_nama`, serta `asal_org_unit_nama` dan
`asal_custodian_nama` pada baris — dan form memakai dropdown berisi nama, bukan kotak ketik.
Daftarnya dibaca lewat `GET /api/v1/reference-data/unit-kerja` dan
`GET /api/v1/reference-data/anggota`, yang keduanya berdiri di atas kontrak Core
`DirektoriOrganisasi`; modul tidak pernah menyentuh database Core.

Nama diterjemahkan **saat dibaca**, bukan dibekukan sebagai snapshot. Nama orang dan nama
unit berubah karena sebab yang tidak ada hubungannya dengan aset — pernikahan, reorganisasi,
pembetulan ejaan — dan dokumen yang membekukan nama akan menampilkan ejaan lama selamanya
tanpa ada yang dapat membetulkannya. Nama bernilai `null` berarti unit atau keanggotaannya
sudah tidak ada di Core; layar menampilkan idnya sebagai jalan terakhir supaya dokumen lama
tetap dapat ditelusuri.

### Lokasi asal tidak diketik

Asal diturunkan dari asetnya, sama seperti dialog `Install asset at location` yang mengisi
field `Functional location` sendiri begitu asetnya dipilih. Selama dokumen masih draf,
jawaban API menyebut keadaan aset **sekarang**; sesudah diselesaikan, nilai yang dibekukan
yang menang. Klien tidak perlu memilih di antara keduanya dan tidak boleh mengirimkannya
kembali.

Dua laporan mengikuti dokumen ini: `management-aset.berita-acara-serah-terima` (Word, satu
dokumen, hanya untuk mutasi yang sudah selesai) dan `management-aset.daftar-mutasi-aset`
(Excel, satu baris per aset yang berpindah).

## Penyusutan massal

`POST /api/v1/penyusutan/proposal-massal` menghitung satu periode untuk seluruh buku aset aktif sekaligus, dengan penyaring opsional `group_aset_id` dan `buku_id`. Padanannya di Dynamics 365 F&O adalah *Create depreciation proposal*.

Buku yang tidak dapat diusulkan dilewati beserta alasannya (`sudah_ada`, `belum_mulai_menyusut`, `sudah_habis`, `tanpa_unit_penggunaan`), bukan menggagalkan seluruh proses — satu aset yang belum lengkap tidak boleh menahan ratusan lainnya. Metode `consumption` tidak ikut karena angka pemakaiannya berbeda tiap aset dan hanya diketahui per aset.

### Field di luar bentuk dasar

Sebagian master membawa kolom sendiri di luar `kode`/`nama`/`keterangan`/`aktif`. Kolom itu dideklarasikan sekali per master lewat `extraRules()`/`extraPayload()`/`extraPresent()` pada controller, dan dirender di UI lewat `extraFields` pada `ui/src/master/masters.ts`. Tidak ada halaman bespoke per master.

**Group aset** membawa perlakuan finansial, mengikuti "Fixed asset group" F&O:

| Field | Arti |
| --- | --- |
| `kelompok_harta_fiskal_id` | Referensi aturan fiskal berversi; aset menyimpan snapshot ID saat diterima |
| `property_type` | Masuk neraca atau tidak: aset tetap, barang inventaris, atau lainnya. Padanan `Property type` di F&O |
| `lokasi_aset_id` | Lokasi bawaan saat aset diterima; hanya nilai awal, tidak pernah dibaca ulang |
| `capitalization_threshold` | Di bawah nilai ini aset tetap dicatat, tetapi bukunya tidak menyusut |

Sifat harta — berwujud, tidak berwujud, hak guna — sengaja tidak disimpan pada group.
Klasifikasi itu menentukan akun, dan akun ditentukan posting profile milik Finance, bukan
modul ini; ia juga konstan per group sehingga tidak pernah memisahkan apa pun yang belum
dipisahkan oleh group itu sendiri. Aset yang perlu dibedakan sifatnya dibedakan dengan
membuat group tersendiri. Kolom lama `major_type` sudah dibuang dan field bernama itu
ditolak oleh API.

**Profil penyusutan** membawa aturan penyusutannya sendiri (`method`, `frequency`, `year_basis`, `convention`, `useful_life_periods`, `rate_percent`, `manual_schedule`). Field mana yang wajib bergantung pada `method`, dan divalidasi sebagai aturan per field sehingga klien menerima pesan yang tepat sasaran.

### Klasifikasi aset

Klasifikasi mengikuti model Dynamics 365 F&O: **master klasifikasi datar dan saling lepas**, dan aset menunjuk masing-masing secara langsung.

```text
                ┌─ group aset   (sumbu FINANSIAL: penyusutan, GL, penomoran)
                │
        ASET ───┼─ jenis aset   (sumbu TEKNIS: maintenance, atribut)
                │
                └─ model aset   (katalog per pabrikan, opsional)
```

Kedua sumbu wajib dan sejajar; tidak ada yang menyaring pilihan yang lain, dan tidak ada tingkat perantara yang wajib diisi. Karena itu tenant yang hanya mengenal satu tingkat klasifikasi tetap terlayani, sementara tenant yang butuh pembedaan lebih rinci menambahkannya sebagai atribut, bukan sebagai sub-kategori baru.

Tipe atribut mengikuti model Dynamics 365 Asset Management: tipe dasar (`string`, `decimal`, `integer`, `date`, `boolean`) terpisah dari batasan yang dapat berubah. Teks tanpa Values menjadi isian bebas, sedangkan teks dengan Values menjadi dropdown. Desimal dan bilangan bulat tanpa min/max menerima angka bebas; bila kedua batas diisi, nilainya harus berada dalam rentang. Mapping Data type/Values bersifat langsung terhadap Dynamics, sementara range angka merupakan penyesuaian dengan min/max yang sudah dimiliki aplikasi ini. Setelah sebuah atribut pernah diisi pada aset, tipe dasarnya terkunci permanen agar nilai lama tetap dapat dibaca dengan benar.

Yang hierarkis hanyalah **data**, bukan skema: `m_lokasi_aset.parent_id` dan `tr_aset.induk_aset_id` menunjuk dirinya sendiri sedalam yang dibutuhkan tenant. Keduanya **struktur domain milik Management Aset**, bukan organization hierarchy CoreERP; aturan "jangan menyimpan `parent_id` permanen" pada `docs/dev/01a-tenant-and-org-hierarchy.md` berlaku untuk identitas organization di Core, bukan untuk struktur seperti ini.

### Lokasi dan dimensi keuangan

Pohon lokasi sengaja **terpisah** dari struktur organisasi. "Di mana benda ini berada" dan "siapa yang bertanggung jawab" adalah dua pertanyaan berbeda yang berubah karena sebab berbeda: reorganisasi tidak memindahkan barang, dan memindahkan barang tidak mengubah struktur organisasi. Menyatukan keduanya membuat riwayat lokasi rusak setiap kali unit kerja digabung, dan membatasi kedalaman lokasi pada unit organisasi terkecil — padahal stock opname butuh sampai tingkat ruangan atau rak.

Keduanya dihubungkan lewat satu field opsional, `m_lokasi_aset.org_unit_id`; padanan toggle **Update asset dimension** pada Functional location type di F&O. Saat aset diterima atau dimutasi, `financial_dimension_org_unit_id` pada aset diisi dari unit milik lokasinya, dan jatuh kembali ke unit pengguna bila lokasi tidak dipetakan. Nilainya **disalin, bukan dilihat saat dibaca**: mengubah pemetaan lokasi kelak tidak menulis ulang pembebanan aset yang sudah berjalan.

Aturan yang berlaku pada master berinduk:

- Anak wajib menyebut induk wajibnya saat dibuat; induk dapat diganti lewat `PATCH`. Master dengan lebih dari satu induk memperlakukan tiap induk secara terpisah — memindahkan satu tidak menggeser yang lain.
- Induk wajib berada pada tenant yang sama dan belum diarsipkan. Selain divalidasi aplikasi, database menegakkannya lewat foreign key gabungan `(tenant_id, <induk>_id)` → `(tenant_id, id)`, sehingga induk lintas tenant tidak mungkin tersimpan.
- Induk tidak dapat diarsipkan selama masih dipakai anak yang belum diarsipkan; API menjawab `409` dengan kode `referenced_by_children`. Penanda `aktif` tidak memengaruhi aturan ini — yang dijaga adalah referensi yang masih hidup, bukan status pakainya.
- Daftar anak dapat disaring dengan `?<induk>_id=<ULID>`, dan setiap record anak menyertakan ringkasan induk (`id`, `kode`, `nama`) agar UI tidak perlu permintaan tambahan. Induk yang sudah diarsipkan tetap disertakan supaya asal data tidak hilang.

### Hak akses

Setiap master memiliki empat permission dan satu duty tersendiri, sehingga satu master dapat dikelola tanpa ikut memberi hak pada master sebelahnya:

- `management-aset.<resource>.read`
- `management-aset.<resource>.create`
- `management-aset.<resource>.update`
- `management-aset.<resource>.archive`

Duty `management-aset.<resource>.manage` menggabungkan keempatnya. Role tenant menyusun duty tersebut sesuai jabatan lokal; tidak ada duty gabungan lintas master.

Catatan praktis: memilih induk pada UI memerlukan hak **lihat** pada master induk. Pengguna yang hanya memegang `management-aset.model-aset.manage` tidak akan melihat menu pabrikan dan model, tetapi endpoint model tetap tersedia untuk grid dan integrasi yang berwenang. API sendiri tidak meminta hak baca induk — keberadaan induk diperiksa sebagai referential integrity, bukan sebagai otorisasi.

Arsip memakai soft delete agar record yang kelak direferensikan data turunan tidak hilang secara fisik.

### Penomoran

Setiap master memiliki reference Number Sequence sendiri. Master tenant memakai scope `tenant`, sedangkan nomor aset dan dokumen siklus memakai scope `legal_entity`. Aplikasi tidak menyimpan counter dan tidak menerima `kode` dari klien; nilai `kode` yang dikirim klien diabaikan.

Prefix di bawah adalah `default_prefix` pada `app.yaml`; `loadtest/verify.sql` memeriksa prefix yang sama supaya nomor yang tertukar antar reference langsung ketahuan.

| Reference | Prefix | Scope |
| --- | --- | --- |
| `management-aset.model-aset` | `MDLA` | `tenant` |
| `management-aset.group-aset` | `GRPA` | `tenant` |
| `management-aset.jenis-aset` | `JNSA` | `tenant` |
| `management-aset.kondisi-aset` | `KNDA` | `tenant` |
| `management-aset.pabrikan-aset` | `PBRA` | `tenant` |
| `management-aset.item-checklist-maintenance` | `ICMA` | `tenant` |
| `management-aset.analisa-maintenance` | `ANMA` | `tenant` |
| `management-aset.aset` | `ASTA` | `legal_entity` |
| `management-aset.tipe-lokasi-aset` | `TLKA` | `tenant` |
| `management-aset.lokasi-aset` | `LOCA` | `tenant` |
| `management-aset.tipe-atribut` | `TATR` | `tenant` |
| `management-aset.buku-penyusutan` | `BKPY` | `tenant` |
| `management-aset.profil-penyusutan` | `DPRE` | `tenant` |
| `management-aset.perencanaan-aset` | `PLNA` | `legal_entity` |
| `management-aset.permintaan-pembelian-aset` | `RPPA` | `legal_entity` |
| `management-aset.pemeliharaan-aset` | `PMHA` | `legal_entity` |
| `management-aset.mutasi-aset` | `MUTA` | `legal_entity` |
| `management-aset.dekomisioning-aset` | `DKMA` | `legal_entity` |
| `management-aset.penjualan-aset` | `PJLA` | `legal_entity` |
| `management-aset.pemusnahan-aset` | `PMSA` | `legal_entity` |
| `management-aset.maintenance-job-types` | `JPMA` | `tenant` |
| `management-aset.maintenance-job-type-variants` | `VJMA` | `tenant` |
| `management-aset.maintenance-job-type-defaults` | `DJMA` | `tenant` |
| `management-aset.maintenance-checklist-variables` | `VCMA` | `tenant` |
| `management-aset.maintenance-checklist-templates` | `TCMA` | `tenant` |

Format, status, dan counter adalah keputusan owner/admin tenant di Control Plane, bukan milik kode app. Materialisasi awal memakai profile non-continuous, tanpa mode manual, tanpa reset periode, preallocation 20, minimum 0, maksimum 19999, dan prefix + lima digit; owner/admin dapat mengubah pengaturan yang masih boleh diubah sebelum nomor digunakan.

`POST` wajib membawa header `Idempotency-Key`. Retry dengan kunci yang sama mengembalikan record yang sama beserta header `Idempotent-Replayed: true` dan tidak menerbitkan nomor kedua; kunci yang sama dengan isi berbeda dijawab `409 idempotency_conflict`.

## Perencanaan aset

Perencanaan memakai `tr_perencanaan_aset` sebagai header dan `tr_perencanaan_aset_details` sebagai rincian. Lookup memilih **jenis aset** dari `m_jenis_aset`; aset fisik belum ada pada tahap ini. Spesifikasi yang diperlukan disimpan pada `requested_specification` di setiap rincian sebagai snapshot transaksi, sehingga tidak ada master spesifikasi generik.

Rencana membawa entitas legal dan unit kerja dari konteks CoreERP yang aktif, nomor `PLNA` diterbitkan Core dengan scope entitas legal, dan hanya rencana berstatus draf yang dapat diubah atau diarsipkan. Hak aksesnya dipisah menjadi `read`, `create`, `update`, dan `archive` melalui entry point → permission → privilege → duty `management-aset.perencanaan-aset.manage`.

## Setup

1. Daftarkan `app.yaml` ke katalog dengan `php artisan app:register-manifest management-aset`. Registrasi perlu dikirim ulang setiap kali daftar permission, duty, atau reference nomor bertambah.
2. Pasang module untuk tenant dengan `php artisan module:install management-aset <tenant>` — atau biarkan pendaftaran usaha melakukannya. Di sanalah migration module dijalankan dan catatan pemasangannya dibuat.
3. Setelah module tercatat **terpasang**, Control Plane mematerialisasi seluruh reference pada **Nomor dokumen** dengan scope dari manifest. Verifikasi daftar dan preview di halaman tersebut; seed tenant baru baru menerbitkan nomor setelah tahap ini siap.

Halaman module ikut build shell Core dan menerima konteks tenant dari request Core yang sama; ia tidak menerima `tenant_id` dari browser dan tidak memakai token konteks.

Manifest masih berversi `0.1.0`. Upgrade memerlukan compatibility matrix, backup, serta rollback terverifikasi (lihat `docs/dev/13-publishing-an-app-release.md`). Selama module berada pada release pengembangan, jalankan migration baru dengan `module:migrate` dan jangan memperlakukannya sebagai upgrade produksi.

API health tersedia pada `GET /api/v1/health`. Contract lengkap berada di `contracts/openapi.yaml`, yang merupakan bundle hasil generate dari `contracts/src/`. Sunting sumbernya di `contracts/src/`, lalu jalankan `python contracts/bundle.py`; `--check` memverifikasi bundle masih sinkron.

## Workflow

Manifest mendaftarkan tipe workflow **Verifikasi usulan pemusnahan aset**.
Admin tenant memilih approver dan mengaktifkan versinya melalui pengaturan
Workflow di CoreERP. Tipe ini menandai proses yang dapat dikonfigurasi; pengajuan
dan keputusan verifikasi di aplikasi Aset akan ditambahkan bersama endpoint
approval, bukan disimpulkan hanya dari konfigurasi.

## Struktur kode

API memakai satu base controller `App\Http\Controllers\MasterDataController` yang memegang seluruh perilaku bersama: hak akses per resource, batas tenant, idempotency, penerbitan nomor, validasi induk, dan penjagaan arsip. Kode khusus master berada di `api/app/Http/Controllers/master/` dan `api/app/Models/master/`. UI master berada di `ui/src/master/`. Pola folder untuk fitur berikutnya tercatat pada `docs/agent.md`.

Test berada di `api/tests/Feature`. Selain CRUD, test menjaga hal yang tidak boleh regresi: induk lintas tenant tertolak, hak satu master tidak merembet ke master lain, induk beranak yang belum diarsipkan tidak dapat diarsipkan, dan `kode` selalu berasal dari Core.

## Load test

Test feature tidak cukup untuk menyatakan modul selesai. Ia menjalankan satu request pada satu proses, sehingga tidak dapat melihat koneksi database habis, nomor terbit dua kali, batas tenant yang bocor saat request saling menyela, atau idempotency key yang berlomba.

`loadtest/` berisi stack lengkap: empat instance API di belakang nginx, PostgreSQL asli, stub Number Sequence yang sekaligus mencatat setiap nomor, dan skenario k6 dengan 1000 virtual user pada 128 tenant. Cara menjalankan, hasil terukur, dan batas kejujurannya ada di [loadtest/README.md](loadtest/README.md).

Hasil pada 1000 VU: nol pelanggaran lintas tenant, nol nomor ganda dari 4.342 nomor terbit, nol eskalasi hak, dan nol error 5xx dari aplikasi. SLO latensi terpenuhi sampai 16 request serentak pada laptop 12 core; di atas itu yang bertambah adalah antrean, bukan hasil.

Load test ini juga yang menemukan bahwa penanganan koneksi database menjadi bottleneck jauh sebelum kode modul: tanpa koneksi persisten, PostgreSQL membakar 5,5 core hanya untuk fork proses baru setiap request. Karena itu tersedia `DB_PERSISTENT` pada `api/config/database.php`, default mati, dinyalakan pada deployment dengan worker proses tetap.
# Dekomisioning aset

Aset yang akan dijual atau dimusnahkan terlebih dahulu diajukan untuk dekomisioning. Setelah approver menyetujui di CoreERP, event keputusan mengubah aset menjadi `decommissioned`; baru setelah itu aplikasi menerima usulan penjualan atau pemusnahan. Nomor dokumen memakai reference `management-aset.dekomisioning-aset` dengan prefix `DKMA` per badan hukum.
