# Buku alamat: party, alamat, dan kontak

Halaman ini untuk developer yang menyentuh alamat atau kontak sebuah pihak: organisasi tenant sendiri, dan nanti pelanggan, pemasok, atau pegawai milik app. Ia menjelaskan apa yang disimpan Core, aturan yang ditegakkan kodenya, dan mengapa alamat organisasi tidak boleh disalin ke tabel lain.

## Mengapa alamat ada di Core

Mengikuti Global Address Book Dynamics 365, yang tinggal di lapisan platform dan bukan sebuah modul. **Party** adalah identitas satu pihak, orang atau organisasi. **Tempat** (`locations`) berdiri sendiri dan ditautkan ke party, sehingga satu gedung dapat dipakai beberapa pihak; alamat pos dan kontak elektronik menempel ke tempat. Perannya (pelanggan, pemasok, pegawai) berlaku per legal entity dan dimiliki app, kecuali vendor yang dimiliki Core sendiri (lihat **Vendor** di bawah); Core menyimpan registry semua peran.

Legal entity dan operating unit ikut menjadi party karena keduanya punya nama dan alamat yang tercetak pada dokumen resmi. Identitas organisasi sudah di Core, dan memisahkan alamatnya berarti membelah satu identitas ke dua database. Ini juga yang menutup godaan setiap fitur menambah kolom alamatnya sendiri: identitas cetak pernah menyimpan alamat dan telepon, lalu dibongkar pada 2026-09-05 supaya alamat hanya pernah diubah di satu tempat.

## Yang disimpan

Skema lengkapnya ada di `apps/core/database/migrations/2026_07_27_020000_create_party_and_address_book_tables.php`, dengan bentuk tempat bersama dari `2026_09_29_130000_share_locations_between_parties.php`; daftar negara ISO 3166-1 diisi `2026_09_05_140000_seed_country_regions.php` sebagai migrasi, bukan seeder, karena tanpa isinya tidak ada alamat yang dapat disimpan.

**Satu tabel negara.** `country_regions` juga negara bagi master wilayah (`ref_provinces`, `ref_postal_codes`, `ref_address_parameters`, `ref_administrative_divisions`, `ref_country_hierarchy_levels`, `time_zones`); `ref_countries` tidak lagi ditunjuk foreign key mana pun dan dibuang pada rilis berikutnya. Master wilayah dipakai bersama seluruh tenant dan **tidak punya pintu tulis di sisi tenant**: isinya dari migrasi, seeder, dan perintah impor, dan halaman Address setup hanya membaca. Alasannya di [buku alamat global](/todo/buku-alamat-global/), OWN-05.

| Tabel | Isi | Aturan yang dijaga database |
| --- | --- | --- |
| `parties` | Nama pihak, jenis `person` atau `organization` (bentuk nama, bukan peran), `search_name` untuk pencarian | Composite unique `(tenant_id, id)`; `type` wajib ada di `party_types` |
| `locations` | Satu tempat (padanan `LogisticsLocation`): nama, tanpa kolom party | Composite unique `(tenant_id, id)` |
| `party_locations` | Tautan party ke tempat (padanan `DirPartyLocation`), utama atau bukan, berlaku dari–sampai | Partial unique: satu tautan utama per party, dan satu tautan per pasangan party–tempat, di antara yang belum diarsipkan |
| `party_location_purposes` | Kegunaan satu tautan, boleh lebih dari satu | Kode wajib ada di `location_purposes`; satu baris aktif per kegunaan per tautan |
| `location_purposes` | Referensi kegunaan platform: `business`, `delivery`, `invoice`, `payment`, `home` | Diisi migration, bukan milik tenant |
| `postal_addresses` | Alamat pos satu tempat dan bentuk tercetaknya `formatted` | Satu per tempat; kode negara wajib ada di `country_regions` |
| `electronic_addresses` | Kontak satu tempat: `email`, `phone`, `whatsapp`, `fax`, `url`, dengan keterangan dan penanda utama | Partial unique: satu kontak utama per tempat per jenis |
| `organization_parties` | Tautan organisasi ke party-nya, satu ke satu | Unique per party, sehingga satu party paling banyak mewakili satu organisasi |
| `party_role_registrations` | Registry peran party per legal entity, ditulis app pemilik peran | Unique `(party, peran, legal entity)` |

