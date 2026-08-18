# <Nama App>

<Satu kalimat: app ini mengelola apa. Jangan salin deskripsi pemasaran.>

## Identitas

| | |
| --- | --- |
| ID manifest | `<app-id>` |
| Publisher | `apperp` |
| Versi | `0.1.0` — release pengembangan |
| Kind | `business-app` |
| Butuh Core | `^0.1` |
| Repository | `app-erp-<app-key>` |
| Nama folder lokal | `<isi bila berbeda dari nama repository>` |
| Database | `app_erp_<app>` |
| UI entry | `/apps-content/<app-id>/` |

## Domain yang dimiliki

**Milik app ini** — <data apa yang app ini pegang, termasuk struktur klasifikasi domainnya sendiri.>

**Bukan milik app ini** — <apa yang datang dari Core atau app lain, dan lewat jalur apa.> Identity, tenant membership, security role, scope organisasi, dan penerbitan nomor selalu milik Core.

## Kontrak

| | |
| --- | --- |
| OpenAPI | `contracts/openapi.yaml` |
| AsyncAPI | `contracts/asyncapi.yaml` |
| Health | `GET /api/v1/health` |

**Reference nomor** — <daftar reference yang dideklarasikan manifest, beserta prefix-nya. Kalau tidak ada, tulis "tidak ada" dan sebutkan alasannya.>

**Event yang dipublikasi** — <channel `<app>.<aggregate>.<action>.vN` yang benar-benar terbit, dan app mana yang mengonsumsinya. Kalau belum ada, tulis belum ada.>

**Workflow** — <tipe workflow yang didaftarkan manifest. Kalau tidak ada, tulis tidak ada.>

## Rantai keamanan

<Ringkas duty apa saja yang dideklarasikan manifest, dan role tenant seperti apa yang biasanya disusun dari duty itu. Aturan lengkapnya di
[Rantai keamanan modul transaksi](/dev/19-transaction-security-chain).>

## Struktur kode

| Path | Isinya |
| --- | --- |
| `api/app/Http/Controllers/` | |
| `api/app/Models/` | |
| `ui/src/` | |
| `api/tests/Feature` | |
| `loadtest/` | |

## Status terhadap gate

| Gate | Status | Bukti |
| --- | --- | --- |
| Gate penemuan | | |
| Migration PostgreSQL | | |
| Kontrak | | |
| Test feature | | |
| **Gate concurrency** | | |
| Scope organisasi | | |
| Upgrade release | | |

Pakai ✅ / ⏳ / ❌ dan **selalu sertakan buktinya**. Status tanpa bukti sama saja dengan status palsu — lihat [Gate fondasi Core](/dev/10-core-foundation-gates).

## Menjalankan

Bagian dari stack lokal. Dari folder `erp-dev`:

```powershell
.\start.ps1 -Build
```

| Layanan | Alamat |
| --- | --- |
| API | `localhost:<port>` |
| UI | `localhost:<port>` |
| Database | `localhost:<port>` — `<nama database>` |

Diakses lewat shell Core di `http://localhost:8000`.

## Halaman untuk developer

Halaman ini hanya ringkasan modul. Tiap fitur yang sudah selesai butuh halamannya sendiri, yang menjelaskan **apa yang disimpan, aturan apa yang dijaga kode, dan kenapa aturannya begitu** — bukan cara memakai layar.

Susunannya:

```
docs/apps/<nama-app>/
├── index.md              halaman ini
├── arsitektur/           hal lintas fitur: batas tenant, integrasi Core, kontrak, database, pengujian
├── master/               satu halaman per master yang punya aturan khusus
└── transaction/          satu halaman per dokumen atau proses
```

Bentuk tiap halaman, bahasa yang dipakai, dan hal yang tidak boleh ditulis ada di [Pola dokumen fitur](/apps/management-aset/pola-dokumen). Contoh yang sudah jadi ada di [Management Aset](/apps/management-aset/).

Halaman baru wajib didaftarkan di `docs/.vitepress/config.ts` — sidebar disusun manual, jadi halaman yang tidak didaftarkan tidak akan ditemukan orang.

## Dokumen terkait

**Di repository app** — <daftar berkas dokumen di repo app, dengan satu kalimat isi masing-masing.>

**Aturan platform yang berlaku:**

- [Standar module](/dev/02-module-standard)
- [Rantai keamanan modul transaksi](/dev/19-transaction-security-chain)
- <dokumen kanonik lain yang relevan dengan domain app ini>
- <item backlog terkait di /todo/>

## Lihat juga

- [Katalog app](/apps/)
- [Membangun app baru](/apps/membangun-app-baru)
- [Pola dokumen fitur](/apps/management-aset/pola-dokumen) — cara menulis halaman fitur

---

::: info Cara memakai cetakan ini
Salin folder ini menjadi `docs/apps/<app-key>/`, isi seluruh placeholder `<...>`, lalu daftarkan halamannya pada sidebar `/apps/` di `.vitepress/config.ts` dan pada tabel katalog di `/apps/index.md`.

Isi hanya yang benar-benar ada. Bagian yang belum ada ditulis "belum ada" beserta alasannya — jangan dihapus dan jangan dikarang.

Berkas ini sendiri tidak ikut dirender menjadi halaman situs (`srcExclude` pada config).
:::
