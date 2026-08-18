# Master data

Halaman ini untuk developer. Isinya perilaku yang **dipakai bersama oleh semua master** di modul aset — bukan penjelasan satu per satu.

Master data adalah daftar pilihan yang dipakai berulang: `group-aset`, `jenis-aset`, `kondisi-aset`, `pabrikan-aset`, `model-aset`, `lokasi-aset`, `tipe-lokasi-aset`, `tipe-atribut`, `profil-penyusutan`, `buku-penyusutan`, tipe pekerjaan maintenance, dan master work order. Daftar lengkapnya ada di `api/routes/api.php` pada array `$masters`.

Sebagian punya aturan khusus dan dibahas di halamannya sendiri; sisanya hanya memakai bentuk dasar di halaman ini, tanpa kolom maupun aturan tambahan. Tetapi **tidak punya aturan khusus bukan berarti tidak punya fungsi** — daftar lengkapnya di bawah.

## Fungsi tiap master

| Master | Menjawab | Dibahas di |
| --- | --- | --- |
| `group-aset` | Barang ini disusutkan bagaimana, masuk kelompok pajak apa | [Group aset](/apps/management-aset/master/groupaset/) |
| `jenis-aset` | Data teknis apa yang harus diisi, pekerjaan apa yang berlaku | [Jenis aset](/apps/management-aset/master/jenisaset/) |
| `tipe-atribut` | Hal apa saja yang bisa dicatat sebagai data tambahan | [Jenis aset](/apps/management-aset/master/jenisaset/) |
| `pabrikan-aset` | Barang ini buatan siapa | [Pabrikan dan model](/apps/management-aset/master/katalog-model/) |
| `model-aset` | Tipe barang dari pabrikan itu | [Pabrikan dan model](/apps/management-aset/master/katalog-model/) |
| `kondisi-aset` | Keadaan fisik barang sekarang — baik, rusak ringan, rusak berat. Dipakai menyaring daftar dan menilai apakah barang masih layak dipakai | halaman ini |
| `tipe-lokasi-aset` | Golongan lokasi: gudang, kantor, area produksi | [Lokasi](/apps/management-aset/master/lokasi/) |
| `lokasi-aset` | Di mana barangnya berada, dan siapa yang menanggung biayanya | [Lokasi](/apps/management-aset/master/lokasi/) |
| `profil-penyusutan` | Cara menghitung penyusutan | [Penyusutan](/apps/management-aset/master/depresiasi/) |
| `buku-penyusutan` | Untuk keperluan apa penyusutan dihitung | [Penyusutan](/apps/management-aset/master/depresiasi/) |
| `maintenance-job-types` | Jenis pekerjaan perawatan | [Setup maintenance](/apps/management-aset/master/maintenance/) |
| `maintenance-job-type-variants` | Turunan pekerjaan, misalnya servis per jarak tempuh | [Setup maintenance](/apps/management-aset/master/maintenance/) |
| `maintenance-job-type-defaults` | Nilai bawaan saat pekerjaan dibuat | [Setup maintenance](/apps/management-aset/master/maintenance/) |
| `maintenance-checklist-variables` | Hal yang diukur atau dinilai saat pemeriksaan | [Setup maintenance](/apps/management-aset/master/maintenance/) |
| `maintenance-checklist-templates` | Susunan baris pemeriksaan yang dipakai berulang | [Setup maintenance](/apps/management-aset/master/maintenance/) |
| `tipe-work-order` | Golongan pekerjaan, sekaligus aturan apa yang wajib diisi sebelum boleh ditutup | [Master work order](/apps/management-aset/master/work-order/) |
| `tingkat-layanan` | Seberapa mendesak penanganannya | [Master work order](/apps/management-aset/master/work-order/) |
| `trade` | Keahlian apa yang dibutuhkan pekerjaan itu | [Master work order](/apps/management-aset/master/work-order/) |
| `sebab-kerusakan` | Kenapa pekerjaan itu diperlukan | halaman ini |
| `tindakan-perbaikan` | Apa yang dilakukan untuk memperbaikinya | halaman ini |
| `item-checklist-maintenance` | Daftar pemeriksaan dari rancangan lama | [Setup maintenance](/apps/management-aset/master/maintenance/) |
| `analisa-maintenance` | Kategori analisa dari rancangan lama | [Setup maintenance](/apps/management-aset/master/maintenance/) |

