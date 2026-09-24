# Proses penyusutan

Halaman ini untuk developer. Penyiapannya — profil, buku, matriks — ada di [Penyusutan: profil, buku, dan matriks](/apps/management-aset/master/depresiasi/).

Penyusutan dijalankan **per periode, per buku**. Hasilnya baris di `aset_tr_penyusutan_aset` yang mencatat berapa yang disusutkan pada periode itu. Jurnalnya sampai ke aplikasi finance lewat proses **Post penyusutan**, yang menerbitkan `asset.depreciation` ke [feed posting finance](/dev/34-feed-posting-finance).

Tabel pendamping `aset_tr_export_penyusutan` adalah jalur lama: hasil finalisasi dalam bentuk siap diserahkan ke pembukuan. Sejak area 11 feed posting finance ia tidak lagi ditulis; tabel dan riwayatnya dibiarkan dan tetap dapat dibaca.

## Tiga langkah, sengaja dipisah

| Langkah | Hasil | Bisa dibatalkan? |
| --- | --- | --- |
| **Proposal** | periode `proposed` | Ya, tinggal diulang |
| **Finalisasi** | periode `final`, saldo buku bertambah | Tidak. Harus lewat pembalikan |
| **Post penyusutan** | satu jurnal `asset.depreciation`, periodenya ditandai `posted_posting_id` | Tidak. Pembalikan menerbitkan jurnal baliknya |

Alasannya: angka penyusutan perlu diperiksa sebelum dikunci, dan register yang sudah benar perlu dikirim ke buku besar sekaligus, bukan aset demi aset. Proposal boleh dijalankan berkali-kali dan dibandingkan; finalisasi yang membuatnya jadi kenyataan akuntansi di register; post yang membuatnya sampai ke buku besar.

## Endpoint

| Endpoint | Gunanya |
| --- | --- |
| `GET /api/v1/penyusutan` | Daftar periode, termasuk `buku_id`, `posting_layer` bukunya, dan `posted_posting_id` |
| `GET /api/v1/penyusutan/buku` | Buku aktif yang bisa disusutkan |
| `POST /api/v1/penyusutan/proposal` | Mengusulkan satu buku |
| `POST /api/v1/penyusutan/proposal-massal` | Mengusulkan semua buku aktif sekaligus untuk satu periode |
| `POST /api/v1/penyusutan/{id}/finalisasi` | Mengunci satu periode |
| `POST /api/v1/penyusutan/{id}/reversal` | Membalik periode yang sudah final |
| `GET /api/v1/penyusutan/posting/pratinjau` | Pratinjau "Post penyusutan" satu entitas legal, buku, dan tanggal akhir periode |
| `POST /api/v1/penyusutan/posting` | Menjalankan "Post penyusutan" |

## Aturan yang dijaga

**Buku yang ditandai tidak disusutkan akan ditolak.** Sebagian kombinasi group × buku memang sengaja tidak disusutkan; mencoba mengusulkannya menghasilkan pesan yang menyebut alasannya, bukan hasil nol.

**Buku yang sudah ditutup ditolak.** Buku ditutup ketika asetnya dilepas. Statusnya bukan `active` lagi.

**Periode yang berakhir sebelum aset mulai disusutkan ditolak.** Aset yang mulai dipakai bulan Maret tidak punya penyusutan bulan Januari. Ini dicek terhadap tanggal mulai dipakai, bukan tanggal perolehan.

**Aset harus punya unit penggunaan pada periode itu.** Angka penyusutan dibebankan ke unit kerja, dan unit itu diambil dari riwayat penempatan yang berlaku pada periode tersebut — bukan dari unit aset sekarang. Aset yang pindah unit di tengah tahun membebani dua unit berbeda pada periode berbeda, dan itu memang yang diinginkan.

Kalau tidak ada penempatan yang berlaku, permintaan ditolak. Angka yang tidak jelas dibebankan ke siapa lebih buruk daripada tidak ada angka.

**Finalisasi aman diulang.** Kalau periode sudah `final`, permintaan ulang mengembalikan periode itu tanpa menambah saldo buku untuk kedua kalinya. Jaringan yang putus setelah server selesai memproses tidak boleh menghasilkan pembukuan ganda.

**Pembalikan adalah satu-satunya jalan mundur.** Periode `final` tidak bisa dihapus atau diedit. Membalik membuat catatan baru yang meniadakan yang lama, sehingga jejaknya tetap ada.

## Post penyusutan

Satu proses = satu entitas legal × satu buku × satu tanggal akhir periode = satu posting, dari periode asli berstatus `final` yang belum di-post (`Services/DepreciationPosting`).

