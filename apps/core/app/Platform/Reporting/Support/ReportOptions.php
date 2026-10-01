<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Support;

use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Reporting\Models\ReportLastUsedOption;
use App\Platform\Reporting\Models\ReportPreset;
use App\Platform\Tenant\Models\TenantMembership;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Opsi dan filter laporan per pengguna: yang terakhir dipakai (K-24) dan preset bernama (K-25), padanan
 * tabel `Object Options` Business Central.
 *
 * Semuanya dibatasi tenant dan kode laporan. Yang terakhir dipakai milik satu pengguna. Preset pribadi hanya
 * terlihat oleh pemiliknya; preset bersama terlihat oleh setiap pengguna tenant yang boleh menjalankan
 * laporannya — hak menjalankan itu diperiksa pemanggil sebelum kelas ini dipakai. Membuat, mengubah, dan
 * mengarsipkan preset bersama butuh `core.report-preset.update`, siapa pun pembuatnya, seperti setelan
 * "Shared with all users" di Report Settings BC.
 *
 * Nilai yang disimpan hanya parameter yang dikenal laporan, berupa teks atau daftar teks (filter pilihan
 * banyak). Isinya tidak divalidasi di sini: aturannya milik module, dan module memeriksanya setiap kali
 * laporan dijalankan, jadi nilai yang sudah tidak berlaku ditolak di sana dengan pesan yang sama seperti
 * isian tangan.
 */
final class ReportOptions
{
    private const MAX_VALUE_LENGTH = 200;

    private const MAX_LIST_ITEMS = 200;

    /** Parameter filter tambahan pengguna (K-30), sama dengan `AdditionalFilters::PARAMETER` di module. */
    private const ADDITIONAL_FILTERS = 'filters';

    private const MAX_FILTER_EXPRESSION_LENGTH = 250;

    public function __construct(private readonly UserClock $clock) {}

    /**
     * Isian awal halaman filter dan dialog cetak: opsi terakhir pengguna ini, dan preset yang boleh ia lihat
     * dengan tanggal relatifnya sudah diterjemahkan menurut zonanya.
     *
     * @return array{last_used: array<string, mixed>|null, presets: list<array<string, mixed>>, can_share: bool}
     */
    public function forMembership(TenantMembership $membership, stdClass $report, ?string $legalEntityId): array
    {
        $now = $this->now($membership, $legalEntityId);
        $last = ReportLastUsedOption::query()
            ->where(['tenant_id' => $membership->tenant_id, 'user_id' => $membership->user_id, 'report_code' => $report->code])
            ->first();

        $presets = $this->visiblePresets($membership, $report)
            ->with('user:id,name')
            ->orderByDesc('shared')
            ->orderBy('name')
            ->get();

        return [
            'last_used' => $last === null ? null : [
                'parameters' => self::clean($report, $last->parameters, allowTokens: false),
                'format' => $last->format,
                'layout_ref' => $last->layout_ref,
                'updated_at' => $last->updated_at?->toIso8601String(),
            ],
            'presets' => array_values($presets->map(fn (ReportPreset $preset): array => $this->present($preset, $membership, $now))->all()),
            'can_share' => self::canShare($membership),
        ];
    }

    /** Boleh membuat, mengubah, dan mengarsipkan preset bersama. */
    public static function canShare(TenantMembership $membership): bool
    {
        return $membership->hasCorePermission(CoreSecurityCatalog::REPORT_PRESET_UPDATE);
    }