WhatsApp adalah jenis kontak tersendiri, bukan telepon berlabel, karena kop dan dokumen menampilkannya terpisah dari nomor telepon kantor.

## Buku alamat organisasi

Kartu organisasi di `settings/organization` punya bagian **Alamat Utama & Cabang** dan **Informasi Kontak & Komunikasi** untuk legal entity, dan padanannya untuk operating unit. Keduanya dilayani Core lewat `api/v1/organizations/{organization}/locations` dan `.../contacts`, dengan kode di `app/Platform/AddressBook/Support/OrganizationAddressBook.php` dan controller di `app/Platform/AddressBook/Http/Controllers/`.

Aturan yang ditegakkan kode, dan alasannya:

- **Party organisasi dibuat saat pertama dibutuhkan, bukan saat organisasi dibuat.** Organisasi yang sudah ada sebelum buku alamat ikut mendapat party begitu alamatnya dibuka, tanpa backfill. Nama party disamakan dengan nama organisasi setiap kali dibaca, jadi mengganti nama legal entity tidak meninggalkan party bernama lama.
- **Id alamat di layar dan API adalah id tautan, bukan id tempat.** `location_id` pada permintaan simpan menautkan tempat yang sudah ada alih-alih membuat yang baru; `sharable-locations` menawarkan tempat beralamat milik tenant. Mengubah alamat pos tempat bersama mengubahnya bagi setiap pemakainya, dan layar menyebut berapa pihak lain yang terdampak (`shared_with`).
- **Alamat pertama otomatis utama; kontak pertama suatu jenis di satu tempat otomatis utama.** Tanpa ini kop dokumen kosong sampai seseorang ingat mencentang "utama". Penulis diantrekan dengan mengunci baris party; partial unique index tetap menolak dua utama dari penulis yang tidak lewat sini (`SharedLocationConcurrencyTest`).
- **Mengarsipkan alamat hanya melepas tautannya.** Tempat, alamat pos, dan kontaknya tetap ada untuk pihak lain yang memakainya; tidak ada aturan "tempat yatim ikut terhapus", karena modul kelak menunjuk tempat langsung dan Core tidak dapat memeriksa tabel modul. Mengarsipkan yang utama menaikkan alamat tertua yang tersisa.
- **Kontak menempel ke tempat.** `address_id` memasang kontak ke salah satu alamat; tanpa itu kontak menempel ke alamat utama, atau — bila party belum punya alamat — ke satu tempat tanpa alamat pos bernama "Informasi kontak" yang tidak tampil sebagai alamat.
- **Bentuk tercetak disusun saat disimpan, ke kolom `formatted`.** Aturannya di `PostalAddressFormatter`: jalan dan gedung, PO Box, kelurahan atau kecamatan, lalu kota, provinsi, dan kode pos. Nama negara hanya ditulis untuk alamat di luar Indonesia. Dokumen resmi menyalin bentuk ini, sehingga perubahan alamat kemudian tidak menulis ulang dokumen lama.
- **Kode negara dinormalkan lalu diperiksa ke tabel.** `id` dan `ID` sama; `ZZ` ditolak 422, bukan disimpan sebagai teks bebas.
- **Layar Buku alamat butuh duty *Lihat organisasi*; mengubah kontak, lokasi, dan identitas cetak butuh *Kelola organisasi* (`core.organization.update`).** API baca kontak dan lokasi tetap terbuka bagi semua anggota tenant karena dipakai pemilih di layar lain. Organisasi tenant lain dijawab 404, bukan 403, supaya keberadaannya tidak bocor.

## Yang membaca buku alamat

