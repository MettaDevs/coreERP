<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Support\AssetConditionPolicy;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

class AssetTypeConditionTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    private const PERMISSIONS = ['management-aset.jenis-aset.read', 'management-aset.jenis-aset.update', 'management-aset.kondisi-aset.read'];

    private const BASE = '/api/modules/management-aset/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
    }

    public function test_choices_are_shared_by_types_and_removal_is_archived(): void
    {
        $type = $this->seedMaster('aset_m_jenis_aset', 'Jenis uji');
        $otherType = $this->seedMaster('aset_m_jenis_aset', 'Jenis lain');
        $condition = $this->seedMaster('aset_m_kondisi_aset', 'Kondisi uji');
        $other = $this->seedMaster('aset_m_kondisi_aset', 'Kondisi lain');
        $url = self::BASE.'/jenis-aset/'.$type.'/conditions';

        $this->sebagaiPengguna($this->tenantId, self::PERMISSIONS)->getJson($url)
            ->assertOk()->assertJsonCount(2, 'data.remaining')->assertJsonCount(0, 'data.selected');
        $this->putJson($url, ['version' => 1, 'condition_ids' => [$condition]])
            ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('data.selected.0.id', $condition);
        $this->putJson(self::BASE.'/jenis-aset/'.$otherType.'/conditions', ['version' => 1, 'condition_ids' => [$condition]])->assertOk();
        $this->getJson(self::BASE.'/kondisi-aset?jenis_aset_id='.$type.'&aktif=true')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->putJson($url, ['version' => 1, 'condition_ids' => [$other]])->assertConflict();
        $this->putJson($url, ['version' => 2, 'condition_ids' => []])->assertOk();
        $this->assertSame(1, DB::table('aset_m_jenis_aset_kondisi')->where('jenis_aset_id', $type)->whereNotNull('deleted_at')->count());
        $this->getJson(self::BASE.'/kondisi-aset?jenis_aset_id='.$type.'&aktif=true')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson($url, ['version' => 3, 'condition_ids' => [$condition]])->assertOk();
        $this->assertSame(2, DB::table('aset_m_jenis_aset_kondisi')->where('jenis_aset_id', $type)->count());
    }

    public function test_permission_tenant_and_active_condition_are_enforced(): void
    {
        $type = $this->seedMaster('aset_m_jenis_aset', 'Jenis uji');
        $condition = $this->seedMaster('aset_m_kondisi_aset', 'Kondisi uji');
        $url = self::BASE.'/jenis-aset/'.$type.'/conditions';
        $this->sebagaiPengguna($this->tenantId, ['management-aset.jenis-aset.update'])
            ->putJson($url, ['version' => 1, 'condition_ids' => [$condition]])->assertForbidden();
        $this->sebagaiPengguna($this->tenantId, self::PERMISSIONS)
            ->putJson($url, ['condition_ids' => [$condition]])->assertStatus(428);
        DB::table('aset_m_kondisi_aset')->where('id', $condition)->update(['aktif' => false]);
        $this->putJson($url, ['version' => 1, 'condition_ids' => [$condition]])->assertUnprocessable();
        $otherTenant = $this->buatTenantUji();
        $this->sebagaiPengguna($otherTenant, self::PERMISSIONS)->getJson($url)->assertNotFound();
        $this->tenantId = $otherTenant;
        $otherType = $this->seedMaster('aset_m_jenis_aset', 'Jenis lain');
        $this->putJson(self::BASE.'/jenis-aset/'.$otherType.'/conditions', ['version' => 1, 'condition_ids' => [$condition]])->assertUnprocessable();
    }

    public function test_validation_rejects_condition_outside_the_selected_type(): void
    {
        $type = $this->seedMaster('aset_m_jenis_aset', 'Jenis uji');
        $condition = $this->seedMaster('aset_m_kondisi_aset', 'Kondisi uji');
        $other = $this->seedMaster('aset_m_kondisi_aset', 'Kondisi lain');
        $this->sebagaiPengguna($this->tenantId, self::PERMISSIONS)
            ->putJson(self::BASE.'/jenis-aset/'.$type.'/conditions', ['version' => 1, 'condition_ids' => [$condition]])->assertOk();
        AssetConditionPolicy::validate($type, $condition);
        AssetConditionPolicy::validate($type, null);
        $this->expectException(ValidationException::class);
        AssetConditionPolicy::validate($type, $other);
    }

    private function seedMaster(string $table, string $name): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => strtoupper(Str::random(8)), 'nama' => $name, 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
