# Pemindahan ke satu runtime

Folder ini adalah **pekerjaan sekali jalan**, bukan desain kanonik. Setelah pemindahan selesai dan
aturannya sudah masuk ke `docs/dev`, folder ini boleh dihapus. Orang yang bergabung setahun lagi tidak
perlu tahu apa yang terjadi di sini.

## Dua dokumen

| Dokumen | Isi | Kapan dibaca |
| --- | --- | --- |
| [00-keputusan.md](00-keputusan.md) | Kenapa CoreERP berpindah dari satu proses dan satu database per app menjadi satu runtime dengan modul; angka terukur di laptop, proyeksi 100 tenant, bukti dari Odoo, ERPNext, Business Central, Shopify | Sebelum menyetujui rencananya, atau saat ada yang bertanya kenapa |
| [01-prd.md](01-prd.md) | Rencana kerjanya: 63 task ukuran satu pull request dalam tujuh fase, lengkap dengan berkas yang disentuh, syarat selesai, rencana mundur, dan ukuran keberhasilan | Saat mengambil task berikutnya |

## Cara memakai daftar task

Satu task adalah satu pull request. Kerjakan berurutan; nomor task tidak dipakai ulang walau sebuah task
dibatalkan, supaya rujukan pada pull request lama tetap sah.

Sebelum mengambil task, baca bagian 2 sampai 4 pada PRD: apa yang dianggap selesai, lima prinsip yang
tidak boleh dilanggar, dan konvensi penamaan branch serta commit.

## Status

| Fase | Task | Selesai |
| --- | --- | --- |
| 0 — penjaga batas dan kerangka modul | F0-01 sampai F0-07 | belum |
| 1 — Core menjadi tuan rumah modul | F1-01 sampai F1-09 | belum |
| 2 — Management Aset pindah | F2-01 sampai F2-20 | belum |
| 3 — UI menjadi satu build | F3-01 sampai F3-10 | belum |
| 4 — edisi dan bundle on-prem | F4-01 sampai F4-06 | belum |
| 5 — dev stack dan CI | F5-01 sampai F5-05 | belum |
| 6 — modul kedua, pengukuran, pembersihan | F6-01 sampai F6-06 | belum |

## Yang pindah ke `docs/dev` setelah selesai

Aturan yang lahir dari pekerjaan ini harus dipindahkan menjadi desain kanonik, bukan ditinggal di sini:

- Batas modul dengan schema PostgreSQL dan peran database per modul, ke [standar app](../../dev/02-module-standard.md).
- Kontrak layanan Core yang boleh dipanggil modul, ke [API dan integration bridge](../../dev/04-api-and-integration.md).
- Bentuk edisi pelanggan dan cara membangun bundle, ke [release dan on-prem](../../dev/03-release-and-on-prem.md).
- Susunan runtime dan cara menjalankannya, ke [pengembangan lokal](../../dev/11-local-docker-development.md).
