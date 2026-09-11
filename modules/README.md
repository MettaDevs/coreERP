# Modules

Satu module adalah satu folder di `modules/<publisher>/<module>/`. Ia **berjalan di runtime Core** dan
memakai database tenant yang sama: punya API, UI, migration, dan contract sendiri, tetapi tidak punya
container, database, maupun token layanan sendiri.

Ini berbeda dari app di repo `app-erp-*`, yang sampai hari ini masih punya container dan database
sendiri. Perbedaan lengkapnya ada pada **Dua bentuk module yang hidup berdampingan** di
[AGENTS.md](../AGENTS.md); aturan lengkapnya pada [standar module](../docs/dev/02-module-standard.md).

## Bentuk folder

```text
modules/
└─ apperp/                        # publisher
   └─ management-aset/            # module
      ├─ app.yaml                 # manifest: entry point, permission, privilege, duty, nomor, workflow
      ├─ composer.json            # package lokal, autoload PSR-4 untuk namespace module
      ├─ src/                     # PHP: Http/, Models/, Services/, Actions/, Providers/
      ├─ database/migrations/     # migration module saja
      ├─ routes/                  # web.php dan api.php, dimuat penyedia layanan module
      ├─ ui/                      # halaman React, di-import build shell Core
      ├─ tests/                   # Feature/ dan Unit/
      └─ contracts/               # hanya bila ada permukaan yang dipanggil dari luar runtime
```

Tidak ada folder lain di akar module. Yang tidak boleh ada, beserta sebabnya:

| Yang dilarang | Sebab |
| --- | --- |
| `bootstrap/`, `public/`, `artisan` | module bukan aplikasi Laravel; ia dimuat runtime Core |
| `config/app.php` | daftar provider dan alias dimiliki Core |
| `Dockerfile`, `compose.yaml`, `nginx.conf` | module tidak punya container |
| `.env`, `.env.example` | module tidak punya proses sendiri, jadi tidak punya lingkungan sendiri |
| `vendor/` | dependency diselesaikan sekali di akar repo |

Kunci `api.image` dan `ui.image` pada `app.yaml` masih ada dan dibiarkan apa adanya sampai bentuk rilis
diganti pada F5-05. Keduanya tidak dibaca runtime.

## Halaman module

Halaman React module berada di `ui/Pages/` dan ikut build shell Core. Tidak ada iframe, tidak ada
aplikasi React kedua, dan tidak ada token yang dipertukarkan lebih dulu.

```text
modules/apperp/contoh-a/ui/Pages/Daftar.tsx
```

Controller module merendernya seperti halaman Inertia biasa, dengan nama berbentuk
`<id module>::<nama berkas>`:

```php
return Inertia::render('contoh-a::Daftar', ['barang' => $barang]);
```

Penerbit tidak ikut disebut; pemilih halaman pada `apps/core/resources/js/app.tsx` mencocokkan
akhiran jalurnya, dan id module sudah unik di seluruh runtime.

Tiga hal yang mengikat:

- **Halaman module hanya mengimpor `@apperp/ui`, React, dan berkasnya sendiri.** Impor `@/...` milik
  shell akan berhasil dibangun — folder ini ikut build yang sama — dan justru itu bahayanya: module-nya
  pecah begitu dipasang di runtime yang shell-nya berbeda.
- **Id entri menu pada `app.yaml` adalah jalur rutenya.** Core menyusun tautan sidebar dengan aturan
  `/<id module>/<id entri menu>`, jadi berkas rute module wajib punya rute dengan jalur itu.
- **Halaman module dimuat malas.** Tuan rumahnya di
  `apps/core/resources/js/pages/modules/host.tsx` memasang pembatas penangguhan dan pembatas
  kesalahan; jangan menghapus salah satunya.

Berkas `ui/` diperiksa Prettier lewat `npm run format:check` di `apps/core`, dengan
`--config .prettierrc` yang ditulis eksplisit: Prettier mencari konfigurasi dengan menaiki folder dari
berkas yang diperiksa, dan di atas `modules/` tidak ada satu pun. ESLint **belum** mencakup folder ini —
ia menolak berkas di luar folder konfigurasinya — jadi berkas `ui/` untuk sementara hanya dijaga
Prettier dan `tsc`.

