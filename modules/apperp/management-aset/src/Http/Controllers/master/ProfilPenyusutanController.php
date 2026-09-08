<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\ProfilPenyusutan;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Support\MasterChild;

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

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'aset_m_buku_penyusutan', column: 'depreciation_profile_id', label: 'buku penyusutan'),
            new MasterChild(table: 'aset_m_buku_penyusutan', column: 'alternative_profile_id', label: 'buku penyusutan'),
            new MasterChild(table: 'aset_m_group_buku_penyusutan', column: 'depreciation_profile_id', label: 'baris matriks group'),
            new MasterChild(table: 'aset_m_group_buku_penyusutan', column: 'alternative_profile_id', label: 'baris matriks group'),
            new MasterChild(table: 'aset_tr_buku_aset', column: 'depreciation_profile_id', label: 'buku aset'),
            new MasterChild(table: 'aset_tr_buku_aset', column: 'alternative_profile_id', label: 'buku aset'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'method' => [...$required, Rule::in(ProfilPenyusutan::METHODS)],
            'frequency' => [...$required, Rule::in(ProfilPenyusutan::FREQUENCIES)],
            'year_basis' => [...$required, Rule::in(ProfilPenyusutan::YEAR_BASIS)],
            'convention' => ['sometimes', 'nullable', Rule::in(BukuPenyusutan::CONVENTIONS)],
            // Kewajiban lintas field mengikuti metode. Ditulis sebagai aturan validasi,
            // bukan abort(422) manual, supaya klien menerima pesan per field.
            'useful_life_periods' => ['required_if:method,straight_line,straight_line_life_remaining,reducing_balance', 'nullable', 'integer', 'min:1'],
            'rate_percent' => ['required_if:method,reducing_balance', 'nullable', 'numeric', 'gt:0'],
            'manual_schedule' => ['required_if:method,manual', 'nullable', 'array'],
            'manual_schedule.*.amount' => ['required_with:manual_schedule', 'numeric', 'min:0'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
        ];
    }

    protected function afterWriteValidation(array $data, string $tenantId, bool $creating, ?MasterData $record = null): void
    {
        $from = array_key_exists('effective_from', $data) ? $data['effective_from'] : $record?->effective_from;
        $to = array_key_exists('effective_to', $data) ? $data['effective_to'] : $record?->effective_to;
        if ($from !== null && $to !== null && $this->dateString($to) < $this->dateString($from)) {
            throw ValidationException::withMessages([
                'effective_to' => 'Tanggal berlaku sampai tidak boleh lebih awal dari tanggal berlaku mulai.',
            ]);
        }

        if ($creating || ! $record) {
            return;
        }

        $computationFields = [
            'method', 'frequency', 'year_basis', 'convention', 'useful_life_periods',
            'rate_percent', 'manual_schedule', 'effective_from', 'effective_to',
        ];
        $changed = array_filter(
            $computationFields,
            fn (string $field): bool => array_key_exists($field, $data) && $this->valuesDiffer($record->{$field}, $data[$field], $field),
        );
        if ($changed === []) {
            return;
        }

        $used = DB::table('aset_tr_buku_aset')
            ->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query
                ->where('depreciation_profile_id', $record->getKey())
                ->orWhere('alternative_profile_id', $record->getKey()))
            ->exists();
        if ($used) {
            throw ValidationException::withMessages([
                'method' => 'Profil ini sudah dipakai buku aset. Buat profil baru atau versi dengan tanggal berlaku baru agar histori aset lama tetap utuh.',
            ]);
        }
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        foreach (['method', 'frequency', 'year_basis', 'convention', 'useful_life_periods', 'rate_percent', 'manual_schedule', 'effective_from', 'effective_to'] as $column) {
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
            'useful_life_periods', 'rate_percent', 'manual_schedule', 'effective_from', 'effective_to',
        ]);
    }

    private function dateString(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private function valuesDiffer(mixed $left, mixed $right, string $field): bool
    {
        if (in_array($field, ['effective_from', 'effective_to'], true)) {
            return $this->dateString($left) !== $this->dateString($right);
        }
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left !== (float) $right;
        }

        return $left != $right;
    }
}
