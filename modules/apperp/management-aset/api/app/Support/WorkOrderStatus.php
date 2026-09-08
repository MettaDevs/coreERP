<?php

namespace App\Support;

/**
 * Mesin status work order pemeliharaan.
 *
 * Ia dikumpulkan di satu kelas, bukan disebar sebagai `abort_if` di controller seperti
 * `lifecycle_state` aset, karena work order berpindah tangan: perencana menjadwalkan,
 * teknisi mengerjakan, penyelia menutup. Ketika daftar transisi dan hak yang menjaganya
 * tersebar, satu jalur akan terlewat dan status dapat dilompati.
 *
 * Kelas ini hanya memiliki grafik transisi dan hak yang menjaganya. Syarat isi data —
 * baris pekerjaan ada, checklist wajib terisi, sebab dan tindakan sesuai tipe — bergantung
 * pada database dan ditegakkan controller.
 */
final class WorkOrderStatus
{
    public const DRAFT = 'draft';

    public const DIJADWALKAN = 'dijadwalkan';

    public const DIKERJAKAN = 'dikerjakan';

    public const SELESAI = 'selesai';

    public const DITUTUP = 'ditutup';

    public const DIBATALKAN = 'dibatalkan';

    public const ALL = [
        self::DRAFT, self::DIJADWALKAN, self::DIKERJAKAN,
        self::SELESAI, self::DITUTUP, self::DIBATALKAN,
    ];

    /**
     * Status akhir. Dokumen yang berada di sini tidak dapat diubah, tidak dapat berpindah
     * status, dan tidak dapat diarsipkan menjadi sesuatu yang lain.
     */
    public const AKHIR = [self::DITUTUP, self::DIBATALKAN];

    /**
     * Transisi yang sah beserta aksi permission yang menjaganya.
     *
     * Pembatalan dijaga `schedule`, bukan `update`: teknisi yang hanya boleh mengerjakan
     * tidak boleh membatalkan pekerjaannya sendiri, dan pembatalan pekerjaan yang sudah
     * berjalan adalah keputusan penjadwalan, bukan penyuntingan dokumen.
     *
     * @var array<string, array<string, string>>
     */
    private const TRANSISI = [
        self::DRAFT => [self::DIJADWALKAN => 'schedule', self::DIBATALKAN => 'schedule'],
        self::DIJADWALKAN => [self::DIKERJAKAN => 'execute', self::DIBATALKAN => 'schedule'],
        self::DIKERJAKAN => [self::SELESAI => 'execute', self::DIBATALKAN => 'schedule'],
        self::SELESAI => [self::DITUTUP => 'close', self::DIBATALKAN => 'schedule'],
        self::DITUTUP => [],
        self::DIBATALKAN => [],
    ];

    /** Status yang masih mengizinkan baris pekerjaan diganti secara massal. */
    public static function dapatDisunting(string $status): bool
    {
        return $status === self::DRAFT;
    }

    public static function terkunci(string $status): bool
    {
        return in_array($status, self::AKHIR, true);
    }

    public static function dapatBerpindah(string $dari, string $ke): bool
    {
        return isset(self::TRANSISI[$dari][$ke]);
    }

    /** Aksi permission yang wajib dimiliki untuk satu transisi; null bila transisi tidak sah. */
    public static function aksiUntuk(string $dari, string $ke): ?string
    {
        return self::TRANSISI[$dari][$ke] ?? null;
    }

    /** @return list<string> */
    public static function tujuanDari(string $dari): array
    {
        return array_keys(self::TRANSISI[$dari] ?? []);
    }

    /** Pembatalan selalu menuntut alasan; tanpa itu riwayat tidak dapat dibaca ulang. */
    public static function butuhAlasan(string $ke): bool
    {
        return $ke === self::DIBATALKAN;
    }
}
