<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

class MasterDataAsetTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    private int $issuedNumbers = 0;

    private int $requestCounter = 0;

    /** Http::fake menambah stub, tidak menggantinya, jadi format diubah lewat properti ini. */
    private string $numberFormat = 'NS-%06d';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->configureCoreErpContext();
        Http::fake(function () {
            $this->issuedNumbers++;

            return Http::response(['data' => ['number' => sprintf($this->numberFormat, $this->issuedNumbers)]]);
        });
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function standaloneMasters(): array
    {
        return [
            'group aset' => ['group-aset', 'm_group_aset'],
            'kondisi aset' => ['kondisi-aset', 'm_kondisi_aset'],
            'pabrikan aset' => ['pabrikan-aset', 'm_pabrikan_aset'],
            'item checklist maintenance' => ['item-checklist-maintenance', 'm_item_checklist_maintenance'],
            'analisa maintenance' => ['analisa-maintenance', 'm_analisa_maintenance'],
        ];
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function chainedMasters(): array
    {
        return [
            'entitas aset' => ['entitas-aset', 'jenis_aset_id'],
            'kategori aset' => ['kategori-aset', 'group_aset_id'],
            'jenis aset' => ['jenis-aset', 'kategori_aset_id'],
        ];
    }

    #[DataProvider('standaloneMasters')]
    public function test_master_mandiri_menjalankan_crud_tanpa_induk(string $resource, string $table): void
    {
        $created = $this->createRecord($resource, ['nama' => 'Data Pertama', 'keterangan' => 'Contoh'])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'NS-000001')
            ->assertJsonPath('data.nama', 'Data Pertama')
            ->assertJsonPath('data.aktif', true);
        $id = $created->json('data.id');

        // Master mandiri tidak boleh membawa kolom induk apa pun.
        foreach (['entitas_aset_id', 'group_aset_id', 'kategori_aset_id'] as $parentColumn) {
            $this->assertArrayNotHasKey($parentColumn, $created->json('data'));
        }

        Http::assertSent(fn ($request) => str_contains(
            $request->url(),
            '/api/internal/v1/number-sequences/management-aset.'.$resource.'/issue',
        ));

        $this->request($resource, 'get', '/api/v1/'.$resource.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);

        $this->request($resource, 'patch', '/api/v1/'.$resource.'/'.$id, ['nama' => 'Data Diubah', 'keterangan' => ''])
            ->assertOk()
            ->assertJsonPath('data.nama', 'Data Diubah')
            ->assertJsonPath('data.keterangan', null);

        $this->request($resource, 'delete', '/api/v1/'.$resource.'/'.$id)->assertNoContent();
        $this->assertSoftDeleted($table, ['id' => $id, 'tenant_id' => $this->tenantId]);
        $this->request($resource, 'get', '/api/v1/'.$resource)->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[DataProvider('chainedMasters')]
    public function test_master_berantai_wajib_membawa_induk(string $resource, string $parentColumn): void
    {
        $this->createRecord($resource, ['nama' => 'Tanpa induk'])
            ->assertStatus(422)
            ->assertJsonValidationErrors($parentColumn);

        // Validasi induk berjalan sebelum nomor diminta, jadi Core tidak pernah dihubungi.
        Http::assertNothingSent();
    }

    public function test_rantai_klasifikasi_menyimpan_dan_menyajikan_induknya(): void
    {
        $chain = $this->buildChain();

        $entitas = $this->request('entitas-aset', 'get', '/api/v1/entitas-aset/'.$chain['entitas-aset'])->assertOk();
        $entitas->assertJsonPath('data.jenis_aset_id', $chain['jenis-aset']);
        $entitas->assertJsonPath('data.jenis_aset.id', $chain['jenis-aset']);
        $entitas->assertJsonPath('data.jenis_aset.nama', 'Excavator 20 Ton');

        $this->request('kategori-aset', 'get', '/api/v1/kategori-aset/'.$chain['kategori-aset'])
            ->assertOk()
            ->assertJsonPath('data.group_aset.id', $chain['group-aset']);

        $this->request('jenis-aset', 'get', '/api/v1/jenis-aset/'.$chain['jenis-aset'])
            ->assertOk()
            ->assertJsonPath('data.kategori_aset.id', $chain['kategori-aset']);
    }

    public function test_daftar_anak_dapat_disaring_menurut_induk(): void
    {
        $chain = $this->buildChain();
        $lain = $this->createRecord('jenis-aset', ['nama' => 'Jenis Lain', 'kategori_aset_id' => $chain['kategori-aset']])->assertCreated()->json('data.id');
        $this->createRecord('entitas-aset', ['nama' => 'Entitas Lain', 'jenis_aset_id' => $lain])->assertCreated();

        $this->request('entitas-aset', 'get', '/api/v1/entitas-aset')->assertOk()->assertJsonPath('meta.total', 2);
        $this->request('entitas-aset', 'get', '/api/v1/entitas-aset?jenis_aset_id='.$chain['jenis-aset'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $chain['entitas-aset']);
    }

    public function test_induk_dari_tenant_lain_ditolak_dan_tidak_menerbitkan_nomor(): void
    {
        $foreignTenant = (string) Str::ulid();
        $foreignGroup = $this->createRecord('group-aset', ['nama' => 'Group Tenant Lain'], tenantId: $foreignTenant)
            ->assertCreated()
            ->json('data.id');
        $foreignCategory = $this->createRecord('kategori-aset', ['nama' => 'Kategori Tenant Lain', 'group_aset_id' => $foreignGroup], tenantId: $foreignTenant)->assertCreated()->json('data.id');
        $foreignJenis = $this->createRecord('jenis-aset', ['nama' => 'Jenis Tenant Lain', 'kategori_aset_id' => $foreignCategory], tenantId: $foreignTenant)->assertCreated()->json('data.id');
        Http::assertSentCount(3);

        $this->createRecord('entitas-aset', ['nama' => 'Entitas Curian', 'jenis_aset_id' => $foreignJenis])
            ->assertStatus(422)
            ->assertJsonValidationErrors('jenis_aset_id');

        $this->assertDatabaseCount('m_entitas_aset', 0);
        Http::assertSentCount(3);
    }

    public function test_induk_dari_tenant_lain_juga_ditolak_saat_mengubah_anak(): void
    {
        $chain = $this->buildChain();
        $foreignTenant = (string) Str::ulid();
        $foreignGroup = $this->createRecord('group-aset', ['nama' => 'Group Tenant Lain'], tenantId: $foreignTenant)
            ->assertCreated()
            ->json('data.id');
        $foreignCategory = $this->createRecord('kategori-aset', ['nama' => 'Kategori Tenant Lain', 'group_aset_id' => $foreignGroup], tenantId: $foreignTenant)->assertCreated()->json('data.id');
        $foreignJenis = $this->createRecord('jenis-aset', ['nama' => 'Jenis Tenant Lain', 'kategori_aset_id' => $foreignCategory], tenantId: $foreignTenant)->assertCreated()->json('data.id');

        $this->request('entitas-aset', 'patch', '/api/v1/entitas-aset/'.$chain['entitas-aset'], ['jenis_aset_id' => $foreignJenis])
            ->assertStatus(422)
            ->assertJsonValidationErrors('jenis_aset_id');

        $this->assertDatabaseHas('m_entitas_aset', [
            'id' => $chain['entitas-aset'],
            'jenis_aset_id' => $chain['jenis-aset'],
        ]);
    }

    public function test_induk_yang_sudah_diarsipkan_tidak_dapat_dipilih(): void
    {
        $jenis = $this->createRecord('jenis-aset', ['nama' => 'Jenis Arsip', 'kategori_aset_id' => $this->buildChain()['kategori-aset']])->assertCreated()->json('data.id');
        $this->request('jenis-aset', 'delete', '/api/v1/jenis-aset/'.$jenis)->assertNoContent();

        $this->createRecord('entitas-aset', ['nama' => 'Entitas Baru', 'jenis_aset_id' => $jenis])
            ->assertStatus(422)
            ->assertJsonValidationErrors('jenis_aset_id');
    }

    public function test_anak_dapat_dipindahkan_ke_induk_lain_pada_tenant_yang_sama(): void
    {
        $chain = $this->buildChain();
        $tujuan = $this->createRecord('jenis-aset', ['nama' => 'Jenis Tujuan', 'kategori_aset_id' => $chain['kategori-aset']])->assertCreated()->json('data.id');

        $this->request('entitas-aset', 'patch', '/api/v1/entitas-aset/'.$chain['entitas-aset'], ['jenis_aset_id' => $tujuan])
            ->assertOk()
            ->assertJsonPath('data.jenis_aset_id', $tujuan)
            ->assertJsonPath('data.jenis_aset.nama', 'Jenis Tujuan');
    }

    public function test_induk_tidak_dapat_diarsipkan_selama_anaknya_masih_aktif(): void
    {
        $chain = $this->buildChain();

        $this->request('group-aset', 'delete', '/api/v1/group-aset/'.$chain['group-aset'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'referenced_by_children');
        $this->request('kategori-aset', 'delete', '/api/v1/kategori-aset/'.$chain['kategori-aset'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'referenced_by_children');

        $this->request('entitas-aset', 'delete', '/api/v1/entitas-aset/'.$chain['entitas-aset'])->assertNoContent();
        $this->request('jenis-aset', 'delete', '/api/v1/jenis-aset/'.$chain['jenis-aset'])->assertNoContent();
        $this->request('kategori-aset', 'delete', '/api/v1/kategori-aset/'.$chain['kategori-aset'])->assertNoContent();
        $this->request('group-aset', 'delete', '/api/v1/group-aset/'.$chain['group-aset'])->assertNoContent();
    }

    public function test_hak_pada_satu_master_tidak_memberi_hak_pada_master_lain(): void
    {
        $chain = $this->buildChain();
        $onlyGroupRead = $this->withHeaders($this->contextHeaders($this->tenantId, ['management-aset.group-aset.read']));

        $onlyGroupRead->getJson('/api/v1/group-aset')->assertOk();
        $onlyGroupRead->getJson('/api/v1/kategori-aset')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $onlyGroupRead->getJson('/api/v1/jenis-aset')->assertForbidden();
        $onlyGroupRead->getJson('/api/v1/kondisi-aset')->assertForbidden();
        $onlyGroupRead->withHeader('Idempotency-Key', 'tanpa-hak-create')
            ->postJson('/api/v1/entitas-aset', ['nama' => 'Entitas', 'jenis_aset_id' => $chain['jenis-aset']])
            ->assertForbidden();
        $onlyGroupRead->patchJson('/api/v1/group-aset/'.$chain['group-aset'], ['nama' => 'Group Diubah'])->assertForbidden();
        $onlyGroupRead->deleteJson('/api/v1/group-aset/'.$chain['group-aset'])->assertForbidden();
    }

    public function test_setiap_master_memakai_reference_nomornya_sendiri(): void
    {
        $this->buildChain();

        foreach (['entitas-aset', 'group-aset', 'kategori-aset', 'jenis-aset'] as $resource) {
            Http::assertSent(fn ($request) => $request->url() === 'http://core.test/api/internal/v1/number-sequences/management-aset.'.$resource.'/issue'
                && str_starts_with((string) $request['idempotency_key'], $resource.':')
                && $request->hasHeader('X-CoreERP-Tenant-Id', $this->tenantId));
        }
        Http::assertSentCount(4);
    }

    public function test_kode_dari_klien_diabaikan_dan_selalu_berasal_dari_core(): void
    {
        $this->createRecord('kondisi-aset', ['nama' => 'Baik', 'kode' => 'DIPAKSA-01'])
            ->assertCreated()
            ->assertJsonPath('data.kode', 'NS-000001');
    }

    public function test_daftar_master_tidak_pernah_menampilkan_data_tenant_lain(): void
    {
        $this->createRecord('kondisi-aset', ['nama' => 'Baik'])->assertCreated();
        $foreignTenant = (string) Str::ulid();
        $foreign = $this->createRecord('kondisi-aset', ['nama' => 'Rusak'], tenantId: $foreignTenant)
            ->assertCreated()
            ->json('data.id');

        $this->request('kondisi-aset', 'get', '/api/v1/kondisi-aset')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nama', 'Baik');
        $this->request('kondisi-aset', 'get', '/api/v1/kondisi-aset/'.$foreign)->assertNotFound();
    }

    public function test_kunci_pembuatan_milik_record_yang_diarsipkan_tetap_direplay(): void
    {
        // Unique (tenant_id, creation_key) tetap mengikat record yang sudah diarsipkan.
        // Retry dengan kunci itu harus mengembalikan record yang sama, bukan 500.
        $key = 'kunci-dipakai-ulang';
        $created = $this->postWithKey('kondisi-aset', ['nama' => 'Baik'], $key)->assertCreated();
        $this->request('kondisi-aset', 'delete', '/api/v1/kondisi-aset/'.$created->json('data.id'))->assertNoContent();

        $this->postWithKey('kondisi-aset', ['nama' => 'Baik'], $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $created->json('data.id'));

        Http::assertSentCount(1);
        $this->assertDatabaseCount('m_kondisi_aset', 1);
    }

    public function test_retry_tetap_direplay_walau_aktif_dikirim_sebagai_angka(): void
    {
        $key = 'aktif-sebagai-angka';
        $first = $this->postWithKey('kondisi-aset', ['nama' => 'Baik', 'aktif' => 1], $key)->assertCreated();

        $this->postWithKey('kondisi-aset', ['nama' => 'Baik', 'aktif' => 1], $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
    }

    public function test_filter_daftar_menghormati_nilai_tepi_yang_sah(): void
    {
        $this->createRecord('kondisi-aset', ['nama' => 'Baik', 'keterangan' => '0'])
            ->assertCreated()
            ->assertJsonPath('data.keterangan', '0');
        $this->createRecord('kondisi-aset', ['nama' => 'Rusak', 'aktif' => false])->assertCreated();

        // `?aktif=` kosong berarti tanpa filter, bukan "hanya yang tidak aktif".
        $this->request('kondisi-aset', 'get', '/api/v1/kondisi-aset?aktif=')->assertOk()->assertJsonPath('meta.total', 2);
        $this->request('kondisi-aset', 'get', '/api/v1/kondisi-aset?aktif=false')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_pencarian_angka_nol_tetap_dipakai_sebagai_kata_kunci(): void
    {
        // Nomor sengaja tanpa digit nol supaya yang tersaring benar-benar berasal dari nama.
        $this->numberFormat = 'PB-9999%d';
        $this->createRecord('pabrikan-aset', ['nama' => 'Merek 0'])->assertCreated();
        $this->createRecord('pabrikan-aset', ['nama' => 'Merek Lain'])->assertCreated();

        $this->request('pabrikan-aset', 'get', '/api/v1/pabrikan-aset?q=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nama', 'Merek 0');
    }

    public function test_kunci_pembuatan_dibatasi_agar_selalu_muat_pada_batas_core(): void
    {
        // Slug terpanjang (26) + ':' + kunci harus tetap <= 160 karakter di sisi Core.
        $this->postWithKey('item-checklist-maintenance', ['nama' => 'Ban depan'], str_repeat('k', 133))
            ->assertCreated();
        $this->postWithKey('item-checklist-maintenance', ['nama' => 'Ban belakang'], str_repeat('k', 134))
            ->assertStatus(422);

        Http::assertSent(fn ($request) => strlen((string) $request['idempotency_key']) <= 160);
    }

    public function test_database_menegakkan_batas_tenant_pada_foreign_key_rantai(): void
    {
        $chain = $this->buildChain();
        $jenis = $chain['jenis-aset'];

        // Menulis langsung ke tabel, melewati validasi aplikasi, agar yang diuji adalah
        // foreign key gabungan (tenant_id, jenis_aset_id) -> (tenant_id, id).
        $this->assertTrue($this->insertEntitasDirectly($this->tenantId, $jenis));
        $this->assertDatabaseCount('m_entitas_aset', 2);

        $this->assertFalse($this->insertEntitasDirectly((string) Str::ulid(), $jenis), 'induk milik tenant lain harus ditolak database');
        $this->assertFalse($this->insertEntitasDirectly($this->tenantId, (string) Str::ulid()), 'induk yang tidak ada harus ditolak database');
        $this->assertDatabaseCount('m_entitas_aset', 2);

        // Arsip adalah soft delete sehingga referensi tidak pernah terputus; hard delete tetap ditahan.
        $this->assertFalse($this->hardDelete('m_jenis_aset', $jenis), 'hard delete induk yang masih direferensikan harus ditahan');
        $this->assertDatabaseHas('m_jenis_aset', ['id' => $jenis, 'deleted_at' => null]);
    }

    /** Kode dan creation_key selalu unik per pemanggilan agar kegagalan hanya dapat berasal dari foreign key. */
    private function insertEntitasDirectly(string $tenantId, string $parentId): bool
    {
        $suffix = ++$this->requestCounter;

        try {
            return DB::table('m_entitas_aset')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'creation_key' => 'langsung-'.$suffix,
                'jenis_aset_id' => $parentId,
                'kode' => sprintf('EA-L%05d', $suffix),
                'nama' => 'Entitas langsung',
                'aktif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            return false;
        }
    }

    private function hardDelete(string $table, string $id): bool
    {
        try {
            DB::table($table)->where('id', $id)->delete();

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * Rantai lengkap group -> kategori -> jenis -> entitas pada tenant aktif.
     *
     * @return array<string, string>
     */
    private function buildChain(): array
    {
        $group = $this->createRecord('group-aset', ['nama' => 'Alat Berat'])
            ->assertCreated()->json('data.id');
        $kategori = $this->createRecord('kategori-aset', ['nama' => 'Excavator', 'group_aset_id' => $group])
            ->assertCreated()->json('data.id');
        $jenis = $this->createRecord('jenis-aset', ['nama' => 'Excavator 20 Ton', 'kategori_aset_id' => $kategori])
            ->assertCreated()->json('data.id');
        $entitas = $this->createRecord('entitas-aset', ['nama' => 'Entitas Induk', 'jenis_aset_id' => $jenis])
            ->assertCreated()->json('data.id');

        return [
            'entitas-aset' => $entitas,
            'group-aset' => $group,
            'kategori-aset' => $kategori,
            'jenis-aset' => $jenis,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function createRecord(string $resource, array $payload, ?string $tenantId = null): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($tenantId ?? $this->tenantId, $this->permissionsFor($resource)))
            ->withHeader('Idempotency-Key', $this->creationKeyFor($resource))
            ->postJson('/api/v1/'.$resource, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function postWithKey(string $resource, array $payload, string $key): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor($resource)))
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/'.$resource, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function request(string $resource, string $method, string $uri, array $payload = []): TestResponse
    {
        return $this->withHeaders($this->contextHeaders($this->tenantId, $this->permissionsFor($resource)))
            ->json(strtoupper($method), $uri, $payload);
    }

    /** Kunci unik per percobaan pembuatan, tetap diawali slug resource. */
    private function creationKeyFor(string $resource): string
    {
        return $resource.'-'.++$this->requestCounter;
    }

    /** @return list<string> */
    private function permissionsFor(string $resource): array
    {
        return array_map(
            fn (string $action): string => 'management-aset.'.$resource.'.'.$action,
            ['read', 'create', 'update', 'archive'],
        );
    }
}
