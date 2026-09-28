# Buku alamat: party, alamat, dan kontak

Halaman ini untuk developer yang menyentuh alamat atau kontak sebuah pihak: organisasi tenant sendiri, dan nanti pelanggan, pemasok, atau pegawai milik app. Ia menjelaskan apa yang disimpan Core, aturan yang ditegakkan kodenya, dan mengapa alamat organisasi tidak boleh disalin ke tabel lain.

## Mengapa alamat ada di Core

Mengikuti Global Address Book Dynamics 365, yang tinggal di lapisan platform dan bukan sebuah modul. **Party** adalah identitas satu pihak, orang atau organisasi, beserta alamat pos dan kontak elektroniknya. Perannya (pelanggan, pemasok, pegawai) berlaku per legal entity dan dimiliki app, kecuali vendor yang dimiliki Core sendiri (lihat **Vendor** di bawah); Core menyimpan registry semua peran.

Legal entity dan operating unit ikut menjadi party karena keduanya punya nama dan alamat yang tercetak pada dokumen resmi. Identitas organisasi sudah di Core, dan memisahkan alamatnya berarti membelah satu identitas ke dua database. Ini juga yang menutup godaan setiap fitur menambah kolom alamatnya sendiri: identitas cetak pernah menyimpan alamat dan telepon, lalu dibongkar pada 2026-09-05 supaya alamat hanya pernah diubah di satu tempat.

## Yang disimpan

Skema lengkapnya ada di `apps/core/database/migrations/2026_07_27_020000_create_party_and_address_book_tables.php`; daftar negara ISO 3166-1 diisi `2026_09_05_140000_seed_country_regions.php` sebagai migrasi, bukan seeder, karena tanpa isinya tidak ada alamat yang dapat disimpan.

| Tabel | Isi | Aturan yang dijaga database |
| --- | --- | --- |
| `parties` | Nama pihak, jenis `person` atau `organization`, `search_name` untuk pencarian | Composite unique `(tenant_id, id)` agar tabel anak menolak induk milik tenant lain |
| `party_locations` | Satu lokasi: nama, kegunaan (`business`, `delivery`, `invoice`, `payment`, `home`), utama atau bukan | Partial unique index: satu lokasi utama per party |
| `postal_addresses` | Alamat pos satu lokasi dan bentuk tercetaknya `formatted` | Kode negara wajib ada di `country_regions` |
| `electronic_addresses` | Kontak: `email`, `phone`, `whatsapp`, `fax`, `url`, dengan keterangan dan penanda utama | Partial unique index: satu kontak utama per party per jenis |
| `organization_parties` | Tautan organisasi ke party-nya, satu ke satu | Unique per party, sehingga satu party paling banyak mewakili satu organisasi |
| `party_role_registrations` | Registry peran party per legal entity, ditulis app pemilik peran | Unique `(party, peran, legal entity)` |

WhatsApp adalah jenis kontak tersendiri, bukan telepon berlabel, karena kop dan dokumen menampilkannya terpisah dari nomor telepon kantor.

## Buku alamat organisasi

Kartu organisasi di `settings/organization` punya bagian **Alamat Utama & Cabang** dan **Informasi Kontak & Komunikasi** untuk legal entity, dan padanannya untuk operating unit. Keduanya dilayani Core lewat `api/v1/organizations/{organization}/locations` dan `.../contacts`, dengan kode di `app/Support/AddressBook/OrganizationAddressBook.php` dan controller di `app/Http/Controllers/GlobalAddressBook/`.

Aturan yang ditegakkan kode, dan alasannya:

- **Party organisasi dibuat saat pertama dibutuhkan, bukan saat organisasi dibuat.** Organisasi yang sudah ada sebelum buku alamat ikut mendapat party begitu alamatnya dibuka, tanpa backfill. Nama party disamakan dengan nama organisasi setiap kali dibaca, jadi mengganti nama legal entity tidak meninggalkan party bernama lama.
- **Lokasi pertama otomatis utama; kontak pertama suatu jenis otomatis utama.** Tanpa ini kop dokumen kosong sampai seseorang ingat mencentang "utama". Menandai lokasi lain sebagai utama menurunkan yang lama dalam transaksi yang sama; database tetap menolak dua utama bila dua request berpacu.
- **Menghapus yang utama menaikkan yang tertua tersisa.** Dokumen tidak boleh kehilangan alamat hanya karena alamat utama dihapus.
- **Bentuk tercetak disusun saat disimpan, ke kolom `formatted`.** Aturannya di `PostalAddressFormatter`: jalan dan gedung, PO Box, kelurahan atau kecamatan, lalu kota, provinsi, dan kode pos. Nama negara hanya ditulis untuk alamat di luar Indonesia. Dokumen resmi menyalin bentuk ini, sehingga perubahan alamat kemudian tidak menulis ulang dokumen lama.
- **Kode negara dinormalkan lalu diperiksa ke tabel.** `id` dan `ID` sama; `ZZ` ditolak 422, bukan disimpan sebagai teks bebas.
- **Semua anggota tenant boleh membaca; hanya admin tenant (`canManageAccess`) boleh mengubah.** Organisasi tenant lain dijawab 404, bukan 403, supaya keberadaannya tidak bocor.

