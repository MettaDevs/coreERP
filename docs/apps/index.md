# App

Setiap app bisnis adalah **release unit mandiri dengan repository sendiri**: API, UI, database, migration, kontrak, dan image Docker. CoreERP tidak menyimpan domain atau database app.

Halaman di bagian ini bersifat **teknis dan internal** — ditujukan untuk developer yang membangun atau merawat app, bukan panduan pemakaian untuk pengguna bisnis.

## Katalog

| App | ID manifest | Repository | Database | Status |
| --- | --- | --- | --- | --- |
| [Management Aset](/apps/management-aset/) | `management-aset` | `app-erp-management-aset` | `management_aset` | Release pengembangan `0.1.0` |
| [Human Resources](/apps/human-resources/) | `human-resources` | `app-erp-hr` | `human_resources` | Release pengembangan `0.1.0` |
| [Business Partner](/apps/business-partner/) | `business-partner` | `app-erp-business-partner` | `app_erp_business_partner` | Fondasi release pengembangan `0.1.0` |
| [Procurement](/apps/procurement/) | `procurement` | `app-erp-procurement` | `app_erp_procurement` | Fondasi release pengembangan `0.1.0` |
| Template app | `change-me` | `app-erp-template` | — | Titik mulai app baru |

::: warning Nama repository ≠ nama folder
Repository Management Aset bernama `app-erp-management-**asset**`, tetapi build memerlukan folder bernama `app-erp-management-**aset**`. Lihat [Menyiapkan lingkungan lokal](/onboarding/setup).
:::

## Mau membangun app baru?

Jangan mulai dari `git clone` template. Mulai dari [Membangun app baru](/apps/membangun-app-baru) — ada gate keputusan yang harus dilewati sebelum baris kode pertama, dan proposalnya butuh persetujuan.

## Isi halaman app

Tiap halaman app di sini memuat hal yang sama, dengan urutan yang sama:

| Bagian | Isinya |
| --- | --- |
| Identitas | ID manifest, publisher, versi, repository, database, port lokal |
| Domain yang dimiliki | Data apa yang app ini pegang, dan apa yang bukan miliknya |
| Kontrak | OpenAPI, AsyncAPI, reference nomor, tipe workflow |
| Struktur kode | Di mana barang disimpan di repo app |
| Status terhadap gate | Gate mana yang sudah lewat, mana yang belum, dengan buktinya |
| Menjalankan | Cara menyalakannya di stack lokal |
| Dokumen terkait | Aturan platform yang berlaku, plus dokumen di repo app |

Halaman ini **tidak** menggantikan dokumen di repository app. Ia titik masuk: cukup untuk tahu app itu apa, batasnya di mana, dan harus baca apa selanjutnya.

Cetakannya ada di `docs/apps/_template/index.md`. Salin folder itu menjadi `docs/apps/<app-key>/`, isi placeholder-nya, lalu daftarkan pada sidebar dan tabel katalog di atas. Cetakan itu sendiri tidak dirender jadi halaman.

## Apa yang di Core, apa yang di repo app

| Isi | Tempat |
| --- | --- |
| Identitas, domain yang dimiliki, status gate, kontrak yang dipublikasi ke app lain | **Core** — `docs/apps/<app>/` |
| Domain bisnis, schema, detail endpoint, struktur folder, cara test, keputusan internal | **Repo app** — `docs/` di repository app itu |

Dua tes untuk memutuskan: **kalau kode app berubah, dokumen ini ikut berubah?** Ya → repo app. **Yang baca ini tim app itu sendiri atau orang luar?** Orang luar → Core.

Dokumen yang berubah bareng kode harus hidup di repo yang sama dengan kodenya. Kalau tidak, memperbaruinya butuh dua PR di dua repo, dan dokumen mati dengan cara persis seperti itu.

## Aturan yang berlaku untuk semua app

Tiga hal yang tidak bisa ditawar, berapa pun app-nya:

**Satu app tidak membaca database app lain.** Tidak ada foreign key, Eloquent relation, atau query lintas database. Integrasi memakai REST/OpenAPI atau event/AsyncAPI.

**Data tenant memakai konteks tepercaya dari Core.** Jangan menerima `tenant_id` atau scope organisasi bebas dari browser.

**Nomor dokumen diterbitkan Core.** App mendeklarasikan reference pada manifest; admin tenant yang mengaktifkan dan mengatur formatnya.

## Lihat juga

- [Standar module](/dev/02-module-standard) — kontrak lengkap satu app
- [Gate penemuan dan keputusan](/dev/18-module-discovery-and-decision-gate) — sebelum app dibuat
- [Menerbitkan release app](/dev/13-publishing-an-app-release) — dari repo app ke katalog Core
- [Target worktree](/dev/06-worktree-target) — kenapa satu app satu repository
