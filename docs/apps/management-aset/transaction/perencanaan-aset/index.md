# Perencanaan aset

Halaman ini untuk developer. Isinya dokumen rencana pengadaan aset — yang dibuat **sebelum** barangnya ada.

Rencana aset adalah daftar barang yang akan diadakan beserta jumlah dan satuannya. Ia belum menjadi aset; aset baru lahir saat barangnya diterima.

## Bentuknya: satu dokumen, banyak baris

| Tabel | Isi |
| --- | --- |
| `aset_tr_perencanaan_aset` | Dokumennya: nomor rencana, entitas, unit kerja, status, versi |
| `aset_tr_perencanaan_aset_details` | Baris: barang apa, berapa banyak, satuan apa |

Dokumen permintaan pembelian memakai bentuk yang sama — `aset_tr_permintaan_pengadaan_aset` dan `aset_tr_permintaan_pengadaan_aset_details` — tetapi perilakunya belum diputuskan.

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

Rencana **belum** memesan apa pun. Dokumen permintaan pembelian adalah langkah berikutnya, dan sampai sekarang rutenya sudah ada tetapi isinya belum dikerjakan — perilakunya belum diputuskan, jadi kontraknya sengaja belum ditulis.

Lihat catatan di [Dokumen siklus aset](/apps/management-aset/transaction/siklus-aset/).

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `src/Http/Controllers/transaksi/PerencanaanAset/PerencanaanAsetController.php` | Seluruh logika |
| `src/Services/DaftarSatuanAset.php` | Satuan dari Core |
| `ui/transactions/perencanaan-aset/PlanningPage.tsx` | Layar |

## Halaman terkait

- [Register aset](/apps/management-aset/transaction/register-aset/) — langkah setelah barang diterima
- [Integrasi dengan Core](/apps/management-aset/arsitektur/integrasi-core) — satuan dan penomoran
