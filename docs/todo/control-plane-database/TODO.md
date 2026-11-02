# TODO pemisahan database control plane

Daftar ini menjalankan [PRD](PRD.md). Hasil inventaris dan kontrak CPDB-01 tersedia pada [laporan CPDB-01](CPDB-01.md) untuk review. Pemisahan koneksi/schema dan perilaku aplikasi belum diimplementasikan. Owner berikut adalah peran yang diusulkan; isi nama, branch, PR, SHA, bukti, dan penghambat ketika pekerjaan diambil.

Nama database target adalah `core_control_plane`. Master organisasi/hierarchy dan metadata sharing berada pada Organization Foundation di database tersebut; ERP dan Faskes tidak mempunyai master writable kedua. Baca [kontrak organisasi/sharing](ORGANIZATION-AND-SHARING.md) dan [TODO enforcement healthcare](../metta-platform-portal/SHARING-TODO.md).

Gunakan status `ready`, `blocked`, `in_progress`, `in_review`, `verified`, atau `superseded`. Centang tugas hanya setelah bukti ada. Jangan mengganti ID tugas saat scope berubah; tambahkan keputusan pengganti.

## Urutan dan pembagian kerja

| Unit                 | Owner                                               | Bergantung pada | Status awal | Hasil yang dapat direview                                               |
| -------------------- | --------------------------------------------------- | --------------- | ----------- | ----------------------------------------------------------------------- |
| CPDB-01              | Codex, integrasi platform/database/security         | Tidak ada       | in_review   | [Manifest, baseline, kontrak dan blocker](CPDB-01.md).                  |
| PF-01                | Owner portal platform yang sama dengan rencana lama | CPDB-01         | blocked     | Tenant dapat dibuat tanpa instalasi ERP atau identity duplikat.         |
| CPDB-02              | Database + runtime                                  | CPDB-01, PF-01  | blocked     | Koneksi, target migration, role, serta store framework eksplisit.       |
| CPDB-03              | Platform access + backend ERP                       | CPDB-02         | blocked     | Projection, FK/query pengganti, durable operations, dan kontrak revoke. |
| CPDB-04              | Release owner + database                            | CPDB-03         | blocked     | Rehearsal, copy/validation tooling, dan rollback yang teruji.           |
| CPDB-05              | QA + security + release owner                       | CPDB-04         | blocked     | Acceptance dua database dan handoff cutover.                            |
| PF-02 dan berikutnya | Tim portal/Faskes                                   | CPDB-05         | blocked     | Lanjutkan rencana portal pada storage yang sudah dipisah.               |

PF-01 bukan pekerjaan ganda. Schema ownership mempunyai satu owner integrasi. UI prototyping boleh paralel; perubahan schema bersama tidak dikerjakan oleh dua branch tanpa koordinasi kontrak.

## CPDB-01. Inventaris dan kunci batas

