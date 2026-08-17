# Register aset

Halaman ini untuk developer. Isinya bukan cara memakai layar, melainkan cara kerja register aset di dalam: data apa yang disimpan, aturan mana yang dijaga kode, dan kenapa aturannya begitu.

Register aset adalah **catatan satu barang milik perusahaan**, sejak diterima sampai dilepas. Satu baris di sini mewakili satu benda nyata: satu mesin, satu mobil, satu laptop. Bukan stok, bukan jumlah — kalau perusahaan punya sepuluh laptop yang sama, ada sepuluh baris.

## Dua sumbu klasifikasi, dan kenapa keduanya wajib

Ini konsep paling penting di modul ini. Setiap aset menunjuk **dua** master sekaligus, dan keduanya tidak saling menyaring:

| Sumbu | Kolom | Menentukan |
| --- | --- | --- |
| **Group aset** | `group_aset_id` | Perlakuan uang: buku penyusutan, kelompok harta fiskal, pembebanan |
| **Jenis aset** | `jenis_aset_id` | Perlakuan teknis: atribut apa yang harus diisi, pekerjaan maintenance apa yang berlaku |

Dua mobil bisa berada di group yang sama (disusutkan dengan cara yang sama) tetapi jenis yang berbeda (yang satu butuh catatan kilometer, yang lain tidak). Sebaliknya juga bisa.

Karena itu **memilih group tidak mempersempit pilihan jenis**, dan sebaliknya. Kalau Anda melihat kode yang menyaring salah satu berdasarkan yang lain, itu bug.

Dulu keduanya tersusun sebagai rantai `group → kategori → jenis`. Susunan itu sudah dibongkar mengikuti model Dynamics 365 F&O; sekarang keduanya ditunjuk langsung dari aset, tanpa perantara.

## Perjalanan hidup satu aset

Kolomnya `lifecycle_state`. Hanya empat nilai, dan hanya kode tertentu yang boleh mengubahnya:

| Status | Artinya | Diubah oleh |
| --- | --- | --- |
| `received` | Sudah tercatat, belum ditempatkan | Otomatis saat aset dibuat |
| `in_use` | Sudah ditempatkan di unit kerja dan dipakai | `POST /aset/{id}/penempatan` |
| `decommissioned` | Disetujui untuk dihentikan pemakaiannya | Persetujuan workflow dari Core |
| `disposed` | Sudah dijual atau dimusnahkan | Dokumen penjualan / pemusnahan |

Arahnya satu jalan. Yang perlu diingat saat menulis kode baru:

- Aset `decommissioned` atau `disposed` **tidak bisa dimutasi** lagi.
- Aset `disposed` **tidak bisa diubah** sama sekali.
- Aset baru bisa dijual atau dimusnahkan **setelah** statusnya `decommissioned`. Jadi persetujuan tidak bisa dilangkahi.

Status `decommissioned` tidak diputuskan app ini. Ia datang dari keputusan workflow milik Core lewat event bertanda tangan. Lihat [Identity dan access](/dev/09-identity-and-access) untuk cara token dan tanda tangan itu bekerja.

## Data yang disimpan

Tabel utamanya `t_aset`. Model PHP-nya `Asset`, tetapi `protected $table` menunjuk `tr_penerimaan_aset` — nama itu peninggalan penggantian nama tabel dan mudah membingungkan waktu mencari.

Kolom yang perlu Anda kenali:

| Kolom | Isi |
| --- | --- |
| `kode` | Nomor aset. Diterbitkan Core, tidak pernah dibuat app ini |
| `legal_entity_id` | Badan hukum pemilik. Menentukan tahun buku dan penyusutan |
| `responsible_org_unit_id` | Unit kerja yang bertanggung jawab sekarang. Ikut berubah saat mutasi |
| `financial_dimension_org_unit_id` | Unit yang menanggung biayanya. Diambil dari lokasi kalau lokasinya dipetakan, kalau tidak ikut unit pemakai |
| `kelompok_harta_fiskal_id` | Kelompok pajak. **Disalin** dari group saat penerimaan |
| `parent_asset_id` | Induk, kalau aset ini komponen dari aset lain |
| `acquired_on` | Tanggal diterima |
| `placed_in_service_on` | Tanggal mulai dipakai. Ini yang jadi dasar hitungan penyusutan |
| `acquisition_value` | Nilai perolehan |

Tabel pendukung:

| Tabel | Isi |
| --- | --- |
| `tr_penempatan_aset` | Riwayat penempatan. Satu baris per perpindahan, tidak pernah ditimpa |
| `tr_aset_atribut` | Nilai atribut milik aset ini |
| `tr_buku_aset` | Buku penyusutan yang dibentuk saat aset dibuat |

