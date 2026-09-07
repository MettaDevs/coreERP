# Standar app dan addon app

Dokumen ini memakai istilah **app**. App adalah produk atau kemampuan bisnis yang dapat dipasang dan dirilis mandiri. Istilah `module` pada nama tabel, endpoint, atau kode yang sudah ada adalah nama teknis lama; jangan memakainya untuk desain baru atau komunikasi produk.

## Repository dan release unit wajib

Satu app bisnis memiliki satu repository. API dan UI bukan repository terpisah karena keduanya perlu diuji, diberi versi, dan dirilis sebagai satu kemampuan bisnis.

```text
app-erp-accounting/                 # satu repository app
├── app.yaml
├── api/                            # Laravel service milik Accounting
│   ├── Dockerfile
│   ├── app/
│   ├── routes/api.php
│   └── tests/
├── ui/                             # React/Vite UI milik Accounting
│   ├── package.json
│   └── src/
├── database/
│   ├── migrations/
│   └── seeders/
├── contracts/
│   ├── openapi.yaml
│   └── asyncapi.yaml
├── deploy/
│   ├── compose.fragment.yaml
│   └── migrate.sh
└── README.md
```

Repository CoreERP ini adalah repository platform. Ia menampung `control-plane`, `provider-console`, dan `web-shell`; ia tidak berisi domain atau database app bisnis.

Setiap app menghasilkan artifact terpisah: image API, artifact/image UI, migration, contract, dan manifest. Cloud dapat menyajikan UI lewat CDN/artifact registry; on-prem perpetual menyajikannya dari image static UI yang hanya ada untuk app berlisensi dan didistribusikan dalam bundle release bertanda tangan.

Jangan memakai Git submodule untuk menghubungkan repository. Contract yang dipakai pihak lain dipublish sebagai artifact berversi; source app tidak diambil langsung oleh app lain.

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
| `api`, `ui`, `database`, `events` | Ya | Katalog mengenal artifact, database logis, dan kontrak app |
| `ui.navigation` | Ya | Menu app muncul di shell Core. Item menu hanya boleh memakai permission `read` milik app yang sama |
| `security.entry_points` / `permissions` / `privileges` / `duties` | Ya, keempatnya | Duty tersedia untuk disusun admin tenant menjadi security role |
| `security.data_policies` | Hanya bila resource perlu dibatasi organisasi | Muncul sebagai batas data saat admin memberi role ke anggota |
| `dependsOn` | Tidak, bila app berdiri sendiri | Dependency disimpan dengan rentang versi. Core menolak app yang belum ada, versi yang tidak cocok, dan cycle. Saat onboarding, prerequisite transitif ikut menjadi entitlement serta dipasang lebih dulu. |
| `number_sequences.references` | Hanya bila app menerbitkan nomor | Reference muncul di layar **Nomor dokumen** Core (`settings/number-sequences`) untuk diaktifkan dan diatur admin tenant |
| `workflow_types` | Hanya bila ada approval atau verifikasi | Tipe workflow tersedia untuk dikonfigurasi admin tenant |
| `reports` | Hanya bila app punya dokumen cetak atau ekspor | Laporan muncul di katalog Core; admin tenant mengatur layoutnya di **Layout laporan**, pengguna mencetak lewat dialog Shell. Dataset tetap diminta ke app; lihat [dokumen cetak](23-document-rendering.md) |

App tidak menerbitkan nomornya sendiri. Setelah reference terdaftar dan admin mengaktifkannya, app meminta nomor lewat API internal Core `POST /api/internal/v1/number-sequences/{reference}/issue` atau `/reserve`, dengan `idempotency_key` wajib. Detailnya di [Number sequence](14-number-sequences.md).

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

Contoh manifest utuh yang sudah berjalan ada di `app-erp-management-aset/app.yaml` — 787 baris, dengan blok `security` sepanjang 600 baris. Contoh di atas sengaja dipersingkat.

#::: tip Mencari langkah mengerjakannya?
Halaman ini menetapkan **aturannya**. Urutan mengerjakan beserta persiapan teknis, konvensi penamaan dan alokasi port, berkas yang wajib ada, dan gate per tahap ada di [jalur membangun app baru](../apps/membangun-app-baru.md).
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

## Ownership dan database

Setiap app memiliki owner yang bertanggung jawab atas code review, contract, database, release, rollback, dan incident app tersebut. Core Platform memiliki `core_erp`. Setiap app resmi memiliki database dengan pola `app_erp_<app>`, misalnya `app_erp_procurement` dan `app_erp_management_aset`; addon memakai `addon_<publisher>_<app>`. Setiap database mempunyai database user/secret sendiri. Tidak ada foreign key, Eloquent relation, atau query langsung lintas database.

### Nama tabel

Nama tabel memakai `snake_case` dan menyatakan jenis data, bukan nama layar atau
nama controller. Setiap tabel app memakai salah satu bentuk berikut:

