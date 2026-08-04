# UI, monitoring, laporan, dan gate

## [~] Desain visual dari awal

Rancang bersama domain, bukan setelah API selesai:

- register aset dengan filter status, group, unit pengguna, PIC, dan lokasi;
- detail aset dengan timeline penerimaan, penggunaan, lokasi, PIC, mutasi,
  penyusutan, dan pelepasan;
- formulir transaksi bertahap yang membedakan penerima, unit penerima, unit
  pengguna, PIC, dan lokasi;
- halaman proposal/finalisasi penyusutan dengan exception yang jelas;
- visual workflow designer dan work-item inbox dari Core;
- empty state, error state, loading state, konfirmasi destructive action, dan
  akses keyboard yang menjelaskan dampak dalam bahasa pengguna.

Gunakan standar `coreerp-page-standard` dan `coreerp-ui` saat implementasi UI.

## [~] Monitoring dan laporan

Sediakan daftar inventaris serta laporan penyusutan dan mutasi. Monitoring harus
menjawab status/kondisi, lokasi efektif, PIC efektif, dan unit pengguna efektif.
Laporan historis memakai assignment/lokasi pada tanggal laporan; pemindahan hari
ini tidak boleh mengubah laporan bulan lalu.

## [ ] Gate sebelum status selesai

Hasil gate lokal 2026-07-28: scenario 1000 VU / 90 detik, 128 tenant,
empat instance API, dan PostgreSQL nyata menghasilkan **0** pelanggaran SQL
untuk nomor/kunci aset ganda, referensi lintas tenant, serta export penyusutan
ganda. Namun gate ini **belum lulus** karena Nginx mencatat upstream timeout
ketika semua instance jenuh; tidak ada SQLSTATE atau fatal PHP pada log API.
Ulangi pada kapasitas deployment representatif dan jangan menandai bagian ini
selesai sebelum 5xx/timeouts aplikasi memenuhi gate.

- Core workflow, Web Shell inbox, outbox/inbox, attachment, audit trail,
  currency, kalender fiskal, dan number sequence yang dibutuhkan tersedia dan
  memiliki writer/reader/failure state.
- Tidak ada query atau foreign key database lintas Core, Aset, dan backoffice.
- Test membuktikan penerima, unit penerima, unit pengguna, PIC, dan lokasi dapat
  berbeda; direct receipt tetap berhasil.
- Test membuktikan mutasi tidak mengubah histori; aset dengan profile berbeda
  menghasilkan jadwal berbeda; finalisasi immutable; correction/reversal membuat
  baris baru.
- Test membuktikan `posting_id` yang sama tidak menghasilkan export ganda dan
  retry acknowledgement aman.
- Test membuktikan tenant/legal entity/org-unit isolation serta workflow aktif
  dan nonaktif mengikuti kebijakan tenant.
- Sebelum modul disebut selesai, jalankan gate load/concurrency CoreERP: 1000+
  VU, 100+ tenant, 2+ API instance, PostgreSQL nyata, 90 detik, dan verifikasi
  SQL langsung tanpa pelanggaran tenant, nomor ganda, eskalasi hak, atau 5xx.
