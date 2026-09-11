# Standar app dan addon app

Dokumen ini memakai istilah **app** untuk produk atau kemampuan bisnis yang dapat dijual, dipasang,
dan dicabut sendiri, dan **module** untuk bentuk teknis yang menjalankannya di dalam runtime Core.

## Bentuk yang berlaku untuk pekerjaan baru: module di dalam repo Core

Satu module adalah satu folder di `modules/<penerbit>/<module>/` di dalam repo ini. Ia berjalan di
runtime Core dan memakai database tenant yang sama: punya rute, halaman, migration, dan manifest
sendiri, tetapi **tidak** punya container, database, maupun token layanan sendiri.

```text
modules/
└─ apperp/                        # penerbit
   └─ management-aset/            # module
      ├─ app.yaml                 # manifest: entry point, permission, privilege, duty, nomor, workflow
      ├─ composer.json            # package lokal, autoload PSR-4 untuk namespace module
      ├─ src/                     # PHP: Http/, Models/, Services/, Listeners/, Reporting/
      ├─ database/migrations/     # migration module saja
      ├─ routes/                  # web.php dan api.php, dimuat penyedia layanan module
      ├─ ui/                      # halaman React, di-import build shell Core
      ├─ tests/                   # Feature/ dan Unit/
      └─ contracts/               # hanya bila ada permukaan yang dipanggil dari luar runtime
```

Yang **tidak boleh ada** di akar module, beserta sebabnya: `bootstrap/`, `public/`, dan `artisan`
karena module bukan aplikasi Laravel; `config/app.php` karena daftar provider dan alias dimiliki
Core; `Dockerfile`, `compose.yaml`, dan `nginx.conf` karena module tidak punya container; `.env`
karena module tidak punya proses sendiri; `vendor/` karena dependency diselesaikan sekali di akar
repo. Daftar berjalannya ada di `modules/README.md`.

Halaman module ikut build shell Core. Tidak ada iframe dan tidak ada aplikasi React kedua; module
merender halaman Inertia dengan nama `<id module>::<nama berkas>`, dan Core menyusun tautan sidebar
dengan aturan `/<id module>/<id entri menu>` dari manifest yang sama.

## Bentuk lama: app dengan repository dan container sendiri

**Bentuk ini sudah tidak berlaku.** Ia dicatat di sini supaya sebuah repo `app-erp-*` lama yang
ditemukan orang berikutnya dapat dikenali, bukan supaya ia dipakai lagi. Sejak 10 September 2026
tidak ada satu pun app yang berjalan sebagai container tersendiri, dan seluruh kode yang melayaninya
— penempatan app, pendaftaran rilis penyedia, path konten, reverse proxy, halaman tuan rumah
beriframe, dan token konteks app — dibuang dari repo ini.

Bentuknya dulu: satu repository per app bisnis, memuat `app.yaml`, service Laravel di `api/`, UI
React di `ui/`, migration, contract, dan fragment Compose di `deploy/`. Setiap app menghasilkan
artifact terpisah — image API, image UI, migration, contract, dan manifest — dan UI-nya disajikan di
dalam iframe pada path `/apps-content/<placement>/<app-id>/`.

Yang menggantikannya adalah folder module di `modules/<penerbit>/<module>/`, dijelaskan pada sisa
halaman ini.

Repository CoreERP ini menampung `apps/control-plane`, `apps/provider-console`, dan seluruh module
di bawah `modules/`. Surface Web Shell — launcher dan kerangka layar module — hidup di dalam UI
Control Plane, bukan folder tersendiri.

## Contoh manifest

```yaml
id: accounting
publisher: apperp
version: 1.0.0
kind: business-app
requires:
  core: ^1.0
dependsOn:
  business-partner: ^1.0
api:
  image: registry.apperp.local/apps/accounting-api:1.0.0
  openapi: contracts/openapi.yaml
ui:
  image: registry.apperp.local/apps/accounting-ui:1.0.0
  entry: /apps/accounting/
  navigation:
    rail:
      - id: jurnal
        label: Jurnal
    sidebar:
      jurnal:
        - id: jurnal-umum
          label: Jurnal umum
          permission: accounting.jurnal.read
database:
  logical_name: app_erp_accounting
  migrations: database/migrations
events:
  asyncapi: contracts/asyncapi.yaml
workflow_types:
  - code: accounting.jurnal-verification
    name: Verifikasi jurnal
    decision_context_schema:
      required: [document_id]
security:
  data_policies:
    - code: accounting.jurnal-responsibility
      name: Akses jurnal menurut entitas legal
      protected_permissions:
        - accounting.jurnal.read
        - accounting.jurnal.create
  entry_points:
    - code: accounting.jurnal.form
      name: Layar jurnal
      type: form
    - code: accounting.jurnal.api
      name: API jurnal
      type: api
  permissions:
    - code: accounting.jurnal.read
      name: Lihat jurnal
      entry_point: accounting.jurnal.form
      access: read
    - code: accounting.jurnal.create
      name: Buat jurnal
      entry_point: accounting.jurnal.api
      access: create
  privileges:
    - code: accounting.jurnal.maintain
      name: Pelihara jurnal
      permissions:
        - accounting.jurnal.read
        - accounting.jurnal.create
  duties:
    - code: accounting.jurnal.manage
      name: Kelola jurnal
      privileges:
        - accounting.jurnal.maintain
number_sequences:
  references:
    - code: accounting.jurnal
      name: Nomor jurnal
      default_prefix: JRNL
      allowed_scopes:
        - legal_entity
data_retention: archive
```

Manifest mendaftarkan metadata keamanan kanonik sampai duty. Security role, user assignment, dan organization scope dibuat pada tenant; ketiganya bukan bagian dari manifest app dan tidak dibatasi ke satu app.

### Blok manifest dan apa yang dipicunya