### Kenapa ada yang disalin, bukan ditunjuk

`kelompok_harta_fiskal_id` dan lokasi awal **disalin** dari group saat aset dibuat, bukan dibaca ulang setiap kali.

Alasannya: kalau nanti admin mengubah pilihan fiskal pada group, aset yang sudah aktif tidak boleh ikut berubah. Penyusutannya sudah berjalan dengan aturan yang lama, dan mengubahnya diam-diam akan membuat riwayat pajaknya tidak cocok lagi. Jadi group hanya mengisi kekosongan di awal; setelah itu nilainya milik aset.

Pola yang sama berlaku untuk lokasi. Mengubah lokasi bawaan group tidak memindahkan aset mana pun.

## Endpoint

Semuanya di bawah `/api/v1`:

| Endpoint | Gunanya |
| --- | --- |
| `GET /aset` | Daftar. Bisa dicari lewat `?q=` yang mencocokkan kode atau nomor seri |
| `POST /aset` | Menerima aset baru |
| `GET /aset/{id}` | Detail, lengkap dengan nilai atributnya |
| `PATCH /aset/{id}` | Koreksi data |
| `GET /aset/{id}/history` | Riwayat penempatan |
| `POST /aset/{id}/penempatan` | Mutasi ke unit kerja atau lokasi lain |

`POST /aset` **wajib** membawa header `Idempotency-Key`. Kalau kunci yang sama dikirim dua kali, yang kedua mengembalikan aset yang sama dengan header `Idempotent-Replayed: true` — bukan aset baru. Ini yang menjaga nomor aset tidak terbuang saat jaringan putus dan klien mengulang.

Bentuk lengkapnya ada di `contracts/openapi.yaml` pada repo app.

## Hak akses

| Permission | Untuk |
| --- | --- |
| `management-aset.aset.read` | Melihat daftar dan detail |
| `management-aset.aset.create` | Menerima aset baru |
| `management-aset.aset.update` | Mengoreksi data |
| `management-aset.aset.mutate` | Memindahkan ke unit atau lokasi lain |

Empat hak yang terpisah, bukan satu hak "kelola aset". Orang yang boleh mencatat penerimaan belum tentu boleh memindahkan, dan yang boleh memindahkan belum tentu boleh mengoreksi nilai perolehan.

## Batas tanggung jawab organisasi

Punya permission belum cukup. Setiap pembacaan dan penulisan aset masih disaring lagi lewat `OrganizationScope`, memakai kebijakan `management-aset.asset-responsibility`.

Cara kerjanya: Core mengirim daftar badan hukum dan unit kerja yang boleh diakses pengguna, di dalam token konteks yang sudah ditandatangani. App menyaring kueri berdasarkan itu. Jadi dua orang dengan permission yang sama persis tetap bisa melihat daftar aset yang berbeda.

Yang penting untuk diingat saat menulis endpoint baru: **jangan pernah percaya id organisasi yang datang dari browser**. Pilihan di layar hanya untuk mengisi nilai awal, bukan bukti hak akses.

## Aturan yang dijaga, dan alasannya

Bagian ini yang paling sering ditanyakan. Semuanya ada di `AssetController`.

**Group tidak bisa diganti setelah aset dibuat.** Buku penyusutan sudah terbentuk dari matriks group × buku. Mengganti group berarti bukunya salah, tanpa ada yang memberi tahu. Permintaan yang mencoba mengubahnya ditolak dengan pesan yang menjelaskan alasannya — bukan diabaikan diam-diam, supaya pengguna tidak mengira group sudah berganti.

**Nilai perolehan tidak bisa diubah setelah ada periode penyusutan.** Periode yang sudah jalan dihitung dari nilai itu. Kalau memang harus diubah, periodenya dibalik dulu.

**Tanggal mulai dipakai hanya bisa digeser kalau belum ada penyusutan.** Alasannya sama.

**Mengganti jenis aset menghapus semua nilai atributnya.** Atribut milik jenis lama tidak berlaku untuk jenis baru, jadi nilainya wajib dikirim ulang. Kalau tidak dikirim, atributnya kosong — itu disengaja, bukan kehilangan data.

**Aset tidak bisa jadi induk dirinya sendiri.** Diperiksa langsung.

**Model harus cocok dengan jenis dan pabrikannya.** Kalau sebuah jenis aset sudah punya daftar model, hanya model dari daftar itu yang boleh dipilih. Kalau jenisnya belum punya daftar, model apa pun boleh. Pabrikan pada model harus sama dengan pabrikan pada aset.

