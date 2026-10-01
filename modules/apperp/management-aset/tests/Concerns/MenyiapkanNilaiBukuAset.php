<?php

namespace Modules\Apperp\ManagementAset\Tests\Concerns;

use App\Foundation\FinancePosting\Models\FinancePosting;
use App\Foundation\FinancePosting\Models\FinanceReferenceAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panggung jurnal yang mengubah nilai buku aset — pelepasan dan penyesuaian nilai — di atas panggung jurnal
 * penerimaan: akun pelepasan dan penyesuaian nilai di daftar akun, group yang menyusut di buku komersial
 * (lapisan current) dan buku fiskal (lapisan none), dan aset yang diterima lalu disusutkan.
 *
 * Kelas pemakainya wajib memakai `BerinteraksiDenganKonteksCore`, `MenerbitkanJurnalPenerimaan`, dan
 * `RefreshDatabase`, lalu memanggil `siapkanNilaiBukuAset()` dari `setUp()`.
 */
trait MenyiapkanNilaiBukuAset
{
    protected function siapkanNilaiBukuAset(): void
    {
        $this->siapkanJurnalPenerimaan();
        foreach ([
            'beban' => ['6510', '6-5100', 'Beban Penyusutan Kendaraan', FinanceReferenceAccount::PROFIT_LOSS],
            'akumulasi_turun' => ['1458', '1-2395', 'Akumulasi Penurunan Nilai - Kendaraan', 'balance_sheet'],
            'beban_turun' => ['6520', '6-5200', 'Rugi Penurunan Nilai Aset', FinanceReferenceAccount::PROFIT_LOSS],
            'naik' => ['1453', '1-2310', 'Revaluasi Aset Tetap - Kendaraan', 'balance_sheet'],
            'surplus' => ['3410', '3-4100', 'Surplus Revaluasi Aset Tetap', 'balance_sheet'],
            'hasil_jual' => ['1130', '1-1300', 'Piutang Penjualan Aset', 'balance_sheet'],
            'laba' => ['7110', '7-1100', 'Laba Pelepasan Aset', FinanceReferenceAccount::PROFIT_LOSS],
            'rugi' => ['8110', '8-1100', 'Rugi Pelepasan Aset', FinanceReferenceAccount::PROFIT_LOSS],
        ] as $kunci => [$eksternal, $kode, $nama, $jenis]) {
            $this->akun[$kunci] = FinanceReferenceAccount::query()->create([
                'tenant_id' => $this->tenantId, 'legal_entity_id' => null, 'external_id' => $eksternal,
                'code' => $kode, 'name' => $nama, 'type' => $jenis, 'active' => true,
            ])->id;
        }
    }

    /**
     * Group yang menyusut 48 bulan, garis lurus bulanan, di buku komersial (current) dan fiskal (none), dan
     * dipetakan ke seluruh akun yang dipakai jurnal perolehan, penyusutan, penyesuaian nilai, dan pelepasan.
     *
     * @param  list<string>  $tanpa  Kolom akun yang sengaja dibiarkan kosong.
     * @return array{0: string, 1: string, 2: string} group, buku komersial, buku fiskal
     */
    private function groupLengkap(string $kode, string $nama, array $tanpa = []): array
    {
        $group = $this->groupTanpaBuku($kode, $nama);
        $komersial = $this->bukuBerprofilUji('KOM-'.$kode, 'Komersial '.$nama, 'current');
        $fiskal = $this->bukuBerprofilUji('FIS-'.$kode, 'Fiskal '.$nama, 'none');
        $this->sebagaiPengguna($this->tenantId, $this->izinMaster('group-aset'))
            ->putJson(self::API.'group-aset/'.$group.'/buku-penyusutan', ['version' => DB::table('aset_m_group_aset')->where('id', $group)->value('version'), 'rows' => [
                ['buku_id' => $komersial, 'useful_life_periods' => 48, 'convention' => 'full_month', 'depreciate' => true],
                ['buku_id' => $fiskal, 'useful_life_periods' => 48, 'convention' => 'full_month', 'depreciate' => true],
            ]])->assertOk();
        $this->petakan($group, array_diff_key([
            'acquisition_account_id' => $this->akun['kendaraan'],
            'accumulated_depreciation_account_id' => $this->akun['akumulasi'],
            'depreciation_expense_account_id' => $this->akun['beban'],
            'payable_account_id' => $this->akun['hutang'],
            'write_down_account_id' => $this->akun['akumulasi_turun'],
            'write_down_expense_account_id' => $this->akun['beban_turun'],
            'appreciation_account_id' => $this->akun['naik'],
            'appreciation_offset_account_id' => $this->akun['surplus'],
            'disposal_proceeds_account_id' => $this->akun['hasil_jual'],
            'disposal_gain_account_id' => $this->akun['laba'],
            'disposal_loss_account_id' => $this->akun['rugi'],
        ], array_flip($tanpa)));

        return [$group, $komersial, $fiskal];
    }

    private function bukuBerprofilUji(string $kode, string $nama, string $postingLayer): string
    {
        $profil = $this->masterUji('profil-penyusutan', [
            'nama' => 'Profil '.$nama, 'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar',
            'useful_life_periods' => 48, 'convention' => 'full_month',
        ]);

        return $this->masterUji('buku-penyusutan', ['kode' => $kode, 'nama' => $nama, 'posting_layer' => $postingLayer, 'depreciation_profile_id' => $profil]);
    }

