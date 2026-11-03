# Peta autentikasi dan baca untuk pembandingan native

Tujuannya membandingkan Bun Hono, Bun Elysia, Go, Rust, dan PHP CoreERP dengan perilaku bisnis serta rantai query yang sama. Kontrak tetap HTTP/JSON yang ada. Kandidat membuka sesi dan menjalankan operasi sendiri, tanpa memanggil PHP untuk autentikasi atau operasi.

Sumber dipaku pada `6b3cf5846fd4442c3e895cacf2ba58275f9dd75e`, branch `codex/native-stack-comparison`. Penelusuran ini hanya membaca source. Tidak ada akses server, database, atau eksekusi aplikasi. Tidak ada angka query aktual atau hasil performa yang diukur di sini.

Dependency tidak terpasang pada worktree ini. Untuk membaca implementasi framework, salinan vendor `D:/Kerja/CoreERP/apps/core/vendor` dipakai setelah versi terpasangnya dicocokkan dengan composer.lock worktree: Laravel framework `v13.35.0`, Fortify `v1.37.2`. Referensi `vendor/...` di bawah menunjuk salinan tersebut. Belum ada pemeriksaan hash setiap berkas dependency.

## Gambaran alur

| Tahap | Perilaku yang harus dipertahankan | Lokasi source |
| --- | --- | --- |
| Registrasi route module | JSON module memakai `web`, `auth`, prefix `/api/modules/management-aset`, kemudian `/v1` dan `konteks-module:management-aset` | `modules/apperp/management-aset/src/ModuleServiceProvider.php:196-210`; `routes/api.php:12-23` pada module |
| Middleware global | Trace context, alamat environment, dan asal passkey berjalan sebelum grup web | `apps/core/bootstrap/app.php:43-117` |
| Grup web framework | Decrypt cookies, queued cookies, StartSession, share errors, PreventRequestForgery, bindings | `vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:482-493` |
| Web tambahan | Appearance, pencabutan sesi provider, keanggotaan tenant pada alamat, Inertia, link headers, wajib ganti password, lisensi | `apps/core/bootstrap/app.php:95-120` |
| JSON biasa | `api/*` tanpa `X-Inertia` tidak menyusun props Shell, katalog produk, atau menu | `apps/core/app/Http/Middleware/HandleInertiaRequests.php:45-50` |
| Module context | Membership aktif, lisensi app, permission module, perusahaan dan unit aktif, TenantScope, data policy | `apps/core/app/Platform/Modules/Http/Middleware/ResolveModuleContext.php:64-159` |
| Guard resource | Permission baca spesifik wajib, meskipun ada permission lain pada module | `PenerimaanAsetController.php:1391-1403`; `AsetController.php:469-471` |

Path controller lengkap ada pada bagian query bisnis di bawah. Jangan memasukkan biaya props Inertia ke kandidat bila request PHP memakai JSON biasa. Jangan menghapus middleware sesi karena request tersebut JSON.

## Fixture yang memakai jalur tepercaya

Untuk database eksperimen terpisah, siapkan schema Core, katalog module, migration module, dan seed acuan yang memang dibutuhkan. `apps/core/loadtest/prepare.sh:16-22` mencatat urutan migration, seed, `app:register-manifest`, lalu `module:migrate`. Script itu memakai `migrate:fresh`, jadi bukan perintah untuk database yang sudah berjalan. Penelusuran ini tidak menjalankannya.

Pembuatan akun dan tenant yang sesuai source saat ini dapat memakai action internal berikut pada tahap persiapan fixture PHP, sebelum kandidat berjalan:

```php
$creation = app(\App\Platform\Tenant\Actions\RegisterBusiness::class)->forOperator([
    'name' => $fixtureName,
    'email' => $fixtureEmail,
    'password' => $fixturePassword,
    'business_name' => $fixtureBusiness,
    'app_ids' => ['management-aset'],
    'tenant_id' => $fixtureTenantId,
    'must_change_password' => false,
    'first_environment' => 'production',
    'first_environment_hosting' => 'provider',
], null);
```