    /**
     * Mencatat opsi yang baru saja dipakai. Dipanggil saat laporan dijalankan — pratinjau di layar dan
     * permintaan ekspor — jadi yang terakhir menang, tanpa versi: ini jejak kebiasaan pengguna, bukan data
     * yang diedit bersama. `null` untuk format atau layout berarti yang tersimpan dipertahankan.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function rememberLastUsed(TenantMembership $membership, stdClass $report, array $parameters, ?string $format = null, ?string $layoutRef = null): void
    {
        $now = now();
        // Satu pernyataan, supaya dua tab yang menjalankan laporan yang sama bersamaan tidak jatuh di indeks unik.
        DB::statement(
            'INSERT INTO report_last_used_options (id, tenant_id, user_id, report_code, parameters, format, layout_ref, created_at, updated_at) '
            .'VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?) '
            .'ON CONFLICT (tenant_id, user_id, report_code) WHERE deleted_at IS NULL DO UPDATE SET '
            .'parameters = EXCLUDED.parameters, '
            .'format = COALESCE(EXCLUDED.format, report_last_used_options.format), '
            .'layout_ref = COALESCE(EXCLUDED.layout_ref, report_last_used_options.layout_ref), '
            .'updated_at = EXCLUDED.updated_at',
            [
                (string) Str::ulid(), $membership->tenant_id, $membership->user_id, $report->code,
                json_encode((object) self::clean($report, $parameters, allowTokens: false), JSON_THROW_ON_ERROR),
                $format, $layoutRef, $now, $now,
            ],
        );
    }

    /**
     * Preset baru. Preset bersama hanya untuk pemegang `core.report-preset.update`; pemanggil memeriksanya.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function createPreset(TenantMembership $membership, stdClass $report, string $name, array $parameters, bool $shared = false): ReportPreset
    {
        $name = $this->name($name);
        $this->assertNameFree($membership, $report, $name, null, $shared);

        try {
            // Savepoint: pelanggaran indeks unik membatalkan seluruh transaksi PostgreSQL bila tidak dibatasi.
            return DB::transaction(fn (): ReportPreset => ReportPreset::query()->create([
                'tenant_id' => $membership->tenant_id,
                'user_id' => $membership->user_id,
                'report_code' => $report->code,
                'name' => $name,
                'shared' => $shared,
                'parameters' => self::clean($report, $parameters, allowTokens: true),
            ])->refresh());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [self::duplicateMessage($shared)]]);
        }
    }

    /**
     * Mengganti nama atau isi preset, dengan versi baris. Preset yang boleh diubah dipilih `editablePreset()`.
     *
     * @param  array<string, mixed>|null  $parameters
     */
    public function updatePreset(ReportPreset $preset, stdClass $report, TenantMembership $membership, int $expectedVersion, ?string $name, ?array $parameters): ReportPreset
    {
        $values = [];
        if ($name !== null) {
            $values['name'] = $this->name($name);
            $this->assertNameFree($membership, $report, $values['name'], $preset->id, $preset->shared);
        }
        if ($parameters !== null) {
            $values['parameters'] = self::clean($report, $parameters, allowTokens: true);
        }

        try {
            DB::transaction(function () use ($preset, $expectedVersion, $values): void {
                RowVersion::claim($preset, $expectedVersion);
                if ($values !== []) {
                    $preset->forceFill($values)->save();
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => [self::duplicateMessage($preset->shared)]]);
        }

        return $preset->refresh();
    }

    /** Mengarsipkan preset, dengan versi baris. Preset yang boleh diarsipkan dipilih `editablePreset()`. */
    public function archivePreset(ReportPreset $preset, int $expectedVersion): void
    {
        DB::transaction(function () use ($preset, $expectedVersion): void {
            RowVersion::claim($preset, $expectedVersion);
            $preset->delete();
        });
    }

    /**
     * Preset yang boleh diubah dan diarsipkan pengguna ini: preset pribadinya sendiri, dan preset bersama bila
     * ia memegang `core.report-preset.update`. Selain itu dijawab tidak ada, termasuk preset pribadi orang lain.
     */
    public function editablePreset(TenantMembership $membership, stdClass $report, string $id): ?ReportPreset
    {
        $canShare = self::canShare($membership);

        return ReportPreset::query()
            ->where(['tenant_id' => $membership->tenant_id, 'report_code' => $report->code])
            ->where(function (Builder $query) use ($membership, $canShare): void {
                $query->where(fn (Builder $own) => $own->where('user_id', $membership->user_id)->where('shared', false));
                if ($canShare) {
                    $query->orWhere('shared', true);
                }
            })
            ->whereKey($id)
            ->first();
    }

    /** @return array<string, mixed> */
    public function present(ReportPreset $preset, TenantMembership $membership, CarbonImmutable $now): array
    {
        return [
            'id' => $preset->id,
            'name' => $preset->name,
            'shared' => $preset->shared,
            'mine' => $preset->user_id === (int) $membership->user_id,
            // Nama, bukan id: layar menulis "Dibuat oleh Rina" pada preset bersama.
            'owner_name' => $preset->user?->name,
            'parameters' => $preset->parameters,
            'resolved_parameters' => RelativeDates::resolve($preset->parameters, $now),
            'version' => $preset->version,
        ];
    }

    /** Saat ini menurut zona pengguna, untuk menerjemahkan tanggal relatif. */
    public function now(TenantMembership $membership, ?string $legalEntityId): CarbonImmutable
    {
        return CarbonImmutable::now($this->clock->timezoneFor($membership->user, $legalEntityId));
    }

    /**
     * Parameter yang dikenal laporan, berupa teks atau daftar teks; nilai kosong dibuang. Token tanggal
     * relatif hanya boleh ada di preset, dan harus salah satu yang dikenal.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, string|list<string>|array<string, array<string, string|list<string>>>>
     */
    public static function clean(stdClass $report, array $parameters, bool $allowTokens): array
    {
        // Urut seperti parameter laporannya, supaya isian yang sama selalu tersimpan dan terbaca sama.
        $clean = [];
        foreach (array_map('strval', (array) $report->parameters) as $key) {
            if (! array_key_exists($key, $parameters)) {
                continue;
            }
            $value = $parameters[$key];
            if ($key === self::ADDITIONAL_FILTERS) {
                $filters = is_array($value) ? self::cleanAdditionalFilters($value) : [];
                if ($filters !== []) {
                    $clean[$key] = $filters;
                }

                continue;
            }
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                if (RelativeDates::isToken($value) && (! $allowTokens || ! RelativeDates::known($value))) {
                    throw ValidationException::withMessages(["parameters.{$key}" => ['Pilihan tanggal relatif ini tidak dikenal.']]);
                }
                $clean[$key] = mb_substr($value, 0, self::MAX_VALUE_LENGTH);

                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                $items = array_values(array_unique(array_filter(
                    array_map(fn (mixed $item): string => is_scalar($item) ? mb_substr(trim((string) $item), 0, self::MAX_VALUE_LENGTH) : '', $value),
                    fn (string $item): bool => $item !== '' && ! RelativeDates::isToken($item),
                )));
                if ($items !== []) {
                    $clean[$key] = array_slice($items, 0, self::MAX_LIST_ITEMS);
                }
            }
        }

        return $clean;
    }

