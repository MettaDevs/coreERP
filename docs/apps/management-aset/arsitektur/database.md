# Database dan migration

Halaman ini untuk developer. Isinya pola yang berlaku untuk setiap tabel baru di modul ini.

## Kunci gabungan yang menutup kebocoran antar tenant

Ini pola paling penting di modul ini. Setiap tabel punya:

```php
$table->unique(['tenant_id', 'id']);
```

Kunci itu terlihat berlebihan — `id` sudah primary key. Gunanya bukan mencegah duplikat, melainkan **memungkinkan tabel lain menunjuknya bersama tenant**:

```php
$table->foreign(['tenant_id', 'jenis_aset_id'])
      ->references(['tenant_id', 'id'])->on('m_jenis_aset');
```

Akibatnya baris milik tenant A tidak bisa menunjuk induk milik tenant B, dan yang menolak adalah database. Kelupaan menyaring di satu controller tetap tidak berubah jadi kebocoran data.

**Setiap tabel baru wajib memakai pola ini.** Foreign key yang hanya menunjuk `id` adalah cacat, meskipun kodenya saat ini menyaring dengan benar.

## Kunci unik lain yang selalu ada

| Kunci | Menjaga |
| --- | --- |
| `(tenant_id, kode)` | Tidak ada dua record dengan kode sama dalam satu tenant |
| `(tenant_id, creation_key)` | Idempotency ditegakkan database, bukan hanya kode |

`creation_key` menyimpan `Idempotency-Key` dari permintaan. Kalau dua permintaan dengan kunci sama tiba bersamaan, satu berhasil dan yang lain menabrak batasan unik lalu membaca ulang record milik pemenang. Tanpa batasan itu, keduanya akan berhasil dan menghasilkan dua record.

## Arsip lunak

Master memakai `softDeletes()`. `DELETE` mengisi `deleted_at`, tidak menghapus baris.

Alasannya: record yang sudah dipakai transaksi lama tetap harus bisa dibaca. Yang berubah hanya ia berhenti muncul sebagai pilihan.

Karena itu validasi induk selalu menyertakan `whereNull('deleted_at')` — induk yang sudah diarsipkan tidak boleh dipilih untuk record baru, tetapi record lama yang menunjuknya tetap sah.

## Aturan penghapusan pada foreign key

| Pilihan | Dipakai untuk |
| --- | --- |
| `restrictOnDelete()` | Hampir semua. Menolak menghapus induk yang masih dipakai |
| `cascadeOnDelete()` | Baris anak yang tidak punya arti sendiri, misalnya baris template checklist |

Jangan memakai cascade untuk hal yang punya arti sendiri. Menghapus satu master tidak boleh menghilangkan transaksi yang menunjuknya.

## Migration yang mengubah data

Sebagian migration bukan mengubah bentuk tabel, melainkan memperbaiki data yang sudah ada. Yang perlu diperhatikan:

**Jangan memperbaiki baris yang sudah menghasilkan akibat.** Contoh nyata: migration perbaikan scope penomoran hanya menyentuh sequence yang belum pernah menerbitkan nomor. Mengubah scope setelah nomor terbit bisa memecah penghitung dan menerbitkan nomor yang sama dua kali.

**`down()` boleh sengaja kosong** untuk perbaikan data satu arah, asal alasannya ditulis di dalamnya. Membalikkan perbaikan bisa membuat data yang dibuat setelahnya jadi tidak sah.

## Menjalankan migration

```bash
bash deploy/migrate.sh
```

Terpisah dari penyalaan aplikasi, dan ikut dibawa image API saat deploy.

Untuk pengembangan lokal, stack `erp-dev` menjalankannya otomatis.

## Yang harus diperiksa sebelum menambah tabel

1. Ada `tenant_id`, dan diindeks.
2. Ada `unique(['tenant_id', 'id'])`.
3. Semua foreign key menunjuk pasangan `(tenant_id, id)`.
4. Kalau ia master: ada `kode`, `creation_key`, `aktif`, `softDeletes()`, dan dua kunci unik di atas.
5. Kalau ia punya baris anak: aturan penghapusannya dipilih sadar, bukan default.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `database/migrations/` | Semua migration |
| `deploy/migrate.sh` | Penjalan saat deploy |
| `database/README.md` | Catatan tambahan di repo app |

Contoh pola yang baik untuk ditiru: `2026_08_15_100000_create_work_order_masters.php` punya helper `createMaster()` yang membentuk seluruh bentuk dasar master dalam satu tempat.

## Halaman terkait

- [Batas tenant dan organisasi](/apps/management-aset/arsitektur/batas-tenant-dan-organisasi) — lapis penyaringan di atas skema ini
- [Master data](/apps/management-aset/master/) — perilaku yang bersandar pada kunci-kunci di atas
- [Query scope dan schema](/dev/08-query-scopes-and-schema) — aturan platform
