<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\External\CsvRows;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Analytics\Models\QueryLogEntry;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CocokDenganKontrak;
use Tests\TestCase;

/**
 * Publikasi analitik dibaca sistem luar (US-09, butir 15.5–15.8): daftar, metadata, baris JSON dan CSV per
 * halaman dengan cursor, saringan tambahan yang hanya menyempitkan, penyembunyian kelompok kecil, dan log query
 * bersumber `api` dengan id publikasi dan klien. Penolakan — scope kurang, klien tidak terdaftar, tenant lain,
 * dihentikan, dicabut — dijawab dengan status dan kode yang dijanjikan kontrak `integrasi-analitik.yaml`.
 *
 * Bahannya register aset sungguhan: unit A dua aset, unit B tiga aset, dihitung sebagai direktur (role Owner).
 * Bukti merahnya ditulis di pull request area 15.
 */
class PublicationFeedTest extends TestCase
{
    use BuildsAssetTenants, CocokDenganKontrak, PublishesAnalytics, RefreshDatabase;

    private User $director;

    private string $tenant;

    private string $legalEntity;

    private string $unitA;

    private string $unitB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAssetModule();

        $this->director = $this->business('Klinik Publikasi', 'direktur@publikasi.test');
        $this->tenant = (string) $this->membershipOf($this->director)->tenant_id;
        $this->legalEntity = $this->organization($this->tenant, 'legal_entity', 'PT Klinik Publikasi');
        $this->unitA = $this->organization($this->tenant, 'operating_unit', 'Unit A');
        $this->unitB = $this->organization($this->tenant, 'operating_unit', 'Unit B');

