<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Foundation\FinancePosting\Models\FinancePosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerbitkanJurnalPenerimaan;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenyiapkanNilaiBukuAset;
use Tests\Concerns\CocokDenganKontrak;
use Tests\TestCase;

/**
 * Penjualan dan pemusnahan aset sebagai draf lalu diposting, dan jurnal pelepasannya `asset.disposal_sale`
 * / `asset.disposal_scrap`: saldo buku yang di-post ke finance dikeluarkan, hasil penjualan dicatat, dan
 * selisihnya menjadi laba atau rugi pelepasan — di transaksi yang sama dengan pelepasan asetnya, sesudah
 * penyusutan sampai tanggal pelepasan beres.
 */
class DisposalPostingTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, CocokDenganKontrak, MenerbitkanJurnalPenerimaan, MenyiapkanNilaiBukuAset, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanNilaiBukuAset();
    }

    public function test_a_draft_does_not_dispose_and_posting_removes_cost_and_depreciation_and_books_the_gain(): void
    {
        [$group, $book] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);
        $this->susutkan('2026-10-01', '2026-10-31');
        $this->hentikan($aset);

        $draf = $this->drafPelepasan('penjualan-aset', $aset, '2026-10-31', 50000000, 'Lelang');

        // Draf belum melepas apa pun dan belum menjurnal apa pun.
        $this->assertSame('decommissioned', DB::table('aset_tr_aset')->where('id', $aset)->value('lifecycle_state'));
        $this->assertSame(['active'], DB::table('aset_tr_buku_aset')->where('aset_id', $aset)->distinct()->pluck('status')->all());
        $this->assertSame(0, FinancePosting::query()->where('posting_id', 'AST-DSP-'.$aset)->count());

        $jawab = $this->postingPelepasan('penjualan-aset', $draf)->assertOk();

        $postingId = 'AST-DSP-'.$aset;
        $this->assertSame('posted', $jawab->json('data.status'));
        $this->assertSame(['posting_id' => $postingId, 'status' => 'pending'], $jawab->json('data.posting'));
        $payload = $this->payloadPosting($postingId);
        $this->assertCocokSkema($payload, 'FinancePosting');
        $this->assertSame('asset.disposal_sale', $payload['posting_type']);
        $this->assertSame(['2026-10-31', '2026-10-31'], [$payload['posting_date'], $payload['document_date']]);
        $this->assertSame('Penjualan aset '.$this->kode($aset).': Lelang', $payload['source_document']['description']);
        $this->assertSame($jawab->json('data.kode'), $payload['source_document']['number']);
        // Nilai buku 47 juta (48 juta dikurangi penyusutan Oktober 1 juta), dijual 50 juta: laba 3 juta.
        $this->assertSame([
            ['1-2390', '1000000.00', '0.00', ['BUSINESS_UNIT:KLN-A']],
            ['1-2300', '0.00', '48000000.00', ['BUSINESS_UNIT:KLN-A']],
            ['1-1300', '50000000.00', '0.00', ['BUSINESS_UNIT:KLN-A']],
            ['7-1100', '0.00', '3000000.00', ['BUSINESS_UNIT:KLN-A', 'DEPARTMENT:POLI-UMUM']],
        ], $this->jurnal($payload));
        $this->assertSame(['debit' => '51000000.00', 'credit' => '51000000.00'], $payload['totals']);
        $this->assertSame([$this->bookCode($book), '47000000.00', '3000000.00'], [
            $payload['details']['assets'][0]['book'],
            $payload['details']['assets'][0]['net_book_value'],
            $payload['details']['assets'][0]['gain_loss'],
        ]);

        // Aset dilepas dan kedua bukunya ditutup per tanggal dokumen; buku fiskal tidak menjurnal apa pun.
        $this->assertSame('disposed', DB::table('aset_tr_aset')->where('id', $aset)->value('lifecycle_state'));
        $this->assertSame(['closed'], DB::table('aset_tr_buku_aset')->where('aset_id', $aset)->distinct()->pluck('status')->all());
        $this->assertSame(1, FinancePosting::query()->where('posting_type', 'like', 'asset.disposal%')->count());

        // Dokumen yang sudah diposting terkunci.
        $this->postingPelepasan('penjualan-aset', $draf)->assertUnprocessable();
    }

    public function test_scrapping_books_the_book_value_as_a_loss_and_refuses_proceeds(): void
    {
        [$group] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);
        $this->susutkan('2026-10-01', '2026-10-31');
        $this->hentikan($aset);

        $this->kirimDrafPelepasan('pemusnahan-aset', $aset, '2026-11-05', 1000000)->assertUnprocessable()->assertJsonValidationErrors('nilai');

        $this->lepas('pemusnahan-aset', $aset, '2026-11-05')->assertOk();

        $payload = $this->payloadPosting('AST-DSP-'.$aset);
        $this->assertSame('asset.disposal_scrap', $payload['posting_type']);
        $this->assertSame([
            ['1-2390', '1000000.00', '0.00', ['BUSINESS_UNIT:KLN-A']],
            ['1-2300', '0.00', '48000000.00', ['BUSINESS_UNIT:KLN-A']],
            ['8-1100', '47000000.00', '0.00', ['BUSINESS_UNIT:KLN-A', 'DEPARTMENT:POLI-UMUM']],
        ], $this->jurnal($payload));
    }

    public function test_a_sale_below_book_value_books_a_loss(): void
    {
        [$group] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);
        $this->hentikan($aset);

        $this->lepas('penjualan-aset', $aset, '2026-09-30', 40000000)->assertOk();

        // Belum ada penyusutan: tidak ada baris akumulasi, dan rugi 8 juta.
        $this->assertSame([
            ['1-2300', '0.00', '48000000.00', ['BUSINESS_UNIT:KLN-A']],
            ['1-1300', '40000000.00', '0.00', ['BUSINESS_UNIT:KLN-A']],
            ['8-1100', '8000000.00', '0.00', ['BUSINESS_UNIT:KLN-A', 'DEPARTMENT:POLI-UMUM']],
        ], $this->jurnal($this->payloadPosting('AST-DSP-'.$aset)));
    }

    public function test_depreciation_up_to_the_disposal_date_must_be_settled_before_posting(): void
    {
        [$group] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);
        $this->hentikan($aset);
        $draf = $this->drafPelepasan('penjualan-aset', $aset, '2026-10-15', 50000000);

        // Usulan yang belum difinalkan menahan posting.
        $this->usulkanPeriode('2026-10-01', '2026-10-31');
        $this->postingPelepasan('penjualan-aset', $draf)
            ->assertUnprocessable()->assertJsonValidationErrors(['tanggal' => 'belum difinalkan']);

        // Penyusutan final yang berakhir sesudah tanggal pelepasan juga menahan.
        foreach (DB::table('aset_tr_penyusutan_aset')->where('status', 'proposed')->pluck('id') as $id) {
            $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.finalize'])->postJson(self::API.'penyusutan/'.$id.'/finalisasi')->assertOk();
        }
        $this->postingPelepasan('penjualan-aset', $draf)
            ->assertUnprocessable()->assertJsonValidationErrors(['tanggal' => '31/10/2026']);
        $this->assertSame(0, FinancePosting::query()->where('posting_id', 'AST-DSP-'.$aset)->count());
        $this->assertSame('decommissioned', DB::table('aset_tr_aset')->where('id', $aset)->value('lifecycle_state'));

        // Draf diubah ke tanggal akhir periode, lalu boleh diposting.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penjualan-aset.create'])
            ->patchJson(self::API.'penjualan-aset/'.$draf, ['tanggal' => '2026-10-31', 'version' => DB::table('aset_tr_dokumen_siklus_aset')->where('id', $draf)->value('version')])
            ->assertOk()->assertJsonPath('data.tanggal', '2026-10-31');
        $this->postingPelepasan('penjualan-aset', $draf)->assertOk();
    }

    public function test_an_unmapped_gain_holds_the_posting_but_the_asset_is_still_disposed(): void
    {
        [$group] = $this->groupLengkap('KENDARAAN', 'Kendaraan', ['disposal_gain_account_id']);
        $aset = $this->terimaSatu($group);
        $this->hentikan($aset);

        $this->lepas('penjualan-aset', $aset, '2026-09-30', 50000000)->assertOk()->assertJsonPath('data.posting.status', 'held');

        $posting = FinancePosting::query()->where('posting_id', 'AST-DSP-'.$aset)->firstOrFail();
        $this->assertSame('ACCOUNT_NOT_MAPPED', $posting->hold_reasons[0]['code']);
        $this->assertStringContainsString('laba pelepasan', $posting->hold_reasons[0]['message']);
        $this->assertSame('disposed', DB::table('aset_tr_aset')->where('id', $aset)->value('lifecycle_state'));
    }

    public function test_the_preview_shows_the_journal_posting_publishes_without_saving(): void
    {
        [$group] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);
        $this->hentikan($aset);
        $draf = $this->drafPelepasan('penjualan-aset', $aset, '2026-09-30', 50000000);

        $pratinjau = $this->sebagaiPengguna($this->tenantId, ['management-aset.penjualan-aset.post'])
            ->getJson(self::API.'penjualan-aset/'.$draf.'/pratinjau-posting')
            ->assertOk()->json('data');

        $this->assertSame([], $pratinjau['blockers']);
        $this->assertSame(['48000000.00', '48000000.00', '2000000.00'], [$pratinjau['amounts']['acquisition_value'], $pratinjau['amounts']['net_book_value'], $pratinjau['amounts']['gain_loss']]);
        $this->assertSame(['AST-DSP-'.$aset, 'pending', '50000000.00'], [$pratinjau['posting']['posting_id'], $pratinjau['posting']['status'], $pratinjau['posting']['total']]);
        $this->assertSame(['1-2300', '1-1300', '7-1100'], array_column($pratinjau['posting']['lines'], 'account_code'));
        $this->assertSame(0, FinancePosting::query()->where('posting_id', 'AST-DSP-'.$aset)->count());
        $this->assertSame('decommissioned', DB::table('aset_tr_aset')->where('id', $aset)->value('lifecycle_state'));

        // Pratinjau yang sama dengan jurnal yang terbit.
        $this->postingPelepasan('penjualan-aset', $draf)->assertOk();
        $this->assertSame($pratinjau['posting']['lines'][2]['credit'], $this->payloadPosting('AST-DSP-'.$aset)['journal_lines'][2]['credit']);

        // Butuh hak membuat atau memposting, dan tenant lain tidak melihat dokumennya.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penjualan-aset.read'])
            ->getJson(self::API.'penjualan-aset/'.$draf.'/pratinjau-posting')->assertForbidden();
        $this->sebagaiPengguna((string) Str::ulid(), ['management-aset.penjualan-aset.post'])
            ->getJson(self::API.'penjualan-aset/'.$draf.'/pratinjau-posting')->assertNotFound();
    }

    public function test_posting_needs_its_own_permission_and_a_second_draft_for_a_disposed_asset_is_refused(): void
    {
        [$group] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);
        $this->hentikan($aset);
        $pertama = $this->drafPelepasan('penjualan-aset', $aset, '2026-09-30', 50000000);
        $kedua = $this->drafPelepasan('pemusnahan-aset', $aset, '2026-09-30');

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penjualan-aset.create'])
            ->postJson(self::API.'penjualan-aset/'.$pertama.'/posting', ['version' => 1])->assertForbidden();

        $this->postingPelepasan('penjualan-aset', $pertama)->assertOk();
        $this->postingPelepasan('pemusnahan-aset', $kedua)
            ->assertUnprocessable()->assertJsonValidationErrors(['aset_id' => 'sudah dilepas']);

        // Draf yang tidak jadi dipakai dibatalkan, tidak dihapus.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.pemusnahan-aset.create'])
            ->postJson(self::API.'pemusnahan-aset/'.$kedua.'/batal', ['version' => DB::table('aset_tr_dokumen_siklus_aset')->where('id', $kedua)->value('version')])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(1, FinancePosting::query()->where('posting_id', 'AST-DSP-'.$aset)->count());
    }

    public function test_a_group_without_a_posted_book_disposes_without_a_journal(): void
    {
        $group = $this->group('INVENTARIS', 'Inventaris', 'none');
        $aset = $this->asetTanpaJurnal($group);
        $this->hentikan($aset);

        $this->lepas('pemusnahan-aset', $aset, '2026-09-30')->assertOk()->assertJsonPath('data.posting', null);

        $this->assertSame(0, FinancePosting::query()->where('posting_id', 'AST-DSP-'.$aset)->count());
        $this->assertSame('disposed', DB::table('aset_tr_aset')->where('id', $aset)->value('lifecycle_state'));
    }

    /** Aset dari group tanpa buku yang di-post: penerimaannya tidak dapat diselesaikan, jadi asetnya disisipkan. */
    private function asetTanpaJurnal(string $group): string
    {
        $aset = (string) Str::ulid();
        DB::table('aset_tr_aset')->insert([
            'id' => $aset, 'tenant_id' => $this->tenantId, 'creation_key' => 'aset-'.$aset, 'kode' => 'INV-'.Str::random(5),
            'nama' => 'Meja', 'legal_entity_id' => $this->le, 'responsible_org_unit_id' => $this->poli, 'group_aset_id' => $group,
            'jenis_aset_id' => $this->jenis(), 'acquired_on' => '2026-01-01', 'acquisition_value' => 1000000, 'currency_code' => 'IDR',
            'lifecycle_state' => 'received', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $aset;
    }

    private function kode(string $aset): string
    {
        return (string) DB::table('aset_tr_aset')->where('id', $aset)->value('kode');
    }
}
