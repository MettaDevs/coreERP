# Model semantik: dataset yang dinyatakan module

Bagian dari [engine analitik](/todo/analitik/). Halaman ini kontrak antara module dan engine: apa
yang ditulis pengembang module supaya tabelnya dapat dianalisis, aturan setiap bagiannya, dan test
yang wajib menyertainya. Kode di halaman ini adalah sketsa yang mengikat bentuknya; begitu kodenya
ada di repo, kodenya yang menjadi rujukan dan halaman ini diperbarui dalam pull request yang sama.

## Kenapa dataset dinyatakan, bukan ditebak

Core boleh membaca tabel module, tetapi tidak menebak artinya:

- **Kolom angka bukan measure.** `acquisition_value` sah dijumlah; `tahun_perolehan` dan nomor urut
  tidak. Hanya pemilik tabel yang tahu bedanya.
- **Kolom kebijakan data tidak dapat ditebak.** Aset dibatasi menurut `responsible_org_unit_id`,
  bukan `financial_dimension_org_unit_id`, walau keduanya menunjuk unit kerja. Salah pilih kolom
  berarti kepala unit melihat aset unit lain.
- **Uang tidak boleh dijumlah lintas mata uang** (KA-22), dan hanya pemilik tabel yang tahu kolom
  mata uangnya.

