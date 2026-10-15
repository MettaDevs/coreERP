# Perencanaan aset

Halaman ini untuk developer. Isinya dokumen rencana pengadaan aset — yang dibuat **sebelum** barangnya ada.

Rencana aset adalah daftar barang yang akan diadakan beserta jumlah dan satuannya. Ia belum menjadi aset; aset baru lahir saat barangnya diterima.

## Mengenali rencana dan membuat permintaan

Field **Kode perencanaan** mengikuti pengaturan nomor Core: otomatis menampilkan pesan bahwa kode dibuat saat disimpan, manual mewajibkan kode yang diketik, dan keduanya membolehkan kode kosong untuk nomor otomatis. Kode tidak berubah setelah dokumen disimpan.

Field `description` ditampilkan sebagai **Tujuan perencanaan**. Daftar dan pilihan perencanaan menampilkan kode bersama tujuan; pilihan juga menampilkan tanggal agar petugas mengenali sumber permintaan. Data lama tanpa tujuan memakai teks “Tanpa tujuan”.

Dari **Buka rincian**, petugas memilih baris lalu menekan **Buat permintaan**. Form permintaan terbuka dengan rencana dan baris terpilih untuk diperiksa sebelum disimpan. Dropdown perencanaan tetap tersedia saat membuat permintaan dari halamannya sendiri.