    /**
     * Filter tambahan pengguna (K-30): `filters[<data item>][<kolom>]` berisi ekspresi filter BC atau daftar
     * nilai. Di sini hanya bentuknya yang dirapikan; kolom dan ekspresinya diperiksa module saat laporan
     * dijalankan. Awalan `@` di sini berarti "tidak peka huruf besar" milik sintaks BC, bukan token tanggal.
     *
     * @param  array<array-key, mixed>  $filters
     * @return array<string, array<string, string|list<string>>>
     */
    private static function cleanAdditionalFilters(array $filters): array
    {
        $clean = [];
        foreach (array_slice($filters, 0, 10, true) as $item => $columns) {
            if (! is_string($item) || preg_match('/^[a-z0-9_]{1,64}$/', $item) !== 1 || ! is_array($columns)) {
                continue;
            }
            foreach (array_slice($columns, 0, 50, true) as $column => $value) {
                if (! is_string($column) || preg_match('/^[a-z0-9_]{1,64}$/', $column) !== 1) {
                    continue;
                }
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $clean[$item][$column] = mb_substr(trim((string) $value), 0, self::MAX_FILTER_EXPRESSION_LENGTH);
                } elseif (is_array($value) && array_is_list($value)) {
                    $items = array_values(array_unique(array_filter(
                        array_map(fn (mixed $entry): string => is_scalar($entry) ? mb_substr(trim((string) $entry), 0, self::MAX_VALUE_LENGTH) : '', $value),
                        fn (string $entry): bool => $entry !== '',
                    )));
                    if ($items !== []) {
                        $clean[$item][$column] = array_slice($items, 0, self::MAX_LIST_ITEMS);
                    }
                }
            }
        }

        return $clean;
    }

    /** @return Builder<ReportPreset> */
    private function visiblePresets(TenantMembership $membership, stdClass $report): Builder
    {
        return ReportPreset::query()
            ->where(['tenant_id' => $membership->tenant_id, 'report_code' => $report->code])
            ->where(fn (Builder $query) => $query->where('user_id', $membership->user_id)->orWhere('shared', true));
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => ['Nama preset wajib diisi, paling panjang 80 karakter.']]);
        }

        return $name;
    }

    /** Nama preset pribadi unik per pemiliknya; nama preset bersama unik per laporan. */
    private function assertNameFree(TenantMembership $membership, stdClass $report, string $name, ?string $exceptId, bool $shared): void
    {
        $taken = ReportPreset::query()
            ->where(['tenant_id' => $membership->tenant_id, 'report_code' => $report->code, 'shared' => $shared])
            ->when(! $shared, fn (Builder $query) => $query->where('user_id', $membership->user_id))
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => [self::duplicateMessage($shared)]]);
        }
    }

    private static function duplicateMessage(bool $shared): string
    {
        return $shared
            ? 'Sudah ada preset bersama dengan nama ini untuk laporan ini. Pilih nama lain.'
            : 'Anda sudah punya preset dengan nama ini untuk laporan ini. Pilih nama lain.';
    }
}