**Identitas cetak** ([Dokumen cetak, layout, dan ekspor](23-document-rendering.md)) mengisi `kop.alamat*` dari alamat utama dan `kop.telepon`, `kop.whatsapp`, `kop.fax`, `kop.email`, `kop.laman` dari kontak utama tiap jenis — dari tempat alamat utama lebih dulu, lalu dari tempat party yang lain. Ringkasan itu dibaca lewat `OrganizationAddressBook::summary()` tanpa membuat party, supaya sekadar mencetak tidak menulis apa pun. Halaman identitas cetak hanya menampilkan alamat dan kontak yang akan tercetak; mengubahnya dilakukan di dua bagian di atas.

## Direktori pihak dan kartu detail

Halaman `settings/global-address-book` membaca pihak nyata dari database. Daftar memakai
`DataTable` SDK, pencarian nama/nomor, saringan jenis/peran/status/buku alamat, dan pagination.
Membuka pihak menampilkan identitas, alamat, informasi kontak, hubungan, peran bisnis,
dan akun vendor. Simpan dan Arsipkan tidak lagi mengubah state lokal saja.

Shell menyediakan satu pintu **Buku alamat › Party**. Detail, tambah, dan ubah Party
memakai halaman penuh dengan FastTab dari `CollapsibleSectionGroup` dan
`CollapsibleSection` SDK; identitas, alamat, kontak, hubungan, dan peran bukan menu
Shell terpisah. `RecordActionBar` memuat tindakan record. Font, kontrol, dan tema
mengikuti SDK serta Shell, tanpa stylesheet atau font khusus halaman.

General memakai dua kolom pada desktop dan satu kolom pada layar kecil. Label nama
dibedakan antara organisasi dan perorangan; status memakai Switch. Nama pencarian
mengikuti ketikan nama hingga pengguna mengubah nama pencarian sendiri, lalu menjadi
nilai mandiri. Bahasa pihak baru memakai Bahasa Indonesia. Alias dan pemecahan nama
perorangan (gelar, nama depan/tengah/belakang, inisial, profesi, sertifikasi) disembunyikan
dari form dan detail tanpa menghapus nilai yang sudah tersimpan.

NIB (`organization_number`) berada pada General untuk pihak organisasi. DUNS
disembunyikan dari form dan detail. Jumlah karyawan tidak dikirim oleh form;
nilai kosong/0 disembunyikan dan nilai positif yang sudah tersimpan ditampilkan
read-only. Pembaca integrasi jumlah karyawan belum tersedia, sebagaimana catatan
di kode. Klasifikasi ABC disembunyikan sampai ada proses yang memakainya.

Pemilihan alamat memakai master wilayah secara berurutan: negara, provinsi,
kota/kabupaten, kecamatan, kelurahan/desa, lalu kode pos. `postal_addresses`
menyimpan ID setiap master beserta teks untuk tampilan dan cetakan. Server menolak
anak wilayah yang bukan milik induk pilihan dan mengambil nama dari master, bukan
memercayai teks kiriman. Migration hanya menambah relasi nullable; teks alamat lama
tetap utuh dan tidak dipetakan otomatis berdasarkan nama. Jalan, gedung, RT/RW,
dan PO Box tetap input bebas. Pilihan penagihan/pembayaran disembunyikan dari form,
tetapi kegunaan lama tetap disimpan.

Daftar kontak memakai DataTable SDK dengan keterangan, tipe, nomor/alamat, serta
checkbox utama. Input nomor/alamat mengikuti tipe kontak; keterangan memakai
Textarea. Kontak baru dihubungkan ke alamat utama secara otomatis, sedangkan tautan
alamat kontak lama dipertahankan. Ikon mengikuti tipe; link telepon/email/web memakai
scheme yang sesuai. Daftar peran kosong memakai Empty SDK; DataTable baru dirender
setelah ada peran sehingga tabel kosong tidak memunculkan scrollbar horizontal.

Dataset detail wilayah yang disertakan hanya Indonesia. `DatabaseSeeder` tetap
memasang daftar negara dan zona waktu, tetapi seeder provinsi/kota/desa dunia serta
pembangkit wilayah/kode pos sintetis sudah dihapus. Alamat luar Indonesia memakai
teks wilayah; data dunia yang pernah tersimpan tidak dihapus dari database.