    /** @param  array<string, mixed>  $payload */
    private function masterUji(string $resource, array $payload): string
    {
        return (string) $this->sebagaiPengguna($this->tenantId, $this->izinMaster($resource))
            ->withHeader('Idempotency-Key', $resource.'-'.Str::ulid())
            ->postJson(self::API.$resource, $payload)
            ->assertCreated()->json('data.id');
    }

    /** @return list<string> */
    private function izinMaster(string $resource): array
    {
        return array_map(static fn (string $aksi): string => 'management-aset.'.$resource.'.'.$aksi, ['read', 'create', 'update', 'archive']);
    }

    /** Menerima satu aset bernilai `$nilai` yang dipakai Poli Umum; id asetnya. */
    private function terimaSatu(string $group, int|string $nilai = 48000000): string
    {
        $penerimaan = $this->draf([], [$this->baris($group, 1, $nilai)]);
        $this->selesaikan($penerimaan)->assertOk();

        return (string) DB::table('aset_tr_aset')->where('penerimaan_aset_id', $penerimaan)->value('id');
    }

    /** Usulan penyusutan satu periode untuk seluruh buku aktif. */
    private function usulkanPeriode(string $mulai, string $akhir): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create'])
            ->postJson(self::API.'penyusutan/proposal-massal', ['period_starts_on' => $mulai, 'period_ends_on' => $akhir])
            ->assertCreated();
    }

    /** Usulan satu periode untuk seluruh buku aktif, lalu seluruhnya difinalkan. */
    private function susutkan(string $mulai, string $akhir): void
    {
        $this->usulkanPeriode($mulai, $akhir);
        foreach (DB::table('aset_tr_penyusutan_aset')->where('status', 'proposed')->pluck('id') as $id) {
            $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.finalize'])
                ->postJson(self::API.'penyusutan/'.$id.'/finalisasi')->assertOk();
        }
    }

    /** Melewati workflow Core: pelepasan mensyaratkan aset sudah terdekomisioning. */
    private function hentikan(string $aset): void
    {
        DB::table('aset_tr_aset')->where('id', $aset)->update(['lifecycle_state' => 'decommissioned']);
    }

    /**
     * Draf penjualan atau pemusnahan lalu langsung diposting; jawabannya jawaban posting.
     *
     * @return TestResponse<Response>
     */
    private function lepas(string $jenis, string $aset, string $tanggal, int|string|null $nilai = null, ?string $keterangan = null): TestResponse
    {
        return $this->postingPelepasan($jenis, $this->drafPelepasan($jenis, $aset, $tanggal, $nilai, $keterangan));
    }

    private function drafPelepasan(string $jenis, string $aset, string $tanggal, int|string|null $nilai = null, ?string $keterangan = null): string
    {
        return (string) $this->kirimDrafPelepasan($jenis, $aset, $tanggal, $nilai, $keterangan)->assertCreated()->json('data.id');
    }

    /** @return TestResponse<Response> */
    private function kirimDrafPelepasan(string $jenis, string $aset, string $tanggal, int|string|null $nilai = null, ?string $keterangan = null): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.'.$jenis.'.create'])
            ->withHeader('Idempotency-Key', $jenis.'-'.Str::ulid())
            ->postJson(self::API.$jenis, array_filter([
                'legal_entity_id' => $this->le,
                'responsible_org_unit_id' => $this->poli,
                'aset_id' => $aset,
                'tanggal' => $tanggal,
                'nilai' => $nilai,
                'keterangan' => $keterangan,
            ], static fn ($nilai): bool => $nilai !== null));
    }

    /** @return TestResponse<Response> */
    private function postingPelepasan(string $jenis, string $dokumen): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.'.$jenis.'.post'])
            ->postJson(self::API.$jenis.'/'.$dokumen.'/posting', ['version' => DB::table('aset_tr_dokumen_siklus_aset')->where('id', $dokumen)->value('version')]);
    }

    /** @return array<string, mixed> */
    private function payloadPosting(string $postingId): array
    {
        return FinancePosting::query()->where('posting_id', $postingId)->firstOrFail()->payload;
    }

    /** @return array<string, string> */
    private function buku(string $aset, string $bukuId): array
    {
        return array_map('strval', (array) DB::table('aset_tr_buku_aset')->where(['aset_id' => $aset, 'buku_id' => $bukuId])
            ->first(['acquisition_value', 'accumulated_depreciation', 'write_down_amount', 'appreciation_amount', 'net_book_value', 'status']));
    }

    /**
     * Baris jurnal payload sebagai [kode akun, debit, kredit, [KODE:nilai dimensi]].
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    private function jurnal(array $payload): array
    {
        return array_values(array_map(static fn (array $baris): array => [
            (string) $baris['account']['code'],
            (string) $baris['debit'],
            (string) $baris['credit'],
            array_values(array_map(static fn (array $dimensi): string => $dimensi['code'].':'.$dimensi['value_code'], $baris['financial_dimensions'])),
        ], $payload['journal_lines']));
    }
}