## Yang membaca buku alamat

**Identitas cetak** ([Dokumen cetak, layout, dan ekspor](23-document-rendering.md)) mengisi `kop.alamat*` dari alamat utama dan `kop.telepon`, `kop.whatsapp`, `kop.fax`, `kop.email`, `kop.laman` dari kontak utama tiap jenis. Ringkasan itu dibaca lewat `OrganizationAddressBook::summary()` tanpa membuat party, supaya sekadar mencetak tidak menulis apa pun. Halaman identitas cetak hanya menampilkan alamat dan kontak yang akan tercetak; mengubahnya dilakukan di dua bagian di atas.

## Vendor

Peran pertama yang benar-benar didaftarkan, dan dimiliki Core, bukan app: feed posting finance membutuhkan vendor sebelum ada modul hutang ([PRD](../todo/feed-posting-finance/README.md), K-06). Layarnya di **Buku alamat › Vendor** (`settings/vendors`).

- Tabel `vendors` menyimpan akun vendor per legal entity: nomor, NPWP, dan status. Nama, alamat, dan kontak tetap milik party, jadi mengganti nama vendor mengganti nama party-nya di semua legal entity.
- Menyimpan vendor baru menulis party (bila baru), akun vendor, dan baris `party_role_registrations` berperan `vendor` dengan `owning_app_id = core` dalam satu transaksi.
- Satu party paling banyak satu vendor per legal entity; database menolak yang kedua.
- Nomor dari reference `core.vendor` ([Number sequences](14-number-sequences.md#reference-milik-core-sendiri)); nomor dan legal entity tidak dapat diubah sesudah disimpan.
- Module membaca vendor lewat kontrak `DaftarVendor`, sistem di luar CoreERP lewat `GET /api/internal/v1/vendors` dengan token klien integrasi.

## Yang belum ada

Party untuk pelanggan dan pegawai belum punya API. Saat app pertama membutuhkannya, endpoint-nya masuk di `api/v1/parties` dengan aturan "satu utama" yang sama, dan app menulis registry perannya lewat API internal, bukan menyalin alamat ke databasenya sendiri.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `apps/core/app/Models/{Party,PartyLocation,PostalAddress,ElectronicAddress,OrganizationParty,CountryRegion}.php` | Model buku alamat |
| `apps/core/app/Support/AddressBook/OrganizationAddressBook.php` | Tautan organisasi ke party, aturan satu utama, ringkasan untuk kop |
| `apps/core/app/Support/AddressBook/PostalAddressFormatter.php` | Bentuk tercetak alamat |
| `apps/core/app/Http/Controllers/GlobalAddressBook/` | Endpoint alamat dan kontak organisasi |
| `apps/core/resources/js/components/organization/address-book-section.tsx` | Dua bagian pada kartu organisasi |
| `apps/core/tests/Feature/ControlPlane/OrganizationAddressBookTest.php` | Utama otomatis dan berpindah, satu utama per jenis, kop membaca buku alamat, hak akses |
| `apps/core/app/Actions/Finance/SaveVendor.php`, `apps/core/app/Models/Vendor.php` | Vendor: party, akun vendor per legal entity, dan registry perannya |
| `apps/core/tests/Feature/ControlPlane/VendorTest.php` | Nomor vendor, satu vendor per party per legal entity, isolasi tenant, sinkron `updated_since` |

## Halaman terkait

- [Tenant dan hierarki organisasi](01a-tenant-and-org-hierarchy.md) — legal entity dan operating unit yang menjadi party
- [Dokumen cetak, layout, dan ekspor](23-document-rendering.md) — identitas cetak yang membaca alamat dan kontak dari sini
