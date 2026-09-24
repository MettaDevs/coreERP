<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerimaAset;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * UAT penyusutan: menjalankan aset sungguhan melewati banyak periode lewat API, bukan
 * memanggil kalkulator langsung.
 *
 * Test unit membuktikan rumusnya benar untuk satu periode. Yang dibuktikan di sini
 * adalah hal yang hanya muncul setelah belasan periode berjalan: nilai buku mendarat
 * tepat di residu, penyusutan berhenti sendiri, dan dua buku pada aset yang sama tidak
 * saling mengganggu.
 */
class DepreciationEndToEndTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerimaAset, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    private int $issued = 0;

    /** Tahun buku Juli-Juni, sengaja berbeda dari tahun kalender. */
    private const FISCAL_YEAR = ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();

        // Tahun buku Juli–Juni dibuat sungguhan: periode fiskal sekarang dibaca dari database
        // Core, bukan dari jawaban HTTP palsu. Test ini memang tentang dasar tahun fiskal yang
        // berbeda dari tahun kalender, jadi kalendernya harus benar-benar begitu.
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->buatKalenderFiskalUji($this->tenantId, $this->legalEntityId, self::FISCAL_YEAR['starts_on'], self::FISCAL_YEAR['ends_on']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/fiscal-periods')) {
                return Http::response(['data' => [
                    'calendar' => ['id' => (string) Str::ulid(), 'code' => 'FY', 'name' => 'Kalender'],
                    'year' => ['id' => (string) Str::ulid(), 'name' => 'FY2027', ...self::FISCAL_YEAR],
                    'period' => ['id' => (string) Str::ulid(), 'ordinal' => 1, 'name' => 'P1', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31'],
                ]]);
            }

            return Http::response(['data' => ['number' => 'NS-'.str_pad((string) ++$this->issued, 6, '0', STR_PAD_LEFT)]]);
        });
    }

    public function test_garis_lurus_habis_tepat_di_akhir_masa_manfaat_lalu_berhenti(): void
    {
        $book = $this->scenario(['method' => 'straight_line', 'useful_life_periods' => 12], acquisition: 1200);

        $amounts = $this->fastForward($book, 14);

        // Dua belas periode masing-masing 100, lalu berhenti dengan sendirinya.
        $this->assertSame(array_fill(0, 12, 100.0), array_slice($amounts, 0, 12));
        $this->assertSame([0.0, 0.0], array_slice($amounts, 12, 2));
        $this->assertSame(1200.0, $this->accumulated($book));
        $this->assertSame(0.0, $this->netBookValue($book));
    }

    public function test_garis_lurus_dengan_residu_mendarat_tepat_di_nilai_sisa(): void
    {
        $book = $this->scenario(['method' => 'straight_line', 'useful_life_periods' => 12], acquisition: 1200, residual: 200);

        $this->fastForward($book, 14);

        // Nilai buku berhenti di residu, tidak menembusnya dan tidak menjadi negatif.
        $this->assertSame(200.0, $this->netBookValue($book));
        $this->assertSame(1000.0, $this->accumulated($book));
    }

    public function test_saldo_menurun_menurun_terus_tanpa_pernah_menembus_residu(): void
    {
        $book = $this->scenario(
            ['method' => 'reducing_balance', 'useful_life_periods' => 24, 'rate_percent' => 60],
            acquisition: 1000,
            residual: 100,
        );

        $amounts = $this->fastForward($book, 24);

        // Angkanya mengecil dari periode ke periode, tidak pernah membesar.
        for ($i = 1; $i < 12; $i++) {
            $this->assertLessThanOrEqual($amounts[$i - 1], $amounts[$i], "periode {$i} tidak boleh lebih besar dari sebelumnya");
        }
        $this->assertGreaterThanOrEqual(100.0, $this->netBookValue($book));
    }

    public function test_jadwal_manual_mengikuti_urutan_periodenya(): void
    {
        $book = $this->scenario([
            'method' => 'manual',
            'manual_schedule' => [['amount' => 500], ['amount' => 300], ['amount' => 200]],
        ], acquisition: 1000);

        $this->assertSame([500.0, 300.0, 200.0], $this->fastForward($book, 3));
        $this->assertSame(0.0, $this->netBookValue($book));

        // Aset yang sudah habis menghasilkan nol, bukan error: jadwal memang selesai.
        $this->assertSame(0.0, $this->finalizeAmount($this->propose($book, 4)));

        // Berbeda dengan jadwal yang kehabisan baris selagi nilai buku masih ada; itu
        // konfigurasi yang belum selesai dan harus ditolak, bukan ditebak nol.
        $kurang = $this->scenario([
            'method' => 'manual',
            'manual_schedule' => [['amount' => 100]],
        ], acquisition: 1000);
        $this->fastForward($kurang, 1);
        $this->propose($kurang, 2)->assertStatus(422);
    }

    public function test_konsumsi_memakai_angka_pemakaian_yang_dikirim(): void
    {
        $book = $this->scenario(['method' => 'consumption'], acquisition: 1000);

        $this->assertSame(150.0, $this->finalizeAmount($this->propose($book, 1, consumption: 150)));
        $this->assertSame(90.0, $this->finalizeAmount($this->propose($book, 2, consumption: 90)));
        $this->assertSame(760.0, $this->netBookValue($book));
    }

    public function test_garis_lurus_sisa_umur_membagi_habis_tanpa_menyisakan_ekor(): void
    {
        // 1000 dibagi 3 tidak bulat; metode sisa umur harus tetap mendarat tepat di nol.
        $book = $this->scenario(['method' => 'straight_line_life_remaining', 'useful_life_periods' => 3], acquisition: 1000);

        $this->fastForward($book, 3);

        $this->assertSame(0.0, $this->netBookValue($book));
        $this->assertSame(1000.0, $this->accumulated($book));
    }

    public function test_dua_buku_pada_satu_aset_menyusut_mandiri(): void
    {
        $group = $this->master('group-aset', ['nama' => 'Kendaraan']);
        $jenis = $this->master('jenis-aset', ['nama' => 'Mobil']);
        $komersial = $this->profil('Komersial', ['method' => 'straight_line', 'useful_life_periods' => 10]);
        $fiskal = $this->profil('Fiskal', ['method' => 'straight_line', 'useful_life_periods' => 5]);
        $bukuK = $this->master('buku-penyusutan', ['nama' => 'Komersial', 'posting_layer' => 'current', 'depreciation_profile_id' => $komersial]);
        $bukuF = $this->master('buku-penyusutan', ['nama' => 'Fiskal', 'posting_layer' => 'none', 'depreciation_profile_id' => $fiskal]);
        $this->matrix($group, [
            ['buku_id' => $bukuK, 'useful_life_periods' => 10, 'convention' => 'full_month'],
            ['buku_id' => $bukuF, 'useful_life_periods' => 5, 'convention' => 'full_month'],
        ])->assertOk();
        $aset = $this->receive($group, $jenis, 1000);

        $books = DB::table('aset_tr_buku_aset')->where('aset_id', $aset)->orderBy('useful_life_periods')->pluck('id', 'useful_life_periods');
        $this->fastForward($books[5], 5);
        $this->fastForward($books[10], 5);

        // Buku fiskal sudah habis dalam 5 periode; komersial baru separuh jalan.
        $this->assertSame(0.0, $this->netBookValue($books[5]));
        $this->assertSame(500.0, $this->netBookValue($books[10]));

        // Kedua buku tidak lagi menulis ekspor. Buku fiskal memorandum juga tidak pernah di-post ke
        // finance: proses "Post penyusutan" menolaknya (`DepreciationPostingTest`).
        $this->assertSame(0, DB::table('aset_tr_export_penyusutan')->count());
    }

    public function test_dasar_tahun_kalender_dan_fiskal_menghasilkan_awal_yang_berbeda(): void
    {
        // Konvensi setengah tahun bergantung pada batas tahun, jadi dasar tahun mengubah
        // tanggal mulai menyusutnya. Tahun buku Juli-Juni sengaja dipakai supaya bedanya
        // terlihat; tanpa kalender fiskal dari Core, batas jatuh ke tahun kalender.
        // 20 November ada di paruh KEDUA tahun kalender tetapi paruh PERTAMA tahun buku
        // Juli-Juni, sehingga konvensi "mulai tahun depan" memberi hasil yang jelas beda.
        $kalender = $this->scenario(
            ['method' => 'straight_line', 'useful_life_periods' => 12, 'year_basis' => 'calendar'],
            convention: 'half_year_next_year',
            placedInService: '2026-11-20',
        );
        $fiskal = $this->scenario(
            ['method' => 'straight_line', 'useful_life_periods' => 12, 'year_basis' => 'fiscal'],
            convention: 'half_year_next_year',
            placedInService: '2026-11-20',
        );

        $this->assertSame('2027-01-01', $this->startDate($kalender));
        $this->assertSame('2026-07-01', $this->startDate($fiskal));
    }

    public function test_periode_sebelum_aset_mulai_menyusut_ditolak(): void
    {
        $book = $this->scenario(
            ['method' => 'straight_line', 'useful_life_periods' => 12],
            convention: 'full_month',
            placedInService: '2026-06-20',
        );

        // Aset baru dipakai Juni, jadi periode Maret tidak boleh menghasilkan penyusutan.
        $this->proposeOn($book, '2026-03-01', '2026-03-31')->assertStatus(422);
        $this->proposeOn($book, '2026-06-01', '2026-06-30')->assertCreated();
    }

    public function test_penyusutan_tidak_bocor_antar_tenant(): void
    {
        $milikKita = $this->scenario(['method' => 'straight_line', 'useful_life_periods' => 12], acquisition: 1200);
        $this->fastForward($milikKita, 3);

        $tenantLain = (string) Str::ulid();
        $this->sebagaiPengguna($tenantLain, ['management-aset.penyusutan.read'])
            ->getJson('/api/modules/management-aset/v1/penyusutan')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Buku milik tenant lain tidak dapat dipakai membuat proposal.
        $this->sebagaiPengguna($tenantLain, ['management-aset.penyusutan.create'])
            ->postJson('/api/modules/management-aset/v1/penyusutan/proposal', [
                'buku_aset_id' => $milikKita, 'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31',
            ])->assertNotFound();

        $this->assertSame(3, DB::table('aset_tr_penyusutan_aset')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_pembalikan_mengembalikan_nilai_buku_tanpa_mengubah_periode_asal(): void
    {
        $book = $this->scenario(['method' => 'straight_line', 'useful_life_periods' => 12], acquisition: 1200);
        $periodId = $this->propose($book, 1)->json('data.id');
        $this->finalize($periodId);
        $this->assertSame(1100.0, $this->netBookValue($book));

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.correct'])
            ->postJson('/api/modules/management-aset/v1/penyusutan/'.$periodId.'/reversal', ['reason' => 'Salah periode'])
            ->assertCreated();

        $this->assertSame(1200.0, $this->netBookValue($book));
        // Periode asal tetap final dan tidak ditulis ulang.
        $this->assertSame('final', DB::table('aset_tr_penyusutan_aset')->where('id', $periodId)->value('status'));
        $this->assertSame(2, DB::table('aset_tr_penyusutan_aset')->count());
    }

    /**
     * Ekspor lama berhenti ditulis sejak "Post penyusutan" (feed posting finance, TODO 11.4), untuk
     * buku yang di-post maupun memorandum, lewat finalisasi maupun pembalikan. Jurnal penyusutan kini
     * satu posting ringkas per proses post, dan pembaliknya mengikuti apakah periode aslinya sudah
     * di-post (`DepreciationPostingTest`).
     */
    public function test_finalisasi_dan_pembalikan_tidak_lagi_menulis_ekspor(): void
    {
        $memorandum = $this->scenario(['method' => 'straight_line', 'useful_life_periods' => 12], acquisition: 1200);
        $this->reverse($this->finalizedPeriod($memorandum));
        $diPost = $this->scenario(['method' => 'straight_line', 'useful_life_periods' => 12], acquisition: 1200, diPost: true);
        $this->reverse($this->finalizedPeriod($diPost));

        $this->assertSame(4, DB::table('aset_tr_penyusutan_aset')->count());
        $this->assertSame(0, DB::table('aset_tr_export_penyusutan')->count());
    }

    // ---- penyusun skenario -------------------------------------------------

    /**
     * Menyiapkan satu aset lengkap dengan bukunya dan mengembalikan id buku aset.
     *
     * Bukunya memorandum (`posting_layer = none`) kecuali `$diPost`, yang memberinya lapisan current.
     *
     * @param  array<string, mixed>  $profile
     */
    private function scenario(
        array $profile,
        float $acquisition = 1000,
        float $residual = 0,
        string $convention = 'full_month',
        string $placedInService = '2026-06-15',
        bool $diPost = false,
    ): string {
        $group = $this->master('group-aset', ['nama' => 'Group '.Str::random(6)]);
        $jenis = $this->master('jenis-aset', ['nama' => 'Jenis '.Str::random(6)]);
        $profilId = $this->profil('Profil '.Str::random(6), $profile);
        $buku = $this->master('buku-penyusutan', [
            'nama' => 'Buku '.Str::random(6),
            'depreciation_profile_id' => $profilId,
            'posting_layer' => $diPost ? 'current' : 'none',
        ]);
        $this->matrix($group, [[
            'buku_id' => $buku,
            'useful_life_periods' => $profile['useful_life_periods'] ?? null,
            'convention' => $convention,
        ]])->assertOk();

        $aset = $this->receive($group, $jenis, $acquisition, $residual, $placedInService);

        // Buku memorandum saja tidak cukup untuk menerima aset (area 9), jadi penerimaan menambah
        // buku uji yang di-post tetapi tidak menyusut. Yang dikembalikan tetap buku skenario ini.
        return (string) DB::table('aset_tr_buku_aset')->where('aset_id', $aset)->where('buku_id', $buku)->value('id');
    }

    /**
     * Menjalankan penyusutan bulan demi bulan dan mengembalikan nilainya per periode.
     *
     * @return list<float>
     */
    private function fastForward(string $bookId, int $periods): array
    {
        $amounts = [];
        for ($index = 1; $index <= $periods; $index++) {
            $amounts[] = $this->finalizeAmount($this->propose($bookId, $index));
        }

        return $amounts;
    }

    /** @return TestResponse<Response> */
    private function propose(string $bookId, int $monthOffset, ?float $consumption = null): TestResponse
    {
        $start = Carbon::parse('2026-07-01')->addMonthsNoOverflow($monthOffset - 1);

        return $this->proposeOn($bookId, $start->toDateString(), $start->copy()->endOfMonth()->toDateString(), $consumption);
    }

    /** @return TestResponse<Response> */
    private function proposeOn(string $bookId, string $start, string $end, ?float $consumption = null): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create'])
            ->postJson('/api/modules/management-aset/v1/penyusutan/proposal', array_filter([
                'buku_aset_id' => $bookId,
                'period_starts_on' => $start,
                'period_ends_on' => $end,
                'consumption_amount' => $consumption,
            ], fn ($value) => $value !== null));
    }

    /** @param TestResponse<Response> $proposal */
    private function finalizeAmount(TestResponse $proposal): float
    {
        $proposal->assertSuccessful();
        $id = (string) $proposal->json('data.id');
        $this->finalize($id);

        return (float) DB::table('aset_tr_penyusutan_aset')->where('id', $id)->value('amount');
    }

    private function finalizedPeriod(string $bookId): string
    {
        $periodId = (string) $this->propose($bookId, 1)->json('data.id');
        $this->finalize($periodId);

        return $periodId;
    }

    private function reverse(string $periodId): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.correct'])
            ->postJson('/api/modules/management-aset/v1/penyusutan/'.$periodId.'/reversal', ['reason' => 'Salah periode'])
            ->assertCreated();
    }

    private function finalize(string $periodId): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.finalize'])
            ->postJson('/api/modules/management-aset/v1/penyusutan/'.$periodId.'/finalisasi')
            ->assertOk();
    }

    private function netBookValue(string $bookId): float
    {
        return (float) DB::table('aset_tr_buku_aset')->where('id', $bookId)->value('net_book_value');
    }

    private function accumulated(string $bookId): float
    {
        return (float) DB::table('aset_tr_buku_aset')->where('id', $bookId)->value('accumulated_depreciation');
    }

    private function startDate(string $bookId): string
    {
        return substr((string) DB::table('aset_tr_buku_aset')->where('id', $bookId)->value('depreciation_start_on'), 0, 10);
    }

    /** @param array<string, mixed> $payload */
    private function master(string $resource, array $payload): string
    {
        return $this->sebagaiPengguna($this->tenantId, $this->permissionsFor($resource))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/'.$resource, $this->denganKodeKetik($resource, $payload))
            ->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $profile */
    private function profil(string $nama, array $profile): string
    {
        return $this->master('profil-penyusutan', [
            'nama' => $nama,
            'frequency' => 'monthly',
            'year_basis' => 'calendar',
            ...$profile,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return TestResponse<Response>
     */
    private function matrix(string $groupId, array $rows): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('group-aset'))
            ->putJson('/api/modules/management-aset/v1/group-aset/'.$groupId.'/buku-penyusutan', ['rows' => $rows]);
    }

    private function receive(string $group, string $jenis, float $acquisition, float $residual = 0, string $placedInService = '2026-06-15'): string
    {
        return $this->terimaAset($this->tenantId, [
            'legal_entity_id' => $this->legalEntityId,
            'nama' => 'Aset penyusutan ujung ke ujung',
            'group_aset_id' => $group, 'jenis_aset_id' => $jenis,
            'acquired_on' => '2026-06-01', 'placed_in_service_on' => $placedInService,
            'acquisition_value' => $acquisition, 'residual_value' => $residual,
            'currency_code' => 'IDR', 'usage_org_unit_id' => $this->orgUnitId,
        ]);
    }

    /** @return list<string> */
    private function permissionsFor(string $resource): array
    {
        return array_map(fn (string $a): string => 'management-aset.'.$resource.'.'.$a, ['read', 'create', 'update', 'archive']);
    }
}
