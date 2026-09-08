# Pemindahan ke satu runtime

Folder ini adalah **pekerjaan sekali jalan**, bukan desain kanonik. Setelah pemindahan selesai dan
aturannya sudah masuk ke `docs/dev`, folder ini boleh dihapus. Orang yang bergabung setahun lagi tidak
perlu tahu apa yang terjadi di sini.

## Tiga dokumen

| Dokumen | Isi | Kapan dibaca |
| --- | --- | --- |
| [00-keputusan.md](00-keputusan.md) | Kenapa CoreERP berpindah dari satu proses dan satu database per app menjadi satu runtime dengan modul; angka terukur di laptop, proyeksi 100 tenant, bukti dari Odoo, ERPNext, Business Central, Shopify | Sebelum menyetujui rencananya, atau saat ada yang bertanya kenapa |
| [01-prd.md](01-prd.md) | Rencana kerjanya: 79 task ukuran satu pull request dalam delapan fase, lengkap dengan berkas yang disentuh, syarat selesai, rencana mundur, dan ukuran keberhasilan | Saat mengambil task berikutnya |
| [02-bukti-penjaga.md](02-bukti-penjaga.md) | Pesan gagal ketiga penjaga batas, apa adanya, beserta cara memperbaruinya | Saat menyentuh penjaga batas, atau saat ada yang meragukan penjaganya menguji sesuatu |

## Cara memakai daftar task

Satu task adalah satu pull request. Kerjakan berurutan; nomor task tidak dipakai ulang walau sebuah task
dibatalkan, supaya rujukan pada pull request lama tetap sah.

Sebelum mengambil task, baca bagian 2 sampai 4 pada PRD: apa yang dianggap selesai, lima prinsip yang
tidak boleh dilanggar, dan konvensi penamaan branch serta commit.

## Sedang dikerjakan

Papan ini mencegah dua orang mengambil task yang sama. Isinya berubah cepat, jadi ia hidup di sini saja
dan tidak disalin ke PRD — daftar yang ada di dua tempat akan menyimpang, dan yang menyimpang lebih
berbahaya daripada yang tidak ada.

**Ambil task berikutnya dengan menulis namamu di sini lebih dulu, sebelum menyentuh kode.**

| Task | Siapa | Cabang | Catatan |
| --- | --- | --- | --- |
| F2-10 konteks permintaan | agent | `feat/f2-10-konteks-permintaan` | selesai, menunggu #53 digabungkan; menghalangi F2-11 |
| F2-12 kolom `database_name` | agent | `fix/f2-12-database-name-opsional` | selesai, menunggu #52 digabungkan |
| F3-00 penjaga kenal modul migrasi | agent | `feat/f3-00-penjaga-modul-migrasi` | menghalangi F3-01 |

Kosongkan barisnya setelah pull request-nya digabungkan.

## Status

| Fase | Task | Selesai |
| --- | --- | --- |
| 0 — prasyarat: CI hijau, aturan kerja, penghapusan lunak | F0-01 sampai F0-07 | F0-01 sampai F0-04 selesai; F0-05 ditutup tanpa dikerjakan; F0-06 menunggu F3-01 |
| 1 — penjaga batas dan kerangka modul | F1-01 sampai F1-08 | selesai |
| 2 — Core menjadi tuan rumah modul | F2-01 sampai F2-12 | F2-01 sampai F2-09 selesai |
| 3 — Management Aset pindah | F3-01 sampai F3-24 | belum |
| 4 — UI menjadi satu build | F4-01 sampai F4-10 | belum |
| 5 — edisi dan bundle on-prem | F5-01 sampai F5-06 | belum |
| 6 — dev stack dan CI | F6-01 sampai F6-05 | belum |
| 7 — modul kedua, pengukuran, pembersihan | F7-01 sampai F7-09 | belum |

Total 79 task. Fase 0 membereskan yang sudah menghalangi sebelum proyek dimulai: pemeriksaan otomatis
yang merah dan aturan repo yang melarang pekerjaan ini. Ia sempat direncanakan tidak menyentuh kode sama
sekali, tapi menghijaukan pemeriksaan ternyata menuntut perbaikan kode juga — rinciannya ada pada catatan
pelaksanaan F0-01 di PRD.

## Yang pindah ke `docs/dev` setelah selesai

Aturan yang lahir dari pekerjaan ini harus dipindahkan menjadi desain kanonik, bukan ditinggal di sini:

- Batas modul dengan awalan tabel, `tenant_id` wajib, dan aturan retensi, ke [standar app](../../dev/02-module-standard.md).
- Kontrak layanan Core yang boleh dipanggil modul, ke [API dan integration bridge](../../dev/04-api-and-integration.md).
- Bentuk edisi pelanggan dan cara membangun bundle, ke [release dan on-prem](../../dev/03-release-and-on-prem.md).
- Susunan runtime dan cara menjalankannya, ke [pengembangan lokal](../../dev/11-local-docker-development.md).
