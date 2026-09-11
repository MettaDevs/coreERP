# Database dan migration

Halaman ini untuk developer. Isinya pola yang berlaku untuk setiap tabel baru di modul ini.

## Awalan tabel adalah batas modulnya

Modul memakai database tenant yang sama dengan Core. Yang memisahkan datanya adalah **awalan
`aset_`** pada setiap tabel, tanpa kecuali — termasuk tabel bantu seperti
`aset_processed_core_events`, yang tanpa awalan hampir pasti bertabrakan dengan modul lain yang
menyaring kejadian ganda.

Konsekuensinya perlu disadari sejak awal: `DB::table('tabel_modul_lain')` **akan berhasil**. Yang
menolaknya bukan database, melainkan penjaga batas di `apps/core/tests/Feature/Boundary/`
yang memindai seluruh isi `modules/`. Itu sebabnya query mentah pada tabel modul dilarang: ia juga
melewati lapisan model, dan lapisan model itulah yang menegakkan penyaringan tenant.

## Kunci gabungan yang menutup kebocoran antar tenant

Ini pola paling penting di modul ini. Setiap tabel punya:

```php
$table->unique(['tenant_id', 'id']);
```

Kunci itu terlihat berlebihan — `id` sudah primary key. Gunanya bukan mencegah duplikat, melainkan **memungkinkan tabel lain menunjuknya bersama tenant**:

```php
$table->foreign(['tenant_id', 'jenis_aset_id'])
      ->references(['tenant_id', 'id'])->on('aset_m_jenis_aset');
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

## Migration yang mengganti nama tabel

`2026_09_08_130000_prefix_tabel_modul.php` adalah contoh yang layak dibaca sebelum menulis
migration serupa. Dua keputusannya:

**Daftar tabelnya dibaca dari katalog PostgreSQL, bukan ditulis tangan.** Daftar yang ditulis tangan
akan tertinggal satu tabel pada hari seseorang menambah migration baru sebelum migration ini
dijalankan di suatu lingkungan, dan tabel yang tertinggal itu tidak gagal dengan sendirinya — ia
hanya diam sampai bertabrakan dengan modul lain.

**Migration lama sengaja tidak disunting.** Semuanya tetap membuat tabel bernama lama, lalu
migration ini yang mengganti namanya. Menyunting migration lama akan mengubah riwayat yang sudah
dijalankan tenant yang ada, dan itu melanggar aturan bahwa perintah pemasangan harus aman diulang.
Aturan yang sama berlaku untuk kolom baru: `deleted_at` ditambahkan lewat migration tersendiri,
bukan dengan menyunting migration yang sudah pernah berjalan.

## Menjalankan migration

```bash
php artisan module:migrate management-aset
```

Terpisah dari penyalaan aplikasi. Migration modul punya catatannya sendiri di Core, jadi
`php artisan migrate` untuk Core tidak menjalankannya dan sebaliknya.

Untuk pengembangan lokal, skrip orkestrasi menjalankannya otomatis untuk tiap modul terpilih.

## Yang harus diperiksa sebelum menambah tabel

1. Namanya berawalan `aset_`.
2. Ada `tenant_id`, dan diindeks.
3. Ada `unique(['tenant_id', 'id'])`.
4. Semua foreign key menunjuk pasangan `(tenant_id, id)`.
5. Modelnya memakai trait `MilikTenant`, dan tidak menyaring `tenant_id` lagi dengan tangan.
6. Kalau ia master: ada `kode`, `creation_key`, `aktif`, `softDeletes()`, dan dua kunci unik di atas.
7. Kalau ia punya baris anak: aturan penghapusannya dipilih sadar, bukan default.

Poin 1 dan 5 dijaga `ModuleTableBoundaryTest` dan `ModelModuleMilikTenantTest`; keduanya ikut
`php artisan test` dan memindai seluruh isi `modules/`.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `database/migrations/` | Semua migration |
| `database/README.md` | Catatan tambahan di dalam folder modul |

Semuanya relatif terhadap `modules/apperp/management-aset/`.

Contoh pola yang baik untuk ditiru: `2026_08_15_100000_create_work_order_masters.php` punya helper `createMaster()` yang membentuk seluruh bentuk dasar master dalam satu tempat.

## Halaman terkait

- [Batas tenant dan organisasi](/apps/management-aset/arsitektur/batas-tenant-dan-organisasi) — lapis penyaringan di atas skema ini
- [Master data](/apps/management-aset/master/) — perilaku yang bersandar pada kunci-kunci di atas
- [Query scope dan schema](/dev/08-query-scopes-and-schema) — aturan platform