- `RegisterBusiness.php:28-58` memilih `TenantCreator::system` saat operator null. Action membuat principal, memanggil tenant registrar, lalu `BootstrapErpTenant`. Ini tidak melewati endpoint pendaftaran tamu.
- Hasil `apps/core/app/Platform/Tenant/Support/TenantCreation.php:11-18` menyediakan `principal`, `tenant`, dan `membership`.
- `BootstrapErpTenant.php:59-78` membuat production/provider berstatus active dengan `database_name=null`, beserta environment member. Pada SHA ini, jalur tersebut memakai database bersama.
- `BootstrapErpTenant.php:81-113` membuat entitlement, role Owner, duty Owner, assignment aktif, dan scope setiap policy dengan legal entity serta organisasi null, yaitu akses seluruh organisasi.
- `BootstrapErpTenant.php:116-139` memasang module dan mengirim TenantProvisioned sesudah commit. Biarkan transaksi terluar benar-benar commit. `first_environment=none` melewati pemasangan sesudah commit.
- Nama fixture dan ULID datang dari generator fixture, bukan nama perusahaan atau orang yang ditulis mati dalam aplikasi.

Setelah akun ada, setiap runtime membuka sesinya sendiri melalui HTTP:

1. `GET /login` dengan cookie jar baru. Ambil `XSRF-TOKEN` yang sudah URL-decoded untuk header `X-XSRF-TOKEN`.
2. `POST /login` dengan JSON email/password fixture dan header `Accept: application/json`, `Content-Type: application/json`, `X-Requested-With: XMLHttpRequest`, serta `X-XSRF-TOKEN`. Jawaban sukses fixture tanpa 2FA adalah 200 `{"two_factor":false}`. Simpan cookie baru setelah login karena id sesi dan token berubah.
3. Untuk penyiapan organisasi melalui PHP sungguhan, owner dapat `POST /api/v1/organizations`. Legal entity memakai classification, name, company_code, country_code. Operating unit memakai classification, name, operating_unit_type, operating_unit_number. Bentuk payload ada di `apps/core/loadtest/k6/lib.js:186-213`. Tipe operating unit tetap berasal dari data acuan yang dipasang.
4. `PUT /api/v1/workspace-context` dengan `membership_id`, `legal_entity_id`, dan `org_unit_id`, memakai cookie dan token sesi terbaru. Jawabannya memulangkan empat id pilihan tersebut. Route ada di `apps/core/routes/web.php:320-321`.
5. Seed data bisnis sintetis sekali, lalu semua runtime memakai kumpulan baris dan expected response yang sama. Jangan menyalin cookie PHP untuk membuat kandidat seolah-olah sudah mendukung login.

Helper loadtest lama `apps/core/loadtest/k6/lib.js:125-230` berguna sebagai contoh HTTP cookie/CSRF. Pembuatan tenantnya masih melalui `/api/v1/business-registrations`; jangan menyalin jalur tamu itu untuk fixture baru. Helper test module `tests/Concerns/BerinteraksiDenganKonteksCore.php:546-738` membangun rantai akses sungguhan tetapi memakai `actingAs` pada baris 600. Helper tersebut bukan bukti login HTTP atau CSRF.

## Autentikasi, sesi, dan query konteks

