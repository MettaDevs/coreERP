<?php

namespace Modules\Apperp\ManagementAset\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Models\master\AssetTypeCondition;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;

final class AssetConditionPolicy
{
    public static function choices(?string $assetTypeId): Builder
    {
        $query = KondisiAset::query();
        if ($assetTypeId !== null && $assetTypeId !== '') {
            $links = AssetTypeCondition::query()->where('jenis_aset_id', $assetTypeId);
            if ($links->exists()) {
                $query->whereIn('id', $links->select('kondisi_aset_id'));
            }
        }

        return $query;
    }

    public static function validate(?string $assetTypeId, ?string $conditionId, string $field = 'kondisi_aset_id'): void
    {
        if ($conditionId === null || $conditionId === '') {
            return;
        }
        if (! self::choices($assetTypeId)->whereKey($conditionId)->where('aktif', true)->exists()) {
            throw ValidationException::withMessages([$field => 'Kondisi tidak aktif atau tidak dapat dipakai untuk jenis aset ini.']);
        }
    }
}
