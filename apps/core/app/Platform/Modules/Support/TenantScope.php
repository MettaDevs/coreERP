<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use RuntimeException;

/**
 * Menyisipkan penyaringan tenant pada setiap query model module.
 *
 * Dulu kebocoran antar tenant tertahan oleh database yang memang terpisah. Sekarang semua
 * tenant berada di satu tabel, jadi satu query yang lupa menyaring mengembalikan baris
 * milik seluruh pelanggan. Database tidak bisa mencegahnya; scope inilah yang mencegahnya.
 *
 * **Gagal menutup, bukan gagal membuka.** Bila tenant aktif tidak diketahui, query dibatalkan
 * dengan pengecualian, bukan dijalankan tanpa penyaringan. Pilihan sebaliknya berarti sebuah
 * pekerjaan latar yang lupa menyetel konteks akan membaca data semua orang tanpa satu pun
 * tanda bahaya — kegagalan paling mahal yang bisa terjadi pada penempatan gabungan.
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    /** Kunci wadah tempat tenant aktif disimpan. Diisi middleware; di test diisi langsung. */
    public const KEY = 'coreerp.module.tenant_id';

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('tenant_id'), self::activeTenant());
    }

    public static function activeTenant(): string
    {
        $tenantId = app()->bound(self::KEY) ? app(self::KEY) : null;

        if (! is_string($tenantId) || $tenantId === '') {
            throw new RuntimeException(
                'Query module dijalankan tanpa tenant aktif. Setel '.self::KEY.' lebih dulu; '.
                'menjalankannya tanpa penyaringan akan membaca data seluruh tenant.'
            );
        }

        return $tenantId;
    }
}
