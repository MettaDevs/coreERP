<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

class AssetLocationManualCodeTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    private const URL = '/api/modules/management-aset/v1/lokasi-aset';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->configureSequence($this->tenantId, true);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.lokasi-aset.create', 'management-aset.lokasi-aset.update']);
    }

    private function configureSequence(string $tenantId, bool $allowManual): void
    {
        $referenceId = DB::table('app_number_sequence_references')->where('code', 'management-aset.lokasi-aset')->value('id');
        DB::table('tenant_number_sequences')->where('tenant_id', $tenantId)->where('reference_id', $referenceId)->update([
            'allow_manual' => $allowManual,
            'segments' => json_encode([['type' => 'constant', 'value' => 'LOCA'], ['type' => 'number', 'length' => 5]], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_manual_code_replays_and_a_changed_code_conflicts(): void
    {
        $this->getJson(self::URL.'/number-policy')->assertOk()->assertJsonPath('data.allow_manual', true);
        $payload = ['nama' => 'Lokasi uji', 'kode' => 'LOCA00001'];
        $this->withHeader('Idempotency-Key', 'location:'.Str::ulid());
        $created = $this->postJson(self::URL, $payload)->assertCreated()->assertJsonPath('data.kode', 'LOCA00001');
        $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
        $this->postJson(self::URL, [...$payload, 'kode' => 'LOCA00002'])->assertConflict();
        $this->patchJson(self::URL.'/'.$created->json('data.id'), ['nama' => 'Nama baru', 'kode' => 'LOCA00002', 'version' => $created->json('data.version')])
            ->assertOk()->assertJsonPath('data.kode', 'LOCA00001');
        $this->assertSame(1, $this->jumlahNomorTerbit());
        $this->assertDatabaseHas('number_sequence_issues', ['formatted_value' => 'LOCA00001', 'is_manual' => true]);
        $this->withHeader('Idempotency-Key', 'location:'.Str::ulid())->postJson(self::URL, ['nama' => 'Otomatis', 'kode' => ''])
            ->assertCreated()->assertJsonPath('data.kode', 'LOCA00002');
    }

    public function test_disallowed_or_invalid_manual_codes_do_not_issue_numbers(): void
    {
        $this->withHeader('Idempotency-Key', 'location:'.Str::ulid());
        $this->postJson(self::URL, ['nama' => 'Lokasi uji', 'kode' => 'INVALID'])->assertUnprocessable();
        $this->postJson(self::URL, ['nama' => 'Lokasi uji', 'kode' => str_repeat('A', 51)])->assertUnprocessable();
        $this->configureSequence($this->tenantId, false);
        $this->getJson(self::URL.'/number-policy')->assertOk()->assertJsonPath('data.allow_manual', false);
        $this->postJson(self::URL, ['nama' => 'Lokasi uji', 'kode' => 'LOCA00001'])->assertUnprocessable();
        $this->assertSame(0, $this->jumlahNomorTerbit());
        $this->assertDatabaseCount('aset_m_lokasi_aset', 0);
        $this->postJson(self::URL, ['nama' => 'Otomatis'])->assertCreated()->assertJsonPath('data.kode', 'LOCA00001');
    }

    public function test_duplicate_is_rejected_in_one_tenant_but_allowed_in_another(): void
    {
        $payload = ['nama' => 'Lokasi uji', 'kode' => 'LOCA00042'];
        $this->withHeader('Idempotency-Key', 'location:'.Str::ulid())->postJson(self::URL, $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', 'location:'.Str::ulid())->postJson(self::URL, $payload)->assertUnprocessable();
        $otherTenant = $this->buatTenantUji();
        $this->configureSequence($otherTenant, true);
        $this->sebagaiPengguna($otherTenant, ['management-aset.lokasi-aset.create'])
            ->withHeader('Idempotency-Key', 'location:'.Str::ulid())->postJson(self::URL, $payload)->assertCreated();
        $this->assertSame(2, $this->jumlahNomorTerbit());
    }

    public function test_number_policy_requires_create_permission(): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.lokasi-aset.read'])
            ->getJson(self::URL.'/number-policy')->assertForbidden();
    }

    public function test_existing_location_code_without_an_issue_is_rejected(): void
    {
        DB::table('aset_m_lokasi_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId,
            'creation_key' => 'fixture:'.Str::ulid(), 'kode' => 'LOCA00042', 'nama' => 'Lokasi lama',
        ]);
        $this->withHeader('Idempotency-Key', 'location:'.Str::ulid())
            ->postJson(self::URL, ['nama' => 'Lokasi baru', 'kode' => 'LOCA00042'])->assertUnprocessable();
        $this->assertSame(0, $this->jumlahNomorTerbit());
    }

    public function test_document_code_collision_rolls_back_number_issuance(): void
    {
        DB::table('aset_m_lokasi_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId,
            'creation_key' => 'fixture:'.Str::ulid(), 'kode' => 'LOCA00001', 'nama' => 'Lokasi lama',
        ]);
        $this->withHeader('Idempotency-Key', 'location:'.Str::ulid())
            ->postJson(self::URL, ['nama' => 'Lokasi otomatis'])->assertUnprocessable();
        $this->assertSame(0, $this->jumlahNomorTerbit());
        $this->assertDatabaseCount('aset_m_lokasi_aset', 1);
    }
}