| Bagian | Implementasi dan konsekuensi |
| --- | --- |
| Konfigurasi sesi | `apps/core/config/session.php:21,35,50,76,89,104,117,131-202,231`: default database, 120 menit, encryption default false untuk database dan true untuk Redis, JSON serialization eksplisit, lottery GC 2/100, cookie HttpOnly dan SameSite lax. Nilai env runtime belum dibaca. Jangan memakai parser PHP serialize lama. |
| Crypto cookie | `apps/core/config/app.php:103-108`: AES-256-CBC dan previous keys. `vendor/.../Cookie/CookieValuePrefix.php:14-16,38-45`: prefix HMAC SHA1 atas nama cookie ditambah `v2`, lalu `|`, harus divalidasi sebelum mengambil id sesi. Encrypter Laravel memverifikasi MAC envelope sebelum decrypt. |
| Guard key | `vendor/.../Auth/SessionGuard.php:892-895`: `login_web_` ditambah SHA1 nama class SessionGuard. Guard membaca id dari session, membaca user melalui provider, lalu Authenticated event, baris 171-206. |
| User yang boleh login | `apps/core/app/Providers/AppServiceProvider.php:160-164`: provider `people` menyaring `account_type=person`. `User.php:51` memakai OwnedByControlPlane. Akun application tidak boleh dipulihkan melalui sesi. |
| Login | `vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:71-91`: canonicalize username, two-factor check, AttemptToAuthenticate, PrepareAuthenticatedSession. Two-factor feature aktif pada `apps/core/config/fortify.php:170-190`. |
| Verifikasi password ganda | `vendor/laravel/fortify/src/Actions/RedirectIfTwoFactorAuthenticatable.php:50-102` mengambil user dan memeriksa hash terlebih dahulu. Fixture tanpa 2FA kemudian masuk `AttemptToAuthenticate.php:49-62` dan guard attempt memeriksa credentials lagi. Pertahankan kerja ini bila mengukur login yang sama. Jangan menurunkan biaya bcrypt khusus kandidat. |
| Efek login | `AppServiceProvider.php:166-168` memperbarui last_login_at dan memberi stamp generasi bila driver Redis. `vendor/.../Auth/SessionGuard.php:571-608` menulis guard id, password_hash_web, meregenerasi sesi, lalu event Login dan Authenticated. `PrepareAuthenticatedSession.php:34-40` meregenerasi sesi lagi dan membersihkan limiter. |
| Limiter | `apps/core/app/Providers/FortifyServiceProvider.php:119-123`: 5/min per email yang dilowercase dan ditransliterasi bersama IP. Penyimpanan cache limiter ikut biaya login. Login untuk setiap VU pada waktu yang sama akan mengenai limiter. |
| Penyimpanan sesi database | `vendor/.../Session/DatabaseSessionHandler.php:115-143,151-205,228-255`: baca session, cek last_activity, base64 decode payload; tulis base64 JSON, last_activity, user_id, IP, dan user agent. StartSession tetap menyimpan sesi pada GET. Lottery dapat menambah DELETE sesi kedaluwarsa. Jangan mengubah lottery hanya pada PHP. |
| Audit pada baca | `AppServiceProvider.php:182-183` memasang AuditActor saat Authenticated dan membersihkannya saat Logout. `apps/core/app/Platform/ChangeLog/Support/AuditActor.php:25-35` menjalankan `select set_config(?, ?, false)` pada koneksi bisnis aktif. Ini query nyata pada setiap pemulihan user, walaupun endpoint hanya membaca. |
| Alamat environment | `apps/core/app/Platform/Environment/Http/Middleware/ResolveEnvironment.php:74-136`: fromHost, query environment dengan tenant slug/kind, 404 untuk tidak ada, 503 untuk lingkungan yang pernah hidup tetapi sedang tidak aktif, lalu bind ActiveEnvironment dan pilih koneksi. Tanpa base domain/alamat environment, bagian ini dilewati. |
| Pusat dan bisnis | `EnvironmentConnection.php:81-117,199-215,269-292` mengarahkan database bisnis dan memaku central connection, sesi database, cache, queue, serta reset token. `OwnedByControlPlane.php:39-50` membuat user/tenant model tetap membaca pusat. Jangan menyamakan semua query dengan satu database bila menguji dedicated environment. |
| Keanggotaan alamat | `RequireTenantMembershipAtAddress.php:54-69` menolak anggota tenant lain. Provider access menambah exists query pada alamat tenant. Vendor boleh melewati guard alamat tetapi tetap tidak mendapat workspace tenant asing. |
| Pilihan tenant | `CurrentWorkspace.php:53-111` membaca memberships aktif milik user dengan tenant eager-load, membatasi tenant alamat, memilih id dari sesi bila sah atau membership pertama menurut created_at. Pilihan sesi tidak memberikan hak. |
| Pilihan organisasi | `CurrentWorkspace.php:115-174,218-229` mengambil organisasi aktif yang dijangkau policy, urut nama, memilih perusahaan/unit sah atau yang pertama, menulis pilihan ke sesi. Pindah tenant/perusahaan menghapus work date. Membership dan organisasi diingat hanya dalam satu request. |
| Penggantian workspace | `WorkspaceContextController.php:17-50` mencari membership aktif milik user, memvalidasi perusahaan/unit terhadap organisasi yang diizinkan, lalu activate. `WorkspaceContextRequest.php:9-20` menuntut user dan membership_id string. Id body yang tidak sah menghasilkan 422. |
| Rantai permission | `LaunchableAppCatalog.php:16-22,151-173`: assignments aktif dan role aktif pada rentang waktu, recursive effective roles, lalu distinct permissions lewat role_duties, duty_privileges, privilege_permissions, permissions, disaring app_id dan diurut kode. |
| Hierarki role | `RoleHierarchy.php:26-54` memakai recursive CTE dengan tenant pada akar dan link. Parent mewarisi child, inactive child tidak memberikan izin dan tidak menjembatani keturunannya. |
| Policy | `DataPolicyAccessResolver.php:26-103` cache per membership selama request; query scope dan assignment dengan valid_from/until, lalu per policy all atau pasangan legal entity/unit, ditambah closure hierarchy bila include_descendants. Pada SHA ini query scope tidak join roles; jangan mengklaim sudah menjalankan perbaikan active-role policy dari snapshot lain. |
| Company dan unit | `OrganizationScope.php:58-62` menerapkan CompanyContextFilter lalu DataPolicyFilter. `CompanyContextFilter.php:19-23` tanpa perusahaan menghasilkan `1=0`. `DataPolicyFilter.php:33-71` menggunakan all, atau OR pasangan legal entity+unit; tanpa grant menghasilkan `1=0`. Unit aktif di workspace bukan filter tambahan atas seluruh unit yang diizinkan. |
| Batas tenant/arsip | `TenantScope.php:32-48` menuntut tenant aktif dan menyisipkan filter tenant. Model Aset, PenerimaanAset, detail, dan cancellation menambahkan scope tenant dan aturan soft delete masing-masing. Join lookup controller selalu mencocokkan tenant kedua tabel. |