| Blok | Wajib? | Yang terjadi di Core setelah registrasi |
| --- | --- | --- |
| `api`, `ui`, `database`, `events` | Ya untuk app yang berjalan sebagai container sendiri | Katalog mengenal artifact, database logis, dan kontrak app. `database.logical_name` hanya wajib bagi app container: module berjalan di dalam runtime Core dan memakai database Core, jadi ia tidak punya nama database sendiri untuk disebutkan dan katalog menyimpannya sebagai kosong |
| `ui.navigation` | Ya | Menu app muncul di shell Core. Item menu hanya boleh memakai permission `read` milik app yang sama |
| `security.entry_points` / `permissions` / `privileges` / `duties` | Ya, keempatnya | Duty tersedia untuk disusun admin tenant menjadi security role |
| `security.data_policies` | Hanya bila resource perlu dibatasi organisasi | Muncul sebagai batas data saat admin memberi role ke anggota |
| `dependsOn` | Tidak, bila app berdiri sendiri | Dependency disimpan dengan rentang versi. Core menolak app yang belum ada, versi yang tidak cocok, dan cycle. Saat onboarding, prerequisite transitif ikut menjadi entitlement serta dipasang lebih dulu. |
| `number_sequences.references` | Hanya bila app menerbitkan nomor | Reference muncul di layar **Nomor dokumen** Core (`settings/number-sequences`) untuk diaktifkan dan diatur admin tenant |
| `workflow_types` | Hanya bila ada approval atau verifikasi | Tipe workflow tersedia untuk dikonfigurasi admin tenant |
| `reports` | Hanya bila module punya dokumen cetak atau ekspor | Laporan muncul di katalog Core; admin tenant mengatur layoutnya di **Layout laporan**, pengguna mencetak lewat dialog Shell. Datasetnya tetap milik module, diserahkan lewat kontrak `PenyediaLaporanModul` di dalam proses. Lihat [dokumen cetak](23-document-rendering.md) |

Module tidak menerbitkan nomornya sendiri. Setelah reference terdaftar dan admin mengaktifkannya, module meminta nomor lewat kontrak `PenerbitNomor` di dalam proses yang sama. Addon pihak ketiga di luar runtime memakai API internal Core `POST /api/internal/v1/number-sequences/{reference}/issue` atau `/reserve`; `idempotency_key` wajib pada keduanya. Detailnya di [Number sequence](14-number-sequences.md).

### Dependency app

`dependsOn` adalah map dari ID app ke rentang versi, bukan daftar nama produk dan
bukan `docker-compose depends_on`. Bentuk yang diterima saat ini adalah versi
tepat, misalnya `1.2.3`, atau rentang caret `^1.2` / `^1.2.3`. Untuk app tanpa
dependency, pakai `{}`. Nilai `[]` lama masih diterima agar manifest placeholder
tidak rusak, tetapi tidak boleh dipakai untuk mendaftarkan dependency baru.

Saat registrasi katalog, target dependency harus sudah berstatus `available` dan
versi katalog saat itu harus memenuhi rentang yang dideklarasikan. Core menyimpan
setiap relasi di `app_dependencies`, menolak self-reference dan cycle transitif,
serta menolak pembaruan versi yang akan melanggar rentang app lain yang bergantung
padanya. Katalog tidak menerima dependency yang belum terdaftar karena tidak ada
urutan instalasi yang dapat diverifikasi untuk target yang belum dikenal.

Saat tenant memilih produk, Core menutup seluruh dependency transitif secara
otomatis: entitlement prerequisite dibuat bersama entitlement produk pilihan dan
job placement-nya dijadwalkan lebih dulu. Worker juga menahan app turunan sampai
semua prerequisite `ready` pada placement yang sama. Ini mekanisme teknis; layar
penjualan harus menerangkan prerequisite sebagai bagian dari paket, bukan meminta
pembeli mencari atau membeli app teknis satu per satu.

Contoh manifest utuh yang sudah berjalan ada di `modules/apperp/management-aset/app.yaml`; blok `security`-nya jauh lebih panjang dari contoh di atas, yang sengaja dipersingkat.

::: tip Mencari langkah mengerjakannya?
Halaman ini menetapkan **aturannya**. Urutan mengerjakan beserta persiapan teknis, konvensi penamaan, berkas yang wajib ada, dan gate per tahap ada di [jalur membangun modul baru](../apps/membangun-app-baru.md).
:::

## Empat lapis keamanan tidak boleh diringkas

Manifest wajib mendeklarasikan keempat lapis secara terpisah, mengikuti [role-based security Dynamics 365](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/role-based-security):

| Lapis | Arti | Aturan kode |
| --- | --- | --- |
| Entry point | Yang dilindungi: form, menu item, API/service operation, report, atau action. | `<app>.<resource>.<form\|api>` |
| Permission | Pasangan entry point + access level. | `<app>.<resource>.<aksi>` |
| Privilege | Satu tugas; kumpulan permission. | `<app>.<resource>.<tugas>` |
| Duty | Bagian proses bisnis; kumpulan privilege. | `<app>.<resource>.<proses>` |

`type` entry point: `form`, `menu_item`, `api`, `report`, `action`.

`access` permission memakai access level Dynamics 365: `read`, `update`, `create`, `correct`, `delete`, `invoke`. Aksi lifecycle penghapusan CoreERP (`archive`, `void`, `retire`) memakai `delete`; service operation tanpa CRUD memakai `invoke`.

Kode privilege tidak boleh sama dengan kode permission. Tanpa aturan ini, manifest dapat memakai satu kode untuk dua lapis dan rantai `duty → privilege → permission` berubah menjadi satu lapis bersalin tiga. Core menolak manifest semacam itu.

Nama key manifest sama persis dengan payload API katalog Core, sehingga `app.yaml` dapat dikirim apa adanya tanpa lapisan transformasi.

## Ownership dan data

Setiap app memiliki owner yang bertanggung jawab atas code review, contract, data, release, rollback, dan incident app tersebut.

