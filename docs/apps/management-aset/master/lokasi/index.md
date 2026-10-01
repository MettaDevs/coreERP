# Lokasi aset, alamat, dan dimensi keuangan

Halaman ini untuk developer. Perilaku dasar master ada di [Master data](/apps/management-aset/master/).

Lokasi menjawab **di mana barangnya berada**. Yang membuatnya lebih menarik daripada master biasa: lokasi juga ikut menentukan **ke mana biayanya dibebankan**.

## Dua master

| Master | Tabel | Isi |
| --- | --- | --- |
| `tipe-lokasi-aset` | `aset_m_tipe_lokasi_aset` | Golongan lokasi, misalnya gudang, kantor, area produksi |
| `lokasi-aset` | `aset_m_lokasi_aset` | Lokasi sebenarnya, boleh berinduk lokasi lain |

Lokasi bisa bersarang: gedung berisi lantai, lantai berisi ruang. Karena itu ia punya `parent_id` yang menunjuk tabelnya sendiri.

**Lingkaran ditolak.** A → B → A diperiksa saat penyimpanan. Tanpa itu, penelusuran induk akan berputar tanpa henti.

## Padanan di Business Central dan Dynamics 365

Bentuknya mengikuti [functional location D365](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/functional-locations/create-functional-locations): hierarki lewat `Parent`, **Address yang diwariskan** (sub-lokasi tanpa alamat memakai alamat lokasi induknya), dan financial dimensions yang turun ke aset yang dipasang di sana.

Tabel `FA Location` (5609) Business Central hanya berisi `Code` dan `Name`: datar, tanpa induk, tanpa alamat. BC menaruh `FA Location Code`, `Location Code`, `Responsible Employee`, dan dimensi langsung pada kartu `Fixed Asset`. Karena itu BC bukan rujukan bentuknya di sini.

| Field | Padanan | Catatan |
| --- | --- | --- |
| `parent_id` | Parent (D365) | Sama. BC tidak punya |
| `alamat_id` | Address FastTab (D365) | Menunjuk buku alamat Core, tidak menyimpan alamat sendiri |
| `org_unit_id` | Financial dimensions (D365) | Sudah ada sebelumnya; lihat bagian dimensi di bawah |
| `departemen_bawaan_id` | **Tidak ada padanan langsung** | D365 hanya punya FastTab Workers (orang, bukan unit kerja); BC tidak punya apa pun di lokasi |

Yang sengaja **tidak** diikuti dari D365:

- D365 menyalin perubahan Address induk ke sub-lokasi lewat dialog "update sub functional locations". Di sini warisan dihitung saat dibaca, jadi tidak ada salinan yang perlu disinkronkan dan tidak ada dialog.
- D365 menyimpan alamat sebagai alamat lokasi itu sendiri. Di sini alamat selalu menunjuk tempat yang sudah ada di buku alamat Core, karena alamat yang sama dipakai organisasi untuk kop dokumen; mengubahnya sekali berlaku di semua tempat.
- Site/Warehouse dan lifecycle state functional location belum ada. Belum ada fitur modul ini yang membacanya.

## Alamat dari buku alamat Core

`alamat_id` menunjuk satu tempat (`locations`) yang punya alamat pos di buku alamat Core (`App\Platform\AddressBook`). Modul tidak menyimpan alamatnya; nama dan alamat tercetak dibaca lewat kontrak `App\Platform\Modules\Contracts\AddressDirectory` setiap kali lokasi disajikan.

**Lokasi yang kosong mewarisi alamat lokasi induk terdekat yang punya alamat.** Lazimnya hanya lokasi puncak (site atau gedung) yang diisi; lantai dan ruang di bawahnya ikut. Lokasi anak tetap boleh punya alamat sendiri, misalnya gudang di luar kompleks, dan alamat itulah yang berlaku untuknya dan untuk anak-anaknya.

Jawaban API memisahkan keduanya:

