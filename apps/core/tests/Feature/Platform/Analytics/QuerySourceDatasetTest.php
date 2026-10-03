<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\CompiledMeasure;
use App\Platform\Analytics\Datasets\DatasetValidator;
use App\Platform\Analytics\Query\QueryCompiler;
use App\Platform\Analytics\Query\QueryExecutor;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\FilterField;
use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Modules\Support\TenantScope;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ContohA\Models\Barang;
use RuntimeException;
use Tests\TestCase;

/**
 * Dataset bersumber query (`fromQuery()`): engine menyaring tenant dua kali — scope model di dalam query
 * sumber, dan `base.tenant_id = ?` di query luar — sehingga query sumber yang kehilangan scope-nya tetap
 * tidak membaca tenant lain, dan keduanya gagal tertutup di luar tenant aktif.
 */
class QuerySourceDatasetTest extends TestCase
{
    use RefreshDatabase;

    private const DATASET = 'contoh-a.barang-per-bawaan';

    private string $tenantA;

    private string $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // Tabel module contoh dibuat test-nya sendiri, seperti penjaga batas lain (`TenantScopeBoundaryTest`).
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 3).'/Fixtures/modules/apperp/contoh-a/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->tenantA = $this->tenant('Tenant sumber A');
        $this->tenantB = $this->tenant('Tenant sumber B');
        foreach ([[$this->tenantA, 'A1', true], [$this->tenantA, 'A2', false], [$this->tenantA, 'A3', false], [$this->tenantB, 'B1', true], [$this->tenantB, 'B2', true]] as [$tenant, $code, $default]) {
            DB::table('contoh_a_m_barang')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'kode' => $code, 'nama' => 'Barang '.$code,
                'bawaan' => $default, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_a_query_source_dataset_reads_only_the_active_tenant(): void
    {
        $validator = app(DatasetValidator::class);
        $dataset = $validator->compile($validator->declare($this->dataset(DatasetDefinition::make(self::DATASET, 'Barang per bawaan')
            ->fromQuery(static fn () => Barang::query()->select(['tenant_id', 'bawaan'])->selectRaw('count(*) as jumlah')->groupBy('tenant_id', 'bawaan'))
            ->permission('contoh-a.barang.read')
            ->field('bawaan', 'Barang bawaan', FieldType::Boolean, classification: DataClass::CustomerContent)
            ->measure('jumlah', 'Jumlah barang', Aggregate::Sum, field: 'jumlah'))));

        $this->assertSame([[false, '2'], [true, '1']], $this->groupedByDefault($dataset));
    }

    public function test_the_outer_tenant_filter_still_holds_when_the_source_lost_its_scope(): void
    {
        // Dirakit langsung, melewati validator yang menolak query sumber tanpa scope tenant: yang diuji di sini
        // lapis kedua, untuk hari ketika lapis pertama luput.
        $dataset = new CompiledDataset(
            code: self::DATASET,
            caption: 'Barang per bawaan',
            moduleId: 'contoh-a',
            version: 1,
            model: Barang::class,
            table: CompiledDataset::SOURCE_ALIAS,
            permission: 'contoh-a.barang.read',
            policy: null,
            fields: ['bawaan' => new FilterField('bawaan', 'Barang bawaan', FieldType::Boolean, 'base.bawaan')],
            measures: ['jumlah' => new CompiledMeasure('jumlah', 'Jumlah barang', Aggregate::Count, null, MeasureFormat::Number, null, null, [])],
            times: [],
            defaultTime: null,
            source: static fn () => Barang::query()->withoutGlobalScope(TenantScope::class)->select(['tenant_id', 'bawaan']),
        );

        $this->assertSame([[false, 2], [true, 1]], $this->groupedByDefault($dataset));
    }

    public function test_the_source_fails_closed_outside_an_active_tenant(): void
    {
        $validator = app(DatasetValidator::class);
        $dataset = $validator->compile($validator->declare($this->dataset(DatasetDefinition::make(self::DATASET, 'Barang per bawaan')
            ->fromQuery(static fn () => Barang::query()->select(['tenant_id', 'bawaan']))
            ->permission('contoh-a.barang.read')
            ->field('bawaan', 'Barang bawaan', FieldType::Boolean, classification: DataClass::CustomerContent)
            ->measure('count', 'Jumlah barang', Aggregate::Count))));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tanpa tenant aktif');

        $dataset->baseQuery();
    }

    /** @return list<array{0: mixed, 1: mixed}> pasangan bawaan dan measure, urut bawaan */
    private function groupedByDefault(CompiledDataset $dataset): array
    {
        $user = User::factory()->create();
        $membership = TenantMembership::query()->create(['tenant_id' => $this->tenantA, 'user_id' => $user->id, 'status' => 'active']);
        $principal = UserPrincipal::fromMembership($membership, 'UTC');
        $query = app(QueryParser::class)->parse(['dataset' => self::DATASET, 'dimensions' => ['bawaan'], 'measures' => [array_key_first($dataset->measures())]]);

        $result = app(TenantRunner::class)->runFor($this->tenantA, static fn (): array => app(QueryExecutor::class)
            ->run(app(QueryCompiler::class)->compile($dataset, $query, $principal), 3000));

        $rows = array_map(static fn (object $row): array => [$row->d0, $row->m0], $result['rows']);
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $rows;
    }

    private function tenant(string $name): string
    {
        $client = (string) Str::ulid();
        $tenant = (string) Str::ulid();
        $slug = Str::slug($name).'-'.Str::lower(Str::random(6));
        DB::table('clients')->insert(['id' => $client, 'legal_name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenant, 'client_id' => $client, 'name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $tenant;
    }

    private function dataset(DatasetDefinition $definition): Dataset
    {
        return new readonly class($definition) implements Dataset
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
        };
    }
}
