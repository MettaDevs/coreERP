# Entry point, permission, privilege, dan duty

::: tip Sebagian besar isi dokumen ini sudah selesai dan dipindah
Rantai `entry point → permission → privilege → duty → security role → assignment` **sudah berjalan** di Core. Penjelasan kanoniknya, beserta aturan dan diagramnya, pindah ke [Rantai keamanan modul transaksi](../../dev/19-transaction-security-chain.md).

Yang tersisa di halaman ini hanya **EP-06 (Segregation of Duties)**. Catatan penyelesaian EP-01 sampai EP-05 dipertahankan di bawah sebagai jejak, bukan sebagai pekerjaan.
:::

## Selesai — jangan dikerjakan ulang

Diverifikasi langsung ke kode, bukan dari status yang tertulis.

| Item | Yang dikerjakan | Bukti di kode |
| --- | --- | --- |
| EP-01 | Detail akses saat admin memilih duty: tiap duty dapat dibuka sampai privilege dan permission efektifnya, lengkap dengan access level dan entry point | `apps/core/resources/js/pages/settings/access.tsx` |
| EP-02 | Layar audit "mengapa orang ini bisa melakukan tindakan ini?" — telusuran read-only per anggota dari role sampai entry point, diikuti batas datanya | `access.tsx`, bagian assignment per anggota |
| EP-03 | Ringkasan tindakan efektif per entry point pada daftar security role, misalnya `Register aset: lihat, tambah, ubah` | `access.tsx`, agregasi permission per entry point |
| EP-04 | Validasi manifest menolak: kode privilege sama dengan kode permission, permission tanpa entry point terdaftar, privilege memakai permission app lain, dan duty memakai privilege app lain. Menu sidebar juga dibatasi permission `read` milik app yang sama | `app/Http/Requests/Provider/AppCatalogRequest.php` |
| EP-05 | Uji otorisasi dan batas data di API app | `app-erp-management-aset/api/tests/Feature/` |

Penyimpanan empat lapis secara terpisah: `app/Actions/Provider/RegisterAppCatalog.php`.

## [~] EP-06 — Segregation of Duties

### Yang sudah ada

- Tabel `sod_rules` per tenant: pasangan duty, tingkat risiko, alasan, opsi mitigasi, status aktif.
- Tabel `sod_conflicts` beserta kolom mitigasi, penyetuju, dan masa berlaku.
- `App\Support\SodConflictEvaluator` menghitung seluruh duty efektif lintas role **termasuk role turunan**, lalu menolak assignment yang memegang kedua duty konflik.
- Penegakan pada perubahan assignment anggota: `app/Actions/Access/UpdateMembership.php`.

### Yang belum ada

- [ ] **Mitigasi belum berjalan.** Kolomnya tersedia di `sod_conflicts`, tetapi tidak ada jalur kode yang mengisinya. Konflik ditolak tanpa jalan pintas — ini disengaja untuk sekarang, tetapi berarti "mitigasi tersedia" tidak boleh diklaim.
- [ ] **Konflik tidak pernah dicatat.** `sod_conflicts` tidak ditulis di mana pun selain migration. Tidak ada jejak audit dan tidak ada layar untuk melihatnya.
- [ ] **Penegakan hanya pada satu jalur.** `app/Actions/Onboarding/RedeemInvitation.php` memberikan seluruh role yang menempel pada undangan tanpa memanggil evaluator. Undangan yang membawa dua duty konflik lolos dari gate yang ditegakkan pada perubahan assignment manual.
- [ ] **Pasangan konflik nyata belum ditetapkan.** Manifest Management Aset punya duty `Kelola pemusnahan aset` untuk membuat usulan; pasangan verifikasi dekomisioning belum ada. Rinciannya di [contoh SoD dekomisioning aset](dekomisioning-aset.md), diagram di [Draw.io](../../diagrams/contoh-sod-dekomisioning-aset.drawio).

### Aturan pengerjaan

Nama dan kode pasangan konflik harus berasal dari manifest app yang sudah terdaftar, bukan di-hardcode di Core. App lain ditambahkan hanya setelah memiliki duty lifecycle yang setara dan pemilik prosesnya menyetujui pasangannya.

**Selesai bila:** pasangan duty yang disetujui dapat ditolak atau dimitigasi secara tercatat, pada **seluruh** jalur pemberian role — bukan hanya assignment manual.

## Referensi Dynamics 365

- [Security architecture — Finance &amp; Operations](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/sysadmin/security-architecture): permission menambah akses ke entry point; security policy membatasi data.
- [Set up a process hierarchy, roles, and privileges](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/setup-process-role-hierarchy): role, duty, privilege, permission, dan level akses CRUD/invoke.
- [Security reports](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/security-reports): laporan yang menelusuri role sampai entry point menurut level akses.
- [Set up segregation of duties](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/set-up-segregation-duties): konflik duty dan kontrol pemisahan tugas.

## Referensi CoreERP

- [Rantai keamanan modul transaksi](../../dev/19-transaction-security-chain.md) — kanonik, termasuk aturan dan diagram
- [Identity dan access](../../dev/09-identity-and-access.md) — model akses lengkap
- [Standar module](../../dev/02-module-standard.md) — kontrak manifest dan kepemilikan security metadata
