# Part 0 — kerangka berjalan

Bagian dari [Procurement](/todo/procurement/). Sebelum satu fitur pun dibangun, buktikan dulu bahwa
modul `procurement` melewati seluruh jalur hidup sebuah modul: dikenal katalog, dapat dipilih
operator di admin.erp, terpasang di tenant, dan tampil kepada penggunanya. Setiap part sesudahnya
menumpang jalur ini; bila jalurnya patah, lebih murah menemukannya sekarang, saat modulnya masih
kosong, daripada setelah ia memuat proses bisnis.

Tiga keadaan yang wajib dibedakan, sesuai aturan repo: **katalog** berarti modul dikenal,
**entitlement** berarti tenant berhak memakainya, dan **terpasang** hanya sah setelah migration
modul berhasil dan barisnya tercatat di `core_module_installations`. Bukti di bawah memeriksa
ketiganya terpisah.

## Jalur yang sudah ada

| Langkah | Alat |
| --- | --- |
| Membuat modul dari templat | `php artisan module:make` (`modules/_template`) |
| Memasukkan ke katalog | `php artisan app:register-manifest`, membaca `app.yaml` dan folder `manifest/` |
| Memilih modul untuk client baru | admin.erp, dialog tenant baru: daftar app dibaca dari katalog, Core memutuskan ketersediaannya saat `POST internal/v1/tenants` |
| Memasang ke tenant yang sudah ada | `php artisan module:install {module} {tenant}` |
| Ikut image on-prem | `php artisan edition:modules` |

admin.erp belum punya layar untuk menambah modul ke tenant yang **sudah ada**; ia hanya membaca
entitlement. Celah ini dicatat, bukan dikerjakan di part ini — layarnya keputusan tersendiri milik
admin.erp.

## TODO

Status mengikuti [aturan backlog](/todo/).

**Tempat:** modul, `modules/apperp/procurement` · **Setelah:** — · **Selesai bila:** butir 0.2
sampai 0.6 terbukti di stack lokal `erp-dev` yang dibangun ulang dengan `start.ps1 -Build`, dengan
query langsung ke database runtime dan tangkapan layar sebagai buktinya.

- [ ] 0.1 `module:make` membuat `modules/apperp/procurement`: id manifest `procurement`, awalan tabel
  `procurement_` seperti di [halaman Procurement](/apps/procurement/). Repo lama
  `app-erp-procurement` tidak ditarik masuk — isinya hanya health endpoint dan halaman kosong.
- [ ] 0.2 **Katalog.** `app:register-manifest procurement` dijalankan lewat container `core-app`;
  baris `apps` untuk `procurement` ada dengan status yang dapat dibeli.
- [ ] 0.3 **Entitlement dan pemasangan lewat admin.erp.** Dialog tenant baru menampilkan
  Procurement; tenant uji yang dibuat dengan Procurement mendapat baris entitlement **dan** baris
  `core_module_installations`, dan migration modul tercatat di database tenant itu.
- [ ] 0.4 **Tenant yang sudah ada.** `module:install procurement {tenant}` memasang modul ke tenant
  uji lain dengan hasil yang sama seperti 0.3.
- [ ] 0.5 **Tampil kepada pengguna.** Login sebagai tenant uji di `http://localhost:8000`:
  Procurement tampil di peluncur dan menunya terbuka. Tenant tanpa entitlement tidak melihatnya,
  dan rutenya ditolak bila dibuka langsung.
- [ ] 0.6 **Image on-prem.** `edition:modules` memuat `procurement`.
- [ ] 0.7 **Test.** Test penyaringan tenant dari templat lulus; test Boundary di `tests/Feature/Boundary`
  lulus; suite Core dijalankan dua tahap seperti CI.
- [ ] 0.8 Halaman [Procurement](/apps/procurement/) diperbarui: modul mulai dari templat, bukan dari
  repo lama.