**Module** memakai database tenant yang sama dengan Core. Pemisahnya adalah **awalan nama tabel**
yang diturunkan dari nama folder module, misalnya `aset_` dan `hr_`. Awalan itu didaftarkan pada
katalog dan diperiksa penjaga batas di `apps/control-plane/tests/Feature/Boundary/`: tabel tanpa
awalan yang benar, dan tabel milik module lain yang disentuh, ditolak sebelum pull request digabung.

Addon pihak ketiga yang berjalan di luar runtime ini memakai database sendiri dengan pola
`addon_<publisher>_<app>`, dengan database user dan secret sendiri; tidak ada foreign key, Eloquent
relation, atau query langsung lintas database.

Larangannya tetap: sebuah module tidak boleh membaca atau menulis data milik module lain. Yang
menolak adalah penjaga batas dan analisa statis.

Perbedaan itu harus disebut apa adanya. **Batas antar module ditegakkan pemeriksaan otomatis, bukan
mesin database.** Jangan menuliskan bahwa mesin database yang menjaganya: kalimat itu membuat
pembaca berikutnya menganggap sebuah `JOIN` lintas module akan ditolak PostgreSQL, padahal ia akan
berjalan mulus sampai seseorang menjalankan penjaganya.

Tiga aturan yang mengikuti dari satu database bersama:

- **Foreign key dari tabel module hanya boleh menunjuk tabel milik Core**, tidak pernah ke tabel
  module lain. Foreign key lintas module membuat dua module tidak bisa dipasang atau dicabut
  sendiri-sendiri, dan itu justru yang sedang dihindari.
- **Runtime memakai satu koneksi database.** Module menulis tabelnya sendiri lewat koneksi yang sama
  dengan Core. Schema PostgreSQL per module dan peran database per module pernah dipertimbangkan dan
  dibatalkan: keduanya menambah bagian yang harus disiapkan admin pelanggan tanpa menambah satu pun
  batas yang tidak sudah dijaga penjaga di atas.
- **Laporan lintas tenant tidak ada.** Konsolidasi terjadi **di dalam** satu tenant, lewat legal
  entity dan operating unit. Jangan merancang federasi database untuk kebutuhan yang tidak ada.

### Nama tabel

Nama tabel memakai `snake_case` dan menyatakan jenis data, bukan nama layar atau
nama controller.

Untuk module, **awalan module wajib dan itulah yang diperiksa mesin**; sisa namanya mengikuti
konvensi di bawah. Awalan yang berlaku dinyatakan module pada `app.yaml`-nya, dan tercatat di
katalog Core setelah registrasi.

Empat hal tentang awalan itu yang menghemat banyak waktu bila diketahui lebih dulu:

- **Ia dipilih pendek, dan boleh berbeda dari nama folder.** Folder `management-aset` memakai awalan
  `aset_`. Nama tabel dibaca berkali-kali sehari oleh orang yang sedang menelusuri masalah; awalan
  sepanjang nama folder membuat setiap nama tabel lebih panjang tanpa menambah satu pun kejelasan.
- **Ia tidak boleh berubah setelah module pertama kali dipasang di tempat pelanggan.** Mengubahnya
  berarti mengganti nama seluruh tabel pada setiap server pelanggan lewat migration, dan migration
  yang sudah pernah berjalan tidak disunting.
- **Pemetaan namespace ke awalan didaftarkan di `modules/README.md` pada pull request yang membuat
  module itu**, bukan sesudahnya. Tabrakan awalan hanya murah kalau ketahuan saat peninjauan.
- **Pemeriksanya menguji saling-menelan, bukan hanya kesamaan persis.** Awalan `aset_` dan
  `aset_lama_` bukan awalan yang sama, tetapi tabel `aset_lama_barang` cocok dengan keduanya, dan
  penjaga yang hanya membandingkan kesamaan persis akan melewatkannya.

Tabel lama yang lahir tanpa awalan diganti nama **satu kali**, saat module-nya dipindah masuk.

Bentuk yang dipakai sesudah awalan:

| Bentuk | Dipakai untuk | Contoh |
| --- | --- | --- |
| `m_<resource>` | Master, reference, setup, konfigurasi, atau tabel relasi milik master yang bukan fakta transaksi mandiri. | `aset_m_group_aset`, `aset_m_jenis_aset_atribut` |
| `tr_<transaction>` | Header atau fakta transaksi mandiri. | `aset_tr_penerimaan_aset` |
| `tr_<transaction>_details` | Baris/detail yang selalu dimiliki satu header transaksi. Bentuk ini selalu jamak: `_details`, bukan `_detail`. | `aset_tr_perencanaan_aset_details` |
| `tr_<aggregate>_<record>` | Catatan transaksi turunan yang bukan daftar baris header, misalnya nilai atribut, log, atau fakta operasional lain milik aggregate transaksi. | `aset_tr_aset_atribut` |

`m_` bukan berarti setiap tabelnya adalah master yang mendapat menu, permission,
atau Number Sequence sendiri. Tabel konfigurasi dan relasi—misalnya
`aset_m_jenis_aset_atribut`—tetap memakai `m_` bila ia bukan fakta transaksi mandiri.
Sebaliknya, tabel `tr_` harus menyimpan fakta proses bisnis; jangan memakai `tr_`
untuk sekadar cache atau data tampilan.

Nama tidak memakai bentuk generik atau ambigu seperti `tbl_aset`, `aset_data`, atau
`transaction_aset`. Jika suatu tabel baru tidak cocok dengan empat bentuk di atas,
putusan naming-nya dibuat pada proposal app sebelum migration ditulis; jangan
menciptakan prefix baru diam-diam.

