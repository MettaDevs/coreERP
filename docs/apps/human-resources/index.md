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
| Bentuk | Module di runtime Core |
| Folder | `modules/apperp/human-resources/` |
| Namespace PHP | `Modules\Apperp\HumanResources\` |
| Awalan tabel | `hr_` |
| Jalur layar | `/human-resources/<id entri menu>` |

## Domain yang dimiliki

**Milik app ini** — pekerja, jabatan, posisi, dan penugasan pekerja pada posisi.

**Bukan milik app ini** — identity, tenant membership, security role, dan scope organisasi. Semuanya tetap milik Core.

HR **tidak membuat identity kedua** dan **tidak menyentuh tabel Core maupun modul lain**. Pekerja ditautkan ke `core_membership_id` yang diperoleh lewat kontrak `DirektoriOrganisasi`; email hanya dipakai untuk mencari dan menampilkan anggota.

::: tip Kenapa app ini penting untuk platform
HR adalah pemilik kebenaran workforce dan position. [Gate fondasi Core](/dev/10-core-foundation-gates) menempatkan *automatic role assignment* sebagai menunggu app ini: Core mengevaluasi rule, tapi fakta bisnis posisi diterbitkan HR. Selama HR belum stabil, automatic role assignment belum boleh dibangun.
:::

## Alur yang menjadi acuan

Kasus dua business unit — satu orang bekerja di dua tempat tanpa identity ganda:

1. Administrator membuat operating unit dan hierarchy di Core (`/settings/organization`).
2. HR membuat satu pekerja, menautkannya ke `core_membership_id`.
3. HR membuat dua posisi pada operating unit berbeda.
4. HR membuat dua penugasan aktif untuk pekerja itu. **Satu posisi hanya dapat diisi satu pekerja pada periode yang sama.**
5. Core memberi role manual per scope. Konteks dan izin dibaca langsung dari permintaan yang sedang dilayani lewat kontrak `KonteksTenant` dan `KonteksPermintaan` — tidak ada lagi token yang dipertukarkan.

::: warning Sinkronisasi role otomatis berhenti berjalan
Jalur lamanya memanggil Core lewat HTTP untuk menerapkan aturan penugasan role otomatis, dan Core
belum punya kontrak yang menerima penugasan posisi. Penugasan tetap tersimpan; rolenya ditugaskan
admin tenant sampai kontraknya ada. Ini kehilangan yang disengaja dicatat, bukan yang terlewat.
:::

Alur inilah yang membuat [data-policy-scoped role assignment](/dev/09-identity-and-access) di Core masuk akal — satu role assignment boleh punya beberapa scope.

## Kontrak

| | |
| --- | --- |
| OpenAPI | `contracts/openapi.yaml` |
| AsyncAPI | `contracts/asyncapi.yaml` |
| Prefix rute JSON | `/api/modules/human-resources/v1/...` |

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
| Masuk stack lokal | ✅ Ada | Ditemukan dengan memindai `modules/`; tidak ada service Compose yang perlu didaftarkan |
| Penjaga batas | ✅ Hijau | `apps/control-plane/tests/Feature/Boundary/` |
| Batas tenant terbukti | ✅ Ada | `tests/Feature/PenyaringanTenantTest.php` |
| **Gate concurrency** | ❌ **Belum** | Tidak ada folder `loadtest/` |
| Halaman fitur di `docs/apps/` | ⚠️ Minimal | Baru halaman ini |

::: danger Belum boleh dinyatakan selesai
Tanpa gate concurrency, modul ini belum terverifikasi di bawah beban. Rujukan implementasinya ada di `modules/apperp/management-aset/loadtest/` — stack lengkap dengan empat instance API, PostgreSQL asli, dan `verify.sql` sebagai oracle kebenaran.
:::

## Menjalankan

Bagian dari stack lokal. Dari folder orkestrasi:

```powershell
.\start.ps1 -Build
```

Modul tidak punya alamat sendiri. Layarnya dibuka lewat shell Core di `http://localhost:8000` pada
jalur `/human-resources/<id entri menu>`, setelah modul dipasang untuk tenant yang sedang dibuka.
Datanya ada di database Core, pada tabel berawalan `hr_`.

## Dokumen terkait

**Di dalam folder modul** — `README.md`.

**Aturan platform yang berlaku:**

- [Identity dan access](/dev/09-identity-and-access) — workforce, position, dan rangkap penugasan
- [Gate fondasi Core](/dev/10-core-foundation-gates) — fondasi yang menunggu app ini
- [Standar module](/dev/02-module-standard) — kontrak app
- [Load dan concurrency testing](/dev/20-load-and-concurrency-testing) — gate yang belum dilewati
- [Backlog keamanan dan akses](/todo/general/02-keamanan-dan-akses) — temuan audit terkait

## Lihat juga

- [Katalog app](/apps/)
- [Management Aset](/apps/management-aset/) — modul pertama, dengan halaman arsitektur yang lebih lengkap
- [Membangun modul baru](/apps/membangun-app-baru)