Peringatan pembacaan versi: `EffectivePermissions.php` tidak ada pada SHA ini. `LaunchableAppCatalog` menjalankan rantai query langsung. Jangan memindahkan rancangan cache permission dari memory atau branch lain ke pembanding ini.

`ResolveModuleContext` memeriksa izin module dan lisensi app. Pemeriksaan installed/entitled milik launcher ada pada `LaunchableAppCatalog.php:116-147,185-221`, bukan otomatis tambahan pada setiap JSON request. Pertahankan apa yang benar-benar dijalankan PHP.

CSRF Laravel 13 memakai `vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php:95-195`. HEAD/GET/OPTIONS lolos. `Sec-Fetch-Site: same-origin` juga lolos. Selain itu middleware membandingkan token sesi secara constant-time dengan `_token`, `X-CSRF-TOKEN`, atau hasil decrypt header `X-XSRF-TOKEN`. Hanya backchannel logout yang dikecualikan aplikasi (`bootstrap/app.php:63-66`). Negative test token hilang harus menghilangkan header same-origin. Test feature Laravel biasa melewati CSRF, sehingga wajib ada probe HTTP nyata.

Pencabutan semua sesi berbasis generasi hanya aktif untuk Redis: `SessionRevocation.php:17-50`. Middleware `EndSessionAfterProviderLogout.php:15-26` membaca generasi, logout, invalidate, regenerate token, lalu 401 JSON bila berubah. Pada sesi database, jalur generasi sengaja tidak membuka Valkey. Uji sesi database yang dihapus, sesi kedaluwarsa, serta membership/role yang dicabut tetap diperlukan.

## Query bisnis dan bentuk jawaban

Controller berada di `modules/apperp/management-aset/src/Http/Controllers/transaksi/`.

