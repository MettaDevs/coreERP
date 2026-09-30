<?php

declare(strict_types=1);

namespace App\Support\Retention;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Satu-satunya jalan menghapus log berdasarkan umur (area 4, K-14 sampai K-16). Membaca setelan tiap tenant,
 * menghapus baris yang lewat masa simpan dalam kelompok kecil, dan mencatat hasilnya ke
 * `retention_policy_log_entries` bila ada baris terhapus atau penghapusan gagal.
 *
 * Penghapusannya fisik dan itu benar di sini: yang dihapus log dan berkas teknis, bukan data bisnis. Tabel di
 * luar `RetentionPolicies` tidak pernah disentuh.
 *
 * Kebijakan yang punya bawaan selalu berjalan; tenant hanya memilih masa simpan, tidak kurang dari minimum.
 * Kebijakan tanpa bawaan mati sampai tenant menyalakannya.
 */
final class RetentionService
{
    private const BATCH = 1000;

    /** Hasil ekspor punya berkas di disk, jadi kelompoknya lebih kecil. */
    private const FILE_BATCH = 200;

    /**
     * Menerapkan kebijakan untuk satu atau semua tenant.
     *
     * @return int Jumlah baris yang dihapus.
     */
    public function apply(?string $policyCode = null, ?string $tenantId = null): int
    {
        $policies = $policyCode === null ? RetentionPolicies::all() : [RetentionPolicies::find($policyCode)];
        $tenantIds = $tenantId !== null ? [$tenantId] : DB::table('tenants')->orderBy('id')->pluck('id')->all();

        $total = 0;
        foreach ($tenantIds as $id) {
            $settings = $this->settingsFor((string) $id);
            foreach ($policies as $policy) {
                $total += $this->applyToTenant($policy, (string) $id, $settings[$policy->code]);
            }
        }

        return $total;
    }

    /**
     * Setelan efektif tenant untuk semua kebijakan. `days` tidak pernah di bawah minimum, walau config bawaan
     * diisi lebih kecil.
     *
     * @return array<string, array{enabled: bool, days: int, customized: bool, optional: bool}>
     */
    public function settingsFor(string $tenantId): array
    {
        $rows = DB::table('retention_policy_setups')->where('tenant_id', $tenantId)->get()->keyBy('policy_code');

        $settings = [];
        foreach (RetentionPolicies::all() as $policy) {
            $row = $rows->get($policy->code);
            $default = $policy->defaultDays();
            $optional = $default === null;

            $settings[$policy->code] = [
                'enabled' => $optional ? (bool) ($row->enabled ?? false) : true,
                'days' => max($policy->minimumDays, (int) ($row->retention_days ?? $default ?? $policy->minimumDays)),
                'customized' => $row !== null,
                'optional' => $optional,
            ];
        }

        return $settings;
    }

    /** Masa simpan efektif satu kebijakan, atau null bila kebijakan itu mati untuk tenant ini. */
    public function daysFor(string $policyCode, string $tenantId): ?int
    {
        $setting = $this->settingsFor($tenantId)[$policyCode];

        return $setting['enabled'] ? $setting['days'] : null;
    }

    public function save(string $tenantId, RetentionPolicy $policy, bool $enabled, ?int $days): void
    {
        $values = ['enabled' => $enabled, 'retention_days' => $days, 'updated_at' => now()];
        $key = ['tenant_id' => $tenantId, 'policy_code' => $policy->code];

        if (DB::table('retention_policy_setups')->where($key)->exists()) {
            DB::table('retention_policy_setups')->where($key)->update($values);

            return;
        }

        DB::table('retention_policy_setups')->insert([...$key, ...$values, 'id' => strtolower((string) Str::ulid()), 'created_at' => now()]);
    }

