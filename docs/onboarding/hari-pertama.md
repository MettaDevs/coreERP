# Hari pertama

Target hari ini: stack lokal jalan, kamu tahu di mana barang disimpan, dan kamu paham istilah yang dipakai tim.

## Checklist

- [ ] Dapat akses ke lima repository (lihat tabel di bawah)
- [ ] Docker Desktop terpasang dan jalan
- [ ] [Stack lokal jalan](/onboarding/setup), Core terbuka di `http://localhost:8000`
- [ ] Baca [Glosarium](/onboarding/glosarium) — tenant, organization, legal entity, operating unit
- [ ] Baca [Grand design](/dev/01-grand-design) — satu kali, tidak perlu hafal
- [ ] Buka `AGENTS.md` di root repo — itu aturan main yang berlaku untuk semua orang

## Clone di folder sejajar

Stack lokal mengasumsikan repository berada dalam satu folder induk yang sama:

```text
D:\Kerja\
├─ CoreERP\
├─ app-erp-management-aset\
├─ app-erp-hr\
├─ app-erp-template\
└─ erp-dev\                 # orkestrasi lokal
```

Kalau strukturnya beda, `start.ps1` tidak akan menemukan app-nya. Ini asumsi yang belum otomatis diperiksa — kalau kamu tergoda menaruhnya di tempat lain, jangan.

## Apa yang kamu jalankan sebenarnya

Setelah stack naik, ini yang aktif:

| Layanan | Alamat | Isinya |
| --- | --- | --- |
| Core app | `http://localhost:8000` | Control plane + web shell. Ini pintu masuk utama. |
| Dokumentasi | `http://localhost:18090` | Situs ini. Ikut nyala bersama stack. |
| Core database | `localhost:5543` | `core_erp` |
| Management Aset — API | `localhost:18091` | App bisnis pertama |
| Management Aset — UI | `localhost:18092` | Dibuka lewat shell Core, bukan langsung |
| Management Aset — database | `localhost:5544` | `management_aset` |
| HR — UI | `localhost:18093` | App bisnis kedua |

Worker dan scheduler Core ikut jalan. Itu penting: job onboarding seperti `DeployAppPlacement` tidak akan selesai kalau worker mati, dan app akan tertahan di status belum siap.

## Peta repo CoreERP

```text
apps/control-plane/    Laravel. Identity, tenant, entitlement, placement, release registry.
apps/web-shell/        Shell UI yang memuat app.
apps/provider-console/ Konsol vendor.
packages/ui/           SDK UI bersama (@apperp/ui).
modules/               Kosong sampai module diimplementasikan di sini.
integrations/          Bridge lintas app.
deploy/                Manifest deployment dan contoh konfigurasi.
docs/                  Dokumentasi ini.
```

Perintah Composer dan NPM untuk Core dijalankan dari `apps/control-plane`, bukan dari root repo.

## Yang jangan dilakukan minggu ini

**Jangan bikin app baru dulu.** Ada gate keputusan yang harus dilewati, dan proposalnya butuh persetujuan. Lihat [Gate penemuan dan keputusan](/dev/18-module-discovery-and-decision-gate).

**Jangan query database app dari Core, atau sebaliknya.** Tidak ada pengecualian. Kalau kamu butuh data dari app lain, jawabannya REST atau event.

**Jangan hardcode nama.** Nama perusahaan, nama orang, nama modul besar — semuanya konfigurasi di database. Kalau ragu, tanya dulu.

**Jangan percaya `docs/todo/`.** Isinya 227 temuan audit yang menunggu review, bukan pekerjaan yang sudah disetujui. Yang mengikat ada di [Desain kanonik](/dev/).

## Besok

[Empat kebenaran lifecycle](/onboarding/empat-kebenaran), lalu [Alur end-to-end](/onboarding/alur-end-to-end). Dua dokumen itu yang paling cepat membuat arsitekturnya masuk akal.
