# Lokasi aset dan dimensi keuangan

Halaman ini untuk developer. Perilaku dasar master ada di [Master data](/apps/management-aset/master/).

Lokasi menjawab **di mana barangnya berada**. Yang membuatnya lebih menarik daripada master biasa: lokasi juga ikut menentukan **ke mana biayanya dibebankan**.

## Dua master

| Master | Tabel | Isi |
| --- | --- | --- |
| `tipe-lokasi-aset` | `aset_m_tipe_lokasi_aset` | Golongan lokasi, misalnya gudang, kantor, area produksi |
| `lokasi-aset` | `aset_m_lokasi_aset` | Lokasi sebenarnya, boleh berinduk lokasi lain |

Lokasi bisa bersarang: gedung berisi lantai, lantai berisi ruang. Karena itu ia punya `parent_id` yang menunjuk tabelnya sendiri.

**Lingkaran ditolak.** A → B → A diperiksa saat penyimpanan. Tanpa itu, penelusuran induk akan berputar tanpa henti.

## Jembatan ke dimensi keuangan

Lokasi punya kolom `org_unit_id`. Kalau diisi, ia berarti: **barang yang berada di lokasi ini dibebankan ke unit kerja tersebut.**

Saat aset dibuat atau dipindahkan, `financial_dimension_org_unit_id` pada aset diisi dengan urutan:

1. Unit kerja yang dipetakan pada lokasinya, kalau ada.
2. Kalau lokasi tidak dipetakan, unit kerja pemakai aset.

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
| `AssetController::locationDimension()` | Pemetaan lokasi ke unit kerja |

## Halaman terkait

- [Register aset](/apps/management-aset/transaction/register-aset/) — tempat pembebanan ditentukan
- [Penempatan dan mutasi](/apps/management-aset/transaction/penempatan/) — perpindahan lokasi
- [Group aset](/apps/management-aset/master/groupaset/) — lokasi bawaan
