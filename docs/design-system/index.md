# Design system netral-stack

Folder ini menggambarkan CoreERP tanpa terikat PHP, React, atau framework lain: nilai tampilan (token), bentuk dan perilaku setiap komponen, dan alur dari layar sampai database. Gunanya untuk menilai dan, bila jadi, menjalankan perpindahan ke stack lain. Implementasi baru dianggap setia bila menghasilkan tampilan dan perilaku yang sama serta membaca dan menulis skema database yang sama; yang boleh berbeda adalah cara stack itu menanganinya dan perubahan skema yang membuatnya lebih baik, bila dikerjakan menurut [pemeriksaan kesetiaan](/design-system/04-pemeriksaan-kesetiaan).

## Isi folder

- [Pedoman utama](/design-system/README): bahasa layar, fondasi visual, ikon, logo, dan cara memakai sistem ini.
- `tokens.json`: semua token warna (tema terang dan gelap), tipografi, jarak, radius, bayangan, ukuran, breakpoint, z-index, dan opasitas, dengan catatan pemakaian dan kontras di setiap token.
- [Alur layar](/design-system/01-alur-layar), [Alur permintaan](/design-system/02-alur-permintaan), [Alur data](/design-system/03-alur-data): alur netral-stack dengan nama tabel, kolom, index, dan trigger yang berlaku sekarang.
- [Pemeriksaan kesetiaan](/design-system/04-pemeriksaan-kesetiaan): daftar periksa sebelum sebuah module boleh disebut sudah pindah.
- `components/<Nama>/README.md`: kontrak perilaku setiap komponen, ditautkan dari sidebar per kelompok. `preview.html` di sampingnya adalah rendisi statis HTML/CSS untuk pratinjau, bukan kode yang harus dipakai; stylesheet-nya `components/bundle.css`.
- Catatan aset: [Logo](/design-system/assets/Logos) dan [Ikon](/design-system/assets/Icons).

## Hubungan dengan artifact

Isi yang sama terbit sebagai artifact Design System di claude.ai, lengkap dengan pratinjau yang hidup di tema terang dan gelap: https://claude.ai/artifact/67fEJNECSNBaNo2G4SJse2. Artifact itu privat; orang lain baru bisa membukanya setelah pemiliknya membagikan lewat menu Share.

Folder ini adalah salinan yang di-review lewat pull request. Setelah berkas di sini berubah, terbitkan ulang berkas yang sama ke artifact supaya keduanya tidak menyimpang. Tiga hal tidak ikut disimpan di repo karena sumbernya sudah ada:

- Font: subset latin dari paket `@fontsource/poppins` (300–700) dan `@fontsource-variable/geist`, sesuai `type.fonts` di `tokens.json`.
- Ikon: paket `lucide-react` versi 0.475.0; daftar nama yang dipakai ada di [catatan ikon](/design-system/assets/Icons).
- Logo: `apps/core/public/brand/coreerp-logo.png`.

## Potret, bukan sumber yang ikut berubah sendiri

Isinya diambil dari `packages/ui` serta kode Core dan module pada commit `468efde9` (3 November 2026). Token berasal dari `packages/ui/src/styles.css` dan string kelas di `packages/ui/src/components/`; perubahan di sana tidak otomatis mengubah folder ini.

Kalimat "Dipakai di … layar" pada kartu komponen adalah hitungan pada tanggal itu. Hitung ulang dari akar repo:

```bash
grep -rlE "@apperp/ui/data-table['\"]" apps/core/resources/js apps/control-plane/resources/js modules/apperp/*/ui | wc -l
```

Ganti `data-table` dengan nama berkas komponen di `packages/ui/src/components/`.

## Celah yang dicatat

Selama penelusuran ditemukan perilaku kode yang menyimpang dari aturan repo atau dari dokumen lain, misalnya nomor penerimaan yang bisa bolong dan rute module yang tidak memeriksa pemasangan. Daftarnya ada di bagian akhir [Alur permintaan](/design-system/02-alur-permintaan#_8-celah-yang-ditemukan-keadaan-kode-saat-ini) dan [Alur data](/design-system/03-alur-data#_10-celah-yang-ditemukan-keadaan-kode-saat-ini). Setiap butir perlu diputuskan: ditiru apa adanya di stack baru, atau diperbaiki saat pindah.
