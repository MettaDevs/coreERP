# Master work order

Halaman ini untuk developer. Perilaku dasar master ada di [Master data](/apps/management-aset/master/); setup pekerjaan maintenance ada di [Setup maintenance](/apps/management-aset/master/maintenance/).

Master di halaman ini dipakai oleh dokumen work order saat dikerjakan dan ditutup.

| Master | Isi |
| --- | --- |
| `tipe-work-order` | Golongan pekerjaan. Membawa aturan, bukan sekadar label |
| `tingkat-layanan` | Urgensi penanganan |
| `sebab-kerusakan` | Kenapa pekerjaan diperlukan |
| `tindakan-perbaikan` | Apa yang dilakukan |
| `trade` | Bidang keahlian yang dibutuhkan |
| `validasi-status-work-order` | Aturan yang harus dipenuhi sebelum berpindah status |

## Tingkat layanan: angka kecil berarti lebih mendesak

`urutan` yang lebih kecil berarti lebih mendesak, mengikuti cara Dynamics 365 memakai service level 1 sebagai yang tertinggi.

Ia **hanya mengurutkan dan menyaring**. Tidak ada perhitungan tenggat waktu di fase ini, jadi target waktu sengaja belum disimpan. Kalau nanti tenggat ditambahkan, ia kolom baru — bukan menafsirkan ulang `urutan`.

## Trade: dulu pilihan di layar, sekarang master

`trade` adalah bidang keahlian yang dibutuhkan sebuah pekerjaan, padanan "Trade" pada modul Asset management Dynamics 365 F&O.

Sebelumnya daftarnya ditulis langsung di UI, sehingga tenant tidak bisa menambah keahlian tanpa merilis ulang aplikasi. Sekarang ia master biasa.

Catatan penting: **keahlian ini bukan kompetensi pekerja.** Kompetensi pekerja milik modul Human Resources dan dipasang pada orangnya. Yang ada di sini hanya "pekerjaan ini butuh keahlian apa". Persyaratan keahlian dan sertifikat pada tipe pekerjaan sempat ada di sini lalu dihapus, karena menyimpannya sebagai teks bebas melanggar batas modul dan tidak akan pernah cocok dengan data pekerja yang sebenarnya.

## Validasi status: matriks tetap, bukan daftar yang bisa ditambah

`validasi-status-work-order` berbeda dari master lain. Barisnya adalah **matriks tetap status × aturan** yang diisi saat tenant disiapkan. Tidak ada tambah dan tidak ada hapus.

Yang boleh diubah tenant hanya dua: apakah aturan itu aktif, dan seberapa parah pelanggarannya.

| Keparahan | Akibatnya |
| --- | --- |
| `informasi` | Hanya dicatat |
| `peringatan` | Transisi tetap berjalan, tetapi tersimpan pada jejak status |
| `galat` | Transisi ditolak |

Tabelnya `m_validasi_status_work_order`. Endpointnya `GET`/`PUT /api/v1/validasi-status-work-order` — mengganti seluruh matriks sekaligus, bukan per baris.

Alasan bentuknya begini: daftar aturan adalah bagian dari perilaku aplikasi, jadi ia dirilis bersama kode. Yang boleh berbeda antar tenant hanya seberapa keras aturan itu ditegakkan.

## Di mana kodenya

| Berkas | Isinya |
| --- | --- |
| `api/app/Http/Controllers/master/TipeWorkOrderController.php` | Tipe work order |
| `api/app/Http/Controllers/master/TingkatLayananController.php` | Tingkat layanan |
| `api/app/Http/Controllers/master/TradeController.php` | Keahlian |
| `api/app/Http/Controllers/master/SebabKerusakanController.php`, `TindakanPerbaikanController.php` | Sebab kerusakan dan tindakan perbaikan |
| `api/app/Http/Controllers/master/ValidasiStatusWorkOrderController.php` | Matriks validasi status |
| `api/app/Support/WorkOrderValidation.php` | Daftar keparahan yang sah |
| `database/migrations/2026_08_15_100000_create_work_order_masters.php` | Tabel master di halaman ini |

## Halaman terkait

- [Pemeliharaan aset](/apps/management-aset/transaction/pemeliharaan-aset/) — pemakai master ini
- [Setup maintenance](/apps/management-aset/master/maintenance/) — tipe pekerjaan dan checklist
