<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prinsip 3 keamanan analitik (`docs/todo/analitik/keamanan.md`): dasbor bersama dihitung sebagai **yang
 * melihat**, bukan sebagai yang membuat. Membagikan dasbor membagikan susunannya, bukan hak penyusunnya.
 *
 * Direktur (role Owner, seluruh aset) menyusun dasbor bersama berisi satu tile jumlah aset. Kepala unit A yang
 * membukanya melihat aset unit A saja; staf yang boleh membaca aset tetapi tanpa hibah melihat nol; staf tanpa
 * permission baca aset mendapat 403 `analytics.dataset_forbidden`, bukan angka pinjaman dari direktur.
 *
 * Dilihat merah dengan membuat principal `WidgetDataController` dari pemilik dasbor alih-alih dari keanggotaan
 * yang meminta: kepala unit A dan staf tanpa hibah lalu melihat angka direktur (3), dan staf tanpa permission
 * mendapat 200.
 */
class SharedDashboardRunsAsViewerTest extends TestCase
{
    use BuildsAssetTenants, RefreshDatabase;

    private User $director;

    private string $tenant;

    private string $legalEntity;

    private string $unitA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();

        $this->director = $this->business('Tenant dasbor bersama', 'direktur@dasbor-bersama.test');
        $this->tenant = (string) $this->membershipOf($this->director)->tenant_id;
        $this->legalEntity = $this->organization($this->tenant, 'legal_entity', 'PT Dasbor Bersama');
        $this->unitA = $this->organization($this->tenant, 'operating_unit', 'Unit A');
        $unitB = $this->organization($this->tenant, 'operating_unit', 'Unit B');

        $this->asset($this->tenant, $this->legalEntity, $this->unitA, '100000000');
        $this->asset($this->tenant, $this->legalEntity, $this->unitA, '50000000');
        $this->asset($this->tenant, $this->legalEntity, $unitB, '200000000');
    }

    public function test_a_shared_widget_counts_only_what_the_viewer_may_read(): void
    {
        $dashboard = $this->actingAs($this->director)->postJson('/api/v1/analytics/dashboards', ['name' => 'Ringkasan aset', 'shared' => true])
            ->assertCreated()->json('data.id');
        $widget = $this->actingAs($this->director)->postJson("/api/v1/analytics/dashboards/{$dashboard}/widgets", [
            'title' => 'Jumlah aset',
            'type' => 'kpi',
            'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']],
            'visual' => ['measure' => 'count'],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->director)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()
            ->assertJsonPath('rows', [['count' => 3]]);

        // Kepala unit A: boleh melihat dasbor dan membaca aset, dengan hibah unit A saja.
        $headOfUnitA = $this->member($this->tenant, ['core.analytics.inquire', 'management-aset.aset.manage'], [[$this->legalEntity, $this->unitA]]);
        $this->actingAs($headOfUnitA)->getJson("/api/v1/analytics/dashboards/{$dashboard}")->assertOk()
            ->assertJsonPath('data.widgets.0.id', $widget)
            ->assertJsonPath('data.can_edit', false);
        $this->actingAs($headOfUnitA)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()
            ->assertJsonPath('rows', [['count' => 2]]);
        $this->actingAs($headOfUnitA)->postJson("/api/v1/analytics/widgets/{$widget}/refresh")->assertOk()
            ->assertJsonPath('rows', [['count' => 2]]);

        // Boleh membaca aset tetapi tanpa hibah kebijakan: nol, bukan angka direktur.
        $withoutGrant = $this->member($this->tenant, ['core.analytics.inquire', 'management-aset.aset.manage']);
        $this->actingAs($withoutGrant)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertOk()
            ->assertJsonPath('rows', [['count' => 0]]);

        // Tanpa permission baca aset: dasbornya terlihat, angkanya tidak.
        $withoutAssetRead = $this->member($this->tenant, ['core.analytics.inquire', 'management-aset.pemeliharaan-aset.manage'], [[$this->legalEntity, $this->unitA]]);
        $this->actingAs($withoutAssetRead)->getJson("/api/v1/analytics/dashboards/{$dashboard}")->assertOk();
        $this->actingAs($withoutAssetRead)->getJson("/api/v1/analytics/widgets/{$widget}/data")
            ->assertForbidden()
            ->assertExactJson(['error' => ['code' => 'analytics.dataset_forbidden', 'message' => 'Anda tidak punya akses ke data ini.', 'field' => 'dataset']]);

        // Tanpa hak melihat dasbor sama sekali: gate rute, sebelum widget dibaca.
        $assetOnly = $this->member($this->tenant, ['management-aset.aset.manage'], [[null, null]]);
        $this->actingAs($assetOnly)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertForbidden();
    }

    public function test_a_private_dashboard_widget_is_not_found_for_anyone_but_its_owner(): void
    {
        $dashboard = $this->actingAs($this->director)->postJson('/api/v1/analytics/dashboards', ['name' => 'Catatan direktur'])
            ->assertCreated()->json('data.id');
        $widget = $this->actingAs($this->director)->postJson("/api/v1/analytics/dashboards/{$dashboard}/widgets", [
            'title' => 'Jumlah aset', 'type' => 'kpi', 'query' => ['dataset' => self::ASSET_DATASET, 'measures' => ['count']],
        ])->assertCreated()->json('data.id');

        // Seluruh organisasi pun tidak membuka widget di dasbor pribadi orang lain.
        $wholeOrganization = $this->member($this->tenant, ['core.analytics.analyze', 'core.analytics.manage', 'management-aset.aset.manage'], [[null, null]]);
        $this->actingAs($wholeOrganization)->getJson("/api/v1/analytics/widgets/{$widget}/data")->assertNotFound();
        $this->actingAs($wholeOrganization)->getJson("/api/v1/analytics/dashboards/{$dashboard}")->assertNotFound();
    }
}
