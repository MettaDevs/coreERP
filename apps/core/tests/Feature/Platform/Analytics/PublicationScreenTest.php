<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Layar Publikasi data dan API-nya (butir 15.2): siapa boleh melihat, membuat, dan mengubah; salinan query yang
 * baru berubah saat pemiliknya menerapkannya; pratinjau dan pilihan nilai saringan terkunci.
 *
 * Aturan yang dijaga: publikasi dihitung sebagai pemiliknya, jadi hanya pemiliknya yang mengubah isi dan melihat
 * pratinjaunya. Pemegang hak publikasi lain menghentikan, mencabut, atau mengambil alih — tidak pernah
 * menjalankan publikasi atas jangkauan orang lain. Dilihat merah dengan membuang `authorizeOwner()` dari
 * `PublicationController::update()` (penerbit lain mengubah saringan terkunci publikasi direktur) dan dengan
 * membuang pemeriksaan pemilik di `preview()`.
 */
class PublicationScreenTest extends TestCase
{
    use BuildsAssetTenants, PublishesAnalytics, RefreshDatabase;

    private User $director;

    private string $tenant;

    private string $legalEntity;

    private string $unitA;

    private string $unitB;

    private string $saved;

    /** @var array{token: string, id: string} */
    private array $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();
        $this->withoutVite();

        $this->director = $this->business('Klinik Layar', 'direktur@layar-publikasi.test');
        $this->tenant = (string) $this->membershipOf($this->director)->tenant_id;
        $this->legalEntity = $this->organization($this->tenant, 'legal_entity', 'PT Layar');
        $this->unitA = $this->organization($this->tenant, 'operating_unit', 'Unit A');
        $this->unitB = $this->organization($this->tenant, 'operating_unit', 'Unit B');
        $this->asset($this->tenant, $this->legalEntity, $this->unitA, '1000');
        $this->asset($this->tenant, $this->legalEntity, $this->unitB, '2000');

