# Pembatalan transaksi aset

Pembatalan mengembalikan dampak transaksi pada register aset dan menerbitkan jurnal balik. Satu baris `aset_tr_pembatalan` adalah satu perintah pembatalan langsung atau satu pengajuan persetujuan, bukan jurnal buku besar. Dokumen dan jurnal asal tetap tersimpan.

## Keputusan desain

| Bagian | Keputusan |
| --- | --- |
| Pemilik transaksi | Modul aset membatalkan penerimaan, penyusutan, dan penyesuaian nilai. BO finance membukukan jurnal balik dan mengirim ack. |
| Scope | Tenant dari konteks tepercaya; entitas legal dan unit dokumen memakai policy `management-aset.asset-responsibility`. |
| Hak akses | Batal langsung, mengajukan, dan menyetujui adalah hak terpisah per resource. Duty kelola atau posting lama tidak memperoleh hak Batal. |
| Nomor | Tidak memerlukan reference nomor baru. Nomor bisnis tetap nomor dokumen asal; pengajuan dan jurnal menggunakan ID internal. |
| Persetujuan | Pengguna tanpa hak Batal dapat mengajukan. Pengguna berwenang boleh membatalkan langsung. Penyetuju workflow dipilih dari pengguna atau role pada konfigurasi tenant; tidak perlu pasangan tetap pengaju–atasan. |
| Pemisahan tugas | Pengaju tidak dapat menyetujui pengajuan pembatalannya sendiri. Ini aturan tipe pembatalan, tidak mengubah parameter workflow tenant secara global. |
| Transport | Perintah lewat REST modul; workflow dan feed finance dipanggil di dalam proses. Email dikirim melalui API SSO yang sudah memakai SMTP. |

Keputusan di atas disepakati pemilik produk pada 9 Oktober 2026. Padanan langsung BC adalah **Cancel Entries / Cancel FA Ledger Entries**, dilanjutkan posting ulang nilai yang benar. Pilihan **Use New Posting Date** mengatur tanggal jurnal balik; ia tidak mengganti tanggal dokumen asal. Pembatalan write-down mengurangi saldo write-down asal, bukan membuat appreciation sebagai pengganti.

