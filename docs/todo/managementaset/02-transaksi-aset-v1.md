# Transaksi Management Aset V1

## [~] Register aset dan jalur penerimaan

Buat register aset sebagai aggregate utama. Penerimaan langsung adalah jalur sah:
aset dapat dicatat dari bukti serah-terima tanpa harus berasal dari perencanaan
atau permintaan pembelian. Bila dokumen perencanaan/permintaan tersedia, ia hanya
menjadi referensi asal, bukan prasyarat.

Penerimaan membuat aset, nilai perolehan, bukti/lampiran, unit penerima,
penerima, unit pengguna awal, PIC awal, lokasi awal, dan status lifecycle awal.
Nomor dokumen yang berdampak legal/akuntansi memakai number sequence dengan
scope legal entity.

## [~] State machine lifecycle

Pisahkan transaksi berikut dari master aset dan tegakkan transisinya di server:

1. perencanaan dan permintaan (opsional);
2. penerimaan/pencatatan dan kapitalisasi register;
3. utilisasi/check-out dan pengembalian;
4. mutasi unit pengguna, PIC, dan/atau lokasi;
5. pemeliharaan;
6. penjualan atau pemusnahan;
7. monitoring dan laporan.

Mutasi selalu membuat histori assignment/lokasi baru yang memiliki tanggal
efektif dan alasan. Tidak ada endpoint edit yang mengubah unit, PIC, atau lokasi
masa lalu. Pelepasan menghentikan penyusutan sesuai aturan periode dan mencegah
mutasi/utilisasi berikutnya kecuali ada reversal yang sah.

## [~] Master dan default

Gunakan asset group sebagai sumber default book dan depreciation profile. Aset
boleh memakai override profile/book hanya dengan alasan, riwayat, dan approval
bila workflow tenant untuk perubahan itu aktif. Master lokasi, group, kategori,
jenis, kondisi, pabrikan, serta alasan mutasi/pelepasan dipisahkan dari
transaksi.

Sebelum implementasi, putuskan resource/action untuk permissions secara eksplisit
(misalnya register, penerimaan, mutasi, pelepasan, lokasi, profile/book). Jangan
menyatukan semuanya menjadi satu permission master-data.