### Kenapa sebab dan tindakan jadi master, bukan teks bebas

`sebab-kerusakan`, `tindakan-perbaikan`, dan `trade` dulunya pilihan yang ditulis langsung di UI — atau tidak ada sama sekali, dan teknisi mengetik sendiri.

Dipindahkan jadi master karena dua alasan: tenant bisa menambah nilainya tanpa menunggu rilis aplikasi, dan **hasil pekerjaan bisa dihitung**. "Aki soak" yang diketik tiga teknisi akan jadi tiga tulisan berbeda; sebagai master, ia satu baris yang bisa dijumlahkan jadi laporan penyebab kerusakan tersering.

Ini pertimbangan yang berlaku umum: kalau sebuah teks bebas kelak ingin dihitung, ia seharusnya master sejak awal.

## Satu controller untuk semua

Hampir semua master tidak punya controller sendiri yang berisi logika. Mereka mewarisi `MasterDataController` dan hanya menyebutkan dua hal:

```php
class TradeController extends MasterDataController
{
    protected function resource(): string { return 'trade'; }
    protected function model(): string { return Trade::class; }
}
```

Kalau master itu punya induk atau anak, ia menambah `parentMasters()` dan `childMasters()`.

Artinya: **kalau Anda menemukan bug pada satu master, kemungkinan besar bug itu ada di semua master.** Perbaiki di base controller, jangan di satu turunannya.

## Base kedua: tabel penghubung

Tidak semua yang tersimpan adalah master. Matriks group × buku, kaitan pekerjaan ke jenis aset, dan baris template checklist adalah **tabel penghubung** — mereka disunting di dalam form pemiliknya, bukan berdiri sendiri di navigasi.

Base-nya `MasterLinkController`, dan ia sengaja **bukan** turunan `MasterDataController`. Bedanya:

| | Master | Tabel penghubung |
| --- | --- | --- |
| Punya `kode` | Ya | Tidak |
| Minta nomor ke Core | Ya | Tidak |
| Butuh `Idempotency-Key` | Ya | Tidak |
| Permission | Milik sendiri | Milik pemiliknya |
| Cara menyimpan | Per record | Satu `PUT` mengganti seluruh daftar |

Baris penghubung tidak butuh kunci idempotency karena bentuk penyimpanannya sudah idempoten: permintaan yang sama diulang menghasilkan keadaan yang sama, bukan baris tambahan.

Yang perlu diperhatikan: karena ia mengganti seluruh daftar, ia **menghapus lalu menyisipkan ulang** — dan itu harus mengunci baris pemiliknya lebih dulu. Alasannya di [Setup maintenance](/apps/management-aset/master/maintenance/).

## Bentuk yang sama untuk semua

Setiap master punya kolom yang sama:

| Kolom | Isi |
| --- | --- |
| `kode` | Diterbitkan Number Sequence Core. Tidak pernah dibuat app ini, tidak bisa diubah pengguna |
| `nama` | Wajib, maksimal 150 karakter |
| `keterangan` | Opsional |
| `aktif` | Penanda masih boleh dipilih atau tidak |
| `tenant_id` | Selalu ada, selalu ikut menyaring |
| `creation_key` | Kunci idempotency |

Ditambah kolom khusus milik master itu sendiri, misalnya `urutan` pada tingkat layanan.

## Lima endpoint, sama untuk semua

| Endpoint | Permission |
| --- | --- |
| `GET /api/v1/{resource}` | `.read` |
| `POST /api/v1/{resource}` | `.create` |
| `GET /api/v1/{resource}/{id}` | `.read` |
| `PATCH /api/v1/{resource}/{id}` | `.update` |
| `DELETE /api/v1/{resource}/{id}` | `.archive` |

Permission-nya per resource, bukan satu hak "kelola master". Punya `group-aset.update` tidak memberi akses ke `jenis-aset`. Ini disengaja dan diuji.

## Aturan yang dijaga, dan alasannya

**Kode selalu dari Core.** `POST` meminta nomor ke Number Sequence lewat `NumberSequenceClient`. Kalau reference-nya belum diaktifkan admin tenant, permintaan gagal dengan 503 — bukan diam-diam memakai nomor buatan sendiri. Nomor yang tidak bisa dipertanggungjawabkan lebih buruk daripada gagal.

