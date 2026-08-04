# Human Resources

App bisnis kedua. Menyimpan fakta tenaga kerja: pekerja, jabatan, posisi, dan penugasan pekerja pada posisi.

## Identitas

| | |
| --- | --- |
| ID manifest | `human-resources` |
| Publisher | `apperp` |
| Versi | `0.1.0` — release pengembangan |
| Kind | `business-app` |
| Butuh Core | `^0.1` |
| Repository | `app-erp-hr` |
| Database | `human_resources` |

## Domain yang dimiliki

**Milik app ini** — pekerja, jabatan, posisi, dan penugasan pekerja pada posisi.

**Bukan milik app ini** — identity, tenant membership, security role, dan scope organisasi. Semuanya tetap milik Core.

HR **tidak membuat identity kedua** dan **tidak membaca database Core**. Pekerja ditautkan ke `core_membership_id` dari Core; email hanya dipakai untuk mencari dan menampilkan anggota.

::: tip Kenapa app ini penting untuk platform
HR adalah pemilik kebenaran workforce dan position. [Gate fondasi Core](/dev/10-core-foundation-gates) menempatkan *automatic role assignment* sebagai menunggu app ini: Core mengevaluasi rule, tapi fakta bisnis posisi diterbitkan HR. Selama HR belum stabil, automatic role assignment belum boleh dibangun.
:::

## Alur yang menjadi acuan

Kasus dua business unit — satu orang bekerja di dua tempat tanpa identity ganda:

1. Administrator membuat operating unit dan hierarchy di Core (`/settings/organization`).
2. HR membuat satu pekerja, menautkannya ke `core_membership_id`.
3. HR membuat dua posisi pada operating unit berbeda.
4. HR membuat dua penugasan aktif untuk pekerja itu. **Satu posisi hanya dapat diisi satu pekerja pada periode yang sama.**
5. Core memberi role manual per scope, atau lewat automatic rule berbasis posisi. Token aplikasi membawa hasil scope efektif yang ditandatangani.

Alur inilah yang membuat [data-policy-scoped role assignment](/dev/09-identity-and-access) di Core masuk akal — satu role assignment boleh punya beberapa scope.

## Kontrak

| | |
| --- | --- |
| OpenAPI | `contracts/openapi.yaml` |
| AsyncAPI | `contracts/asyncapi.yaml` |

**Reference nomor** — diterbitkan Core, bukan oleh app:

| Entitas | Reference |
| --- | --- |
| Pekerja | `PEGH` |
| Jabatan | `JABH` |
| Posisi | `POSH` |

## Batas versi ini

Sudah ada: workforce dasar dan integrasi akses berbasis posisi.

**Belum dibangun:** payroll, cuti, rekrutmen, kompensasi, employee self-service, approval workflow, reporting relationship, temporary access, dan SoD.

## Status terhadap gate

| Gate | Status | Catatan |
| --- | --- | --- |
| Manifest dan kontrak | ✅ Ada | `app.yaml`, `contracts/openapi.yaml`, `contracts/asyncapi.yaml` |
| Masuk stack lokal | ✅ Ada | Service `hr-db`, `hr-api`, `hr-ui` terdaftar di `erp-dev/compose.yaml` |
| **Gate concurrency** | ❌ **Belum** | Tidak ada folder `loadtest/` di repository |
| Dokumen di repo app | ⚠️ Minimal | Hanya `README.md`; folder `docs/` belum ada |

::: danger Belum boleh dinyatakan selesai
Tanpa gate concurrency, app ini belum terverifikasi di bawah beban. Rujukan implementasinya ada di `app-erp-management-aset/loadtest/` — stack lengkap dengan empat instance API, PostgreSQL asli, stub Number Sequence sebagai oracle nomor, dan `verify.sql` sebagai oracle kebenaran.
:::

## Menjalankan

Bagian dari stack lokal. Dari folder `erp-dev`:

```powershell
.\start.ps1 -Build
```

| Layanan | Alamat |
| --- | --- |
| UI | `localhost:18093` |

Diakses lewat shell Core di `http://localhost:8000`.

## Dokumen terkait

**Di repository app** — `README.md`.

**Aturan platform yang berlaku:**

- [Identity dan access](/dev/09-identity-and-access) — workforce, position, dan rangkap penugasan
- [Gate fondasi Core](/dev/10-core-foundation-gates) — fondasi yang menunggu app ini
- [Standar module](/dev/02-module-standard) — kontrak app
- [Load dan concurrency testing](/dev/15-load-and-concurrency-testing) — gate yang belum dilewati
- [Backlog keamanan dan akses](/todo/general/02-keamanan-dan-akses) — temuan audit terkait

## Lihat juga

- [Katalog app](/apps/)
- [Management Aset](/apps/management-aset/) — app yang sudah melewati gate concurrency
- [Membangun app baru](/apps/membangun-app-baru)
