<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Models\Publication;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Saringan terkunci publikasi gagal tertutup (`docs/todo/analitik/keamanan.md`, ancaman *Saringan terkunci
 * kosong*, jebakan Metabase): saringan yang nilainya kosong, atau yang menyebut kolom yang tidak lagi dikenal
 * data, berarti **nol baris** bagi sistem luar — tidak pernah "semua". Saringan pemanggil tidak dapat
 * melepasnya, hanya mempersempit lagi. Layar menolak menyimpan saringan terkunci tanpa nilai, supaya publikasi
 * seperti itu tidak lahir lewat jalur biasa; baris yang ditulis langsung ke database tetap gagal tertutup.
 *
 * Dilihat merah dengan membuang pemeriksaan nilai kosong di `DataPolicyScope` (daftar kosong memulangkan kelima
 * aset) dan dengan membuang penolakan nilai kosong di `PublicationEditor::lockedFilters()` (PATCH diterima).
 */
class LockedFilterEmptyMeansNothingTest extends TestCase
{
    use BuildsAssetTenants, PublishesAnalytics, RefreshDatabase;

    private User $director;

    private string $unitA;

    private string $unitB;

    /** @var array{token: string, id: string} */
    private array $client;

    private Publication $publication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();

        $this->director = $this->business('Klinik Saringan', 'direktur@saringan-terkunci.test');
        $tenant = (string) $this->membershipOf($this->director)->tenant_id;
        $legalEntity = $this->organization($tenant, 'legal_entity', 'PT Saringan');
        $this->unitA = $this->organization($tenant, 'operating_unit', 'Unit A');
        $this->unitB = $this->organization($tenant, 'operating_unit', 'Unit B');
        $this->asset($tenant, $legalEntity, $this->unitA, '1000');
        $this->asset($tenant, $legalEntity, $this->unitA, '2000');
        foreach (['3000', '4000', '5000'] as $value) {
            $this->asset($tenant, $legalEntity, $this->unitB, $value);
        }

        $saved = $this->savedQuery($this->director, 'Aset per unit', ['dataset' => self::ASSET_DATASET, 'dimensions' => ['responsible_org_unit_id'], 'measures' => ['count']]);
        $this->client = $this->integrationClient($this->director, 'Portal unit A');
        $id = $this->publish($this->director, [
            'name' => 'Aset unit A', 'saved_query_id' => $saved, 'client_ids' => [$this->client['id']],
            'locked_filters' => ['responsible_org_unit_id' => [$this->unitA]],
        ])->assertCreated()
            ->assertJsonPath('data.locked_filters', ['responsible_org_unit_id' => [$this->unitA]])
            ->json('data.id');
        $this->publication = Publication::query()->whereKey($id)->firstOrFail();
    }

    public function test_a_locked_filter_narrows_and_a_caller_filter_cannot_lift_it(): void
    {
        $this->assertSame([2], $this->counts());
        $this->assertSame([], $this->counts(['filter' => ['responsible_org_unit_id' => [$this->unitB]]]));
        $this->assertSame([2], $this->counts(['filter' => ['responsible_org_unit_id' => [$this->unitA, $this->unitB]]]));
    }

    public function test_an_empty_or_unknown_locked_filter_means_no_rows_never_all(): void
    {
        foreach ([[], [''], '', '   '] as $empty) {
            $this->lock(['responsible_org_unit_id' => $empty]);
            $this->assertSame([], $this->counts(), 'Saringan terkunci kosong '.json_encode($empty).' memulangkan baris.');
        }

        // Kolom yang tidak dikenal data (diganti nama atau dibuang module) juga tidak pernah dilepas diam-diam.
        $this->lock(['kolom_yang_sudah_hilang' => ['x']]);
        $this->assertSame([], $this->counts());
    }

    public function test_the_screen_refuses_a_locked_filter_without_a_value_or_on_an_unknown_column(): void
    {
        $patch = fn (array $locked) => $this->actingAs($this->director)->patchJson(
            "/api/v1/analytics/publications/{$this->publication->id}",
            ['locked_filters' => $locked],
            self::ifMatch((int) $this->publication->fresh()?->version),
        );

        $patch(['responsible_org_unit_id' => []])->assertUnprocessable()->assertJsonValidationErrors('locked_filters.responsible_org_unit_id');
        $patch(['responsible_org_unit_id' => '  '])->assertUnprocessable()->assertJsonValidationErrors('locked_filters.responsible_org_unit_id');
        $patch(['kolom_yang_tidak_ada' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('locked_filters.kolom_yang_tidak_ada');
        $patch(['acquired_on' => '31/02/2026..'])->assertUnprocessable()->assertJsonValidationErrors('locked_filters.acquired_on');

        $this->assertSame(['responsible_org_unit_id' => [$this->unitA]], $this->publication->fresh()?->lockedFiltersFor(self::ASSET_DATASET));
        $this->assertSame([2], $this->counts());
    }

    /** @param array<string, mixed> $locked */
    private function lock(array $locked): void
    {
        // Ditulis langsung, seperti baris yang lahir di luar layar: penjaganya harus ada di jalur baca.
        DB::table('analytics_publications')->where('id', $this->publication->id)
            ->update(['locked_filters' => json_encode([self::ASSET_DATASET => $locked], JSON_THROW_ON_ERROR)]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function counts(array $query = []): array
    {
        return array_column($this->feed($this->client['token'], '/aset-unit-a/rows', $query)->assertOk()->json('rows'), 'count');
    }
}
