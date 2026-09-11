# Pola dokumen fitur

Halaman ini menjelaskan **cara menulis dokumen fitur** di bawah `docs/apps/`. Halaman fitur yang sudah ada di Management Aset adalah contoh jadinya; ikuti bentuknya.

## Untuk siapa dokumen ini ditulis

Untuk **developer yang akan menyentuh kode itu**, bukan untuk pengguna akhir.

Dokumen Dynamics 365 F&O di internet umumnya menjelaskan cara memakai layar: klik ini, isi itu. Dokumen di sini menjelaskan hal yang berbeda: apa yang disimpan, aturan apa yang dijaga kode, dan **kenapa** aturannya begitu.

Uji sederhananya: kalau seseorang membaca halaman ini lalu membuka kodenya, ia seharusnya tidak menemukan kejutan. Dan kalau ia hendak menambah sesuatu, ia seharusnya tahu apa yang akan ia langgar.

## Yang wajib ada

Tidak semua bagian berlaku untuk semua fitur, tapi ini urutannya:

1. **Kalimat pembuka** — satu paragraf: fitur ini apa, dan satu baris data di sini mewakili apa di dunia nyata.
2. **Konsep yang mudah tertukar** — kalau ada dua hal yang sering dikira sama, jelaskan bedanya lebih dulu. Ini biasanya bagian paling berharga.
3. **Status atau tahapan**, kalau ada — dalam tabel, lengkap dengan siapa atau apa yang boleh mengubahnya.
4. **Data yang disimpan** — tabel dan kolom yang perlu dikenali. Tidak perlu semua kolom; yang penting yang mudah salah paham.
5. **Endpoint** — daftar dan gunanya masing-masing.
6. **Hak akses** — permission apa saja, dan kenapa dipisah begitu.
7. **Aturan yang dijaga, dan alasannya** — bagian terpenting. Lihat di bawah.
8. **Yang datang dari Core** — apa yang tidak dibuat sendiri app ini.
9. **Di mana kodenya** — tabel berkas dan isinya.
10. **Halaman terkait** — tautan.

## Aturan ditulis bersama alasannya

Ini yang membedakan dokumen berguna dari daftar aturan yang diabaikan orang.

Jangan menulis:

> Group aset tidak bisa diganti setelah aset dibuat.

Tulis:

> **Group aset tidak bisa diganti setelah aset dibuat.** Buku penyusutan sudah terbentuk dari matriks group × buku. Mengganti group berarti bukunya salah, tanpa ada yang memberi tahu.

Aturan tanpa alasan akan dianggap birokrasi dan dicari jalan memutarnya. Aturan dengan alasan akan dipertahankan orang, bahkan diperluas ke tempat lain yang mirip.

Kalau Anda tidak tahu alasannya, cari di komentar kode atau riwayat git. Kalau tetap tidak ketemu, tulis apa adanya bahwa alasannya belum diketahui — itu jujur, dan menandai sesuatu yang perlu ditanyakan.

## Bahasa

- **Bahasa Indonesia sehari-hari.** Tulis seperti menjelaskan ke rekan kerja, bukan seperti dokumen resmi.
- **Hindari kata yang jarang dipakai.** Tulis "berkas" bukan "artefak", "susunan" bukan "arsitektur data", "bagian" bukan "komponen" kalau memang bisa.
- **Istilah teknis yang memang nama benda tetap dipakai apa adanya** — `tenant_id`, endpoint, permission, idempotency, deploy, release, event, scope. Menerjemahkannya justru membingungkan waktu orang mencari di kode.

  Batasnya: **selama masih di konteks teknis, biarkan.** "Event bertanda tangan dari Core" jelas bagi developer, dan menggantinya jadi "kejadian bertanda tangan" malah mengaburkan. Yang tidak boleh adalah membawa istilah itu ke kalimat sehari-hari atau ke layar pengguna bisnis — daftar yang dilarang tampil di UI ada di [glosarium](/onboarding/glosarium).

  Yang diganti hanyalah kata yang **tidak punya alasan teknis** dan sudah ada padanan biasanya: "artefak" jadi "berkas", "krusial" jadi menjelaskan kenapa penting.
- **Jangan menerjemahkan kiasan Inggris kata per kata.** Ini kesalahan yang paling sering lolos, karena hasilnya terlihat seperti bahasa Indonesia padahal tidak berarti apa-apa:

  | Jangan | Kenapa | Tulis |
  | --- | --- | --- |
  | "jendelanya 300 detik" | *Time window*. Dalam bahasa Indonesia jendela ya jendela rumah | "batas selisihnya 300 detik" |
  | "pemeriksa yang berteriak serigala" | *Cry wolf*. Ceritanya tidak dikenal sebagai peribahasa di sini | "pemeriksa yang sering salah memberi peringatan" |
  | "stempel karet" | *Rubber stamp* | "persetujuan yang hanya formalitas" |
  | "tidak bisa jadi saksi bagi dirinya sendiri" | Terdengar seperti sidang | "jawabannya tidak bisa dipakai menilai dirinya sendiri" |

  Ujinya: bacakan kalimatnya keras-keras ke orang yang tidak tahu bahasa Inggris. Kalau ia berhenti dan bertanya "maksudnya apa?", ganti.
- **Jangan menakut-nakuti dan jangan menjual.** Tidak perlu "sangat penting", "krusial", "wajib diperhatikan". Kalau memang penting, alasannya yang menunjukkan.

## Angka yang berubah jangan disalin

Jangan menulis "delapan master data" atau "dua puluh sembilan reference". Angka seperti itu basi dalam hitungan minggu, dan dokumen yang salah lebih buruk daripada dokumen yang tidak ada.

Tunjuk sumbernya:

> Daftar master yang berlaku ada di `routes/api.php` pada array `$masters`.

Hal yang sama berlaku untuk daftar kolom, daftar permission, dan versi paket.

## Contoh kode secukupnya

Blok kode dipakai untuk tiga hal saja: perintah yang harus dijalankan, bentuk data yang sulit dijelaskan dengan kalimat, dan pasangan salah/benar yang secara visual nyaris sama.

Selebihnya cukup sebut nama berkas dan fungsinya. Contoh kode di dokumen akan menyimpang dari kode sebenarnya; rujukan ke berkas ikut berubah sendiri.

## Menautkan

Tautkan ke aturan platform yang berlaku, bukan menyalin isinya:

- [Standar module](/dev/02-module-standard)
- [Identity dan access](/dev/09-identity-and-access)
- [Number sequence](/dev/14-number-sequences)
- [Load dan concurrency testing](/dev/20-load-and-concurrency-testing)

Halaman baru wajib didaftarkan di `docs/.vitepress/config.ts` — sidebar disusun manual, jadi halaman yang tidak didaftarkan tidak akan ditemukan orang.

## Sebelum selesai

```powershell
cd docs
npm run docs:build
```

Harus lolos tanpa peringatan tautan mati.

Jangan memakai `npx vitepress build .`: perintah itu menarik VitePress baru ke cache npx tanpa
`vitepress-plugin-mermaid`, dan build gagal pada halaman pertama yang memuat diagram.

## Contoh yang sudah ada

Ikuti bentuk halaman ini:

- [Register aset](/apps/management-aset/transaction/register-aset/) — contoh paling lengkap
- [Master data](/apps/management-aset/master/) — contoh perilaku yang dipakai bersama
- [Pemeliharaan aset](/apps/management-aset/transaction/pemeliharaan-aset/) — contoh fitur dengan mesin status