**`POST` wajib membawa `Idempotency-Key`.** Kunci itu disimpan sebagai `creation_key` dengan batasan unik `(tenant_id, creation_key)` di database. Kirim ulang kunci yang sama, yang kembali adalah record yang sama — bukan record baru dan bukan nomor baru. Kalau dua permintaan dengan kunci sama tiba bersamaan, yang kalah menangkap pelanggaran batasan unik lalu membaca ulang record milik pemenang.

**Induk harus satu tenant dan belum diarsipkan.** Divalidasi lewat `Rule::exists` yang menyaring `tenant_id` dan `deleted_at`. Lapis keduanya ada di database: foreign key-nya gabungan `(tenant_id, parent_id)` menunjuk `(tenant_id, id)`, jadi anak milik tenant A **secara struktural tidak bisa** menunjuk induk milik tenant B. Database yang menolak, bukan kode aplikasi.

**Induk yang masih punya anak aktif tidak bisa diarsipkan.** Kalau diizinkan, anaknya menunjuk induk yang sudah hilang dari daftar pilihan, dan form yang menampilkannya jadi setengah rusak. Arsipkan anaknya dulu.

**Master yang menunjuk dirinya sendiri tidak boleh membentuk lingkaran.** Lokasi aset bisa berinduk lokasi lain, jadi A → B → A harus ditolak. Diperiksa di `rejectParentCycle`.

**Arsip itu lunak.** `DELETE` mengisi `deleted_at`, tidak menghapus baris. Record yang sudah dipakai transaksi lama tetap bisa dibaca; ia hanya berhenti muncul sebagai pilihan.

## Data awal Indonesia

Tenant baru tidak mulai dari nol. Saat Core mengirim event `core.tenant.provisioned.v1`, app mengisi master dasar dari template Indonesia: kelompok harta fiskal menurut PMK 72/2023, profil penyusutan, buku, tipe lokasi, kondisi, dan setup maintenance.

Sifatnya:

- **Idempoten.** Dicek lewat `(tenant_id, creation_key)`. Event yang dikirim ulang tidak menggandakan apa pun.
- **Tidak menimpa.** Record yang sudah disesuaikan tenant dibiarkan apa adanya, dan record yang sengaja diarsipkan tidak dihidupkan lagi.
- **Tetap lewat Core untuk nomor.** Data awal pun kodenya diterbitkan Number Sequence, bukan ditulis langsung.

Kodenya di `api/app/Services/ProvisionIndonesiaStarterData.php`, isinya di `api/config/management_aset.php`.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Http/Controllers/MasterDataController.php` | Seluruh perilaku bersama di atas |
| `api/app/Http/Controllers/MasterLinkController.php` | Base untuk tabel penghubung |
| `api/app/Http/Controllers/master/` | Turunan per master, biasanya hanya beberapa baris. Contoh yang paling sederhana: `KondisiAsetController`, `TradeController` |
| `api/app/Http/Controllers/master/TipeAtributController.php`, `TipeAtributNilaiController.php` | Definisi atribut dan pilihan nilainya |
| `api/app/Models/master/` | Model |
| `api/app/Support/MasterParent.php`, `MasterChild.php` | Deklarasi hubungan induk dan anak |
| `api/app/Services/ProvisionIndonesiaStarterData.php` | Data awal |
| `ui/src/master/MasterPage.tsx`, `MasterForm.tsx` | Layar dan form master — satu komponen dipakai semua master, dibentuk dari konfigurasi di `masters.ts` |
| `ui/src/master/detail/MasterDetailPage.tsx` | Panel detail dua kolom untuk master yang dibaca dengan membandingkan satu record dengan lainnya |
| `ui/src/master/` | Panel khusus: `JenisAsetModels`, `JenisAsetAtribut`, `TipeAtributNilai`, `MaintenanceChecklistTemplateLines`, dan sejenisnya |

## Halaman terkait

- [Jenis aset dan atribut](/apps/management-aset/master/jenisaset/)
- [Penyusutan: profil, buku, dan matriks](/apps/management-aset/master/depresiasi/)
- [Setup maintenance](/apps/management-aset/master/maintenance/)
- [Register aset](/apps/management-aset/transaction/register-aset/) — pemakai utama master ini
- [Number sequence](/dev/14-number-sequences) — cara `kode` diterbitkan
