# TODO fase 0–1 — kerangka berjalan dan engine untuk satu module

Butir kerja area 0–11 [engine analitik](/todo/analitik/). Aturan pengerjaan, peta ketergantungan,
dan berkas bersama ada di [indeks TODO](/todo/analitik/TODO); baca itu lebih dulu.

Hasil fase ini: konsultan dan admin tenant menyusun dasbor aset sendiri dari dataset module aset,
dengan hak, kebijakan data, dan data pribadi yang terjaga, diuji di bawah beban.

---

### 0. [x] Kerangka berjalan

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

Selesai 3 Oktober 2026. Yang dikirim berbeda dari rencana di beberapa butir; bedanya dicatat di butir
masing-masing (*Dikirim:*), dan halaman rancangan yang bersangkutan ikut diperbarui.

- [x] 0.1 **Saklar sementara.** `config/analytics.php` dengan `enabled` (`COREERP_ANALYTICS_ENABLED`,
  bawaan `false`, `true` di `.env.example` lokal). Selama KA-14 belum disetujui, rute dan menu analitik
  hanya berlaku bila saklar menyala, dan endpoint dijaga keanggotaan tenant ditambah permission baca
  dataset. Kode permission analitik **tidak** dibuat sebelum KA-14 disetujui: kode yang sudah masuk
  role tenant tidak dapat diganti diam-diam (memori repo: kode kontrak bukan untuk disapu).
  *Dikirim:* rute **tetap terdaftar**, dan middleware `EnsureAnalyticsEnabled` yang menjawab 404 saat
  saklar mati, bukan pendaftaran bersyarat. Daftar rute dibaca Wayfinder untuk tipe layar dan di-cache
  saat container naik; pendaftaran bersyarat membuat keduanya berbeda antar lingkungan, dan saklarnya
  tidak dapat diuji dalam satu proses. Menu memakai prop bersama `analyticsEnabled`.
- [x] 0.2 **Kontrak tipis**: `Contracts\Analytics\{Dataset, Datasets, DatasetDefinition, Aggregate,
  MeasureFormat}` dengan subset `model()`, `permission()`, `dataPolicy()`, `fieldsFromModel()`,
  `measure()` (Count, Sum, mata uang), `time()`; dan `Contracts\DataPolicyFilter` utuh. Bentuk
  persis di [model semantik](/todo/analitik/model-semantik). *Dikirim:* `measure()` sudah bertanda
  tangan lengkap (`unit` dan `where` ikut tersimpan), `Aggregate` dan `MeasureFormat` lengkap.