Referensi: [penyusutan BC](https://learn.microsoft.com/en-us/dynamics365/business-central/fa-how-depreciate-amortize), [pembatalan acquisition BC](https://learn.microsoft.com/en-us/dynamics365/business-central/fa-how-acquire), dan [notifikasi workflow BC](https://learn.microsoft.com/en-us/dynamics365/business-central/across-setting-up-workflow-notifications). Persetujuan atas pembatalan aset adalah adaptasi workflow Core; tidak dinyatakan sebagai template bawaan BC.

## Status pengajuan dan pembukuan berbeda

| Status pengajuan | Arti |
| --- | --- |
| `pending` | Menunggu keputusan. Transaksi asal masih berlaku. |
| `applied` | Register sudah dibatalkan. Jurnal balik dapat masih menunggu finance. |
| `rejected` | Pengajuan ditolak; register tidak berubah. |
| `blocked` | Persetujuan tercatat, tetapi versi atau transaksi lanjutan berubah sehingga pembatalan tidak dijalankan. Alasannya tersimpan dan pengajuan baru dapat dibuat setelah diperiksa. |

`posting_ids` menyebut jurnal baliknya. Status masing-masing dibaca lewat `PostingFeed::status()`, bukan disimpulkan dari `applied`. Jurnal asal yang dicatat manual membuat pembalikannya manual juga. Jurnal asal yang ditolak finance tidak boleh menghasilkan jurnal negatif yang dikirim ke finance.

## Aturan register

- **Penyusutan:** usulan dapat dibatalkan tanpa jurnal. Finalisasi dibatalkan dengan baris negatif baru, saldo buku dikembalikan, dan periode asli diberi `cancelled_at`. Penyusutan yang lebih baru serta penyesuaian/reklasifikasi setelah periode tersebut menahan pembatalan. Periode asli dan baris pembalik tidak dihitung sebagai periode berjalan; tanggal akhir yang sama dapat diusulkan lagi. Koreksi nilai perolehan kembali tersedia setelah seluruh periode aktif pada aset dibatalkan.
- **Penyesuaian nilai:** saldo write-down/appreciation asal dikurangi dan nilai buku dibalik. Nilai sebelum/sesudah yang dibekukan di dokumen tetap sama. Penyusutan setelah tanggal dokumen, buku ditutup, reklasifikasi, atau saldo yang tidak cukup menahan pembatalan.
- **Penerimaan:** aset yang belum mempunyai transaksi lanjutan dapat dibatalkan bersama seluruh penerimaannya. Jurnal acquisition/opening balance dan koreksi nilainya dibalik. Aset hasil penerimaan diarsipkan dan buku ditutup; tidak ada penghapusan fisik. Mutasi, pemeliharaan, monitoring, dokumen siklus, reklasifikasi, atau penyesuaian aktif menahan pembatalan. Penerimaan yang dibatalkan tidak dapat diselesaikan lagi; perbaikannya memakai dokumen baru.

Pemeriksaan, kunci record, perubahan saldo, dan penerbitan jurnal berjalan dalam transaksi yang sama. Callback workflow memakai savepoint: pemeriksaan ulang yang gagal tidak meninggalkan saldo yang berubah sebagian.

## Hak dan workflow

Pada setiap resource `penerimaan-aset`, `penyusutan`, dan `penyesuaian-nilai-aset`:

| Permission | Duty | Arti |
| --- | --- | --- |
| `management-aset.<resource>.cancel` | `<resource>.cancellation` | Membatalkan langsung; access level `delete`. |
| `management-aset.<resource>.request-cancellation` | `<resource>.cancellation-request` | Mengajukan; access level `invoke`. |
| `management-aset.<resource>.approve-cancellation` | `<resource>.cancellation-approval` | Menyetujui/menolak tugas pembatalan yang ditugaskan; access level `invoke`. |

Konfigurasi workflow bertipe `management-aset.<resource>-cancellation` berlaku per entitas legal. Metadata `decision_context_schema.x-approval-authority` menyatakan permission, data policy, dan larangan persetujuan oleh pengaju. Core menyaring penerima saat penugasan dan memeriksa haknya kembali saat keputusan. Role yang dicabut setelah tugas dibuat tidak tetap memberikan hak persetujuan. Workflow tanpa penyetuju berwenang atau yang langsung selesai tanpa persetujuan menolak pengajuan.

## Endpoint

Semua memakai prefix `/api/modules/management-aset/v1/`, dengan resource di atas:

| Endpoint | Arti |
| --- | --- |
| `GET <resource>/{id}/pratinjau-pembatalan` | Penghalang, versi, tanggal asal, jurnal balik, dan pengajuan terakhir; tidak menyimpan. |
| `POST <resource>/{id}/batal` | Batal langsung dengan `reason`, `posting_date`, dan versi. |
| `POST <resource>/{id}/ajukan-pembatalan` | Mengajukan body yang sama. Pengajuan pending dikembalikan saat retry. |

Rute penyusutan lama `POST penyusutan/{id}/reversal` tetap mengembalikan `{period, posting}` dan kini memakai hak `.cancel`; tanggal asal dipakai bila tanggal baru tidak dikirim. Ini tidak memberikan hak Batal kepada pemegang `.correct` secara otomatis.

## Jurnal dan email

Pembalikan penuh memakai `PostingFeed::reverse()`. Akun, dimensi, dan presisi disalin dari jurnal tersimpan, termasuk ketika pemetaan sudah diganti. Pembalikan penyusutan memilih dua baris porsi aset dari jurnal ringkas yang sama melalui `PostingFeed::journal()`.

Feed pull dan push menunggu ack `posted` pada jurnal asal sebelum mengirim jurnal yang membaliknya. Ini berlaku juga untuk tanggal pembalikan yang lebih lama daripada koreksi perolehan asalnya. Jenis yang diterbitkan ada pada [feed posting finance](/dev/34-feed-posting-finance).

Email hanya pemberitahuan dengan tautan **Tinjau permintaan**. Penerima tetap masuk dengan akun sendiri dan memberikan keputusan di aplikasi. Jika sudah login, tautan membuka inbox langsung. Work item menyimpan alamatnya saat dibuat, sehingga proses terjadwal tidak menebak host dari permintaan CLI. Pengiriman gagal tetap menyisakan tugas di inbox dan dicoba kembali oleh `workflow:notify`.

SMTP tetap berada di SSO. Core memakai API notifikasi umum SSO `POST /api/v1/notifications/send` dan pengaturan kredensial API yang sudah ada. Kontrak consumer: `apps/core/contracts/external/sso-workflow-notifications.yaml`, diverifikasi dari source SSO terbaru. Respons sukses berarti email diterima antrean SSO; keberhasilan pengiriman SMTP dipantau pada antrean SSO. [Workflow Core](/dev/21-visual-workflow-engine) menjelaskan pengaturan pengirim.

## Di mana kodenya

| Berkas | Isi |
| --- | --- |
| `src/Services/AssetCancellationEngine.php` | Pemeriksaan, pengajuan, register, dan jurnal balik. |
| `src/Http/Controllers/transaksi/Cancellation/AssetCancellationController.php` | Pratinjau dan perintah API. |
| `src/Listeners/ApplyAssetCancellationDecision.php` | Keputusan workflow, dedup status, dan savepoint. |
| `src/Models/transaksi/Cancellation/AssetCancellation.php` | Pengaju, pelaksana, keputusan, dan jurnal terkait. |
| `manifest/fixed-asset/asset-cancellations.yaml` | Hak, privilege, duty, dan tipe workflow. |
| `ui/transactions/_shared/CancellationAction.tsx` | Modal Cancel Entries dan pratinjau. |
| `ui/transactions/_shared/CancellationStatus.tsx` | Status register dan finance yang berbeda. |
| `tests/Feature/AssetCancellationTest.php` | Register, jurnal beku, koreksi ulang, hak, tenant, approval, dan email. |

Pembatalan ini berlaku untuk aset tetap; tidak menambahkan engine valuasi persediaan barang.
