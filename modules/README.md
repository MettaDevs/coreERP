# Modules

Satu module adalah satu folder di `modules/<publisher>/<module>/`. Ia **berjalan di runtime Core** dan
memakai database tenant yang sama: punya API, UI, migration, dan contract sendiri, tetapi tidak punya
container, database, maupun token layanan sendiri.

Ini berbeda dari app di repo `app-erp-*`, yang sampai hari ini masih punya container dan database
sendiri. Perbedaan lengkapnya ada pada **Dua bentuk module yang hidup berdampingan** di
[AGENTS.md](../AGENTS.md); alasannya pada [keputusan satu runtime](../docs/todo/satu-runtime/00-keputusan.md).

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

## Namespace dan awalan tabel

Setiap module memakai satu namespace dan satu awalan tabel, dan keduanya diturunkan dari nama foldernya:

| Folder | Namespace PHP | Awalan tabel |
| --- | --- | --- |
| `apperp/management-aset` | `Modules\Apperp\ManagementAset\` | `aset_` |
| `apperp/human-resources` | `Modules\Apperp\HumanResources\` | `hr_` |
| `apperp/contoh-a` | `Modules\Apperp\ContohA\` | `contoh_a_` |

Namespace mengikuti `StudlyCase` dari nama folder. Awalan tabel **tidak** selalu sama dengan nama folder:
ia dipilih pendek dan tidak berubah setelah module pertama kali dipasang, karena mengubahnya berarti
mengganti nama tabel di setiap instalasi pelanggan. Awalan baru dicatat di tabel ini pada pull request
yang membuat module-nya, supaya tabrakan ketahuan saat peninjauan, bukan saat migrasi jalan.

Konvensi `m_`, `tr_`, dan `tr_*_details` pada [standar app](../docs/dev/02-module-standard.md) tetap
berlaku; awalan module ditulis di depannya, misalnya `aset_m_group` dan `aset_tr_penerimaan`.

## Aturan yang berlaku di dalam setiap module

- **Setiap tabel membawa `tenant_id`, dan setiap query menyaringnya.** Satu query yang lupa menyaring
  membocorkan data seluruh tenant, karena tidak ada lagi database terpisah yang menahannya.
- **Module tidak menyentuh tabel module lain.** Pada app lama ini dijaga database terpisah; di sini
  dijaga test dan analisa statis. Sama-sama batas, dan pelanggarnya sama-sama ditolak.
- **Tidak ada baris yang dihapus fisik.** Lihat [penghapusan lunak](../docs/dev/02-module-standard.md#penghapusan-lunak),
  termasuk kewajiban indeks unik parsial pada kode bisnis.
- **Module memanggil Core lewat pemanggilan fungsi biasa,** bukan HTTP. Kelas Core yang boleh dipanggil
  didaftar pada bagian 5.3 [PRD pemindahan](../docs/todo/satu-runtime/01-prd.md).

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
