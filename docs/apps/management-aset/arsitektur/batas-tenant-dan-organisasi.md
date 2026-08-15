# Batas tenant dan organisasi

Halaman ini untuk developer. Isinya dua lapis penyaringan yang berlaku di seluruh modul, dan kenapa keduanya diperlukan.

Sering tertukar, padahal berbeda:

| Lapis | Menjawab | Kalau bocor |
| --- | --- | --- |
| **Tenant** | Data ini milik perusahaan mana? | Perusahaan lain melihat data Anda. Fatal |
| **Organisasi** | Di dalam perusahaan itu, siapa yang boleh melihatnya? | Orang gudang melihat aset kantor pusat. Salah, tapi bukan bencana |

## Lapis pertama: tenant

Setiap tabel punya `tenant_id`, dan setiap kueri menyaringnya. Nilainya diambil dari token konteks yang dikirim Core — `$request->attributes->get('coreerp.tenant_id')` — **tidak pernah** dari isi permintaan.

### Lapis kedua di database

Penyaringan di kode saja tidak cukup, karena satu kueri yang lupa menyaring sudah membocorkan. Karena itu batasnya juga ditegakkan skema.

Setiap tabel punya kunci unik gabungan `(tenant_id, id)`, dan setiap foreign key menunjuk pasangan itu, bukan `id` saja:

```php
$table->foreign(['tenant_id', 'maintenance_job_type_id'])
      ->references(['tenant_id', 'id'])->on('m_maintenance_job_type');
```

Akibatnya baris milik tenant A **secara struktural tidak bisa** menunjuk induk milik tenant B. Yang menolak database, bukan kode aplikasi — jadi kelupaan di satu controller tidak berubah jadi kebocoran data.

Ini pola wajib untuk tabel baru. Rinciannya di [Database dan migration](/apps/management-aset/arsitektur/database).

## Lapis kedua: tanggung jawab organisasi

Punya permission belum berarti boleh melihat semua aset. Penyaringan keduanya lewat `OrganizationScope`, memakai kebijakan `management-aset.asset-responsibility`.

Cara kerjanya:

1. Core menentukan badan hukum dan unit kerja mana yang boleh diakses pengguna, berdasarkan penugasan role dan hierarki organisasi.
2. Daftar itu dikirim di dalam token konteks yang sudah ditandatangani.
3. App menyaring kueri berdasarkan `legal_entity_id` dan `responsible_org_unit_id` pada aset.

Jadi dua orang dengan permission yang sama persis tetap bisa melihat daftar aset yang berbeda.

### Yang penting saat menulis endpoint baru

**Jangan pernah percaya id organisasi yang datang dari browser.** Pilihan di layar hanya untuk mengisi nilai awal atau menyaring tampilan — bukan bukti hak akses. Pemeriksaannya selalu terhadap daftar di token.

**Pakai `OrganizationScope::assetQuery()`, bukan `where('tenant_id')` saja.** Kueri yang hanya menyaring tenant akan menampilkan aset milik unit yang tidak menjadi tanggung jawab pemanggil.

**Penulisan diperiksa dengan `require()`.** Membuat atau memindahkan aset ke unit yang bukan tanggung jawab pemanggil ditolak.

## Pembagian tanggung jawab dengan Core

| Milik Core | Milik app ini |
| --- | --- |
| Katalog kebijakan | Penegakan pada tiap endpoint |
| Pemberian hak lewat penugasan role | Menyebut kolom mana yang mewakili badan hukum dan unit kerja |
| Penyelesaian hierarki dan versinya | — |
| Tanggal berlaku dan jejak audit | — |

App tidak pernah mengirim nama tabelnya ke Core, dan Core tidak pernah membaca database app. Yang dipertukarkan hanya kode kebijakan dan daftar hak di dalam token.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Support/OrganizationScope.php` | Penyaringan dan pemeriksaan hak |
| `api/app/Http/Middleware/` | Pembacaan dan verifikasi token konteks |
| `docs/rancangan-scope-data-aset.md` di repo app | Rancangan pemisahan data per organisasi |

## Halaman terkait

- [Identity dan access](/dev/09-identity-and-access) — model role, duty, privilege, permission di Core
- [Query scope dan schema](/dev/08-query-scopes-and-schema) — aturan platform
- [Register aset](/apps/management-aset/transaction/register-aset/) — pemakai utama