Di dalam kumpulan tabel miliknya sendiri, sebuah app boleh memakai transaksi, foreign key, dan desain tabel normal. Semua tabel tenant-scoped membawa `tenant_id`; data dengan konsekuensi hukum/akuntansi membawa `legal_entity_id`; data operasional membawa `org_unit_id` bila ownership terjadi pada operating unit. ID organisasi adalah reference opaque ke Organization service, bukan foreign key lintas database. Lihat [model tenant dan organisasi](01a-tenant-and-org-hierarchy.md).

### Penyaringan tenant

Model module memakai trait `MilikTenant` dan tidak menulis penyaringan tenant sendiri:

```php
use App\Support\Modules\Contracts\MilikTenant;

final class Barang extends Model
{
    use MilikTenant;
}
```

Trait itu melakukan tiga hal, dan ketiganya perlu:

| Kejadian | Yang dilakukan |
| --- | --- |
| membaca | menyaring `tenant_id` ke tenant aktif; tanpa tenant aktif query **dibatalkan**, bukan dijalankan tanpa saringan |
| menyimpan baris baru | mengisi `tenant_id` dari tenant aktif bila module tidak menuliskannya |
| menyimpan dengan `tenant_id` berbeda | membatalkan penyimpanan |

**Jangan menyaring `tenant_id` dengan tangan pada model yang sudah memakai trait ini.** Bukan karena
berlebihan, tetapi karena query seperti itu tetap benar walau traitnya dicabut — sehingga penjaganya
berhenti terukur, dan tidak ada test yang gagal ketika perlindungannya hilang.

Penjagaan ini hidup di lapisan model. `DB::table()` melewatinya sepenuhnya, dan itulah sebabnya query
mentah pada tabel module dilarang — `DB::table()`, `DB::select()`, dan `DB::statement()` sama saja.
Migration dikecualikan, karena ia memang menulis SQL langsung dan berjalan sebelum ada satu pun
tenant.

Kalau sebuah laporan memang menuntut SQL langsung, dua hal wajib dilakukan bersama: **saring tenant
secara eksplisit**, dan **daftarkan pengecualiannya di berkas test penjaganya**. Yang kedua sama
pentingnya dengan yang pertama. Pengecualian yang hidup di berkas konfigurasi tidak terlihat pada
diff pull request berikutnya; pengecualian yang hidup di berkas test muncul di depan mata peninjau
setiap kali daftarnya bertambah.

Larangan ini tidak boleh dilonggarkan diam-diam. Melonggarkannya berarti mengubah berkas test dan
menjelaskan alasannya pada pull request — bukan menambahkan satu baris `DB::table()` yang kebetulan
lolos.

Satu bentuk query yang juga dilarang: **memberi alias pada tabel utama**. Penyaringan tenant
disisipkan dengan nama tabel yang sebenarnya, jadi tabel utama yang beralias membuat penyaringannya
menunjuk nama yang tidak ada lagi di query itu. Tabel yang di-`join` tetap boleh beralias.

### Penghapusan lunak

Tidak ada baris yang dihapus fisik. Menghapus berarti mengisi `deleted_at`; baris itu tetap ada di
tabelnya. Ini berlaku untuk semua tabel, bukan hanya yang menyimpan data berkonsekuensi hukum.

Alasannya bukan sekadar kehati-hatian. Rekam medis elektronik wajib disimpan paling singkat 25 tahun
sejak kunjungan terakhir menurut Permenkes 24/2022, dan sebuah perintah hapus yang tersedia tetapi
"tidak boleh dipakai untuk modul tertentu" cepat atau lambat akan dipakai untuk modul yang lupa
menyatakan penguncinya. Perintah yang tidak ada tidak bisa salah dipakai.

Di layar, tindakan ini bernama **Arsipkan**, bukan Hapus. Permission-nya tetap ber-`access: delete`
karena itu memang hak yang diberikan.

#### Indeks unik pada kode bisnis wajib parsial

Indeks unik biasa ikut menghitung baris yang sudah diarsipkan. Akibatnya kode yang sudah dihapus tidak
pernah bisa dipakai lagi, dan pengguna melihat "kode sudah dipakai" untuk kode yang tidak muncul di
daftar mana pun. Ini gejala yang sangat sulit dilacak karena baris penyebabnya tidak terlihat.

Laravel belum memiliki pembungkus untuk indeks parsial, jadi ia ditulis sebagai SQL langsung:

```php
Schema::create('aset_m_group', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->ulid('tenant_id')->index();
    $table->string('kode', 50);
    $table->string('nama', 150);
    $table->softDeletes();
    $table->timestamps();
    // Jangan: $table->unique(['tenant_id', 'kode']);
});

DB::statement(
    'CREATE UNIQUE INDEX aset_m_group_tenant_kode_unique '.
    'ON aset_m_group (tenant_id, kode) WHERE deleted_at IS NULL'
);
```

`down()` membuangnya dengan `DROP INDEX aset_m_group_tenant_kode_unique`.

Aturannya: **setiap indeks unik yang memuat kolom kode bisnis pada tabel yang memiliki `deleted_at`
wajib parsial.** Indeks unik pada identitas teknis—`id`, `creation_key`, pasangan `tenant_id` dengan
`id`—tetap penuh, karena nilainya memang tidak boleh dipakai ulang oleh siapa pun.

Ini bukan kekhawatiran teoretis. Diukur 8 September 2026 pada PostgreSQL 16 yang dipakai stack lokal,
memakai `units_of_measure` yang memang sudah memiliki `deleted_at` dan indeks unik penuh: satuan
diarsipkan, hilang dari daftar, lalu kodenya tidak bisa dipakai lagi.

```
UPDATE 1
 terlihat_di_daftar
--------------------
                  0
ERROR:  duplicate key value violates unique constraint "units_of_measure_tenant_id_code_unique"
DETAIL:  Key (tenant_id, code)=(..., KG) already exists.
```

Indeks parsial memperbaikinya tanpa melonggarkan apa pun: kode yang sudah diarsipkan bisa dipakai
ulang, dan dua baris hidup dengan kode sama tetap ditolak dengan pesan yang sama.