| Bentuk | Dipakai untuk | Contoh |
| --- | --- | --- |
| `m_<resource>` | Master, reference, setup, konfigurasi, atau tabel relasi milik master yang bukan fakta transaksi mandiri. | `m_group_aset`, `m_jenis_aset_atribut` |
| `tr_<transaction>` | Header atau fakta transaksi mandiri. | `tr_penerimaan_aset` |
| `tr_<transaction>_details` | Baris/detail yang selalu dimiliki satu header transaksi. Bentuk ini selalu jamak: `_details`, bukan `_detail`. | `tr_perencanaan_aset_details` |
| `tr_<aggregate>_<record>` | Catatan transaksi turunan yang bukan daftar baris header, misalnya nilai atribut, log, atau fakta operasional lain milik aggregate transaksi. | `tr_aset_atribut` |

`m_` bukan berarti setiap tabelnya adalah master yang mendapat menu, permission,
atau Number Sequence sendiri. Tabel konfigurasi dan relasi—misalnya
`m_jenis_aset_atribut`—tetap memakai `m_` bila ia bukan fakta transaksi mandiri.
Sebaliknya, tabel `tr_` harus menyimpan fakta proses bisnis; jangan memakai `tr_`
untuk sekadar cache atau data tampilan.

Nama tidak memakai bentuk generik atau ambigu seperti `tbl_aset`, `aset_data`, atau
`transaction_aset`. Jika suatu tabel baru tidak cocok dengan empat bentuk di atas,
putusan naming-nya dibuat pada proposal app sebelum migration ditulis; jangan
menciptakan prefix baru diam-diam.

Di dalam database sendiri, app boleh memakai transaksi, foreign key, dan table desain normal. Semua tabel tenant-scoped membawa `tenant_id`; data dengan konsekuensi hukum/akuntansi membawa `legal_entity_id`; data operasional membawa `org_unit_id` bila ownership terjadi pada operating unit. ID organisasi adalah reference opaque ke Organization service, bukan foreign key lintas database. Lihat [model tenant dan organisasi](01a-tenant-and-org-hierarchy.md).

## Contract dan dependency

| Area | Aturan |
| --- | --- |
| API sync | REST/JSON di bawah `/api/v1`, lengkap dalam OpenAPI. |
| Event | Event dibuat melalui outbox setelah commit; payload dan channel ditulis dalam AsyncAPI. |
| UI | UI entry mendaftarkan route/menu melalui host SDK; host memuat artifact hanya bila entitlement aktif, installation registry `ready`, dan user mempunyai permission entry point. Kontrol generik wajib memakai `@apperp/ui`; CSS app hanya mengatur layout dan domain. |
| Auth | Semua endpoint memvalidasi token, `TenantContext`, entitlement, installation readiness, permission, dan organization scope. Security metadata mengikuti [identity dan access](09-identity-and-access.md). |
| Data | Tidak ada database access lintas app. ID app lain hanya reference opaque. |
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

Contoh: saat user memilih `Akses` pada rail, sidebar dapat berisi `Anggota`, `Role`, dan `Undangan`. Pada Management Aset, rail memuat `Master data`; sidebar kemudian berisi kedelapan master pada `app.yaml`-nya, mulai `Entitas aset` sampai `Analisa maintenance`. Setiap entry sidebar membawa `entryPoint` berupa permission `read` master tersebut, sehingga menu yang tidak boleh dibuka user tidak ikut tampil. Saat user berpindah aplikasi melalui header, kedua navigasi tersebut diganti seluruhnya oleh navigasi aplikasi aktif.

App tidak membuat ulang header atau kerangka navigasi. App hanya mendaftarkan identitas, route, menu utama pada rail, menu turunan pada sidebar, dan permission entry point-nya. Nama, ikon, urutan, dan label menu berasal dari metadata app/host SDK, bukan daftar app yang di-hardcode di Web Shell.

Kontrak host navigasi versi awal bersifat deklaratif melalui `ui.navigation` pada manifest. Control Plane memvalidasi bahwa setiap item sidebar menunjuk permission `read` milik app yang sama, menyimpannya di katalog, lalu Web Shell memfilter dan merendernya dengan komponen Core. Pemilihan item memakai query `view` pada route host dan hash pada UI artifact. App tidak mengimpor komponen internal Control Plane dan tidak menggambar ulang rail/sidebar.

## Lifecycle app

Lifecycle tidak dimodelkan sebagai satu status linear karena empat fakta mempunyai sumber kebenaran berbeda:

| Fakta | Sumber kebenaran |
| --- | --- |
| Catalogued | App catalog/manifest mengenal produk dan contract-nya. |
| Entitled | Tenant entitlement menyatakan hak komersial masih berlaku. |
| Installed | Installation registry mencatat artifact dan migration berhasil pada placement/release. |
| Ready | Placement/runtime health menyatakan release dapat diroute. |

Disable dan uninstall belum memiliki worker. Saat worker itu dibuat, uninstall harus
menolak app yang masih menjadi dependency app lain, mengarsipkan data default, dan
memerlukan backup serta approval eksplisit untuk `purge`; jangan menganggap aturan
masa depan itu sudah berjalan.

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
- [Menerbitkan release app](13-publishing-an-app-release.md) — cara manifest app masuk katalog Core
- [API dan integration bridge](04-api-and-integration.md) — satu-satunya jalan komunikasi antar app
- [Kustomisasi dan addon](05-customization-and-addons.md) — kebutuhan khusus customer tanpa fork
- [Release dan on-prem](03-release-and-on-prem.md) — lifecycle install, upgrade, uninstall
