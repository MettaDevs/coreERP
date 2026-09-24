<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerbitkanJurnalPenerimaan;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Saldo awal aset lama saat cutover (feed posting finance, TODO 10.7): penerimaan ber-cara saldo awal
 * melahirkan aset dengan akumulasi per buku, penyusutannya berlanjut dari periode ke-(offset + 1), dan
 * `asset.opening_balance` terbit bertanggal cutover dengan nilai buku di akun penyeimbang (K-13, K-27,
 * K-28).
 */
class OpeningBalanceTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerbitkanJurnalPenerimaan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJurnalPenerimaan();
    }

    public function test_the_journal_is_dated_at_cutover_and_credits_the_net_book_value_to_the_offset(): void
    {
        [$group, $komersial, $fiskal] = $this->groupSaldoAwal('KENDARAAN', 'Kendaraan');
        $this->petakanSaldoAwal($group);

        $id = $this->drafSaldoAwal([
            // Buku fiskal menyusut lebih lambat, jadi akumulasinya sendiri (K-28).
            $this->barisSaldoAwal($group, 2, '120000000', '60000000', 24, [
                ['buku_id' => $fiskal, 'akumulasi_per_unit' => '30000000', 'periode_berjalan' => 24],
            ]),
        ]);
        $this->selesaikan($id)->assertOk()->assertJsonPath('data.status', 'selesai');

        $posting = $this->posting($id, 'AST-OPB-');
        $payload = $posting->payload;
        $kode = (string) DB::table('aset_tr_penerimaan_aset')->where('id', $id)->value('kode');
        $this->assertSame('pending', $posting->status);
        $this->assertSame('asset.opening_balance', $payload['posting_type']);
        // Jurnalnya bertanggal cutover; tanggal perolehan asli menjadi tanggal dokumen (K-27).
        $this->assertSame('2026-01-01', $payload['posting_date']);
        $this->assertSame('2022-01-15', $payload['document_date']);
        $this->assertNull($payload['vendor']);
        $this->assertSame('Saldo awal aset '.$kode, $payload['source_document']['description']);
        $this->assertSame([
            ['1-2300', '240000000.00', '0.00', $kode.' · Kendaraan'],
            ['1-2390', '0.00', '120000000.00', 'Akumulasi penyusutan · '.$kode.' · Kendaraan'],
            ['3-9000', '0.00', '120000000.00', 'Saldo awal · '.$kode.' · Kendaraan'],
        ], array_map(static fn (array $baris): array => [$baris['account']['code'], $baris['debit'], $baris['credit'], $baris['description']], $payload['journal_lines']));
        $this->assertSame(['debit' => '240000000.00', 'credit' => '240000000.00'], $payload['totals']);
        $this->assertSame([['BUSINESS_UNIT', 'KLN-A']], array_map(
            static fn (array $dimensi): array => [$dimensi['code'], $dimensi['value_code']],
            $payload['journal_lines'][1]['financial_dimensions'],
        ));
        $aset = $payload['details']['assets'];
        $this->assertCount(2, $aset);
        $this->assertSame(['KOM-KENDARAAN', '120000000.00', '60000000.00', '60000000.00'], [$aset[0]['book'], $aset[0]['acquisition_value'], $aset[0]['accumulated_depreciation'], $aset[0]['net_book_value']]);

        // Buku aset lahir dengan akumulasinya masing-masing, dan penyusutannya mulai di cutover.
        $this->assertSame(['60000000.00', '60000000.00', 24, '60000000.00', '2026-01-01'], $this->bukuAset($id, $komersial));
        $this->assertSame(['30000000.00', '30000000.00', 24, '90000000.00', '2026-01-01'], $this->bukuAset($id, $fiskal));
        $this->lihat($id)->assertJsonPath('data.posting.status', 'pending')->assertJsonPath('data.cara_perolehan', 'saldo_awal')
            // Angka per buku dipulangkan sebagai daftar, bukan teks JSON: layar memetakannya baris demi baris.
            ->assertJsonPath('data.details.0.saldo_awal_buku', [['buku_id' => $fiskal, 'akumulasi_per_unit' => '30000000', 'periode_berjalan' => 24]]);
    }

    public function test_depreciation_after_cutover_continues_at_period_offset_plus_one(): void
    {
        [$group, $komersial, $fiskal] = $this->groupSaldoAwal('ALKES', 'Alat kesehatan', metode: 'straight_line_life_remaining');
        $id = $this->drafSaldoAwal([
            $this->barisSaldoAwal($group, 1, '48000000', '30000000', 24, [
                ['buku_id' => $fiskal, 'akumulasi_per_unit' => '12000000', 'periode_berjalan' => 24],
            ]),
        ]);
        $this->selesaikan($id)->assertOk();
        $bukuKomersial = $this->idBukuAset($id, $komersial);

        // Periode sebelum cutover sudah tercakup akumulasinya.
        $this->usulkan($bukuKomersial, '2025-12-01', '2025-12-31')->assertStatus(422);

        // Sisa umur 48 − 24 = 24 periode untuk nilai buku 18 juta: 750 ribu. Tanpa offset
        // hitungannya 18 juta / 48 = 375 ribu, dan tanpa akumulasi awal 48 juta / 24 = 2 juta.
        $januari = $this->usulkan($bukuKomersial, '2026-01-01', '2026-01-31')->assertCreated();
        $this->assertSame(750000.0, (float) $januari->json('data.amount'));
        $this->finalisasi((string) $januari->json('data.id'));
        // Akumulasi = saldo awal + periode final; nilai bukunya turun dari sana.
        $this->assertSame(['30750000.00', '30000000.00', 24, '17250000.00', '2026-01-01'], $this->bukuAset($id, $komersial));

        // Buku fiskal berlanjut dari angkanya sendiri: 36 juta / (96 − 24) = 500 ribu.
        $fiskalJanuari = $this->usulkan($this->idBukuAset($id, $fiskal), '2026-01-01', '2026-01-31')->assertCreated();
        $this->assertSame(500000.0, (float) $fiskalJanuari->json('data.amount'));

        // Tutup bulan massal memakai offset yang sama: 17,25 juta / (48 − 25) = 750 ribu. Tanpa
        // offset hitungannya 17,25 juta / 47.
        $februari = $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create'])
            ->postJson(self::API.'penyusutan/proposal-massal', ['period_starts_on' => '2026-02-01', 'period_ends_on' => '2026-02-28', 'buku_id' => $komersial])
            ->assertCreated();
        $this->assertSame([750000.0], array_map(static fn (array $periode): float => (float) $periode['amount'], $februari->json('data.periode')));
    }

    public function test_an_acquisition_date_after_cutover_is_refused_when_saved_and_when_completed(): void
    {
        [$group] = $this->groupSaldoAwal('KENDARAAN', 'Kendaraan');

        $this->kirimDraf($this->headerSaldoAwal(['tanggal' => '2026-02-01', 'tanggal_siap_pakai' => '2026-02-01']), [
            $this->barisSaldoAwal($group, 1, '10000000', '0', 0),
        ])->assertStatus(422)->assertJsonValidationErrors([
            'tanggal' => 'Tanggal perolehan saldo awal harus sama dengan atau sebelum cutover (01/01/2026). Aset yang diperoleh sesudah cutover dicatat sebagai pembelian atau hibah.',
        ]);

        // Cutover yang dimundurkan sesudah draf disimpan tetap menahan penyelesaiannya.
        $id = $this->drafSaldoAwal([$this->barisSaldoAwal($group, 1, '10000000', '0', 0)], ['tanggal' => '2025-12-01', 'tanggal_siap_pakai' => '2025-12-01']);
        $this->actingAs($this->owner)->putJson("/api/v1/organizations/{$this->le}/finance-posting", ['enabled' => true, 'cutover_date' => '2025-06-01'])->assertOk();
        $this->pratinjau($id)->assertOk()->assertJsonPath('data.blockers.0.field', 'tanggal');
        $this->selesaikan($id)->assertStatus(422)->assertJsonValidationErrors(['tanggal']);
        $this->assertSame(0, DB::table('aset_tr_aset')->where('penerimaan_aset_id', $id)->count());
    }

    public function test_a_legal_entity_without_cutover_cannot_complete_an_opening_balance(): void
    {
        [$group] = $this->groupSaldoAwal('KENDARAAN', 'Kendaraan');
        $lain = $this->organisasi(['classification' => 'legal_entity', 'name' => 'PT Metta Lain', 'company_code' => 'LAIN', 'country_code' => 'ID']);

        $id = $this->drafSaldoAwal([$this->barisSaldoAwal($group, 1, '10000000', '4000000', 10)], ['legal_entity_id' => $lain]);
        $pesan = 'Entitas legal ini belum punya tanggal cutover. Jurnal saldo awal bertanggal cutover, jadi atur tanggalnya dulu di Pengaturan › Organisasi › Posting ke Aplikasi Finance.';
        $this->pratinjau($id)->assertOk()
            ->assertJsonPath('data.blockers.0.message', $pesan)
            ->assertJsonPath('data.lines', []);
        $this->selesaikan($id)->assertStatus(422)->assertJsonValidationErrors(['tanggal' => $pesan]);
    }

    public function test_opening_balance_amounts_are_checked_per_book(): void
    {
        [$group, $komersial, $fiskal] = $this->groupSaldoAwal('KENDARAAN', 'Kendaraan', masaKomersial: 96, masaFiskal: 48);
        [$groupLain, , $fiskalLain] = $this->groupSaldoAwal('ALKES', 'Alat kesehatan');
        $salah = fn (array $baris, array $header = []): TestResponse => $this->kirimDraf($this->headerSaldoAwal($header), [$baris])->assertStatus(422);

        $salah([...$this->barisSaldoAwal($group, 1, '10000000', '9500000', 10), 'residu_per_unit' => 600000])
            ->assertJsonValidationErrors(['details.0.akumulasi_per_unit' => 'Akumulasi ditambah nilai residu tidak boleh melebihi nilai per unit.']);
        $salah($this->barisSaldoAwal($group, 1, '10000000', '100.125', 10))
            ->assertJsonValidationErrors(['details.0.akumulasi_per_unit' => 'Akumulasi paling banyak 2 angka di belakang koma.']);
        // Angka baris berlaku juga untuk buku fiskal yang tidak diisi tersendiri, yang umurnya lebih pendek.
        $salah($this->barisSaldoAwal($group, 1, '10000000', '5000000', 60))
            ->assertJsonValidationErrors(['details.0.periode_berjalan' => 'Periode berjalan melebihi masa manfaat buku FIS-KENDARAAN (48 periode). Isi angka buku itu tersendiri.']);
        $this->kirimDraf($this->headerSaldoAwal(), [$this->barisSaldoAwal($group, 1, '10000000', '5000000', 60, [
            ['buku_id' => $fiskal, 'akumulasi_per_unit' => '10000000', 'periode_berjalan' => 48],
        ])])->assertCreated();
        $salah($this->barisSaldoAwal($group, 1, '10000000', '5000000', 10, [['buku_id' => $komersial, 'akumulasi_per_unit' => '1', 'periode_berjalan' => 1]]))
            ->assertJsonValidationErrors(['details.0.saldo_awal_buku.0.buku_id' => 'Angka buku yang di-post ke finance diisi di baris itu sendiri, dan tiap buku lain cukup diisi sekali.']);
        $salah($this->barisSaldoAwal($group, 1, '10000000', '5000000', 10, [['buku_id' => $fiskalLain, 'akumulasi_per_unit' => '1', 'periode_berjalan' => 1]]))
            ->assertJsonValidationErrors(['details.0.saldo_awal_buku.0.buku_id' => 'Buku ini tidak ada di matriks group x buku group baris ini.']);
        $salah([...$this->barisSaldoAwal($groupLain, 1, '10000000', '0', 0), 'ppn_per_unit' => 1100000])
            ->assertJsonValidationErrors(['details.0.ppn_per_unit' => 'PPN tidak berlaku untuk saldo awal.']);
        $salah($this->barisSaldoAwal($groupLain, 1, '10000000', '0', 0), ['vendor_id' => $this->vendor])
            ->assertJsonValidationErrors(['vendor_id' => 'Saldo awal tidak punya vendor maupun faktur; kosongkan isian ini.']);
        // Dokumen pembelian tidak boleh membawa angka saldo awal.
        $this->kirimDraf([], [$this->barisSaldoAwal($groupLain, 1, '10000000', '1000000', 3)])->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.akumulasi_per_unit' => 'Akumulasi dan periode berjalan hanya diisi untuk saldo awal.']);
    }

    public function test_the_books_of_a_group_are_listed_with_the_posted_book_first(): void
    {
        [$group, $komersial, $fiskal] = $this->groupSaldoAwal('KENDARAAN', 'Kendaraan', masaKomersial: 60, masaFiskal: 96);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.read'])
            ->getJson(self::API.'penerimaan-aset/buku?group_aset_id='.$group)
            ->assertOk()
            ->assertExactJson(['data' => [
                ['buku_id' => $komersial, 'kode' => 'KOM-KENDARAAN', 'nama' => 'Komersial Kendaraan', 'posting_layer' => 'current', 'di_post' => true, 'masa_manfaat' => 60],
                ['buku_id' => $fiskal, 'kode' => 'FIS-KENDARAAN', 'nama' => 'Fiskal Kendaraan', 'posting_layer' => 'none', 'di_post' => false, 'masa_manfaat' => 96],
            ]]);
    }

    public function test_an_unmapped_opening_balance_is_held_and_released_once_mapped(): void
    {
        [$group] = $this->groupSaldoAwal('KENDARAAN', 'Kendaraan');
        $id = $this->drafSaldoAwal([$this->barisSaldoAwal($group, 1, '10000000', '4000000', 10)]);
        $this->selesaikan($id)->assertOk();

        $posting = $this->posting($id, 'AST-OPB-');
        $this->assertSame('held', $posting->status);
        $this->assertSame(
            ['Group KENDARAAN · harga perolehan belum dipetakan ke akun.', 'Group KENDARAAN · akumulasi penyusutan belum dipetakan ke akun.', 'Group KENDARAAN · penyeimbang saldo awal belum dipetakan ke akun.'],
            array_column($posting->hold_reasons, 'message'),
        );

        $this->petakanSaldoAwal($group);
        $this->actingAs($this->owner)->postJson('/api/v1/finance-postings/'.$posting->id.'/revalidate')->assertOk();
        $this->assertSame('pending', $posting->refresh()->status);
    }

    public function test_zero_accumulation_and_fully_depreciated_assets_skip_their_empty_lines(): void
    {
        [$baru] = $this->groupSaldoAwal('BARU', 'Aset baru');
        [$habis] = $this->groupSaldoAwal('HABIS', 'Aset habis');
        $this->petakanSaldoAwal($baru);
        $this->petakanSaldoAwal($habis);

        $id = $this->drafSaldoAwal([
            $this->barisSaldoAwal($baru, 1, '10000000', '0', 0, nama: 'Kursi baru'),
            $this->barisSaldoAwal($habis, 1, '8000000', '8000000', 48, nama: 'Meja lama'),
        ]);
        $this->selesaikan($id)->assertOk();

        $kode = (string) DB::table('aset_tr_penerimaan_aset')->where('id', $id)->value('kode');
        $this->assertSame([
            ['1-2300', '10000000.00', '0.00', $kode.' · Aset baru'],
            ['1-2300', '8000000.00', '0.00', $kode.' · Aset habis'],
            ['1-2390', '0.00', '8000000.00', 'Akumulasi penyusutan · '.$kode.' · Aset habis'],
            ['3-9000', '0.00', '10000000.00', 'Saldo awal · '.$kode.' · Aset baru'],
        ], array_map(static fn (array $baris): array => [$baris['account']['code'], $baris['debit'], $baris['credit'], $baris['description']], $this->posting($id, 'AST-OPB-')->payload['journal_lines']));
    }

    // ---- penyusun skenario -------------------------------------------------

    /**
     * Group dengan buku komersial yang di-post dan buku fiskal memorandum, keduanya menyusut dengan
     * metode `$metode`.
     *
     * @return array{0: string, 1: string, 2: string} group, buku komersial, buku fiskal
     */
    private function groupSaldoAwal(string $kode, string $nama, int $masaKomersial = 48, int $masaFiskal = 96, string $metode = 'straight_line'): array
    {
        $group = $this->groupTanpaBuku($kode, $nama);
        $komersial = $this->bukuBerprofil('KOM-'.$kode, 'Komersial '.$nama, 'current', $metode, $masaKomersial);
        $fiskal = $this->bukuBerprofil('FIS-'.$kode, 'Fiskal '.$nama, 'none', $metode, $masaFiskal);
        $this->sebagaiPengguna($this->tenantId, $this->izin('group-aset'))
            ->putJson(self::API.'group-aset/'.$group.'/buku-penyusutan', ['rows' => [
                ['buku_id' => $komersial, 'useful_life_periods' => $masaKomersial, 'convention' => 'full_month', 'depreciate' => true],
                ['buku_id' => $fiskal, 'useful_life_periods' => $masaFiskal, 'convention' => 'full_month', 'depreciate' => true],
            ]])->assertOk();

        return [$group, $komersial, $fiskal];
    }

    private function bukuBerprofil(string $kode, string $nama, string $postingLayer, string $metode, int $masa): string
    {
        $profil = $this->master('profil-penyusutan', [
            'nama' => 'Profil '.$nama, 'method' => $metode, 'frequency' => 'monthly', 'year_basis' => 'calendar',
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

    private function petakanSaldoAwal(string $group): void
    {
        $this->petakan($group, [
            'acquisition_account_id' => $this->akun['kendaraan'],
            'accumulated_depreciation_account_id' => $this->akun['akumulasi'],
            'opening_balance_offset_account_id' => $this->akun['penyeimbang'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function headerSaldoAwal(array $header = []): array
    {
        return ['cara_perolehan' => 'saldo_awal', 'vendor_id' => null, 'tanggal' => '2022-01-15', 'tanggal_siap_pakai' => '2022-01-15', ...$header];
    }

    /**
     * @param  list<array<string, mixed>>  $baris
     * @param  array<string, mixed>  $header
     */
    private function drafSaldoAwal(array $baris, array $header = []): string
    {
        return $this->draf($this->headerSaldoAwal($header), $baris);
    }

    /**
     * @param  list<array{buku_id: string, akumulasi_per_unit: string, periode_berjalan: int}>  $perBuku
     * @return array<string, mixed>
     */
    private function barisSaldoAwal(string $group, int $jumlah, string $nilai, string $akumulasi, int $periode, array $perBuku = [], string $nama = 'Ambulans'): array
    {
        return [...$this->baris($group, $jumlah, $nilai, 0, $nama), 'akumulasi_per_unit' => $akumulasi, 'periode_berjalan' => $periode, 'saldo_awal_buku' => $perBuku];
    }

    private function idBukuAset(string $receiptId, string $bukuId): string
    {
        return (string) DB::table('aset_tr_buku_aset as b')
            ->join('aset_tr_aset as a', 'a.id', '=', 'b.aset_id')
            ->where('a.penerimaan_aset_id', $receiptId)->where('b.buku_id', $bukuId)
            ->orderBy('a.kode')->value('b.id');
    }

    /** @return array{0: string, 1: string, 2: int, 3: string, 4: string} akumulasi, akumulasi awal, offset, nilai buku, mulai susut */
    private function bukuAset(string $receiptId, string $bukuId): array
    {
        $buku = DB::table('aset_tr_buku_aset')->where('id', $this->idBukuAset($receiptId, $bukuId))->first();

        return [
            (string) $buku->accumulated_depreciation,
            (string) $buku->opening_accumulated_depreciation,
            (int) $buku->elapsed_periods_offset,
            (string) $buku->net_book_value,
            substr((string) $buku->depreciation_start_on, 0, 10),
        ];
    }

    /** @return TestResponse<Response> */
    private function usulkan(string $bukuAsetId, string $mulai, string $akhir): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create'])
            ->postJson(self::API.'penyusutan/proposal', ['buku_aset_id' => $bukuAsetId, 'period_starts_on' => $mulai, 'period_ends_on' => $akhir]);
    }

    private function finalisasi(string $periodeId): void
    {
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.finalize'])
            ->postJson(self::API.'penyusutan/'.$periodeId.'/finalisasi')
            ->assertOk();
    }
}