Bentuk ini juga yang dipakai Microsoft: query object BC menyatakan kolom, `Method = Sum`, join, dan
saringan tetap; entity store F&O menyatakan *aggregate measurement* dan *aggregate dimension*
([riset](/todo/analitik/riset#_1-bagaimana-microsoft-menyusunnya)).

Yang **tidak** ditulis ulang: nama tampilan kolom. Katalog K-30 (`FIELD_CAPTIONS`, `FIELD_OPTIONS`,
`FIELD_LOOKUPS`, `FIELD_HIDDEN`) dan klasifikasi data (`COLUMN_CLASSIFICATION`) yang sudah ada di model
dipakai apa adanya lewat `fieldsFromModel()`.

## Kontrak

Semua kelas di bawah tinggal di `apps/core/app/Platform/Modules/Contracts/Analytics/`, kecuali
`DataPolicyFilter` yang tinggal satu tingkat di atasnya karena dipakai juga di luar analitik.

### `Dataset` dan `Datasets`

```php
<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/**
 * Satu dataset analitik milik module: tabel yang boleh dianalisis, field yang ditawarkan, measure
 * yang sah dijumlah, dan kolom yang menegakkan kebijakan data. Padanan query object Business
 * Central bertipe API dan aggregate measurement F&O.
 *
 * Core membaca tabelnya langsung (keputusan pemilik produk, 3 Oktober 2026), tetapi hanya lewat
 * definisi ini: nama tabel dan kolom tidak pernah ditulis di Core.
 */
interface Dataset
{
    /** Id module pemilik, sama dengan `id` pada `app.yaml`. Dataset dari module yang tidak terpasang tidak ditawarkan. */
    public function moduleId(): string;

    /**
     * Definisi dataset. Dipanggil registry sekali per proses lalu dibekukan. Tidak boleh membaca
     * database, sesi, atau konteks permintaan: definisi sama untuk setiap tenant dan pengguna.
     */
    public function definition(): DatasetDefinition;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/**
 * Tempat module mendaftarkan datasetnya, sekali saat boot dari penyedia layanannya:
 *
 *     $this->app->make(Datasets::class)->register($this->app->make(AssetRegisterDataset::class));
 *
 * Diikat sebagai singleton di `CoreServices::SINGLETON_BINDINGS`, sama seperti
 * `ModuleReportProviders`: diikat dengan `bind`, setiap pendaftaran masuk ke salinan yang langsung
 * dibuang dan Core melihat daftar kosong tanpa satu pun kesalahan.
 */
interface Datasets
{
    public function register(Dataset $dataset): void;
}
```

### `Aggregate`, `MeasureFormat`, `SharedDimension`

```php
<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/** Cara sebuah measure dihitung. Daftar yang sama dengan pilihan agregasi Generic Chart BC. */
enum Aggregate: string
{
    /** Jumlah baris; dengan field, jumlah baris yang field-nya terisi. */
    case Count = 'count';
    case CountDistinct = 'count_distinct';
    case Sum = 'sum';
    case Average = 'avg';
    case Minimum = 'min';
    case Maximum = 'max';

    /**
     * Dapat dihitung ulang dari ringkasan yang lebih halus (fase 3). Rata-rata dan jumlah unik
     * tidak: rata-rata dari rata-rata dan jumlah dari jumlah unik keduanya salah.
     */
    public function rollsUp(): bool
    {
        return in_array($this, [self::Count, self::Sum, self::Minimum, self::Maximum], true);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/** Cara nilai measure ditampilkan. Nama nilainya sama dengan tipe nilai laporan (`ValueFormat`). */
enum MeasureFormat: string
{
    case Number = 'number';
    /** Wajib membawa kolom mata uang; lihat {@see DatasetDefinition::measure()}. */
    case Money = 'money';
    case Percent = 'percent';
    /** Wajib membawa kolom satuan. */
    case Quantity = 'quantity';
    /** Dalam jam, seperti KPI pemeliharaan. */
    case Hours = 'hours';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/**
 * Dimensi milik Core atau Foundation yang dipakai lebih dari satu module. Labelnya diterjemahkan
 * Core, dan dua dataset dari module berbeda dapat digabung menurut nilainya (drill-across, area 14).
 * Menambah kasus berarti menambah resolver di Core; module tidak dapat membuat dimensi bersama.
 */
enum SharedDimension: string
{
    case LegalEntity = 'core.legal-entity';
    case OperatingUnit = 'core.operating-unit';
    case User = 'core.user';
    case Vendor = 'foundation.vendor';
    case Currency = 'foundation.currency';
}
```

### `DatasetDefinition`

Pembangun yang hanya mengumpulkan pernyataan. Validasinya di Core (`DatasetValidator`), supaya kelas
kontrak tetap tipis dan module tidak dapat melewatinya.

```php
<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FieldType;
use Closure;

/**
 * Pernyataan satu dataset. Semua nama kolom ditulis tanpa awalan tabel untuk tabel dasar, dan
 * `alias.kolom` untuk tabel yang di-join. Kunci field dan measure berbentuk `snake_case`, unik di
 * dalam dataset, dan menjadi janji kepada widget tenant: menggantinya butuh `version()` baru beserta
 * peta nama lama.
 */
final class DatasetDefinition
{
    /** @var array<string, array<string, mixed>> */
    private array $fields = [];

    /** @var array<string, array<string, mixed>> */
    private array $measures = [];

    /** @var array<string, array<string, mixed>> */
    private array $joins = [];

    /** @var array<string, array<string, mixed>> */
    private array $references = [];

    /** @var array<string, SharedDimension> */
    private array $shared = [];

    /** @var list<string> */
    private array $times = [];

    private ?string $defaultTime = null;

    /** @var class-string|null */
    private ?string $model = null;

    private ?Closure $source = null;

    private ?string $permission = null;

    /** @var array{code: string, legal_entity: string, operating_unit: ?string}|null */
    private ?array $policy = null;

    /** @var array{only: list<string>, except: list<string>}|null */
    private ?array $fromModel = null;

    private ?string $description = null;

    private ?string $recordRoute = null;

    private int $version = 1;

    /** @var array<string, string> */
    private array $renamed = [];

    private function __construct(
        public readonly string $code,
        public readonly string $caption,
    ) {}

    /** `$code` berawalan id module, misalnya `management-aset.asset-register`. `$caption` tampil di layar. */
    public static function make(string $code, string $caption): self
    {
        return new self($code, $caption);
    }

    public function description(string $text): self
    {
        $this->description = $text;

        return $this;
    }

    /**
     * Tabel dasar lewat model module. Model wajib memakai `BelongsToTenant`, jadi penyaringan tenant
     * dan baris terarsip ikut dari model, tidak ditulis ulang. Tabel dasar tidak pernah diberi alias.
     *
     * @param  class-string  $model
     */
    public function model(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Sumber berupa query module, untuk bentuk yang tidak dapat dinyatakan sebagai tabel ditambah join
     * (misalnya nilai buku terakhir per aset). Closure memulangkan query builder yang disusun dari model
     * module, tanpa saringan milik pengguna mana pun. Field wajib dinyatakan satu per satu, beserta
     * klasifikasinya, karena tidak ada model untuk dibaca katalognya.
     *
     * @param  Closure(): \Illuminate\Contracts\Database\Query\Builder  $source
     */
    public function fromQuery(Closure $source): self
    {
        $this->source = $source;

        return $this;
    }

    /** Permission baca resource yang sudah ada di manifest module (KA-15), misalnya `management-aset.aset.read`. */
    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    /**
     * Kebijakan data yang menjaga resource ini dan kolom yang menegakkannya, persis kolom yang dipakai
     * endpoint daftar module. Tanpa `$operatingUnit`, hanya legal entity yang dicocokkan — sama dengan
     * `OrganizationScope::legalEntityQuery()` untuk record tanpa unit kerja.
     */
    public function dataPolicy(string $code, string $legalEntity, ?string $operatingUnit = null): self
    {
        $this->policy = ['code' => $code, 'legal_entity' => $legalEntity, 'operating_unit' => $operatingUnit];

        return $this;
    }

    /**
     * Seluruh kolom katalog filter tambahan K-30 model dasar menjadi field: nama tampilan dari
     * `FIELD_CAPTIONS`, pilihan dari `FIELD_OPTIONS`, tipe dari database, kolom `FIELD_HIDDEN` dan
     * `AccountData` tidak ikut.
     *
     * @param  list<string>  $only
     * @param  list<string>  $except
     */
    public function fieldsFromModel(array $only = [], array $except = []): self
    {
        $this->fromModel = ['only' => $only, 'except' => $except];

        return $this;
    }

    /**
     * Field yang tidak ada di katalog model, atau berasal dari tabel yang di-join (`$column` = `alias.kolom`).
     *
     * @param  array<string, string>  $options  nilai => label, untuk `FieldType::Option`
     */
    public function field(string $key, string $caption, FieldType $type, ?string $column = null, array $options = [], ?DataClass $classification = null): self
    {
        $this->fields[$key] = compact('caption', 'type', 'column', 'options', 'classification');

        return $this;
    }

    /**
     * Field rujukan ke master milik module yang sama, dengan sumber labelnya. Join label membawa baris
     * terarsip juga, supaya aset yang group-nya sudah diarsipkan tetap bernama.
     *
     * @param  class-string  $model
     */
    public function reference(string $key, string $model, string $label = 'nama', ?string $code = 'kode'): self
    {
        $this->references[$key] = compact('model', 'label', 'code');

        return $this;
    }

    /** Field yang menunjuk dimensi milik Core atau Foundation; labelnya diterjemahkan Core. */
    public function shared(string $key, SharedDimension $dimension): self
    {
        $this->shared[$key] = $dimension;

        return $this;
    }

    /**
     * Tabel module yang sama yang di-join ke tabel dasar. Join selalu membawa `tenant_id` yang sama, dan
     * bawaannya hanya baris yang belum diarsipkan.
     *
     * @param  class-string  $model
     */
    public function join(string $alias, string $model, string $localColumn, string $foreignColumn = 'id', bool $includeArchived = false): self
    {
        $this->joins[$alias] = compact('model', 'localColumn', 'foreignColumn', 'includeArchived');

        return $this;
    }

    /**
     * Nilai yang sah dihitung. `$where` adalah saringan tetap measure, hanya kesamaan dan daftar nilai
     * pada field pilihan, ya/tidak, atau rujukan (`['lifecycle_state' => ['disposed']]`), dan dikompilasi
     * menjadi `FILTER (WHERE …)`.
     *
     * @param  array<string, list<string|int|bool|null>|string|int|bool|null>  $where
     */
    public function measure(
        string $key,
        string $caption,
        Aggregate $aggregate,
        ?string $field = null,
        MeasureFormat $format = MeasureFormat::Number,
        ?string $currency = null,
        ?string $unit = null,
        array $where = [],
    ): self {
        $this->measures[$key] = compact('caption', 'aggregate', 'field', 'format', 'currency', 'unit', 'where');

        return $this;
    }

    /** Field tanggal yang dapat dipakai untuk rentang dan pengelompokan waktu. */
    public function time(string $field, bool $default = false): self
    {
        $this->times[] = $field;
        if ($default) {
            $this->defaultTime = $field;
        }

        return $this;
    }

    /** Alamat layar record, dengan `{id}` diganti id baris. Dipakai drill-through (fase 2). */
    public function recordRoute(string $template): self
    {
        $this->recordRoute = $template;

        return $this;
    }

    /**
     * Versi definisi. Naikkan setiap kali kunci field atau measure dihapus atau diganti; `$renamed`
     * memetakan kunci lama ke kunci baru supaya widget tenant tetap berjalan.
     *
     * @param  array<string, string>  $renamed
     */
    public function version(int $version, array $renamed = []): self
    {
        $this->version = $version;
        $this->renamed = $renamed;

        return $this;
    }

    /**
     * Bentuk mentah untuk registry Core. Bukan API module.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
```

`toArray()` sengaja memulangkan bentuk mentah: penafsirannya — membaca katalog model, mengecek
kolom, memasang klasifikasi — dikerjakan `DatasetValidator` di Core, satu tempat untuk semua module.

### `DataPolicyFilter`

Aturan penyaringan menurut hibah kebijakan data hari ini hanya ada di module
(`Modules\Apperp\ManagementAset\Support\OrganizationScope::query()`). Analitik membutuhkan aturan yang
**sama persis**, jadi aturannya dipindah ke kontrak dan dipakai keduanya. Dua implementasi aturan yang
sama akan menyimpang (R-11).

```php
<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Menyaring query menurut hibah satu kebijakan data, bentuk yang dipulangkan
 * `DataPolicyAccessResolver::resolve()` untuk satu kode kebijakan:
 * `{all: bool, scope_grants: list<{legal_entity_id: ?string, operating_unit_ids: list<string>}>}`.
 *
 * Gagal tertutup: tanpa hibah, nol baris. Hibah tanpa legal entity atau tanpa unit tidak menjangkau
 * apa pun pada mode legal entity + unit. Aturan ini sama dengan `OrganizationScope::query()` module
 * aset; module boleh pindah memakainya, tetapi tidak wajib.
 */
final class DataPolicyFilter
{
    /**
     * @param  array{all?: mixed, scope_grants?: mixed}  $scope
     */
    public static function apply(Builder $query, array $scope, string $legalEntityColumn, ?string $operatingUnitColumn): Builder
    {
        if (($scope['all'] ?? false) === true) {
            return $query;
        }

        $grants = self::grants($scope);
        if ($grants === []) {
            return $query->whereRaw('1 = 0');
        }

        if ($operatingUnitColumn === null) {
            $legalEntities = array_values(array_unique(array_filter(array_column($grants, 'legal_entity_id'))));

            return $legalEntities === [] ? $query->whereRaw('1 = 0') : $query->whereIn($legalEntityColumn, $legalEntities);
        }

        return $query->where(function (Builder $query) use ($grants, $legalEntityColumn, $operatingUnitColumn): void {
            foreach ($grants as $grant) {
                $query->orWhere(function (Builder $query) use ($grant, $legalEntityColumn, $operatingUnitColumn): void {
                    if ($grant['legal_entity_id'] === null || $grant['operating_unit_ids'] === []) {
                        $query->whereRaw('1 = 0');

                        return;
                    }
                    $query->where($legalEntityColumn, $grant['legal_entity_id'])
                        ->whereIn($operatingUnitColumn, $grant['operating_unit_ids']);
                });
            }
        });
    }

    /** @return list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}> */
    private static function grants(array $scope): array
    {
        // Bentuk dibaca satu per satu, bukan dipercaya: nilai yang bukan daftar string tidak boleh
        // menjatuhkan permintaan, dan tidak boleh melebarkan jangkauan.
        $out = [];
        foreach (is_array($scope['scope_grants'] ?? null) ? $scope['scope_grants'] : [] as $grant) {
            if (! is_array($grant)) {
                continue;
            }
            $units = [];
            foreach (is_array($grant['operating_unit_ids'] ?? null) ? $grant['operating_unit_ids'] : [] as $unit) {
                if (is_string($unit)) {
                    $units[] = $unit;
                }
            }
            $out[] = [
                'legal_entity_id' => is_string($grant['legal_entity_id'] ?? null) ? $grant['legal_entity_id'] : null,
                'operating_unit_ids' => $units,
            ];
        }

        return $out;
    }
}
```

Test paritasnya ada di [keamanan](/todo/analitik/keamanan#test-paritas-kebijakan-data).

## Contoh: register aset

```php
<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Support\StatusAset;

/**
 * Register aset sebagai dataset analitik: satu baris per aset tercatat, termasuk komponen.
 *
 * Kolom kebijakan sama dengan `OrganizationScope::asetQuery()` di layar daftar aset — unit penanggung
 * jawab, bukan unit dimensi keuangan — dan test paritas menjaganya tetap sama.
 */
final class AssetRegisterDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        return DatasetDefinition::make('management-aset.asset-register', 'Register aset')
            ->description('Satu baris per aset tercatat, termasuk komponen.')
            ->model(Aset::class)
            ->permission('management-aset.aset.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            // Keterangan dan nomor seri tidak berguna sebagai pengelompok dan memperbesar kardinalitas.
            ->fieldsFromModel(except: ['keterangan', 'serial_number', 'model_number'])
            ->reference('group_aset_id', GroupAset::class)
            ->reference('jenis_aset_id', JenisAset::class)
            ->reference('kondisi_aset_id', KondisiAset::class)
            ->reference('lokasi_aset_id', LokasiAset::class)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('currency_code', SharedDimension::Currency)
            ->time('acquired_on', default: true)
            ->time('placed_in_service_on')
            ->measure('count', 'Jumlah aset', Aggregate::Count)
            ->measure('acquisition_value', 'Nilai perolehan', Aggregate::Sum,
                field: 'acquisition_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('average_acquisition_value', 'Rata-rata nilai perolehan', Aggregate::Average,
                field: 'acquisition_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('disposed', 'Aset dilepas', Aggregate::Count,
                where: ['lifecycle_state' => [StatusAset::DILEPAS]])
            ->recordRoute('/management-aset/inventarisasi-aset/{id}')
            ->version(1);
    }
}
```

Dua catatan pada contoh ini:

- `legal_entity_id` ada di `FIELD_HIDDEN` model aset ("dipilih lewat workspace"). Untuk analitik ia
  justru dimensi yang berguna, jadi dataset menyatakannya ulang lewat `shared()`. `shared()` dan
  `reference()` menambahkan field walau kolomnya tersembunyi dari katalog filter.
- Rute record wajib menunjuk layar detail yang benar-benar ada. Periksa rute layar module sebelum
  menulisnya; alamat yang tidak ada adalah janji yang gagal saat diklik.

## Contoh: join di dalam module

```php
DatasetDefinition::make('management-aset.work-orders', 'Work order pemeliharaan')
    ->model(WorkOrder::class)
    ->permission('management-aset.pemeliharaan-aset.read')
    // Work order tidak punya unit sendiri; kebijakannya mengikuti aset yang dirawat.
    ->join('asset', Aset::class, localColumn: 'aset_id')
    ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'asset.legal_entity_id', operatingUnit: 'asset.responsible_org_unit_id')
    ->fieldsFromModel()
    ->field('asset_group', 'Group aset', FieldType::Reference, column: 'asset.group_aset_id')
    ->reference('asset_group', GroupAset::class)
    ->time('scheduled_on', default: true)
    ->time('completed_at')
    ->measure('count', 'Jumlah work order', Aggregate::Count)
    ->measure('completed', 'Work order selesai', Aggregate::Count, where: ['status' => ['completed']])
    ->measure('actual_hours', 'Jam kerja aktual', Aggregate::Sum, field: 'actual_hours', format: MeasureFormat::Hours)
    ->version(1);
```

Nama kelas dan kolom work order di atas ilustrasi; area 5 memakai nama yang ada di module.

## Dataset bersumber query

Untuk bentuk yang tidak dapat dinyatakan sebagai tabel plus join — nilai buku terakhir per aset per
buku, misalnya, yang di dasbor aset dihitung dengan `ROW_NUMBER()` — module menyerahkan query
sumbernya:

```php
DatasetDefinition::make('management-aset.book-values', 'Nilai buku aset')
    ->fromQuery(fn () => BookValueQuery::latestPerAssetAndBook())
    ->permission('management-aset.penyusutan.read')
    ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
    ->field('book_code', 'Buku', FieldType::Text, classification: DataClass::CustomerContent)
    ->field('asset_id', 'Aset', FieldType::Reference, classification: DataClass::CustomerContent)
    ->reference('asset_id', Aset::class)
    ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
    ->measure('net_book_value', 'Nilai buku', Aggregate::Sum, field: 'net_book_value',
        format: MeasureFormat::Money, currency: 'currency_code')
    ->version(1);
```

Aturan sumber query, karena ia satu-satunya tempat module menyerahkan SQL-nya sendiri:

- Disusun dari model module yang memakai `BelongsToTenant`, sehingga penyaringan tenant ada di dalam
  subquery. Engine tetap menambah `tenant_id = ?` pada query luar sebagai lapis kedua, karena
  subquery tidak membawa global scope ke luar.
- Tidak memuat saringan milik pengguna, sesi, atau permintaan. Saringan pengguna dan kebijakan data
  dipasang engine pada query luar, memakai kolom yang dinyatakan.
- Setiap kolom yang dinyatakan sebagai field, measure, atau kolom kebijakan wajib ada di daftar
  `SELECT` subquery. `DatasetValidator` memeriksanya dengan menjalankan subquery `LIMIT 0`.
- Setiap field menyatakan klasifikasinya sendiri.

## Mendaftarkan dataset

Satu baris di `boot()` penyedia layanan module, di samping pendaftaran laporan dan ekspor daftar:

```php
$datasets = $this->app->make(Datasets::class);
foreach ([AssetRegisterDataset::class, WorkOrderDataset::class, DisposalDataset::class] as $dataset) {
    $datasets->register($this->app->make($dataset));
}
```

Tidak ada perubahan manifest, katalog admin.erp, atau migration module. Hak membacanya memakai
permission yang sudah ada (KA-15), jadi menambah dataset tidak mengubah susunan role tenant mana pun.

## Yang diperiksa `DatasetValidator`

Validator berjalan di dua tempat: saat registry pertama kali dibaca di runtime (dataset rusak
**dilewati dan dicatat**, tidak menjatuhkan aplikasi, sama seperti registry module melewati manifest
rusak), dan di `AnalyticsDatasetsBoundaryTest`, yang **gagal** untuk dataset rusak.

| Aturan | Pesan bila dilanggar | Kenapa |
| --- | --- | --- |
| Kode berawalan `<moduleId>.` dan unik | Kode dataset harus berawalan id module | Kode tersimpan di widget tenant |
| Tepat satu dari `model()` atau `fromQuery()` | Dataset butuh satu sumber | — |
| Model memakai `BelongsToTenant` | Model dataset harus memakai BelongsToTenant | Penyaringan tenant milik model |
| Model dan join berasal dari namespace module yang sama | Join hanya ke tabel module sendiri | Module tidak menyentuh module lain |
| Kolom field, measure, join, waktu, dan kebijakan ada di tabelnya | Kolom X tidak ada di tabel Y | Salah ketik terungkap di CI, bukan di layar pelanggan |
| Permission ada di manifest module dengan access `read` | Permission X tidak ada | Dataset tanpa penjaga |
| Permission yang dilindungi sebuah kebijakan data mewajibkan `dataPolicy()` kebijakan itu | Resource ini dibatasi kebijakan X; nyatakan kolom kebijakannya | Lupa kebijakan = kepala unit melihat unit lain |
| Setiap field punya klasifikasi; `AccountData` ditolak | Kolom X belum diklasifikasi | Gerbang data pribadi bergantung padanya |
| Measure `Money` membawa `currency`, `Quantity` membawa `unit` | Measure uang wajib menyebut kolom mata uang | KA-22 |
| `Sum`/`Average` hanya pada kolom angka | Kolom X bukan angka | — |
| `where` measure hanya pada field pilihan, ya/tidak, atau rujukan | — | Saringan tetap yang sederhana dan aman |
| Waktu hanya pada kolom `date`/`timestamp`/`timestamptz` | — | — |
| `renamed` menunjuk kunci yang ada | — | Peta nama yang menunjuk kekosongan tidak menolong siapa pun |
| `recordRoute` berawalan `/<moduleId>/` dan memuat `{id}` | — | — |
| Kunci `snake_case`, maksimal 64 karakter | — | Kunci tampil di JSON dan URL OData |

## Test yang wajib menyertai setiap dataset

Di folder test module, `tests/Feature/Analytics/<Nama>DatasetTest.php`, memakai rantai izin
sungguhan seperti test module lain:

1. **Isolasi tenant.** Dua tenant dengan data; query lewat `POST /api/v1/analytics/query` sebagai
   pengguna tenant A tidak pernah memulangkan angka dari tenant B.
2. **Paritas kebijakan data.** Untuk pengguna dengan hibah unit A saja, unit B saja, semua, dan tanpa
   hibah: himpunan id baris lewat drill analitik sama dengan himpunan id dari endpoint daftar module.
3. **Uang per mata uang.** Dengan aset IDR dan USD, measure `acquisition_value` tanpa dimensi mata
   uang memulangkan dua nilai, tidak satu jumlah campuran.
4. **Measure bersaringan.** `disposed` menghitung hanya aset berstatus dilepas.
5. **Waktu berzona.** Aset bertanggal di batas bulan muncul di bulan yang benar menurut zona
   pengguna (lihat [mesin query](/todo/analitik/mesin-query#waktu-dan-zona)).

Penjaga katalog generik (`AnalyticsDatasetsBoundaryTest`) sudah menjaga aturan tabel di atas untuk
semua dataset; test module tidak mengulangnya.

## Mengubah dataset yang sudah dipakai

| Perubahan | Cara | Akibat bagi widget tenant |
| --- | --- | --- |
| Menambah field atau measure | Langsung | Tidak ada |
| Mengganti nama tampilan | Langsung (caption bukan kunci) | Judul berubah, widget tetap |
| Mengganti kunci | `version(n+1, renamed: ['lama' => 'baru'])` | Widget dipetakan otomatis saat dibaca |
| Menghapus field atau measure | `version(n+1)` tanpa peta | Widget yang memakainya menampilkan "Kolom X sudah tidak tersedia" |
| Mengganti kolom kebijakan | Langsung, beserta test paritas | Jangkauan baris berubah seperti layar module |
| Menghapus dataset | Hapus pendaftarannya | Widget menampilkan "Data ini tidak tersedia lagi" |

Kolom yang dihapus dari tabel module mengikuti aturan expand/contract: dataset berhenti memakainya
paling lambat di rilis yang sama dengan kode module yang berhenti membacanya.

## Kesalahan yang paling mungkin

- **Memberi alias pada tabel dasar.** `TenantScope` disisipkan dengan nama tabel sebenarnya; tabel
  dasar beralias membuat saringan tenant menunjuk nama yang tidak ada di query itu
  ([penyaringan tenant](/dev/02-module-standard#penyaringan-tenant)). Compiler tidak pernah melakukannya; dataset tidak
  dapat memintanya.
- **Menyaring tenant dengan tangan di sumber query.** Bukan berlebihan, tetapi membuat penjaganya
  tidak terukur: saringan tangan tetap benar walau trait dicabut. Pakai model ber-`BelongsToTenant`.
- **Menjadikan kolom catatan bebas sebagai field.** Keterangan dan nama bebas punya kardinalitas
  tinggi dan sering berisi data pribadi; kecualikan lewat `except`.
- **Mengira `count` menghitung entitas unik.** `Count` menghitung baris. Work order per aset dihitung
  `CountDistinct` pada `aset_id`.
