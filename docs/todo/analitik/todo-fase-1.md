# TODO fase 0–1 — kerangka berjalan dan engine untuk satu module

Butir kerja area 0–11 [engine analitik](/todo/analitik/). Aturan pengerjaan, peta ketergantungan,
dan berkas bersama ada di [indeks TODO](/todo/analitik/TODO); baca itu lebih dulu.

Hasil fase ini: konsultan dan admin tenant menyusun dasbor aset sendiri dari dataset module aset,
dengan hak, kebijakan data, dan data pribadi yang terjaga, diuji di bawah beban.

---

### 0. [ ] Kerangka berjalan

**Tempat:** Core Platform, module aset, satu halaman Core · **Setelah:** PR #262 (aturan Core membaca
tabel module) · **Keputusan:** KA-02, KA-03, KA-07, KA-24 · **Skill:** `coreerp-analytics`,
`coreerp-architecture`, `laravel-tdd`, `coreerp-ui`, `code-formatting` · **Selesai bila:**
`POST /api/v1/analytics/query` atas `management-aset.asset-register` memulangkan jumlah aset dan nilai
perolehan per status, dengan tenant, permission, dan kebijakan data dibuktikan test yang pernah
dilihat merah; satu halaman menampilkan satu tile dan satu grafik kolom dari endpoint itu; semua
penjaga Boundary hijau.

Gunanya membuktikan setiap lapis tersambung **sebelum** enam agen bekerja bersamaan, dan membekukan
tiga kontrak yang mereka pakai bersama: antarmuka PHP dataset, bentuk query JSON, dan bentuk hasil.
Semuanya tipis; area 1–9 memperluasnya tanpa mengubah bentuk yang sudah ada.

- [ ] 0.1 **Saklar sementara.** `config/analytics.php` dengan `enabled` (`COREERP_ANALYTICS_ENABLED`,
  bawaan `false`, `true` di `.env.example` lokal). Selama KA-14 belum disetujui, rute dan menu analitik
  hanya terdaftar bila saklar menyala, dan endpoint dijaga keanggotaan tenant ditambah permission baca
  dataset. Kode permission analitik **tidak** dibuat sebelum KA-14 disetujui: kode yang sudah masuk
  role tenant tidak dapat diganti diam-diam (memori repo: kode kontrak bukan untuk disapu).
- [ ] 0.2 **Kontrak tipis**: `Contracts\Analytics\{Dataset, Datasets, DatasetDefinition, Aggregate,
  MeasureFormat}` dengan subset `model()`, `permission()`, `dataPolicy()`, `fieldsFromModel()`,
  `measure()` (Count, Sum, mata uang), `time()`; dan `Contracts\DataPolicyFilter` utuh. Bentuk
  persis di [model semantik](/todo/analitik/model-semantik).
- [ ] 0.3 **Registry**: `Analytics\Datasets\DatasetRegistry implements Datasets`, diikat di
  `CoreServices::SINGLETON_BINDINGS`. Validasi minimal (kode berawalan module, model ber-
  `BelongsToTenant`); validasi lengkap di area 1.
- [ ] 0.4 **Query minimal**: `AnalyticsQuery` (dataset, dimensi biasa, measure, saringan, limit),
  `QueryParser`, validasi kunci dikenal.
- [ ] 0.5 **Compiler minimal**: model dasar tanpa alias, `DataPolicyFilter` sebelum saringan,
  `FieldFilterExpression` untuk saringan, dimensi `d0…`, measure `m0…`, mata uang sebagai dimensi
  tersirat, `LIMIT n + 1`.