- [x] CPDB-01a. Rekam HEAD, schema version, koneksi aktual, data size, writer aktif, dan baseline latency. [Baseline](CPDB-01.md#baseline-source-dan-runtime) memuat snapshot read-only dua database dan probe empat runtime. Latency yang diukur hanya read SQL; latency aplikasi dan writer yang tidak tampak tetap unknown.
- [x] CPDB-01b. Klasifikasikan semua objek dari model marker, migration Core/module, dan katalog PostgreSQL. `inventory/manifest.json` dan `inventory/migrations.json` memuat move/retain/projection dengan nol objek ditemukan yang belum diklasifikasikan, pada scope snapshot yang disebut.
- [x] CPDB-01c. Temukan FK, raw join, whereHas, eager loading, audit actor lookup, trigger, view, dan fungsi lintas calon batas. [Dependency map](CPDB-01.md#pengganti-coupling-dan-transaksi), indeks source dan kontrak X01..X12 mencatat pengganti. SQL dinamis/writer tak teramati tetap gate trace/fencing rehearsal, bukan dianggap sudah aman.
- [x] CPDB-01d. Putuskan authority pengelola tenant, entitlement, environment grant, undangan organisasi, serta role bisnis ERP. [Kontrak identity/kewenangan](CPDB-01.md#identity-dan-kewenangan) mempertahankan Owner ERP, tanpa `system_role` membership. Schema chain pengelolaan pusat belum diimplementasikan.
- [x] CPDB-01e. Tentukan konfigurasi control/business/maintenance dan lokasi pooled ERP ketika database_name kosong. [Tabel resolusi](CPDB-01.md#resolusi-koneksi-dan-writer) mencakup request, job, CLI, installer, dan on-prem. Pooled ERP tetap business `core_erp`, bukan database control baru.
- [x] CPDB-01f. Catat backup scope, key availability, target revoke/staleness, serta pengukuran RPO/RTO dan maintenance yang harus dilakukan pada rehearsal. [Bukti dan batas pengukuran](CPDB-01.md#backup-kunci-dan-pengukuran) memisahkan hasil read-only dari unknown. Sesuai batas kerja CPDB-01, rehearsal copy/restore tidak dijalankan; hasil terukur tetap wajib pada CPDB-04f.

CPDB-01 juga mencakup rincian berikut sebelum schema final dibuat.

- [x] CPDB-01g. Masukkan Organization Foundation pusat sebagai authority identity/hierarchy tanpa ERP pada manifest/kontrak. [Partisi field dan Address Book](CPDB-01.md#ownership-yang-dikunci) mencakup generic profile, projection dan company setup lokal; bukan migration authority runtime.
- [x] CPDB-01h. Kunci kontrak domain group pasien, clinical-record grants, obat, jasa, pricing/availability dan inventory source. [Kontrak policy/profile](CPDB-01.md#policy-group-profile-custody-dan-pembayaran) dan `inventory/contracts.json` mencakup assignment kelompok, authority katalog, process version, legacy mapping, satu write binding, revision/effective date, capability dan data shared lama.
- [x] CPDB-01i. Tambahkan binding custody serta procurement, clinical org, stock owner, lokasi/custodian, seller, beneficiary, collector dan settlement reference pada kontrak. Bukti berada pada bagian custody `inventory/contracts.json`. Legal eligibility, ledger dan pembayaran tidak dinyatakan berjalan; mode titipan tetap mengikuti SH-06/CD.

Centang CPDB-01 membuktikan keluaran inventaris/kontrak yang diminta, bukan acceptance split atau rehearsal. Owner integrasi adalah Codex pada branch `codex/cpdb-01-evidence-20261102`, source baseline `08366c35547632c6bcf9c7b643ef2455df4fc1bb`. Hasil diserahkan melalui [PR #372](https://github.com/MettaDevs/coreERP/pull/372), dengan bukti lokal pada `inventory/verification.json` dan status CI pada head PR tersebut. PF-01 memakai hasil tersebut dan implementasinya dicatat terpisah pada [laporan PF-01](../metta-platform-portal/PF-01.md).

## PF-01. Gunakan pekerjaan fondasi yang sudah direncanakan

- [x] Implementasi PF-01a sampai PF-01d tersedia untuk review pada [TODO portal](../metta-platform-portal/TODO.md). Jangan membuat creator tenant kedua untuk proyek database.
- [x] Tenant/membership kanonik tanpa bootstrap ERP dibuktikan pada fixture HTTP/SQL [PF-01](../metta-platform-portal/PF-01.md). Transaksi campuran ERP lama tetap satu database; durable adapter boundary dua database masih CPDB-03.

## CPDB-02. Pisahkan koneksi dan migration

- [ ] CPDB-02a. Implementasikan resolusi control, business, environment, dan maintenance yang eksplisit. Bukti berupa sentinel dan current_database() pada koneksi yang berbeda.
- [ ] CPDB-02b. Perbaiki EnvironmentConnection::controlPlane(), for(), register(), dan pins(). Bukti berupa pooled ERP tidak diarahkan ke control DB setelah context switch atau job panjang.
- [ ] CPDB-02c. Tetapkan koneksi session/cache/queue/locks/password reset/scheduler. Bukti berupa daftar store dan test yang benar-benar menjalankan producer/consumer setelah restart.
- [ ] CPDB-02d. Buat target/history migration pusat dan bisnis yang berbeda dengan schema owner tunggal di source Core. Bukti berupa fresh bootstrap dan upgrade, tanpa migration module ERP masuk control DB.
- [ ] CPDB-02e. Pisahkan role runtime, migration, dan provisioning. Bukti berupa uji negatif CONNECT/table/sequence/default privilege dan tidak ada credential maintenance pada browser.
- [ ] CPDB-02f. Dokumentasikan pengecualian runtime yang masih memegang dua credential. Jangan mengklaim pemisahan process atau host selesai.

## CPDB-03. Ganti coupling database bersama

Foundation dan metadata policy pada unit ini tidak menggantikan enforcement pasien/stok pada SH-01/02.

- [ ] CPDB-03a. Buat projection tenant/principal/membership yang dibutuhkan integritas lokal. Bukti berupa FK lokal, field minimum, revision, checkpoint, tombstone, dan rekonsiliasi; tanpa secret.
- [ ] CPDB-03b. Ganti join/subquery lintas batas serta lookup aktor transaksi. Bukti berupa authorization dan audit tetap benar ketika koneksi pusat sengaja diputus setelah snapshot sah diterima.
- [ ] CPDB-03c. Terapkan commit pusat + durable operation/outbox dan commit lokal + receipt. Bukti berupa crash injection pada setiap batas commit, duplicate delivery, dan out-of-order revision.
- [ ] CPDB-03d. Pisahkan acceptance organisasi dari pemasangan akun/role lokal. Bukti berupa local failure mempertahankan grant pending, bukan memberi akses Owner sebagai fallback.
- [ ] CPDB-03e. Terapkan revoke/staleness maksimal 60 detik sesuai PRD dan timeout/backoff terukur. Bukti berupa session aktif, launch baru, outage panjang, dan recovery tidak memperluas akses.
- [ ] CPDB-03f. Buktikan on-prem perpetual tanpa koneksi vendor tetap berfungsi. Bukti berupa login, installer, module migration, lisensi, backup, dan update lokal.
- [ ] CPDB-03g. Sediakan action/kontrak directory dan hierarchy Foundation tenant-scoped dari core_control_plane tanpa entitlement ERP. Buat mapping/projection ERP dan Faskes dengan ID/revision stabil; tidak ada writer master kedua.
- [ ] CPDB-03h. Persist policy definitions dan deployment acknowledgement untuk mode/capability yang dikenal. Uji OS-01/02/09/16/17/18; jangan melaporkan policy effective sebelum target menerapkannya.

## CPDB-04. Bangun dan latih perpindahan

- [ ] CPDB-04a. Implementasikan tooling inventaris/copy/verifikasi yang idempotent beserta dry-run. Nama command dicatat setelah implementasi; jangan menggunakan command placeholder dari dokumen.
- [ ] CPDB-04b. Latih freeze, drain, fencing, backup, copy, sequence repair, checksum, dan decrypt/sign verification pada lingkungan Linux terisolasi.
- [ ] CPDB-04c. Latih rollback sebelum first write tujuan. Bukti berupa source menjadi authority tunggal kembali dan semua proses memakai koneksi yang benar.
- [ ] CPDB-04d. Latih forward repair dan reverse reconciliation setelah first write/side effect. Bukti berupa transaksi ERP baru tidak tertimpa backup lama.
- [ ] CPDB-04e. Uji restart worker, delayed acknowledgement, stale lease, grant dicabut, dan suspend selama cutover. Bukti berupa operasi lama tidak mengaktifkan resource yang ditutup.
- [ ] CPDB-04f. Tetapkan durasi maintenance dan RPO/RTO berdasarkan hasil. Simpan manifest, gap, dan operator runbook final.

## CPDB-05. Verifikasi dan serahkan cutover

- [ ] CPDB-05a. Jalankan acceptance AC-01 sampai AC-16 dengan PostgreSQL dua database dan credential berbeda; mock atau single-DB suite tidak cukup. AC-14/15/16 memeriksa peta capability dan penolakan mode yang belum didukung, termasuk profil kelompok layanan dan custody DEPO. Seluruh enforcement SH tidak menjadi prasyarat cutover; mode sharing tertentu baru dirilis setelah SH dan OS terkait lulus.
- [ ] CPDB-05b. Jalankan Boundary, Tenant, Identity/SSO, Access, Environment, provisioning, suite konsol, serta module yang ditemukan pada dependency map. Jalankan contract bundle dan coverage bila API berubah.
- [ ] CPDB-05c. Uji browser nyata admin, ERP, dan fixture Faskes. Verifikasi forbidden cross-tenant, grant pending, readiness, role, serta takedown/enrollment OFF.
- [ ] CPDB-05d. Bandingkan latency, projection lag, koneksi, antrean, dan error trunk/head pada fixture setara. Ukur hasil; jangan menetapkan capacity dari perkiraan jumlah tenant.
- [ ] CPDB-05e. Minta review independen atas manifest, diff, privilege, secret handling, dan hasil rollback. Catat verdict pada SHA final dan versi seluruh image.
- [ ] CPDB-05f. Serahkan prosedur cutover untuk instruksi pelaksanaan. Tidak melakukan deploy/cutover atau membuka trial hanya karena TODO sudah dibuat.
- [ ] CPDB-05g. Setelah cutover benar-benar disetujui dan lulus, perbarui docs/dev dengan perilaku aktual dan lanjutkan PF-02. Dokumentasi target tidak menggantikan verifikasi runtime.
- [ ] CPDB-05h. Buktikan customer Faskes-only dapat membuat organisasi/hierarchy pada Foundation tanpa membuat instalasi ERP. Simpan hasil akses lintas tenant dan perubahan organisasi yang dibaca kedua adapter.
- [ ] CPDB-05i. Jalankan `node scripts/check-healthcare-domain-neutrality.mjs`. Uji SG-13/14 pada skenario pemerintah dan swasta; review nama tabel/field/event/profile baru agar netral. Checker dokumen tidak menggantikan test kontrak/runtime ketika implementasi tersedia.

## Gate skenario nyata

| Skenario                                            | Hasil wajib                                                                                                    |
| --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Database pusat dan bisnis berbeda                   | Query runtime membuktikan nama DB dan role berbeda; menukar role menghasilkan penolakan yang diperkirakan.     |
| Default connection berubah saat memilih environment | Model pusat tetap di pusat, pooled ERP tetap di bisnis.                                                        |
| Undangan diterima, local grant gagal                | Membership tidak ganda, akun tidak shared, akses environment belum aktif.                                      |
| Worker mati setelah local commit sebelum ack        | Retry membaca receipt dan tidak menggandakan perubahan.                                                        |
| Worker lama selesai setelah suspend                 | Revision lama ditolak; resource tidak dibuka kembali.                                                          |
| Control DB tidak tersedia                           | Operasi authority ditolak; snapshot bisnis hanya berlaku sampai batas staleness. Tidak fallback ke tabel lama. |
| ERP business DB tidak tersedia                      | Platform tidak menyatakan operasi bisnis berhasil; metadata dan kegagalan tetap tercatat dengan benar.         |
| Restore snapshot lama                               | Revoke, desired state, signing/key, dan histori operasi direkonsiliasi sebelum akses dibuka.                   |
| Cutover gagal setelah write baru                    | Forward repair/reverse reconciliation dipakai, bukan env rollback buta atau restore ERP gabungan.              |
| On-prem tanpa jaringan vendor                       | Workflow lokal yang didukung tetap berjalan sesuai edisi.                                                      |

## Bukti dan perubahan

Setiap tugas verified menyimpan PR, SHA, schema version, manifest, output command, dan screenshot atau log perilaku yang relevan. Gunakan status `INCONCLUSIVE` bila bukti belum cukup, bukan dianggap lulus.

| Tanggal    | Keputusan                                                                               | Bukti                                                       | Status                                                     |
| ---------- | --------------------------------------------------------------------------------------- | ----------------------------------------------------------- | ---------------------------------------------------------- |
| 2026-11-02 | Target database control plane terpisah; runtime/repo tetap; bridging ditunda.           | PRD dan instruksi user.                                     | Desain disepakati secara arah; implementasi belum dimulai. |
| 2026-11-02 | Inventaris/kontrak CPDB-01a..i tersedia; rehearsal write/copy/restore tetap di CPDB-04. | [CPDB-01](CPDB-01.md), `inventory/`, dan collector/checker. | in_review; belum ada split runtime.                        |

Jangan mengubah kuota trial, membuka public ERP, mengganti key, atau menghapus data sebagai efek samping pemisahan. Perubahan kebutuhan mengikuti [aturan perubahan portal](../metta-platform-portal/CHANGES.md) dan harus memperbarui acceptance case yang terkena.
