<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterLinkController;
use Modules\Apperp\ManagementAset\Models\master\TipeAtribut;
use Modules\Apperp\ManagementAset\Models\master\TipeAtributNilai;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\AssetAttribute;

/**
 * Pilihan nilai untuk atribut bertipe daftar tetap, disunting di dalam form atributnya.
 */
class TipeAtributNilaiController extends MasterLinkController
{
    protected function ownerResource(): string
    {
        return 'tipe-atribut';
    }

    protected function ownerModel(): string
    {
        return TipeAtribut::class;
    }

    protected function ownerColumn(): string
    {
        return 'tipe_atribut_id';
    }

    protected function model(): string
    {
        return TipeAtributNilai::class;
    }

    protected function rowRules(string $tenantId): array
    {
        return [
            'nilai' => ['required', 'string', 'max:150'],
            'urutan' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function identity(array $row): array
    {
        return ['nilai' => $row['nilai']];
    }

    protected function rowPayload(array $row): array
    {
        return ['urutan' => (int) ($row['urutan'] ?? 0)];
    }

    protected function columns(): array
    {
        return ['id', 'nilai', 'urutan'];
    }

    protected function afterRowsValidated(string $tenantId, string $ownerId, array $rows): void
    {
        $dataType = TipeAtribut::query()->whereKey($ownerId)->value('data_type');
        if ($dataType !== 'string') {
            throw ValidationException::withMessages([
                'rows' => 'Pilihan nilai hanya dapat dipakai untuk tipe data teks.',
            ]);
        }
        if ($rows === []) {
            return;
        }

        $allowed = collect($rows)->pluck('nilai')->map(fn (mixed $value): string => trim((string) $value))->unique()->values();
        $conflicts = AssetAttribute::query()
            ->where('tipe_atribut_id', $ownerId)
            ->whereNotNull('nilai_text')
            ->whereNotIn('nilai_text', $allowed->all());
        if (! $conflicts->exists()) {
            return;
        }

        abort(response()->json(['error' => [
            'code' => 'attribute_values_in_use',
            'message' => 'Pilihan belum dapat diterapkan karena ada nilai aset yang tidak tercakup.',
            'conflicting_count' => (clone $conflicts)->count(),
            'conflicting_values' => (clone $conflicts)->distinct()->orderBy('nilai_text')->limit(20)->pluck('nilai_text')->values(),
        ]], 409));
    }
}
