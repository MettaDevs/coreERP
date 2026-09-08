<?php

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Support\Carbon;

/**
 * Perhitungan penyusutan satu periode untuk satu buku aset.
 *
 * Dipisahkan dari controller supaya dapat diuji sebagai unit: aturannya banyak dan
 * kesalahan di sini tidak terlihat sampai laporan keuangan salah.
 */
final class DepreciationCalculator
{
    /**
     * Tanggal aset benar-benar mulai disusutkan.
     *
     * Dihitung dari tanggal aset mulai digunakan, bukan tanggal perolehan, mengikuti
     * Dynamics 365 F&O. Konvensi menentukan pergeserannya.
     *
     * @param  array{starts_on: string, ends_on: string}|null  $fiscalYear
     *                                                                      Tahun buku yang memuat tanggal tersebut, bila profil memakai dasar tahun fiskal.
     *                                                                      Untuk dasar tahun kalender, cukup `null`.
     */
    public function startDate(string $placedInService, ?string $convention, ?array $fiscalYear = null): Carbon
    {
        $date = Carbon::parse($placedInService)->startOfDay();

        return match ($convention) {
            'full_month' => $date->copy()->startOfMonth(),
            'mid_month_1st' => $date->day <= 15
                ? $date->copy()->startOfMonth()
                : $date->copy()->addMonthNoOverflow()->startOfMonth(),
            'mid_month_15th' => $date->copy()->startOfMonth()->addDays(14),
            'mid_quarter' => $this->midQuarter($date),
            'half_year', 'half_year_start_of_year', 'half_year_next_year' => $this->halfYear($date, $convention, $fiscalYear),
            // `none` dan konvensi yang tidak dikenal memakai tanggal apa adanya.
            default => $date,
        };
    }

    /**
     * Nilai penyusutan satu periode.
     *
     * @param  object  $book  Baris buku aset beserta atribut profil yang sudah di-join.
     * @param  int  $elapsedPeriods  Jumlah periode yang sudah disusutkan sebelumnya.
     */
    public function amount(object $book, int $elapsedPeriods, ?float $consumption = null): float
    {
        $residual = (float) ($book->residual_value ?? 0);
        $acquisition = (float) $book->acquisition_value;
        $netBookValue = (float) $book->net_book_value;
        // Sisa yang masih boleh disusutkan. Inilah pagar yang menahan nilai buku
        // menembus nilai residu, dan pada garis lurus sebelumnya tidak pernah dipasang.
        $remaining = round(max(0.0, $netBookValue - $residual), 2);
        if ($remaining <= 0.0 || ! ($book->depreciate ?? true)) {
            return 0.0;
        }

        $usefulLife = max(1, (int) ($book->useful_life_periods ?: 1));
        // Garis lurus berhenti setelah masa manfaat habis; sebelumnya ia terus
        // mengusulkan jumlah yang sama selamanya.
        if (in_array($book->method, ['straight_line', 'straight_line_life_remaining'], true) && $elapsedPeriods >= $usefulLife) {
            return 0.0;
        }

        // Periode terakhir masa manfaat mengambil seluruh sisa. Tanpa ini pembulatan tiap
        // periode menumpuk: (1200-200)/12 dibulatkan 83,33 lalu dikali 12 hanya 999,96,
        // sehingga nilai buku berhenti di 200,04 dan sisa empat sen itu tidak pernah
        // tersusutkan sampai kapan pun.
        if (in_array($book->method, ['straight_line', 'straight_line_life_remaining'], true)
            && $elapsedPeriods === $usefulLife - 1) {
            return $remaining;
        }

        $amount = match ($book->method) {
            'straight_line' => ($acquisition - $residual) / $usefulLife,
            // Garis lurus sisa umur: sisa nilai dibagi sisa periode, sehingga pembulatan
            // periode-periode awal tidak menyisakan ekor di akhir masa manfaat.
            'straight_line_life_remaining' => $remaining / max(1, $usefulLife - $elapsedPeriods),
            'reducing_balance' => $remaining * ((float) $book->rate_percent / 100) / $this->periodsPerYear($book->frequency),
            'consumption' => (float) ($consumption ?? 0),
            'manual' => $this->manualAmount($book->manual_schedule ?? null, $elapsedPeriods),
            default => 0.0,
        };

        // F&O memakai nilai round-off sebagai kelipatan nominal minimum. Periode
        // terakhir dikecualikan supaya nilai buku tetap mendarat tepat di residu.
        $roundedAmount = $this->roundOff($amount, $book->round_off_depreciation ?? null);

        return round(min($roundedAmount, $remaining), 2);
    }