- [ ] 0.6 **Eksekutor** baca-saja persis seperti di [mesin query](/todo/analitik/mesin-query#eksekusi-baca-saja),
  termasuk `rollBack()` di `finally`.
- [ ] 0.7 **Principal pengguna minimal**: tenant, permission module dari
  `LaunchableAppCatalog::permissionsFor()`, hibah dari `DataPolicyAccessResolver::resolve()`, zona waktu
  dari layanan yang dipakai `ReportSource::forModule()`.
- [ ] 0.8 **Dataset aset minimal** `AssetRegisterDataset`: `lifecycle_state`, `group_aset_id`,
  `count`, `acquisition_value` (uang, `currency_code`), kebijakan
  `management-aset.asset-responsibility` pada `legal_entity_id` + `responsible_org_unit_id`.
  Didaftarkan di `ModuleServiceProvider::boot()` di samping pendaftaran laporan.
- [ ] 0.9 **Endpoint** `POST /api/v1/analytics/query` di `routes/analytics.php`, yang di-require satu
  baris dari grup `api/v1` di `routes/web.php`. Query dijalankan di dalam `TenantRunner::runFor()`.
- [ ] 0.10 **Halaman** `pages/platform/analytics/explore.tsx` sementara: query tetap, satu tile
  (jumlah aset) dan satu grafik kolom (`@apperp/ui/chart`) nilai perolehan per status. Entri sidebar
  hanya bila saklar menyala.
- [ ] 0.11 **Penjaga**: `AnalyticsBoundaryTest` versi awal — tidak ada `Modules\` dan tidak ada nama
  tabel berawalan module di `app/Platform/Analytics`.
- [ ] 0.12 **Test** di `tests/Feature/Platform/Analytics/WalkingSkeletonTest.php`:
  - dua tenant, angka tidak bercampur;
  - pengguna tanpa permission `management-aset.aset.read` → 403;
  - hibah unit A saja → hanya aset unit A; tanpa hibah → nol;
  - aset IDR dan USD → dua baris nilai, bukan satu jumlah;
  - eksekutor dipanggil di dalam transaksi test, lalu `INSERT` di transaksi yang sama berhasil;
  - setiap test di atas dilihat merah sekali dengan merusak penangkalnya.

**Berkas milik area ini:** semua berkas baru di atas. **Berkas bersama:** `routes/web.php` (satu baris),
`CoreServices.php` (satu baris), `ModuleServiceProvider.php` aset (satu blok).

---

### 1. [ ] Kontrak dataset lengkap dan registry

**Tempat:** `app/Platform/Modules/Contracts/Analytics/*`, `app/Platform/Analytics/Datasets/*`,
`tests/Feature/Boundary/*`, `tests/Fixtures/modules/*` · **Setelah:** 0 · **Keputusan:** KA-03, KA-15,
KA-22 · **Skill:** `coreerp-analytics`, `laravel-patterns`, `laravel-tdd` · **Selesai bila:** seluruh
API `DatasetDefinition` di [model semantik](/todo/analitik/model-semantik) tersedia, setiap aturan
`DatasetValidator` punya test yang merah untuk definisi rusak, dan dataset rusak di runtime dilewati
tanpa menjatuhkan aplikasi.

- [ ] 1.1 `DatasetDefinition` lengkap: `reference()`, `shared()`, `join()`, `fromQuery()`, `measure()`
  dengan `where`, `currency`, `unit`, beberapa `time()`, `recordRoute()`, `version()` dengan `renamed`,
  `description()`.
- [ ] 1.2 `SharedDimension` dan kontrak `SharedDimensions` (registry resolver label). Resolver Core:
  legal entity dan unit kerja (tabel `organizations`), pengguna (nama anggota; label hanya untuk
  principal berhak data pribadi), mata uang. Resolver vendor didaftarkan
  `App\Foundation\Vendor`-nya sendiri dari penyedia layanannya, karena Platform tidak boleh menyebut
  Foundation.
- [ ] 1.3 `DatasetValidator` dengan seluruh aturan di tabel
  [yang diperiksa](/todo/analitik/model-semantik#yang-diperiksa-datasetvalidator). Untuk aturan
  "permission yang dilindungi kebijakan mewajibkan `dataPolicy()`", baca kebijakan module dari manifest
  gabungannya (`ModuleManifestFiles::read()`), bukan dari database.
- [ ] 1.4 `CompiledDataset`: kolom terkualifikasi, `FilterField` per field untuk
  `FieldFilterExpression`, kolom label, tipe database tiap kolom waktu (`date`, `timestamp`,
  `timestamptz`), measure, kebijakan, dan `hash()` definisi untuk kunci cache.
- [ ] 1.5 `DatasetRegistry` lengkap: kompilasi malas sekali per proses; `forTenant()` hanya dataset dari
  module yang terpasang dan berlisensi; dataset rusak dilewati dengan `Log::warning` tanpa data tenant.
- [ ] 1.6 Fixture module di `tests/Fixtures/modules` dengan dua dataset (satu berkebijakan, satu
  tidak), supaya test engine tidak bergantung pada module aset.
- [ ] 1.7 `AnalyticsDatasetsBoundaryTest`: validator atas seluruh dataset terdaftar; dibuktikan merah
  dengan fixture berkolom salah.
- [ ] 1.8 `AnalyticsBoundaryTest` lengkap: juga `DB::table(`/`DB::select(` di luar daftar kelas yang
  memang menyusun SQL.
- [ ] 1.9 `php artisan analytics:datasets` — daftar per module, versi, jumlah field dan measure, hasil
  validasi.

---

### 2. [ ] Model query lengkap

**Tempat:** `app/Platform/Analytics/Query/{AnalyticsQuery, Dimension, TimeRange, TimeGranularity,
QueryParser, QueryNormalizer, QueryValidator, RelativeRange}.php`,
`resources/schemas/analytics-query.schema.json`, `resources/js/lib/analytics/{types,query}.ts` ·
**Setelah:** 0 · **Keputusan:** KA-07, KA-08 · **Skill:** `coreerp-analytics`, `laravel-tdd` ·
**Selesai bila:** semua kunci di [bentuk query](/todo/analitik/mesin-query#bentuk-query) dibaca,
dinormalkan, dan divalidasi dengan galat berpath; token relatif benar di batas hari, minggu, tahun,
dan tahun kabisat untuk tiga zona Indonesia.

- [ ] 2.1 Skema JSON (draft 2020-12) sebagai sumber bentuk untuk kontrak dan tipe TypeScript. Tidak
  menambah pustaka validasi skema: parser memvalidasi dengan aturan sendiri, dan satu test memastikan
  kunci di skema sama dengan kunci yang dikenal parser.
- [ ] 2.2 `QueryParser`: galat 422 dengan path (`dimensions.1.granularity`) dan pesan bahasa Indonesia.
- [ ] 2.3 `QueryNormalizer`: urutan kunci dan nilai daftar tetap; dua JSON setara menghasilkan bentuk
  normal yang sama.
- [ ] 2.4 `QueryValidator` terhadap `CompiledDataset` dan principal: kunci dikenal, batas
  (`config/analytics.php`), granularitas hanya pada field waktu, urutan hanya pada kunci terpilih,
  `limit` dalam batas. Gerbang data pribadi dipanggil di sini, implementasinya milik area 4.
- [ ] 2.5 `RelativeRange` dengan token di [rentang relatif](/todo/analitik/mesin-query#rentang-waktu-relatif);
  menghasilkan ekspresi `Y-m-d..Y-m-d` untuk `FieldFilterExpression`.
- [ ] 2.6 `types.ts` dan pembantu `query.ts` (penyusun query, pembaca kolom hasil) untuk layar.
- [ ] 2.7 Test unit tanpa database: bentuk sah dan tidak sah, normalisasi, token di
  `Asia/Jakarta`, `Asia/Makassar`, `Asia/Jayapura` pada 31 Desember 23.30 dan 29 Februari 2028.

---

### 3. [ ] Compiler, eksekusi, dan hasil

**Tempat:** `app/Platform/Analytics/Query/{QueryCompiler, JoinPlanner, MeasureSql, TimeBucketSql,
QueryExecutor, QueryLimits, CompiledQuery, ResultColumn, ResultSet, LabelResolver, GapFiller,
TotalsQuery, AnalyticsQueryException}.php`, `Console/AnalyticsExplainCommand.php` · **Setelah:** 0, 1,
2 · **Keputusan:** KA-22, KA-24 · **Skill:** `coreerp-analytics`, `laravel-patterns`, `laravel-tdd` ·
**Selesai bila:** setiap aturan di [mesin query](/todo/analitik/mesin-query) punya test, termasuk
batas zona waktu, uang campur, rollback ke savepoint, dan batas waktu yang benar-benar menghentikan
query.

- [ ] 3.1 `JoinPlanner`: hanya join yang disebut; `tenant_id` sama dan `deleted_at IS NULL` pada join
  data; join label menyertakan baris terarsip.
- [ ] 3.2 `MeasureSql`: enam agregat, `FILTER (WHERE …)`, `coalesce` untuk `sum`.
- [ ] 3.3 `TimeBucketSql`: tiga jenis kolom waktu, zona sebagai literal yang dicocokkan dengan
  `DateTimeZone::listIdentifiers()`, minggu mulai Senin.
- [ ] 3.4 Dimensi tersirat mata uang dan satuan; rumus dan total ikut mewarisinya.
- [ ] 3.5 Urutan bawaan (waktu naik, selain itu measure pertama turun), top-N, tanda `truncated`.
- [ ] 3.6 Query total, satu baris per mata uang.
- [ ] 3.7 `QueryExecutor` dan tabel pemetaan SQLSTATE; `InvalidFilterExpression` → 422 berpath.
- [ ] 3.8 `LabelResolver`: pilihan, rujukan module, dimensi bersama (lewat registry area 1), ya/tidak.
- [ ] 3.9 `GapFiller` sampai 1000 titik, per kombinasi dimensi lain.
- [ ] 3.10 `ResultSet`: alias kembali ke kunci, desimal sebagai string, `meta` lengkap.
- [ ] 3.11 `analytics:explain` (SQL + `EXPLAIN` tanpa `ANALYZE`).
- [ ] 3.12 Test:
  - SQL hasil compile tidak pernah memberi alias tabel dasar;
  - baris anak yang (karena data rusak) menunjuk master tenant lain tidak membawa label tenant lain;
  - bucket bulan untuk `timestamp` UTC 30 September 16.30 = Oktober bagi WITA;
  - `pg_sleep` di dataset fixture berhenti di batas waktu → 422 `analytics.query_timeout`;
  - compiler yang dipaksa menulis gagal `25006`;
  - celah bulan terisi nol untuk `count`, kosong untuk `avg`.

---

### 4. [ ] Keamanan baca

**Tempat:** `app/Platform/Analytics/Security/*`, migration katalog keamanan (setelah KA-14),
`CoreSecurityCatalog` · **Setelah:** 0, 1 · **Keputusan:** KA-05, KA-14, KA-15 · **Skill:**
`coreerp-analytics`, `coreerp-architecture`, `security-review` · **Selesai bila:** urutan otorisasi di
[keamanan](/todo/analitik/keamanan#urutan-otorisasi-satu-query) berlaku untuk setiap principal, gerbang
data pribadi menjaga kolom, saringan, urutan, dan drill, dan rantai izin terdaftar (bila KA-14 sudah
disetujui).

- [ ] 4.1 `AnalyticsPrincipal` dan `UserPrincipal` lengkap (batas baris dan waktu dari config,
  `fingerprint()`).
- [ ] 4.2 `DatasetAccess`: terpasang dan berlisensi lewat `LaunchableAppCatalog::for()`, permission lewat
  `permissionsFor()`.
- [ ] 4.3 `DataPolicyScope`: hibah kebijakan dataset → `DataPolicyFilter`; `lockedFilters()` dipasang
  sebagai saringan yang tidak dapat dilepas.
- [ ] 4.4 `PersonalDataGate`: katalog, dimensi, saringan, urutan, kolom drill; label nama orang untuk
  field `EndUserPseudonymousIdentifiers` hanya bagi yang berhak.
- [ ] 4.5 `ScopeFingerprint`: hash hibah terurut + hak data pribadi + saringan terkunci.
- [ ] 4.6 **Setelah KA-14 disetujui**: migration katalog keamanan
  ([contoh](/todo/analitik/keamanan#rantai-izin-yang-diusulkan)), konstanta `CoreSecurityCatalog`,
  gate rute, dan saklar 0.1 dilepas. Bila susunan yang disetujui berbeda, perbarui halaman keamanan
  lebih dulu.
- [ ] 4.7 Test: `PersonalDataGateTest`; `DataPolicyFilterTest` yang memakai kasus yang sama dengan test
  `OrganizationScope` module aset; principal tanpa hibah → nol baris; principal dengan `all` → semua.

`OrganizationScope` module aset **tidak** diubah di area ini. Memindahkannya ke `DataPolicyFilter`
boleh di area 23 sebagai pekerjaan module; sampai itu, test paritas yang menjaga keduanya sama.

---

### 5. [ ] Dataset module aset

**Tempat:** `modules/apperp/management-aset/src/Analytics/*`, satu blok di `ModuleServiceProvider::boot()`,
`modules/apperp/management-aset/tests/Feature/Analytics/*` · **Setelah:** 0, 1 · **Keputusan:** KA-15,
KA-22 · **Skill:** `coreerp-analytics` · **Selesai bila:** setiap dataset di bawah lolos
`AnalyticsDatasetsBoundaryTest`, dan setiap dataset berkebijakan punya test paritas yang pernah dilihat
merah.

Untuk setiap dataset, **baca dulu controller daftar resource-nya**: permission yang dicek dan cara
`OrganizationScope` dipanggil (`asetQuery`, `query` dengan kolom tertentu, atau `legalEntityQuery`)
menentukan `permission()` dan `dataPolicy()`. Jangan menebak dari nama kolom. Nama kelas, tabel, dan
kolom di bawah adalah arah; yang dipakai adalah yang ada di module.

- [ ] 5.1 `asset-register` — register aset (lengkap dari area 0).
- [ ] 5.2 `asset-receipts` — penerimaan aset: vendor (dimensi bersama), nilai, jumlah baris.
- [ ] 5.3 `depreciation-entries` — riwayat penyusutan per buku dan periode; `book-values` — nilai buku
  terakhir per aset per buku, dataset bersumber query.
- [ ] 5.4 `work-orders`, `maintenance-requests`, `downtime` — kebijakan lewat join ke aset bila
  resource-nya tidak punya unit sendiri, persis seperti endpoint daftarnya.
- [ ] 5.5 `disposals` (penjualan dan pemusnahan: hasil, laba/rugi), `value-adjustments`,
  `reclassifications`.
- [ ] 5.6 `insurance-policies`, `warranties` — kebijakan mode legal entity saja bila endpoint daftarnya
  memakai `legalEntityQuery`.
- [ ] 5.7 `physical-checks` — pemeriksaan fisik aset.
- [ ] 5.8 Test per dataset: isolasi tenant, paritas kebijakan, uang per mata uang, measure bersaringan,
  waktu berzona ([daftar](/todo/analitik/model-semantik#test-yang-wajib-menyertai-setiap-dataset)).

KPI pemeliharaan (MTBF, MTTR, ketersediaan) **tidak** dijadikan dataset di fase ini: ia dihitung
`MaintenanceKpi` dari beberapa tabel dengan logika jam yang tidak dapat dinyatakan sebagai agregat
sederhana. Bentuk "dataset terhitung" untuknya diputuskan di area 23.

---

### 6. [ ] Penyimpanan dasbor dan API layar

**Tempat:** migration `analytics_dashboards`, `analytics_widgets`, `analytics_saved_queries`;
`app/Platform/Analytics/{Models, Http}/*`; `routes/analytics.php` · **Setelah:** 0, 2, 4 ·
**Keputusan:** KA-12, KA-14, KA-17 · **Skill:** `coreerp-analytics`, `api-design`, `laravel-patterns`,
`coreerp-architecture` (gate migration) · **Selesai bila:** semua endpoint di
[API untuk layar](/todo/analitik/dasbor-dan-visual#api-untuk-layar) bekerja dengan aturan berbagi,
versi baris, dan isolasi tenant yang diuji, dan widget dihitung sebagai yang melihat.

- [ ] 6.1 Migration sesuai [tabel](/todo/analitik/dasbor-dan-visual#tabel): kolom jejak, `version`, tiga
  trigger, indeks unik parsial; lolos `MigrasiKompatibelMundurTest`, `DataClassificationBoundaryTest`,
  `AuditColumnsBoundaryTest`.
- [ ] 6.2 Model dengan klasifikasi, dan route binding yang menyaring tenant aktif (id tenant lain → 404).
- [ ] 6.3 Controller dan request: dataset (katalog per principal), dasbor, widget, data widget,
  refresh, query tersimpan, query bebas.
- [ ] 6.4 Widget divalidasi saat disimpan: query lewat parser dan validator terhadap dataset saat ini;
  `visual` lewat aturan per jenis widget.
- [ ] 6.5 Membaca widget lama: kunci dipetakan lewat `renamed`; field yang hilang menghasilkan status
  widget `field_removed` dengan nama kolomnya, bukan galat 500.
- [ ] 6.6 Aturan berbagi seperti `ReportOptions` K-25; `RowVersion::claim` pada setiap perubahan.
- [ ] 6.7 Batas widget per dasbor.
- [ ] 6.8 Rute halaman Inertia `/analytics`, `/analytics/dashboards/{id}`, `/analytics/explore`.
- [ ] 6.9 Test: CRUD; aturan berbagi; IDOR 404; 428 tanpa versi, 409 versi basi;
  `SharedDashboardRunsAsViewerTest`; `AnalyticsTenantIsolationTest` untuk setiap endpoint.

---

### 7. [ ] Layar dasbor

**Tempat:** `resources/js/pages/platform/analytics/{index, dashboard}.tsx`,
`resources/js/components/analytics/*`, `resources/js/lib/analytics/{api, format}.ts`,
`components/app-sidebar.tsx` · **Setelah:** 0, 6 (boleh mulai dengan API tiruan dari tipe area 2 dan 6)
· **Keputusan:** KA-12 · **Skill:** `coreerp-ui`, `coreerp-page-standard`, `react-patterns`,
`code-formatting` · **Selesai bila:** semua jenis widget fase 1 tergambar dari hasil nyata, setiap
keadaan galat tampil dengan alasannya, layar diperiksa di runtime yang dibangun ulang — terang, gelap,
lebar ponsel, dan keyboard.

- [ ] 7.1 Daftar dasbor: satu `Card`, `DataTable`, aksi Buat dasbor di `CardAction`.
- [ ] 7.2 Halaman dasbor: grid 12 kolom dengan peta kelas lengkap (bukan string tersusun), mode ubah
  dengan tombol geser dan pilihan lebar, simpan dengan versi.
- [ ] 7.3 `WidgetFrame`: memuat saat terlihat, kerangka saat memuat, galat yang dapat ditindaklanjuti,
  kosong, tanpa akses, data pribadi, tanda terpotong, "Dihitung pukul …", Muat ulang, menu (Ubah,
  Lihat sebagai tabel, Hapus).
- [ ] 7.4 Tile angka dengan ambang ala Cue, uang ringkas ("Rp 1,3 M"), ikon dan teks untuk setiap gaya.
- [ ] 7.5 Grafik kolom, batang, garis, area, donat, bertumpuk lewat `@apperp/ui/chart`; panel per mata
  uang; batas titik.
- [ ] 7.6 Tabel lewat `DataTable` dengan baris total; teks biasa.
- [ ] 7.7 `format.ts` sebagai satu-satunya pemformat angka hasil analitik di layar.
- [ ] 7.8 Entri sidebar dengan permission dasbor; breadcrumb lewat `Page.layout`; tanpa `AppLayout`
  ganda.
- [ ] 7.9 Aksesibilitas: `aria-label` ringkasan grafik, padanan tabel, fokus keyboard pada menu widget.
- [ ] 7.10 Verifikasi runtime: `start.ps1 -Build`, buka lewat rail, tangkapan keadaan galat dengan data
  uji (tidak di-commit; temuan ditulis sebagai teks di pull request).

---

### 8. [ ] Pembangun widget dan penjelajah

**Tempat:** `resources/js/components/analytics/{widget-builder, dataset-picker, measure-picker,
dimension-picker, filter-editor, time-range-picker, visual-picker}.tsx`,
`resources/js/pages/platform/analytics/explore.tsx` · **Setelah:** 2, 6, 7 · **Keputusan:** KA-08, KA-21
· **Skill:** `coreerp-ui`, `coreerp-page-standard` · **Selesai bila:** cerita US-02 lulus — widget
"nilai perolehan per group aset tahun ini" tersusun dari nol dalam kurang dari lima menit oleh orang
yang belum pernah melihat layarnya — dan penjelajah dapat menyimpan hasilnya sebagai widget atau
query tersimpan.

- [ ] 8.1 Alur `Sheet` tujuh langkah di [pembangun widget](/todo/analitik/dasbor-dan-visual#pembangun-widget),
  `portalContainer` pada setiap `Select`.
- [ ] 8.2 `filter-editor.tsx` versi Core dari pola `AdditionalFilters` K-30: field bawaan, "+ Tambah
  saringan", contoh sintaks per tipe, isian dipakai saat Enter atau meninggalkan isian. Pemilih
  rujukan memanggil endpoint lookup module dari metadata dataset; dimensi bersama memakai endpoint
  Core. Layar laporan aset tidak diubah.
- [ ] 8.3 Pemilih tampilan yang menonaktifkan jenis yang tidak cocok beserta alasannya.
- [ ] 8.4 Pratinjau dengan jeda 500 ms dan pembatalan permintaan lama.
- [ ] 8.5 Penjelajah: tabel hasil dengan total, berpindah ke grafik, simpan ke dasbor atau sebagai
  query tersimpan.
- [ ] 8.6 Query penjelajah tersimpan di query string, dibaca ulang setiap render, sehingga analisis
  dapat dibagikan sebagai tautan.

---

### 9. [ ] Cache, batas beban, dan log

**Tempat:** `app/Platform/Analytics/{Cache, Support}/*`, migration `analytics_query_cache` dan
`analytics_query_log`, `RetentionPolicies`, rate limiter di `AnalyticsServiceProvider` · **Setelah:** 3,
4 · **Keputusan:** KA-18, KA-24 · **Skill:** `coreerp-analytics`, `laravel-patterns` · **Selesai bila:**
perilaku di [cache](/todo/analitik/kinerja-dan-uji-beban#cache) dan [batas](/todo/analitik/kinerja-dan-uji-beban#batas)
terbukti test, dan log query tercatat dengan nilai data pribadi disamarkan.

- [ ] 9.1 Tabel cache dan log, dengan klasifikasi dan kolom jejak.
- [ ] 9.2 `QueryCache`: kunci seperti di halaman kinerja, kompresi, batas ukuran, kunci serbuan, TTL per
  widget, lewati cache saat Muat ulang, pembersihan saat baca dan tulis.
- [ ] 9.3 Batas query bersamaan per tenant dengan kunci bernomor → 429 `analytics.busy` dengan
  `Retry-After`.
- [ ] 9.4 Rate limiter `analytics-interactive` per pengguna.
- [ ] 9.5 `QueryLog` dan kebijakan retensi `analytics_query_log` (bawaan 90 hari, PQ-05).
- [ ] 9.6 Test: `AnalyticsCacheIsolationTest` (tenant, sidik jari scope, zona waktu, tanggal); TTL;
  slot habis → 429; nilai saringan field data pribadi tersamarkan di log; retensi terdaftar.

---

### 10. [ ] Uji beban

**Tempat:** `apps/core/loadtest/k6/analytics-*.js`, `apps/core/loadtest/prepare.sh`,
`apps/core/loadtest/verify.sql`, `apps/core/loadtest/README.md` · **Setelah:** 5, 6, 9 · **Skill:**
`coreerp-architecture` (bagian gate beban) · **Selesai bila:** semua skenario di
[uji beban](/todo/analitik/kinerja-dan-uji-beban#uji-beban) berjalan dengan gate kebenaran nol pelanggaran,
gate latensi tercatat beserta perangkat kerasnya, sumber daya yang jenuh pertama disebut, dan oracle
pernah terlihat merah.

- [ ] 10.1 Data uji di `prepare.sh` (ukuran tenant acak, dua legal entity, delapan unit, IDR dan USD,
  tanggal di batas bulan, tiga pengguna per tenant).
- [ ] 10.2 Lima skenario k6, mencatat angka yang diamati untuk oracle.
- [ ] 10.3 Oracle SQL di `verify.sql`; pembuktian merah dengan kebijakan data yang sengaja dimatikan.
- [ ] 10.4 Gate latensi dan laporan sumber daya di README uji beban.
- [ ] 10.5 Skenario campur membuktikan layar transaksi aset tetap dalam gate-nya.

---

### 11. [ ] Dokumentasi dan skill

**Tempat:** `docs/dev/35-analitik.md` (baru), `docs/dev/README.md`, `docs/dev/05-customization-and-addons.md`,
`docs/apps/management-aset/…` (halaman dataset), `docs/.vitepress/config.ts`,
`.agents/skills/coreerp-analytics/SKILL.md` dan salinannya di `.claude/skills/` · **Setelah:** 1–9 ·
**Keputusan:** KA-25 · **Skill:** `coreerp-docs` · **Selesai bila:** halaman kanonik menjelaskan yang
**sudah** dikirim kode (bukan rencana), terdaftar di sidebar, build docs bersih, dan skill memuat
perintah serta jebakan yang benar-benar ditemui selama fase 1.

- [ ] 11.1 `docs/dev/35-analitik.md` dengan pola dokumen repo: kalimat pembuka, konsep yang mudah
  tertukar (dataset vs laporan, dasbor bersama vs publikasi), aturan beserta alasannya, di mana kodenya.
- [ ] 11.2 `docs/dev/05`: dasbor yang dapat disusun dari dataset pindah ke "setelan"; dasbor yang butuh
  data di luar CoreERP tetap integrasi di luar.
- [ ] 11.3 Halaman dataset module aset: daftar dataset, measure, kolom kebijakan, dan kenapa kolom itu.
- [ ] 11.4 Skill `coreerp-analytics` diperbarui dari rencana menjadi keadaan sebenarnya; kedua salinan
  sama (`check-skill-copies.py`).
- [ ] 11.5 Folder TODO ini: status area, keputusan yang berubah, dan temuan yang terbukti salah.
