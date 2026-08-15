# Master data

Halaman ini untuk developer. Isinya perilaku yang **dipakai bersama oleh semua master** di modul aset — bukan penjelasan satu per satu.

Master data adalah daftar pilihan yang dipakai berulang: group aset, jenis aset, kondisi, pabrikan, lokasi, tipe pekerjaan maintenance, dan seterusnya. Daftar lengkapnya ada di `api/routes/api.php` pada array `$masters`.

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
| `api/app/Http/Controllers/master/` | Turunan per master, biasanya hanya beberapa baris |
| `api/app/Models/master/` | Model |
| `api/app/Support/MasterParent.php`, `MasterChild.php` | Deklarasi hubungan induk dan anak |
| `api/app/Services/ProvisionIndonesiaStarterData.php` | Data awal |
| `ui/src/master/` | Layar master, satu komponen untuk semua |

## Halaman terkait

- [Jenis aset dan atribut](/apps/management-aset/master/jenisaset/)
- [Penyusutan: profil, buku, dan matriks](/apps/management-aset/master/depresiasi/)
- [Setup maintenance](/apps/management-aset/master/maintenance/)
- [Register aset](/apps/management-aset/transaction/register-aset/) — pemakai utama master ini
- [Number sequence](/dev/14-number-sequences) — cara `kode` diterbitkan
