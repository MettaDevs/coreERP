<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Models\CurrencyPrecision;
use App\Models\FinancePosting;
use App\Models\FinanceReferenceAccount;
use App\Models\OrganizationHierarchyVersion;
use App\Support\Finance\MoneyPrecision;
use Brick\Math\BigDecimal;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerbitkanJurnalPenerimaan;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * "Post penyusutan" dan jurnal pembaliknya (feed posting finance, TODO 11.5; K-14, K-15): satu posting
 * ringkas per entitas legal × buku × periode yang totalnya sama persis dengan register, proses kedua
 * yang pulang kosong, pembalikan yang mengikuti apakah periode aslinya sudah di-post, dan ekspor lama
 * yang berhenti ditulis.
 *
 * Panggungnya klinik dengan dua department — Poli Umum dan UGD — supaya beban (akun laba rugi) terlihat
 * dipecah per department sedangkan akumulasi (akun neraca) diringkas per business unit.
 */
class DepreciationPostingTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerbitkanJurnalPenerimaan, RefreshDatabase;

    private const MULAI = '2026-10-01';

    private const AKHIR = '2026-10-31';

    private string $ugd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJurnalPenerimaan();
        $this->akun['beban'] = FinanceReferenceAccount::query()->create([
            'tenant_id' => $this->tenantId, 'legal_entity_id' => null, 'external_id' => '6510',
            'code' => '6-5100', 'name' => 'Beban Penyusutan Kendaraan', 'type' => FinanceReferenceAccount::PROFIT_LOSS, 'active' => true,
        ])->id;
        $this->ugd = $this->departemenBaru('UGD', 'UGD');
    }

    public function test_one_posting_summarises_the_period_per_group_and_dimension_and_equals_the_register(): void
    {
        [$group, $komersial] = $this->groupMenyusut('KENDARAAN', 'Kendaraan');
        $this->petakanPenyusutan($group);
        $this->terima($group, $this->poli, 2, 48000000);
        $this->terima($group, $this->ugd, 1, 96000000);
        $this->usulkanDanFinalkan();

        $hasil = $this->jalankanPost($komersial)->assertCreated()->json('data');
        $postingId = 'AST-DEP-'.$this->le.'-'.$komersial.'-20261031-1';
        $this->assertSame($postingId, $hasil['posting']['posting_id']);
        $this->assertSame(['pending', 3, '4000000.00', '4000000.00'], [$hasil['posting']['status'], $hasil['assets'], $hasil['register_total'], $hasil['posting']['total']]);

        $posting = FinancePosting::query()->where('posting_id', $postingId)->firstOrFail();
        $payload = $posting->payload;
        $this->assertSame('asset.depreciation', $payload['posting_type']);
        // Jurnalnya bertanggal akhir periode, begitu juga tanggal dokumennya (TODO 11.2.8).
        $this->assertSame([self::AKHIR, self::AKHIR], [$payload['posting_date'], $payload['document_date']]);
        $this->assertSame('Penyusutan buku KOM-KENDARAAN s.d. 31/10/2026', $payload['source_document']['description']);
        // Beban per department (akun laba rugi: BU + department), akumulasi per business unit.
        $this->assertEqualsCanonicalizing([
            ['6-5100', '2000000.00', '0.00', ['BUSINESS_UNIT:KLN-A', 'DEPARTMENT:POLI-UMUM']],
            ['6-5100', '2000000.00', '0.00', ['BUSINESS_UNIT:KLN-A', 'DEPARTMENT:UGD']],
        ], array_slice($this->barisJurnal($payload), 0, 2));
        $this->assertSame([['1-2390', '0.00', '4000000.00', ['BUSINESS_UNIT:KLN-A']]], array_slice($this->barisJurnal($payload), 2));
        $this->assertSame('Beban penyusutan s.d. 31/10/2026 · Kendaraan', $payload['journal_lines'][0]['description']);

        // Total posting sama dengan jumlah periode di register, sampai ke sen (TODO 11.5.1).
        $register = (string) DB::table('aset_tr_penyusutan_aset')->where('posted_posting_id', $postingId)->sum('amount');
        $this->assertSame('4000000.00', $register);
        $this->assertSame(['debit' => '4000000.00', 'credit' => '4000000.00'], $payload['totals']);
        $this->assertTrue(BigDecimal::of($register)->isEqualTo((string) $posting->total_debit));
        $this->assertCount(3, $payload['details']['assets']);
        $this->assertSame(3, DB::table('aset_tr_penyusutan_aset')->where('posted_posting_id', $postingId)->count());
        // Buku fiskal menyusut di register, tetapi tidak pernah di-post.
        $this->assertSame(3, DB::table('aset_tr_penyusutan_aset')->where('status', 'final')->whereNull('posted_posting_id')->count());
    }

    public function test_a_second_run_is_empty_and_periods_finalised_later_get_the_next_sequence(): void
    {
        [$group, $komersial] = $this->groupMenyusut('KENDARAAN', 'Kendaraan');
        $this->petakanPenyusutan($group);
        $this->terima($group, $this->poli, 2, 48000000);
        $periode = $this->usulkan($komersial);
        $this->finalkan($periode[0]);

        $pertama = $this->jalankanPost($komersial)->assertCreated()->json('data');
        $this->assertSame([1, '1000000.00'], [$pertama['assets'], $pertama['register_total']]);

        // Proses kedua untuk buku dan periode yang sama pulang kosong (TODO 11.5.2).
        $kedua = $this->jalankanPost($komersial)->assertOk()->json('data');
        $this->assertNull($kedua['posting']);
        $this->assertSame(['proposed' => 1, 'posted' => 1, 'reversed' => 0, 'other_book' => 0], $kedua['skipped']);

        $this->finalkan($periode[1]);
        $ketiga = $this->jalankanPost($komersial)->assertCreated()->json('data');
        $this->assertSame('AST-DEP-'.$this->le.'-'.$komersial.'-20261031-2', $ketiga['posting']['posting_id']);
        $this->assertSame(1, $ketiga['assets']);
        $this->assertSame(2, FinancePosting::query()->where('posting_type', 'asset.depreciation')->count());
    }

    public function test_a_book_that_never_posts_is_refused_with_a_clear_message(): void
    {
        [$group, , $fiskal] = $this->groupMenyusut('KENDARAAN', 'Kendaraan');
        $this->petakanPenyusutan($group);
        $this->terima($group, $this->poli, 1, 48000000);
        $this->usulkanDanFinalkan();

        $pesan = 'Buku FIS-KENDARAAN tidak di-post ke aplikasi finance karena lapisan posting-nya none. Penyusutannya tetap tercatat di register aset.';
        $this->pratinjauPost($fiskal)->assertOk()
            ->assertJsonPath('data.blockers.0.message', $pesan)
            ->assertJsonPath('data.posting', null);
        $this->jalankanPost($fiskal)->assertStatus(422)->assertJsonValidationErrors(['buku_id' => $pesan]);
        $this->assertSame(0, FinancePosting::query()->where('posting_type', 'asset.depreciation')->count());

        // Daftar periode membawa lapisan posting bukunya, supaya layar tidak menawarkan buku ini untuk di-post.
        $lapisan = $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.read'])
            ->getJson(self::API.'penyusutan')->assertOk()->collect('data')->pluck('posting_layer', 'book_code')->sortKeys()->all();
        $this->assertSame(['FIS-KENDARAAN' => 'none', 'KOM-KENDARAAN' => 'current'], $lapisan);
    }

    public function test_only_the_book_that_posted_the_acquisition_sends_depreciation(): void
    {
        // Dua buku berlapisan bukan none: jurnal perolehan lewat buku current (K-26), jadi hanya buku
        // itu yang mengirim penyusutan. Buku operasional tetap menyusut di register saja.
        [$group, $komersial, $operasional] = $this->groupMenyusut('KENDARAAN', 'Kendaraan', lapisanKedua: 'operations');
        $this->petakanPenyusutan($group);
        $this->terima($group, $this->poli, 1, 48000000);
        $this->usulkanDanFinalkan();

        $this->pratinjauPost($operasional)->assertOk()
            ->assertJsonPath('data.assets', 0)
            ->assertJsonPath('data.skipped.other_book', 1)
            ->assertJsonPath('data.posting', null);
        $this->jalankanPost($operasional)->assertOk()->assertJsonPath('data.posting', null);
        $this->jalankanPost($komersial)->assertCreated()->assertJsonPath('data.assets', 1);
    }

    public function test_amounts_finer_than_the_currency_precision_hold_the_run(): void
    {
        [$group, $komersial] = $this->groupMenyusut('KENDARAAN', 'Kendaraan', masa: 3);
        $this->petakanPenyusutan($group);
        $this->terima($group, $this->poli, 1, 1000000);
        $this->usulkanDanFinalkan();
        $this->assertSame('333333.33', (string) DB::table('aset_tr_penyusutan_aset')->where('status', 'final')->value('amount'));

        CurrencyPrecision::query()->create(['tenant_id' => $this->tenantId, 'currency_code' => 'IDR', 'amount_decimals' => 0, 'unit_amount_decimals' => 0]);
        app(MoneyPrecision::class)->forget();

        $this->jalankanPost($komersial)->assertStatus(422)->assertJsonValidationErrors([
            'buku_id' => 'Penyusutan 1 aset di buku KOM-KENDARAAN lebih halus dari presisi IDR (0 desimal), jadi jurnalnya tidak akan sama persis dengan register. Atur pembulatan penyusutan di matriks group x buku (Master data › Group aset) supaya penyusutan berikutnya sesuai presisi.',
        ]);
        $this->assertNull(DB::table('aset_tr_penyusutan_aset')->where('status', 'final')->value('posted_posting_id'));
    }

    public function test_reversing_a_posted_period_reverses_that_asset_only_at_the_original_date(): void
    {
        [$group, $komersial] = $this->groupMenyusut('KENDARAAN', 'Kendaraan');
        $this->petakanPenyusutan($group);
        $this->terima($group, $this->poli, 2, 48000000);
        $periode = $this->usulkan($komersial);
        $this->finalkan($periode[0]);
        $this->finalkan($periode[1]);
        $asal = (string) $this->jalankanPost($komersial)->assertCreated()->json('data.posting.posting_id');

        $jawab = $this->balikkan($periode[0])->assertCreated()->json('data');
        $postingBalik = 'AST-DRV-'.$jawab['period']['id'];
        $this->assertSame(['posting_id' => $postingBalik, 'status' => 'pending'], $jawab['posting']);
        $this->assertSame($postingBalik, DB::table('aset_tr_penyusutan_aset')->where('id', $jawab['period']['id'])->value('posted_posting_id'));

        // Jurnal balik merujuk posting asal, bertanggal periode asal, untuk porsi aset itu saja (TODO 11.5.3).
        $payload = FinancePosting::query()->where('posting_id', $postingBalik)->firstOrFail()->payload;
        $this->assertSame(['asset.depreciation_reversal', $asal, self::AKHIR, self::AKHIR], [$payload['posting_type'], $payload['reverses_posting_id'], $payload['posting_date'], $payload['document_date']]);
        $this->assertSame([
            ['1-2390', '1000000.00', '0.00', ['BUSINESS_UNIT:KLN-A']],
            ['6-5100', '0.00', '1000000.00', ['BUSINESS_UNIT:KLN-A', 'DEPARTMENT:POLI-UMUM']],
        ], $this->barisJurnal($payload));
        $this->assertCount(1, $payload['details']['assets']);
    }

    public function test_reversing_before_posting_publishes_nothing_and_the_period_never_posts(): void
    {
        [$group, $komersial] = $this->groupMenyusut('KENDARAAN', 'Kendaraan');
        $this->petakanPenyusutan($group);
        $this->terima($group, $this->poli, 2, 48000000);
        $periode = $this->usulkan($komersial);
        $this->finalkan($periode[0]);
        $this->finalkan($periode[1]);

        // Belum di-post: pembalikan tidak menerbitkan apa pun (TODO 11.5.4).
        $this->balikkan($periode[0])->assertCreated()->assertJsonPath('data.posting', null);
        $this->assertSame(0, FinancePosting::query()->whereIn('posting_type', ['asset.depreciation', 'asset.depreciation_reversal'])->count());

        // Dan periode yang sudah dibalik tidak ikut proses post: bebannya sudah ditiadakan.
        $hasil = $this->jalankanPost($komersial)->assertCreated()->json('data');
        $this->assertSame([1, 1], [$hasil['assets'], $hasil['skipped']['reversed']]);
        $this->assertNull(DB::table('aset_tr_penyusutan_aset')->where('id', $periode[0])->value('posted_posting_id'));
    }

    public function test_a_period_reversed_while_the_run_waits_for_its_lock_is_left_out(): void
    {
        [$group, $komersial] = $this->groupMenyusut('KENDARAAN', 'Kendaraan');
        $this->petakanPenyusutan($group);
        $this->terima($group, $this->poli, 2, 48000000);
        $periode = $this->usulkan($komersial);
        $this->finalkan($periode[0]);
        $this->finalkan($periode[1]);

        // Pembalikan yang selesai di antara pembacaan pertama proses post dan kuncinya: proses post
        // wajib membaca ulang sesudah kunci didapat, bukan mem-post periode yang bebannya sudah
        // ditiadakan tanpa jurnal baliknya.
        $asli = DB::table('aset_tr_penyusutan_aset')->where('id', $periode[0])->first();
        $sisipkan = true;
        DB::listen(function (QueryExecuted $query) use (&$sisipkan, $asli): void {
            if (! $sisipkan || ! str_contains($query->sql, 'as reversed')) {
                return;
            }
            $sisipkan = false;
            DB::table('aset_tr_penyusutan_aset')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $asli->tenant_id, 'buku_aset_id' => $asli->buku_aset_id,
                'legal_entity_id' => $asli->legal_entity_id, 'usage_org_unit_id' => $asli->usage_org_unit_id,
                'period_starts_on' => $asli->period_starts_on, 'period_ends_on' => $asli->period_ends_on,
                'amount' => -1 * (float) $asli->amount, 'status' => 'final', 'reverses_period_id' => $asli->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $hasil = $this->jalankanPost($komersial)->assertCreated()->json('data');
        $this->assertFalse($sisipkan, 'Pembalikan sisipan tidak pernah terjadi.');
        $this->assertSame([1, 1], [$hasil['assets'], $hasil['skipped']['reversed']]);
        $this->assertNull(DB::table('aset_tr_penyusutan_aset')->where('id', $periode[0])->value('posted_posting_id'));
    }

    public function test_finalising_and_reversing_no_longer_write_the_old_export(): void
    {
        [$group, $komersial] = $this->groupMenyusut('KENDARAAN', 'Kendaraan');
        $this->petakanPenyusutan($group);
        $this->terima($group, $this->poli, 1, 48000000);
        $periode = $this->usulkan($komersial);
        $this->finalkan($periode[0])->assertJsonPath('data.period.status', 'final')->assertJsonMissingPath('data.export');
        $this->jalankanPost($komersial)->assertCreated();
        $this->balikkan($periode[0])->assertCreated()->assertJsonMissingPath('data.export');

        $this->assertSame(0, DB::table('aset_tr_export_penyusutan')->count());
    }

    public function test_a_user_scoped_to_one_unit_posts_only_that_units_depreciation(): void
    {
        [$group, $komersial] = $this->groupMenyusut('KENDARAAN', 'Kendaraan');
        $this->petakanPenyusutan($group);
        $this->terima($group, $this->poli, 2, 48000000);
        $this->terima($group, $this->ugd, 1, 96000000);
        $this->usulkanDanFinalkan();

        $poli = $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.post'], [[
            'policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $this->le,
            'organization_id' => $this->poli, 'include_descendants' => false,
        ]])->postJson(self::API.'penyusutan/posting', $this->masukanPost($komersial))->assertCreated()->json('data');
        $this->assertSame([2, '2000000.00'], [$poli['assets'], $poli['register_total']]);

        // Sisanya — UGD — di-post pengguna lain dengan nomor urut berikutnya, bukan nomor yang sama.
        $sisa = $this->jalankanPost($komersial)->assertCreated()->json('data');
        $this->assertSame([1, 'AST-DEP-'.$this->le.'-'.$komersial.'-20261031-2'], [$sisa['assets'], $sisa['posting']['posting_id']]);

        // Pengguna yang hanya berwenang di entitas legal lain ditolak, bukan dijawab kosong.
        $lain = $this->organisasi(['classification' => 'legal_entity', 'name' => 'PT Metta Lain', 'company_code' => 'LAIN', 'country_code' => 'ID']);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.post'], [[
            'policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $lain,
            'organization_id' => $lain, 'include_descendants' => true,
        ]])->postJson(self::API.'penyusutan/posting', $this->masukanPost($komersial))->assertForbidden();
    }

    public function test_the_preview_shows_the_journal_the_run_publishes_and_an_unmapped_group_holds_it(): void
    {
        [$group, $komersial] = $this->groupMenyusut('KENDARAAN', 'Kendaraan');
        $this->petakan($group, ['acquisition_account_id' => $this->akun['kendaraan'], 'accumulated_depreciation_account_id' => $this->akun['akumulasi'], 'payable_account_id' => $this->akun['hutang']]);
        $this->terima($group, $this->poli, 1, 48000000);
        $this->usulkanDanFinalkan();

        $pratinjau = $this->pratinjauPost($komersial)->assertOk()->json('data');
        $this->assertSame(['held', 1, '1000000.00'], [$pratinjau['posting']['status'], $pratinjau['assets'], $pratinjau['register_total']]);
        $this->assertSame(['Group KENDARAAN · beban penyusutan belum dipetakan ke akun.'], array_column($pratinjau['posting']['problems'], 'message'));
        $this->assertSame(0, DB::table('aset_tr_penyusutan_aset')->whereNotNull('posted_posting_id')->count());

        // Pemetaan yang kosong tidak menahan proses: posting terbit `held` dan periodenya tetap
        // ditandai, sehingga validasi ulang sesudah dipetakan tidak butuh proses post kedua (K-18).
        $hasil = $this->jalankanPost($komersial)->assertCreated()->json('data');
        $this->assertSame($pratinjau['posting']['lines'], $hasil['posting']['lines']);
        $posting = FinancePosting::query()->where('posting_id', $hasil['posting']['posting_id'])->firstOrFail();
        $this->assertSame('held', $posting->status);

        $this->petakanPenyusutan($group);
        $this->actingAs($this->owner)->postJson('/api/v1/finance-postings/'.$posting->id.'/revalidate')->assertOk();
        $this->assertSame('pending', $posting->refresh()->status);
    }

    public function test_posting_needs_its_own_permission(): void
    {
        [, $komersial] = $this->groupMenyusut('KENDARAAN', 'Kendaraan');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.read', 'management-aset.penyusutan.create', 'management-aset.penyusutan.finalize', 'management-aset.penyusutan.correct'])
            ->postJson(self::API.'penyusutan/posting', $this->masukanPost($komersial))
            ->assertForbidden();
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.read'])
            ->getJson(self::API.'penyusutan/posting/pratinjau?'.http_build_query($this->masukanPost($komersial)))
            ->assertForbidden();
    }

    // ---- penyusun skenario -------------------------------------------------

    /**
     * Group yang menyusut di dua buku: buku komersial berlapisan current dan buku kedua (bawaannya fiskal,
     * lapisan none), garis lurus bulanan dengan konvensi bulan penuh.
     *
     * @return array{0: string, 1: string, 2: string} group, buku komersial, buku kedua
     */
    private function groupMenyusut(string $kode, string $nama, int $masa = 48, string $lapisanKedua = 'none'): array
    {
        $group = $this->groupTanpaBuku($kode, $nama);
        $komersial = $this->bukuBerprofil('KOM-'.$kode, 'Komersial '.$nama, 'current', $masa);
        $kedua = $this->bukuBerprofil(($lapisanKedua === 'none' ? 'FIS-' : 'OPS-').$kode, 'Buku kedua '.$nama, $lapisanKedua, $masa);
        $this->sebagaiPengguna($this->tenantId, $this->izin('group-aset'))
            ->putJson(self::API.'group-aset/'.$group.'/buku-penyusutan', ['rows' => [
                ['buku_id' => $komersial, 'useful_life_periods' => $masa, 'convention' => 'full_month', 'depreciate' => true],
                ['buku_id' => $kedua, 'useful_life_periods' => $masa, 'convention' => 'full_month', 'depreciate' => true],
            ]])->assertOk();

        return [$group, $komersial, $kedua];
    }

    private function bukuBerprofil(string $kode, string $nama, string $postingLayer, int $masa): string
    {
        $profil = $this->master('profil-penyusutan', [
            'nama' => 'Profil '.$nama, 'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar',
            'useful_life_periods' => $masa, 'convention' => 'full_month',
        ]);

        return $this->master('buku-penyusutan', ['kode' => $kode, 'nama' => $nama, 'posting_layer' => $postingLayer, 'depreciation_profile_id' => $profil]);
    }

    /** @param  array<string, mixed>  $payload */
    private function master(string $resource, array $payload): string
    {
        return (string) $this->sebagaiPengguna($this->tenantId, $this->izin($resource))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::ulid())
            ->postJson(self::API.$resource, $payload)
            ->assertCreated()->json('data.id');
    }

    /** @return list<string> */
    private function izin(string $resource): array
    {
        return array_map(static fn (string $aksi): string => 'management-aset.'.$resource.'.'.$aksi, ['read', 'create', 'update', 'archive']);
    }

    private function petakanPenyusutan(string $group): void
    {
        $this->petakan($group, [
            'acquisition_account_id' => $this->akun['kendaraan'],
            'accumulated_depreciation_account_id' => $this->akun['akumulasi'],
            'depreciation_expense_account_id' => $this->akun['beban'],
            'payable_account_id' => $this->akun['hutang'],
        ]);
    }

    /** Menerima `$jumlah` aset bernilai `$nilai` yang dipakai `$unit`, lalu menyelesaikannya. */
    private function terima(string $group, string $unit, int $jumlah, int $nilai): void
    {
        $id = $this->draf(['responsible_org_unit_id' => $unit, 'receiving_org_unit_id' => $unit], [$this->baris($group, $jumlah, $nilai)]);
        $this->selesaikan($id)->assertOk();
    }

    /** Department baru di bawah klinik, lewat versi hierarki manajemen berikutnya. */
    private function departemenBaru(string $nama, string $nomor): string
    {
        $id = $this->organisasi(['classification' => 'operating_unit', 'name' => $nama, 'operating_unit_type' => 'department', 'operating_unit_number' => $nomor]);
        $terbit = OrganizationHierarchyVersion::query()->where('status', 'published')
            ->whereHas('hierarchy', fn ($query) => $query->where('name', 'Struktur manajemen'))->firstOrFail();
        $this->actingAs($this->owner)->post("/settings/organization/hierarchy-versions/{$terbit->id}/drafts", ['effective_from' => '2026-06-01'])->assertSessionHasNoErrors();
        $draf = OrganizationHierarchyVersion::query()->where('status', 'draft')->where('hierarchy_id', $terbit->hierarchy_id)->firstOrFail();
        $this->post("/settings/organization/hierarchy-versions/{$draf->id}/placements", ['organization_id' => $id, 'parent_organization_id' => $this->klinik])->assertSessionHasNoErrors();
        $this->post("/settings/organization/hierarchy-versions/{$draf->id}/publish")->assertSessionHasNoErrors();

        return $id;
    }

    /**
     * Usulan penyusutan Oktober 2026 untuk seluruh aset buku `$bukuId`.
     *
     * @return list<string> Id periode, urut kode aset.
     */
    private function usulkan(string $bukuId): array
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create'])
            ->postJson(self::API.'penyusutan/proposal-massal', ['period_starts_on' => self::MULAI, 'period_ends_on' => self::AKHIR, 'buku_id' => $bukuId])
            ->assertCreated();

        return array_values(DB::table('aset_tr_penyusutan_aset as p')
            ->join('aset_tr_buku_aset as b', 'b.id', '=', 'p.buku_aset_id')
            ->join('aset_tr_aset as a', 'a.id', '=', 'b.aset_id')
            ->where('b.buku_id', $bukuId)->whereNull('p.reverses_period_id')
            ->orderBy('a.kode')->pluck('p.id')->map(static fn ($id): string => (string) $id)->all());
    }

    /** Usulan Oktober 2026 untuk seluruh buku, lalu seluruhnya difinalkan. */
    private function usulkanDanFinalkan(): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create'])
            ->postJson(self::API.'penyusutan/proposal-massal', ['period_starts_on' => self::MULAI, 'period_ends_on' => self::AKHIR])
            ->assertCreated();
        foreach (DB::table('aset_tr_penyusutan_aset')->where('status', 'proposed')->pluck('id') as $id) {
            $this->finalkan((string) $id);
        }
    }

    /** @return TestResponse<Response> */
    private function finalkan(string $periodeId): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.finalize'])
            ->postJson(self::API.'penyusutan/'.$periodeId.'/finalisasi')->assertOk();
    }

    /** @return TestResponse<Response> */
    private function balikkan(string $periodeId): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.correct'])
            ->postJson(self::API.'penyusutan/'.$periodeId.'/reversal', ['reason' => 'Salah periode']);
    }

    /** @return array{legal_entity_id: string, buku_id: string, period_ends_on: string} */
    private function masukanPost(string $bukuId): array
    {
        return ['legal_entity_id' => $this->le, 'buku_id' => $bukuId, 'period_ends_on' => self::AKHIR];
    }

    /** @return TestResponse<Response> */
    private function jalankanPost(string $bukuId): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.post'])
            ->postJson(self::API.'penyusutan/posting', $this->masukanPost($bukuId));
    }

    /** @return TestResponse<Response> */
    private function pratinjauPost(string $bukuId): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.post'])
            ->getJson(self::API.'penyusutan/posting/pratinjau?'.http_build_query($this->masukanPost($bukuId)));
    }

    /**
     * Baris jurnal payload sebagai [kode akun, debit, kredit, [KODE:nilai dimensi]].
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    private function barisJurnal(array $payload): array
    {
        return array_values(array_map(static fn (array $baris): array => [
            (string) $baris['account']['code'],
            (string) $baris['debit'],
            (string) $baris['credit'],
            array_values(array_map(static fn (array $dimensi): string => $dimensi['code'].':'.$dimensi['value_code'], $baris['financial_dimensions'])),
        ], $payload['journal_lines']));
    }
}