    /** @param  array{enabled: bool, days: int, customized: bool, optional: bool}  $setting */
    private function applyToTenant(RetentionPolicy $policy, string $tenantId, array $setting): int
    {
        if (! $setting['enabled']) {
            return 0;
        }

        $cutoff = now()->subDays($setting['days']);
        $deleted = 0;

        try {
            $this->purge($policy, $tenantId, $cutoff, $deleted);
        } catch (Throwable $exception) {
            Log::warning('retention.apply.failed', ['policy' => $policy->code, 'tenant_id' => $tenantId, 'exception' => $exception]);
            $this->record($tenantId, $policy, $deleted, $cutoff, 'failed', 'Penghapusan gagal. Coba lagi nanti; bila terulang, hubungi administrator.');

            return $deleted;
        }

        if ($deleted > 0) {
            $this->record($tenantId, $policy, $deleted, $cutoff, 'success', null);
        }

        return $deleted;
    }

    /** @param  int  $deleted  Diisi selama berjalan, supaya kegagalan di tengah tetap melaporkan baris yang sudah terhapus. */
    private function purge(RetentionPolicy $policy, string $tenantId, DateTimeInterface $cutoff, int &$deleted): void
    {
        do {
            $removed = $policy->fileColumn === null
                ? DB::transaction(fn (): int => $this->deleteBatch($policy, $tenantId, $cutoff))
                : $this->deleteBatchWithFiles($policy, $tenantId, $cutoff);
            $deleted += $removed;
        } while ($removed > 0);
    }

    private function deleteBatch(RetentionPolicy $policy, string $tenantId, DateTimeInterface $cutoff): int
    {
        // `ctid` menunjuk baris fisik, jadi kelompok bisa dihapus tanpa mengandalkan kunci tabel yang berbeda-beda.
        $batch = $this->expired($policy, $tenantId, $cutoff)->select('ctid')->limit(self::BATCH);

        return DB::table($policy->table)->whereIn('ctid', $batch)->delete();
    }

    private function deleteBatchWithFiles(RetentionPolicy $policy, string $tenantId, DateTimeInterface $cutoff): int
    {
        // Disk dibuka lebih dulu: disk yang salah setel menggagalkan kelompok ini sebelum ada baris yang hilang.
        $disk = Storage::disk((string) config('reporting.disk'));
        $file = (string) $policy->fileColumn;

        $rows = $this->expired($policy, $tenantId, $cutoff)->limit(self::FILE_BATCH)->get(['id', $file]);
        if ($rows->isEmpty()) {
            return 0;
        }

        DB::table($policy->table)->where('tenant_id', $tenantId)->whereIn('id', $rows->pluck('id')->all())->delete();
        foreach ($rows as $row) {
            if ($row->{$file}) {
                $disk->delete($row->{$file});
            }
        }

        return $rows->count();
    }

    private function expired(RetentionPolicy $policy, string $tenantId, DateTimeInterface $cutoff): Builder
    {
        $query = DB::table($policy->table)->where($policy->dateColumn, '<', $cutoff);

        if ($policy->tenantVia === 'sequence_id') {
            $query->whereIn('sequence_id', fn (Builder $sequences) => $sequences->select('id')->from('tenant_number_sequences')->where('tenant_id', $tenantId));
        } else {
            $query->where('tenant_id', $tenantId);
        }

        foreach ($policy->filters as $filter) {
            if ($filter['exclude'] ?? false) {
                $query->whereNotIn($filter['column'], $filter['values']);
            } else {
                $query->whereIn($filter['column'], $filter['values']);
            }
        }

        return $query;
    }

    private function record(string $tenantId, RetentionPolicy $policy, int $deleted, DateTimeInterface $cutoff, string $status, ?string $message): void
    {
        DB::table('retention_policy_log_entries')->insert([
            'id' => strtolower((string) Str::ulid()),
            'tenant_id' => $tenantId,
            'policy_code' => $policy->code,
            'deleted_count' => $deleted,
            'cutoff_at' => $cutoff,
            'status' => $status,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
