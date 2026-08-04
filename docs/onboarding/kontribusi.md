# Cara berkontribusi

## Alur kerja

1. Pastikan pekerjaannya sudah disetujui. Untuk hal baru, lewati [gate penemuan](/dev/18-module-discovery-and-decision-gate) dulu.
2. Buat branch dari `main`.
3. Kerjakan dengan perubahan sekecil mungkin.
4. Jalankan verifikasi lokal (lihat [Definition of done](/onboarding/definition-of-done)).
5. Buka pull request ke `main`.

::: info Model branch belum final
Workflow CI saat ini memasang trigger untuk `main`, `develop`, `master`, dan `workos`, tetapi hanya `main` yang ada di remote. Konfirmasi ke maintainer sebelum membuat branch panjang — dan perbarui halaman ini setelah keputusannya diambil.
:::

## Aturan yang mengikat semua repo

Berlaku di `CoreERP` maupun di setiap repo app.

**Jaga perubahan dan dependency tetap minimal.** Jangan membuat abstraksi atau compatibility layer spekulatif. Kalau ada yang belum jelas, **berhenti dan tanya** — jangan menebak lalu terus jalan.

**Jangan hardcode nama.** Nama perusahaan, nama orang, nama modul besar — semuanya konfigurasi di database.

**Jangan query database lintas app.** Pakai REST/OpenAPI atau event/AsyncAPI. Lihat [API dan integration bridge](/dev/04-api-and-integration).

**Jangan fork Core untuk kebutuhan customer.** Pakai konfigurasi, integration connector, atau addon app. Lihat [Kustomisasi dan addon](/dev/05-customization-and-addons).

**Pertahankan perubahan user yang tidak terkait.** Verifikasi hanya scope yang kamu ubah.

## Bahasa UI

Teks untuk pengguna bisnis — hint, label, dialog, empty state, error, status — wajib memakai bahasa sehari-hari yang menjelaskan tindakan atau dampaknya.

Istilah internal seperti `entitlement`, `artifact`, `deployment registry`, `installation registry`, `TenantContext`, `tenant_id`, atau `placement` **dilarang tampil**, kecuali layarnya memang untuk developer atau operator teknis.

| Jangan | Pakai |
| --- | --- |
| "Entitlement tidak aktif" | "Langganan aplikasi ini sedang tidak aktif" |
| "Artifact belum di-deploy" | "Aplikasi ini sedang disiapkan" |
| "Tenant context tidak valid" | "Sesi kamu sudah berakhir, silakan masuk lagi" |

## Sebelum menambah sesuatu yang baru

App, master, transaksi, workflow, nomor, atau integrasi baru **wajib** melewati gate penemuan: cari referensi resmi Dynamics 365, buat proposal keputusan, tunggu persetujuan. Kalau tidak ada padanan di Dynamics, nyatakan terus terang — jangan mengarang klaim kesetaraan.

Proposalnya menetapkan pemilik data, lifecycle, scope organisasi, keamanan, nomor, workflow/SoD, dan kontrak API/event.

Prosedur dan templatenya di `.agents/skills/module-discovery/SKILL.md`.

## Kalau menyimpan atau menampilkan data operasional

Buka **Data policy decision gate** di `.agents/skills/coreerp-architecture/SKILL.md` dan ikuti. Deklarasikan policy data beserta kontraknya pada manifest **hanya bila** resource-nya memang perlu dibatasi organisasi.

## Menulis dokumentasi

Dokumentasi ikut dalam PR yang sama dengan kodenya. Itu satu-satunya cara ia tetap benar.

**Di mana menulis apa:**

| Isi | Tempat |
| --- | --- |
| Kontrak yang mengikat semua repo | `CoreERP/docs/dev/` |
| Orientasi, setup, glosarium | `CoreERP/docs/onboarding/` |
| Domain spesifik satu app | `docs/` di repo app itu |
| Cara menjalankan stack lokal | `erp-dev/README.md` |

**Aturan menulis:**

- **Jangan mengubah nama berkas di `docs/dev/`.** Repo app lain menautkannya lewat nama berkas, termasuk relative path lintas repo. Mengganti nama akan memutus tautan di repo yang tidak kamu lihat.
- Tulis tautan antar dokumen sebagai path relatif `.md` (`[judul](02-module-standard.md)`) supaya tetap bisa diklik baik di situs maupun saat berkas dibaca langsung.
- Setiap dokumen kanonik diakhiri blok **Lihat juga** yang menautkan dokumen tetangganya.
- Kalau sebuah fondasi belum ada, tulis bahwa ia belum ada. Jangan mengarang state.

**Membaca vs menulis dokumentasi:**

| | Alamat | Kapan dipakai |
| --- | --- | --- |
| Membaca | `http://localhost:18090` | Ikut nyala bersama `start.ps1`. Isinya hasil build, jadi perubahan baru tampil setelah `start.ps1 -Build`. |
| Menulis | `http://localhost:5173` | `npm run docs:dev` dari folder `docs/`. Hot reload, dipakai saat sedang mengedit. |

```bash
cd docs && npm install && npm run docs:dev
```

Sebelum PR yang menyentuh dokumentasi:

```bash
npm run docs:build
```

Build gagal kalau ada tautan antar dokumen yang mati. Itu memang disengaja — jembatan antar dokumen dijaga oleh build, bukan oleh ingatan.

## Lihat juga

- [Definition of done](/onboarding/definition-of-done)
- [Menyiapkan lingkungan lokal](/onboarding/setup)
- [Gate penemuan dan keputusan](/dev/18-module-discovery-and-decision-gate)
