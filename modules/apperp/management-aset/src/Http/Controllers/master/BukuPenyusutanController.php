<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterDataController;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\MasterData;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Support\MasterChild;

/**
 * @extends MasterDataController<BukuPenyusutan>
 */
class BukuPenyusutanController extends MasterDataController
{
    protected function resource(): string
    {
        return 'buku-penyusutan';
    }

    protected function model(): string
    {
        return BukuPenyusutan::class;
    }

    /** Kode diketik, misalnya `KOMERSIAL`; lihat MasterDataController::manualCode(). */
    protected function manualCode(): bool
    {
        return true;
    }

    protected function childMasters(): array
    {
        return [
            new MasterChild(table: 'aset_m_group_buku_penyusutan', column: 'buku_id', label: 'baris matriks group'),
            new MasterChild(table: 'aset_tr_buku_aset', column: 'buku_id', label: 'buku aset'),
        ];
    }

    protected function extraRules(string $tenantId, bool $creating): array
    {
        $profileExists = Rule::exists('aset_m_profil_penyusutan', 'id')
            ->where('tenant_id', $tenantId)
            ->where('aktif', true)
            ->whereNull('deleted_at');

        return [
            'posting_layer' => ['sometimes', Rule::in(BukuPenyusutan::POSTING_LAYERS)],
            // Dilebur ke `posting_layer` (K-15): buku `none` tidak pernah di-post, selain itu
            // di-post. Dua saklar yang maknanya tumpang tindih pernah menghasilkan pembalikan
            // yang terekspor padahal aslinya tidak. Kiriman lama ditolak, bukan ditelan, supaya
            // pengirimnya tahu saklarnya sudah tidak mengatur apa pun.
            'export_to_backoffice' => ['prohibited'],
            'round_off_depreciation' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'depreciation_profile_id' => ['sometimes', 'nullable', 'ulid', $profileExists],
            'alternative_profile_id' => ['sometimes', 'nullable', 'ulid', $profileExists],
        ];
    }

    protected function afterWriteValidation(array $data, string $tenantId, bool $creating, ?MasterData $record = null): void
    {
        if ($creating || ! $record) {
            return;
        }

        $configurationFields = [
            'posting_layer', 'round_off_depreciation',
            'depreciation_profile_id', 'alternative_profile_id',
        ];
        $changed = array_filter(
            $configurationFields,
            fn (string $field): bool => array_key_exists($field, $data) && $this->valuesDiffer($record->{$field}, $data[$field]),
        );
        if ($changed === []) {
            return;
        }

        $used = BukuAset::query()->where('buku_id', $record->getKey())->exists();
        if ($used) {
            throw ValidationException::withMessages([
                'depreciation_profile_id' => 'Buku ini sudah dipakai aset. Buat buku baru untuk perubahan aturan agar histori aset lama tetap utuh.',
            ]);
        }
    }

    protected function extraPayload(array $data): array
    {
        $payload = [];
        foreach (['posting_layer', 'round_off_depreciation', 'depreciation_profile_id', 'alternative_profile_id'] as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = $data[$column];
            }
        }

        return $payload;
    }

    protected function extraPresent(MasterData $record): array
    {
        return $record->only(['posting_layer', 'round_off_depreciation', 'depreciation_profile_id', 'alternative_profile_id']);
    }

    private function valuesDiffer(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left !== (float) $right;
        }

        return $left != $right;
    }
}