`IndonesianAddressHierarchySeeder`, `address:import-indonesia`, dan migration
`2026_10_15_030000_populate_indonesian_address_hierarchy.php` memakai pembaca yang sama
di `database/data/import_indonesia_hierarchy.php`. Pembaca mencocokkan kode resmi
dalam scope Indonesia, mempertahankan PK baris terstruktur, menolak kode ganda atau
induk yang hilang, dan menyimpan kode pos per desa dari dataset bundel. SQL sumber
dibaca sebagai pasangan data, tidak dieksekusi. Pengulangan impor melewati baris yang
sudah sama. Impor tidak mengganti ID tabel terstruktur dengan ID registry generik.

Provenance bundel saat ini: `wilayah.sql` menyatakan Kepmendagri 300.2.2-2138/2025,
last edit 13 Februari 2026; `wilayah_kodepos.sql` menyatakan rujukan yang sama,
last edit 30 Juni 2025, keduanya dari cahyadsn dengan lisensi MIT. Ini bukan klaim bahwa
seluruh perubahan administratif terbaru sudah tercakup. Pembaruan dataset berikutnya
perlu mencatat keputusan dan versi sumber serta membandingkan kode yang berubah.

Alamat, kontak, serta peran tetap terlihat sebagai FastTab saat mengubah pihak.
Pihak baru perlu disimpan sebelum alamat atau kontak ditambahkan, karena keduanya
memerlukan identitas record yang sudah tersimpan. Peran diturunkan dari akun bisnis,
bukan checkbox yang menciptakan akun vendor sendiri.

