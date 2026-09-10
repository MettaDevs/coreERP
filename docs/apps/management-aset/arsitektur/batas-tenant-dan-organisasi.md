# Batas tenant dan organisasi

Halaman ini untuk developer. Isinya dua lapis penyaringan yang berlaku di seluruh modul, dan kenapa keduanya diperlukan.

Sering tertukar, padahal berbeda:

| Lapis | Menjawab | Kalau bocor |
| --- | --- | --- |
| **Tenant** | Data ini milik perusahaan mana? | Perusahaan lain melihat data Anda. Fatal |
| **Organisasi** | Di dalam perusahaan itu, siapa yang boleh melihatnya? | Orang gudang melihat aset kantor pusat. Salah, tapi bukan bencana |

## Lapis pertama: tenant

Setiap tabel punya `tenant_id`, dan setiap kueri menyaringnya. Nilainya datang dari middleware
konteks modul, **tidak pernah** dari isi permintaan.

### Trait yang menyaring, bukan penyaringan yang ditulis ulang

Model modul memakai trait `MilikTenant` dan **tidak menulis penyaringan tenant sendiri**:

```php
use App\Support\Modules\Contracts\MilikTenant;

final class Asset extends Model
{
    use MilikTenant;
}
```

Trait itu melakukan tiga hal, dan ketiganya perlu:

| Kejadian | Yang dilakukan |
| --- | --- |
| membaca | menyaring ke tenant aktif; tanpa tenant aktif query **dibatalkan**, bukan dijalankan tanpa saringan |
| menyimpan baris baru | mengisi `tenant_id` dari tenant aktif bila modul tidak menuliskannya |
| menyimpan dengan `tenant_id` berbeda | membatalkan penyimpanan |

**Jangan menyaring `tenant_id` dengan tangan pada model yang sudah memakai trait ini.** Bukan karena
berlebihan, tetapi karena query seperti itu tetap benar walau traitnya dicabut — sehingga
penjaganya berhenti terukur, dan tidak ada test yang gagal ketika perlindungannya hilang.

Penjagaan ini hidup di lapisan model. `DB::table()` melewatinya sepenuhnya, dan itulah sebabnya
query mentah pada tabel modul dilarang.

### Dari mana nilai itu datang

Middleware `konteks-module:management-aset` mengisi konteks permintaan, lalu modul membacanya lewat
dua kontrak Core:

| Kontrak | Menjawab |
| --- | --- |
| `KonteksTenant` | Tenant, badan hukum, dan unit kerja yang sedang dipilih — **tempat** query boleh membaca |
| `KonteksPermintaan` | Id pengguna, permission efektif, dan lingkup kebijakan data — **apa** yang boleh dilakukan di tempat itu |

Dua pertanyaan berbeda, dua pintu berbeda, dan tidak ada satu pun jawaban yang punya dua sumber.
Keduanya gagal menutup: tanpa konteks, jawabannya `false` atau sebuah lemparan, tidak pernah sebuah
tebakan.

Kalau Anda menemukan kode yang mengambil `tenant_id` dari isi permintaan, itu cacat keamanan, bukan sekadar gaya penulisan yang berbeda.

### Lapis kedua di database

Penyaringan di kode saja tidak cukup, karena satu kueri yang lupa menyaring sudah membocorkan. Karena itu batasnya juga ditegakkan skema.

Setiap tabel punya kunci unik gabungan `(tenant_id, id)`, dan setiap foreign key menunjuk pasangan itu, bukan `id` saja:

```php
$table->foreign(['tenant_id', 'maintenance_job_type_id'])
      ->references(['tenant_id', 'id'])->on('aset_m_maintenance_job_type');
```

Akibatnya baris milik tenant A **secara struktural tidak bisa** menunjuk induk milik tenant B. Yang menolak database, bukan kode aplikasi — jadi kelupaan di satu controller tidak berubah jadi kebocoran data.

Ini pola wajib untuk tabel baru. Rinciannya di [Database dan migration](/apps/management-aset/arsitektur/database).

## Lapis kedua: tanggung jawab organisasi

Punya permission belum berarti boleh melihat semua aset. Penyaringan keduanya lewat `OrganizationScope`, memakai kebijakan `management-aset.asset-responsibility`.

Cara kerjanya:

1. Core menentukan badan hukum dan unit kerja mana yang boleh diakses pengguna, berdasarkan penugasan role dan hierarki organisasi.
2. Daftar itu tersedia lewat `KonteksPermintaan::kebijakanData()`, sebagai data biasa — modul tidak pernah menerima objek Core.
3. Modul menyaring kueri berdasarkan `legal_entity_id` dan `responsible_org_unit_id` pada aset.

Jadi dua orang dengan permission yang sama persis tetap bisa melihat daftar aset yang berbeda.

### Yang penting saat menulis endpoint baru

**Jangan pernah percaya id organisasi yang datang dari browser.** Pilihan di layar hanya untuk mengisi nilai awal atau menyaring tampilan — bukan bukti hak akses. Pemeriksaannya selalu terhadap daftar di konteks permintaan.

**Pakai `OrganizationScope::assetQuery()`, bukan `where('tenant_id')` saja.** Kueri yang hanya menyaring tenant akan menampilkan aset milik unit yang tidak menjadi tanggung jawab pemanggil.

**Penulisan diperiksa dengan `require()`.** Membuat atau memindahkan aset ke unit yang bukan tanggung jawab pemanggil ditolak.

## Pembagian tanggung jawab dengan Core

| Milik Core | Milik app ini |
| --- | --- |
| Katalog kebijakan | Penegakan pada tiap endpoint |
| Pemberian hak lewat penugasan role | Menyebut kolom mana yang mewakili badan hukum dan unit kerja |
| Penyelesaian hierarki dan versinya | — |
| Tanggal berlaku dan jejak audit | — |

Modul tidak pernah mengirim nama tabelnya ke Core, dan Core tidak pernah menyentuh tabel modul.
Yang dipertukarkan hanya kode kebijakan dan daftar hak.

Batas itu tidak berubah karena keduanya kini satu proses dan satu database. Yang berubah adalah
siapa yang menegakkannya: dulu database terpisah, sekarang penjaga batas di
`apps/control-plane/tests/Feature/Boundary/`.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `modules/apperp/management-aset/src/Support/OrganizationScope.php` | Penyaringan dan pemeriksaan hak |
| `apps/control-plane/app/Support/Modules/Contracts/KonteksTenant.php`, `KonteksPermintaan.php` | Pintu tempat modul membaca konteksnya |
| `apps/control-plane/app/Support/Modules/TenantScope.php` | Penegakan `MilikTenant` pada sisi baca dan sisi tulis |
| [Rancangan scope data aset](/apps/management-aset/arsitektur/rancangan-scope-data-aset) | Rancangan pemisahan data per organisasi |

## Halaman terkait

- [Identity dan access](/dev/09-identity-and-access) — model role, duty, privilege, permission di Core
- [Query scope dan schema](/dev/08-query-scopes-and-schema) — aturan platform
- [Register aset](/apps/management-aset/transaction/register-aset/) — pemakai utama
