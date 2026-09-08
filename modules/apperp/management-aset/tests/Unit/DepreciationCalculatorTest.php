<?php

namespace Tests\Unit;

use Modules\Apperp\ManagementAset\Services\DepreciationCalculator;
use PHPUnit\Framework\TestCase;

class DepreciationCalculatorTest extends TestCase
{
    private DepreciationCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new DepreciationCalculator;
    }

    /** @param array<string, mixed> $overrides */
    private function book(array $overrides = []): object
    {
        return (object) [
            'method' => 'straight_line',
            'frequency' => 'monthly',
            'useful_life_periods' => 12,
            'rate_percent' => null,
            'manual_schedule' => null,
            'acquisition_value' => 1200,
            'residual_value' => 0,
            'net_book_value' => 1200,
            'depreciate' => true,
            'alternative_profile_id' => null,
            ...$overrides,
        ];
    }

    public function test_garis_lurus_berhenti_setelah_masa_manfaat_habis(): void
    {
        // Cacat lama: jumlah tetap terus diusulkan selamanya, melewati masa manfaat.
        $this->assertSame(100.0, $this->calculator->amount($this->book(), 0));
        $this->assertSame(100.0, $this->calculator->amount($this->book(['net_book_value' => 100]), 11));
        $this->assertSame(0.0, $this->calculator->amount($this->book(['net_book_value' => 0]), 12));
    }

    public function test_garis_lurus_tidak_menembus_nilai_residu(): void
    {
        // Cacat lama: nilai sisa hanya dipakai saldo menurun, sehingga garis lurus dapat
        // membawa nilai buku menembus residu bahkan sampai negatif.
        $book = $this->book(['acquisition_value' => 1200, 'residual_value' => 200, 'net_book_value' => 250]);

        // Sisa yang boleh disusutkan tinggal 50, meski jatah per periode 83,33.
        $this->assertSame(50.0, $this->calculator->amount($book, 5));
        $this->assertSame(0.0, $this->calculator->amount($this->book(['residual_value' => 200, 'net_book_value' => 200]), 5));
    }

    public function test_saldo_menurun_memakai_dasar_setelah_dikurangi_residu(): void
    {
        $book = $this->book([
            'method' => 'reducing_balance', 'rate_percent' => 24, 'frequency' => 'monthly',
            'net_book_value' => 1200, 'residual_value' => 200,
        ]);

        // (1200 - 200) * 24% / 12 = 20
        $this->assertSame(20.0, $this->calculator->amount($book, 0));
    }

    public function test_buku_yang_ditandai_tidak_disusutkan_menghasilkan_nol(): void
    {
        $this->assertSame(0.0, $this->calculator->amount($this->book(['depreciate' => false]), 0));
    }

    public function test_garis_lurus_sisa_umur_membagi_rata_sisa_periode(): void
    {
        $book = $this->book(['method' => 'straight_line_life_remaining', 'net_book_value' => 300, 'useful_life_periods' => 12]);

        // Sisa 300 dibagi sisa 3 periode.
        $this->assertSame(100.0, $this->calculator->amount($book, 9));
    }

    public function test_round_off_menurunkan_periode_tengah_dan_periode_terakhir_mengambil_sisa(): void
    {
        $book = $this->book([
            'acquisition_value' => 1000,
            'net_book_value' => 1000,
            'useful_life_periods' => 3,
            'round_off_depreciation' => 100,
        ]);

        $this->assertSame(300.0, $this->calculator->amount($book, 0));
        $this->assertSame(300.0, $this->calculator->amount($book, 1));
        // Pembulatan tidak boleh meninggalkan saldo akhir 100 atau 0,01.
        $final = $this->book([
            'acquisition_value' => 1000,
            'net_book_value' => 400,
            'useful_life_periods' => 3,
            'round_off_depreciation' => 100,
        ]);
        $this->assertSame(400.0, $this->calculator->amount($final, 2));
    }

    public function test_round_off_nol_mempertahankan_nilai_asli(): void
    {
        $book = $this->book([
            'acquisition_value' => 1000,
            'net_book_value' => 1000,
            'useful_life_periods' => 3,
            'round_off_depreciation' => 0,
        ]);

        $this->assertSame(333.33, $this->calculator->amount($book, 0));
    }

    public function test_frekuensi_menentukan_pembagi_saldo_menurun(): void
    {
        $yearly = $this->book(['method' => 'reducing_balance', 'rate_percent' => 24, 'frequency' => 'yearly']);
        $quarterly = $this->book(['method' => 'reducing_balance', 'rate_percent' => 24, 'frequency' => 'quarterly']);

        $this->assertSame(288.0, $this->calculator->amount($yearly, 0));
        $this->assertSame(72.0, $this->calculator->amount($quarterly, 0));
    }

    public function test_pindah_ke_profil_alternatif_saat_saldo_menurun_kalah_dari_garis_lurus(): void
    {
        // Saldo menurun ganda atas masa manfaat 5 tahun: tarif 40% per tahun, 60 periode.
        $awal = $this->book([
            'method' => 'reducing_balance', 'rate_percent' => 40, 'frequency' => 'monthly',
            'net_book_value' => 1200, 'useful_life_periods' => 60, 'alternative_profile_id' => '01J',
        ]);

        // Awal masa manfaat saldo menurun masih lebih besar: 40 lawan 20.
        $this->assertFalse($this->calculator->shouldSwitch($awal, 0));

        // Menjelang akhir nilai buku sudah kecil dan sisa periode tinggal sedikit,
        // sehingga garis lurus sisa umur menang: 3,33 lawan 10.
        $akhir = $this->book([
            'method' => 'reducing_balance', 'rate_percent' => 40, 'frequency' => 'monthly',
            'net_book_value' => 100, 'useful_life_periods' => 60, 'alternative_profile_id' => '01J',
        ]);
        $this->assertTrue($this->calculator->shouldSwitch($akhir, 50));
    }

    public function test_tidak_pindah_profil_bila_alternatif_tidak_disetel(): void
    {
        $book = $this->book(['method' => 'reducing_balance', 'rate_percent' => 24, 'alternative_profile_id' => null]);

        $this->assertFalse($this->calculator->shouldSwitch($book, 10));
    }

    public function test_konvensi_menentukan_tanggal_mulai_penyusutan(): void
    {
        // Semuanya dihitung dari tanggal aset mulai digunakan, bukan tanggal perolehan.
        $placed = '2026-03-20';

        $this->assertSame('2026-03-20', $this->calculator->startDate($placed, 'none')->toDateString());
        $this->assertSame('2026-03-01', $this->calculator->startDate($placed, 'full_month')->toDateString());
        // Tanggal 20 jatuh di paruh kedua bulan, jadi mundur ke bulan berikutnya.
        $this->assertSame('2026-04-01', $this->calculator->startDate($placed, 'mid_month_1st')->toDateString());
        $this->assertSame('2026-03-15', $this->calculator->startDate($placed, 'mid_month_15th')->toDateString());
        // Tanggal 10 masih paruh pertama, tetap di bulan yang sama.
        $this->assertSame('2026-03-01', $this->calculator->startDate('2026-03-10', 'mid_month_1st')->toDateString());
    }

    public function test_konvensi_setengah_tahun_memakai_batas_tahun_kalender_bila_tanpa_kalender_fiskal(): void
    {
        $this->assertSame('2026-07-02', $this->calculator->startDate('2026-03-20', 'half_year')->toDateString());
        $this->assertSame('2026-01-01', $this->calculator->startDate('2026-03-20', 'half_year_start_of_year')->toDateString());
        // Paruh kedua tahun: penyusutan baru dimulai tahun berikutnya.
        $this->assertSame('2027-01-01', $this->calculator->startDate('2026-10-05', 'half_year_next_year')->toDateString());
    }

    public function test_konvensi_setengah_tahun_mengikuti_kalender_fiskal_saat_tersedia(): void
    {
        // Tahun buku Juli-Juni, bukan tahun kalender. Inilah sebabnya kalender fiskal
        // dibaca dari Core dan tidak boleh ditebak dari bulan Januari.
        $fiscalYear = ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'];

        $this->assertSame(
            '2026-07-01',
            $this->calculator->startDate('2026-08-15', 'half_year_start_of_year', $fiscalYear)->toDateString(),
        );
        $this->assertSame(
            '2027-07-01',
            $this->calculator->startDate('2027-03-15', 'half_year_next_year', $fiscalYear)->toDateString(),
        );
    }
}