    /**
     * Apakah profil alternatif sudah harus menggantikan profil utama.
     *
     * Pola "reducing balance switch to straight line life remaining": begitu saldo
     * menurun menghasilkan angka lebih kecil daripada garis lurus sisa umur, penyusutan
     * berpindah ke yang alternatif agar aset tetap habis pada akhir masa manfaat.
     */
    public function shouldSwitch(object $book, int $elapsedPeriods): bool
    {
        if (($book->method ?? null) !== 'reducing_balance' || empty($book->alternative_profile_id)) {
            return false;
        }
        $usefulLife = max(1, (int) ($book->useful_life_periods ?: 1));
        if ($elapsedPeriods >= $usefulLife) {
            return false;
        }
        $remaining = max(0.0, (float) $book->net_book_value - (float) ($book->residual_value ?? 0));
        $reducing = $remaining * ((float) $book->rate_percent / 100) / $this->periodsPerYear($book->frequency);
        $straightLine = $remaining / max(1, $usefulLife - $elapsedPeriods);

        return round($reducing, 2) < round($straightLine, 2);
    }

    public function periodsPerYear(?string $frequency): int
    {
        return match ($frequency) {
            'yearly' => 1,
            'half_yearly' => 2,
            'quarterly' => 4,
            default => 12,
        };
    }

    /** Kuartal penempatan menentukan porsi tahun pertama: 87,5% / 62,5% / 37,5% / 12,5%. */
    private function midQuarter(Carbon $date): Carbon
    {
        $quarterStart = $date->copy()->firstOfQuarter();

        return $quarterStart->addDays((int) round($quarterStart->daysInMonth * 3 / 2) - 1);
    }

    /** @param array{starts_on: string, ends_on: string}|null $fiscalYear */
    private function halfYear(Carbon $date, string $convention, ?array $fiscalYear): Carbon
    {
        // Tanpa kalender fiskal dari Core, batas tahun jatuh ke tahun kalender.
        $start = $fiscalYear ? Carbon::parse($fiscalYear['starts_on'])->startOfDay() : $date->copy()->startOfYear();
        $end = $fiscalYear ? Carbon::parse($fiscalYear['ends_on'])->startOfDay() : $date->copy()->endOfYear()->startOfDay();
        $midpoint = $start->copy()->addDays((int) floor($start->diffInDays($end) / 2));
        $firstHalf = $date->lessThanOrEqualTo($midpoint);

        return match ($convention) {
            'half_year_start_of_year' => $firstHalf ? $start : $midpoint,
            'half_year_next_year' => $firstHalf ? $start : $end->copy()->addDay(),
            default => $midpoint,
        };
    }

    private function manualAmount(mixed $schedule, int $index): float
    {
        $rows = is_string($schedule) ? json_decode($schedule, true) : $schedule;
        if (! is_array($rows) || ! isset($rows[$index]['amount'])) {
            abort(422, 'Jadwal manual belum memuat nilai untuk periode ini.');
        }

        return (float) $rows[$index]['amount'];
    }

    private function roundOff(float $amount, mixed $roundOff): float
    {
        $unit = (float) ($roundOff ?? 0);
        if ($amount <= 0 || $unit <= 0) {
            return round(max(0.0, $amount), 2);
        }

        // The base F&O behavior shown on the Book page lowers the amount to the
        // configured increment. Explicit up/down methods are localization-specific
        // and are intentionally not modeled here.
        return round(floor(($amount / $unit) + 1e-9) * $unit, 2);
    }
}
