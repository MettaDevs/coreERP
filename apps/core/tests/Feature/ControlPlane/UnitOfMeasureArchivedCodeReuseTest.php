<?php

namespace Tests\Feature\ControlPlane;

use App\Models\UnitOfMeasure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test ini mengukur indeks unik pada `units_of_measure`, bukan lapisan lain.
 *
 * Karena itu barisnya dibuat lewat model langsung, bukan lewat `UnitOfMeasureService`
 * atau request HTTP. Bila baris dibuat lewat lapisan yang punya aturan validasi
 * "kode harus unik", test ini akan tetap hijau meskipun indeksnya dikembalikan menjadi
 * unik penuh — validasi yang menjawab lebih dulu, dan test itu tidak mengukur apa pun.
 */
class UnitOfMeasureArchivedCodeReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_kode_satuan_yang_sudah_diarsipkan_bisa_dipakai_ulang(): void
    {
        [$tenant, $class] = $this->tenantWithClass();

        $lama = $this->unit($tenant, $class, 'KG', 'Kilogram');
        $lama->delete();

        $baru = $this->unit($tenant, $class, 'KG', 'Kilogram (baru)');

        $this->assertNotSame($lama->getKey(), $baru->getKey());
        $this->assertSoftDeleted('units_of_measure', ['id' => $lama->getKey()]);

        // Yang terarsip tidak boleh ikut muncul; yang hidup hanya satu.
        $hidup = UnitOfMeasure::query()->where('tenant_id', $tenant)->where('code', 'KG')->get();
        $this->assertCount(1, $hidup);
        $this->assertSame($baru->getKey(), $hidup->first()?->getKey());
    }

    public function test_dua_baris_hidup_dengan_kode_sama_tetap_ditolak(): void
    {
        [$tenant, $class] = $this->tenantWithClass();

        $this->unit($tenant, $class, 'KG', 'Kilogram');

        try {
            $this->unit($tenant, $class, 'KG', 'Kilogram kembar');
        } catch (QueryException $e) {
            $this->assertStringContainsString('units_of_measure_tenant_id_code_unique', $e->getMessage());

            return;
        }

        $this->fail('Dua baris hidup dengan kode yang sama seharusnya ditolak database.');
    }

    public function test_kode_yang_sama_pada_tenant_berbeda_tetap_boleh(): void
    {
        [$tenantA, $classA] = $this->tenantWithClass();
        [$tenantB, $classB] = $this->tenantWithClass();

        $this->unit($tenantA, $classA, 'KG', 'Kilogram');
        $this->unit($tenantB, $classB, 'KG', 'Kilogram');

        $this->assertSame(2, UnitOfMeasure::query()->where('code', 'KG')->count());
    }

    /** @return array{string,string} */
    private function tenantWithClass(): array
    {
        $tenant = (string) Str::ulid();
        $class = (string) Str::ulid();
        $now = now();

        DB::table('uom_classes')->insert([
            'id' => $class,
            'tenant_id' => $tenant,
            'code' => 'MASS',
            'name' => 'Massa',
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$tenant, $class];
    }

    private function unit(string $tenant, string $class, string $code, string $name): UnitOfMeasure
    {
        return UnitOfMeasure::query()->create([
            'tenant_id' => $tenant,
            'uom_class_id' => $class,
            'code' => $code,
            'name' => $name,
            'decimal_places' => 3,
            'active' => true,
        ]);
    }
}
