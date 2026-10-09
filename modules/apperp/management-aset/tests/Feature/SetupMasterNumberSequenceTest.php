<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\TenantRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Services\ProvisionIndonesiaStarterData;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SetupMasterNumberSequenceTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
    }

    /** @return array<string, array{string, string}> */
    public static function setupMasters(): array
    {
        return [
            'group' => ['group-aset', 'aset_m_group_aset'],
            'book' => ['buku-penyusutan', 'aset_m_buku_penyusutan'],
        ];
    }

    #[DataProvider('setupMasters')]
    public function test_new_codes_follow_the_configured_sequence_and_ignore_client_codes(string $resource, string $table): void
    {
        $referenceId = DB::table('app_number_sequence_references')->where('code', 'management-aset.'.$resource)->value('id');
        DB::table('tenant_number_sequences')->where('tenant_id', $this->tenantId)->where('reference_id', $referenceId)->update([
            'minimum_number' => 42,
            'segments' => json_encode([['type' => 'constant', 'value' => 'TEST'], ['type' => 'number', 'length' => 5]], JSON_THROW_ON_ERROR),
        ]);

        $key = 'setup:'.Str::ulid();
        $url = '/api/modules/management-aset/v1/'.$resource;
        $client = $this->sebagaiPengguna($this->tenantId, ['management-aset.'.$resource.'.create', 'management-aset.'.$resource.'.update']);
        $created = $client->withHeader('Idempotency-Key', $key)->postJson($url, ['nama' => 'Master uji'])
            ->assertCreated()->assertJsonPath('data.kode', 'TEST00042');
        $id = $created->json('data.id');

        $client->withHeader('Idempotency-Key', $key)->postJson($url, ['nama' => 'Master uji'])
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.kode', 'TEST00042');
        $client->withHeader('Idempotency-Key', 'setup:'.Str::ulid())->postJson($url, ['nama' => 'Master berikutnya', 'kode' => 'BYPASS'])
            ->assertCreated()->assertJsonPath('data.kode', 'TEST00043');
        $client->patchJson($url.'/'.$id, ['kode' => 'BYPASS', 'nama' => 'Nama baru', 'version' => $created->json('data.version')])
            ->assertOk()->assertJsonPath('data.kode', 'TEST00042');

        $this->assertDatabaseHas($table, ['id' => $id, 'tenant_id' => $this->tenantId, 'kode' => 'TEST00042']);
        $this->assertSame(2, $this->jumlahNomorTerbit());
    }

    #[DataProvider('setupMasters')]
    public function test_an_inactive_sequence_cannot_be_bypassed_with_a_client_code(string $resource, string $table): void
    {
        $referenceId = DB::table('app_number_sequence_references')->where('code', 'management-aset.'.$resource)->value('id');
        DB::table('tenant_number_sequences')->where('tenant_id', $this->tenantId)->where('reference_id', $referenceId)->update(['status' => 'inactive']);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.'.$resource.'.create'])
            ->withHeader('Idempotency-Key', 'setup:'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/'.$resource, ['nama' => 'Master uji', 'kode' => 'BYPASS'])
            ->assertUnprocessable();

        $this->assertSame(0, DB::table($table)->where('tenant_id', $this->tenantId)->count());
        $this->assertSame(0, $this->jumlahNomorTerbit());
    }

    public function test_starter_books_use_the_sequence_and_keep_existing_codes_on_replay(): void
    {
        $runner = $this->app->make(TenantRunner::class);
        $provision = fn (): array => $this->app->make(ProvisionIndonesiaStarterData::class)->forTenant($this->tenantId);
        $runner->runFor($this->tenantId, $provision);
        $books = DB::table('aset_m_buku_penyusutan')->where('tenant_id', $this->tenantId)->orderBy('creation_key')->pluck('kode', 'id')->all();

        $this->assertCount(2, $books);
        foreach ($books as $code) {
            $this->assertStringStartsWith($this->awalanNomor('management-aset.buku-penyusutan').'-', $code);
        }

        $issued = $this->jumlahNomorTerbit();
        $runner->runFor($this->tenantId, $provision);
        $this->assertSame($books, DB::table('aset_m_buku_penyusutan')->where('tenant_id', $this->tenantId)->orderBy('creation_key')->pluck('kode', 'id')->all());
        $this->assertSame($issued, $this->jumlahNomorTerbit());
    }
}
