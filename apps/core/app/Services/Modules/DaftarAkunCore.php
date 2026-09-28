<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\FinanceReferenceAccount;
use App\Support\Modules\Contracts\DaftarAkun;
use Illuminate\Database\Eloquent\Builder;

/**
 * Membaca daftar akun referensi untuk module, sebagai baris biasa, bukan model Core.
 *
 * Setiap pembacaan disaring `tenant_id` yang diberikan pemanggil: id akun milik tenant lain tidak
 * pernah terbaca, sekalipun module mengirim id yang benar-benar ada.
 */
final class DaftarAkunCore implements DaftarAkun
{
    public function cari(string $tenantId, ?string $legalEntityId, string $kata = '', int $batas = 20): array
    {
        $kata = trim($kata);
        // `%` dan `_` di kata pencarian adalah huruf biasa, bukan wildcard.
        $pola = '%'.addcslashes($kata, '\\%_').'%';

        return array_values(FinanceReferenceAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->where(fn (Builder $query) => $legalEntityId === null
                ? $query->whereNull('legal_entity_id')
                : $query->whereNull('legal_entity_id')->orWhere('legal_entity_id', $legalEntityId))
            ->when($kata !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('code', 'ilike', $pola)
                ->orWhere('name', 'ilike', $pola)
                ->orWhere('external_id', $kata)))
            ->orderBy('code')
            ->limit(max(1, min($batas, 100)))
            ->get()
            ->map(self::baris(...))
            ->all());
    }

    public function satu(string $tenantId, string $accountId): ?array
    {
        $akun = FinanceReferenceAccount::query()->where('tenant_id', $tenantId)->find($accountId);

        return $akun === null ? null : self::baris($akun);
    }

    public function banyak(string $tenantId, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return FinanceReferenceAccount::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_values(array_unique($accountIds)))
            ->get()
            ->mapWithKeys(fn (FinanceReferenceAccount $akun): array => [$akun->id => self::baris($akun)])
            ->all();
    }

    /** @return array{id: string, external_id: string, code: string, name: string, type: string, active: bool, legal_entity_id: ?string} */
    private static function baris(FinanceReferenceAccount $akun): array
    {
        return [
            'id' => $akun->id,
            'external_id' => $akun->external_id,
            'code' => $akun->code,
            'name' => $akun->name,
            'type' => $akun->type,
            'active' => $akun->active,
            'legal_entity_id' => $akun->legal_entity_id,
        ];
    }
}
