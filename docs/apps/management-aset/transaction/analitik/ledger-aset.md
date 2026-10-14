# Ledger aset dan analisis bersama

Ledger FA menyimpan perubahan nilai setiap buku aset. Ia tidak memerlukan General Ledger Finance:
finalisasi penyusutan mengubah buku aset dan mencatat ledger dalam transaksi yang sama. Pengiriman jurnal
ke Finance tetap merupakan tahap terpisah, termasuk bila penerimanya sistem Finance lama.

## Hubungan dengan Business Central

Padanannya adalah [FA Ledger Entries dan Fixed Assets Ad-hoc Analysis](https://learn.microsoft.com/en-us/dynamics365/business-central/ad-hoc-analysis-fa).
[Calculate Depreciation](https://learn.microsoft.com/en-us/dynamics365/business-central/fa-how-depreciate-amortize)
menghasilkan usulan yang diperiksa sebelum diposting. Di CoreERP, `proposed` adalah usulan; finalisasi
mem-post nilai ke buku aset dan ledger. `posted_posting_id` tetap berarti pengiriman ke Finance, bukan
syarat transaksi FA boleh masuk analisis.

## Catatan yang disimpan

`aset_tr_ledger_aset` menyimpan snapshot kode/nama aset, group, entitas legal, unit penanggung jawab,
buku, mata uang, tanggal transaksi, jenis transaksi, sumber dokumen, serta empat perubahan saldo.
`amount = acquisition_delta - accumulated_delta - write_down_delta + appreciation_delta` adalah
perubahan nilai buku, bukan pendapatan penjualan. Nilai penyusutan dapat dibaca lewat
`accumulated_delta`; pembalikan mempunyai tanda yang berlawanan.

Trigger buku menangkap INSERT dan perubahan nilai, termasuk update lewat query builder. Menutup buku
mencatat pengeluaran seluruh saldonya. Pindah group mencatat keluar dari group asal dan masuk ke tujuan;
pecah aset mengikuti perubahan saldo buku asal dan pembentukan buku tujuan. Semua itu ikut rollback
transaksi sumber. Percobaan ulang yang tidak mengubah saldo tidak menambah ledger.

Metadata sumber dipasang lewat `FixedAssetLedgerContext` sebagai variabel PostgreSQL lokal transaksi.
Perolehan, finalisasi, penyesuaian nilai, reklasifikasi, pelepasan, dan pembatalan memasang tanggal serta
rujukannya sebelum mengubah buku. Tanpa metadata, perubahan tetap tercatat dengan rujukan buku/aset;
jalur bisnis baru wajib memasang metadata agar dokumennya dapat dikenali.

Ledger tidak mempunyai perintah edit, arsip, atau hapus. Trigger database menolak UPDATE/DELETE:
koreksi dilakukan pada proses sumber dengan baris pembalik. Identitas UUID adalah id internal baris,
bukan nomor dokumen bisnis, sehingga tidak membutuhkan Number Sequence baru.

## Data sebelum ledger tersedia

Migration data menyusun perolehan dasar, periode final/pembalik, penyesuaian yang sudah diposting,
reklasifikasi, dan penutupan buku dari catatan yang tersedia. Semua diberi `is_reconstructed = true`.
Nama, unit, dan group yang tidak tersimpan secara historis memakai informasi yang tersedia pada saat
rekonstruksi; ia tidak mengaku memulihkan histori yang tidak pernah dicatat. Buku yang sudah mempunyai
ledger dilewati sehingga rekonstruksi tidak menggandakan transaksi baru.

Tanggal penutupan lama yang tidak tersimpan tetap kosong, bukan diganti dengan tanggal hari ini.

## Hak baca dan pemisahan nilai

Dataset `management-aset.fixed-asset-ledger` memakai `management-aset.penyusutan.read`, izin laporan
keuangan aset yang sudah ada. Kebijakan `management-aset.asset-responsibility` memakai
`legal_entity_id` dan snapshot `responsible_org_unit_id`. Riwayat tetap dapat dibaca setelah aset diarsipkan.
Tidak ada privilege, duty, workflow, ataupun permission baru.

Dataset menyatakan `partition('book_code')`. Compiler memisahkan setiap buku pada kelompok dan total,
ditambah pemisahan mata uang dari measure uang. Menggabungkan buku komersial dan fiskal tidak menghasilkan
satu total yang menghitung aset dua kali.

## Engine dan pemakai berikutnya

Engine layar berada di `apps/core/resources/js/components/analytics/ad-hoc-analysis.tsx`. Module hanya
memasok `datasetCode`, `title`, dan susunan awal `columns`, `groups`, `measures`, `filters`, `pivot`.
Layar FA di `ui/transactions/ledger/FixedAssetLedgerPage.tsx` adalah pemakai pertama, bukan pemilik engine.

Mode daftar memakai `records: true`, `dimensions` sebagai kolom, dan `measures: []`. Compiler membaca
baris sumber tanpa GROUP BY, dengan gerbang izin, tenant, data policy, personal data, batas baris, timeout,
dan transaksi baca-saja yang sama dengan analisis. Baris identik tetap merupakan dua transaksi.

Mode analisis menghitung setiap tingkat kelompok di server. Detail memakai `drill`: kesamaan tambahan
yang di-AND dengan filter pengguna, sehingga membuka kelompok tidak melonggarkan filter semula.
Pivot memakai agregat server untuk sel dan total baris; ia tidak menjumlah ulang average atau distinct
count di peramban. Pivot di atas 50 nilai kolom atau hasil terpotong meminta filter lebih sempit.

Susunan layar dimiliki state React per tampilan. Tautan URL lama masih dapat dibaca; interaksi berikutnya
memakai state layar tanpa permintaan halaman baru ke server. Tampilan bernama disimpan di peramban, dipisahkan per tenant, pengguna, dan
dataset; hanya susunan yang disimpan, bukan data. Ini berbeda dari analisis tersimpan server dan dasbor
yang sudah ada. Tombol Muat ulang meminta perhitungan baru melewati cache.

Kolom urutan dan arah naik/turun diteruskan ke compiler, bukan mengurutkan potongan hasil di peramban.

## Verifikasi dan berkas

`FixedAssetLedgerDatasetTest` menjaga isolasi tenant/unit, buku terpisah, rollback, retry, group keluar/masuk,
immutability, dan detail yang hanya menyempitkan. Regression engine tetap dijalankan melalui test
`Platform/Analytics`. Pengujian UI memakai build runtime dan layar Transaksi nilai aset.

Lihat juga [dataset aset](./index), [engine analitik Core](/dev/35-analitik), dan
[laporan nilai buku](/apps/management-aset/transaction/laporan/).

Pengaturan kolom, filter, pengelompokan, pivot, urutan, dan perhitungan berada di panel kanan.
Pembatas panel dapat ditarik atau diubah lewat keyboard. Pada layar kecil, panel berada di bawah tabel
agar keduanya tetap terlihat; tombol Kolom dan filter dapat menyembunyikannya.

Agregat ditampilkan dalam kolom angka, bukan kalimat ringkasan yang memanjang. Nama tampilan
diubah lewat menu tab, tanpa form yang selalu terbuka. Lookup Group aset mengambil daftar master group yang boleh dibaca pengguna.

Kesetaraan BC belum penuh: kolom, grup, dan nilai dapat diseret serta diurutkan lewat Alt+panah; turunan
tahun/kuartal/bulan belum tersedia di layar ini. Detail kelompok memakai satu header dan satu DataTable dengan baris berindentasi.
Navigasi sel spreadsheet belum selengkap web client BC. Tampilan bernama tetap disimpan per peramban.

Panel Kolom menyediakan Row Groups dan Values. Kolom angka ditautkan ke perhitungan SUM lewat
metadata `field_key`, tanpa mengasumsikan pola nama measure module. Susunan kolom/grup/nilai
mengikuti URL dan tampilan tersimpan. Mode pivot mengaktifkan menu header Freeze sampai kolom ini
dan Lepas freeze. DataTable SDK menampilkan garis horizontal pada isi; pembatas resize header muncul
saat hover/focus. Deskripsi header opsional dapat diberikan lewat `description`. Default pemasangan
belum dipindah ke seeder pada perubahan ini.

Values hanya menampilkan item terpilih sebagai `Sum(kolom)` dan bukan daftar semua measure.
Klik item membuka pilihan agregat yang didaftarkan dataset untuk field itu; semua kolom angka
ledger mendukung Sum, Min, Max, dan Average. Drag kolom angka memilih Sum secara default.
Tombol panah urutan dihilangkan; keyboard tetap dapat memakai Alt+panah pada item yang difokuskan.

Grup, aset, dan transaksi dibuka dalam satu grid yang sama. Membuka anak menambah baris, tanpa
Table/Header baru. Nilai setiap tingkat tetap dihitung server; detail memakai drill yang mempersempit
filter. Buku dan mata uang implisit tidak ditambahkan ke label grup; kolomnya mengikuti pilihan pengguna.
Kolom mata uang dikeluarkan dari susunan awal layar ledger.

Panel Kolom memakai tiga panel vertikal yang dapat diubah tingginya: daftar kolom, Row Groups,
dan Values. Masing-masing mempunyai scroll sendiri. Pencarian menyaring daftar kolom tanpa mengubah
urutannya. `column_order` menyimpan urutan semua kolom, terpisah dari pilihan centang, sehingga
kolom yang dimatikan tetap berada pada posisi yang sama.

SDK `DragReorderItem` menyediakan ghost berlabel serta indikator sisip sebelum/sesudah item;
komponen engine hanya memvalidasi payload terhadap metadata dataset dan menerapkan urutan baru.
Tab tampilan menyediakan tambah, ubah nama, duplikat, dan hapus konfigurasi tampilan. Tambah memakai
susunan awal dari halaman; kebijakan default dari seeder belum ditentukan. Perubahan konfigurasi
disimpan otomatis sebagai metadata pribadi di peramban, bukan data hasil query, dan belum disinkronkan
antar perangkat.

Header tabel analisis menyediakan menu saat hover/focus dan filter kolom terpisah. Filter teks
memakai Contains sebagai awal, dengan Not contains, Equals, Not equal, Starts with, Ends with,
Blank, dan Not blank. Angka/tanggal memakai pembanding yang sesuai. Filter nama reference memakai
field filter label yang dibuat compiler, join tenant yang sama, dan binding nilai melalui parser
filter yang sudah ada; tidak mengubah ID dasar record. Buku aset mengambil nama dari master buku
melalui relasi buku aset. Freeze tetap hanya tersedia pada pivot. Reset columns, reset analysis
filters, autosize, dan wrap header tersedia di menu header.
