# Hari pertama

Target hari ini: stack lokal jalan, kamu tahu di mana barang disimpan, dan kamu paham istilah yang dipakai tim.

## Checklist

- [ ] Dapat akses ke repo `CoreERP` dan repo orkestrasi lokal
- [ ] Docker Desktop terpasang dan jalan
- [ ] [Stack lokal jalan](/onboarding/setup), Core terbuka di `http://localhost:8000`
- [ ] Baca [Glosarium](/onboarding/glosarium) — tenant, organization, legal entity, operating unit
- [ ] Baca [Grand design](/dev/01-grand-design) — satu kali, tidak perlu hafal
- [ ] Buka `AGENTS.md` di root repo — itu aturan main yang berlaku untuk semua orang

## Clone di folder sejajar

Stack lokal mengasumsikan dua repository berada dalam satu folder induk yang sama:

```text
D:\Kerja\
├─ CoreERP\                   # Core dan seluruh module di dalamnya
└─ erp-docker-start-dev\      # orkestrasi lokal
```

Nama foldernya harus persis, karena `compose.yaml` membangun dari relative path ke `../CoreERP`.
Ini asumsi yang belum otomatis diperiksa — kalau kamu tergoda menaruhnya di tempat lain, jangan.

## Apa yang kamu jalankan sebenarnya

Setelah stack naik, ini yang aktif:

| Layanan | Alamat | Isinya |
| --- | --- | --- |
| Core app | `http://localhost:8000` | Control plane, web shell, **dan seluruh module bisnis**. Ini pintu masuk utama. |
| Dokumentasi | `http://localhost:18090` | Situs ini. Ikut nyala bersama stack. |
| Core database | `localhost:5543` | `core_erp` — Core dan seluruh module memakai database ini |

Worker, scheduler, dan renderer Core ikut jalan. Itu penting: job onboarding tidak akan selesai
kalau worker mati, dan app akan tertahan di status belum siap.

Module tidak punya port sendiri untuk dibuka. Halamannya dirender shell Core pada jalur
`/<id module>/<id entri menu>`, dan menunya muncul setelah module itu dipasang untuk tenant yang
sedang kamu buka.

## Peta repo CoreERP

```text
apps/control-plane/    Laravel. Identity, tenant, entitlement, katalog app, pemasangan module. Sekaligus shell UI yang merender halaman module.
apps/provider-console/ Konsol vendor.
packages/ui/           SDK UI bersama (@apperp/ui).
modules/               Module bisnis, satu folder per module di bawah <penerbit>/. Baca modules/README.md dulu.
editions/              Satu berkas per pelanggan: module apa yang dibeli dan rilis mana yang dipasang.
integrations/          Bridge lintas app.
deploy/                Manifest deployment dan contoh konfigurasi.
docs/                  Dokumentasi ini.
```

Perintah Composer dan NPM untuk Core dijalankan dari `apps/control-plane`, bukan dari root repo.
Perintah itu ikut menjangkau `modules/`: `composer lint:check` menjalankan Pint pada keduanya, dan
`php artisan test` menjalankan suite Core beserta suite `Module` yang menyapu `modules/*/*/tests`.

## Yang jangan dilakukan minggu ini

**Jangan bikin modul baru dulu.** Ada gate keputusan yang harus dilewati, dan proposalnya butuh persetujuan. Lihat [Gate penemuan dan keputusan](/dev/18-module-discovery-and-decision-gate).

**Jangan menyentuh tabel milik module lain.** Tidak ada pengecualian, walaupun tabelnya ada di database yang sama dan `DB::table()` akan berhasil menjangkaunya. Kalau kamu butuh data dari module lain, jawabannya kontrak atau event — bukan query.

**Jangan hardcode nama.** Nama perusahaan, nama orang, nama modul besar — semuanya konfigurasi di database. Kalau ragu, tanya dulu.

**Jangan percaya `docs/todo/`.** Isinya rencana kerja dan temuan audit yang menunggu review, bukan pekerjaan yang sudah disetujui. Yang mengikat ada di [Desain kanonik](/dev/). Cara membaca folder itu dijelaskan di halaman pengantarnya sendiri.

## Besok

[Tiga kebenaran lifecycle](/onboarding/tiga-kebenaran), lalu [Alur end-to-end](/onboarding/alur-end-to-end). Dua dokumen itu yang paling cepat membuat arsitekturnya masuk akal.
