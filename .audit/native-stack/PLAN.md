# Pembanding stack ERP

Status 2026-11-03. Eksperimen terisolasi dari origin/main 6b3cf5846fd4442c3e895cacf2ba58275f9dd75e. Analytics tetap hold. Tidak mengubah atau deploy layanan aplikasi yang aktif.

## Definisi selesai

PHP source aktual dan empat kandidat Bun/Hono, Bun/Elysia, Go, Rust menjalankan alur bisnis dan skenario negatif yang sama. Semua efek tersimpan cocok dengan oracle SQL independen. Empat kandidat tidak memakai PHP untuk setup yang dibutuhkan saat operasi, auth, izin, numbering, validasi, write, posting, audit, atau read-back. Pengujian lokal harus lulus sebelum server. Uji dev1/dev2 mencatat konfigurasi, raw metrics privat, ringkasan aman, limit concurrency yang terbukti, variasi lima putaran per kandidat, dan bottleneck. Kesimpulan yang belum punya bukti berstatus NOT VERIFIED atau INCONCLUSIVE.

## Urutan kerja

- [x] Baca prinsip poteto-mode dan workflow figure-it-out.
- [ ] Phase A: Frame. Telusuri source alur tulis, baca, auth, middleware dan query; inventaris runtime lokal dan server tanpa perubahan.
- [ ] Phase B: Design the workflow. Kunci kontrak perilaku, fixture, oracle, query tracing, batas eksperimen dan dua sketsa desain sebelum implementasi.
- [ ] Phase C: Run the loop. Buktikan baseline PHP, bangun empat kandidat, uji kesetaraan lokal, lalu uji bertahap di dev1/dev2.
- [ ] Phase D: Keep the audit trail. Catat keputusan bersama evidence saat terjadi.
- [ ] Phase E: Verify and hand back. Review independen atas code, hasil, fairness, dan audit trail; laporkan fakta, gap, dan perintah ulang.

## Checkpoint

Unit pertama selesai ketika flow source dan baseline PHP dapat direproduksi beserta fingerprint query dan oracle hasil. Jangan menyederhanakan aturan PHP agar kandidat terlihat cepat. Jangan memulai benchmark server sebelum correctness lokal. Server Windows ditunda.

## Beban yang diminta

Jeda tiap aksi diambil secara independen dari distribusi dengan mean 5 detik dan start tersebar. Ini simulasi perilaku pengguna, belum telemetri manusia. Laporkan waktu respon terpisah dari think time serta latensi per aksi dan siklus bisnis. Ramping bertahap hingga pertama gagal SLO dengan generator dan host terpantau. Pisahkan race/retry dari profil pengguna.

## Metrik

Telusuri pedoman resmi Business Central. Repo memiliki target baca p95 <200 ms dan p99 <500 ms, tulis p95 <400 ms dan p99 <900 ms pada concurrency yang tertahan. Ini target proyek, belum hasil. Gate posting/chain penuh akan dinyatakan setelah source diketahui. Nol pelanggaran tenant, nomor/posting ganda, efek rollback salah, dan application 5xx.

## API setelah steering pengguna

Pengguna meminta pilihan di antara tRPC dan oRPC. Keputusan eksperimen memakai oRPC OpenAPI handler pada kedua kandidat Bun. Hono dan Elysia memasang handler yang sama. Go, Rust, dan PHP mengikuti bentuk request, response, status, dan header HTTP yang sama. Overhead oRPC termasuk angka kandidat Bun. Pemilihan rebuild final masih menunggu bukti. Grounding awal yang menyebut tidak ada oRPC didahului keputusan ini.
