<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerimaAset;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Matriks group x buku menentukan aset dari satu group mendapat buku apa saja.
 * Inilah yang membuat penyusutan komersial dan fiskal dapat berjalan berdampingan
 * dengan aturan masing-masing, sesuai praktik lapangan.
 */
class DepreciationBookTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerimaAset, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    private int $issued = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
        Http::fake(fn () => Http::response(['data' => ['number' => 'NS-'.str_pad((string) ++$this->issued, 6, '0', STR_PAD_LEFT)]]));
    }

    public function test_aset_mendapat_satu_buku_untuk_tiap_baris_matriks(): void
    {
        $group = $this->master('group-aset', ['nama' => 'Kendaraan']);
        $jenis = $this->master('jenis-aset', ['nama' => 'Mobil']);
        $komersial = $this->profil('Garis lurus 60 bulan', 'straight_line', 60);
        $fiskal = $this->profil('Saldo menurun 25%', 'reducing_balance', 48, 25.0);

        $bukuKomersial = $this->master('buku-penyusutan', ['nama' => 'Komersial', 'posting_layer' => 'current', 'export_to_backoffice' => true, 'depreciation_profile_id' => $komersial]);
        $bukuFiskal = $this->master('buku-penyusutan', ['nama' => 'Fiskal', 'posting_layer' => 'tax', 'export_to_backoffice' => false, 'depreciation_profile_id' => $fiskal]);

        $this->matrix($group, [
            ['buku_id' => $bukuKomersial, 'useful_life_periods' => 60, 'convention' => 'full_month'],
            ['buku_id' => $bukuFiskal, 'useful_life_periods' => 48, 'convention' => 'full_month'],
        ])->assertOk()->assertJsonCount(2, 'data');

        $aset = $this->receive($group, $jenis, ['acquisition_value' => 240000000, 'placed_in_service_on' => '2026-03-20']);

        $books = DB::table('aset_tr_buku_aset')->where('aset_id', $aset)->orderBy('useful_life_periods')->get();
        $this->assertCount(2, $books, 'satu baris matriks menghasilkan satu buku');
        $this->assertSame([48, 60], $books->pluck('useful_life_periods')->map(fn ($v) => (int) $v)->all());
        // Konvensi `full_month` menarik awal penyusutan ke hari pertama bulan itu.
        $this->assertSame('2026-03-01', substr((string) $books->first()->depreciation_start_on, 0, 10));
    }

    public function test_buku_baru_tidak_mengaktifkan_bridge_finance_secara_default(): void
    {
        $book = $this->master('buku-penyusutan', ['nama' => 'Buku tanpa bridge']);

        $this->assertFalse((bool) DB::table('aset_m_buku_penyusutan')->where('id', $book)->value('export_to_backoffice'));
    }

    public function test_aset_di_bawah_ambang_kapitalisasi_tetap_tercatat_tetapi_tidak_menyusut(): void
    {
        $group = $this->master('group-aset', ['nama' => 'Inventaris', 'capitalization_threshold' => 1000000]);
        $jenis = $this->master('jenis-aset', ['nama' => 'Meja']);
        $profil = $this->profil('Garis lurus', 'straight_line', 48);
        $buku = $this->master('buku-penyusutan', ['nama' => 'Komersial', 'depreciation_profile_id' => $profil]);
        $this->matrix($group, [['buku_id' => $buku, 'useful_life_periods' => 48]])->assertOk();

        $murah = $this->receive($group, $jenis, ['acquisition_value' => 500000]);
        $mahal = $this->receive($group, $jenis, ['acquisition_value' => 5000000]);

        $this->assertFalse((bool) DB::table('aset_tr_buku_aset')->where('aset_id', $murah)->value('depreciate'));
        $this->assertTrue((bool) DB::table('aset_tr_buku_aset')->where('aset_id', $mahal)->value('depreciate'));
    }

    public function test_buku_yang_tidak_disusutkan_menolak_proposal(): void
    {
        $group = $this->master('group-aset', ['nama' => 'Inventaris', 'capitalization_threshold' => 1000000]);
        $jenis = $this->master('jenis-aset', ['nama' => 'Kursi']);
        $profil = $this->profil('Garis lurus', 'straight_line', 48);
        $buku = $this->master('buku-penyusutan', ['nama' => 'Komersial', 'depreciation_profile_id' => $profil]);
        $this->matrix($group, [['buku_id' => $buku, 'useful_life_periods' => 48]])->assertOk();
        $aset = $this->receive($group, $jenis, ['acquisition_value' => 500000]);
        $bookId = DB::table('aset_tr_buku_aset')->where('aset_id', $aset)->value('id');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create'])
            ->postJson('/api/modules/management-aset/v1/penyusutan/proposal', [
                'buku_aset_id' => $bookId, 'period_starts_on' => '2026-04-01', 'period_ends_on' => '2026-04-30',
            ])->assertStatus(422);
    }

    public function test_matriks_mengganti_seluruh_himpunan_dan_mengarsipkan_baris_yang_hilang(): void
    {
        $group = $this->master('group-aset', ['nama' => 'Bangunan']);
        $profil = $this->profil('Garis lurus', 'straight_line', 240);
        $satu = $this->master('buku-penyusutan', ['nama' => 'Komersial', 'depreciation_profile_id' => $profil]);
        $dua = $this->master('buku-penyusutan', ['nama' => 'Fiskal', 'depreciation_profile_id' => $profil]);

        $this->matrix($group, [['buku_id' => $satu], ['buku_id' => $dua]])->assertOk()->assertJsonCount(2, 'data');
        // Kiriman berikutnya hanya memuat satu baris; sisanya diarsipkan, bukan dihapus.
        $this->matrix($group, [['buku_id' => $satu, 'useful_life_periods' => 240]])->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(1, DB::table('aset_m_group_buku_penyusutan')->whereNull('deleted_at')->count());
        $this->assertSame(1, DB::table('aset_m_group_buku_penyusutan')->whereNotNull('deleted_at')->count());
    }

    public function test_matriks_menolak_buku_tanpa_profil_efektif(): void
    {
        $group = $this->master('group-aset', ['nama' => 'Group tanpa profil']);
        $buku = $this->master('buku-penyusutan', ['nama' => 'Buku tanpa profil']);

        $this->matrix($group, [['buku_id' => $buku]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows.0.depreciation_profile_id');

        $this->assertSame(0, DB::table('aset_m_group_buku_penyusutan')->where('group_aset_id', $group)->count());
    }

    public function test_profil_yang_sudah_dipakai_buku_aset_tidak_dapat_diubah(): void
    {
        $group = $this->master('group-aset', ['nama' => 'Group immutable']);
        $jenis = $this->master('jenis-aset', ['nama' => 'Jenis immutable']);
        $profil = $this->profil('Profil immutable', 'straight_line', 12);
        $buku = $this->master('buku-penyusutan', ['nama' => 'Buku immutable', 'depreciation_profile_id' => $profil]);
        $this->matrix($group, [['buku_id' => $buku]])->assertOk();
        $this->receive($group, $jenis);

        $this->sebagaiPengguna($this->tenantId, $this->permissionsFor('profil-penyusutan'))
            ->patchJson('/api/modules/management-aset/v1/profil-penyusutan/'.$profil, ['useful_life_periods' => 24])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');
    }

    public function test_matriks_memakai_hak_akses_group_dan_menolak_buku_tenant_lain(): void
    {
        $group = $this->master('group-aset', ['nama' => 'Mesin']);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.group-aset.read'])
            ->putJson('/api/modules/management-aset/v1/group-aset/'.$group.'/buku-penyusutan', ['rows' => []])
            ->assertForbidden();

        $foreignTenant = (string) Str::ulid();
        $foreignBuku = $this->master('buku-penyusutan', ['nama' => 'Buku Tenant Lain'], tenantId: $foreignTenant);
        $this->matrix($group, [['buku_id' => $foreignBuku]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows.0.buku_id');
    }

    public function test_round_off_matrix_mengalahkan_book_dan_kosong_mengikuti_book(): void
    {
        $jenis = $this->master('jenis-aset', ['nama' => 'Peralatan']);
        $profil = $this->profil('Garis lurus', 'straight_line', 3);
        $buku = $this->master('buku-penyusutan', [
            'nama' => 'Komersial',
            'depreciation_profile_id' => $profil,
            'round_off_depreciation' => 10,
        ]);

        $groupDenganOverride = $this->master('group-aset', ['nama' => 'Group override']);
        $this->matrix($groupDenganOverride, [[
            'buku_id' => $buku,
            'useful_life_periods' => 3,
            'round_off_depreciation' => 100,
        ]])->assertOk();
        $asetOverride = $this->receive($groupDenganOverride, $jenis, ['acquisition_value' => 1000]);

        $groupDenganFallback = $this->master('group-aset', ['nama' => 'Group fallback']);
        $this->matrix($groupDenganFallback, [[
            'buku_id' => $buku,
            'useful_life_periods' => 3,
        ]])->assertOk();
        $asetFallback = $this->receive($groupDenganFallback, $jenis, ['acquisition_value' => 1000]);

        $this->assertSame(100.0, (float) DB::table('aset_tr_buku_aset')->where('aset_id', $asetOverride)->value('round_off_depreciation'));
        $this->assertSame(10.0, (float) DB::table('aset_tr_buku_aset')->where('aset_id', $asetFallback)->value('round_off_depreciation'));
    }

    /** @param array<string, mixed> $payload */
    private function master(string $resource, array $payload, ?string $tenantId = null): string
    {
        return $this->sebagaiPengguna($tenantId ?? $this->tenantId, $this->permissionsFor($resource))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/'.$resource, $payload)
            ->assertCreated()
            ->json('data.id');
    }

    private function profil(string $nama, string $method, int $usefulLife, ?float $rate = null): string
    {
        return $this->master('profil-penyusutan', array_filter([
            'nama' => $nama, 'method' => $method, 'frequency' => 'monthly', 'year_basis' => 'calendar',
            'useful_life_periods' => $usefulLife, 'rate_percent' => $rate,
        ], fn ($value) => $value !== null));
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

    /** @param array<string, mixed> $overrides */
    private function receive(string $group, string $jenis, array $overrides = []): string
    {
        return $this->terimaAset($this->tenantId, [
            'legal_entity_id' => $this->legalEntityId,
            'nama' => 'Aset buku penyusutan uji',
            'group_aset_id' => $group, 'jenis_aset_id' => $jenis,
            'acquired_on' => '2026-03-20', 'currency_code' => 'IDR',
            'usage_org_unit_id' => $this->orgUnitId,
            'acquisition_value' => 1000000,
            ...$overrides,
        ]);
    }

    /** @return list<string> */
    private function permissionsFor(string $resource): array
    {
        return array_map(fn (string $a): string => 'management-aset.'.$resource.'.'.$a, ['read', 'create', 'update', 'archive']);
    }
}