Cara memeriksa apakah sebuah repo masih punya pasangan yang salah:

```bash
grep -rn "softDeletes()" database/migrations/    # tabel yang mengarsipkan
grep -rn "unique(\['tenant_id', 'kode'\]"      # indeks yang tidak boleh penuh
```

#### Penyaringan terjadi di lapisan model

Baris terarsip disaring satu kali di model, lewat trait `SoftDeletes` Laravel, bukan diulang pada setiap
query. Query yang menyaring sendiri boleh ada hanya bila ia memang tidak lewat model, misalnya laporan
yang menulis SQL langsung, dan pada kasus itu penyaringannya ditulis eksplisit.

Alasannya bukan kerapian. Penyaringan yang diulang harus benar di **setiap** tempat; yang terlewat satu
tempat memunculkan baris terarsip di satu layar saja, dan itu terbaca sebagai bug data, bukan bug query.

Setiap modul wajib punya satu test yang mengarsipkan satu baris lalu membuktikan baris itu tidak muncul
di daftar dan tidak bisa diambil lewat detail.

#### Mencabut modul tidak menyentuh data

Perintah pencabutan modul mengubah status pemasangan dan berhenti di situ. Ia tidak memiliki opsi
penghapusan data dalam bentuk apa pun. Memasang ulang modul yang sama pada tenant yang sama
mengembalikan datanya seperti sedia kala. Ini bentuk yang sama dengan Business Central, yang mencabut
ekstensi tanpa menyentuh datanya.

#### `aktif` bukan `deleted_at`

Keduanya sering tertukar dan artinya berbeda:

| Kolom | Arti | Muncul di daftar |
| --- | --- | --- |
| `aktif` | ada dan sah, tetapi sedang tidak dipakai untuk transaksi baru | ya, dengan penanda |
| `deleted_at` | dianggap tidak ada oleh pengguna | tidak |

Master yang tidak boleh dipakai lagi tetapi masih dirujuk transaksi lama memakai `aktif = false`,
bukan `deleted_at`.

#### Data tumbuh selamanya, dan itu diterima dengan sadar

Karena tidak ada yang dihapus, tabel hanya bertambah. Pemilik app memantau ukuran tabelnya sebagai
bagian dari tanggung jawab yang sudah disebut di atas, dan angkanya dilaporkan bersama hasil load test,
bukan ditunggu sampai ada yang mengeluh lambat.

Satu hal yang tidak selesai dengan penghapusan lunak: tenant yang berhenti berlangganan. Datanya tetap
ada tanpa batas waktu, dan jalan keluarnya—ekspor lengkap yang bisa dibaca sistem lain, atau serah
terima database—ditulis di kontrak sebelum pelanggan pergi, bukan sesudah.

## Bentuk folder dan pendaftaran module

### Yang ada di dalam folder module, dan yang dilarang ada

Satu module berisi `app.yaml`, `src/`, `database/migrations/`, `routes/`, `ui/`, `tests/`,
`contracts/`, dan `composer.json`. Bentuk minimalnya ada di `modules/_template/`, dan
`module:make` yang menyalinnya.

Yang **dilarang** ada di dalam folder module: `bootstrap/`, `public/`, `config/app.php`, Dockerfile,
berkas compose, dan `artisan`. Semuanya adalah kerangka aplikasi mandiri, dan module bukan aplikasi
mandiri — ia dimuat oleh satu aplikasi yang sudah punya kerangkanya sendiri. Larangan ini berlaku
untuk **semua** module tanpa kecuali, termasuk module yang sedang dipindah dari repo lain, karena
justru di sanalah kerangka lama paling mungkin ikut terbawa.

Satu lagi yang dilarang dan mudah lolos: **alur CI di dalam folder module**. GitHub tidak pernah
menjalankan berkas alur di luar `.github/workflows/` pada akar repo, jadi berkas seperti itu terlihat
seperti pemeriksaan yang berjalan padahal tidak pernah dijalankan siapa pun. Ia ikut mendarat lagi
setiap kali sebuah module ditarik masuk, jadi penjaganya perlu ada, bukan sekadar diingat.

Migration module juga tidak boleh membuat tabel milik Core — `users`, `jobs`, `cache`, dan
kerabatnya. Module yang membuat ulang tabel Core akan berhasil di mesinnya sendiri dan gagal di
server pelanggan yang tabelnya sudah ada.

### Namespace dan autoload

