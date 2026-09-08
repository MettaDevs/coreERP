<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\master;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\MasterLinkController;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\ProfilPenyusutan;

/**
 * Matriks group x buku; padanan "Fixed asset group/book" di Dynamics 365 F&O.
 *
 * Baris di sinilah yang menentukan aset dari satu group mendapat buku apa saja, dengan
 * masa manfaat dan konvensi apa. Nilainya menjadi default
 * yang disalin ke buku aset saat aset diterima, bukan acuan hidup: mengubah matriks
 * tidak menulis ulang aset yang sudah berjalan.
 */
class GroupBukuPenyusutanController extends MasterLinkController
{
    protected function ownerResource(): string
    {
        return 'group-aset';
    }

    protected function ownerTable(): string
    {
        return 'm_group_aset';
    }

    protected function ownerColumn(): string
    {
        return 'group_aset_id';
    }

    protected function table(): string
    {
        return 'm_group_buku_penyusutan';
    }

    protected function rowRules(string $tenantId): array
    {
        $activeBook = Rule::exists('m_buku_penyusutan', 'id')
            ->where('tenant_id', $tenantId)
            ->where('aktif', true)
            ->whereNull('deleted_at');
        $activeProfile = Rule::exists('m_profil_penyusutan', 'id')
            ->where('tenant_id', $tenantId)
            ->where('aktif', true)
            ->whereNull('deleted_at');

        return [
            'buku_id' => ['required', 'ulid', 'distinct', $activeBook],
            'depreciation_profile_id' => ['nullable', 'ulid', $activeProfile],
            'alternative_profile_id' => ['nullable', 'ulid', $activeProfile],
            'useful_life_periods' => ['nullable', 'integer', 'min:1'],
            'convention' => ['nullable', Rule::in(BukuPenyusutan::CONVENTIONS)],
            'depreciate' => ['sometimes', 'boolean'],
            'round_off_depreciation' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function afterRowsValidated(string $tenantId, string $ownerId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $bookIds = array_values(array_filter(array_map(
            fn (array $row): ?string => $row['buku_id'] ?? null,
            $rows,
        )));
        $books = DB::table('m_buku_penyusutan')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $bookIds)
            ->whereNull('deleted_at')
            ->get(['id', 'depreciation_profile_id', 'alternative_profile_id'])
            ->keyBy('id');

        $profileIds = [];
        foreach ($rows as $row) {
            foreach (['depreciation_profile_id', 'alternative_profile_id'] as $field) {
                if (! empty($row[$field])) {
                    $profileIds[] = $row[$field];
                }
            }
            $book = $books->get($row['buku_id'] ?? '');
            foreach (['depreciation_profile_id', 'alternative_profile_id'] as $field) {
                if (! empty($book?->{$field})) {
                    $profileIds[] = $book->{$field};
                }
            }
        }
        $profiles = DB::table('m_profil_penyusutan')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_values(array_unique($profileIds)))
            ->where('aktif', true)
            ->whereNull('deleted_at')
            ->get([
                'id', 'method', 'frequency', 'convention', 'useful_life_periods', 'rate_percent',
                'effective_from', 'effective_to',
            ])
            ->keyBy('id');

        $errors = [];
        foreach ($rows as $index => $row) {
            $book = $books->get($row['buku_id'] ?? '');
            if (! $book) {
                continue;
            }

            $profileId = $row['depreciation_profile_id'] ?? $book->depreciation_profile_id;
            $alternativeId = $row['alternative_profile_id'] ?? $book->alternative_profile_id;
            if (! $profileId) {
                $errors['rows.'.$index.'.depreciation_profile_id'] = 'Pilih profil utama di baris ini atau isi profil utama pada Buku penyusutan sebelum menyimpan konfigurasi.';
            }

            $profile = $this->profile($profiles, $profileId, $errors, 'rows.'.$index.'.depreciation_profile_id');
            if ($profile) {
                $effectiveLife = $row['useful_life_periods'] ?? $profile->useful_life_periods;
                if (in_array($profile->method, ['straight_line', 'straight_line_life_remaining', 'reducing_balance'], true) && ! $effectiveLife) {
                    $errors['rows.'.$index.'.useful_life_periods'] = 'Isi masa manfaat pada baris ini atau pada profil utama agar penyusutan dapat dihitung.';
                }
                if ($profile->method === 'reducing_balance' && ($profile->rate_percent === null || (float) $profile->rate_percent <= 0)) {
                    $errors['rows.'.$index.'.depreciation_profile_id'] = 'Profil saldo menurun harus memiliki persentase per tahun yang lebih besar dari 0.';
                }
            }

            if ($alternativeId) {
                $this->profile($profiles, $alternativeId, $errors, 'rows.'.$index.'.alternative_profile_id');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function identity(array $row): array
    {
        return ['buku_id' => $row['buku_id']];
    }

    protected function rowPayload(array $row): array
    {
        return [
            'depreciation_profile_id' => $row['depreciation_profile_id'] ?? null,
            'alternative_profile_id' => $row['alternative_profile_id'] ?? null,
            'useful_life_periods' => $row['useful_life_periods'] ?? null,
            'convention' => $row['convention'] ?? null,
            'depreciate' => filter_var($row['depreciate'] ?? true, FILTER_VALIDATE_BOOL),
            'round_off_depreciation' => $row['round_off_depreciation'] ?? null,
        ];
    }

    protected function columns(): array
    {
        return [
            'id', 'buku_id', 'depreciation_profile_id', 'alternative_profile_id',
            'useful_life_periods', 'convention', 'depreciate', 'round_off_depreciation',
        ];
    }

    /** @param array<string, object> $profiles @param array<string, string> $errors */
    private function profile($profiles, ?string $id, array &$errors, string $key): ?object
    {
        if (! $id) {
            return null;
        }
        $profile = $profiles->get($id);
        if (! $profile) {
            $errors[$key] = 'Profil penyusutan tidak aktif atau sudah diarsipkan. Pilih profil yang masih berlaku.';

            return null;
        }
        if ($profile->effective_from !== null && $profile->effective_to !== null && (string) $profile->effective_to < (string) $profile->effective_from) {
            $errors[$key] = 'Rentang tanggal berlaku profil penyusutan tidak valid.';
        }
        if (! in_array($profile->method, ProfilPenyusutan::METHODS, true)) {
            $errors[$key] = 'Metode profil penyusutan belum dapat dihitung oleh aplikasi.';
        }
        if (! in_array($profile->frequency, ProfilPenyusutan::FREQUENCIES, true)) {
            $errors[$key] = 'Frekuensi profil penyusutan belum dapat dihitung oleh aplikasi.';
        }
        if ($profile->convention !== null && ! in_array($profile->convention, BukuPenyusutan::CONVENTIONS, true)) {
            $errors[$key] = 'Konvensi profil penyusutan tidak dikenal.';
        }

        return $profile;
    }
}