        foreach (['100000000', '50000000'] as $value) {
            $this->asset($this->tenant, $this->legalEntity, $this->unitA, $value);
        }
        foreach (['200000000', '10000000', '5000000'] as $value) {
            $this->asset($this->tenant, $this->legalEntity, $this->unitB, $value);
        }
    }

    public function test_an_outside_system_reads_a_publication_page_by_page_as_json_and_csv(): void
    {
        $saved = $this->savedQuery($this->director, 'Aset per unit', ['dataset' => self::ASSET_DATASET, 'dimensions' => ['responsible_org_unit_id'], 'measures' => ['count']]);
        $client = $this->integrationClient($this->director, 'n8n gudang data');
        $publication = $this->publish($this->director, [
            'name' => 'Aset per unit', 'saved_query_id' => $saved, 'client_ids' => [$client['id']], 'formats' => ['json', 'csv'],
        ])->assertCreated()->json('data');
        $this->assertSame('aset-per-unit', $publication['code']);

        $this->feed($client['token'])->assertOk()->assertExactJson(['data' => [[
            'code' => 'aset-per-unit', 'name' => 'Aset per unit', 'description' => null, 'status' => 'active',
            'formats' => ['json', 'csv'], 'updated_at' => Publication::query()->findOrFail($publication['id'])->updated_at?->toIso8601String(),
        ]]]);

        $this->feed($client['token'], '/aset-per-unit')->assertOk()
            ->assertJsonPath('data.code', 'aset-per-unit')
            ->assertJsonPath('data.small_group_threshold', null)
            ->assertJsonPath('data.columns', [
                ['key' => 'responsible_org_unit_id', 'kind' => 'dimension', 'caption' => 'Unit penanggung jawab', 'type' => 'reference', 'label_key' => 'responsible_org_unit_id__label'],
                ['key' => 'count', 'kind' => 'measure', 'caption' => 'Jumlah aset', 'type' => 'number', 'format' => 'number'],
            ])
            ->assertJsonPath('data.filterable', [['key' => 'responsible_org_unit_id', 'caption' => 'Unit penanggung jawab', 'type' => 'reference']]);

        $expected = [
            ['responsible_org_unit_id' => $this->unitB, 'responsible_org_unit_id__label' => 'Unit B', 'count' => 3],
            ['responsible_org_unit_id' => $this->unitA, 'responsible_org_unit_id__label' => 'Unit A', 'count' => 2],
        ];
        $this->feed($client['token'], '/aset-per-unit/rows')->assertOk()
            ->assertJsonPath('rows', $expected)
            ->assertJsonPath('meta.publication', 'aset-per-unit')
            ->assertJsonPath('meta.truncated', false)
            ->assertJsonPath('meta.small_groups_hidden', 0)
            ->assertJsonPath('meta.next_cursor', null);

        // Halaman demi halaman: cursor dari halaman pertama membuka yang kedua, lalu habis.
        $first = $this->feed($client['token'], '/aset-per-unit/rows', ['limit' => 1])->assertOk()->assertJsonPath('rows', [$expected[0]]);
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $this->feed($client['token'], '/aset-per-unit/rows', ['limit' => 1, 'cursor' => $cursor])->assertOk()
            ->assertJsonPath('rows', [$expected[1]])
            ->assertJsonPath('meta.next_cursor', null);

        // CSV: kunci kolom di baris judul, cursor di header.
        $csv = $this->feed($client['token'], '/aset-per-unit/rows', ['format' => 'csv', 'limit' => 1])->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $csv->headers->get('Content-Type'));
        $this->assertSame($cursor, $csv->headers->get('X-Next-Cursor'));
        $this->assertSame("responsible_org_unit_id,responsible_org_unit_id__label,count\n{$this->unitB},\"Unit B\",3\n", $csv->getContent());

        // Jawaban sungguhan cocok dengan skema kontrak yang terbit untuk developer luar.
        $contract = 'contracts/terbit/integrasi-analitik.yaml';
        $this->assertCocokSkema($this->feed($client['token'])->json('data.0'), 'AnalyticsPublicationSummary', $contract);
        $this->assertCocokSkema($this->feed($client['token'], '/aset-per-unit')->json('data'), 'AnalyticsPublication', $contract);
        $this->assertCocokSkema($first->json(), 'AnalyticsRowsPage', $contract);
        $this->assertCocokSkema($this->feed($client['token'], '/tidak-ada')->assertNotFound()->json(), 'AnalyticsError', $contract);

        // Log query: sumber api, publikasi dan klien tercatat di principal.
        $log = QueryLogEntry::query()->where('tenant_id', $this->tenant)->where('source', 'api')->latest('id')->firstOrFail();
        $this->assertSame("publication:{$publication['id']};client:{$client['id']}", $log->principal);
        $this->assertSame('success', $log->status);
        $this->assertNotNull(Publication::query()->whereKey($publication['id'])->firstOrFail()->last_used_at);
    }

    public function test_invalid_tokens_cannot_create_rate_limit_buckets_by_changing_their_prefix(): void
    {
        config()->set('coreerp.integration_api_ip_rate_limit', 2);

        $this->feed('palsu-satu.rahasia')->assertUnauthorized();
        $this->feed('palsu-dua.rahasia')->assertUnauthorized();
        $this->feed('palsu-tiga.rahasia')->assertStatus(429);
    }

    public function test_authenticated_integration_clients_have_their_own_rate_limit(): void
    {
        config()->set('coreerp.integration_api_ip_rate_limit', 10);
        config()->set('coreerp.integration_api_rate_limit', 1);
        $client = $this->integrationClient($this->director, 'Pembaca data');

        $this->feed($client['token'])->assertOk();
        $this->feed($client['token'])->assertStatus(429);
    }

    public function test_a_caller_filter_only_narrows_and_never_replaces_the_publications_own_filters(): void
    {
        $saved = $this->savedQuery($this->director, 'Aset diterima per unit', [
            'dataset' => self::ASSET_DATASET,
            'dimensions' => ['responsible_org_unit_id', 'lifecycle_state'],
            'measures' => ['count'],
            'filters' => ['lifecycle_state' => ['received']],
        ]);
        $client = $this->integrationClient($this->director, 'Sheets keuangan');
        $this->publish($this->director, ['name' => 'Aset diterima', 'saved_query_id' => $saved, 'client_ids' => [$client['id']]])->assertCreated();

        $all = $this->feed($client['token'], '/aset-diterima/rows')->assertOk()->json('rows');
        $this->assertSame([3, 2], array_column($all, 'count'));

        $narrowed = $this->feed($client['token'], '/aset-diterima/rows', ['filter' => ['responsible_org_unit_id' => [$this->unitA]]])->assertOk()->json('rows');
        $this->assertSame([2], array_column($narrowed, 'count'));
        foreach ($narrowed as $row) {
            $this->assertContains($row, $all, 'Saringan tambahan memulangkan baris yang tidak ada tanpa saringan itu.');
        }

        // Kolom yang sudah disaring query publikasi tidak ditawarkan: saringan pemanggil tidak boleh menggantikannya.
        $this->feed($client['token'], '/aset-diterima')->assertOk()
            ->assertJsonPath('data.filterable', [['key' => 'responsible_org_unit_id', 'caption' => 'Unit penanggung jawab', 'type' => 'reference']]);
        $this->feed($client['token'], '/aset-diterima/rows', ['filter' => ['lifecycle_state' => ['disposed', 'received', 'in_use']]])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.invalid_filter')
            ->assertJsonPath('error.field', 'filter.lifecycle_state');
        // Kolom yang bukan pengelompok publikasi juga tidak.
        $this->feed($client['token'], '/aset-diterima/rows', ['filter' => ['acquisition_value' => '>0']])
            ->assertUnprocessable()->assertJsonPath('error.field', 'filter.acquisition_value');
        // Cursor terikat ke saringannya.
        $cursor = $this->feed($client['token'], '/aset-diterima/rows', ['limit' => 1])->json('meta.next_cursor');
        $this->feed($client['token'], '/aset-diterima/rows', ['limit' => 1, 'cursor' => $cursor, 'filter' => ['responsible_org_unit_id' => [$this->unitA]]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'analytics.cursor_invalid');
        $this->feed($client['token'], '/aset-diterima/rows', ['cursor' => 'bukan-cursor'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'analytics.cursor_invalid');
        // Format yang tidak dibuka publikasi.
        $this->feed($client['token'], '/aset-diterima/rows', ['format' => 'csv'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'analytics.format_unavailable');
        $this->feed($client['token'], '/aset-diterima/rows', ['limit' => 5001])
            ->assertUnprocessable()->assertJsonPath('error.code', 'analytics.invalid_parameter');
    }

    public function test_small_groups_are_hidden_when_the_publication_sets_a_threshold(): void
    {
        $saved = $this->savedQuery($this->director, 'Nilai per unit', ['dataset' => self::ASSET_DATASET, 'dimensions' => ['responsible_org_unit_id'], 'measures' => ['acquisition_value']]);
        $client = $this->integrationClient($this->director, 'Portal');
        $id = $this->publish($this->director, ['name' => 'Nilai per unit', 'saved_query_id' => $saved, 'client_ids' => [$client['id']]])->assertCreated()->json('data.id');

        // Bawaan: mati (PQ-07 belum diputuskan), semua kelompok tampil.
        $this->assertSame([$this->unitB, $this->unitA], array_column($this->feed($client['token'], '/nilai-per-unit/rows')->json('rows'), 'responsible_org_unit_id'));

        $this->actingAs($this->director)->patchJson("/api/v1/analytics/publications/{$id}", ['min_group_size' => 3], self::ifMatch(1))->assertOk();
        $rows = $this->feed($client['token'], '/nilai-per-unit/rows')->assertOk()
            ->assertJsonPath('meta.small_groups_hidden', 1);
        // Unit A (dua aset) disembunyikan; jumlah baris yang ditambahkan diam-diam tidak ikut keluar.
        $this->assertSame([$this->unitB], array_column($rows->json('rows'), 'responsible_org_unit_id'));
        $this->assertArrayNotHasKey('count', $rows->json('rows.0'));
        $this->assertNotContains('count', array_column($rows->json('columns'), 'key'));
        $this->feed($client['token'], '/nilai-per-unit')->assertOk()->assertJsonPath('data.small_group_threshold', 3);
    }

    public function test_refusals_do_not_reveal_a_publication_to_clients_it_does_not_name(): void
    {
        $saved = $this->savedQuery($this->director, 'Jumlah aset', ['dataset' => self::ASSET_DATASET, 'measures' => ['count']]);
        $named = $this->integrationClient($this->director, 'Klien terdaftar');
        $unnamed = $this->integrationClient($this->director, 'Klien lain');
        $withoutScope = $this->integrationClient($this->director, 'Klien finance', ['finance-postings.read']);
        $id = $this->publish($this->director, ['name' => 'Jumlah aset', 'saved_query_id' => $saved, 'client_ids' => [$named['id']]])->assertCreated()->json('data.id');

        $this->feed($named['token'], '/jumlah-aset/rows')->assertOk()->assertJsonPath('rows', [['count' => 5]]);

        // Tanpa scope: gerbang klien integrasi, sebelum publikasi dicari.
        $this->feed($withoutScope['token'], '/jumlah-aset/rows')->assertForbidden()
            ->assertJsonPath('message', 'Klien integrasi ini tidak punya izin analytics.read.');
        $this->feed('01ARZ3NDEKTSV4RRFFQ69G5FAV.salah', '/jumlah-aset/rows')->assertUnauthorized();

        // Tidak terdaftar, tidak ada, dan milik tenant lain dijawab sama.
        $unknown = ['error' => ['code' => 'analytics.publication_unknown', 'message' => 'Publikasi ini tidak ada atau tidak dibuka untuk klien integrasi ini.', 'field' => 'code']];
        $this->feed($unnamed['token'], '/jumlah-aset/rows')->assertNotFound()->assertExactJson($unknown);
        $this->feed($unnamed['token'], '/jumlah-aset')->assertNotFound()->assertExactJson($unknown);
        $this->feed($unnamed['token'], '/tidak-ada/rows')->assertNotFound()->assertExactJson($unknown);
        $this->feed($unnamed['token'])->assertOk()->assertExactJson(['data' => []]);

        $other = $this->business('Klinik Lain', 'direktur@publikasi-lain.test');
        $otherClient = $this->integrationClient($other, 'Klien tenant lain');
        $this->feed($otherClient['token'], '/jumlah-aset/rows')->assertNotFound()->assertExactJson($unknown);

        // Dihentikan sementara: klien terdaftar tahu alasannya; dilanjutkan, terbaca lagi.
        $version = $this->actingAs($this->director)->postJson("/api/v1/analytics/publications/{$id}/pause", [], self::ifMatch(1))->assertOk()->json('data.version');
        $this->feed($named['token'], '/jumlah-aset/rows')->assertForbidden()->assertJsonPath('error.code', 'analytics.publication_paused');
        $this->feed($named['token'])->assertOk()->assertJsonPath('data.0.status', 'paused');
        $version = $this->actingAs($this->director)->postJson("/api/v1/analytics/publications/{$id}/resume", [], self::ifMatch($version))->assertOk()->json('data.version');
        $this->feed($named['token'], '/jumlah-aset/rows')->assertOk();

        // Dicabut: hilang seperti tidak pernah ada.
        $this->actingAs($this->director)->postJson("/api/v1/analytics/publications/{$id}/revoke", [], self::ifMatch($version))->assertOk();
        $this->feed($named['token'], '/jumlah-aset/rows')->assertNotFound()->assertExactJson($unknown);
        $this->feed($named['token'])->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_csv_cells_never_become_spreadsheet_formulas_and_numbers_stay_raw(): void
    {
        $csv = CsvRows::render(['nama', 'nilai', 'selisih'], [
            ['nama' => '=HYPERLINK("http://contoh")', 'nilai' => '1250000.50', 'selisih' => '-25.00'],
            ['nama' => '+1', 'nilai' => 3, 'selisih' => null],
            ['nama' => '@SUM(A1)', 'nilai' => '0', 'selisih' => '-'],
        ]);

        $this->assertSame(implode("\n", [
            'nama,nilai,selisih',
            '"\'=HYPERLINK(""http://contoh"")",1250000.50,-25.00',
            "'+1,3,",
            '\'@SUM(A1),0,\'-',
            '',
        ]), $csv);
    }
}