## Namespace dan awalan tabel

Setiap module memakai satu namespace dan satu awalan tabel, dan keduanya diturunkan dari nama foldernya:

| Folder | Namespace PHP | Awalan tabel |
| --- | --- | --- |
| `apperp/contoh-a` | `Modules\Apperp\ContohA\` | `contoh_a_` |
| `apperp/contoh-b` | `Modules\Apperp\ContohB\` | `contoh_b_` |
| `apperp/human-resources` | `Modules\Apperp\HumanResources\` | `hr_` |
| `apperp/management-aset` | `Modules\Apperp\ManagementAset\` | `aset_` |

Namespace mengikuti `StudlyCase` dari nama folder. Awalan tabel **tidak** selalu sama dengan nama folder:
ia dipilih pendek dan tidak berubah setelah module pertama kali dipasang, karena mengubahnya berarti
mengganti nama tabel di setiap instalasi pelanggan. Awalan baru dicatat di tabel ini pada pull request
yang membuat module-nya, supaya tabrakan ketahuan saat peninjauan, bukan saat migrasi jalan.

Tabel ini memuat module yang **ada hari ini**, tidak lebih dan tidak kurang, dan itu dijaga
`SusunanManifestModulTest` di `apps/core/tests/Feature/Boundary/`. Baris yang kurang membuat
awalan yang sudah dipakai tampak masih bebas; baris untuk module yang belum ada membuat awalan yang
masih bebas tampak sudah terpakai. Keduanya menyesatkan orang berikutnya, dan sebelum penjaga itu ada,
tidak ada satu pun test yang gagal karenanya.

Awalan `hr_` dicadangkan untuk `apperp/human-resources`, module kedua yang mendarat pada F7-01. Ia
ditulis sebagai kalimat, bukan sebagai baris tabel, sampai foldernya benar-benar ada.

Konvensi `m_`, `tr_`, dan `tr_*_details` pada [standar app](../docs/dev/02-module-standard.md) tetap
berlaku; awalan module ditulis di depannya, misalnya `aset_m_group` dan `aset_tr_penerimaan`.

## Aturan yang berlaku di dalam setiap module

- **Setiap tabel membawa `tenant_id`, dan setiap query menyaringnya.** Satu query yang lupa menyaring
  membocorkan data seluruh tenant, karena tidak ada lagi database terpisah yang menahannya.
- **Module tidak menyentuh tabel module lain.** Pada app lama ini dijaga database terpisah; di sini
  dijaga test dan analisa statis. Sama-sama batas, dan pelanggarnya sama-sama ditolak.
- **Tidak ada baris yang dihapus fisik.** Lihat [penghapusan lunak](../docs/dev/02-module-standard.md#penghapusan-lunak),
  termasuk kewajiban indeks unik parsial pada kode bisnis.
- **Module memanggil Core lewat pemanggilan fungsi biasa,** bukan HTTP, dan hanya lewat satu namespace:
  `App\Support\Modules\Contracts`. Isinya enam antarmuka layanan, trait `MilikTenant` untuk model, dan
  kelas induk `SeederModule` untuk data awal. Butuh sesuatu yang belum ada di sana? Usulkan antarmuka
  baru; jangan mengambil jalan pintas ke kelas Core, karena kelas Core bebas berubah bentuk dan module
  akan ikut pecah tanpa peringatan.

## Module contoh tidak pernah sampai ke pelanggan

`contoh-a` dan `contoh-b` ada sebagai bahan uji penjaga batas dan hidup sampai fase 7. Keduanya menandai
dirinya `kind: internal-fixture` pada `app.yaml`.

Module ber-`kind: internal-fixture` **tidak boleh** masuk ke edisi pelanggan mana pun. Pembangun bundle
menolaknya, dan penolakan itu diuji — bukan sekadar diingat. Sebuah menu bernama "Contoh A" yang muncul
di layar pelanggan adalah kegagalan yang tidak boleh mungkin terjadi.

## Rujukan

[Standar app](../docs/dev/02-module-standard.md) ·
[API dan integration](../docs/dev/04-api-and-integration.md) ·
[Scope data tenant dan org unit](../docs/dev/08-query-scopes-and-schema.md)
