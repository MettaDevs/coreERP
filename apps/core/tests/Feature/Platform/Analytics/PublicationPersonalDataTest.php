<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Access\Models\RoleAssignment;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Actions\RegisterAppCatalog;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Platform\Analytics\Support\SalesFixture;
use Tests\TestCase;

/**
 * Publikasi tidak pernah membawa data pribadi (KA-05, `docs/todo/analitik/akses-luar.md` *Test yang wajib*),
 * apa pun hak pemiliknya. Bahannya dataset contoh `contoh-a.penjualan`: `nama_pembeli` berkelas data pribadi,
 * `dicatat_oleh_user_id` id pengguna (pseudonim) dengan nama orang sebagai labelnya.
 *
 * - Pemilik yang berhak memakai data pribadi tetap tidak dapat mempublikasikan query yang memakai nama pembeli.
 * - Salinan query yang memuatnya — misalnya ditulis di luar layar — dijawab 409 di metadata dan baris, tanpa
 *   satu pun nama di jawabannya.
 * - Id pengguna boleh keluar sebagai pengelompok, tetapi nama orangnya tidak: di JSON, CSV, maupun metadata.
 * - Kolom data pribadi tidak ditawarkan sebagai saringan terkunci maupun saringan pemanggil.
 *
 * Dilihat merah dengan membuat `PublicationPrincipal::mayUsePersonalData()` memulangkan `true`: publikasi nama
 * pembeli diterima, barisnya memuat nama, dan label pengguna memuat nama pemiliknya.
 */
class PublicationPersonalDataTest extends TestCase
{
    use BuildsAssetTenants, PublishesAnalytics, RefreshDatabase, SalesFixture;

    private User $owner;

    private string $tenant;

    /** @var array{token: string, id: string} */
    private array $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();
        $this->migrateSalesModule();
        $this->registerSalesCatalog();

