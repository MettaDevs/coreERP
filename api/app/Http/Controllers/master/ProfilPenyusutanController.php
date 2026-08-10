<?php

namespace App\Http\Controllers\master;

use App\Http\Controllers\MasterDataController;
use App\Models\master\ProfilPenyusutan;
use App\Models\MasterData;
use Illuminate\Validation\Rule;

/**
 * Profil penyusutan adalah master seperti master lainnya: kode dari Number Sequence,
 * idempotency, arsip soft delete. Sebelumnya seluruh perilaku itu ditulis ulang dengan
 * tangan di luar MasterDataController dan hanya mendukung GET serta POST.
 */
class ProfilPenyusutanController extends MasterDataController
{
    protected function resource(): string
    {
        return 'profil-penyusutan';
    }

    protected function model(): string
    {
        return ProfilPenyusutan::class;
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'method' => [...$required, Rule::in(ProfilPenyusutan::METHODS)],
            'frequency' => [...$required, Rule::in(ProfilPenyusutan::FREQUENCIES)],
            'year_basis' => [...$required, Rule::in(ProfilPenyusutan::YEAR_BASIS)],
            'convention' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Kewajiban lintas field mengikuti metode. Ditulis sebagai aturan validasi,
            // bukan abort(422) manual, supaya klien menerima pesan per field.
            'useful_life_periods' => ['required_if:method,straight_line,straight_line_life_remaining,reducing_balance', 'nullable', 'integer', 'min:1'],
            'rate_percent' => ['required_if:method,reducing_balance', 'nullable', 'numeric', 'gt:0'],
            'manual_schedule' => ['required_if:method,manual', 'nullable', 'array'],
            'manual_schedule.*.amount' => ['required_with:manual_schedule', 'numeric', 'min:0'],
        ];
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        foreach (['method', 'frequency', 'year_basis', 'convention', 'useful_life_periods', 'rate_percent', 'manual_schedule'] as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = $data[$column];
            }
        }

        return $payload;
    }

    protected function extraPresent(MasterData $record): array
    {
        return $record->only([
            'method', 'frequency', 'year_basis', 'convention',
            'useful_life_periods', 'rate_percent', 'manual_schedule',
        ]);
    }
}
