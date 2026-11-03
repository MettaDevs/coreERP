# CPDB-02: koneksi, schema, dan role

Hasil diserahkan melalui [PR #377](https://github.com/MettaDevs/coreERP/pull/377), branch `codex/cpdb-02-connections`.

CPDB-02 menyiapkan tujuan koneksi, store framework, runner migration dan privilege database. Pembuktian memakai PostgreSQL terisolasi dengan database serta login berbeda. Unit ini tidak menjalankan cutover server. Projection, transaksi lintas database, directory Foundation dan revoke tetap pekerjaan CPDB-03. PF-02 tetap menunggu CPDB-05.

Status unit adalah `in_review`. Implementasi teruji berada pada `f278e3dc`; [ringkasan verifikasi](evidence/cpdb-02/verification.json), [hasil runtime](evidence/cpdb-02/runtime.json), [fresh/copy](evidence/cpdb-02/fresh-copy.json) dan [keputusan](evidence/cpdb-02/decisions.tsv) memuat bukti serta batasnya. Basis pekerjaan adalah main `6b3cf584`, hasil merge PF-01 melalui [PR #376](https://github.com/MettaDevs/coreERP/pull/376). Source fisik dan key runtime server tidak dipindahkan.

## Mode dan tujuan koneksi

`COREERP_DATABASE_MODE=combined` mempertahankan pemasangan gabungan, termasuk on-prem perpetual. Ia tidak memerlukan database vendor. Mode `split` membutuhkan nama koneksi control dan bisnis secara eksplisit. Config yang kehilangan salah satunya ditolak sebelum authority dapat mengikuti default aktif.

| Tujuan             | Pemilik config                          | Perilaku                                                                                            |
| ------------------ | --------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Authority control  | `COREERP_CONTROL_CONNECTION`            | Model `OwnedByControlPlane` tetap pada control. Database target contoh adalah `core_control_plane`. |
| ERP pooled         | `COREERP_BUSINESS_CONNECTION`           | `database_name` kosong berarti pool bisnis, bukan control.                                          |
| ERP dedicated      | `EnvironmentConnection::for()`          | Template business runtime, database dari registry, URL diurai sebelum database diganti.             |
| Control migration  | `COREERP_CONTROL_MIGRATION_CONNECTION`  | Database sama dengan control, login berbeda dari runtime.                                           |
| Business migration | `COREERP_BUSINESS_MIGRATION_CONNECTION` | Template migration bisnis dengan tujuan database yang sama dengan runtime terpilih.                 |
| Lifecycle database | `COREERP_MAINTENANCE_CONNECTION`        | Credential provisioning terpisah, tidak diturunkan dari control runtime.                            |

Template PostgreSQL berada pada `apps/core/config/database.php`. Host/port migration boleh mengikuti tujuan domainnya; username dan password tidak mengikuti runtime. `.env.example` memuat nama config, tanpa credential.

`runWithin()` memiliki frame untuk default, authority, binding environment dan PDO yang dibuka operasi tersebut. `finally` memulihkan caller setelah exception. Nested A, B, A mempertahankan PDO dan transaksi ancestor. PDO migration dedicated ikut mempunyai lifecycle; koneksi yang tersisa tidak bertambah seiring jumlah environment.

`register()` menolak alias authority dan nama database yang dilindungi. Pendaftaran identik tidak purge PDO; perubahan credential mengganti koneksi dan tidak boleh memutus transaksi yang masih aktif. Guard lifecycle membatasi create/drop agar control, pool dan maintenance tidak diperlakukan sebagai dedicated environment.

## Store dipilih sebelum manager dibangun

SQL session, password reset, cache, cache lock, queue, failed jobs dan batch menggunakan control sejak config dimuat. Override store tetap eksplisit. Scheduler mempunyai `SCHEDULER_CACHE_STORE`. Redis session/revocation tetap memakai koneksi `sessions`; Redis cache/lock tetap memakai `cache`.

Job bisnis membawa environment ID sebagai scalar. `RunReportExport` menyelesaikan environment dan tenant sebelum membaca `report_exports`; ia tidak memilih database dari tenant saja. Payload lama yang belum merekam context ditolak untuk diminta ulang. Sesi dan cookie customer tidak membawa credential database.

Boundary worker mencatat transaksi serta frame caller sebelum job. Cleanup hanya membuang transaksi/resource milik job dan memulihkan actor/change-log caller yang sah. Sync job tetap berada dalam transaksi pemanggil. Octane tetap membersihkan transaksi yang tertinggal di akhir request.

## Source schema tetap milik Core

| Runner                         | Source                                                    | History                    |
| ------------------------------ | --------------------------------------------------------- | -------------------------- |
| `control:migrate`              | Manifest tetap pada `database/control-plane/manifest.php` | `control_plane_migrations` |
| `migrate`                      | History Core bisnis pada `database/migrations/`           | `migrations`               |
| `module:migrate` dan installer | Migration module yang dipilih                             | `core_module_migrations`   |

Runner control mengabaikan discovery module/provider. Baseline berasal dari migration Core pada fixture PostgreSQL, dipartisi dengan `partition-plan.json`, lalu disimpan sebagai SQL hasil generator. Ia membentuk DDL tanpa mengimpor baris source. Runtime tidak menebak ownership dari nama berkas migration.

Baseline mendahului delta PF-01. Manifest kemudian menjalankan berkas PF-01 asli untuk link principal dan receipt. Fresh bootstrap, upgrade dengan customer sintetis yang sudah ada, serta pemanggilan ulang mempunyai bukti tersendiri. History control tidak diisi dengan seluruh history lama.

Partisi Foundation mengikuti `field-partitions.json`. `company_code` dan `fiscal_calendar_id` tetap ERP. Katalog control hanya menyimpan field yang dibutuhkan pembaca pusat; navigation, pemasangan dan security ERP tidak ikut. Reference dan Address Book pada baseline hanya DDL. Pemilihan baris, provenance, publikasi release dan perubahan writer tetap CPDB-03/04.

Routine control mempunyai implementasi lokal yang tidak membutuhkan tipe atau tabel security ERP. Guard baseline menolak schema berisi tabel, routine, sequence atau type selain history control. Schema deployment yang sah dibuat melalui role migration sebelum ACL/history diterapkan.

Business masih memakai history gabungan secara transisional. Tabel pusat lama yang tertinggal di sana belum menjadi projection yang memenuhi CPDB-03. Keberhasilan migration tidak berarti login, grant, join atau transaksi aplikasi sudah siap berjalan sebagai split.

## Privilege mengikuti operasi

Runtime memperoleh DML dan sequence yang diperlukan, tanpa CREATE/ALTER/DROP. Role migration memiliki schema/objek domainnya. Runtime dapat membaca history, tetapi tidak mengubahnya atau menerbitkan/mengatur nomor sequence history. SELECT sequence history diperlukan oleh dump; USAGE/UPDATE tidak diberikan.

Provisioner mempunyai CREATEDB tanpa SUPERUSER, CREATEROLE, BYPASSRLS atau pewarisan role migration. Database yang dibuat memberinya ownership database, sedangkan migration membuat tabelnya. Grant database dan public schema dilakukan sebelum migration/restore. Schema nonpublic tidak dibuat lebih dahulu oleh jalur copy karena dump membawanya sendiri.

Default privilege dipasang oleh migration role yang benar. Restore tanpa ACL memakai default tersebut sebelum sanitizer membaca target. Runtime control tidak dapat CONNECT ke ERP; runtime/migrator ERP tidak dapat CONNECT ke control. PUBLIC dan role inheritance diperiksa sebagai bagian bukti, bukan diasumsikan aman dari perbedaan nama database.

### Pengecualian process

| Process atau jalur         | Credential yang masih diperlukan             | Batas                                                                                         |
| -------------------------- | -------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Core SaaS sekarang         | Control dan business runtime                 | Satu process masih menjangkau dua authority. Kompromi process belum diisolasi penuh.          |
| Pemasangan module          | Business migration dan runtime               | DDL memakai owner; installation row, nomor dan seed kembali memakai runtime.                  |
| CLI provision/copy/purge   | Provisioner serta credential operasi domain  | Credential maintenance disediakan pada process operasi; tidak dikirim ke browser/job payload. |
| Console `InstalledModules` | Control dan bacaan business                  | Query pemasangan tetap tenant-scoped di business; nama katalog dibaca terpisah di control.    |
| On-prem combined           | Authority lokal dan role lokal yang tersedia | Tidak ada directory vendor atau telemetry wajib yang ditambahkan.                             |

Console tetap tidak mempunyai schema atau migration sendiri. Koneksi `business_read` sebaiknya memakai login baca yang dibatasi tabel pemasangan. Mode combined mempertahankan koneksi lama sampai konfigurasi split disiapkan. Penghapusan credential yang masih diperlukan bukan bukti pemisahan process.

## Bukti dan pengulangan

`scripts/build-control-schema.py --offline` memeriksa fingerprint source, plan, routine dan hasil SQL. Regenerasi online hanya menerima fixture yang dipin dan menolak objek/kolom yang belum diklasifikasikan. `scripts/test_build_control_schema.py` memeriksa guard tersebut.

`scripts/verify-database-connections.py` menerima profile privat fixture secara eksplisit. Ia memeriksa label/container/network, identitas database/login, source hash dan hasil setiap pemeriksaan. Helper yang exit nol dengan pesan Laravel, kehilangan lane atau mengembalikan field asing tidak dapat dihitung lulus. Profile, session ID, reset token dan raw output tidak masuk laporan publik.

Feature test permanen mencakup resolver, schema control/upgrade, schema nonempty, custom schema, N-1, lifecycle PDO, caller transaction/actor, serta sesi/cache Valkey pada proses berbeda. Suite Core tetap mengikuti dua tahap paralel dan serial. Console tetap serial.

Hasil dan batas pengukuran berada pada `evidence/cpdb-02/`. Laporan runtime lulus 58 lane pada source yang hash-nya cocok. Valkey diuji dengan server sungguhan dan proses terpisah. Suite console lulus seluruhnya. Catatan run Core membedakan putaran awal yang gagal dari regresi yang kemudian lulus; tidak mengklaim putaran awal itu hijau.

Full dump/restore role terpisah lulus dengan client16.15 pada server16.14, termasuk schema nonpublic dan history. Client17.11 pada fixture yang sama gagal karena `SET transaction_timeout`. Dockerfile perakit pada basis pekerjaan masih memilih client17; source packaging itu tidak diubah di unit ini. Gap tersebut perlu ditutup pada jalur copy/rehearsal sebelum cutover. Dokumentasi [PostgreSQL16 pg_dump](https://www.postgresql.org/docs/16/app-pgdump.html) juga membatasi jaminan memuat hasil client baru ke server major lama.

Snapshot fixture tidak membuktikan runtime dev1 atau legacy berubah. Tidak ada capacity, RPO/RTO, decrypt/copy data runtime atau rollout yang dinyatakan dari unit ini.

## Lanjut setelah review

CPDB-03 mengganti lookup dan join lintas batas, membuat projection lokal serta durable operation, memisahkan grant pending dari provisioning lokal, dan menguji revoke/staleness. CPDB-04 melatih copy/cutover/restore dan mengukur maintenance. CPDB-05 memberi acceptance serta handoff pelaksanaan. Enrollment dan tenant yang ditakedown tetap mengikuti desired state yang ada.