- **Jurnalnya ringkas, bertanggal akhir periode.** Debit beban penyusutan per group aset dan unit penggunaan — akun laba rugi, jadi membawa business unit dan department — lalu kredit akumulasi penyusutan per group aset dan business unit (K-14, K-30). Rincian per aset ikut di `details.assets`.
- **Hanya buku yang mem-post perolehan aset itu yang mengirim penyusutannya** (K-26, K-31). Buku `none` tidak ditawarkan di layar, dan ditolak dengan pesan bila diminta lewat API. Buku lain yang lapisannya bukan `none` tetap menyusut di register, tetapi periodenya dihitung sebagai "jurnal asetnya dikirim lewat buku lain".
- **Total jurnal sama persis dengan register.** Penyusutan yang lebih halus dari presisi mata uang menahan proses dengan pesan untuk mengatur pembulatan penyusutan di matriks group × buku (K-32).
- **Proses kedua pulang kosong.** Periode yang sudah di-post tidak ikut lagi; periode yang difinalkan sesudahnya ikut proses berikutnya dengan nomor urut berikutnya: `AST-DEP-<entitas legal>-<buku>-<YYYYMMDD>-<nomor urut>`.
- **Proses untuk satu buku berjalan satu per satu.** Baris master bukunya dikunci sebagai antrean, supaya dua pengguna — termasuk dua pengguna dengan cakupan unit berbeda — tidak memakai nomor urut yang sama. Periode yang ikut dikunci lalu dibaca ulang, sehingga pembalikan yang berbarengan tidak pernah terlewat.
- **Cakupan unit pengguna berlaku.** Pengguna yang hanya berwenang atas Poli Umum hanya mem-post penyusutan Poli Umum; sisanya di-post pengguna lain sebagai proses berikutnya.
- **Pemetaan akun yang kosong tidak menahan proses.** Posting terbit `held` dan periodenya tetap ditandai; setelah pemetaan diisi, owner atau admin menekan Validasi ulang di layar Posting finance (K-18).

**Pembalikan mengikuti periode aslinya** (TODO 11.3). Bila periode asli sudah di-post, pembalikan menerbitkan `asset.depreciation_reversal` untuk porsi aset itu saja, merujuk posting asalnya, bertanggal periode asal (K-29). Bila belum, tidak ada posting, dan periode aslinya tidak pernah ikut proses post.

## Hubungan dengan koreksi aset

Beberapa larangan pada register aset berasal dari sini:

- Nilai perolehan dan nilai sisa **tidak bisa diubah** kalau sudah ada periode penyusutan. Balikkan periodenya dulu.
- Tanggal mulai dipakai hanya bisa digeser kalau **belum ada** periode. Buku yang sudah berjalan memakai tanggal itu sebagai dasar periode yang terlanjur final.

## Yang datang dari Core

Tahun buku diambil dari fiscal calendar Core lewat `FiscalCalendarClient`. App tidak menyimpan kalender fiskalnya sendiri.

Ini penting: kalender fiskal milik **badan hukum**, bukan unit operasi. Itu sebabnya permintaan nomor dan hitungan periode selalu membawa `legal_entity_id`.

Business unit baris akumulasi dibaca lewat kontrak `DirektoriOrganisasi` — resolver yang sama dengan yang dipakai penerbit posting Core — pada tanggal akhir periode, dan jurnalnya terbit lewat `PenerbitPosting`.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `src/Http/Controllers/transaksi/InventarisasiAset/DepreciationController.php` | Proposal, finalisasi, pembalikan, pratinjau dan proses post |
| `src/Services/DepreciationCalculator.php` | Hitungan per periode |
| `src/Services/DepreciationPosting.php` | "Post penyusutan" dan jurnal pembaliknya |
| `src/Services/KalenderFiskalAset.php` | Tahun buku dari Core |
| `ui/transactions/inventarisasi-aset/DepreciationPage.tsx` | Layar |
| `ui/transactions/inventarisasi-aset/DepreciationPostingSheet.tsx` | Lembar "Post penyusutan" beserta pratinjau jurnalnya |
| `loadtest/k6/depreciation.js` | Uji beban proposal, finalisasi, dan post, termasuk balapannya |

## Halaman terkait

- [Penyusutan: profil, buku, dan matriks](/apps/management-aset/master/depresiasi/) — penyiapan
- [Register aset](/apps/management-aset/transaction/register-aset/) — asal buku dan tanggal
- [Feed posting finance](/dev/34-feed-posting-finance) — penerbit posting dan jenis-jenisnya
- [Number sequence](/dev/14-number-sequences) — penomoran dan reset per tahun buku
