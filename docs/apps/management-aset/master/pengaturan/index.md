# Parameter aset tetap

Halaman ini untuk developer. Layarnya adalah menu **Parameter aset tetap** (`fixed-aset-parameters`), padanan halaman *Fixed Asset Setup* (5607) Business Central atas tabel `FA Setup` (5603), dan *Fixed assets parameters* di F&O.

Isinya pengaturan yang berlaku untuk seluruh aset satu tenant. Hanya pengaturan yang **sudah dibaca kode modul** yang dibuat. Field BC lain dicatat di bawah beserta alasannya, supaya orang berikutnya tidak menambah kolom yang tidak dibaca siapa pun.

## Data yang disimpan

`aset_pengaturan_aset_tetap`, paling banyak satu baris aktif per tenant (indeks unik parsial `(tenant_id) WHERE deleted_at IS NULL`):

| Kolom | Padanan BC | Dibaca oleh |
| --- | --- | --- |
| `buku_penyusutan_bawaan_id` | Default Depr. Book | Monitoring aset: nilai perolehan, akumulasi, dan nilai buku yang dibekukan saat pemeriksaan diselesaikan |

Foreign key `(tenant_id, buku_penyusutan_bawaan_id)` ke `aset_m_buku_penyusutan` menolak buku tenant lain di database. Saat disimpan, buku juga harus aktif dan belum diarsipkan.

**Satu baris per tenant, bukan per entitas legal.** Company BC dan legal entity F&O masing-masing punya setup sendiri. Di sini buku penyusutan, group, dan profil yang dirujuk setup adalah master tenant, jadi setup per entitas legal akan menunjuk master yang sama dari beberapa baris tanpa ada yang membedakannya.

**Baris lahir saat pertama disimpan.** Seperti BC yang menyisipkan record `FA Setup` saat halamannya dibuka, hanya saja di sini penyisipannya terjadi pada `PUT` pertama. Selama belum ada, `GET` memulangkan `version: 0` dan setiap pengaturan `null`. Tidak ada seeder dan tidak ada migration data: tenant lama yang belum pernah menyimpan mendapat perilaku "kosong" yang sama dengan tenant baru.

### Perilaku bila kosong

Setiap pembaca wajib punya perilaku saat pengaturannya `null`:

| Pengaturan | Bila kosong |
| --- | --- |
| Buku penyusutan bawaan | Monitoring memakai buku komersial (tanpa master, atau berlapisan `current`) dengan kode paling awal, perilaku sebelum pengaturan ini ada |

Buku bawaan hanya berlaku pada aset yang memang punya buku itu. Aset yang tidak memilikinya tetap memakai aturan buku komersial.

## Endpoint

| Endpoint | Gunanya | Izin |
| --- | --- | --- |
| `GET pengaturan-aset-tetap` | Membaca pengaturan | `management-aset.fixed-asset-parameters.read` |
| `PUT pengaturan-aset-tetap` | Menyimpan; field yang tidak dikirim tidak diubah | `management-aset.fixed-asset-parameters.update` |

`PUT` wajib membawa versi (`version` atau `If-Match`). Penyimpanan pertama membawa 0; dua penyimpanan pertama yang bersamaan diurutkan `RowVersion::claimIfExists()`, jadi yang kedua ditolak 409 alih-alih membuat baris kedua.

## Hak akses

Rantainya di `manifest/setup/fixed-asset-parameters.yaml`. Izin ubah berdiri di duty sendiri, `management-aset.fixed-asset-parameters.manage`, bukan ditambahkan ke duty lihat `management-aset.fixed-assets-setup.view`: role yang sudah memegang duty lihat tidak boleh diam-diam bisa mengubah pengaturan. Pola yang sama dipakai posting group aset.

Pilihan buku di layar dimuat dari master buku penyusutan, jadi pengguna juga butuh izin lihat buku penyusutan. Tanpa itu layar menampilkan pesan bahwa pilihan belum dapat dimuat.

## Field BC yang sengaja belum dibuat

| Field `FA Setup` | Kenapa belum |
| --- | --- |
| Allow Posting to Main Assets | Modul belum melarang posting ke aset induk (`induk_aset_id`), jadi tidak ada yang membaca saklarnya |
| Allow FA Posting From / To | Batas tanggal posting sudah dijaga kalender fiskal dan cutover entitas legal di Core; rentang kedua di sini akan menjadi aturan tandingan |
| Insurance Depr. Book, Automatic Insurance Posting, Insurance Nos. | Milik fitur asuransi, yang menambahkannya lewat migration sendiri |
| Fixed Asset Nos. | Nomor aset diatur di **Nomor dokumen** Core lewat reference `management-aset.*` di manifest, bukan disimpan di setup modul |
| Bonus Depreciation %, Bonus Depr. Effective Date | Aturan pajak Amerika Serikat; tidak ada padanannya di aturan fiskal yang dipakai |

## Menambah pengaturan

1. Migration baru di modul: kolom nullable di `aset_pengaturan_aset_tetap`. Foreign key ke master modul ditulis gabungan dengan `tenant_id`.
2. Tambahkan kolomnya ke `$fillable` dan `@property` di `src/Models/master/FixedAssetSetup.php`.
3. Tambahkan aturannya di `FixedAssetSetupController::rules()` (selalu `sometimes`) dan nilainya di `present()`.
4. Tulis perilaku bila kosong di pembacanya, lalu catat di tabel di atas.
5. Perbarui skema `PengaturanAsetTetap` di `contracts/src/components/schemas.yaml` dan jalankan `python contracts/bundle.py`.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `database/migrations/2026_10_01_100100_create_fixed_asset_setup_table.php` | Tabel pengaturan |
| `src/Models/master/FixedAssetSetup.php` | Model dan pembaca buku bawaan |
| `src/Http/Controllers/master/FixedAssetSetupController.php` | `GET` dan `PUT` |
| `routes/api/fixed-asset-setup.php` | Rute |
| `ui/pengaturan-aset-tetap/PengaturanAsetTetapPage.tsx` | Layar |
| `tests/Feature/PengaturanAsetTetapTest.php` | Simpan dan baca, versi, lingkup tenant, izin |
| `tests/Feature/AssetMonitoringTest.php` | Buku bawaan dipakai monitoring |