| Field jawaban | Isinya |
| --- | --- |
| `alamat_id`, `alamat` | Milik lokasi ini sendiri, atau `null` |
| `alamat_efektif` | Yang berlaku: milik sendiri atau warisan. `diwarisi_dari` menyebut lokasi pemiliknya, `null` bila milik sendiri |

**Pilihan alamat hanya dari buku alamat.** Alamat dibuat dan diubah di bagian Alamat organisasi (entitas legal atau unit kerja), bukan dari layar lokasi. Id tempat yang tidak ada di buku alamat tenant aktif, atau tempat tanpa alamat pos, ditolak 422. Pilihannya dimuat `GET reference-data/alamat`.

**Alamat unit kerja hanya saran.** Saat lokasi diberi unit kerja bawaan dan alamatnya masih kosong, layar menawarkan alamat utama unit kerja itu (`GET reference-data/alamat?unit_kerja_id=`). Pengguna yang menerimanya menyimpan id tempat itu di lokasi. Sesudahnya tidak ada hubungan apa pun: mengganti alamat utama unit kerja tidak menggeser alamat lokasi. Alasannya, unit kerja bisa pindah gedung sementara ruangannya tetap di tempat yang sama, atau sebaliknya.

## Unit kerja bawaan

`departemen_bawaan_id` adalah unit kerja Core (operating unit) yang bertanggung jawab atas barang di lokasi itu. Ia **mengisi**, bukan **menentukan**:

- Penerimaan aset: `responsible_org_unit_id` yang tidak dikirim diisi unit kerja bawaan `lokasi_aset_id`.
- Mutasi aset: `tujuan_org_unit_id` yang tidak dikirim diisi unit kerja bawaan `tujuan_lokasi_id`.
- Bila dokumennya menyebut unit, unit itu yang dipakai. Bila keduanya kosong, 422 pada field unitnya.

Layar melakukan hal yang sama lebih awal: memilih lokasi mengisi unit di formulir, dan pengguna masih bisa menggantinya sebelum menyimpan. Pengisian di server ada untuk klien API yang hanya mengirim lokasi.

Pewarisannya sama dengan alamat: lokasi kosong memakai unit kerja bawaan lokasi induk terdekat. Unit kerja yang tidak ada di tenant aktif ditolak 422.

### Kenapa bukan `org_unit_id`

Kolom `org_unit_id` sudah ada dan berarti dimensi keuangan (lihat di bawah). Keduanya sering bernilai sama, tetapi menjawab pertanyaan berbeda:

| Kolom lokasi | Mengisi kolom aset | Menjawab |
| --- | --- | --- |
| `departemen_bawaan_id` | `responsible_org_unit_id` | Siapa yang bertanggung jawab atas barangnya, dan siapa yang boleh melihatnya (kebijakan data `asset-responsibility`) |
| `org_unit_id` | `financial_dimension_org_unit_id` | Siapa yang menanggung biayanya |

Menyatukannya akan memaksa ruang server yang dibiayai TI tetapi diurus bagian umum memilih salah satu.

### Kenapa pewarisan dihitung saat dibaca

Memindahkan lokasi ke induk lain, atau mengubah alamat induk, langsung berlaku bagi seluruh anaknya tanpa langkah sinkronisasi. Biayanya satu query pohon lokasi tenant per permintaan daftar (`LocationInheritance::tree()`), bukan satu pendakian per baris. Pendakian dibatasi `LocationDimension::MAX_DEPTH` dan berhenti pada siklus, sama seperti dimensi.

## Jembatan ke dimensi keuangan

Lokasi punya kolom `org_unit_id`. Kalau diisi, ia berarti: **barang yang berada di lokasi ini dibebankan ke unit kerja tersebut.**

Saat aset dibuat atau dipindahkan, `financial_dimension_org_unit_id` pada aset diisi dengan urutan:

1. Unit kerja yang dipetakan pada lokasinya, kalau ada.
2. Kalau lokasinya tidak dipetakan, unit kerja lokasi induk terdekat yang dipetakan (K-08 feed posting finance).
3. Kalau tidak ada satu pun lokasi di jalur ke akar yang dipetakan, unit kerja pemakai aset.

