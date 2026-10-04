<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Access\Models\Role;
use App\Platform\Access\Models\RoleAssignment;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Publikasi dihitung sebagai **pemiliknya saat ini**, dan hak pemilik diperiksa ulang pada setiap permintaan
 * (`docs/todo/analitik/keamanan.md` bagian *Principal*, butir 15.3). Pemilik yang kehilangan hak publikasi,
 * keluar dari tenant, atau kehilangan permission baca datanya membuat publikasinya tertahan — 403
 * `analytics.publication_suspended` — pada permintaan berikutnya, bukan saat cache habis atau token diputar.
 * Pemegang hak publikasi lain yang mengambil alih menghidupkannya kembali dengan jangkauannya sendiri.
 *
 * Analis pemilik publikasi hanya memegang hibah unit A, jadi sistem luar melihat dua aset unit A, bukan lima
 * milik direktur: jangkauan publikasi tidak pernah lebih luas daripada pemiliknya.
 *
 * Tidak ada ingatan permission yang dibuang tangan di sini: setiap permintaan test membaca hak pemilik dari
 * awal, seperti permintaan sungguhan. Dilihat merah dengan melewati pemeriksaan permission di
 * `PublicationAccess` (pencabutan duty publikasi tetap dijawab 200), dengan menyuntikkan `CorePermissions`
 * sekali ke `PublicationAccess` (duty yang dipasang lagi tetap dijawab tertahan, karena controller yang disimpan
 * router membawa ingatan permintaan sebelumnya), dan dengan menerjemahkan penolakan dataset di
 * `PublicationReader::prepare()` menjadi `unavailable` (pencabutan permission aset dijawab 409, bukan 403).
 */
class PublicationSuspendedWhenOwnerLosesAccessTest extends TestCase
{
    use BuildsAssetTenants, PublishesAnalytics, RefreshDatabase;

    private User $director;

    private User $analyst;

    private string $tenant;

    /** @var array{token: string, id: string} */
    private array $client;

    private string $publicationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();

        $this->director = $this->business('Klinik Pencabutan', 'direktur@pencabutan.test');
        $this->tenant = (string) $this->membershipOf($this->director)->tenant_id;
        $legalEntity = $this->organization($this->tenant, 'legal_entity', 'PT Pencabutan');
        $unitA = $this->organization($this->tenant, 'operating_unit', 'Unit A');
        $unitB = $this->organization($this->tenant, 'operating_unit', 'Unit B');
        $this->asset($this->tenant, $legalEntity, $unitA, '1000');
        $this->asset($this->tenant, $legalEntity, $unitA, '2000');
        foreach (['3000', '4000', '5000'] as $value) {
            $this->asset($this->tenant, $legalEntity, $unitB, $value);
        }

        $this->analyst = $this->member($this->tenant, ['core.analytics.publish', 'core.analytics.analyze', 'management-aset.aset.manage'], [[$legalEntity, $unitA]]);
        $saved = $this->savedQuery($this->analyst, 'Jumlah aset', ['dataset' => self::ASSET_DATASET, 'measures' => ['count']]);
        $this->client = $this->integrationClient($this->director, 'Portal klinik');
        $this->publicationId = (string) $this->publish($this->analyst, [
            'name' => 'Jumlah aset unit', 'saved_query_id' => $saved, 'client_ids' => [$this->client['id']],
        ])->assertCreated()->json('data.id');
    }

    public function test_losing_the_publish_duty_suspends_the_publication_on_the_next_request(): void
    {
        $this->assertRows(2);

        $role = $this->roleOf($this->analyst);
        $role->duties()->detach('core.analytics.publish');
        $this->assertSuspended();

        $role->duties()->attach('core.analytics.publish');
        $this->assertRows(2);
    }

    public function test_leaving_the_tenant_suspends_the_publication(): void
    {
        $this->membership($this->analyst)->forceFill(['status' => 'inactive'])->save();
        $this->assertSuspended();
    }

    public function test_losing_read_permission_on_the_data_suspends_the_publication(): void
    {
        $this->roleOf($this->analyst)->duties()->detach('management-aset.aset.manage');
        $this->assertSuspended();
        // Metadata ikut tertahan: kolomnya pun dibaca dengan hak pemilik.
        $this->feed($this->client['token'], '/jumlah-aset-unit')->assertForbidden()->assertJsonPath('error.code', 'analytics.publication_suspended');
    }

    public function test_another_publisher_takes_over_and_the_publication_runs_with_their_reach(): void
    {
        $this->membership($this->analyst)->forceFill(['status' => 'inactive'])->save();
        $this->assertSuspended();
        $this->withoutVite();

        // Layar menandai publikasinya tertahan untuk pemegang hak publikasi lain.
        $this->actingAs($this->director)->get('/analytics/publications')->assertOk()
            ->assertInertia(fn ($page) => $page->where('publications.0.health.state', 'suspended')->where('publications.0.can_edit', false));
        $this->actingAs($this->director)->postJson("/api/v1/analytics/publications/{$this->publicationId}/take-over", [], self::ifMatch(1))
            ->assertOk()->assertJsonPath('data.owner.id', $this->director->id)->assertJsonPath('data.health.state', 'ok');

        // Direktur memegang seluruh aset: jangkauan publikasi ikut pemilik barunya.
        $this->assertRows(5);
    }

    private function assertRows(int $count): void
    {
        $this->feed($this->client['token'], '/jumlah-aset-unit/rows')->assertOk()->assertJsonPath('rows', [['count' => $count]]);
    }

    private function assertSuspended(): void
    {
        $this->feed($this->client['token'], '/jumlah-aset-unit/rows')->assertForbidden()->assertExactJson(['error' => [
            'code' => 'analytics.publication_suspended',
            'message' => 'Publikasi ini tertahan karena pemiliknya tidak lagi berhak membagikan datanya. Minta admin tenant mengambil alih publikasi ini.',
        ]]);
    }

    private function membership(User $user): TenantMembership
    {
        return TenantMembership::query()->where('tenant_id', $this->tenant)->where('user_id', $user->id)->firstOrFail();
    }

    private function roleOf(User $user): Role
    {
        $assignment = RoleAssignment::query()->where('membership_id', $this->membership($user)->id)->firstOrFail();

        return Role::query()->findOrFail($assignment->role_id);
    }
}
