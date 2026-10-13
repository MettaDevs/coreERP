<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Actions\DrillDown;
use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\QueryParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Platform\Analytics\Support\SalesFixture;
use Tests\Feature\Platform\Analytics\Support\TestPrincipal;
use Tests\TestCase;

class DrillDownHierarchyTest extends TestCase
{
    use RefreshDatabase, SalesFixture;

    public function test_declared_dataset_hierarchy_drills_to_its_next_field(): void
    {
        $this->migrateSalesModule();
        $tenant = $this->salesTenant('Tenant hierarki');
        $firstEntity = (string) Str::ulid();
        $secondEntity = (string) Str::ulid();
        $firstUnit = (string) Str::ulid();
        $secondUnit = (string) Str::ulid();
        $this->organization($tenant, $firstEntity, 'Entitas A', 'legal_entity');
        $this->organization($tenant, $secondEntity, 'Entitas B', 'legal_entity');
        $this->organization($tenant, $firstUnit, 'Unit A', 'operating_unit');
        $this->organization($tenant, $secondUnit, 'Unit B', 'operating_unit');
        $this->sale($tenant, $firstEntity, $firstUnit, '100');
        $this->sale($tenant, $firstEntity, $secondUnit, '200');
        $this->sale($tenant, $firstEntity, $secondUnit, '300');
        $this->sale($tenant, $secondEntity, $firstUnit, '400');

        $dataset = app(DatasetRegistry::class)->find(self::SALES);
        $this->assertNotNull($dataset);
        $this->assertSame(['legal_entity_unit' => ['legal_entity_id', 'org_unit_id']], $dataset->hierarchies());
        $principal = new TestPrincipal($tenant);
        $query = (new QueryParser)->parse([
            'dataset' => self::SALES,
            'dimensions' => ['legal_entity_id'],
            'measures' => ['count'],
        ]);
        $top = app(RunQuery::class)->handle($principal, $query, cacheTtl: 0);
        $entity = $top->rows[0]['legal_entity_id'];
        $down = app(DrillDown::class)->run($dataset, $query, $principal, 'legal_entity_id', [
            ['field' => 'legal_entity_id', 'value' => $entity],
        ]);

        $this->assertSame('org_unit_id', $down['next']['field']);
        $this->assertEqualsCanonicalizing(
            [[$firstUnit, 1], [$secondUnit, 2]],
            array_map(static fn (array $row): array => [$row['org_unit_id'], $row['count']], $down['result']->rows),
        );
    }

    private function organization(string $tenant, string $id, string $name, string $classification): void
    {
        DB::table('organizations')->insert([
            'id' => $id,
            'tenant_id' => $tenant,
            'name' => $name,
            'classification' => $classification,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