**Kenapa induk ikut dicari:** satu poli bisa tersebar di beberapa lantai dan ruang. Memetakan setiap ruang satu per satu membuat ruang yang lupa dipetakan diam-diam membebani unit pemakai. Dengan pewarisan, cukup lantainya yang dipetakan, dan memindahkan aset antar ruang di bawah lantai yang sama tidak mengubah jurnalnya.

Aturannya satu fungsi, `Services/LocationDimension::resolve()`, dipakai penerimaan dan mutasi supaya keduanya tidak pernah menjawab berbeda. Pendakiannya dibatasi 32 tingkat dan berhenti pada siklus. Siklus hanya mungkin bila datanya rusak, karena penulisan lokasi sudah menolaknya; bila terjadi, pendakian berhenti, melapor ke pemantauan kesalahan, dan aset memakai unit pemakainya, bukan menggagalkan penerimaan.

Jadi memindahkan aset ke gudang yang dipetakan ke unit lain **ikut memindahkan pembebanan biayanya**. Ini disengaja: kalau barangnya pindah tanggung jawab, biayanya juga.

### Kenapa dipisah dari unit penanggung jawab

Aset punya dua kolom unit yang berbeda dan sering tertukar:

| Kolom | Menjawab |
| --- | --- |
| `responsible_org_unit_id` | Siapa yang bertanggung jawab atas barangnya |
| `financial_dimension_org_unit_id` | Siapa yang menanggung biayanya |

Sering sama, tetapi tidak selalu. Mesin yang dipakai bagian produksi tetapi disimpan di gudang pusat bisa dibebankan ke gudang. Menggabungkan keduanya jadi satu kolom akan memaksa memilih salah satu, dan yang satunya jadi salah.

## Lokasi bawaan dari group

Group aset boleh menentukan lokasi bawaan. Nilai itu hanya mengisi kekosongan saat aset dibuat; setelah itu lokasi milik aset. Mengubah bawaan group tidak memindahkan aset mana pun.

## Aturan yang dijaga

**Lokasi harus satu tenant dan belum diarsipkan** saat dipilih. Divalidasi lewat `Rule::exists`, dan ditegakkan ulang oleh foreign key gabungan di database.

**Lokasi tidak boleh menjadi induk dirinya sendiri**, langsung maupun lewat rantai.

**Lokasi yang masih punya anak aktif tidak bisa diarsipkan.**

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `src/Http/Controllers/master/LokasiAsetController.php` | Master lokasi |
| `src/Http/Controllers/master/TipeLokasiAsetController.php` | Tipe lokasi |
| `database/migrations/2026_08_07_110000_create_asset_location_type_and_dimension_bridge.php` | Tipe lokasi dan jembatan dimensi |
| `src/Services/LocationDimension.php` | Pewarisan unit kerja dimensi keuangan dari lokasi dan induknya |
| `src/Services/LocationInheritance.php` | Pewarisan alamat dan unit kerja bawaan |
| `database/migrations/2026_10_01_100000_add_address_and_default_department_to_asset_locations.php` | Kolom alamat dan unit kerja bawaan |
| `apps/core/app/Platform/AddressBook/ModuleServices/AddressDirectoryCore.php` | Kontrak buku alamat untuk module |
| `ui/master/LokasiAsetFields.tsx` | Field alamat dan unit kerja bawaan beserta nilai warisan dan saran alamat |
| `tests/Feature/LokasiAsetAlamatDanUnitBawaanTest.php` | Pewarisan, saran alamat, penerimaan dan mutasi, lingkup tenant |

## Halaman terkait

- [Register aset](/apps/management-aset/transaction/register-aset/) — tempat pembebanan ditentukan
- [Penempatan dan mutasi](/apps/management-aset/transaction/penempatan/) — perpindahan lokasi
- [Group aset](/apps/management-aset/master/groupaset/) — lokasi bawaan