Pola ini mengadaptasi identitas Name/Description dan tindakan dari baris pada [Planning Worksheet Business Central](https://learn.microsoft.com/en-us/dynamics365/business-central/application/base-application/page/microsoft.inventory.requisition.planning-worksheet). CoreERP meneruskannya ke permintaan pembelian sesuai alur yang disepakati.

## Bentuknya: satu dokumen, banyak baris

| Tabel | Isi |
| --- | --- |
| `aset_tr_perencanaan_aset` | Dokumennya: nomor rencana, entitas, unit kerja, status, versi |
| `aset_tr_perencanaan_aset_details` | Baris: barang apa, berapa banyak, satuan apa |

Dokumen permintaan pembelian memakai bentuk yang sama — `aset_tr_permintaan_pengadaan_aset` dan `aset_tr_permintaan_pengadaan_aset_details` — dengan perilaku yang lebih sedikit: lihat [Hubungannya dengan pengadaan](#hubungannya-dengan-pengadaan).

Satuan diambil dari Core lewat `UnitOfMeasureClient`, bukan disimpan sebagai teks bebas. Kalau layanan satuan belum bisa dihubungi, permintaan gagal 503 — bukan menyimpan satuan yang tidak bisa diverifikasi.

## Status

| Status | Bisa diubah? | Bisa diarsipkan? |
| --- | --- | --- |
| `draft` | Ya | Ya |
| Selain draft | Tidak | Tidak |

Aturannya tegas: **hanya rencana draf yang dapat diubah atau diarsipkan.** Rencana yang sudah keluar dari draf sudah menjadi dasar keputusan orang lain.

## Penanda versi

Perubahan dan pengarsipan wajib membawa `version`. Kalau dokumen sudah berubah sejak terakhir dibaca, permintaan ditolak sebagai versi basi — bukan ditimpa.

Alasannya: rencana disusun banyak orang. Dua orang yang membuka rencana yang sama lalu menyimpan bergantian akan saling menghapus perubahan tanpa sadar, dan tidak ada yang menyadarinya sampai barang yang dipesan ternyata salah.

Pola yang sama dipakai work order.

## Endpoint

| Endpoint | Gunanya |
| --- | --- |
| `GET /api/v1/perencanaan-aset` | Daftar |
| `POST /api/v1/perencanaan-aset` | Membuat rencana |
| `GET /api/v1/perencanaan-aset/{id}` | Detail beserta barisnya |
| `PATCH /api/v1/perencanaan-aset/{id}` | Mengubah, hanya draf |
| `DELETE /api/v1/perencanaan-aset/{id}` | Mengarsipkan, hanya draf |

## Aturan yang dijaga

**Master pada tiap baris divalidasi terhadap tenant.** Barang yang disebut harus benar-benar ada di master milik tenant itu dan belum diarsipkan.

**Nomor dari Core.** Sama seperti dokumen lain; kalau reference belum aktif, permintaan gagal 503.

**`Idempotency-Key` wajib pada pembuatan.**

## Hubungannya dengan pengadaan

Rencana **belum** memesan apa pun. Langkah berikutnya dokumen permintaan pembelian (`RPPA`), yang tiap baris barunya **wajib** menunjuk baris rencana lewat `planning_detail_id`. Perusahaan, jenis aset, dan satuan harus sesuai dengan rencana asal. Baris rencana tidak boleh diulang dalam satu permintaan. Yang sudah dikerjakan:

- daftar, lihat, dan buat permintaan (nomor dari Core per entitas legal, `Idempotency-Key` wajib),
- ubah selama masih **draf**,
- batalkan selama masih draf atau diajukan (`POST …/permintaan-pembelian-aset/{id}/batal`).
- cetak satu permintaan lewat dataset `purchase-request` dan layout Word bawaan, dengan kop dari Core dan tempat tanda tangan.

Keputusan pemilik produk, 14 Oktober 2026: sampai Procurement tersedia, petugas cukup mencetak permintaan. Persetujuan dan pembelian di luar aplikasi tidak menjadi status atau syarat penerimaan di sistem. Tidak ada pengajuan atau workflow persetujuan baru untuk permintaan ini.

Saat barang datang, petugas memilih nomor permintaan dan baris barang pada penerimaan di inventarisasi. Satu permintaan boleh diterima melalui beberapa dokumen penerimaan: meminta 10 unit, menerima 4, lalu menerima 6. `received_quantity` dan `remaining_quantity` pada rincian permintaan dihitung dari penerimaan selesai; draf dan penerimaan dibatalkan tidak dihitung. Penerimaan terhubung harus memakai perusahaan dan jenis aset yang sama; rujukan pembelian tidak berlaku untuk hibah atau saldo awal. Penerimaan tanpa rujukan tetap tersedia untuk pencatatan lain; yang diwajibkan saat ini adalah asal perencanaan pada permintaan.

Pemilih permintaan pada penerimaan menyediakan **Tanpa permintaan asal** untuk
penerimaan langsung. Pilihan ini mengosongkan `permintaan_pembelian_detail_id`;
ia tidak membuat permintaan atau rencana pengganti. Nama, klasifikasi, jumlah,
dan nilai barang diisi langsung pada baris penerimaan. Mengganti rujukan menjadi
tanpa permintaan mempertahankan rincian barang yang sudah diisi. Laporan perolehan
tetap mencakup penerimaan tersebut; laporan pengadaan berbasis rencana/permintaan
tidak memasukkannya karena tidak mempunyai kebutuhan asal.

Penyelesaian mengunci dokumen permintaan sebelum menghitung sisa, dan menjumlah seluruh baris penerimaan yang menunjuk baris permintaan yang sama. Dua penyelesaian bersamaan tidak boleh menerima lebih dari jumlah diminta. Permintaan yang dipakai pada penerimaan tidak dapat diubah atau dibatalkan; rencana yang sudah dirujuk permintaan tidak dapat diubah atau diarsipkan. Ini menjaga hubungan dokumen asal tetap utuh.

Regresinya ada di `PermintaanPembelianAsetTest` dan `PenerimaanAsetTest`; kontrak permintaan ada di `contracts/src/paths/purchase-requests.yaml`.

Isian tambahan yang diwajibkan jenis aset, misalnya prosesor dan RAM, diisi per baris penerimaan dengan renderer yang sama seperti register. Nilainya ikut disimpan pada draf dan dipulangkan sebagai daftar `atribut` saat dokumen dibuka kembali, lalu diteruskan ke setiap aset yang lahir. Mengganti jenis aset mengosongkan atribut, kondisi, dan model yang bergantung padanya.

Alur ini mengadaptasi pemisahan [permintaan pembelian](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchase-requisitions-overview) dan [penerimaan barang](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/product-receipt-against-purchase-orders) di Dynamics 365. Penerimaan CoreERP menunjuk permintaan langsung untuk saat ini; purchase order dan integrasi Procurement tidak dibuat pada perubahan ini.

Lihat catatan di [Dokumen siklus aset](/apps/management-aset/transaction/siklus-aset/).

Sejauh mana rencana sudah diminta dan diterima dibaca di [Laporan pengadaan aset](/apps/management-aset/transaction/laporan/#laporan-pengadaan-aset): satu baris per baris rencana, dengan permintaan yang menunjuknya (`planning_detail_id`) dan penerimaan dari permintaan itu.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `src/Http/Controllers/transaksi/PerencanaanAset/PerencanaanAsetController.php` | Seluruh logika |
| `src/Services/AssetUnitOfMeasureDirectory.php` | Satuan dari Core |
| `ui/transactions/perencanaan-aset/PlanningPage.tsx` | Layar |

## Halaman terkait

- [Register aset](/apps/management-aset/transaction/register-aset/) — langkah setelah barang diterima
- [Integrasi dengan Core](/apps/management-aset/arsitektur/integrasi-core) — satuan dan penomoran
