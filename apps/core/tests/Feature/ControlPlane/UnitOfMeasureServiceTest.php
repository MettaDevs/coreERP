<?php

namespace Tests\Feature\ControlPlane;

use App\Services\UnitOfMeasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UnitOfMeasureServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_is_tenant_scoped_and_conversion_requires_one_class(): void
    {
        [$tenant, $class, $kg, $gram] = $this->units();
        $service = app(UnitOfMeasureService::class);
        $this->assertSame('Kilogram', $service->resolve($tenant, [$kg])[$kg]['name']);
        DB::table('uom_conversions')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'from_unit_id' => $kg, 'to_unit_id' => $gram, 'factor' => 1000, 'offset' => 0, 'rounding_scale' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('2500', $service->convert($tenant, $kg, $gram, '2.5')['value']);
        $this->expectException(ValidationException::class);
        $service->resolve((string) Str::ulid(), [$kg]);
    }

    /** @return array{string,string,string,string} */
    private function units(): array
    {
        $tenant = (string) Str::ulid();
        $class = (string) Str::ulid();
        $system = (string) Str::ulid();
        $kg = (string) Str::ulid();
        $gram = (string) Str::ulid();
        $now = now();
        DB::table('uom_classes')->insert(['id' => $class, 'tenant_id' => $tenant, 'code' => 'MASS', 'name' => 'Massa', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('uom_systems')->insert(['id' => $system, 'tenant_id' => $tenant, 'code' => 'METRIC', 'name' => 'Metrik', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([[$kg, 'KG', 'Kilogram'], [$gram, 'G', 'Gram']] as [$id, $code, $name]) {
            DB::table('units_of_measure')->insert(['id' => $id, 'tenant_id' => $tenant, 'uom_class_id' => $class, 'uom_system_id' => $system, 'code' => $code, 'name' => $name, 'decimal_places' => 3, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        return [$tenant, $class, $kg, $gram];
    }
}