        $this->saved = $this->savedQuery($this->director, 'Aset per unit', ['dataset' => self::ASSET_DATASET, 'dimensions' => ['responsible_org_unit_id'], 'measures' => ['count']], shared: true);
        $this->client = $this->integrationClient($this->director, 'Portal klinik');
    }

    public function test_the_page_lists_every_publication_and_offers_creation_only_to_publishers(): void
    {
        $this->publish($this->director, ['name' => 'Aset per unit', 'saved_query_id' => $this->saved, 'client_ids' => [$this->client['id']]])->assertCreated()
            ->assertJsonPath('data.code', 'aset-per-unit')
            ->assertJsonPath('data.formats', ['json'])
            ->assertJsonPath('data.min_group_size', null)
            ->assertJsonPath('data.clients', [['id' => $this->client['id'], 'name' => 'Portal klinik', 'active' => true]])
            ->assertJsonPath('data.health', ['state' => 'ok', 'message' => null]);

        $this->actingAs($this->director)->get('/analytics/publications')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/analytics/publications')
            ->where('publications.0.code', 'aset-per-unit')
            ->where('publications.0.is_owner', true)
            ->where('canManage', true)
            ->where('savedQueries.0.id', $this->saved)
            ->where('clients.0.can_read', true)
            // Kode data memuat titik, jadi diperiksa lewat isinya, bukan lewat path bertitik.
            ->where('datasets', static function (mixed $datasets): bool {
                $dataset = $datasets instanceof Collection
                    ? $datasets->get(self::ASSET_DATASET)
                    : (is_array($datasets) ? ($datasets[self::ASSET_DATASET] ?? null) : null);
                $fields = $dataset instanceof Collection
                    ? $dataset->get('fields')
                    : (is_array($dataset) ? ($dataset['fields'] ?? null) : null);

                if ($fields instanceof Collection) {
                    return $fields->contains('key', 'responsible_org_unit_id');
                }

                return is_array($fields) && in_array('responsible_org_unit_id', array_column($fields, 'key'), true);
            }));

        // Hanya melihat: daftar tampil, bahan membuat tidak.
        $viewer = $this->member($this->tenant, ['core.analytics.inquire']);
        $reader = $this->readerOnly();
        $this->actingAs($reader)->get('/analytics/publications')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('publications.0.code', 'aset-per-unit')
            ->where('publications.0.can_manage', false)
            ->where('canManage', false)
            ->where('savedQueries', [])
            ->where('clients', []));
        $this->actingAs($reader)->postJson('/api/v1/analytics/publications', ['name' => 'X', 'saved_query_id' => $this->saved])->assertForbidden();
        // Tanpa hak publikasi sama sekali.
        $this->actingAs($viewer)->get('/analytics/publications')->assertForbidden();
    }

    public function test_creation_refuses_what_an_outside_system_could_not_use(): void
    {
        $finance = $this->integrationClient($this->director, 'Aplikasi finance', ['finance-postings.read']);
        $base = ['name' => 'Aset per unit', 'saved_query_id' => $this->saved];

        $this->publish($this->director, [...$base, 'client_ids' => [$finance['id']]])->assertUnprocessable()->assertJsonValidationErrors('client_ids');
        $this->publish($this->director, [...$base, 'formats' => ['xml']])->assertUnprocessable()->assertJsonValidationErrors('formats');
        $this->publish($this->director, [...$base, 'min_group_size' => 1])->assertUnprocessable()->assertJsonValidationErrors('min_group_size');
        $this->publish($this->director, [...$base, 'saved_query_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])->assertUnprocessable()->assertJsonValidationErrors('saved_query_id');
        $this->publish($this->director, [...$base, 'code' => 'Huruf Besar'])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->publish($this->director, [...$base, 'code' => 'aset'])->assertCreated();
        $this->publish($this->director, [...$base, 'code' => 'ASET'])->assertUnprocessable()->assertJsonValidationErrors('code');
        // Tanpa kode, kode dibuat dari nama dan tidak bertabrakan.
        $this->publish($this->director, $base)->assertCreated()->assertJsonPath('data.code', 'aset-per-unit');
        $this->publish($this->director, $base)->assertCreated()->assertJsonPath('data.code', 'aset-per-unit-2');
    }

    public function test_only_the_owner_changes_what_a_publication_exposes_others_pause_or_take_over(): void
    {
        $id = $this->publish($this->director, [
            'name' => 'Aset unit A', 'saved_query_id' => $this->saved, 'client_ids' => [$this->client['id']],
            'locked_filters' => ['responsible_org_unit_id' => [$this->unitA]],
        ])->assertCreated()->json('data.id');
        $publisher = $this->member($this->tenant, ['core.analytics.publish', 'core.analytics.analyze', 'management-aset.aset.manage'], [[null, null]]);

        $this->actingAs($publisher)->patchJson("/api/v1/analytics/publications/{$id}", ['locked_filters' => []], self::ifMatch(1))->assertForbidden();
        $this->actingAs($publisher)->getJson("/api/v1/analytics/publications/{$id}/preview")->assertForbidden();
        $this->actingAs($this->director)->getJson("/api/v1/analytics/publications/{$id}/preview")->assertOk()
            ->assertJsonPath('rows', [['responsible_org_unit_id' => $this->unitA, 'responsible_org_unit_id__label' => 'Unit A', 'count' => 1]]);

        // Tanpa If-Match, tidak ada yang berubah.
        $this->actingAs($publisher)->postJson("/api/v1/analytics/publications/{$id}/pause")->assertStatus(428);
        $version = $this->actingAs($publisher)->postJson("/api/v1/analytics/publications/{$id}/pause", [], self::ifMatch(1))->assertOk()
            ->assertJsonPath('data.status', 'paused')->assertJsonPath('data.can_edit', false)->json('data.version');

        $version = $this->actingAs($publisher)->postJson("/api/v1/analytics/publications/{$id}/take-over", [], self::ifMatch($version))->assertOk()
            ->assertJsonPath('data.owner.id', $publisher->id)->assertJsonPath('data.can_edit', true)->json('data.version');
        $version = $this->actingAs($publisher)->patchJson("/api/v1/analytics/publications/{$id}", ['locked_filters' => ['responsible_org_unit_id' => [$this->unitB]]], self::ifMatch($version))
            ->assertOk()->assertJsonPath('data.locked_filters', ['responsible_org_unit_id' => [$this->unitB]])->json('data.version');
        $this->actingAs($this->director)->patchJson("/api/v1/analytics/publications/{$id}", ['name' => 'Diubah'], self::ifMatch($version))->assertForbidden();

        // Dicabut: tidak dapat diubah, dilanjutkan, atau diambil alih lagi.
        $version = $this->actingAs($this->director)->postJson("/api/v1/analytics/publications/{$id}/revoke", [], self::ifMatch($version))->assertOk()
            ->assertJsonPath('data.health.state', 'revoked')->json('data.version');
        foreach (['resume', 'take-over', 'revoke'] as $action) {
            $this->actingAs($this->director)->postJson("/api/v1/analytics/publications/{$id}/{$action}", [], self::ifMatch($version))
                ->assertUnprocessable()->assertJsonValidationErrors('status');
        }
    }

    public function test_a_change_to_the_saved_query_waits_until_the_owner_applies_it(): void
    {
        $id = $this->publish($this->director, ['name' => 'Aset per unit', 'saved_query_id' => $this->saved, 'client_ids' => [$this->client['id']]])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->director)->patchJson("/api/v1/analytics/saved-queries/{$this->saved}", [
            'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']],
        ], self::ifMatch(1))->assertOk();

        // Sistem luar masih membaca salinan yang dipublikasikan; layar menandai perubahannya.
        $this->assertSame([1, 1], array_column($this->feed($this->client['token'], '/aset-per-unit/rows')->assertOk()->json('rows'), 'count'));
        $this->actingAs($this->director)->get('/analytics/publications')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('publications.0.saved_query.changed', true));

        $this->actingAs($this->director)->patchJson("/api/v1/analytics/publications/{$id}", ['saved_query_id' => $this->saved], self::ifMatch(1))
            ->assertOk()->assertJsonPath('data.saved_query.changed', false);
        $this->feed($this->client['token'], '/aset-per-unit/rows')->assertOk()->assertJsonPath('rows', [['count' => 2]]);
    }

    public function test_locked_filter_choices_show_names_from_the_data_the_publisher_may_read(): void
    {
        $values = $this->actingAs($this->director)->getJson('/api/v1/analytics/publications/field-values?'.http_build_query([
            'dataset' => self::ASSET_DATASET, 'field' => 'responsible_org_unit_id',
        ]))->assertOk()->json('data');
        $this->assertSame([['value' => $this->unitA, 'label' => 'Unit A'], ['value' => $this->unitB, 'label' => 'Unit B']], $values);

        $this->actingAs($this->director)->getJson('/api/v1/analytics/publications/field-values?'.http_build_query([
            'dataset' => self::ASSET_DATASET, 'field' => 'lifecycle_state',
        ]))->assertOk()->assertJsonPath('data.0', ['value' => 'received', 'label' => 'Diterima']);
        $this->actingAs($this->director)->getJson('/api/v1/analytics/publications/field-values?'.http_build_query([
            'dataset' => self::ASSET_DATASET, 'field' => 'nama',
        ]))->assertUnprocessable()->assertJsonValidationErrors('field');

        // Penerbit dengan hibah unit A saja hanya ditawari unit A.
        $narrow = $this->member($this->tenant, ['core.analytics.publish', 'management-aset.aset.manage'], [[$this->legalEntity, $this->unitA]]);
        $this->assertSame([$this->unitA], array_column($this->actingAs($narrow)->getJson('/api/v1/analytics/publications/field-values?'.http_build_query([
            'dataset' => self::ASSET_DATASET, 'field' => 'responsible_org_unit_id',
        ]))->assertOk()->json('data'), 'value'));
    }

    /** Anggota yang hanya boleh melihat daftar publikasi (`core.analytics.publication.read`). */
    private function readerOnly(): User
    {
        $user = $this->member($this->tenant, ['core.analytics.inquire']);
        $privilege = 'core.analytics.publication.read-only.'.$user->id;
        // Tidak ada duty bawaan yang hanya melihat publikasi; role uji memegang permission-nya lewat privilege sendiri.
        DB::table('security_privileges')->insert(['code' => $privilege, 'app_id' => 'core', 'name' => 'Lihat publikasi', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_privilege_permissions')->insert(['privilege_code' => $privilege, 'permission_code' => 'core.analytics.publication.read']);
        DB::table('security_duties')->insert(['code' => $privilege, 'app_id' => 'core', 'name' => 'Lihat publikasi', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_duty_privileges')->insert(['duty_code' => $privilege, 'privilege_code' => $privilege]);
        $this->grantDuties($this->membershipOf($user), [$privilege]);

        return $user;
    }
}