Namespace module berbentuk `Modules\<Penerbit>\<Modul>\`, dan setiap berkas PHP wajib
mendeklarasikan namespace yang sesuai jalur PSR-4-nya. Module **tidak boleh menyumbang kelas ke
namespace milik Core**: sebuah kelas di dalam `App\` yang berasal dari folder module akan lolos
setiap penjaga namespace, karena penjaganya memeriksa siapa yang disebut, bukan siapa yang menulis.

Module di-autoload lewat repositori Composer bertipe `path`, tiap module mendeklarasikan
`autoload.psr-4`-nya sendiri, dan Core memintanya dengan `@dev` — bukan `*`. Paket lokal yang diminta
dengan `*` akan dicari di packagist lebih dulu.

Namespace **test** module didaftarkan pada `autoload-dev` milik **Core**, bukan milik module.
Composer tidak memuat `autoload-dev` sebuah dependensi, jadi blok yang ditulis di `composer.json`
module tidak akan pernah dibaca. Kelas dasar test yang dipakai juga milik Core.

### Satu penyedia layanan per module

Rute, perintah artisan, listener, dan registry laporan module didaftarkan oleh penyedia layanan
module itu sendiri, bukan oleh satu penyedia pusat yang mengenal semua module. Penyedia pusat berarti
biaya menyalakan aplikasi tumbuh seiring jumlah module, dan setiap module baru menyentuh satu berkas
yang sama.

Konfigurasi module digabungkan dengan awalan `modules.<id module>`. Nol kunci konfigurasi module
berdiri di akar: kunci di akar akan bertabrakan dengan kunci Core pada hari namanya kebetulan sama,
dan yang kalah tidak memberi tahu siapa pun.

`resource_path()` tidak boleh dipakai untuk jalur berkas module. Ia menunjuk folder Core, jadi
pemanggilannya berhasil dan memulangkan jalur yang salah.

### Registry melewatkan manifest yang rusak

Manifest yang tidak terbaca **dilewati**, bukan menjatuhkan runtime. Pilihan itu benar — satu berkas
salah tulis tidak boleh mematikan seluruh aplikasi di tempat pelanggan — tetapi ia punya harga:
module yang hilang tidak mengumumkan dirinya. Karena itu `module:list` wajib ada; ia satu-satunya
jawaban atas pertanyaan "kenapa module saya tidak muncul". Module yang masih ber-`id: change-me`,
sisa cetakan yang belum diganti, juga dilewati.

### Riwayat migration module

Riwayat migration module dicatat di `core_module_migrations`, dengan kolom `module_id`, dan **wajib
disaring per module pada setiap pembacaan**. Ia bukan sekadar tabel `migrations` milik Core yang
diberi kolom tambahan, dan ia tidak boleh menumpang tabel itu: dua module yang kebetulan punya
migration bernama sama akan saling menganggap migration lawannya sudah dijalankan.

### Data awal module

Seed module hanya dipanggil oleh **pemasangan module**, tidak pernah oleh `db:seed` global. Seed
dilewati bila catatan pemasangannya sudah menyimpan waktu pengisian, sehingga memasang ulang tidak
menggandakan data awal.

Seeder module memakai model biasa — turunan kontrak seeder module — bukan query mentah, supaya
barisnya ikut tersaring tenant seperti baris lain. Tabel master module membawa kolom penanda
`bawaan`, supaya baris hasil seed bisa dibedakan dari baris yang diketik pengguna. Ini bagian dari
standar tabel master module, bukan kebiasaan satu module contoh: tanpa penanda itu, pembaruan yang
ingin memperbaiki data bawaan tidak punya cara membedakan mana yang boleh disentuh.

### Aturan model

Model module memakai `HasULids`, `SoftDeletes`, dan `MilikTenant` sejak migration pertama, dan tidak
memakai `DB::table()` sama sekali. Setiap kelas module yang `extends Model` **wajib** memakai
`MilikTenant`; penjaganya memeriksa itu untuk semua module, termasuk yang sedang dipindah.
Akibatnya module **tidak menulis `tenant_id` sama sekali** — trait itu yang mengisinya, dan trait itu
juga yang membatalkan penyimpanan ke tenant lain.

Model tabel penghubung ikut bersoft-delete. Kolom baru ditambahkan lewat migration tersendiri;
migration yang sudah pernah berjalan di database pelanggan tidak disunting.

Satu jebakan yang berulang: **model berkunci gabungan tidak boleh memakai pembantu Eloquent yang
bersandar pada primary key** — `find()`, `fresh()`, `refresh()`, dan `save()` pada model yang sudah
ada. Semuanya menyusun `where` dari satu kolom kunci, dan pada kunci gabungan yang satu kolom itu
menunjuk lebih dari satu baris.

### Rute dan test module

Rute module berada di grup `web`, dengan `auth` **di depan** middleware konteks module. Urutannya
menentukan jawabannya: `auth` di depan menghasilkan 401 untuk permintaan tanpa pengguna dan 403 untuk
pengguna tanpa izin. Urutan terbalik menghasilkan 403 untuk keduanya, dan klien tidak bisa
membedakan "belum masuk" dari "tidak berhak". Setiap alias middleware pada berkas rute module wajib
terdaftar di Core, atau tercatat sengaja-belum beserta nomor task yang membereskannya.

Test module berjalan di suite Core, di atas PostgreSQL. Cabang berdasarkan mesin database dilarang —
hanya ada satu mesin database, dan cabang seperti itu menghasilkan jalur yang tidak pernah diuji di
tempat ia benar-benar berjalan.

Test module membangun **rantai izin sungguhan** — permission, privilege, duty, role, penugasan role,
lalu bertindak sebagai penggunanya — dan membangun rantai baru per pemanggilan, bukan memakai satu
rantai bersama. Rantai bersama membuat sebuah test lulus karena test lain sudah menyiapkan izinnya.

### Cetakan module baru

`module:make` mengganti seluruh penanda pada cetakan sekaligus; mengganti sebagian menghasilkan
module yang setengah bernama cetakan dan gagal jauh di kemudian hari. Cetakan itu sendiri wajib lulus
pemeriksa gaya dan analisa tipe, karena keduanya menyapu `modules/` — cetakan yang tidak lulus
membuat setiap module baru lahir dalam keadaan merah.

Module baru wajib benar di **dua tempat di luar foldernya sendiri**: baris awalan tabel pada
`modules/README.md`, dan `require` pada `composer.json` Core. Perintahnya tidak boleh menyunting
assertion pada test mana pun.

## Contract dan dependency

| Area | Aturan |
| --- | --- |
| API sync | REST/JSON di bawah `/api/v1`, lengkap dalam OpenAPI bila permukaannya dipanggil dari luar runtime. Rute module yang hanya dipanggil halamannya sendiri dijaga test module, bukan kontrak terbit. |
| Event | Event dibuat melalui outbox setelah commit; payload dan channel ditulis dalam AsyncAPI. Antar module di satu runtime, ia berbentuk event Laravel yang dikirim di dalam proses — namanya, envelope-nya, dan aturan versinya tetap sama. |
| UI | Halaman module ikut build shell dan dirender sebagai halaman Inertia. Shell menampilkan entry hanya bila entitlement aktif, catatan pemasangan module berstatus `installed`, dan user mempunyai permission entry point. Kontrol generik wajib memakai `@apperp/ui`. |
| Auth | Semua endpoint memvalidasi `TenantContext`, entitlement, pemasangan module, permission, dan organization scope. Module membacanya dari middleware konteks module lewat kontrak `KonteksTenant` dan `KonteksPermintaan`. Security metadata mengikuti [identity dan access](09-identity-and-access.md). |
| Data | Tidak ada akses ke data app lain. ID app lain hanya reference opaque. |
| Jobs | Idempotent, membawa `tenant_id`, memiliki retry/dead-letter policy. |
| Observability | Log, trace, metric, dan event menyertakan tenant/app/correlation ID. |
| Compatibility | `dependsOn` dengan versi tepat atau caret divalidasi saat katalog terdaftar; Core menolak cycle dan perubahan versi yang merusak dependent. `requires.core` belum divalidasi oleh Control Plane. |

Contract adalah batas integrasi, bukan shared domain model. Contract tetap dimiliki repository app penerbit. App consumer memakai versi contract yang dipublish dan menjalankan compatibility check di CI.

## Bantuan kontekstual pada halaman dan field

UI app mengikuti pola **field description** Dynamics 365: setiap field dapat memiliki help text opsional, tetapi bantuan hanya ditulis untuk field yang rumit atau pemakaiannya tidak langsung jelas. Dynamics 365 juga menampilkan deskripsi saat pengguna mengarahkan pointer ke field dan tidak mengisi deskripsi pada semua halaman. Lihat [View and export field descriptions](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/get-started/view-export-field-descriptions).

- Judul page, card, dan dialog cukup menyatakan konteksnya. Deskripsi atau ikon bantuan pada header bukan default; pakai hanya bila ada aturan atau konteks yang berlaku untuk seluruh permukaan.
- Detail yang hanya berlaku untuk satu field harus melekat pada field tersebut. Jelaskan arti bisnis, nilai yang diharapkan, batasan, ketergantungan, atau dampak pengisiannya dalam bahasa sehari-hari.
- Field yang sudah jelas tidak perlu help text. Jangan menyalin kalimat yang sama ke header dan setiap field.
- Hover sekitar satu detik menampilkan bantuan sementara dan bantuan otomatis hilang saat cursor berpindah. Klik label/judul field menampilkan bantuan yang tetap terbuka walau cursor meninggalkan field; klik label/judul itu lagi menutupnya. Padanan keyboard harus dapat melakukan toggle yang sama. Syarat, validasi, dan error yang perlu diketahui pengguna tidak boleh disembunyikan hanya di bantuan hover.
- Standar ini menetapkan keputusan konten dan perilaku, bukan nama prop, bentuk ikon, atau penyimpanan metadata. Perubahan komponen UI dapat dilakukan bertahap tanpa mengubah aturan di atas.

`packages/` tidak dibuat sebagai tempat menaruh kode bersama tanpa kebutuhan nyata. Package baru hanya dibuat ketika minimal dua repository membutuhkan interface yang sama dan interface tersebut siap diberi versi/publish. Kandidat awal yang wajar hanya SDK kecil untuk autentikasi/konteks tenant atau host UI; bukan model bisnis bersama.

## Membuat banyak baris sekaligus

Sebelum membuat layar pembuatan data, jawab dulu satu pertanyaan: dalam satu kali kunjungan, berapa banyak yang biasanya dibuat pengguna, dan seberapa banyak detail yang harus diisi per satuannya?

| Yang biasa dibuat | Detail per satuan | Bentuk |
| --- | --- | --- |
| Banyak | Ringkas dan seragam | **Tabel**: satu baris satu data, sel dapat diisi langsung |
| Satu | Ringkas | Card |
| Banyak | Bercabang, banyak detail kecil | Card |
| Satu | Bercabang | Card |

Tabel hanya dipakai bila kedua syarat terpenuhi bersamaan. Banyak tetapi detailnya bercabang tetap memakai card, karena tabel memaksa setiap field muat dalam satu sel dan yang tidak muat akan diam-diam dihilangkan. Sedikit tetapi ringkas juga tetap memakai card, karena tabel menambah beban belajar tanpa imbalan. Acuannya pola *Edit List* Business Central, bukan konsep baru.

Tiga aturan mengikat bentuk tabel:

- Detail yang tidak muat dalam sel dipindah ke dialog lapis kedua, bukan dibuang. Selnya menjadi tombol yang menampilkan ringkasan keadaan, misalnya `Belum diatur`, `2 batas`, atau `Tidak dibatasi`. Ini yang menjaga fitur tetap utuh tanpa melebarkan tabel.
- Bila yang dibuat berupa identitas yang dihasilkan sistem — kode, token, nomor urut — setiap baris wajib memiliki kolom keterangan bebas. Tanpa itu pengguna tidak dapat mengingat baris mana dibuat untuk siapa atau untuk keperluan apa.
- Endpoint menerima bentuk jamak dalam satu request dan satu transaksi, serta tetap menerima bentuk tunggal agar contract yang sudah dipublish tidak pecah.

Contoh yang sudah berjalan: kode undangan pada Control Plane. Satu baris berisi keterangan, user platform, security role, tanggung jawab hasil hitungan, batas data, dan sumber; batas data membuka dialog lapis kedua karena isinya bercabang. Admin membuat sepuluh kode dalam satu kali kirim, bukan mengulang dialog sepuluh kali.

## Navigasi Web Shell

Web Shell memiliki layout bersama agar pengguna tidak berpindah-pindah pola saat membuka app.

| Area layar | Pemilik | Isi |
| --- | --- | --- |
| Pemilih aplikasi di header | Web Shell | Launcher aplikasi: hanya app yang berhak dipakai tenant, tercatat `ready` pada installation registry, dan boleh dibuka user yang aktif. |
| Rail paling kiri | App aktif | Navigasi utama app, misalnya Dashboard, Organisasi, dan Akses. |
| Header | Web Shell | Konteks kerja yang dipakai bersama, pencarian, notifikasi, preferensi tampilan, dan menu akun. |
| Sidebar di kanan rail | App aktif | Navigasi turunan dari pilihan pada rail, yang didaftarkan UI artifact melalui host SDK. |
| Konten utama | App aktif | Halaman dan alur bisnis app aktif. |

Contoh: saat user memilih `Akses` pada rail, sidebar dapat berisi `Anggota`, `Role`, dan `Undangan`. Pada Management Aset, rail memuat `Master data`; isi sidebarnya adalah daftar master pada blok `ui.navigation` di `modules/apperp/management-aset/app.yaml`. Setiap entry sidebar membawa `entryPoint` berupa permission `read` master tersebut, sehingga menu yang tidak boleh dibuka user tidak ikut tampil. Saat user berpindah aplikasi melalui header, kedua navigasi tersebut diganti seluruhnya oleh navigasi aplikasi aktif.

App tidak membuat ulang header atau kerangka navigasi. App hanya mendaftarkan identitas, route, menu utama pada rail, menu turunan pada sidebar, dan permission entry point-nya. Nama, ikon, urutan, dan label menu berasal dari metadata app/host SDK, bukan daftar app yang di-hardcode di Web Shell.

Kontrak host navigasi bersifat deklaratif melalui `ui.navigation` pada manifest. Control Plane memvalidasi bahwa setiap item sidebar menunjuk permission `read` milik app yang sama, menyimpannya di katalog, lalu Web Shell memfilter dan merendernya dengan komponen Core.

**Id entri menu adalah jalur rutenya**: Core menyusun tautan sidebar dengan aturan `/<id module>/<id entri menu>`, jadi berkas rute module wajib punya rute dengan jalur itu. Module tidak mengimpor komponen internal Control Plane dan tidak menggambar ulang rail/sidebar.

## Lifecycle app

Lifecycle tidak dimodelkan sebagai satu status linear karena empat fakta mempunyai sumber kebenaran berbeda:

| Fakta | Sumber kebenaran |
| --- | --- |
| Catalogued | App catalog/manifest mengenal produk dan contract-nya. |
| Entitled | Tenant entitlement menyatakan hak komersial masih berlaku. |
| Installed | Installation registry mencatat artifact dan migration berhasil pada placement/release. |
| Ready | Placement/runtime health menyatakan release dapat diroute. |

Untuk module, ketiga perpindahan itu sudah ada dan dijalankan perintah artisan:
`module:install`, `module:disable`, dan `module:uninstall`, masing-masing menerima id module dan id
tenant — module dibeli **per tenant**, jadi tidak ada bentuk yang berlaku untuk seluruh instalasi
sekaligus.

Catatan pemasangan hidup di `core_module_installations`, berkunci `tenant_id` bersama kode module.
Tiga status yang sah — `installed`, `disabled`, `uninstalled` — dijaga `CHECK` di database, bukan
hanya di model, dan barisnya **tidak pernah dihapus**: pencabutan mengubah status, bukan membuang
catatannya. Kolom `seeded_at` yang menahan data awal terisi dua kali membuat pemasangan aman
dijalankan dua kali; menjalankannya lagi mengembalikan status tanpa menyentuh data dan tanpa
mengisi ulang data awal.

Penonaktifan hanya mengubah status beserta `disabled_at`. Pencabutan ditolak bila module masih
menjadi dependency module lain yang terpasang **pada tenant yang sama** — diperiksa terhadap tenant,
bukan terhadap katalog, karena katalog tidak tahu apa yang dibeli siapa.

Tidak satu pun dari ketiganya menghapus data, dan tidak ada opsi untuk menambahkannya. Aturannya ada
di [Mencabut modul tidak menyentuh data](#mencabut-modul-tidak-menyentuh-data) beserta penjaganya.

Satu hal yang sering disimpulkan terbalik: **pemasangan bukan izin**. Module yang terpasang tidak
memberi seorang pun hak apa pun; hak tetap datang dari rantai `role → duty → privilege → permission`.
Menyimpulkan izin dari pemasangan adalah kesalahan yang sama bentuknya dengan menyimpulkan
pemasangan dari entitlement.

Untuk app yang masih berupa container, ketiga perpindahan itu belum punya worker; jangan menganggap
aturan module di atas sudah berjalan di sana.

## Jenis app

| Type | Pembuat | Contoh |
| --- | --- | --- |
| Business app | Vendor | POS, Booking, Accounting |
| Bridge app | Vendor/partner | POS-Booking Bridge |
| Private addon app | Vendor/partner | Loyalty khusus customer |
| Customer extension app | Customer melalui SDK dan approval | Connector mesin produksi |

Customer extension pada managed cloud tidak boleh mengunggah arbitrary container. Ia harus memakai publisher namespace, signed image, manifest tervalidasi, least-privilege permission, dan security review. Pada on-prem perpetual, customer dapat menjalankan sidecar sendiri tetapi hanya melalui API/event contract publik; tidak ada query langsung DB atau perubahan source Core. Support vendor berlaku sesuai batas kontrak, bukan melalui enrollment runtime wajib.

## Lihat juga

- [Gate penemuan dan keputusan](18-module-discovery-and-decision-gate.md) — dilewati **sebelum** app dibuat
- [Rantai keamanan modul transaksi](19-transaction-security-chain.md) — empat lapis di atas diteruskan sampai ke user
- [Mendaftarkan katalog produk](13-publishing-an-app-release.md) — cara manifest app masuk katalog Core
- [API dan integration bridge](04-api-and-integration.md) — satu-satunya jalan komunikasi antar app
- [Kustomisasi dan addon](05-customization-and-addons.md) — kebutuhan khusus customer tanpa fork
- [Release dan on-prem](03-release-and-on-prem.md) — lifecycle install, upgrade, uninstall
