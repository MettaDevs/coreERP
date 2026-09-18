<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting;

final class ReportHelper
{
    private const BULAN = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    /**
     * Format nilai moneter ke Rupiah teks (misal: "Rp 20.000.000" atau "Rp 416.666,67").
     */
    public static function rupiah(float|int|string|null $nilai, bool $pakaiKoma = false): string
    {
        if ($nilai === null || $nilai === '') {
            return 'Rp 0';
        }

        $angka = (float) $nilai;
        $tanda = $angka < 0 ? '-' : '';
        $mutlak = abs($angka);

        if ($pakaiKoma || fmod($mutlak, 1.0) !== 0.0) {
            $format = number_format($mutlak, 2, ',', '.');
        } else {
            $format = number_format($mutlak, 0, ',', '.');
        }

        return $tanda.'Rp '.$format;
    }

    /**
     * Format tanggal Indonesia (misal: "23 Juli 2026").
     */
    public static function tanggalIndo(?string $tanggal): string
    {
        if (! $tanggal) {
            return '—';
        }

        $time = strtotime($tanggal);
        if ($time === false) {
            return $tanggal;
        }

        $tgl = (int) date('j', $time);
        $bln = (int) date('n', $time);
        $thn = date('Y', $time);

        return sprintf('%d %s %s', $tgl, self::BULAN[$bln] ?? '', $thn);
    }

    /**
     * Nama bulan Indonesia (misal: "Juli").
     */
    public static function bulanIndo(int|string|null $bulan): string
    {
        $num = (int) $bulan;

        return self::BULAN[$num] ?? (string) $bulan;
    }

    /**
     * Rumus spesifikasi seragam: model aset + nomor model + serial number.
     */
    public static function spesifikasi(?string $modelNama, ?string $modelNumber, ?string $serialNumber): string
    {
        $parts = array_filter([$modelNama, $modelNumber], fn ($v) => $v !== null && trim((string) $v) !== '');
        $main = implode(' ', $parts);

        if ($serialNumber !== null && trim((string) $serialNumber) !== '') {
            $main = $main ? "{$main} (SN: {$serialNumber})" : "SN: {$serialNumber}";
        }

        return $main ?: '—';
    }
}