| HTTP | Query dan jawaban yang wajib sama |
| --- | --- |
| `GET .../penerimaan-aset` | `PenerimaanAset/PenerimaanAsetController.php:97-130`: guard read; status/dari/sampai validation; tenant+soft delete+company+policy; tanggal/status filter; location left join; dua correlated subquery detail untuk count dan sum jumlah; tiga scalar subquery nama organisasi dan dua scalar subquery anggota/user dari OrganizationDirectory; shared IncrementalList. Jawaban `data,next_cursor`, tanpa total. |
| Lookup receipt list | `PenerimaanAsetController.php:1294-1324` mengambil seluruh header plus lokasi_aset_kode/nama. `apps/core/app/Platform/Organization/ModuleServices/OrganizationDirectoryCore.php:68-78` menambahkan scalar subquery nama, bukan request terpisah. Search receipt memakai kode, legal_entity_nama, keterangan, lokasi_aset_nama, responsible_org_unit_nama, status. Sort default tanggal desc. Numeric sort jumlah_baris/jumlah_aset. |
| `GET .../penerimaan-aset/{id}` | `PenerimaanAsetController.php:133-148,1261-1285`: guard, query header berlookup dan scope, lalu nama responsible/receiving unit dan diterima_oleh/penanggung_jawab. `AssetOrganizationDirectory.php:71-103` memuat seluruh unit dan seluruh anggota tenant masing-masing sekali bila id terkait terisi. Kedua unit/orang memakai cache per request. |
| Detail receipt | `PenerimaanAsetController.php:1332-1380`: satu query semua lines, urut line_number, join request line+purchase request+group+jenis+kondisi+pabrikan+model dengan pasangan tenant. Parse saldo_awal_buku dan atribut JSON. `show` menambah vendor bila ada, posting bila selesai/cancelled, dan selalu cancellation summary. `ETag` berasal dari version. |
| Vendor/posting/cancellation | `apps/core/app/Foundation/Vendor/ModuleServices/VendorDirectoryCore.php:71-75` memakai vendor query dan eager-load party. `PostingFeedCore.php:29-32` memanggil `PostingPublisher.php:128-145`. `AssetCancellationEngine.php:59-68` selalu mencari cancellation terbaru; cancellation dengan posting_ids menambah status lookup per id. Fixture draft tanpa vendor/cancellation lebih ringan, jadi sertakan fixture posted dengan vendor dan cancellation untuk cakupan itu. |
| `GET .../aset` | `InventarisasiAset/AsetController.php:41-86`: guard aset.read; lifecycle_state/exclude_state; tenant+soft delete+company+policy; global scope actual dibuang, jadi budgeted assets ikut; subquery asset.* dengan left joins group/type/location, scalar nama legal entity; shared IncrementalList; explicit present fields dan empat nama. Default created_at desc, numeric acquisition_value. Search kode,nama,serial_number. |
| Planning asset lookup | `AsetController.php:48-56` bila planning_company_id ada, tambahan permission planning create/update, ULID validation, PlanningOrganizationScope, kemudian DataPolicyFilter. Ini pengecualian perusahaan eksplisit yang tidak boleh disamakan dengan lookup biasa. |
| `GET .../aset/{id}` | `AsetController.php:89-97`: guard, scoped asset query dengan actual scope dibuang, explicit present fields, satu query atribut bertipe dan definisinya, ETag. `attributesOf:488-514` mengembalikan number sebagai float, boolean sebagai bool, tanggal yyyy-mm-dd, atau nilai_text. |
| Present asset | `AsetController.php:572-574`: hanya 24 field explicit. Model `src/Models/transaksi/InventarisasiAset/Aset.php` memakai acquisition_value decimal:2 string, dates Carbon serialization, is_budgeted bool. Jangan membocorkan tenant_id/creation_key/created_at hanya karena SQL mengambil asset.*. |

`apps/core/app/Platform/Modules/Contracts/IncrementalList.php:25-103` adalah kontrak pagination yang dipakai kedua list. Validasi q<=200, sort allowlist, direction asc/desc, cursor<=2000, per_page 1..100 default 40, record_id, record_ids maksimal 100. Filter id, search, dan scope diterapkan sebelum limit. Helper menambah list_sort_null dan nilai sort text/coalesce, lalu id sebagai tie breaker. Null di akhir, urutan deterministik, ambil N+1 untuk next_cursor. Cursor Laravel adalah base64url JSON dengan nilai sort, id bila perlu, dan _pointsToNextItems. Helper menghapus field sort tambahan dari respons. Kedua controller ini tidak memberikan nativeSortColumns, jadi cabang cursorPaginate legacy dipakai.

`RecordSearch.php:21-46,54-72,91-149,157-200` memakai Unicode NFC, lowercase, token kata/angka, AND antar-term dengan OR antar-kolom, prefix `to_tsquery('simple')`, dan frase berpetik memakai `phraseto_tsquery`. Query tanda baca saja menghasilkan false. Kedua list tidak mengirim identifierSearchColumns, jadi jangan menambahkan compact identifier matching yang tidak diminta controller. Mengganti aturan ini dengan ILIKE/substring tidak setara.

## Pembuktian, instrumentation, dan batas yang tersisa

Sumber test yang paling berguna:

- `apps/core/tests/Feature/Platform/Identity/AuthenticationTest.php:33-117`: login, password salah, 2FA, logout, dan limiter.
- `apps/core/tests/Feature/Platform/Identity/SessionRevocationTest.php:17-63`: generasi lintas host, isolasi user, login baru, cache clear, sesi database tanpa Valkey, kegagalan penulisan revoke.
- `apps/core/tests/Feature/Platform/Tenant/TenantAddressMembershipTest.php:57-178`: tenant alamat, membership dicabut, pilihan sesi asing, vendor tanpa workspace asing.
- `apps/core/tests/Feature/Platform/Environment/EnvironmentAddressTest.php:157-226,306-443`: host, active/inactive/deleted, central-store pins, Valkey, 404/503.
- `apps/core/tests/Feature/Platform/Access/RoleHierarchyTest.php:81-149,207-240`: parent/child/diamond, inactive bridge, module permissions, pelepasan inheritance segera.
- `apps/core/tests/Feature/Platform/Access/DataPolicyAccessResolverTest.php:18` dan `Platform/Modules/CompanyContextFilterTest.php:18-67`: union policy tanpa kebocoran dan perusahaan tidak menambah hak.
- `modules/apperp/management-aset/tests/Feature/ActiveCompanyContextTest.php:81-92`: list/show/update mengikuti perusahaan aktif dan tidak mempersempit semua unit menjadi unit aktif.
- `modules/apperp/management-aset/tests/Feature/RegisterAsetTest.php:89` dan `AssetRegisterListTest.php:34-56`: unit scope dan search.
- `apps/core/tests/Feature/Support/IncrementalListTest.php:23-141` dan `RecordSearchTest.php:40-79`: ties, null, numeric, cursor rusak, scope sebelum limit, search.
- `modules/apperp/management-aset/tests/Feature/PenerimaanAsetTest.php:60,465-473`: receipt standalone dan ETag/version.

