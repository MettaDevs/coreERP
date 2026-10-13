# Catatan perubahan penyusutan per grup

Tanggal: 13 Oktober 2026. Pemilik perubahan kode: CoreERP. Dasar implementasi: main `f91bfceb`.

## Masalah dan tujuan

Kiriman penyusutan beberapa grup sebelumnya membawa rincian aset tetapi tidak menghubungkan grup dengan nomor baris jurnal. BO tidak dapat menentukan baris beban dan akumulasi milik setiap grup. Perubahan ini menyediakan hubungan tersebut agar BO dapat membentuk satu voucher per grup, entitas, bulan, buku, dan mata uang.

## Perubahan yang sudah selesai lokal

| Berkas | Perubahan |
| --- | --- |
| `modules/apperp/management-aset/src/Services/DepreciationPosting.php` | Menambahkan `details.journal_groups`: kode/nama grup, nomor baris beban dan nomor baris akumulasi. Melengkapi metadata aset untuk rekap: identitas/nama/kategori/jenis, tanggal perolehan, umur manfaat, nilai perolehan, akumulasi dan nilai buku. Hitungan sisa umur mengecualikan periode dengan `cancelled_at`. |
| `modules/apperp/management-aset/tests/Feature/DepreciationPostingTest.php` | Regression dua grup dalam satu buku, beberapa department, cakupan baris tepat satu kali, subtotal sesuai aset dan sisi jurnal, serta pembatalan/proposal ulang. Fixture memilih periode yang belum dibatalkan. |
| `modules/apperp/management-aset/tests/Concerns/MenerbitkanJurnalPenerimaan.php` | Fixture memastikan request hierarchy berhasil dan mencari hierarchy tersimpan pada tenant yang benar. |
| `apps/core/contracts/internal/components/schemas/FinancePosting.yaml` | Mendefinisikan metadata grup opsional pada objek `details` terbuka dalam contract v1. |
| `apps/core/contracts/openapi-internal.yaml`, `apps/core/contracts/terbit/integrasi-finance.yaml` | Hasil bundler resmi dari sumber contract. |
| `docs/dev/34-feed-posting-finance.md`, `docs/apps/management-aset/transaction/penyusutan/index.md` | Mendokumentasikan hubungan grup dan baris jurnal serta sumber nilai akuntansi. |

Akun, nominal, presisi, dan dimensi akuntansi tetap berasal dari `journal_lines`. Rumus penyusutan serta agregasi beban per grup/department dan akumulasi per grup/business unit dipertahankan. Source lama tetap berupa snapshot; source baru diperlukan untuk memperoleh metadata tambahan. Perubahan tidak menambah migration atau mengubah database server.

## Dukungan tahap draft jurnal BO

Keputusan USER: Rekapitulasi memilih akun -> draft Jurnal Transaksi -> edit akun atau batal draft -> posting final -> Jurnal Umum dan Buku Besar.

CoreERP sudah mendukung consumer menunggu: pull tidak membukukan source, dan ack final menerima `posted` atau `rejected`. Selama draft/edit/batal draft yang masih dapat dikerjakan ulang, BO tidak mengirim ack final. Batal draft bukan `rejected`. BO mengirim `posted` dengan referensi voucher setelah seluruh grup pada source dibukukan.

Tahap draft, perubahan akun, pembatalan draft, pencegahan duplikasi dan posting final dimiliki BO dan belum diimplementasikan oleh paket CoreERP ini. Nilai penyusutan sumber tetap immutable; koreksi nominal dan pembalikan setelah pembukuan merupakan proses terpisah. Tidak diperlukan status draft baru pada API CoreERP untuk keputusan ini.

## Bukti pemeriksaan sebelumnya

Pemeriksaan dilakukan 12 Oktober 2026 pada lingkungan test sementara:

- DepreciationPostingTest: 14 test lulus, 912 assertion.
- Suite Core paralel termasuk grup lambat: 2.391 test lulus, 29.536 assertion, 5 skip.
- Suite serial: 52 test lulus, 289 assertion, 5 skip ketika klien dump/restore tidak tersedia dalam PATH test. Saat klien tersedia, skenario CopyEnvironment gagal karena pg_restore 17 mengirim `SET transaction_timeout` ke PostgreSQL 16; kesesuaian versi ini belum diperbaiki. Hasil serial bukan bukti skenario penyalinan database lulus.
- Pint check pada tiga berkas PHP dan PHPStan producer lulus.
- Bundle contract sesuai sumber; coverage mencakup 23 route internal.
- Build dokumentasi saat itu lulus tanpa dead link. Catatan ini ditambahkan sesudah build tersebut.

## Checklist publikasi dan kelanjutan

| Task | Penanggung jawab | Status | Penghalang/dependensi | Tanggal selesai |
| --- | --- | --- | --- | --- |
| Implementasi producer, contract dan regression | AGENT | SELESAI LOKAL | Bukti pemeriksaan di atas | 2026-10-12 |
| Catatan perubahan dan audit dukungan draft BO | AGENT | SELESAI | Audit source/contract; bukan E2E dev | 2026-10-13 |
| Commit paket beserta catatan | AGENT | SELESAI | Commit implementasi `37489beb`; branch publikasi `DIO` sesuai permintaan USER | 2026-10-13 |
| Sediakan autentikasi Git dengan izin write CoreERP | USER | DIPERLUKAN | GitHub menolak akun aktif `Diopranata01` dengan HTTP 403; autentikasi melalui perangkat, jangan kirim token ke chat | — |
| Push paket beserta catatan | AGENT | TERHALANG | Menunggu autentikasi akun dengan izin write `MettaDevs/coreERP`; belum ada branch remote dari push ini | — |
| Rilis ke dev dan audit source/image | AGENT | BELUM SELESAI | Push tidak otomatis membuktikan deployment; ikuti alur rilis resmi | — |
| Source PTDI baru dan audit payload beberapa grup | AGENT | MENUNGGU RILIS | Finalisasi/Post sumber dilakukan USER sesuai checklist integrasi | — |
| Audit dan Implementation Plan draft/edit/batal/posting final BO | AGENT | BELUM SELESAI | Periksa mekanisme draft jurnal existing sebelum perubahan kode | — |

Register bukti dev tetap berada di repo BO pada `docs/integrasi-bo-coreerp/E2E_PROGRESS.md`; checklist kelanjutan di `CONFIG_TODO.md`. Paket ini belum membuktikan posting per grup hingga Buku Besar di dev.
