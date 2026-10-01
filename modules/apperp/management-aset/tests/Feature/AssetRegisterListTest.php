<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\PelaksanaUntukTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Reporting\Lists\AssetRegisterList;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use RuntimeException;
use Tests\TestCase;

/**
 * Register aset sebagai daftar yang dapat diekspor Core (K-27): hak dan cakupan organisasinya sama dengan
 * `GET /aset`. Dipanggil seperti Core memanggilnya, dengan konteks sebagai argumen dan tenant terikat.
 */
class AssetRegisterListTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
    }

    public function test_rows_follow_the_granted_operating_units_and_the_search_like_the_screen(): void
    {
        $unitA = (string) Str::ulid();
        $unitB = (string) Str::ulid();
        $group = $this->master('aset_m_group_aset', 'Elektronik', 'GRPA-R1');
        $jenis = $this->master('aset_m_jenis_aset', 'Laptop', 'JNSA-R1');
        $this->asset('AST-R1', 'Laptop unit A', $unitA, $group, $jenis);
        $this->asset('AST-R2', 'Printer unit A', $unitA, $group, $jenis);
        $this->asset('AST-R3', 'Laptop unit B', $unitB, $group, $jenis);
        $source = app(AssetRegisterList::class);

        $unitAOnly = $this->context(['management-aset.aset.read'], [$unitA]);
        $this->assertSame(2, $this->bound(fn () => $source->count($unitAOnly, [])));
        $this->assertSame(['AST-R1', 'AST-R2'], array_column($this->bound(fn () => iterator_to_array($source->rows($unitAOnly, [], ['column' => 'kode', 'direction' => 'asc']), false)), 'kode'));
        $this->assertSame(['AST-R1'], array_column($this->bound(fn () => iterator_to_array($source->rows($unitAOnly, ['q' => 'laptop'], null), false)), 'kode'));

        $all = $this->context(['management-aset.aset.read'], null);
        $rows = $this->bound(fn () => iterator_to_array($source->rows($all, [], ['column' => 'kode', 'direction' => 'desc']), false));
        $this->assertSame(['AST-R3', 'AST-R2', 'AST-R1'], array_column($rows, 'kode'));
        $this->assertSame(['kode' => 'AST-R3', 'nama' => 'Laptop unit B', 'serial' => null, 'group' => 'Elektronik', 'jenis' => 'Laptop', 'lokasi' => null, 'nilai' => '1000000.00', 'status' => 'Diterima'], $rows[0]);

        $this->expectException(RuntimeException::class);
        $this->bound(fn () => $source->count($this->context(['management-aset.pemeliharaan-aset.read'], null), []));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function bound(callable $call): mixed
    {
        return app(PelaksanaUntukTenant::class)->jalankanUntuk($this->tenantId, $call);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>|null  $units  Null berarti seluruh cakupan.
     * @return array<string, mixed>
     */
    private function context(array $permissions, ?array $units): array
    {
        return [
            'tenant_id' => $this->tenantId, 'legal_entity_id' => $this->legalEntityId, 'org_unit_id' => null,
            'user_id' => (string) Str::ulid(), 'permissions' => $permissions, 'timezone' => 'UTC',
            'data_policies' => ['management-aset.asset-responsibility' => $units === null
                ? ['all' => true, 'scope_grants' => []]
                : ['all' => false, 'scope_grants' => [['legal_entity_id' => $this->legalEntityId, 'operating_unit_ids' => $units]]]],
        ];
    }

    private function asset(string $kode, string $nama, string $unit, string $group, string $jenis): void
    {
        DB::table('aset_tr_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode,
            'nama' => $nama, 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $unit,
            'group_aset_id' => $group, 'jenis_aset_id' => $jenis, 'acquired_on' => '2026-01-01',
            'acquisition_value' => 1000000, 'currency_code' => 'IDR', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function master(string $table, string $nama, string $kode, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $kode, 'nama' => $nama, 'aktif' => true, ...$extra, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
