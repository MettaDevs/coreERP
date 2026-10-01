<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\UnitOfMeasureDirectory;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Satuan lewat kontrak Core, bukan lewat HTTP.
 *
 * Menggantikan klien HTTP yang memanggil `/api/internal/v1/units-of-measure`. Kegagalannya dulu
 * melempar `RuntimeException` bertuliskan "Satuan belum dapat dihubungi" yang berakhir 500 di
 * layar pengguna; Core sekarang berada di proses yang sama, jadi kalimat itu tidak pernah lagi
 * benar.
 *
 * Tanda tangan kedua methodnya sengaja dipertahankan supaya pemanggilnya tidak ikut berubah.
 */
class AssetUnitOfMeasureDirectory
{
    public function __construct(private readonly UnitOfMeasureDirectory $directory) {}

    /** @return list<array{id:string,code:string,name:string,symbol:?string,decimal_places:int}> */
    public function active(string $tenantId): array
    {
        /** @var list<array{id:string,code:string,name:string,symbol:?string,decimal_places:int}> $result */
        $result = $this->directory->active($tenantId);

        return $result;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{id:string,code:string,name:string,symbol:?string,decimal_places:int}>
     */
    public function resolve(string $tenantId, array $ids): array
    {
        try {
            $directory = $this->directory->resolve($tenantId, $ids);
        } catch (ValidationException) {
            // Core melempar `ValidationException` dengan kunci `unit_ids` — nama field milik
            // permintaan **Core**, bukan milik module. Dibiarkan lewat, ia muncul di jawaban
            // module sebagai kesalahan validasi pada field yang tidak pernah dikirim pengguna.
            //
            // Selama jalurnya HTTP, penerjemahan ini terjadi dengan sendirinya: kegagalan
            // datang sebagai status, bukan sebagai exception milik Core. Sekarang pembungkus
            // ini yang harus melakukannya — dan itu salah satu alasan pembungkus per module ada.
            throw new RuntimeException('Satuan belum dapat divalidasi.');
        }

        // Pemeriksaan jumlah hasil dipertahankan apa adanya. Ia yang menangkap id satuan yang
        // sudah dihapus di Core: resolusi memulangkan lebih sedikit daripada yang diminta, dan
        // tanpa pemeriksaan ini record tersimpan menunjuk satuan yang tidak ada.
        if (count($directory) !== count($ids)) {
            throw new RuntimeException('Satuan belum dapat divalidasi.');
        }

        /** @var array<string, array{id:string,code:string,name:string,symbol:?string,decimal_places:int}> $directory */
        return $directory;
    }
}
