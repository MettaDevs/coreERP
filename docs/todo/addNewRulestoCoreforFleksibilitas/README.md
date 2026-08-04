# Fleksibilitas akses data seperti Dynamics 365

## Keputusan yang berlaku

CoreERP mengikuti pemisahan Dynamics 365 berikut:

```text
Membership → role assignment → role → duty → privilege → permission
                                │
                                └→ data-policy grant → signed app context
                                                         │
                                                         └→ predicate di API app
```

- Role menjawab **tindakan apa** yang boleh dijalankan.
- Data policy menjawab **record mana** yang boleh dibaca atau diubah.
- Grant adalah milik kombinasi `role assignment + policy`, bukan milik user,
  membership, datatable, atau workspace.
- Satu policy dapat memiliki banyak grant aktif. Grant dalam policy yang sama
  digabungkan; policy berbeda tidak boleh saling meluaskan akses tanpa
  kombinator eksplisit di kontrak resource.
- `include_descendants` selalu menyimpan hierarchy dan versi published yang
  dipakai. Perubahan struktur kemudian tidak mengubah arti grant lama.
- Workspace legal entity/unit hanya memberi nilai awal filter atau dokumen
  baru. Ia bukan bukti akses.
- Bypass bukan efek samping role platform `owner`; bila dibutuhkan, ia harus
  menjadi security role khusus yang eksplisit dan diaudit.

Contoh: seorang holding memiliki grant policy Asset untuk `FO Negarow` dan
`Sales Negarow`. Ia melihat union dua unit itu untuk Asset saja. User yang
hanya memiliki grant `FO Negarow` tidak dapat membaca Sales Negarow. Grant
`Kantor Negarow` tanpa descendants tidak memberi akses ke FO Negarow.

## Referensi Dynamics 365