Gate HTTP tambahan untuk lima runtime: tidak login 401, cookie/MAC rusak 401, session expired/deleted 401, akun application tidak dapat login, password salah 422, limiter 429, CSRF salah/hilang 419 tanpa same-origin header, membership nonaktif 403, assignment di luar tanggal 403, role inactive 403, duty/privilege/permission dicabut berlaku pada request berikutnya dengan cookie yang sama, scope perusahaan/unit/tenant asing 404 atau list kosong, tenant_id bebas di header/body tidak menambah akses, dan logout membuat cookie lama tidak berguna. Uji scope spesifik dan all, hierarchy descendants, null/ties, batch kedua, numeric sort, frase, serta payload persis. Status penolakan harus mengikuti respons PHP aktual pada fixture yang sama.

Instrumentation sementara yang disarankan, belum dipasang:

1. Daftarkan satu listener `DB::listen(QueryExecuted)` saat boot, bukan pada setiap request worker Octane. Ambil collector dari request/container aktif pada setiap event.
2. Letakkan middleware collector paling luar sebelum ResolveEnvironment dan StartSession. Selesaikan collector sesudah seluruh middleware di dalamnya selesai, agar session read, user lookup, audit set_config, environment query, role/policy, business query, session write, serta lottery GC ikut.
3. Simpan urutan fingerprint template SQL, connection-role, jumlah query, dan durasi DB. Jangan simpan bindings, raw SQL yang sudah diinterpolasi, password, cookie, token, atau request body. Fingerprint menormalkan whitespace dan placeholder; hasil publik cukup hash serta label jenis query. Daftar template tanpa data pribadi dapat disimpan lokal untuk pemeriksaan kesetaraan.
4. Ambil jejak dari HTTP sungguhan dengan akun fixture yang baru login. Jangan menganggap `actingAs` setara. Contoh hook aman yang sudah ada: `apps/core/tests/Feature/Boundary/AnggaranQueryPermintaanModuleTest.php:43-70` menggunakan DB::listen dan forgetScopedInstances di antara request, tetapi anggaran test itu tidak menghitung semua biaya transport sesi HTTP.
5. Ulangi request dingin/panas dan cabang negatif. Pisahkan tangkapan instrumented dari run performa, atau pasang overhead yang sama pada semua runtime. Jangan menyimpulkan query count dari komentar lama.

Hal yang belum dibuktikan: env/config efektif stack eksperimen, hash SQL aktual beserta urutannya, session GC yang terjadi pada sampel, latensi atau resource, hash file vendor, byte persis date/decimal JSON dari runtime, respons exception framework pada fixture baru, dan keberhasilan bootstrap pada database eksperimen. Candidate yang berbagi pool harus membersihkan atau memasang ulang audit context pada koneksi yang benar agar identitas tidak tertinggal. Transaction pooling perlu dibuktikan terhadap set_config tingkat sesi.

`D:/Kerja/erp-bench` hanya dibaca sebagai pembanding historis. Rust lama `rust/src/main.rs:411-423` mengambil semua aset dengan created_at desc, tanpa IncrementalList, nama lookup, search, atau filter request list saat ini. `rust/src/laravel.rs` membaca format sesi PHP lama. Ia tidak dapat dipakai sebagai bukti kesetaraan untuk source saat ini. Hasil benchmark historis tidak dipakai sebagai pengukuran baru.

Metode memakai how, poteto-mode, unslop, dan technical-writing. Prinsip Prove It Works mengubah batas laporan: source dibaca langsung dan status runtime tetap dinyatakan belum diverifikasi.
