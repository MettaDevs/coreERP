<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

class AssetLocationTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->configureCoreErpContext();
        $number = 0;
        Http::fake(function () use (&$number) { return Http::response(['data' => ['number' => 'LOCA-'.str_pad((string) ++$number, 6, '0', STR_PAD_LEFT)]], 200); });
    }

    public function test_location_keeps_hierarchy_and_rejects_cycles_or_archiving_a_parent(): void
    {
        $permissions = ['management-aset.lokasi-aset.read', 'management-aset.lokasi-aset.create', 'management-aset.lokasi-aset.update', 'management-aset.lokasi-aset.archive'];
        $root = $this->withHeaders($this->contextHeaders($this->tenantId, $permissions))->withHeader('Idempotency-Key', 'location-root')->postJson('/api/v1/lokasi-aset', ['nama' => 'Kantor pusat'])->assertCreated()->json('data');
        $child = $this->withHeaders($this->contextHeaders($this->tenantId, $permissions))->withHeader('Idempotency-Key', 'location-child')->postJson('/api/v1/lokasi-aset', ['nama' => 'Lantai satu', 'parent_id' => $root['id']])->assertCreated()->assertJsonPath('data.parent.id', $root['id'])->json('data');

        $this->withHeaders($this->contextHeaders($this->tenantId, $permissions))->patchJson('/api/v1/lokasi-aset/'.$root['id'], ['parent_id' => $child['id']])->assertStatus(422);
        $this->withHeaders($this->contextHeaders($this->tenantId, $permissions))->deleteJson('/api/v1/lokasi-aset/'.$root['id'])->assertConflict();
    }
}
