# Keamanan engine analitik

Bagian dari [engine analitik](/todo/analitik/). Analitik adalah jalur baca yang paling lebar di
CoreERP: satu query dapat merangkum seluruh tabel. Karena itu aturannya dirumuskan lebih dulu dan
diuji dengan keadaan yang **harus ditolak**, bukan hanya keadaan yang berhasil.

## Empat prinsip

1. **Aturan yang sama dengan layar module.** Angka di analitik tidak pernah boleh lebih luas daripada
   daftar yang dapat dibuka pengguna yang sama di layar module. Test paritas menjaganya.
2. **Gagal tertutup.** Tenant, hibah, saringan terkunci, atau klasifikasi yang hilang menghasilkan
   nol baris atau penolakan, tidak pernah "semua".
3. **Dasbor bersama dihitung sebagai yang melihat, bukan sebagai yang membuat.** Membagikan dasbor
   membagikan susunannya, bukan hak penyusunnya. Kepala unit A yang membuka dasbor buatan direktur
   melihat angka unit A saja.
4. **Ke luar hanya lewat publikasi.** Pemanggil luar tidak punya identitas di tenant; ia membaca
   publikasi yang dibuat pengguna tenant, dengan jangkauan pembuatnya yang dipersempit saringan
   terkunci (KA-11).

## Urutan otorisasi satu query

