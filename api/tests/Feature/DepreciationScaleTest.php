<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

/**
 * Uji skala penyusutan: puluhan kombinasi metode x masa manfaat x nilai perolehan x
 * residu dijalankan sampai habis lewat API, di beberapa tenant sekaligus.
 *
 * Yang dibuktikan di sini berbeda dari `DepreciationEndToEndTest`. Test itu memeriksa
 * beberapa skenario pilihan; test ini menyapu ruang kombinasinya. Cacat pembulatan hanya
 * muncul pada angka tertentu — 1200/12 mendarat rapi sementara 100,03/24 tidak — jadi
 * satu skenario yang kebetulan bulat dapat menyembunyikan ekor sen yang menggantung
 * selamanya pada angka lain.
 *
 * Tiga invarian ditegakkan untuk setiap kombinasi:
 *   1. nilai buku mendarat TEPAT di residu, bukan sekitar residu;
 *   2. nilai buku tidak pernah menembus residu di periode mana pun;
 *   3. jumlah seluruh periode final sama persis dengan akumulasi penyusutan.
 */
class DepreciationScaleTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    /** Beberapa tenant dipakai bergiliran supaya volume tidak menumpuk di satu tenant. */
    private const TENANTS = 4;

    /** @var list<string> */
    private array $tenants = [];

    private string $legalEntityId;

    private string $orgUnitId;

    private int $issued = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenants = array_map(fn (): string => (string) Str::ulid(), range(1, self::TENANTS));
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
        $this->configureCoreErpContext();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/fiscal-periods')) {
                return Http::response(['data' => [
                    'calendar' => ['id' => (string) Str::ulid(), 'code' => 'FY', 'name' => 'Kalender'],
                    'year' => ['id' => (string) Str::ulid(), 'name' => 'FY2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'],
                    'period' => ['id' => (string) Str::ulid(), 'ordinal' => 1, 'name' => 'P1', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31'],
                ]]);
            }

            return Http::response(['data' => ['number' => 'NS-'.str_pad((string) ++$this->issued, 7, '0', STR_PAD_LEFT)]]);
        });
    }

    public function test_seluruh_kombinasi_garis_lurus_mendarat_tepat_di_residu(): void
    {
        $ran = 0;
        foreach ($this->combinations() as $index => $combo) {
            $tenant = $this->tenants[$index % self::TENANTS];
            $this->assertLandsOnResidual($tenant, $combo);
            $ran++;
        }

        // Penjaga terhadap sapuan yang diam-diam menyusut jadi nol kombinasi.
        $this->assertGreaterThanOrEqual(40, $ran, 'sapuan kombinasi tidak boleh mengecil tanpa disadari');
    }

    public function test_saldo_menurun_dengan_profil_alternatif_tetap_habis_di_akhir_masa_manfaat(): void
    {
        foreach ([150.0, 240.0] as $i => $rate) {
            foreach ([[1000.0, 0.0], [1234.56, 100.0]] as $j => [$acquisition, $residual]) {
                $tenant = $this->tenants[($i * 2 + $j) % self::TENANTS];
                $label = "saldo menurun {$rate}% acq={$acquisition} res={$residual}";
                $book = $this->scenario($tenant, [
                    'method' => 'reducing_balance', 'useful_life_periods' => 12, 'rate_percent' => $rate,
                ], $acquisition, $residual, alternative: ['method' => 'straight_line_life_remaining', 'useful_life_periods' => 12]);

                $amounts = $this->runPeriods($tenant, $book, 12, $residual, $label);

                // Saldo menurun murni tidak pernah mencapai nol; yang membuatnya mendarat
                // adalah peralihan ke garis lurus sisa umur. Kalau peralihannya mati,
                // assertion inilah yang merah lebih dulu.
                $this->assertSame($residual, $this->netBookValue($book), $label.' harus mendarat tepat di residu');
                $this->assertSame(round($acquisition - $residual, 2), array_sum($amounts) === 0.0 ? 0.0 : round(array_sum($amounts), 2), $label.' total penyusutan');
                $this->assertGreaterThan($amounts[11], $amounts[0] + 0.001, $label.' periode awal harus lebih besar dari periode akhir');
            }
        }
    }

    public function test_konsumsi_berlebih_dipotong_tepat_di_residu(): void
    {
        $tenant = $this->tenants[0];
        $book = $this->scenario($tenant, ['method' => 'consumption'], 1000.0, 250.0);

        $this->assertSame(400.0, $this->finalizeAmount($tenant, $this->propose($tenant, $book, 1, 400)));
        // Pemakaian yang diklaim jauh melebihi sisa nilai tidak boleh membuat nilai buku
        // menembus residu; kelebihannya dipotong.
        $this->assertSame(350.0, $this->finalizeAmount($tenant, $this->propose($tenant, $book, 2, 9_999)));
        $this->assertSame(250.0, $this->netBookValue($book));
        $this->assertSame(0.0, $this->finalizeAmount($tenant, $this->propose($tenant, $book, 3, 500)));
    }

    public function test_volume_besar_tidak_bocor_antar_tenant(): void
    {
        $books = [];
        foreach ($this->tenants as $tenant) {
            foreach (range(1, 3) as $ignored) {
                $book = $this->scenario($tenant, ['method' => 'straight_line', 'useful_life_periods' => 6], 600.0);
                $this->runPeriods($tenant, $book, 6, 0.0, 'tenant '.$tenant);
                $books[$tenant][] = $book;
            }
        }

        foreach ($this->tenants as $tenant) {
            // Tiap tenant hanya melihat periodenya sendiri: 3 aset x 6 periode.
            $this->assertSame(18, DB::table('tr_penyusutan_aset')->where('tenant_id', $tenant)->count(), 'jumlah periode tenant '.$tenant);
            $this->assertSame(
                1800.0,
                round((float) DB::table('tr_penyusutan_aset')->where('tenant_id', $tenant)->sum('amount'), 2),
                'total penyusutan tenant '.$tenant,
            );
            $this->withHeaders($this->contextHeaders($tenant, ['management-aset.penyusutan.read']))
                ->getJson('/api/v1/penyusutan')->assertOk()->assertJsonCount(18, 'data');
        }

        $this->assertSame(self::TENANTS * 18, DB::table('tr_penyusutan_aset')->count());
    }

    // ---- invarian -----------------------------------------------------------

    /** @param array<string, mixed> $combo */
    private function assertLandsOnResidual(string $tenant, array $combo): void
    {
        $label = sprintf(
            '%s umur=%d acq=%s res=%s',
            $combo['method'], $combo['life'], $combo['acquisition'], $combo['residual'],
        );
        $book = $this->scenario(
            $tenant,
            ['method' => $combo['method'], 'useful_life_periods' => $combo['life']],
            $combo['acquisition'],
            $combo['residual'],
        );

        $amounts = $this->runPeriods($tenant, $book, $combo['life'], $combo['residual'], $label);

        // Satu periode ekstra membuktikan penyusutan berhenti sendiri, bukan sekadar
        // kebetulan berhenti karena loopnya habis.
        $this->assertSame(0.0, $this->finalizeAmount($tenant, $this->propose($tenant, $book, $combo['life'] + 1)), $label.' harus berhenti setelah masa manfaat');

        $expected = round($combo['acquisition'] - $combo['residual'], 2);
        $this->assertSame($combo['residual'], $this->netBookValue($book), $label.' nilai buku akhir');
        $this->assertSame($expected, $this->accumulated($book), $label.' akumulasi penyusutan');
        // Buku besar harus konsisten dengan jurnalnya: akumulasi bukanlah angka merdeka.
        $this->assertSame($expected, round(array_sum($amounts), 2), $label.' jumlah periode = akumulasi');
    }

    /**
     * Menjalankan `$periods` periode berturut-turut sambil menjaga invarian tiap langkah.
     *
     * @return list<float>
     */
    private function runPeriods(string $tenant, string $book, int $periods, float $residual, string $label): array
    {
        $amounts = [];
        $previous = $this->netBookValue($book);
        for ($index = 1; $index <= $periods; $index++) {
            $amount = $this->finalizeAmount($tenant, $this->propose($tenant, $book, $index));
            $amounts[] = $amount;
            $current = $this->netBookValue($book);
            $this->assertGreaterThanOrEqual(0.0, $amount, $label." periode {$index} tidak boleh negatif");
            $this->assertGreaterThanOrEqual($residual, $current, $label." periode {$index} menembus residu");
            $this->assertLessThanOrEqual($previous, $current, $label." periode {$index} nilai buku naik");
            $this->assertSame(round($previous - $amount, 2), $current, $label." periode {$index} nilai buku tidak sesuai jumlah yang dibukukan");
            $previous = $current;
        }

        return $amounts;
    }

    /**
     * Ruang kombinasi yang disapu.
     *
     * Angka perolehannya sengaja tidak bulat. `1000/12` mendarat rapi dan tidak
     * membuktikan apa-apa; `100.03/24` dan `7.00/24` adalah tempat ekor pembulatan
     * muncul. `500/500` menguji aset yang residunya sama dengan perolehannya — tidak ada
     * yang boleh disusutkan sama sekali.
     *
     * @return list<array<string, mixed>>
     */
    private function combinations(): array
    {
        $combos = [];
        foreach (['straight_line', 'straight_line_life_remaining'] as $method) {
            foreach ([3, 7, 12, 24] as $life) {
                foreach ([[1000.0, 0.0], [1234.56, 333.33], [100.03, 0.0], [7.0, 0.0], [500.0, 500.0]] as [$acquisition, $residual]) {
                    $combos[] = compact('method', 'life') + ['acquisition' => $acquisition, 'residual' => $residual];
                }
            }
        }

        return $combos;
    }

    // ---- penyusun skenario --------------------------------------------------

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $alternative
     */
    private function scenario(string $tenant, array $profile, float $acquisition, float $residual = 0.0, ?array $alternative = null): string
    {
        $group = $this->master($tenant, 'group-aset', ['nama' => 'Group '.Str::random(8)]);
        $jenis = $this->master($tenant, 'jenis-aset', ['nama' => 'Jenis '.Str::random(8)]);
        $buku = $this->master($tenant, 'buku-penyusutan', [
            'nama' => 'Buku '.Str::random(8),
            'depreciation_profile_id' => $this->profil($tenant, $profile),
            'alternative_profile_id' => $alternative ? $this->profil($tenant, $alternative) : null,
        ]);
        $this->withHeaders($this->contextHeaders($tenant, $this->permissionsFor('group-aset')))
            ->putJson('/api/v1/group-aset/'.$group.'/buku-penyusutan', ['rows' => [[
                'buku_id' => $buku,
                'useful_life_periods' => $profile['useful_life_periods'] ?? null,
                'convention' => 'full_month',
            ]]])->assertOk();

        $asset = $this->withHeaders($this->contextHeaders($tenant, ['management-aset.aset.create']))
            ->withHeader('Idempotency-Key', 'aset-'.Str::ulid())
            ->postJson('/api/v1/aset', [
                'legal_entity_id' => $this->legalEntityId,
                'group_aset_id' => $group, 'jenis_aset_id' => $jenis,
                'acquired_on' => '2026-06-01', 'placed_in_service_on' => '2026-06-15',
                'acquisition_value' => $acquisition, 'residual_value' => $residual,
                'currency_code' => 'IDR', 'usage_org_unit_id' => $this->orgUnitId,
            ])->assertCreated()->json('data.id');

        return (string) DB::table('tr_buku_aset')->where('asset_id', $asset)->value('id');
    }

    private function propose(string $tenant, string $book, int $monthOffset, ?float $consumption = null): TestResponse
    {
        $start = Carbon::parse('2026-07-01')->addMonthsNoOverflow($monthOffset - 1);

        return $this->withHeaders($this->contextHeaders($tenant, ['management-aset.penyusutan.create']))
            ->postJson('/api/v1/penyusutan/proposal', array_filter([
                'asset_book_id' => $book,
                'period_starts_on' => $start->toDateString(),
                'period_ends_on' => $start->copy()->endOfMonth()->toDateString(),
                'consumption_amount' => $consumption,
            ], fn ($value) => $value !== null));
    }

    private function finalizeAmount(string $tenant, TestResponse $proposal): float
    {
        $proposal->assertSuccessful();
        $id = (string) $proposal->json('data.id');
        $this->withHeaders($this->contextHeaders($tenant, ['management-aset.penyusutan.finalize']))
            ->postJson('/api/v1/penyusutan/'.$id.'/finalisasi')->assertOk();

        return (float) DB::table('tr_penyusutan_aset')->where('id', $id)->value('amount');
    }

    private function netBookValue(string $book): float
    {
        return round((float) DB::table('tr_buku_aset')->where('id', $book)->value('net_book_value'), 2);
    }

    private function accumulated(string $book): float
    {
        return round((float) DB::table('tr_buku_aset')->where('id', $book)->value('accumulated_depreciation'), 2);
    }

    /** @param array<string, mixed> $payload */
    private function master(string $tenant, string $resource, array $payload): string
    {
        return $this->withHeaders($this->contextHeaders($tenant, $this->permissionsFor($resource)))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::ulid())
            ->postJson('/api/v1/'.$resource, array_filter($payload, fn ($value) => $value !== null))
            ->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $profile */
    private function profil(string $tenant, array $profile): string
    {
        return $this->master($tenant, 'profil-penyusutan', [
            'nama' => 'Profil '.Str::random(8),
            'frequency' => 'monthly',
            'year_basis' => 'calendar',
            ...$profile,
        ]);
    }

    /** @return list<string> */
    private function permissionsFor(string $resource): array
    {
        return array_map(fn (string $a): string => 'management-aset.'.$resource.'.'.$a, ['read', 'create', 'update', 'archive']);
    }
}
