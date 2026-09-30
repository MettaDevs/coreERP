# Penyimpanan berkas di server on-prem

Lampiran dokumen ([gap 7](/todo/AnalisaGapCoreErpkeBCPhase1/#gap-7)) disimpan di disk
`coreerp.attachments.disk`, bawaannya `s3`. Di SaaS disk itu RustFS. Compose edisi on-prem
(`deploy/compose.edition.yaml`) belum punya object storage, jadi server pelanggan on-prem hari ini
tidak punya tempat untuk lampiran.

**Keputusan pemilik produk, 1 Oktober 2026:** dikerjakan nanti, terpisah dari analisa gap. Arahnya S3
juga, tetapi berjalan di server pelanggan itu sendiri, bukan disk lokal dan bukan S3 milik kita.

## Yang perlu diputuskan saat mulai

- Object storage apa yang ikut compose edisi. RustFS dipakai di SaaS; memakai yang sama berarti satu
  jalur untuk diuji.
- Siapa yang membuat bucket dan kredensialnya: langkah pasang, bukan tangan operator.
- Cadangan dan mundur. `scripts/update.sh` memulihkan image dan database; berkas di bucket belum ikut.
  Lampiran adalah data bisnis, jadi kehilangan berkas saat mundur sama buruknya dengan kehilangan baris.

## Pekerjaan

- [ ] Layanan object storage di `deploy/compose.edition.yaml`, dengan volume di filesystem yang
  berbeda dari data database bila server menyediakannya.
- [ ] Langkah pasang membuat bucket dan kredensial, lalu menulis `COREERP_ATTACHMENT_DISK` dan setelan S3
  ke env Core.
- [ ] Health check Core memeriksa disk lampiran dapat ditulis dan dibaca, supaya salah setel terlihat
  saat pasang, bukan saat pengguna pertama melampirkan berkas.
- [ ] Cadangan sebelum pembaruan ikut menyalin bucket, dan mundur memulihkannya bersama database.
- [ ] Uji pasang di server dev kedua (dijalankan pemilik produk, lihat
  [on-prem yang dikelola vendor](/todo/on-prem-dikelola/)).

Ekspor laporan tidak termasuk di sini: disknya `COREERP_REPORTING_DISK`, bawaannya `local`, dan
berkasnya dihapus retensi.
