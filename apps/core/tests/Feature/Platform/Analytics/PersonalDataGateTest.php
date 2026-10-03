<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Datasets\DatasetValidator;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\PersonalDataGate;
use App\Platform\Analytics\Security\ScopeFingerprint;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Models\ModuleInstallation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ContohA\Models\Penjualan;
use Tests\TestCase;

/**
 * Gerbang data pribadi (area 4.4) atas dataset bahan uji `contoh-a.penjualan`, yang punya satu field
 * `EndUserIdentifiableInformation` (`nama_pembeli`) dan satu `EndUserPseudonymousIdentifiers`
 * (`dicatat_oleh_user_id`, dimensi bersama pengguna).
 *
 * Tanpa hak data pribadi, field tertutup ditolak 403 `analytics.field_personal_data` berpath sebagai
 * pengelompok, saringan, dan bahan measure — juga lewat urutan, yang hanya boleh memakai kunci terpilih —
 * dan tidak ditawarkan di katalog. Id pengguna tetap boleh sebagai pengelompok. Dengan hak, semuanya
 * boleh. Gerbangnya dipanggil validator yang dibuat container, jadi jalur `RunQuery` ikut terjaga.
 * Setiap test pernah dilihat merah dengan merusak penangkalnya; caranya ditulis di pull request area 4.
 */
class PersonalDataGateTest extends TestCase
{
    use RefreshDatabase;

    private const DATASET = 'contoh-a.penjualan';