Modelnya tetap **F&O Global Address Book**, sesuai keputusan sebelumnya. BC memiliki
Contacts Company/Person, Contacts list, Vendor Card, dan hubungan kontak–vendor, tetapi
bukan pengganti model party bersama lintas perusahaan yang dipakai CoreERP. Referensi:
[Party and global address book](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/dual-write/party-gab),
[Contacts BC](https://learn.microsoft.com/en-us/dynamics365/business-central/marketing-contacts),
dan [sinkronisasi kontak BC](https://learn.microsoft.com/en-us/dynamics365/business-central/marketing-setup-contacts#synchronize-contacts-with-customers-vendors-and-bank-accounts).
Diagram dual-write menjelaskan hubungan antarsistem; tabel Dataverse tidak disalin menjadi skema CoreERP.

### Identitas dan nomor

`parties.id` tetap id teknis untuk seluruh relasi. `parties.number` adalah nomor yang
dibaca pengguna, diterbitkan melalui reference `core.party`, scope tenant, non-continuous,
tanpa nomor manual, tanpa reset. Prefix awal adalah `PIHK`, dengan contoh
`PIHK00001`–`PIHK19999`; setelan tetap ada di Atur nomor. Nomor vendor memakai reference
`core.vendor` sendiri dan tetap berlaku per entitas legal. Membuat pihak lewat Vendor
atau alamat Organisasi memakai `SaveParty`, bukan nomor acak dari browser.

Pihak yang sudah ada sebelum revamp mempunyai nomor nullable. Aksi **Beri nomor pihak
lama** menerbitkannya sekali melalui layanan nomor yang sama; tidak mengganti id relasi.
Replay pembuatan memakai `Idempotency-Key` yang tetap, sehingga nomor/pihak tidak dibuat ulang.

Untuk Indonesia, nama lengkap wajib, tetapi nama belakang dan pemecahan nama tidak wajib.
Profil orang meliputi gelar, rincian nama, alias, profesi, tanggal lahir/pernikahan,
jenis kelamin, status pernikahan, jumlah anak, minat, bahasa, dan catatan; rincian pribadi
opsional. Profil organisasi meliputi nomor registrasi/NIB, jumlah karyawan, klasifikasi ABC,
dan DUNS opsional. **Nomor registrasi/NIB tidak digabung dengan NPWP.** NPWP masih milik akun
vendor pada desain yang tersedia hari ini. Pilihan klasifikasi/bahasa dan label jenis pihak
dibaca dari database; nilai awal baru dikirim melalui migration.

Prioritas pembayaran disimpan pada **akun vendor per perusahaan**, bukan identitas global.
Nilai kosong berarti tidak diberi prioritas; bilangan positif yang lebih kecil menandakan
prioritas lebih tinggi, mengadaptasi [prioritas vendor BC](https://learn.microsoft.com/en-us/dynamics365/business-central/purchasing-how-prioritize-vendors).
Revamp ini menyimpan nilainya sebagai informasi; tidak membuat saran pembayaran atau
menjalankan pembayaran otomatis.

### Alamat, hubungan, dan peran

- Party dibagi seluruh tenant; akun vendor dan registry peran menyimpan entitas legal.
  Buku alamat adalah kelompok pencarian milik tenant, bukan pemberi hak akses.
- `address_books` dan `party_address_books` mengelompokkan pihak. Mengarsipkan buku alamat
  melepas keanggotaan aktifnya, tetapi tidak mengarsipkan pihak.
- Alamat vendor memakai `party_locations` dan `locations` yang sama dengan buku alamat.
  Layanan `OrganizationAddressBook` juga menerima `Party`, sehingga aturan utama, lokasi
  bersama, dan arsip tautan tidak diduplikasi. Kecamatan, kelurahan/desa, RT, RW, kota/kabupaten,
  provinsi, kode pos, jalan, gedung, dan PO Box disimpan pada alamat pos; lokasi di luar
  Indonesia tetap dapat memakai negara yang sesuai.
- `party_relationships` mencatat hubungan berarah dengan masa berlaku, memakai katalog
  `party_relationship_types`. Kedua pihak wajib satu tenant; hubungan ke diri sendiri dan
  periode tumpang tindih untuk hubungan yang sama ditolak. Hubungan cabang/anak perusahaan
  tidak mengganti hierarki organisasi Core.
- Peran bisnis dibaca dari `party_role_registrations`, tidak diketik sebagai hubungan.
  Akun vendor dibuat lewat halaman Vendor memakai `party_id` existing. Nama bukan kunci
  identitas dan tidak dipakai untuk menggabungkan pihak secara otomatis.
- Perubahan nama pihak terlihat pada semua akun vendor pihak itu. Nama organisasi internal
  tetap diubah melalui halaman Organisasi; direktori menolak menggantinya agar tidak bersaing
  dengan penulis identitas organisasi.
- Pihak yang masih dipakai organisasi atau peran bisnis tidak dapat diarsipkan dari direktori.
  Status nonaktif menghentikan pilihan baru; riwayat dan akun yang sudah ada tetap tersimpan.
  Pihak terarsip dapat ditemukan lewat saringan **Tampilkan → Pihak terarsip** dan dipulihkan
  melalui menu baris. Pemulihan mempertahankan id, nomor, profil, alamat, dan kontaknya.

Pintu UI berada di `api/v1/parties`, `.../{party}/locations`, `.../contacts`,
`.../relationships`, dan `api/v1/address-books`. Seluruhnya memakai session tenant dan
permission `core.organization.read`/`core.organization.update` yang sudah menjaga layar ini.
Nomor, tenant, dan peran tidak diterima sebagai field bebas dari browser. Update/archive
memakai versi record. Endpoint POST untuk **klien integrasi luar tetap ditunda**; tidak
dibuka melalui token integrasi oleh revamp ini.

Kode utama: `GlobalAddressBookController`, `SaveParty`, dan
`apps/core/resources/js/pages/platform/address-book/index.tsx` beserta komponen dan `types.ts`.
Migration `2026_10_14_190000_complete_party_directory` menambah kolom profil/nomor, kelompok,
hubungan, dan rincian alamat Indonesia. Data acuan berada pada migration idempoten
`2026_10_14_190100_seed_party_directory_reference_data`, sehingga fixture test yang dikosongkan
dapat dipulihkan tanpa membuat ulang skema. Platform memakai kontrak `PartyNumbers` yang
implementasinya didaftarkan Foundation; akun vendor dibaca lewat `VendorDirectory`, dan
Foundation mendengarkan event dalam runtime `PartyNameChanged` untuk memperbarui waktu akun.
Pengguna meminta rebuild runtime serta pengujian
akhir dilakukan sendiri; perubahan ini belum diverifikasi di container sesudah revamp.

## Akun vendor per entitas legal

Peran pertama yang benar-benar didaftarkan, dan dimiliki Core, bukan app: feed posting finance membutuhkan vendor sebelum ada modul hutang ([PRD](../todo/feed-posting-finance/README.md), K-06). Layarnya di **Buku alamat › Vendor** (`settings/vendors`).

- Tabel `vendors` menyimpan akun vendor per legal entity: nomor, status, dan prioritas pembayaran. Nama, NPWP, alamat, dan kontak milik party, jadi mengganti identitasnya berlaku pada semua akun vendor pihak tersebut. Kolom `vendors.tax_number` dipertahankan sebagai salinan untuk pembaca dan image N-1; pengelolaannya di UI melalui Party.
- Menyimpan vendor baru menulis party (bila baru), akun vendor, dan baris `party_role_registrations` berperan `vendor` dengan `owning_app_id = core` dalam satu transaksi.
- Satu party paling banyak satu vendor per legal entity; database menolak yang kedua.
- Nomor dari reference `core.vendor` ([Number sequences](14-number-sequences.md#reference-milik-core-sendiri)); nomor dan legal entity tidak dapat diubah sesudah disimpan.
- Module membaca vendor lewat kontrak `VendorDirectory`, sistem di luar CoreERP lewat `GET /api/internal/v1/vendors` dengan token klien integrasi.

Detail baca, tambah, dan ubah Vendor memakai halaman penuh dengan FastTab General,
Alamat, dan Informasi kontak, bukan Sheet. Sesudah membuat vendor, record hasil
respons tetap terbuka dalam mode ubah agar alamat dan kontak dapat dilengkapi.
Keduanya tetap ditulis lewat Party; halaman Vendor tidak membuat salinan data tersebut.
Lookup pemasok menampilkan akun vendor yang sudah dimiliki Party beserta perusahaan
dan status akunnya. Satu Party tetap satu pilihan walaupun mempunyai akun di beberapa
perusahaan. Informasi akun berasal dari tenant yang sama; pemilihan tetap menyimpan
`party_id`, bukan nomor akun perusahaan lain. Membuat akun kedua pada perusahaan yang
sama tetap ditolak backend.

Pada form vendor baru, **Nomor vendor** juga merupakan lookup akun pada perusahaan
tujuan. Memilih akun membuka kartu baca akun tersebut tanpa membuat akun baru.
Memilih Party yang sudah mempunyai akun di perusahaan tujuan, termasuk sesudah
mengganti perusahaan, memeriksa akun lewat backend lalu membuka kartu yang sama.
Simpan dinonaktifkan selama pemeriksaan; validasi unik backend tetap menjadi
pengaman permintaan bersamaan. Nomor baru tetap mengikuti pengaturan number sequence.

Lookup memakai `GET /api/v1/vendors/lookup`, `vendors/party-options`, dan
`vendors/company-options`, masing-masing dengan query pencarian dan cursor internal.
Respons berisi `data` serta `next_cursor`; batch dibatasi 40 record tanpa total count
atau offset. Query akun dibatasi perusahaan tujuan; query Party memuat akun dan
perusahaannya sekaligus, bukan satu query tambahan per baris. Lookup alamat bersama
pada Party/organisasi memakai envelope yang sama dan mengecualikan tautan aktif
milik Party pemanggil. Semua endpoint tersebut adalah API session UI, bukan kontrak
integrasi lintas runtime.
Popup dan Daftar lengkap berbagi hasil/cursor; batch awal tidak diminta ulang hanya
karena pengguna membuka daftar lengkap. Cache singkat milik instance field tidak
menyimpan data lintas halaman/tenant. API JSON juga melewati penyusunan props Shell
di `HandleInertiaRequests`, karena respons tersebut tidak memakai props Inertia;
middleware otentikasi dan pemeriksaan izin controller tetap berjalan.
Pada kartu baca maupun kartu ubah vendor, pemegang izin ubah buku alamat dapat
langsung menambah atau mengubah alamat dan kontak lewat form yang sama dengan GAB.
Pengguna tidak perlu berpindah halaman. Hak baca vendor saja tidak memberi hak
mengubah identitas bersama; endpoint Party tetap memeriksa izin buku alamat.

### Pemilik tempat bersama

`locations.owner_party_id` menentukan satu Party pemilik tempat. Tempat baru dimiliki
Party pembuatnya; menautkan tempat yang sudah ada tidak memindahkan kepemilikan.
`is_location_owner` pada respons alamat/kontak dihitung server, bukan diterima dari
form. Hanya melalui Party pemilik alamat dan kontak pada tempat tersebut dapat
diubah, termasuk kontak utama. Hak ubah buku alamat tetap diwajibkan.
Pemakai lain dapat menautkan, menjadikan alamat utama, atau mengarsipkan tautannya sendiri; alamat bersama
tidak diubah dan pemilik tidak otomatis berganti. Pemilik yang melepas tautan dapat
menautkannya kembali untuk mengelola alamat tersebut. Pemindahan pemilik belum
disediakan pada UI.

Migration menetapkan pemilik data lama dari tautan aktif tertua, lalu tautan
terarsip bila tidak ada tautan aktif; ini aturan pengisian data lama, bukan bukti
siapa pembuat historisnya. Tempat tanpa Party yang masih tersedia tidak diberi
pemilik secara tebakan dan tidak dapat diubah lewat Party lain.

Kebutuhan aset tetap saat ini memakai akun ini untuk penerimaan, penjamin garansi,
penanggung asuransi, dan vendor kontrak servis. Nomor/referensi faktur dan mata uang
transaksi tetap berada pada dokumen pemiliknya. Grup vendor, syarat/metode pembayaran,
rekening bank, dan posting hutang belum menjadi pengaturan master pada halaman ini;
FastTab tidak menampilkan field kosong yang prosesnya belum tersedia.

Migration `2026_10_15_010000_share_party_tax_number.php` memindahkan NPWP akun lama
ke pihaknya. Bila satu pihak mempunyai NPWP berbeda pada akun-akunnya, migration
berhenti sebelum mengubah skema; nomor harus disamakan terlebih dahulu. Perbedaan
format titik/tanda hubung tidak dianggap NPWP berbeda. Foundation mendengarkan
`PartyTaxNumberChanged` dalam transaksi yang sama dan memperbarui salinan serta
waktu/versi akun vendor agar sinkronisasi incremental melihat perubahan tersebut.
Membuat akun vendor dari Party existing mewarisi NPWP; kiriman NPWP yang berbeda
ditolak agar pembuatan akun lain tidak menimpa identitas bersama. Ini identitas pajak
Indonesia yang sederhana, bukan implementasi seluruh Registration IDs F&O dengan
jenis registrasi, negara, alamat, dan masa berlaku.

Lookup pemasok pada vendor menyediakan **Baru** hanya bagi pemegang izin ubah buku
alamat. Aksi itu membuka `PartyForm` yang sama di `PageDialog`, dengan metadata dari
`GET /api/v1/parties/metadata` (izin ubah buku alamat, tenant aktif). Setelah Party
aktif tersimpan, responsnya langsung dimasukkan ke lookup dan dipilih; perusahaan,
nomor manual, prioritas, serta status draft vendor tetap terjaga. Vendor belum dibuat
sampai pengguna menyimpan form vendor. Party yang disimpan nonaktif tetap tersimpan,
tetapi tidak dipilih sebagai pemasok aktif.

Prioritas pembayaran vendor tetap disimpan untuk kompatibilitas data, tetapi field
dan FastTab Pembayaran disembunyikan sampai ada proses atau integrasi yang memakainya.
Perubahan vendor tetap mempertahankan nilai lama yang tidak ditampilkan tersebut.

### FactBox vendor untuk perolehan aset

Daftar dan kartu `/settings/vendors` memakai FactBox SDK untuk identitas, lampiran
record `vendors`, dan perolehan aset. Memilih baris hanya mengganti konteks FactBox;
nomor vendor atau menu baris membuka kartu. Tidak ada salinan alamat/kontak vendor:
FastTab membaca Party yang sama dengan Buku Alamat Global.

`GET /api/v1/vendors/{vendor}/activity` adalah endpoint UI, dijaga izin baca vendor
dan tenant record. `VendorActivity` meminta dataset `management-aset.asset-receipts`
melalui `RunQuery` dan principal pengguna. Ia tidak membaca tabel module secara
langsung dan tidak memerlukan izin membuka penjelajah analitik. Perusahaan aktif
harus sama dengan perusahaan vendor; izin dataset, pemasangan module, dan policy
`management-aset.asset-responsibility` tetap diterapkan engine.

Ringkasan hanya memakai penerimaan berstatus selesai: jumlah dokumen unik, jumlah
unit, dan nilai sebelum PPN per mata uang. Daftar penerimaan terbaru memakai
`receipt_id` dari dataset versi 2 untuk membuka dokumen asal. Angka ini bukan nilai
buku aset saat ini dan bukan saldo utang. Feed posting tidak menyimpan pelunasan atau
buku pembantu vendor, sehingga halaman tidak menyimpulkan saldo dari total debit
jurnal. Pengaturan pembayaran yang belum mempunyai proses tetap tidak ditampilkan.

## Yang belum ada

Direktori sudah mempunyai API session untuk party, alamat, kontak, dan hubungan. Akun
pelanggan/pegawai tetap dimiliki fitur pemiliknya; revamp ini tidak membuat akun bisnis
tersebut hanya karena pihak atau hubungan kontak sudah ada. API tulis party untuk sistem
luar, sinkronisasi otomatis, merge party, riwayat alamat pos, dan aturan pajak lengkap belum
dibangun.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `apps/core/app/Platform/AddressBook/Models/{Party,Location,PartyLocation,PartyLocationPurpose,LocationPurpose,PostalAddress,ElectronicAddress}.php`, `apps/core/app/Platform/Organization/Models/OrganizationParty.php`, `apps/core/app/Platform/Geography/Models/CountryRegion.php` | Model buku alamat |
| `apps/core/app/Platform/AddressBook/Support/OrganizationAddressBook.php` | Tautan organisasi ke party, aturan satu utama, ringkasan untuk kop |
| `apps/core/app/Platform/AddressBook/Support/PostalAddressFormatter.php` | Bentuk tercetak alamat |
| `apps/core/app/Platform/AddressBook/Http/Controllers/` | Endpoint alamat dan kontak organisasi |
| `apps/core/resources/js/pages/platform/organization/components/address-book-section.tsx` | Dua bagian pada kartu organisasi |
| `apps/core/tests/Feature/Platform/AddressBook/OrganizationAddressBookTest.php` | Utama otomatis dan berpindah, satu utama per jenis, kop membaca buku alamat, hak akses |
| `apps/core/tests/Feature/Platform/AddressBook/SharedLocationTest.php` | Tempat bersama, arsip tautan, kegunaan ganda, kontak per tempat, isolasi tenant |
| `apps/core/tests/Feature/Platform/AddressBook/SharedLocationConcurrencyTest.php` | Index yang menolak dua utama dan tautan ganda dari dua koneksi |
| `apps/core/app/Foundation/Vendor/Actions/SaveVendor.php`, `apps/core/app/Foundation/Vendor/Models/Vendor.php` | Vendor: party, akun vendor per legal entity, dan registry perannya |
| `apps/core/tests/Feature/Foundation/Vendor/VendorTest.php` | Nomor vendor, satu vendor per party per legal entity, isolasi tenant, sinkron `updated_since` |

## Halaman terkait

- [Tenant dan hierarki organisasi](01a-tenant-and-org-hierarchy.md) — legal entity dan operating unit yang menjadi party
- [Dokumen cetak, layout, dan ekspor](23-document-rendering.md) — identitas cetak yang membaca alamat dan kontak dari sini
