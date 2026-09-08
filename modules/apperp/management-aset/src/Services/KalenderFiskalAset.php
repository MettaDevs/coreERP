<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Support\Modules\Contracts\KalenderFiskal;
use Illuminate\Validation\ValidationException;

/**
 * Periode fiskal lewat kontrak Core, bukan lewat HTTP.
 *
 * Menggantikan klien HTTP yang memanggil `/api/internal/v1/fiscal-periods`.
 *
 * **Satu perbedaan perilaku yang harus ditutup di sini, bukan dibiarkan bocor.** Endpoint HTTP
 * menjawab 404 ketika entitas legal belum punya kalender atau tanggalnya di luar tahun fiskal,
 * dan klien lama menerjemahkannya menjadi `null` — pemanggil yang memutuskan apakah itu
 * kesalahan. Layanan Core yang dipanggil kontrak justru **melempar** untuk keadaan yang sama.
 *
 * Kalau perbedaan itu dibiarkan, pendaftaran aset yang tanggalnya di luar tahun fiskal berubah
 * dari "silakan pilih tanggal lain" menjadi kesalahan yang tidak diminta siapa pun. Jadi
 * pembungkus ini mengembalikan `null` seperti sebelumnya, dan pemanggilnya tidak ikut berubah.
 *
 * Bahwa Core punya dua jalur dengan jawaban berbeda untuk keadaan yang sama adalah temuan
 * tersendiri; `FiscalCalendarDirectoryController` menyalin ulang logika layanannya. Dibereskan
 * pada F3-20.
 */
class KalenderFiskalAset
{
    public function __construct(private readonly KalenderFiskal $kalender) {}

    /**
     * @return array{calendar: array<string,mixed>, year: array<string,mixed>, period: array<string,mixed>}|null
     *                                                                                                           `null` bila entitas legal belum punya kalender atau tanggalnya belum tercakup.
     */
    public function resolve(string $tenantId, string $legalEntityId, string $date): ?array
    {
        try {
            /** @var array{calendar: array<string,mixed>, year: array<string,mixed>, period: array<string,mixed>} $hasil */
            $hasil = $this->kalender->periode($legalEntityId, $date);

            return $hasil;
        } catch (ValidationException) {
            return null;
        }
    }
}