    private CompiledDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();
        // Tabel module contoh dibuat test-nya sendiri, seperti test registry dataset.
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 3).'/Fixtures/modules/apperp/contoh-a/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
        $dataset = app(DatasetRegistry::class)->find(self::DATASET);
        $this->assertNotNull($dataset, 'Dataset bahan uji tidak terdaftar; test ini akan lulus tanpa menguji apa pun.');
        $this->dataset = $dataset;
    }

    public function test_a_personal_field_is_refused_as_dimension_filter_and_sort_without_the_right(): void
    {
        $cases = [
            [['dimensions' => ['nama_pembeli']], 'dimensions.0'],
            [['dimensions' => ['status', 'nama_pembeli']], 'dimensions.1'],
            // Menyaring nama lalu membaca jumlahnya sama dengan membaca datanya.
            [['filters' => ['nama_pembeli' => 'Budi*']], 'filters.nama_pembeli'],
            // Urutan hanya boleh memakai kunci terpilih, jadi ia tertolak di pengelompoknya.
            [['dimensions' => ['nama_pembeli'], 'sort' => [['key' => 'nama_pembeli', 'direction' => 'asc']]], 'dimensions.0'],
        ];

        foreach ($cases as [$part, $path]) {
            $e = $this->refused($part, $this->principal(false));
            $this->assertSame(403, $e->status);
            $this->assertSame($path, $e->field, json_encode($part) ?: '');
            $this->assertSame('Kolom "Nama pembeli" memuat data pribadi dan tidak dapat dipakai di analitik dengan hak Anda.', $e->getMessage());
        }

        // Dengan hak data pribadi, query yang sama diterima.
        foreach ($cases as [$part]) {
            app(QueryValidator::class)->validate($this->dataset, $this->analyticsQuery($part), $this->principal(true));
        }
        $this->addToAssertionCount(count($cases));
    }

    public function test_a_pseudonymous_id_stays_usable_as_a_grouping_without_the_right(): void
    {
        app(QueryValidator::class)->validate($this->dataset, $this->analyticsQuery([
            'dimensions' => ['dicatat_oleh_user_id', 'status'],
            'filters' => ['dicatat_oleh_user_id' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']],
        ]), $this->principal(false));
        $this->addToAssertionCount(1);
    }

    public function test_the_catalog_hides_personal_fields_and_measures_that_read_them(): void
    {
        $gate = app(PersonalDataGate::class);
        $dataset = $this->datasetWithMeasureOverName();

        $this->assertArrayNotHasKey('nama_pembeli', $gate->visibleFields($dataset, $this->principal(false)));
        $this->assertArrayHasKey('dicatat_oleh_user_id', $gate->visibleFields($dataset, $this->principal(false)));
        $this->assertArrayHasKey('status', $gate->visibleFields($dataset, $this->principal(false)));
        $this->assertSame(['count'], array_keys($gate->visibleMeasures($dataset, $this->principal(false))));

        $this->assertArrayHasKey('nama_pembeli', $gate->visibleFields($dataset, $this->principal(true)));
        $this->assertSame(['count', 'pembeli_terakhir'], array_keys($gate->visibleMeasures($dataset, $this->principal(true))));
    }

    public function test_a_measure_hidden_from_the_catalog_is_refused_when_its_key_is_typed(): void
    {
        $dataset = $this->datasetWithMeasureOverName();
        $query = $this->analyticsQuery(['dataset' => $dataset->code, 'measures' => ['count', 'pembeli_terakhir']]);

        try {
            app(QueryValidator::class)->validate($dataset, $query, $this->principal(false));
            $this->fail('Measure yang membaca nama pembeli seharusnya ditolak.');
        } catch (AnalyticsQueryException $e) {
            $this->assertSame('analytics.field_personal_data', $e->errorCode);
            $this->assertSame('measures.1', $e->field);
        }

        app(QueryValidator::class)->validate($dataset, $query, $this->principal(true));
    }

    public function test_run_query_refuses_before_any_sql_for_an_installed_dataset(): void
    {
        $principal = $this->principal(false, $this->tenant());
        DB::table('core_module_installations')->insert([
            'tenant_id' => $principal->tenantId(), 'module_id' => 'contoh-a', 'version' => '0.1.0',
            'status' => ModuleInstallation::STATUS_INSTALLED, 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            app(RunQuery::class)->handle($principal, $this->analyticsQuery(['dimensions' => ['nama_pembeli']]));
            $this->fail('Query atas nama pembeli seharusnya ditolak.');
        } catch (AnalyticsQueryException $e) {
            $this->assertSame('analytics.field_personal_data', $e->errorCode);
            $this->assertSame('dimensions.0', $e->field);
        }

        // Jalur yang sama tanpa field tertutup berjalan sampai database.
        $this->assertSame([['count' => 0]], app(RunQuery::class)->handle($principal, $this->analyticsQuery([]))->toArray()['rows']);
    }

    /** @param array<string, mixed> $part */
    private function refused(array $part, AnalyticsPrincipal $principal): AnalyticsQueryException
    {
        try {
            app(QueryValidator::class)->validate($this->dataset, $this->analyticsQuery($part), $principal);
        } catch (AnalyticsQueryException $e) {
            $this->assertSame('analytics.field_personal_data', $e->errorCode);

            return $e;
        }

        $this->fail('Query seharusnya ditolak: '.json_encode($part));
    }

    /** @param array<string, mixed> $part */
    private function analyticsQuery(array $part): AnalyticsQuery
    {
        return (new QueryNormalizer)->normalize((new QueryParser)->parse([...['dataset' => self::DATASET, 'measures' => ['count']], ...$part]));
    }

    /** Dataset penjualan yang sama dengan satu measure tambahan yang memulangkan nama pembeli. */
    private function datasetWithMeasureOverName(): CompiledDataset
    {
        $definition = DatasetDefinition::make('contoh-a.pembeli', 'Pembeli')
            ->model(Penjualan::class)
            ->permission('contoh-a.penjualan.read')
            ->dataPolicy('contoh-a.penjualan-unit', legalEntity: 'legal_entity_id', operatingUnit: 'org_unit_id')
            ->fieldsFromModel()
            ->field('dicatat_oleh_user_id', 'Dicatat oleh', FieldType::Reference)
            ->measure('count', 'Jumlah penjualan', Aggregate::Count)
            ->measure('pembeli_terakhir', 'Pembeli terakhir', Aggregate::Maximum, field: 'nama_pembeli');
        $validator = app(DatasetValidator::class);

        return $validator->compile($validator->declare(new readonly class($definition) implements Dataset
        {
            public function __construct(private DatasetDefinition $definition) {}

            public function moduleId(): string
            {
                return 'contoh-a';
            }

            public function definition(): DatasetDefinition
            {
                return $this->definition;
            }
        }));
    }

    private function tenant(): string
    {
        $client = (string) Str::ulid();
        $tenant = (string) Str::ulid();
        $slug = 'gerbang-'.Str::lower(Str::random(8));
        DB::table('clients')->insert(['id' => $client, 'legal_name' => 'Gerbang data pribadi', 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenant, 'client_id' => $client, 'name' => 'Gerbang data pribadi', 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $tenant;
    }

    private function principal(bool $mayUsePersonalData, string $tenantId = 'tenant-gerbang'): AnalyticsPrincipal
    {
        return new readonly class($mayUsePersonalData, $tenantId) implements AnalyticsPrincipal
        {
            public function __construct(private bool $personalData, private string $tenant) {}

            public function tenantId(): string
            {
                return $this->tenant;
            }

            public function holdsPermission(string $moduleId, string $permission): bool
            {
                return true;
            }

            public function policyScope(string $policyCode): array
            {
                return ['all' => true, 'scope_grants' => []];
            }

            public function mayUsePersonalData(): bool
            {
                return $this->personalData;
            }

            public function lockedFilters(string $dataset): array
            {
                return [];
            }

            public function timezone(): string
            {
                return 'Asia/Jakarta';
            }

            public function now(): CarbonImmutable
            {
                return CarbonImmutable::now('Asia/Jakarta');
            }

            public function rowLimit(): int
            {
                return 5000;
            }

            public function timeoutMs(): int
            {
                return 8000;
            }

            public function fingerprint(CompiledDataset $dataset): string
            {
                return ScopeFingerprint::of($this, $dataset->code, $dataset->policy['code'] ?? null);
            }

            public function describe(): string
            {
                return 'uji:'.$this->tenant;
            }
        };
    }
}
