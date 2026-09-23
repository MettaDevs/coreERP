<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Models\FinanceReferenceAccount;
use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Modules\Apperp\ManagementAset\Models\master\AssetPostingGroup;
use Modules\Apperp\ManagementAset\Services\AssetPostingAccounts;
use Modules\Apperp\ManagementAset\Support\AcquisitionMethod;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Posting group aset (feed posting finance, TODO 8.1, 8.2, 8.5, 8.6.1): akun jurnal per group
 * aset dan tanggal berlaku, dipilih dari daftar akun referensi Core.
 */
class AssetPostingGroupTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private const API = '/api/modules/management-aset/v1/posting-group-aset';

    private const PERMISSION = 'management-aset.fixed-asset-posting-profiles.';

    private string $tenantId;

    /** @var array<string, string> */
    private array $akun = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        foreach ([
            'aset' => ['1452', '1-2300', 'Aset Tetap - Kendaraan', 'balance_sheet'],
            'akumulasi' => ['1453', '1-2390', 'Akumulasi Penyusutan - Kendaraan', 'balance_sheet'],
            'beban' => ['6510', '6-5100', 'Beban Penyusutan Kendaraan', 'profit_loss'],
            'hutang' => ['2110', '2-1100', 'Hutang Usaha', 'balance_sheet'],
            'hutang_lain' => ['2120', '2-1200', 'Hutang Lain-lain', 'balance_sheet'],
            'ppn' => ['1150', '1-1500', 'PPN Masukan', 'balance_sheet'],
        ] as $kunci => [$eksternal, $kode, $nama, $jenis]) {
            $this->akun[$kunci] = $this->akunReferensi($this->tenantId, $eksternal, $kode, $nama, $jenis);
        }
    }

    public function test_accounts_are_mapped_per_effective_date_and_the_matrix_shows_the_row_in_force(): void
    {
        $kendaraan = $this->group('KENDARAAN');
        $alkes = $this->group('ALKES');
        $besok = now()->addDays(30)->toDateString();

        $this->simpan($kendaraan, '2026-01-01', $this->wajib())
            ->assertCreated()
            ->assertJsonPath('data.effective_from', '2026-01-01')
            ->assertJsonPath('data.missing', []);
        // PUT ke alamat yang sama adalah pembaruan, bukan baris kedua.
        $this->simpan($kendaraan, '2026-01-01', [...$this->wajib(), 'payable_account_id' => $this->akun['hutang_lain'], 'input_vat_account_id' => $this->akun['ppn']])
            ->assertOk()
            ->assertJsonPath('data.payable_account_id', $this->akun['hutang_lain']);
        $this->simpan($kendaraan, $besok, [...$this->wajib(), 'depreciation_expense_account_id' => null])
            ->assertCreated()
            ->assertJsonPath('data.missing', ['depreciation_expense_account_id']);

        $matriks = $this->pengguna(['read'])->getJson(self::API)->assertOk();

        $this->assertSame(2, DB::table('aset_m_posting_group')->where('group_aset_id', $kendaraan)->count());
        $matriks->assertJsonPath('data.incomplete_groups', 1)
            ->assertJsonPath('data.accounts.0', ['column' => 'acquisition_account_id', 'label' => 'Harga perolehan', 'required' => true])
            ->assertJsonPath('data.accounts.4', ['column' => 'clearing_account_id', 'label' => 'Perantara', 'required' => false])
            ->assertJsonPath('data.account_details.'.$this->akun['hutang_lain'].'.code', '2-1200');
        $groups = $matriks->collect('data.groups')->keyBy('kode');
        // Baris masa depan tidak menggantikan baris yang berlaku hari ini.
        $this->assertSame('2026-01-01', $groups['KENDARAAN']['current']['effective_from']);
        $this->assertSame([$besok, '2026-01-01'], array_column($groups['KENDARAAN']['rows'], 'effective_from'));
        $this->assertSame([], $groups['KENDARAAN']['missing']);
        $this->assertNull($groups['ALKES']['current']);
        $this->assertSame(AssetPostingGroup::REQUIRED_ACCOUNTS, $groups['ALKES']['missing']);
        $this->assertSame($alkes, $groups['ALKES']['id']);
    }

    public function test_reading_creating_updating_and_archiving_are_separate_permissions(): void
    {
        $group = $this->group('KENDARAAN');

        $this->pengguna(['read'])->getJson(self::API)->assertOk();
        $this->simpan($group, '2026-01-01', $this->wajib(), ['read'])->assertForbidden();

        $this->simpan($group, '2026-01-01', $this->wajib(), ['read', 'create'])->assertCreated();
        $this->simpan($group, '2026-01-01', $this->wajib(), ['read', 'create'])
            ->assertForbidden()
            ->assertJsonPath('error.message', 'Hak '.self::PERMISSION.'update belum dimiliki pengguna pada tenant aktif.');

        $this->simpan($group, '2026-07-01', $this->wajib(), ['read', 'update'])->assertForbidden();
        $this->simpan($group, '2026-01-01', $this->wajib(), ['read', 'update'])->assertOk();

        $this->pengguna(['read', 'create', 'update'])->deleteJson(self::API.'/'.$group.'/2026-01-01')->assertForbidden();
        $this->pengguna(['archive'])->deleteJson(self::API.'/'.$group.'/2026-01-01')->assertNoContent();
    }

    public function test_only_active_accounts_valid_for_every_legal_entity_can_be_mapped(): void
    {
        $group = $this->group('KENDARAAN');
        $entitas = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $entitas, 'legal_entity');
        $khusus = $this->akunReferensi($this->tenantId, '1454', '1-2310', 'Kendaraan Entitas A', 'balance_sheet', $entitas);
        $nonaktif = $this->akunReferensi($this->tenantId, '1455', '1-2320', 'Kendaraan Lama', 'balance_sheet', active: false);
        $milikTenantLain = $this->akunReferensi($this->buatTenantUji(), '1452', '1-2300', 'Aset Tetap - Kendaraan', 'balance_sheet');

        $this->simpan($group, '2026-01-01', [
            ...$this->wajib(),
            'acquisition_account_id' => $khusus,
            'accumulated_depreciation_account_id' => $nonaktif,
            'depreciation_expense_account_id' => $milikTenantLain,
            'payable_account_id' => (string) Str::ulid(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'acquisition_account_id' => 'khusus satu entitas legal',
                'accumulated_depreciation_account_id' => 'nonaktif',
                'depreciation_expense_account_id' => 'tidak ada di daftar akun',
                'payable_account_id' => 'tidak ada di daftar akun',
            ]);
        $this->assertSame(0, DB::table('aset_m_posting_group')->count());

        // Akun yang dinonaktifkan sesudah dipetakan tidak menghalangi perbaikan kolom lain.
        $this->simpan($group, '2026-01-01', $this->wajib())->assertCreated();
        FinanceReferenceAccount::query()->whereKey($this->akun['akumulasi'])->update(['active' => false]);
        $this->simpan($group, '2026-01-01', [...$this->wajib(), 'input_vat_account_id' => $this->akun['ppn']])
            ->assertOk()
            ->assertJsonPath('data.accumulated_depreciation_account_id', $this->akun['akumulasi']);

        // Pemilih akun hanya menawarkan akun aktif yang berlaku untuk semua entitas.
        $pilihan = $this->pengguna(['read'])->getJson(self::API.'/akun?q=1-23')->assertOk()->collect('data')->pluck('code')->all();
        $this->assertSame(['1-2300'], $pilihan);
    }

    public function test_an_archived_row_leaves_the_matrix_and_its_date_can_be_used_again(): void
    {
        $group = $this->group('KENDARAAN');
        $this->simpan($group, '2026-01-01', $this->wajib())->assertCreated();

        $this->pengguna(['archive'])->deleteJson(self::API.'/'.$group.'/2026-01-01')->assertNoContent();
        $this->pengguna(['archive'])->deleteJson(self::API.'/'.$group.'/2026-01-01')->assertNotFound();

        $baris = $this->pengguna(['read'])->getJson(self::API)->collect('data.groups')->firstWhere('id', $group);
        $this->assertSame([], $baris['rows']);
        $this->assertSoftDeleted('aset_m_posting_group', ['group_aset_id' => $group]);

        $this->simpan($group, '2026-01-01', $this->wajib())->assertCreated();
        $this->assertSame(2, DB::table('aset_m_posting_group')->where('group_aset_id', $group)->count());
    }

    public function test_a_group_of_another_tenant_or_a_malformed_date_is_not_found(): void
    {
        $milikku = $this->group('KENDARAAN');
        $tenantku = $this->tenantId;
        $this->tenantId = $this->buatTenantUji();
        $milikLain = $this->group('KENDARAAN');
        $this->tenantId = $tenantku;

        $this->simpan($milikLain, '2026-01-01', [])->assertNotFound();
        $this->pengguna(['archive'])->deleteJson(self::API.'/'.$milikLain.'/2026-01-01')->assertNotFound();
        $this->simpan($milikku, '2026-13-01', [])->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->simpan($milikku, '01-01-2026', [])->assertNotFound();
        $this->assertSame(0, DB::table('aset_m_posting_group')->count());
    }

    public function test_the_row_in_force_is_read_by_posting_date_and_every_acquisition_method_uses_its_account(): void
    {
        $group = $this->group('KENDARAAN');
        $this->simpan($group, '2026-01-01', $this->wajib())->assertCreated();
        $this->simpan($group, '2026-07-01', [...$this->wajib(), 'acquisition_account_id' => $this->akun['ppn']])->assertCreated();
        $akun = app(AssetPostingAccounts::class);
        $berlaku = fn (string $tanggal): ?AssetPostingGroup => $this->app->make(PelaksanaUntukTenant::class)
            ->jalankanUntuk($this->tenantId, fn (): ?AssetPostingGroup => $akun->effective($group, $tanggal));

        $this->assertNull($berlaku('2025-12-31'));
        $this->assertSame('2026-01-01', $berlaku('2026-01-01')?->effective_from->toDateString());
        $this->assertSame('2026-01-01', $berlaku('2026-06-30')?->effective_from->toDateString());
        $juli = $berlaku('2026-07-01');
        $this->assertNotNull($juli);
        $this->assertSame('2026-07-01', $juli->effective_from->toDateString());
        // Tenant lain tidak pernah membaca posting group tenant ini.
        $this->assertNull($this->app->make(PelaksanaUntukTenant::class)
            ->jalankanUntuk($this->buatTenantUji(), fn (): ?AssetPostingGroup => $akun->effective($group, '2026-07-01')));

        foreach (AcquisitionMethod::ALL as $cara) {
            $this->assertSame($this->akun['ppn'], $akun->acquisitionAccount($juli, $cara));
        }
        $this->expectException(InvalidArgumentException::class);
        $akun->acquisitionAccount($juli, 'tukar_tambah');
    }

    /** @return array<string, string> */
    private function wajib(): array
    {
        return [
            'acquisition_account_id' => $this->akun['aset'],
            'accumulated_depreciation_account_id' => $this->akun['akumulasi'],
            'depreciation_expense_account_id' => $this->akun['beban'],
            'payable_account_id' => $this->akun['hutang'],
        ];
    }

    /**
     * @param  array<string, ?string>  $accounts
     * @param  list<string>  $actions
     * @return TestResponse<Response>
     */
    private function simpan(string $group, string $date, array $accounts, array $actions = ['read', 'create', 'update']): TestResponse
    {
        return $this->pengguna($actions)->putJson(self::API.'/'.$group.'/'.$date, $accounts);
    }

    /** @param  list<string>  $actions */
    private function pengguna(array $actions): static
    {
        return $this->sebagaiPengguna($this->tenantId, array_map(
            static fn (string $action): string => self::PERMISSION.$action,
            $actions,
        ));
    }

    private function group(string $kode): string
    {
        return (string) $this->sebagaiPengguna($this->tenantId, ['management-aset.group-aset.create'])
            ->withHeader('Idempotency-Key', 'group-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/group-aset', ['kode' => $kode, 'nama' => 'Group '.$kode])
            ->assertCreated()
            ->json('data.id');
    }

    private function akunReferensi(string $tenantId, string $eksternal, string $kode, string $nama, string $jenis, ?string $entitas = null, bool $active = true): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('finance_reference_accounts')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'legal_entity_id' => $entitas, 'external_id' => $eksternal,
            'code' => $kode, 'name' => $nama, 'type' => $jenis, 'active' => $active,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
