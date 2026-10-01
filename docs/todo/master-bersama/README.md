# Master bersama dan modul yang berdiri sendiri

Rencana kerja untuk master yang dipakai lebih dari satu modul — Vendor hari ini, kelak Customer,
Item, dan Worker — beserta saluran integrasi ke sistem di luar CoreERP. Keputusannya diambil pemilik
produk pada 1 Oktober 2026. Mekanismenya **belum dibangun**; aturan yang berlaku untuk pekerjaan baru
sudah dicatat di [standar module](../../dev/02-module-standard.md#master-bersama-dan-modul-yang-berdiri-sendiri).

Dua prinsip yang membingkai semuanya:

- **Sederhana.** Tidak ada mekanisme yang dibangun sebelum ada pemakainya yang nyata.
- **Ikut padanan Business Central atau Dynamics 365 F&O bila ada.** Rujukan BC dibaca dari source
  `D:\Kerja\BCApps` lewat `bc-tools`; rujukan F&O dari Microsoft Learn.

## Janji ke pelanggan: beli modul A saja, tidak ada yang hilang

Pelanggan yang membeli satu modul mendapat seluruh kemampuan modul itu. Ia tidak boleh dipaksa membeli
modul lain hanya untuk mengisi master yang dipakai modul pertamanya. Empat aturan menjaganya:

1. **Master bersama hidup di Foundation**, yang selalu terpasang. Ia tidak pernah dimiliki satu modul.
2. **Modul membawa pintunya sendiri ke master yang ia pakai.** Menu modul A punya entri ke halaman
   master yang sama, dan duty bawaan modul A menyertakan hak master itu. Pengguna modul A tidak perlu
   tahu letak menu Core, dan tidak bergantung pada role milik modul lain.
3. **Modul hanya bergantung ke bawah**: ke Foundation dan Platform, tidak pernah ke modul lain. Kerja
   sama dua modul (misalnya pesanan Procurement yang menjadi penerimaan aset) dibangun sebagai
   tambahan: modul B mendaftar ke titik perluasan modul A atau mendengarkan event-nya. Tanpa B, A
   tetap utuh.
4. **Bagian milik modul lain tampil hanya bila modul itu terpasang.** Tidak ada tab kosong dan tidak
   ada tautan mati.

## Kapan sebuah master milik Foundation

Master masuk Foundation bila **salah satu** benar:

1. dipakai, atau pasti akan dipakai, dua modul atau lebih;
2. ia identitas pihak atau badan usaha — siapa, di mana, entitas mana;
3. di BC atau F&O ia hidup di lapis bersama (Base App atau common), bukan di app fungsional.

Selain itu, master tetap milik modulnya.

## Inventaris per 1 Oktober 2026

| Master | Letak | Keterangan |
| --- | --- | --- |
| Vendor, satuan, mata uang, kalender fiskal dan kerja, number sequence, organisasi, buku alamat, wilayah | Foundation / Platform | sudah benar |
| Worker, Position, Job (`hr_*`) | modul HR | **pindah ke Foundation** (K-W, diputuskan 1 Oktober 2026), lihat di bawah |
| Jenis, group, kondisi, pabrikan, dan model aset; buku dan profil penyusutan; kelompok harta fiskal; posting group aset; seluruh master pemeliharaan; lokasi aset | modul aset | tetap di modul: khusus aset, juga khusus di Asset management dan Fixed assets D365 |

Master yang **belum ada** dan wajib lahir di Foundation begitu pertama dibutuhkan, bukan di modul yang
pertama memintanya: Customer, Item/produk beserta kategorinya, syarat dan cara pembayaran, kode dan
grup pajak, rekening bank, dimensi keuangan, dan syarat pengiriman.

## Satu kartu, bagian per modul

Business Central hanya punya satu `Vendor Card` (page 26, `src/Layers/W1/BaseApp/Purchases/Vendor/VendorCard.Page.al`).
App lain menambah bagiannya sendiri lewat `pageextension … extends "Vendor Card"` dan kolomnya lewat
`tableextension`; tidak ada app yang membuat kartu Vendor tandingan. Menu Purchasing dan Payables
membuka daftar Vendor yang sama.

Bentuknya di CoreERP:

- **Inti master milik Foundation**: identitas (nomor, nama, alamat lewat buku alamat, NPWP, kontak,
  status diblokir) dan lampiran. Kolom yang dipakai dua modul atau lebih masuk inti.
- **Kolom khusus modul disimpan di tabel modul sendiri**, misalnya `proc_vendor_settings (tenant_id, vendor_id, …)`,
  dan tampil sebagai bagian di kartu yang sama.
- **Menu tiap modul menunjuk halaman yang sama.**

Mengubah bagian modul A tidak menyentuh bagian modul B, karena setiap bagian punya pemilik sendiri.

## Lokasi aset mengikuti functional location D365

Lokasi menjawab *di mana barangnya*; departemen menjawab *siapa yang bertanggung jawab dan menanggung
biayanya* (`responsible_org_unit_id`). Keduanya berbeda: satu departemen memakai banyak ruangan, dan
aset dapat berada di ruangan milik departemen lain.

[Functional location](https://learn.microsoft.com/en-us/dynamics365/supply-chain/asset-management/functional-locations/create-functional-locations)
D365 memuat hierarki (`Parent`), **Address yang diwariskan ke sub-lokasi**, Financial dimensions yang
dapat diturunkan ke aset yang dipasang di sana, Site/Warehouse, dan Workers. BC memisah `FA Location Code`,
`Location Code`, `Responsible Employee`, dan dimensi pada tabel `Fixed Asset`.

`m_lokasi_aset` sudah hierarkis (`parent_id`) dan tetap milik modul aset. Yang ditambahkan:

- lokasi puncak menunjuk alamat dari buku alamat Core, dan anaknya mewarisi alamat itu;
- departemen bawaan per lokasi (opsional), yang mengisi unit penanggung jawab saat aset ditempatkan
  dan tetap dapat diubah;
- alamat departemen hanya menjadi saran saat membuat lokasi, bukan sumber datanya.

Sudah dikerjakan 1 Oktober 2026 (`alamat_id`, `departemen_bawaan_id`, kontrak `AddressDirectory`);
aturan dan perbedaannya dengan D365 ada di [Lokasi aset](/apps/management-aset/master/lokasi/).

## K-W: identitas Worker pindah ke Foundation

Hari ini Worker milik modul HR. Core hanya bertanya lewat `LinkedWorkerResolver`, dan modul aset belum
memakainya — penanggung jawab aset memakai **pengguna** (`penanggung_jawab_user_id`), sehingga staf
tanpa login tidak dapat dipilih.

Pemakaian yang pasti datang: penanggung jawab aset (BC `Responsible Employee`), teknisi work order
(D365 Asset management), peminta dan pembeli di Procurement (D365 purchase requisition), dan approval
menurut posisi. Selama Worker milik HR, pelanggan yang hanya membeli Aset atau Procurement kehilangan
semua itu.

Rekomendasi: **identitas pekerja** (nomor, nama, posisi, atasan, tautan ke pengguna) pindah ke
Foundation, seperti `Employee` di Base App BC; hal kepegawaian (kontrak, kompensasi, cuti) tetap di
modul HR. Ini mengubah keputusan gap 3 (#221).

**Diputuskan pemilik pada 1 Oktober 2026: setuju.**

Rincian desain yang menunggu persetujuan: [K-W: identitas Worker di Foundation](./k-w-worker.md).

## Sisa bentuk microservice yang diganti

Modul dulu hidup di proses dan database sendiri. Di satu runtime, sebagian aturan tetap benar dan
sebagian hanya beban:

| Hal | Tetap / berubah |
| --- | --- |
| Modul memakai Core hanya lewat satu namespace kontrak | **tetap** — batas yang bisa dijelaskan dalam satu kalimat ([API dan integrasi](../../dev/04-api-and-integration.md#satu-namespace-dan-hanya-satu)) |
| Kontrak menerima id dan memulangkan baris biasa, bukan model | **tetap** |
| Hanya pemilik yang menulis | **tetap** |
| Foreign key dari tabel modul ke tabel Core | **sudah diizinkan** ([standar module](../../dev/02-module-standard.md#ownership-dan-data)); ke modul lain tetap dilarang |
| Membaca satu per satu (`VendorDirectory::find()` per baris daftar) | **berubah**: kontrak master bersama menyediakan pembacaan banyak id sekaligus |
| Dropdown vendor lewat endpoint perantara milik modul | **berubah**: Foundation menyediakan komponen pemilih beserta endpoint-nya |
| `internal/v1` | hanya untuk sistem di luar CoreERP, tidak pernah antar-modul |

## Saluran integrasi ke sistem luar

"Finance" di Core bukan modul buku besar. Ia jembatan posting ke aplikasi finance pelanggan, dan sejak
pemindahan lapis terbelah dua: jurnalnya di `Foundation\FinancePosting`, salurannya (klien integrasi,
signature push, aturan tujuan) di `Platform\Integration`. Aplikasi finance lama hanyalah satu klien
integrasi yang datanya ada di database.

Padanannya: **Business events** F&O (katalog event, endpoint, aktivasi per entitas legal) dan webhook
subscription BC. Bentuk yang dituju:

```
IntegrationClient   satu per sistem luar: token, IP, scope
  └ Subscription    klien × jenis pesan (finance-posting.v1, vendor.v1, …), mode pull/push, filter
Outbox              satu antrean pesan keluar, diisi pemilik datanya
  └ Delivery        per subscription × pesan: status, percobaan, jadwal coba lagi
Ack handler         didaftarkan pemilik jenis pesan
```

Mesin pengiriman yang sekarang (`PostingPusher`, `PostingAcknowledger`, `FinancePostingDelivery`) hanya
mengenal jurnal posting. Ia dijadikan umum **tanpa mengubah kontrak v1** `integrasi-finance.yaml`:
URL, payload, header signature, dan aturan ack tetap sama, dibuktikan test kontrak yang ditulis lebih
dulu. Untuk jurnal, desain sekarang lebih kuat daripada webhook BC — setiap posting wajib di-ack,
jadi jaminannya dibukukan tepat sekali. Notifikasi gaya BC (`subscriptions` dengan jabat tangan dan
masa berlaku) hanya ditambahkan sebagai saluran baru bila integrator membutuhkannya, tidak menggantikan
feed finance.

## Pekerjaan

- [ ] Test kontrak yang mengunci feed finance v1 (URL, payload, header signature, ack), lebih dulu dari apa pun di bawah
- [ ] Mesin pengiriman umum di `Platform\Integration`; feed finance menjadi jenis pesan pertama
- [ ] Kontrak master bersama: pembacaan banyak id sekaligus untuk Vendor
- [ ] Komponen pemilih Vendor dari Foundation; modul aset berhenti memakai endpoint perantaranya
- [ ] Bagian kartu master per modul (padanan `pageextension`) dan entri menu modul yang menunjuk halaman Core
- [ ] Duty bawaan modul menyertakan hak master yang ia pakai
- [ ] Gate CI: suite test tiap modul berjalan dengan hanya modul itu yang terpasang
- [ ] Lokasi aset: alamat dari buku alamat Core (diwariskan), departemen bawaan per lokasi
- [ ] K-W: identitas Worker (`hr_workers`, `hr_positions`, `hr_jobs`, penugasan posisi, tautan ke pengguna) pindah ke `Foundation\Worker`; modul HR mempertahankan data kepegawaian
- [x] K-2: buku alamat (Party) pindah ke `Platform\AddressBook` — diputuskan pemilik 1 Oktober 2026; menghapus pengecualian `OrganizationParty -> Party` dan `PrintIdentityStore -> OrganizationAddressBook`. Wilayah ikut pindah ke `Platform\Geography`, karena buku alamat memakai `CountryRegion` dan address setup di F&O adalah bagian global address book; tanpa itu lahir dua pelanggaran arah baru
