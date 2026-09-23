# Proses penyusutan

Halaman ini untuk developer. Penyiapannya — profil, buku, matriks — ada di [Penyusutan: profil, buku, dan matriks](/apps/management-aset/master/depresiasi/).

Penyusutan dijalankan **per periode, per buku**. Hasilnya baris di `aset_tr_penyusutan_aset` yang mencatat berapa yang disusutkan pada periode itu.

Ada satu tabel pendamping, `aset_tr_export_penyusutan`, yang menyimpan hasil finalisasi dalam bentuk siap diserahkan ke pembukuan. Ekspor ini jalur lama: ia digantikan proses "Post penyusutan" yang menerbitkan jurnal ke [feed posting finance](/dev/34-feed-posting-finance), dan berhenti ditulis pada TODO 11.4.

## Dua langkah, sengaja dipisah

| Langkah | Status | Bisa dibatalkan? |
| --- | --- | --- |
| **Proposal** | `proposed` | Ya, tinggal dihapus atau diulang |
| **Finalisasi** | `final` | Tidak. Harus lewat pembalikan |

Alasannya: angka penyusutan perlu diperiksa sebelum dikunci. Proposal boleh dijalankan berkali-kali dan dibandingkan; finalisasi yang membuatnya jadi kenyataan akuntansi.

## Endpoint

| Endpoint | Gunanya |
| --- | --- |
| `GET /api/v1/penyusutan` | Daftar periode |
| `GET /api/v1/penyusutan/buku` | Buku aktif yang bisa disusutkan |
| `POST /api/v1/penyusutan/proposal` | Mengusulkan satu buku |
| `POST /api/v1/penyusutan/proposal-massal` | Mengusulkan semua buku aktif sekaligus untuk satu periode |
| `POST /api/v1/penyusutan/{id}/finalisasi` | Mengunci satu periode |
| `POST /api/v1/penyusutan/{id}/reversal` | Membalik periode yang sudah final |

## Aturan yang dijaga

**Buku yang ditandai tidak disusutkan akan ditolak.** Sebagian kombinasi group × buku memang sengaja tidak disusutkan; mencoba mengusulkannya menghasilkan pesan yang menyebut alasannya, bukan hasil nol.

**Buku yang sudah ditutup ditolak.** Buku ditutup ketika asetnya dilepas. Statusnya bukan `active` lagi.

**Periode yang berakhir sebelum aset mulai disusutkan ditolak.** Aset yang mulai dipakai bulan Maret tidak punya penyusutan bulan Januari. Ini dicek terhadap tanggal mulai dipakai, bukan tanggal perolehan.

**Aset harus punya unit penggunaan pada periode itu.** Angka penyusutan dibebankan ke unit kerja, dan unit itu diambil dari riwayat penempatan yang berlaku pada periode tersebut — bukan dari unit aset sekarang. Aset yang pindah unit di tengah tahun membebani dua unit berbeda pada periode berbeda, dan itu memang yang diinginkan.

Kalau tidak ada penempatan yang berlaku, permintaan ditolak. Angka yang tidak jelas dibebankan ke siapa lebih buruk daripada tidak ada angka.

**Finalisasi aman diulang.** Kalau periode sudah `final`, permintaan ulang tidak membuat transaksi atau export kedua; ia mengembalikan yang sudah ada. Jaringan yang putus setelah server selesai memproses tidak boleh menghasilkan pembukuan ganda.

**Buku memorandum tidak diekspor, termasuk pembalikannya.** Periode buku `posting_layer = none` difinalkan tanpa baris ekspor. Pembalikan diekspor hanya bila periode aslinya dulu diekspor; sebelumnya pembalikan selalu diekspor, sehingga pembukuan menerima pembalikan atas jurnal yang tidak pernah ia terima (K-15).

**Pembalikan adalah satu-satunya jalan mundur.** Periode `final` tidak bisa dihapus atau diedit. Membalik membuat catatan baru yang meniadakan yang lama, sehingga jejaknya tetap ada.

## Hubungan dengan koreksi aset

Beberapa larangan pada register aset berasal dari sini:

- Nilai perolehan dan nilai sisa **tidak bisa diubah** kalau sudah ada periode penyusutan. Balikkan periodenya dulu.
- Tanggal mulai dipakai hanya bisa digeser kalau **belum ada** periode. Buku yang sudah berjalan memakai tanggal itu sebagai dasar periode yang terlanjur final.

## Yang datang dari Core

Tahun buku diambil dari fiscal calendar Core lewat `FiscalCalendarClient`. App tidak menyimpan kalender fiskalnya sendiri.

Ini penting: kalender fiskal milik **badan hukum**, bukan unit operasi. Itu sebabnya permintaan nomor dan hitungan periode selalu membawa `legal_entity_id`.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `src/Http/Controllers/transaksi/InventarisasiAset/DepreciationController.php` | Proposal, finalisasi, pembalikan |
| `src/Services/DepreciationCalculator.php` | Hitungan per periode |
| `src/Services/KalenderFiskalAset.php` | Tahun buku dari Core |
| `ui/transactions/inventarisasi-aset/DepreciationPage.tsx` | Layar |
| `loadtest/k6/depreciation.js` | Uji beban proposal dan finalisasi |

## Halaman terkait

- [Penyusutan: profil, buku, dan matriks](/apps/management-aset/master/depresiasi/) — penyiapan
- [Register aset](/apps/management-aset/transaction/register-aset/) — asal buku dan tanggal
- [Number sequence](/dev/14-number-sequences) — penomoran dan reset per tahun buku