Mengikuti [urutan otorisasi query](/dev/08-query-scopes-and-schema#urutan-authorization-query):

| Langkah | Pemeriksaan | Bila gagal |
| --- | --- | --- |
| 1 | Konteks tenant tepercaya (sesi, klien integrasi, atau token embed); tidak pernah dari body atau query | 401 |
| 2 | Hak fitur: permission analitik yang sesuai (lihat KA-14) | 403 |
| 3 | Module dataset terpasang untuk tenant dan berlisensi (`LaunchableAppCatalog::for()`) | 404 `analytics.dataset_unknown` |
| 4 | Permission baca resource dataset (`LaunchableAppCatalog::permissionsFor()`) | 403 `analytics.dataset_forbidden` |
| 5 | Field data pribadi hanya bila principal berhak | 403 `analytics.field_personal_data` |
| 6 | Predikat tenant dari model, lalu kebijakan data dari hibah principal | Nol baris |
| 7 | Saringan pengguna, rentang waktu, saringan terkunci publikasi | Hanya menyempitkan |

Langkah 6 terjadi di compiler, bukan di controller, sehingga tidak ada jalur — layar, API, feed,
embed, job — yang dapat melewatinya.

## Principal

```php
<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use Carbon\CarbonImmutable;

/**
 * Pihak yang menjalankan query. Setiap jalur masuk membuat principal-nya sendiri; mesin query hanya
 * mengenal antarmuka ini, jadi tidak ada cabang "kalau dari API, lewati …".
 */
interface AnalyticsPrincipal
{
    public function tenantId(): string;

    /** Permission baca module yang dipegang, untuk dataset module itu. */
    public function holdsPermission(string $moduleId, string $permission): bool;

    /**
     * Hibah satu kebijakan data, bentuk `DataPolicyAccessResolver::resolve()`. Kebijakan tanpa hibah
     * memulangkan `['all' => false, 'scope_grants' => []]`, bukan null.
     *
     * @return array{all: bool, scope_grants: list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>}
     */
    public function policyScope(string $policyCode): array;

    public function mayUsePersonalData(): bool;

    /** Saringan tambahan yang tidak dapat dilepas principal ini (publikasi, embed). Kosong untuk pengguna. */
    public function lockedFilters(string $dataset): array;

    public function timezone(): string;

    public function now(): CarbonImmutable;

    public function rowLimit(): int;

    public function timeoutMs(): int;

    /** Sidik jari jangkauan untuk kunci cache; lihat ScopeFingerprint. */
    public function fingerprint(string $dataset): string;

    /** Untuk log: `membership:…`, `publication:…`, `embed:…`. */
    public function describe(): string;
}
```

Area 0 mengirim subset antarmuka ini — tanpa `mayUsePersonalData()`, `lockedFilters()`, dan
`fingerprint()`, yang ditambahkan area 4 — beserta `UserPrincipal::fromMembership()` dan
`DatasetAccess` tipis untuk langkah 3 dan 4 urutan otorisasi di atas.

| Principal | Dibuat dari | Permission dan hibah | Data pribadi |
| --- | --- | --- | --- |
| `UserPrincipal` | Keanggotaan sesi (`TenantMembership`) | Milik pengguna: `LaunchableAppCatalog::permissionsFor()` dan `DataPolicyAccessResolver::resolve()` | Bila memegang `core.analytics.personal-data.read` |
| `PublicationPrincipal` | Publikasi + klien integrasi yang memanggil | Milik **pembuat publikasi saat ini**, dipersempit saringan terkunci publikasi | Tidak pernah (KA-05) |
| `EmbedPrincipal` | Token embed | Seperti publikasi, dipersempit lagi oleh parameter yang dikunci saat token dicetak | Tidak pernah |

`PublicationPrincipal` memeriksa ulang pembuatnya pada **setiap** permintaan: keanggotaan aktif,
masih memegang hak publikasi, masih memegang permission dataset. Pembuat yang keluar atau dicabut
haknya membuat publikasinya menjawab 403 `analytics.publication_suspended` sampai admin memindahkan
kepemilikannya. Hak publikasi tidak boleh hidup lebih lama daripada pembuatnya.

## Kebijakan data

`DataPolicyScope` mengambil hibah principal untuk kode kebijakan dataset dan menerapkannya lewat
`DataPolicyFilter` pada kolom yang dinyatakan dataset ([model semantik](/todo/analitik/model-semantik#datapolicyfilter)).
Dataset yang resource-nya dilindungi kebijakan tetapi tidak menyatakan kolomnya **ditolak saat
didaftarkan**, bukan dijalankan tanpa saringan.

### Test paritas kebijakan data

Analitik dan layar module sekarang menyaring dengan dua jalur kode. Test ini yang membuktikan
keduanya memulangkan baris yang sama.

- **Di mana:** satu test per dataset berkebijakan, di folder test module
  (`tests/Feature/Analytics/<Dataset>PolicyParityTest.php`).
- **Susunan:** satu tenant, dua legal entity, tiga unit (A, B, C di bawah A), aset di setiap unit.
- **Pengguna:** hibah unit A tanpa turunan, unit A dengan turunan, unit B saja, semua, dan tanpa
  hibah sama sekali — rantai izin sungguhan untuk masing-masing.
- **Bukti:** untuk setiap pengguna, himpunan id dari endpoint daftar module (misalnya
  `GET /api/modules/management-aset/v1/aset`) **sama dengan** himpunan id dari drill analitik dataset
  yang sama tanpa saringan lain. Untuk fase 1 yang belum punya drill, pembandingnya `count` per unit.
- **Yang membuatnya merah:** mengganti kolom kebijakan dataset menjadi `financial_dimension_org_unit_id`
  harus menggagalkan test ini. Coba sekali sebelum mempercayainya.

## Data pribadi

Klasifikasi per kolom sudah ada di setiap tabel tenant (`DataClass`, `COLUMN_CLASSIFICATION`,
`DataClassificationBoundaryTest`). Gerbang data pribadi membacanya; ia tidak membuat klasifikasi
sendiri.

| Klasifikasi | Di layar tanpa hak data pribadi | Di layar dengan hak | Publikasi, feed, embed |
| --- | --- | --- | --- |
| `CustomerContent`, `SystemMetadata` | Boleh | Boleh | Boleh |
| `OrganizationIdentifiableInformation` (nama vendor, NPWP badan) | Boleh | Boleh | Boleh |
| `EndUserPseudonymousIdentifiers` (id pengguna, id pekerja) | Boleh sebagai pengelompok; label nama orang hanya dengan hak | Boleh | Hanya id, tanpa label nama |
| `EndUserIdentifiableInformation` (nama pasien, NIK, telepon, catatan medis) | **Tidak tampil di katalog**; query yang menyebutnya ditolak | Boleh | **Tidak pernah** |
| `AccountData` (hash token, secret) | Tidak pernah ada di dataset | Tidak pernah | Tidak pernah |

Tiga perilaku yang mudah terlewat:

- **Saringan juga dijaga, bukan hanya kolom tampil.** Menyaring `nama_pasien = 'Budi'` lalu membaca
  `count` sama dengan membaca datanya. Field yang tertutup tidak dapat dipakai sebagai dimensi,
  saringan, urutan, maupun kolom drill.
- **Widget bersama yang memakai field tertutup** tampil "Kolom ini memuat data pribadi" bagi pengguna
  tanpa hak, tanpa angka sama sekali.
- **Kelompok kecil pada publikasi.** Publikasi boleh menyembunyikan baris yang dihitung dari kurang
  dari *k* baris sumber (PQ-07), karena angka "1 pasien penyakit X di unit Y" dapat menunjuk orang.
  Penyembunyian dikerjakan pada hasil, dengan `count` baris sumber yang ditambahkan diam-diam ke query
  publikasi.

## Rantai izin yang diusulkan

**Menunggu persetujuan pemilik produk (KA-14).** Susunan di bawah mengikuti gate izin skill
`coreerp-architecture`: sumber daya dan tindakan dipisah, empat lapis tersimpan sebagai baris
sendiri, kode privilege berbeda dari kode permission, dan access level dinyatakan.

| Entry point | Jenis | Permission | Access | Arti |
| --- | --- | --- | --- | --- |
| `core.analytics.dashboard.form` | form | `core.analytics.dashboard.read` | read | Melihat dasbor milik sendiri dan dasbor bersama |
| | | `core.analytics.dashboard.create` | create | Membuat, mengubah, mengarsipkan dasbor dan widget pribadi |
| `core.analytics.explore.form` | form | `core.analytics.explore.invoke` | invoke | Menjalankan query bebas di penjelajah dan pembangun widget |
| `core.analytics.shared-dashboard.form` | form | `core.analytics.shared-dashboard.update` | update | Mengelola dasbor bersama dan dasbor beranda per peran |
| `core.analytics.personal-data.action` | action | `core.analytics.personal-data.read` | read | Memakai field data pribadi di analitik |
| `core.analytics.publication.form` | form | `core.analytics.publication.read` | read | Melihat publikasi dan embed |
| | | `core.analytics.publication.update` | update | Membuat, mengubah, menghentikan, mencabut publikasi dan embed |

| Privilege | Permission |
| --- | --- |
| `core.analytics.dashboard.view` | `dashboard.read` |
| `core.analytics.dashboard.author` | `dashboard.read`, `dashboard.create`, `explore.invoke` |
| `core.analytics.shared-dashboard.maintain` | `shared-dashboard.update` |
| `core.analytics.personal-data.view` | `personal-data.read` |
| `core.analytics.publication.maintain` | `publication.read`, `publication.update` |

| Duty | Nama di layar | Privilege |
| --- | --- | --- |
| `core.analytics.inquire` | Lihat dasbor | `dashboard.view` |
| `core.analytics.analyze` | Susun dasbor dan analisis data | `dashboard.author` |
| `core.analytics.manage` | Kelola dasbor bersama | `shared-dashboard.maintain` |
| `core.analytics.publish` | Kelola publikasi dan embed | `publication.maintain` |
| `core.analytics.personal-data` | Pakai data pribadi di analitik | `personal-data.view` |

Kenapa dipisah begitu:

- **Publikasi terpisah dari dasbor bersama.** Dasbor bersama tetap di dalam tenant dan dihitung
  sebagai yang melihat; publikasi membawa data keluar atas nama pembuatnya. Dua tingkat risiko yang
  berbeda butuh dua duty.
- **Data pribadi duty sendiri**, supaya pemberiannya selalu disengaja dan tampak di daftar duty role.
- **Penjelajah terpisah dari melihat.** Staf operasional cukup melihat dasbor beranda; query bebas
  adalah kemampuan analis.
- **Hak dataset tidak diduplikasi** (KA-15). Melihat angka aset menuntut `management-aset.aset.read`,
  permission yang sama dengan membuka daftar aset.

Katalognya ditulis sebagai migration Core, pola `2026_10_01_130000_register_report_preset_security_catalog`,
karena admin.erp ikut menjalankan migration Core tanpa kelas `App\`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Katalog keamanan engine analitik (KA-14). Rantainya di docs/todo/analitik/keamanan.md.
 * Tanpa kelas `App\` karena admin.erp ikut menjalankan migration Core. `up()` idempoten.
 */
return new class extends Migration
{
    private const ENTRY_POINTS = [
        'core.analytics.dashboard.form' => ['Dasbor', 'form'],
        'core.analytics.explore.form' => ['Analisis data', 'form'],
        'core.analytics.shared-dashboard.form' => ['Dasbor bersama', 'form'],
        'core.analytics.personal-data.action' => ['Data pribadi di analitik', 'action'],
        'core.analytics.publication.form' => ['Publikasi dan embed', 'form'],
    ];

    /** kode => [entry point, access, nama] */
    private const PERMISSIONS = [
        'core.analytics.dashboard.read' => ['core.analytics.dashboard.form', 'read', 'Lihat dasbor'],
        'core.analytics.dashboard.create' => ['core.analytics.dashboard.form', 'create', 'Buat dasbor pribadi'],
        'core.analytics.explore.invoke' => ['core.analytics.explore.form', 'invoke', 'Jalankan analisis data'],
        'core.analytics.shared-dashboard.update' => ['core.analytics.shared-dashboard.form', 'update', 'Kelola dasbor bersama'],
        'core.analytics.personal-data.read' => ['core.analytics.personal-data.action', 'read', 'Pakai data pribadi di analitik'],
        'core.analytics.publication.read' => ['core.analytics.publication.form', 'read', 'Lihat publikasi'],
        'core.analytics.publication.update' => ['core.analytics.publication.form', 'update', 'Kelola publikasi dan embed'],
    ];

    /** kode => [nama, permission] */
    private const PRIVILEGES = [
        'core.analytics.dashboard.view' => ['Melihat dasbor', ['core.analytics.dashboard.read']],
        'core.analytics.dashboard.author' => ['Menyusun dasbor dan analisis', ['core.analytics.dashboard.read', 'core.analytics.dashboard.create', 'core.analytics.explore.invoke']],
        'core.analytics.shared-dashboard.maintain' => ['Memelihara dasbor bersama', ['core.analytics.shared-dashboard.update']],
        'core.analytics.personal-data.view' => ['Memakai data pribadi di analitik', ['core.analytics.personal-data.read']],
        'core.analytics.publication.maintain' => ['Memelihara publikasi dan embed', ['core.analytics.publication.read', 'core.analytics.publication.update']],
    ];

    /** kode => [nama, privilege] */
    private const DUTIES = [
        'core.analytics.inquire' => ['Lihat dasbor', ['core.analytics.dashboard.view']],
        'core.analytics.analyze' => ['Susun dasbor dan analisis data', ['core.analytics.dashboard.author']],
        'core.analytics.manage' => ['Kelola dasbor bersama', ['core.analytics.shared-dashboard.maintain']],
        'core.analytics.publish' => ['Kelola publikasi dan embed', ['core.analytics.publication.maintain']],
        'core.analytics.personal-data' => ['Pakai data pribadi di analitik', ['core.analytics.personal-data.view']],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ENTRY_POINTS as $code => [$name, $type]) {
            DB::table('app_entry_points')->updateOrInsert(['code' => $code], [
                'app_id' => 'core', 'name' => $name, 'type' => $type, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (self::PERMISSIONS as $code => [$entryPoint, $access, $name]) {
            DB::table('permissions')->updateOrInsert(['code' => $code], [
                'app_id' => 'core', 'entry_point_code' => $entryPoint, 'access_level' => $access,
                'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (self::PRIVILEGES as $code => [$name, $permissions]) {
            DB::table('security_privileges')->updateOrInsert(['code' => $code], [
                'app_id' => 'core', 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('security_privilege_permissions')->where('privilege_code', $code)->delete();
            foreach ($permissions as $permission) {
                DB::table('security_privilege_permissions')->insert(['privilege_code' => $code, 'permission_code' => $permission]);
            }
        }
        foreach (self::DUTIES as $code => [$name, $privileges]) {
            DB::table('security_duties')->updateOrInsert(['code' => $code], [
                'app_id' => 'core', 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('security_duty_privileges')->where('duty_code', $code)->delete();
            foreach ($privileges as $privilege) {
                DB::table('security_duty_privileges')->insert(['duty_code' => $code, 'privilege_code' => $privilege]);
            }
        }

        // Owner memegang semua duty Core, termasuk data pribadi (PQ-04 menunggu konfirmasi pemilik).
        foreach (DB::table('roles')->where('is_owner', true)->pluck('id') as $roleId) {
            foreach (array_keys(self::DUTIES) as $duty) {
                DB::table('security_role_duties')->insertOrIgnore(['role_id' => $roleId, 'duty_code' => $duty]);
            }
        }
    }

    public function down(): void
    {
        // Urutan kebalikan dari up(); lihat migration katalog preset untuk bentuk lengkapnya.
    }
};
```

Kode yang dipakai kode aplikasi masuk `CoreSecurityCatalog` sebagai konstanta (`ANALYTICS_DASHBOARD_READ`
dan seterusnya), dan rute layar memakai `CoreSecurityCatalog::gate(...)`.

## Dasbor, widget, dan query tersimpan

Aturannya meniru preset laporan K-25 (`ReportOptions`), yang sudah teruji:

| Tindakan | Dasbor pribadi | Dasbor bersama |
| --- | --- | --- |
| Melihat | Pemiliknya | Setiap pemegang `dashboard.read` di tenant |
| Mengubah, mengarsipkan | Pemiliknya | Pemegang `shared-dashboard.update` |
| Menyalin menjadi pribadi | Pemiliknya | Pemegang `dashboard.create` |
| Menjadikan bersama | Pemiliknya, bila juga memegang `shared-dashboard.update` | — |

- Id dasbor dan widget di URL selalu dicek terhadap tenant dan aturan di atas; id milik tenant lain
  dijawab 404, bukan 403, supaya keberadaannya tidak bocor.
- Setiap perubahan memakai versi baris (`RowVersion::claim`, header `If-Match`), seperti preset.
- Widget dihitung sebagai **yang melihat** (prinsip 3). Penyusun dasbor bersama tidak dapat
  "meminjamkan" haknya lewat widget.

## Log query

Satu baris per query di `analytics_query_log`: tenant, principal (`describe()`), sumber (`widget`,
`explore`, `api`, `odata`, `embed`, `job`), dataset dan versinya, hash query, query normal, durasi,
jumlah baris, terpotong atau tidak, dari cache atau tidak, status, dan kode galat.

- **Nilai saringan pada field data pribadi disamarkan** sebelum dicatat (`[disamarkan]`), karena
  log dibaca operator yang tidak berhak atas data itu.
- Retensi lewat `RetentionPolicies` dengan kode `analytics_query_log`, bawaan 90 hari (PQ-05).
- Log bukan jejak audit perubahan. Perubahan dasbor dan publikasi tercatat log perubahan BC-style
  yang sudah ada (trigger `log_change`), karena tabelnya tabel tenant biasa.

## Ancaman dan penangkalnya

| Ancaman | Contoh | Penangkal | Test |
| --- | --- | --- | --- |
| Injeksi SQL lewat kunci field | `"dimensions": ["nama; drop table x"]` | Kunci dicocokkan dengan dataset sebelum compile; nama kolom dari definisi tervalidasi, dibungkus `wrap()` | Kunci aneh ditolak 422 |
| Injeksi lewat nilai saringan | `"nama": "' or 1=1 --"` | `FieldFilterExpression` hanya memakai binding | Nilai kutip diperlakukan sebagai teks |
| Injeksi lewat rumus | `[count]); drop table x; --` | Parser tertutup, angka sebagai binding | Parser menolak |
| Lintas tenant lewat id | Membuka widget tenant lain dengan id-nya | Model `analytics_*` disaring tenant; 404 | Test IDOR per endpoint |
| Lintas tenant lewat cache | Kunci cache tanpa tenant | Kunci memuat tenant dan sidik jari scope; tabel cache ada di DB tenant | Dua tenant, query sama, hasil berbeda |
| Eskalasi lewat dasbor bersama | Staf membuka dasbor direktur | Dihitung sebagai yang melihat | Test dua pengguna |
| Eskalasi lewat publikasi | Pembuat dicabut haknya, publikasi tetap jalan | Pemeriksaan ulang pembuat per permintaan | Test pencabutan |
| Saringan terkunci kosong | Daftar nilai `[]` melepas saringan (jebakan Metabase) | Saringan terkunci kosong = nol baris | Test khusus |
| Kebocoran data pribadi lewat saringan | Saring nama lalu baca jumlah | Gerbang menjaga saringan juga | Test |
| Beban berlebihan | Dasbor 50 widget berat | Batas widget, batas waktu, batas bersamaan, rate limit | Uji beban |
| CSRF pada API sesi | Situs lain memicu `POST` | Header `X-XSRF-TOKEN` seperti API Core lain | Tanpa token = 419 |
| XSS lewat judul atau label | Nama group berisi `<script>` | React meng-escape; tooltip grafik tidak memakai `dangerouslySetInnerHTML` | Snapshot teks |
| Clickjacking halaman layar | Layar dasbor dipasang di iframe situs lain | Halaman Shell tidak boleh di-frame; hanya rute embed yang longgar | Header diperiksa |
| Token embed bocor | Token di log atau `Referer` | Fragment, bukan query; TTL menit; terikat asal | Lihat [akses luar](/todo/analitik/akses-luar#embed) |

## Test penjaga keamanan

Wajib ada sebelum area yang bersangkutan dinyatakan `[x]`:

- `AnalyticsTenantIsolationTest` — setiap endpoint analitik, dua tenant, nol kebocoran (area 3, 6).
- `<Dataset>PolicyParityTest` — per dataset berkebijakan (area 5).
- `PersonalDataGateTest` — katalog, dimensi, saringan, urutan, drill, publikasi (area 4).
- `SharedDashboardRunsAsViewerTest` (area 6).
- `AnalyticsCacheIsolationTest` — tenant dan sidik jari scope (area 9).
- `PublicationSuspendedWhenOwnerLosesAccessTest`, `LockedFilterEmptyMeansNothingTest` (area 15).
- `EmbedTokenTest` — kedaluwarsa, dicabut, asal salah, dipakai untuk dasbor lain (area 17).
- `AnalyticsBoundaryTest` dan `AnalyticsDatasetsBoundaryTest` (area 1, lihat
  [arsitektur](/todo/analitik/arsitektur#penjaga-batas-yang-baru)).

Setiap test di atas dilihat merah sekali dengan merusak penangkalnya, sebelum dipercaya
([standar penjaga](/dev/25-standar-penjaga-dan-pengujian)).