- [Organizations and organizational hierarchies overview](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/organization-administration/organizations-organizational-hierarchies)
- [Budget planning security organizations](https://learn.microsoft.com/en-us/dynamics365/finance/budgeting/budget-plan#configure-user-security)
- [Extensible data security policies (XDS)](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/extensible-data-security-policies)
- [Role-based security](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/role-based-security)
- [Automatic role assignment](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/assign-users-security-roles)
- [Temporary role management](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/temp-role-mgmt)
- [Workers, jobs, and positions](https://learn.microsoft.com/en-us/dynamics365/human-resources/hr-personnel-departments-jobs-positions)

## Development data reset

Database development adalah disposable. Model ini memakai **reset database +
migration + seed dari nol**. Tidak ada migration data lama, tabel review,
fallback runtime, atau UI compatibility untuk scope lama. Bila nanti ada data
produksi, rencana migrasi harus disetujui sebagai pekerjaan tersendiri sebelum
implementasi dimulai.

## Backlog implementasi

### Core policy catalog dan context

- [x] **FLEX-101 — Simpan policy data per app.** `app_data_policies` menyimpan
  code namespaced, permission yang dilindungi, dimensi legal entity/operating
  unit, dan aturan descendants. Manifest app mengirim deklarasi ini ke Core.
  **Referensi:** XDS, role-based security.

- [x] **FLEX-102 — Simpan grant per role assignment dan policy.**
  `role_assignment_data_policy_scopes` menyimpan legal entity, node organisasi,
  hierarchy, hierarchy version, dan tanggal berlaku. **Referensi:** Budget
  planning security organizations.

- [x] **FLEX-201 — Terbitkan claim policy bertanda tangan.** Token context app
  membawa `data_policies[policy_code]`; tidak ada lagi claim scope organisasi
  global. **Referensi:** XDS.

- [x] **FLEX-202 — Validasi scope di Core.** Core menolak legal entity sebagai
  unit kerja untuk policy operating-unit, hierarchy yang tidak published, node
  lintas tenant, dan policy yang tidak dipakai role. **Referensi:**
  organizations and hierarchies overview.

- [ ] **FLEX-203 — Bypass khusus dan audit.** Tambahkan security role khusus
  untuk bypass policy, terpisah dari owner/admin, lengkap dengan audit dan test
  negatif. Jangan mengaktifkan bypass sampai pemilik produk menyetujui duty dan
  permission khususnya. **Referensi:** XDS bypass role.

- [ ] **FLEX-204 — Temporary assignment.** Tambahkan grant berbatas waktu
  dengan approval dan mode merge/replace tanpa mengubah assignment manual atau
  otomatis. **Referensi:** Temporary role management.

### Access dan onboarding Core

- [x] **FLEX-401 — UI scope per role/policy.** Layar Access mengatur role dan
  batas data per policy, memakai SDK Select yang dapat dicari di dalam dialog.
  Role otomatis hanya ditampilkan dan tidak terhapus saat admin menyimpan
  role manual. **Referensi:** Role-based security, automatic role assignment.

- [x] **FLEX-402 — Invitation yang dapat dipakai ulang.** Kode undangan aktif
  dapat disalin lagi dan setiap redemption membuat assignment serta grant policy
  yang sama. **Referensi:** Role-based security.

- [x] **FLEX-403 — Onboarding bersih.** Owner awal menerima policy scope untuk
  produk yang dipilih; workspace tidak memperluas akses. **Referensi:**
  organizations and hierarchies overview.

### Human Resources

- [x] **FLEX-501 — Worker dan position terpisah dari identity.** HR menghubungkan
  worker ke `core_membership_id`; email hanya untuk cari/tampilan. Worker dapat
  memiliki beberapa position aktif dan satu position hanya memiliki satu worker
  aktif. **Referensi:** Workers, jobs, and positions.

- [x] **FLEX-502 — Automatic role dari position.** Event assignment HR
  mematerialisasi role assignment otomatis dengan grant policy position; selesai
  atau dicabutnya position hanya menghapus source otomatis itu. **Referensi:**
  automatic role assignment.

- [ ] **FLEX-503 — UI aturan automatic role.** Tambahkan administrasi rule
  position → role → policy agar tidak harus menggunakan endpoint internal.
  **Referensi:** automatic role assignment.

### Management Aset

- [x] **FLEX-601 — Policy Asset.** `management-aset.asset-responsibility`
  melindungi fakta aset dengan legal entity pemilik dan operating unit
  penanggung jawab; master referensi tetap tenant-wide. **Referensi:** XDS.

- [ ] **FLEX-602 — Audit seluruh endpoint lifecycle.** Pastikan policy yang
  sama melindungi list, search, detail, create, update, delete, maintenance,
  planning, sale, disposal, monitoring, dan depreciation. Record lama/tidak
  lengkap harus fail closed untuk user scoped. **Referensi:** XDS.

- [ ] **FLEX-603 — Index dan query plan.** Tambah index sesuai predicate policy
  dan ukur plan list/detail pada PostgreSQL nyata. **Referensi:** XDS
  performance impact.

### Kontrak, dokumentasi, dan pembuktian

- [x] **FLEX-701 — OpenAPI Core.** Request membership dan invitation memakai
  assignment/policy scope baru. **Referensi:** Role-based security.

- [ ] **FLEX-702 — Kontrak Asset dan HR.** Dokumentasikan claim token, policy
  code, field record yang dijaga, dan event HR di OpenAPI/AsyncAPI tanpa foreign
  key lintas database. **Referensi:** XDS, automatic role assignment.

- [ ] **FLEX-703 — Diagram.** Perbarui diagram Draw.io dengan worker dua unit,
  manual/automatic role, token policy, dan enforcement app. **Referensi:**
  seluruh referensi di atas.

- [ ] **FLEX-704 — Load gate.** Jalankan 1.000+ VU, 100+ tenant, minimal dua
  API instance, 90 detik, PostgreSQL nyata, dan verifikasi SQL langsung: nol
  leak lintas tenant/policy, nomor duplikat, privilege escalation, dan 5xx.
  **Referensi:** CoreERP architecture load gate.