- [x] 0.3 **Registry**: `Analytics\Datasets\DatasetRegistry implements Datasets`, diikat di
  `CoreServices::SINGLETON_BINDINGS`. Validasi minimal (kode berawalan module, model ber-
  `BelongsToTenant`); validasi lengkap di area 1. *Dikirim:* juga permission milik module sendiri, nama
  kolom berbentuk pengenal, measure uang wajib menyebut kolom mata uang (KA-22), dan measure selain
  `Count` wajib menyebut kolomnya. Definisi dibaca sekali per proses; field dibaca dari database setiap
  kali (`TableFields` menyimpan tipe kolom per database), dan database yang belum punya tabel dataset
  menjawab dataset tidak tersedia. Method `CompiledDataset` di
  [arsitektur](/todo/analitik/arsitektur#compileddataset).
- [x] 0.4 **Query minimal**: `AnalyticsQuery` (dataset, dimensi biasa, measure, saringan, limit),
  `QueryParser`, validasi kunci dikenal. *Dikirim:* konstruktor `AnalyticsQuery` sudah berbentuk lengkap
  (`Dimension`, `TimeRange`, `TimeGranularity` tipis); `QueryValidator` tipis memeriksa kunci terhadap
  dataset, kunci ganda, dan `limit`. Kunci yang belum dibaca (`time_range`, `sort`, `totals`,
  `fill_gaps`, dimensi berember waktu) ditolak 422 `analytics.invalid_query`, bukan diabaikan.
- [x] 0.5 **Compiler minimal**: model dasar tanpa alias, `DataPolicyFilter` sebelum saringan,
  `FieldFilterExpression` untuk saringan, dimensi `d0…`, measure `m0…`, mata uang sebagai dimensi
  tersirat, `LIMIT n + 1`. *Dikirim:* tanpa SQL mentah sama sekali — Larastan menuntut `literal-string`
  pada `selectRaw`/`orderByRaw`/`groupByRaw`, jadi kolom lewat `addSelect`/`groupBy`/`orderBy` dan
  measure lewat `selectExpression(new MeasureExpression(…))`. Urutan bawaan `m0 desc` lalu dimensi;
  `NULLS LAST` untuk measure yang dapat kosong, dan saringan tetap measure (`where`, sekarang ditolak
  `LogicException`), milik area 3.
- [x] 0.6 **Eksekutor** baca-saja persis seperti di [mesin query](/todo/analitik/mesin-query#eksekusi-baca-saja),
  termasuk `rollBack()` di `finally`. *Dikirim:* galat database yang bukan kesalahan pengguna dilempar
  apa adanya (`fromDatabase($e) ?? $e`), supaya tetap 500 dan dilaporkan.
- [x] 0.7 **Principal pengguna minimal**: tenant, permission module dari
  `LaunchableAppCatalog::permissionsFor()`, hibah dari `DataPolicyAccessResolver::resolve()`, zona waktu
  dari layanan yang dipakai `ReportSource::forModule()`. *Dikirim:* `UserPrincipal::fromMembership()`
  dengan subset antarmuka `AnalyticsPrincipal`, dan `Security\DatasetAccess` tipis untuk langkah 3–4
  urutan otorisasi (module terpasang dan berlisensi → 404, permission → 403).
- [x] 0.8 **Dataset aset minimal** `AssetRegisterDataset`: `lifecycle_state`, `group_aset_id`,
  `count`, `acquisition_value` (uang, `currency_code`), kebijakan
  `management-aset.asset-responsibility` pada `legal_entity_id` + `responsible_org_unit_id`.
  Didaftarkan di `ModuleServiceProvider::boot()` di samping pendaftaran laporan. *Dikirim:* juga field
  `currency_code` dan `acquired_on` (field waktu utama).
- [x] 0.9 **Endpoint** `POST /api/v1/analytics/query` di `routes/analytics.php`, yang di-require satu
  baris dari `routes/web.php`. Query dijalankan di dalam `TenantRunner::runFor()`. *Dikirim:* baris
  `require` ada di grup **`auth`**, bukan di dalam grup `api/v1`: berkas yang sama memuat halaman
  `/analytics/...` (area 6.8 dan 8), yang tidak boleh berawalan `api/v1`. API di dalamnya memakai
  `Route::prefix('api/v1/analytics')->name('api.analytics.')`. Satu query dijalankan `Actions\RunQuery`,
  sama untuk setiap jalur masuk.
- [x] 0.10 **Halaman** `pages/platform/analytics/explore.tsx` sementara: query tetap, satu tile
  (jumlah aset) dan satu grafik kolom (`@apperp/ui/chart`) nilai perolehan per status. Entri sidebar
  hanya bila saklar menyala. *Dikirim:* query **tidak ditulis mati** di layar, karena `AGENTS.md`
  melarang nama module ditulis mati: `ExploreController` menyusunnya dari dataset pertama yang boleh
  dibaca pengguna — measure hitung pertama untuk tile, measure uang pertama per field pilihan pertama
  untuk grafik — dan judulnya dari nama tampilan dataset. Satu panel grafik per mata uang, dengan tabel
  padanannya. Verifikasi layar di runtime dikerjakan sesi induk.
- [x] 0.11 **Penjaga**: `AnalyticsBoundaryTest` versi awal — tidak ada `Modules\` dan tidak ada nama
  tabel berawalan module di `app/Platform/Analytics`. Awalan dibaca dari `table_prefix` setiap
  `app.yaml`, termasuk module contoh bahan uji.
- [x] 0.12 **Test** di `tests/Feature/Platform/Analytics/WalkingSkeletonTest.php`:
  - dua tenant, angka tidak bercampur;
  - pengguna tanpa permission `management-aset.aset.read` → 403;
  - hibah unit A saja → hanya aset unit A; tanpa hibah → nol;
  - aset IDR dan USD → dua baris nilai, bukan satu jumlah;
  - eksekutor dipanggil di dalam transaksi test, lalu `INSERT` di transaksi yang sama berhasil;
  - setiap test di atas dilihat merah sekali dengan merusak penangkalnya.

  *Dikirim:* juga saringan dan galat berpath, module tidak terpasang → 404, saklar mati → 404 untuk
  halaman dan API, halaman Inertia beserta query pratinjaunya, dan `DatasetRegistryTest` untuk
  pemeriksaan minimal registry. Cara setiap penjaga dibuat merah dicatat di pull request area 0.

**Berkas milik area ini:** semua berkas baru di atas. **Berkas bersama:** `routes/web.php` (satu baris),
`CoreServices.php` (satu baris), `ModuleServiceProvider.php` aset (satu blok),
`components/app-sidebar.tsx` (satu entri), serta `HandleInertiaRequests.php` dan `types/global.d.ts`
(prop `analyticsEnabled`, dibuang area 4 bersama saklarnya).

---

### 1. [x] Kontrak dataset lengkap dan registry

**Tempat:** `app/Platform/Modules/Contracts/Analytics/*`, `app/Platform/Analytics/Datasets/*`,
`tests/Feature/Boundary/*`, `tests/Fixtures/modules/*` · **Setelah:** 0 · **Keputusan:** KA-03, KA-15,
KA-22 · **Skill:** `coreerp-analytics`, `laravel-patterns`, `laravel-tdd` · **Selesai bila:** seluruh
API `DatasetDefinition` di [model semantik](/todo/analitik/model-semantik) tersedia, setiap aturan
`DatasetValidator` punya test yang merah untuk definisi rusak, dan dataset rusak di runtime dilewati
tanpa menjatuhkan aplikasi.

Selesai 4 Oktober 2026. Yang dikirim berbeda dari rencana di beberapa butir; bedanya dicatat di butir
masing-masing (*Dikirim:*), dan [model semantik](/todo/analitik/model-semantik) serta
[arsitektur](/todo/analitik/arsitektur#compileddataset) ikut diperbarui.

- [x] 1.1 `DatasetDefinition` lengkap: `reference()`, `shared()`, `join()`, `fromQuery()`, `measure()`
  dengan `where`, `currency`, `unit`, beberapa `time()`, `recordRoute()`, `version()` dengan `renamed`,
  `description()`. *Dikirim:* juga `field()`. `fromQuery()` menerima query **Eloquent**, bukan query
  builder sembarang, supaya modelnya dapat diperiksa; query sumber wajib memilih `tenant_id`.
- [x] 1.2 `SharedDimension` dan kontrak `SharedDimensions` (registry resolver label). Resolver Core:
  legal entity dan unit kerja (tabel `organizations`), pengguna (nama anggota; label hanya untuk
  principal berhak data pribadi), mata uang. Resolver vendor didaftarkan
  `App\Foundation\Vendor`-nya sendiri dari penyedia layanannya, karena Platform tidak boleh menyebut
  Foundation. *Dikirim:* kontrak resolver `SharedDimensionResolver` dengan `labelClassification()`;
  `SharedDimensionRegistry::labels(…, $mayUsePersonalData)` menahan label `EndUserIdentifiableInformation`.
  Mata uang ternyata fitur Foundation (`App\Foundation\Currency`), jadi resolvernya didaftarkan fitur itu,
  seperti vendor; labelnya kode ISO sampai master mata uang ada (FIN-20). Label vendor ikut berkelas data
  pribadi, karena nama party di buku alamat berkelas itu. Ikatan `SharedDimensions` di `CoreServices`
  maju dari area 14 ke area ini.
- [x] 1.3 `DatasetValidator` dengan seluruh aturan di tabel
  [yang diperiksa](/todo/analitik/model-semantik#yang-diperiksa-datasetvalidator). Untuk aturan
  "permission yang dilindungi kebijakan mewajibkan `dataPolicy()`", baca kebijakan module dari manifest
  gabungannya (`ModuleManifestFiles::read()`), bukan dari database. *Dikirim:* dua tahap — `declare()`
  tanpa database dan `compile()` terhadap database — dan tujuh aturan tambahan yang dicatat di tabel itu
  (kebijakan ada di manifest, kunci field yang membayangi kolom lain, alias join milik engine, kolom
  `only`/`except`, rujukan, query sumber, versi).
- [x] 1.4 `CompiledDataset`: kolom terkualifikasi, `FilterField` per field untuk
  `FieldFilterExpression`, kolom label, tipe database tiap kolom waktu (`date`, `timestamp`,
  `timestamptz`), measure, kebijakan, dan `hash()` definisi untuk kunci cache. *Dikirim:* daftar method
  di [arsitektur](/todo/analitik/arsitektur#compileddataset), termasuk `classification()` untuk area 4,
  `joins()`/`reference()`/`labelColumnsFor()` untuk `JoinPlanner` area 3, dan `baseQuery()` untuk
  dataset bersumber query. Join dan join label **dinyatakan** di sini tetapi belum dipasang compiler
  area 0; query yang memakai kolom join masih gagal sebagai galat SQL sampai area 3.
- [x] 1.5 `DatasetRegistry` lengkap: kompilasi malas sekali per proses; `forTenant()` hanya dataset dari
  module yang terpasang dan berlisensi; dataset rusak dilewati dengan `Log::warning` tanpa data tenant.
  *Dikirim:* definisi sekali per proses, hasil kompilasi sekali **per database** (pekerja FrankenPHP
  melayani beberapa database environment); dataset rusak dicatat sekali; tabel yang belum ada tidak
  disimpan, supaya module yang dipasang sesudahnya langsung terbaca; `diagnose()` untuk perintah dan
  penjaga.
- [x] 1.6 Fixture module di `tests/Fixtures/modules` dengan dua dataset (satu berkebijakan, satu
  tidak), supaya test engine tidak bergantung pada module aset. *Dikirim:* di `contoh-a`, bukan module
  baru (menambah module contoh mengubah daftar module yang diuji banyak test lain): tabel
  `contoh_a_tr_penjualan` dengan ketiga jenis kolom waktu, satu kolom data pribadi, dan id pengguna;
  `contoh-a.penjualan` (kebijakan, join, rujukan, dimensi bersama, uang, measure bersaringan) dan
  `contoh-a.barang` (tanpa kebijakan); manifest `manifest/barang.yaml` dan `manifest/penjualan.yaml`.
- [x] 1.7 `AnalyticsDatasetsBoundaryTest`: validator atas seluruh dataset terdaftar; dibuktikan merah
  dengan fixture berkolom salah.
- [x] 1.8 `AnalyticsBoundaryTest` lengkap: juga `DB::table(`/`DB::select(` di luar daftar kelas yang
  memang menyusun SQL. *Dikirim:* daftarnya `SQL_COMPOSERS` (compiler, cache, log); `DB::select…`
  menangkap `selectOne` juga.
- [x] 1.9 `php artisan analytics:datasets` — daftar per module, versi, jumlah field dan measure, hasil
  validasi. *Dikirim:* keluar dengan kode gagal bila ada dataset rusak, supaya dapat dipakai di CI.

---

### 2. [x] Model query lengkap

**Tempat:** `app/Platform/Analytics/Query/{AnalyticsQuery, Dimension, TimeRange, TimeGranularity,
QueryParser, QueryNormalizer, QueryValidator, RelativeRange}.php`,
`resources/schemas/analytics-query.schema.json`, `resources/js/lib/analytics/{types,query}.ts` ·
**Setelah:** 0 · **Keputusan:** KA-07, KA-08 · **Skill:** `coreerp-analytics`, `laravel-tdd` ·
**Selesai bila:** semua kunci di [bentuk query](/todo/analitik/mesin-query#bentuk-query) dibaca,
dinormalkan, dan divalidasi dengan galat berpath; token relatif benar di batas hari, minggu, tahun,
dan tahun kabisat untuk tiga zona Indonesia.

Selesai 3 Oktober 2026, di atas cabang area 0 (PR #274). Yang dikirim berbeda dari rencana di beberapa
butir; bedanya dicatat di butir masing-masing (*Dikirim:*), dan halaman rancangan yang bersangkutan ikut
diperbarui.

- [x] 2.1 Skema JSON (draft 2020-12) sebagai sumber bentuk untuk kontrak dan tipe TypeScript. Tidak
  menambah pustaka validasi skema: parser memvalidasi dengan aturan sendiri, dan satu test memastikan
  kunci di skema sama dengan kunci yang dikenal parser. *Dikirim:* `QueryShapeSyncTest` membandingkan
  lebih dari yang dijanjikan — kunci di setiap tingkat (dimensi, `time_range`, `sort`), ukuran waktu dan
  arah urutan, kunci tipe `AnalyticsQuery` di `types.ts`, dan daftar token periode serta ukuran waktu di
  `query.ts` terhadap `RelativeRange::TOKENS` dan `TimeGranularity`. Kunci fase 2 (`compare`, `formulas`)
  belum ada di skema: skema menggambarkan yang berlaku.
- [x] 2.2 `QueryParser`: galat 422 dengan path (`dimensions.1.granularity`) dan pesan bahasa Indonesia.
  *Dikirim:* semua kunci bentuk query dibaca, termasuk objek dimensi, `time_range`, `sort`, `totals`, dan
  `fill_gaps` (bawaannya mengikuti ada tidaknya dimensi waktu). `compare` dan `formulas` ditolak "belum
  tersedia". Isian saringan `null` dibaca sebagai kosong, karena `ConvertEmptyStringsToNull` mengubah
  teks kosong di badan JSON menjadi `null` sebelum sampai ke parser; tanpa itu isian yang dikosongkan di
  layar ditolak 422.
- [x] 2.3 `QueryNormalizer`: urutan kunci dan nilai daftar tetap; dua JSON setara menghasilkan bentuk
  normal yang sama. *Dikirim:* ia mengembalikan `AnalyticsQuery` yang sudah satu bentuk (bukan larik), dan
  `RunQuery` memanggilnya sebelum apa pun, jadi hash dan kunci cache sama untuk setiap jalur masuk.
  Saringan kosong dibuang, `fill_gaps` tanpa dimensi waktu dimatikan; urutan dimensi, measure, dan `sort`
  dipertahankan karena mengubah hasil. `AnalyticsQuery::normalized()` sekarang mengurutkan pilihan sebagai
  teks (`SORT_STRING`), supaya hasilnya tidak bergantung pada perbandingan angka-sebagai-teks PHP.
- [x] 2.4 `QueryValidator` terhadap `CompiledDataset` dan principal: kunci dikenal, batas
  (`config/analytics.php`), granularitas hanya pada field waktu, urutan hanya pada kunci terpilih,
  `limit` dalam batas. Gerbang data pribadi dipanggil di sini, implementasinya milik area 4.
  *Dikirim:* kunci config baru `limits.dimensions`, `limits.measures`, `limits.filters`, `limits.sort`;
  `time_range` memakai kolom waktu dataset dan token yang dikenal. Titik panggil gerbang data pribadi
  adalah antarmuka `Query\FieldUseGate`, dipanggil terakhir dengan peta path → kolom yang dipakai query.
  Parameternya opsional dan tidak ada implementasinya: **sampai area 4 mengikatnya, tidak ada pemeriksaan
  data pribadi di jalur ini**, sama dengan keadaan area 0 (dataset yang ada tidak menawarkan kolom data
  pribadi). Area 4 menjadikannya wajib. Tidak ada method baru yang diminta dari `CompiledDataset` untuk
  validator; kebutuhan area 4 (klasifikasi per field) dicatat di [arsitektur](/todo/analitik/arsitektur#compileddataset).
- [x] 2.5 `RelativeRange` dengan token di [rentang relatif](/todo/analitik/mesin-query#rentang-waktu-relatif);
  menghasilkan ekspresi `Y-m-d..Y-m-d` untuk `FieldFilterExpression`. *Dikirim:* fungsi statis
  (`expression()` dan `bounds()` untuk `GapFiller` area 3), bukan kelas yang disuntikkan seperti di sketsa
  compiler. Karena parser kini membaca `time_range` dan `sort`, `QueryCompiler` ikut mengompilasinya
  (sebagian kecil area 3.5) supaya tidak ada kunci yang dibaca lalu diabaikan; ember waktu dan `totals`
  ditolak 422 "belum tersedia" sampai area 3.
- [x] 2.6 `types.ts` dan pembantu `query.ts` (penyusun query, pembaca kolom hasil) untuk layar.
  *Dikirim:* `types.ts` hanya bertambah alias bernama di akhir (`QueryDimension`, `QuerySort`,
  `QueryTimeRange`); `query.ts` memuat `buildQuery()`, `dimension()`, `RELATIVE_RANGES` dan
  `TIME_GRANULARITIES` dengan nama tampil bahasa sehari-hari, pembaca kolom hasil, dan
  `groupRowsByImplicit()` (pengelompokan per mata uang yang kini ditulis tangan di `explore.tsx`, yang
  tidak diubah karena area 8 menggantinya).
- [x] 2.7 Test unit tanpa database: bentuk sah dan tidak sah, normalisasi, token di
  `Asia/Jakarta`, `Asia/Makassar`, `Asia/Jayapura` pada 31 Desember 23.30 dan 29 Februari 2028.
  *Dikirim:* `tests/Unit/Platform/Analytics/` — `RelativeRangeTest`, `QueryParserTest`,
  `QueryNormalizerTest`, `QueryValidatorTest`, `QueryShapeSyncTest` — ditambah test endpoint di
  `WalkingSkeletonTest` (rentang waktu mengikuti zona pengguna, urutan dan top-N, hash sama untuk query
  setara, galat berpath). Cara setiap penjaga dibuat merah dicatat di pull request area 2.

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
  field `EndUserPseudonymousIdentifiers` hanya bagi yang berhak. Untuk query, ia mengimplementasikan
  `Query\FieldUseGate` yang sudah dipanggil `QueryValidator` (area 2.4), mengikatnya di container, dan
  menjadikan parameter validator tidak lagi opsional. Test yang dibutuhkan: query yang memakai kolom
  tertutup sebagai pengelompok, saringan, dan rentang waktu ditolak 403 `analytics.field_personal_data`
  berpath; `AnalyticsQueryException` belum punya pabrik untuk kode itu, jadi tambahkan.
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

### 5. [~] Dataset module aset

**Tempat:** `modules/apperp/management-aset/src/Analytics/*`, satu blok di `ModuleServiceProvider::boot()`,
`modules/apperp/management-aset/tests/Feature/Analytics/*` · **Setelah:** 0, 1 · **Keputusan:** KA-15,
KA-22 · **Skill:** `coreerp-analytics` · **Selesai bila:** setiap dataset di bawah lolos
`AnalyticsDatasetsBoundaryTest`, dan setiap dataset berkebijakan punya test paritas yang pernah dilihat
merah.

Untuk setiap dataset, **baca dulu controller daftar resource-nya**: permission yang dicek dan cara
`OrganizationScope` dipanggil (`asetQuery`, `query` dengan kolom tertentu, atau `legalEntityQuery`)
menentukan `permission()` dan `dataPolicy()`. Jangan menebak dari nama kolom. Nama kelas, tabel, dan
kolom di bawah adalah arah; yang dipakai adalah yang ada di module.

Dikerjakan 4 Oktober 2026, sebelum compiler area 3 digabung. Empat belas dataset sudah terdaftar, valid,
dan diuji; yang menunggu area 3 hanya **measure bersaringan** (saringan tetap `where` belum dikompilasi,
dan measure yang tidak dapat dijalankan tidak ditawarkan) dan **pengelompokan waktu berzona**, jadi area
ini tetap `[~]`. Yang dikirim berbeda dari rencana di beberapa butir; bedanya dicatat di butir masing-masing
(*Dikirim:*).

- [~] 5.1 `asset-register` — register aset (lengkap dari area 0). *Dikirim:* seluruh field katalog K-30
  kecuali keterangan, nomor seri, dan nomor model; rujukan berlabel ke enam master (group, jenis, kondisi,
  lokasi, pabrikan, model); dimensi bersama entitas legal, unit penanggung jawab, dan unit dimensi keuangan;
  measure rata-rata nilai perolehan; rute record. `currency_code` tetap field teks, bukan dimensi bersama
  mata uang seperti di sketsa: labelnya kode itu sendiri sampai master mata uang ada (FIN-20), dan mengganti
  tipe field yang sudah dipakai layar tidak mendatangkan apa pun. *Menunggu area 3:* measure `disposed`.
- [x] 5.2 `asset-receipts` — penerimaan aset. *Dikirim:* satu baris per **baris** dokumen penerimaan,
  bersumber query: header penerimaan tidak punya total, dan baris menyimpan `jumlah` serta `nilai_per_unit`,
  jadi nilai penerimaan (`jumlah × nilai_per_unit`, belum termasuk PPN) hanya dapat dihitung di query sumber.
  Vendor ikut sebagai dimensi bersama. Kebijakan pada unit penanggung jawab **header**.
- [x] 5.3 `depreciation-entries` dan `book-values`. *Dikirim:* keduanya bersumber query, karena periode dan
  buku tidak punya kolom mata uang (mata uangnya dari aset), dan buku tidak punya kolom unit sama sekali.
  `depreciation-entries` mengikuti `usage_org_unit_id` milik **periode** (unit pengguna), bukan unit
  penanggung jawab asetnya, persis seperti `GET penyusutan`; pembalikan menyimpan jumlah negatif, jadi
  jumlahnya bersih dengan sendirinya. `book-values` **bukan** `ROW_NUMBER()` "nilai buku terakhir": tabel
  buku aset sudah menyimpan saldo terakhirnya (dipelihara finalisasi, pembalikan, dan penyesuaian nilai),
  jadi yang diperlukan hanya menggabungkannya dengan aset untuk unit dan mata uang. Buku ditutup ikut
  terbawa; daftar di layar hanya menawarkan buku aktif, dan statusnya dapat disaring.
- [x] 5.4 `work-orders`, `maintenance-requests`, `downtime`. *Dikirim:* work order dan permintaan
  pemeliharaan ternyata **tidak** perlu join untuk kebijakan: keduanya membawa `legal_entity_id` dan
  `responsible_org_unit_id` sendiri (permintaan atas lokasi bahkan tidak punya aset). Hanya downtime yang
  mengikuti aset, dan ia bersumber query dengan join dalam ke asetnya, supaya tidak menunggu `JoinPlanner`
  area 3. Lama downtime dihitung hanya untuk catatan yang sudah ditutup (nilai yang bergantung pada jam
  pembacaan tidak cocok untuk cache); catatan terbuka dihitung lewat field "Masih berhenti". Jam kerja ada
  di **baris pekerjaan** work order, bukan di header, jadi dataset ini belum menjumlah jam; dataset baris
  pekerjaan belum dibuat.
- [x] 5.5 `disposals` menjadi `asset-sales` dan `asset-scraps`; `value-adjustments`; `reclassifications`.
  *Dikirim:* penjualan dan pemusnahan satu tabel tetapi layar daftarnya dijaga permission berbeda
  (`penjualan-aset.read`, `pemusnahan-aset.read`), dan satu dataset hanya punya satu permission — dataset
  gabungan akan memperlihatkan pemusnahan kepada pengguna yang hanya boleh membaca penjualan. Laba atau rugi
  pelepasan **tidak tersimpan** di tabel mana pun (dihitung saat pratinjau dan posting, lalu hanya ikut ke
  jurnal), jadi measure-nya hanya hasil penjualan dan nilai perolehan aset yang dilepas. Penyesuaian nilai
  dan reklasifikasi memegang nilainya di baris dan kebijakannya di header, jadi keduanya bersumber query;
  `net_effect` memberi tanda nilai penyesuaian (kenaikan positif, penurunan negatif).
- [x] 5.6 `insurance-policies`, `warranties`. *Dikirim:* polis memakai mode legal entity saja
  (`legalEntityQuery`, tanpa kolom unit); garansi mengikuti unit asetnya dan bersumber query. Polis **belum
  punya measure uang**: tabel polis tidak menyimpan mata uang, dan uang tanpa mata uang tidak boleh dijumlah
  (KA-22). Premi tahunan dan nilai pertanggungan menunggu kolom mata uang pada polis; kontrak servis dan
  pertanggungan per aset belum dijadikan dataset.
- [x] 5.7 `physical-checks` — pemeriksaan fisik aset (monitoring aset). *Dikirim:* satu baris per aset pada
  satu pemeriksaan, bersumber query; kebijakan pada header, yang unitnya boleh kosong — pemeriksaan tanpa
  unit hanya terlihat bagi yang menjangkau seluruh organisasi, sama dengan layar daftarnya.
- [~] 5.8 Test per dataset: isolasi tenant, paritas kebijakan, uang per mata uang, measure bersaringan,
  waktu berzona ([daftar](/todo/analitik/model-semantik#test-yang-wajib-menyertai-setiap-dataset)).
  *Dikirim:* isolasi tenant, paritas kebijakan terhadap endpoint daftar module (hibah unit A, unit B,
  seluruh organisasi, dan tanpa hibah), penolakan tanpa permission baca, dan uang per mata uang, di
  `tests/Feature/Analytics/<Nama>DatasetTest.php`. Pemeriksaan umumnya ada di trait `ProbesAssetDatasets`
  dan `ChecksMoneyPerCurrency` (dunia ujinya dua tenant sungguhan, rantai izin sungguhan, empat pengguna
  yang dibuat sekali per test); setiap test dataset mengisi data awal dan jumlah baris yang diharapkan.
  Setiap test paritas dilihat merah dengan merusak kolom kebijakannya. *Menunggu area 3:* measure
  bersaringan dan pengelompokan waktu berzona.

Query sumber dataset-dataset ini disusun dari `SourceQuery::from(Model::class)`, bukan `Model::query()`:
kontrak `fromQuery()` meminta `Builder<Model>`, dan analisa tipe menolak `Builder<ModelKonkret>` karena
parameter template `Builder` tidak kovarian. Hasilnya sama (`newQuery()` memasang scope tenant dan penanda
arsip), tanpa menekan analisa tipe.

**Peta dataset.** Semuanya memakai kebijakan `management-aset.asset-responsibility`.

| Kode | Sumber | Permission | Kolom kebijakan |
| --- | --- | --- | --- |
| `asset-register` | model aset | `aset.read` | `legal_entity_id`, `responsible_org_unit_id` |
| `asset-receipts` | baris penerimaan + header | `penerimaan-aset.read` | header: `legal_entity_id`, `responsible_org_unit_id` |
| `depreciation-entries` | periode + buku + aset | `penyusutan.read` | periode: `legal_entity_id`, `usage_org_unit_id` |
| `book-values` | buku + aset | `penyusutan.read` | aset: `legal_entity_id`, `responsible_org_unit_id` |
| `work-orders` | model work order | `pemeliharaan-aset.read` | `legal_entity_id`, `responsible_org_unit_id` |
| `maintenance-requests` | model permintaan | `permintaan-pemeliharaan.read` | `legal_entity_id`, `responsible_org_unit_id` |
| `downtime` | downtime + aset | `downtime-aset.read` | aset: `legal_entity_id`, `responsible_org_unit_id` |
| `asset-sales` | dokumen siklus (penjualan) + aset | `penjualan-aset.read` | dokumen: `legal_entity_id`, `responsible_org_unit_id` |
| `asset-scraps` | dokumen siklus (pemusnahan) + aset | `pemusnahan-aset.read` | dokumen: `legal_entity_id`, `responsible_org_unit_id` |
| `value-adjustments` | baris + header + aset | `penyesuaian-nilai-aset.read` | header: `legal_entity_id`, `responsible_org_unit_id` |
| `reclassifications` | baris + header + aset | `reklasifikasi-aset.read` | header: `legal_entity_id`, `responsible_org_unit_id` |
| `insurance-policies` | model polis | `polis-asuransi.read` | `legal_entity_id` saja |
| `warranties` | garansi + aset | `garansi-aset.read` | aset: `legal_entity_id`, `responsible_org_unit_id` |
| `physical-checks` | baris + header + aset | `monitoring-aset.read` | header: `legal_entity_id`, `responsible_org_unit_id` (boleh kosong) |

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
