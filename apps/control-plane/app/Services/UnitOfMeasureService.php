<?php

namespace App\Services;

use App\Models\UnitOfMeasure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UnitOfMeasureService
{
    /** @param list<string> $ids @return array<string, array{id:string,code:string,name:string,symbol:?string,decimal_places:int}> */
    public function resolve(string $tenantId, array $ids): array
    {
        $units = UnitOfMeasure::query()->where('tenant_id', $tenantId)->where('active', true)->whereIn('id', $ids)
            ->get(['id', 'code', 'name', 'symbol', 'decimal_places'])->keyBy('id');
        if ($units->count() !== count($ids)) {
            throw ValidationException::withMessages(['unit_ids' => 'Satuan tidak ditemukan atau sudah tidak aktif.']);
        }

        return $units->map(fn (UnitOfMeasure $unit): array => [
            'id' => $unit->id, 'code' => $unit->code, 'name' => $unit->name,
            'symbol' => $unit->symbol, 'decimal_places' => $unit->decimal_places,
        ])->all();
    }

    /** @return array{value:string,unit_id:string} */
    public function convert(string $tenantId, string $from, string $to, string $value): array
    {
        // ponytail: product-specific rules (for example, box-to-piece) belong to PIM/Inventory once it owns products.
        $units = UnitOfMeasure::query()->where('tenant_id', $tenantId)->whereIn('id', [$from, $to])->where('active', true)
            ->get(['id', 'uom_class_id'])->keyBy('id');
        if ($units->count() !== 2 || $units[$from]->uom_class_id !== $units[$to]->uom_class_id) {
            throw ValidationException::withMessages(['to_unit_id' => 'Satuan harus aktif dan berada dalam kelas yang sama.']);
        }
        if ($from === $to) {
            return ['value' => $value, 'unit_id' => $to];
        }
        $rule = DB::table('uom_conversions')->where(['tenant_id' => $tenantId, 'from_unit_id' => $from, 'to_unit_id' => $to])->first();
        if (! $rule) {
            throw ValidationException::withMessages(['to_unit_id' => 'Aturan konversi satuan belum tersedia.']);
        }
        $result = ((float) $value * (float) $rule->factor) + (float) $rule->offset;
        if ($rule->rounding_scale !== null) {
            $result = round($result, (int) $rule->rounding_scale);
        }

        return ['value' => (string) $result, 'unit_id' => $to];
    }
}
