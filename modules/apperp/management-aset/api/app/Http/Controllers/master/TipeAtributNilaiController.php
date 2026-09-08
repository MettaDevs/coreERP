<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterLinkController;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pilihan nilai untuk atribut bertipe daftar tetap, disunting di dalam form atributnya.
 */
class TipeAtributNilaiController extends MasterLinkController
{
    protected function ownerResource(): string
    {
        return 'tipe-atribut';
    }

    protected function ownerTable(): string
    {
        return 'm_tipe_atribut';
    }

    protected function ownerColumn(): string
    {
        return 'tipe_atribut_id';
    }

    protected function table(): string
    {
        return 'm_tipe_atribut_nilai';
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
        $dataType = DB::table('m_tipe_atribut')
            ->where(['tenant_id' => $tenantId, 'id' => $ownerId])
            ->value('data_type');
        if ($dataType !== 'string') {
            throw ValidationException::withMessages([
                'rows' => 'Pilihan nilai hanya dapat dipakai untuk tipe data teks.',
            ]);
        }
        if ($rows === []) {
            return;
        }

        $allowed = collect($rows)->pluck('nilai')->map(fn (mixed $value): string => trim((string) $value))->unique()->values();
        $conflicts = DB::table('tr_aset_atribut')
            ->where(['tenant_id' => $tenantId, 'tipe_atribut_id' => $ownerId])
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