**Aset tidak bisa ditempatkan kalau buku penyusutannya belum lengkap.** Saat mutasi pertama, kode memeriksa apakah matriks group × buku sudah terisi dan profilnya bisa dihitung. Kalau belum, mutasi ditolak dengan pesan yang menunjuk matriksnya. Ini sengaja: aset yang sudah `in_use` tetapi bukunya belum benar akan menghasilkan penyusutan yang salah diam-diam.

**Koreksi mengunci baris asetnya.** Satu aset bisa dikoreksi dari beberapa instance API sekaligus; tanpa kunci, penggantian baris atribut bisa saling menyelip di antara hapus dan sisip.

## Atribut per jenis aset

Kolom aset sudah tetap, tapi tiap perusahaan punya data tambahan yang berbeda. Itu ditampung lewat atribut.

Susunannya empat tabel:

| Tabel | Isi |
| --- | --- |
| `m_tipe_atribut` | Daftar jenis data yang bisa dicatat, misalnya "Kapasitas mesin". Punya tipe data dan satuan |
| `m_tipe_atribut_nilai` | Pilihan nilai, kalau tipenya berupa pilihan |
| `m_jenis_aset_atribut` | Atribut mana yang berlaku untuk jenis aset mana, dan mana yang wajib |
| `tr_aset_atribut` | Nilai sebenarnya milik satu aset |

Nilainya disimpan di kolom terpisah menurut tipenya (`nilai_text`, `nilai_number`, `nilai_boolean`, `nilai_date`), bukan satu kolom teks untuk semua. Jadi angka bisa dibandingkan sebagai angka dan tanggal bisa diurutkan sebagai tanggal.

Bentuk kiriman divalidasi di `AssetController::rules()`, tetapi isinya divalidasi terhadap definisi milik jenis aset — dan itu hanya diketahui saat berjalan. Kodenya di `AssetAttributeValidator`.

## Yang datang dari Core

App ini tidak membuat sendiri tiga hal berikut:

| Hal | Dari mana | Catatan |
| --- | --- | --- |
| Nomor aset (`kode`) | Number Sequence Core | Diminta dengan `legal_entity_id`, karena penomorannya bisa direset per tahun buku |
| Tahun buku | Fiscal calendar Core | Dipakai untuk menentukan periode penyusutan |
| Hak akses dan batas organisasi | Token konteks bertanda tangan | Tidak pernah dibaca dari database Core langsung |

App tidak pernah menyentuh database Core. Semua lewat API atau token. Lihat [Number sequence](/dev/14-number-sequences) dan [API dan integrasi](/dev/04-api-and-integration).

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Http/Controllers/transaksi/InventarisasiAset/AssetController.php` | Seluruh logika register aset |
| `api/app/Models/transaksi/InventarisasiAset/Asset.php` | Model |
| `api/app/Support/OrganizationScope.php` | Penyaringan berdasarkan tanggung jawab organisasi |
| `api/app/Support/AssetAttributeValidator.php` | Validasi nilai atribut |
| `api/app/Services/NumberSequenceClient.php` | Permintaan nomor ke Core |
| `ui/src/transactions/inventarisasi-aset/AssetPage.tsx` | Layar register aset |
| `database/migrations/2026_07_28_090000_create_asset_register_and_depreciation_tables.php` | Tabel `t_aset` dan tabel penyusutan |
| `database/migrations/2026_08_07_130000_create_asset_attribute_tables.php` | Tabel atribut |
| `contracts/openapi.yaml` | Kontrak endpoint |

## Kalau Anda menambah sesuatu di sini

1. Endpoint baru wajib masuk `contracts/src/paths/aset.yaml`, lalu jalankan `python contracts/bundle.py`. Ada pemeriksa di CI yang menolak rute tanpa kontrak.
2. Kueri baru wajib lewat `OrganizationScope`, bukan hanya `where('tenant_id')`.
3. Aksi baru butuh permission sendiri di `app.yaml`, bukan menumpang permission yang sudah ada.
4. Kalau menambah endpoint yang mengganti sekumpulan baris sekaligus, kunci baris pemiliknya dulu. Alasannya ada di [gate concurrency](/dev/20-load-and-concurrency-testing).

## Lihat juga

- [Management Aset](/apps/management-aset/) — gambaran modul dan cara menjalankannya
- [Standar module](/dev/02-module-standard) — kontrak yang harus dipenuhi setiap app
- [Identity dan access](/dev/09-identity-and-access) — permission, duty, dan scope organisasi
- [Number sequence](/dev/14-number-sequences) — cara nomor diterbitkan
- [Load dan concurrency testing](/dev/20-load-and-concurrency-testing) — gate sebelum modul disebut selesai