        $director = $this->business('Toko Data Pribadi', 'direktur@data-pribadi.test');
        $this->tenant = (string) $this->membershipOf($director)->tenant_id;
        DB::table('core_module_installations')->insert([
            'tenant_id' => $this->tenant, 'module_id' => 'contoh-a', 'version' => '0.1.0', 'status' => 'installed',
            'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $legalEntity = $this->organization($this->tenant, 'legal_entity', 'PT Data Pribadi');
        $unit = $this->organization($this->tenant, 'operating_unit', 'Toko pusat');

        // Pemilik publikasi memegang hak data pribadi, seluruh penjualan, dan hak publikasi.
        $this->owner = User::factory()->create(['name' => 'Rahmawati Pencatat']);
        $membership = TenantMembership::query()->create(['tenant_id' => $this->tenant, 'user_id' => $this->owner->id, 'status' => 'active']);
        $this->grantDuties($membership, ['core.analytics.publish', 'core.analytics.analyze', 'core.analytics.personal-data', 'contoh-a.penjualan.inquire']);
        RoleAssignment::query()->where('membership_id', $membership->id)->firstOrFail()->dataPolicyScopes()->create([
            'tenant_id' => $this->tenant, 'policy_code' => 'contoh-a.penjualan-unit', 'legal_entity_id' => null, 'organization_id' => null,
            'hierarchy_id' => null, 'hierarchy_version_id' => null, 'include_descendants' => false, 'valid_from' => now()->subMinute(),
        ]);

        $this->sale($this->tenant, $legalEntity, $unit, '150000.00', buyer: 'Budi Santoso');
        $this->sale($this->tenant, $legalEntity, $unit, '250000.00', buyer: 'Siti Aminah');
        DB::table('contoh_a_tr_penjualan')->where('tenant_id', $this->tenant)->update(['dicatat_oleh_user_id' => $this->owner->id]);

        $this->client = $this->integrationClient($director, 'Gudang data');
    }

    /**
     * Katalog keamanan penjualan module contoh. `app:register-manifest` sengaja melewati module bahan uji, jadi
     * barisnya didaftarkan lewat pendaftar katalog yang sama, dengan isi `manifest/penjualan.yaml`.
     */
    private function registerSalesCatalog(): void
    {
        app(RegisterAppCatalog::class)->handle(
            ['id' => 'contoh-a', 'name' => 'Contoh A', 'description' => null, 'version' => '0.1.0', 'database_name' => null,
                'has_ui' => false, 'navigation' => null, 'repository_url' => null, 'contract_url' => null, 'status' => 'available'],
            [
                'entry_points' => [['code' => 'contoh-a.penjualan.form', 'name' => 'Layar penjualan', 'type' => 'form']],
                'permissions' => [['code' => 'contoh-a.penjualan.read', 'name' => 'Lihat penjualan', 'entry_point' => 'contoh-a.penjualan.form', 'access' => 'read']],
                'privileges' => [['code' => 'contoh-a.penjualan.view', 'name' => 'Lihat penjualan', 'permissions' => ['contoh-a.penjualan.read']]],
                'duties' => [['code' => 'contoh-a.penjualan.inquire', 'name' => 'Lihat penjualan', 'privileges' => ['contoh-a.penjualan.view']]],
            ],
            dataPolicies: [[
                'code' => 'contoh-a.penjualan-unit', 'name' => 'Penjualan menurut unit kerja', 'protected_permissions' => ['contoh-a.penjualan.read'],
                'requires_legal_entity' => true, 'requires_operating_unit' => true, 'allows_descendants' => true,
            ]],
        );
    }

    public function test_a_query_using_a_personal_field_cannot_be_published_even_by_an_owner_with_the_right(): void
    {
        $saved = $this->savedQuery($this->owner, 'Penjualan per pembeli', ['dataset' => self::SALES, 'dimensions' => ['nama_pembeli'], 'measures' => ['count']]);

        $this->publish($this->owner, ['name' => 'Per pembeli', 'saved_query_id' => $saved, 'client_ids' => [$this->client['id']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['saved_query_id' => 'Analisis ini memakai kolom data pribadi, yang tidak pernah dibuka ke sistem lain. Pilih analisis tanpa kolom itu.']);
        $this->assertSame(0, Publication::query()->count());
    }

    public function test_a_stored_snapshot_with_a_personal_field_is_refused_without_a_single_name(): void
    {
        Publication::query()->create([
            'tenant_id' => $this->tenant, 'code' => 'per-pembeli', 'name' => 'Per pembeli', 'owner_user_id' => $this->owner->id,
            'timezone' => 'Asia/Jakarta', 'dataset_code' => self::SALES, 'dataset_version' => 1,
            'query' => ['dataset' => self::SALES, 'dimensions' => ['nama_pembeli'], 'measures' => ['count']],
            'client_ids' => [$this->client['id']], 'formats' => ['json', 'csv'],
        ]);

        foreach (['/per-pembeli', '/per-pembeli/rows', '/per-pembeli/rows?format=csv'] as $path) {
            [$path, $query] = str_contains($path, '?') ? [Str::before($path, '?'), ['format' => 'csv']] : [$path, []];
            $response = $this->feed($this->client['token'], $path, $query)->assertStatus(409)
                ->assertJsonPath('error.code', 'analytics.publication_unavailable');
            $this->assertStringNotContainsString('Budi', (string) $response->getContent());
            $this->assertStringNotContainsString('Siti', (string) $response->getContent());
        }
    }

    public function test_a_user_id_may_leave_as_a_grouping_but_the_persons_name_never_does(): void
    {
        $saved = $this->savedQuery($this->owner, 'Penjualan per pencatat', ['dataset' => self::SALES, 'dimensions' => ['dicatat_oleh_user_id'], 'measures' => ['count']]);
        // Di layar, pemilik yang berhak melihat nama pencatatnya.
        $this->actingAs($this->owner)->getJson('/api/v1/analytics/saved-queries/'.$saved)->assertOk();
        $this->publish($this->owner, [
            'name' => 'Per pencatat', 'saved_query_id' => $saved, 'client_ids' => [$this->client['id']], 'formats' => ['json', 'csv'],
        ])->assertCreated();

        $json = $this->feed($this->client['token'], '/per-pencatat/rows')->assertOk()
            ->assertJsonPath('rows', [['dicatat_oleh_user_id' => $this->owner->id, 'dicatat_oleh_user_id__label' => null, 'count' => 2]]);
        $csv = $this->feed($this->client['token'], '/per-pencatat/rows', ['format' => 'csv'])->assertOk();
        $meta = $this->feed($this->client['token'], '/per-pencatat')->assertOk();
        foreach ([$json, $csv, $meta] as $response) {
            $this->assertStringNotContainsString('Rahmawati', (string) $response->getContent());
        }

        // Kolom data pribadi tidak ditawarkan sebagai saringan, dan tidak diterima bila diketik.
        $this->assertNotContains('nama_pembeli', array_column($meta->json('data.filterable'), 'key'));
        $this->feed($this->client['token'], '/per-pencatat/rows', ['filter' => ['nama_pembeli' => 'Budi*']])
            ->assertUnprocessable()->assertJsonPath('error.field', 'filter.nama_pembeli');
        $publication = Publication::query()->where('code', 'per-pencatat')->firstOrFail();
        $this->actingAs($this->owner)->patchJson("/api/v1/analytics/publications/{$publication->id}", [
            'locked_filters' => ['nama_pembeli' => 'Budi*'],
        ], self::ifMatch($publication->version))->assertUnprocessable()->assertJsonValidationErrors('locked_filters.nama_pembeli');
    }
}
