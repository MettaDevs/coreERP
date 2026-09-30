<?php

declare(strict_types=1);

namespace Modules\Apperp\HumanResources\Services;

use App\Support\Modules\Contracts\AttachmentRecordType;
use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\KonteksPermintaan;
use Illuminate\Database\Eloquent\Builder;
use Modules\Apperp\HumanResources\Models\Worker;

/**
 * Lampiran dokumen pada pekerja (gap 7, fase 1): kontrak dan identitas. Isinya data pribadi, jadi lampirannya
 * diklasifikasi `EndUserIdentifiableInformation`.
 *
 * Hak bacanya sama dengan daftar pekerja: `human-resources.workers.read`, lalu pengguna tanpa akses seluruh
 * organisasi hanya melihat pekerja yang hari ini memegang posisi di unit kerjanya (kebijakan
 * `human-resources.workforce-responsibility`).
 *
 * Module ini belum punya permission ubah pekerja, jadi belum ada yang dapat melampirkan atau mengarsipkan
 * lampiran pekerja sampai permission itu diputuskan.
 */
final class WorkerAttachments implements AttachmentRecordType
{
    private const POLICY_CODE = 'human-resources.workforce-responsibility';

    public function recordType(): string
    {
        return 'hr_workers';
    }

    public function moduleId(): string
    {
        return 'human-resources';
    }

    public function dataClass(): DataClass
    {
        return DataClass::EndUserIdentifiableInformation;
    }

    public function canRead(string $tenantId, string $recordId): bool
    {
        $context = app(KonteksPermintaan::class);
        if (! $context->punyaIzin('human-resources.workers.read')) {
            return false;
        }

        $query = Worker::query()->whereKey($recordId);
        $scope = $context->kebijakanData()[self::POLICY_CODE] ?? null;
        if (! is_array($scope) || ($scope['all'] ?? false) !== true) {
            $units = $this->operatingUnitIds(is_array($scope) ? ($scope['scope_grants'] ?? null) : null);
            $query->whereHas('assignments', function (Builder $assignment) use ($units): void {
                $assignment
                    ->whereDate('valid_from', '<=', today())
                    ->where(fn (Builder $dates) => $dates->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
                    ->whereHas('position', fn (Builder $position) => $position->whereIn('operating_unit_id', $units === [] ? ['__none__'] : $units));
            });
        }

        return $query->exists();
    }

    public function canChange(string $tenantId, string $recordId): bool
    {
        return false;
    }

    public function hasLine(string $tenantId, string $recordId, int $lineNumber): bool
    {
        return false;
    }

    /** @return list<string> */
    private function operatingUnitIds(mixed $grants): array
    {
        $units = [];
        foreach (is_array($grants) ? $grants : [] as $grant) {
            $ids = is_array($grant) ? ($grant['operating_unit_ids'] ?? null) : null;
            foreach (is_array($ids) ? $ids : [] as $id) {
                if (is_string($id)) {
                    $units[$id] = true;
                }
            }
        }

        return array_keys($units);
    }
}
