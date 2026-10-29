<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\master\AssetTypeConditionController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\AssetTypeCounterController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\GroupBukuPenyusutanController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\JenisAsetAtributController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\JenisAsetModelController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\MaintenanceJobTypeVariantController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\MaintenancePlanDetailController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\MaintenanceSetupLinkController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\ModelAsetController;
use Modules\Apperp\ManagementAset\Http\Controllers\master\TipeAtributNilaiController;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeVariant;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;
use Modules\Apperp\ManagementAset\Models\MasterData;

final class MasterDraftDetails
{
    private const SECTIONS = [
        'pabrikan-aset' => ['models'],
        'jenis-aset' => ['attributes', 'models', 'conditions', 'counters', 'job_types'],
        'group-aset' => ['books'],
        'tipe-atribut' => ['values'],
        'maintenance-job-types' => ['variants', 'asset_types'],
        'maintenance-checklist-variables' => ['values'],
        'maintenance-checklist-templates' => ['lines'],
        'rencana-pemeliharaan' => ['lines', 'targets'],
    ];

    public static function supports(string $resource): bool
    {
        return isset(self::SECTIONS[$resource]);
    }

    /** @return array<string, list<string>> */
    public static function rules(string $resource): array
    {
        $sections = self::SECTIONS[$resource] ?? [];

        return ['details' => $sections === [] ? ['prohibited'] : ['sometimes', 'array:'.implode(',', $sections)], 'details.*' => ['array']];
    }

    /** @param array<string, mixed> $details */
    public function lockBeforeOwner(Request $request, string $resource, string $id, array $details): void
    {
        if ($resource === 'jenis-aset' && isset($details['job_types'])) {
            try {
                app(MaintenanceSetupLinkController::class)->lockJenisAsetJobTypes($request, $id, $details['job_types']);
            } catch (ValidationException $failure) {
                $this->prefix($failure, 'details.job_types');
            }
        }
    }

    public function save(Request $request, AssetNumberSequenceIssuer $numbers, string $resource, MasterData $record, mixed $input, bool $updating = false): void
    {
        $allowed = self::SECTIONS[$resource] ?? [];
        if ($allowed === [] && $input !== []) {
            throw ValidationException::withMessages(['details' => 'Data ini tidak memiliki rincian yang dapat ditambahkan.']);
        }
        $details = validator(['details' => $input], [
            'details' => ['array'.($allowed === [] ? '' : ':'.implode(',', $allowed))],
            'details.*' => ['array'],
        ])->validate()['details'];
        foreach ($details as $section => $payload) {
            try {
                if (($resource === 'pabrikan-aset' && $section === 'models') || ($resource === 'maintenance-job-types' && $section === 'variants')) {
                    $this->saveChildren($request, $numbers, $resource, $section, $record, $payload, $updating);

                    continue;
                }
                $id = (string) $record->getKey();
                $version = (int) $record->refresh()->version;
                $this->replace($request, $resource, $section, $id, $payload, $version);
            } catch (ValidationException $failure) {
                $this->prefix($failure, 'details.'.$section);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function saveChildren(Request $request, AssetNumberSequenceIssuer $numbers, string $resource, string $section, MasterData $record, array $payload, bool $updating): void
    {
        validator($payload, [
            'rows' => ['present', 'array', 'list', 'max:100'],
            'rows.*' => ['array'],
            'rows.*.id' => ['sometimes', 'ulid', 'distinct'],
            'rows.*.version' => ['required_with:rows.*.id', 'integer', 'min:1'],
            'rows.*.creation_key' => ['sometimes', 'string', 'max:133', 'regex:/^[A-Za-z0-9._:-]+$/', 'distinct'],
            'rows.*.kode' => ['sometimes', 'nullable', 'string', 'distinct'],
            'deleted' => ['sometimes', 'array', 'list', 'max:100'],
            'deleted.*.id' => ['required', 'ulid', 'distinct'],
            'deleted.*.version' => ['required', 'integer', 'min:1'],
        ])->validate();
        $manufacturer = $resource === 'pabrikan-aset';
        $controller = $manufacturer ? app(ModelAsetController::class) : app(MaintenanceJobTypeVariantController::class);
        $model = $manufacturer ? ModelAset::class : MaintenanceJobTypeVariant::class;
        $ownerField = $manufacturer ? 'pabrikan_aset_id' : 'maintenance_job_type_id';
        $ownerId = (string) $record->getKey();
        $deletedIds = array_column($payload['deleted'] ?? [], 'id');
        foreach (['rows' => $payload['rows'], 'deleted' => $payload['deleted'] ?? []] as $operation => $rows) {
            foreach ($rows as $index => $row) {
                try {
                    if (isset($row['id'])) {
                        if (! $updating || ! $model::query()->where('tenant_id', $record->tenant_id)->where($ownerField, $ownerId)->whereKey($row['id'])->lockForUpdate()->exists()) {
                            throw ValidationException::withMessages(['id' => 'Baris ini bukan rincian data yang sedang diubah.']);
                        }
                        if ($operation === 'rows' && in_array($row['id'], $deletedIds, true)) {
                            throw ValidationException::withMessages(['id' => 'Baris yang dihapus tidak dapat diubah sekaligus.']);
                        }
                        $response = $operation === 'deleted'
                            ? $controller->destroyPayload($request, $row['id'], (int) $row['version'])
                            : $controller->updatePayload($request, $row['id'], [...$row, $ownerField => $ownerId], (int) $row['version']);
                    } else {
                        if ($updating && ! isset($row['creation_key'])) {
                            throw ValidationException::withMessages(['creation_key' => 'Kunci baris baru wajib dikirim.']);
                        }
                        $key = $row['creation_key'] ?? 'draft:'.hash('sha256', $ownerId.'|'.$section.'|'.$index);
                        $response = $controller->storePayload($request, $numbers, [...$row, $ownerField => $ownerId], $key);
                    }
                    if ($response->getStatusCode() >= 400) {
                        throw new HttpResponseException($response);
                    }
                } catch (ValidationException $failure) {
                    $this->prefix($failure, $operation.'.'.$index);
                }
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function replace(Request $request, string $resource, string $section, string $id, array $payload, int $version): JsonResponse
    {
        return match ($resource.'.'.$section) {
            'jenis-aset.attributes' => app(JenisAsetAtributController::class)->replacePayload($request, $id, $payload, $version),
            'jenis-aset.models' => app(JenisAsetModelController::class)->replacePayload($request, $id, $payload, $version),
            'jenis-aset.conditions' => app(AssetTypeConditionController::class)->replacePayload($request, $id, $payload, $version),
            'jenis-aset.counters' => app(AssetTypeCounterController::class)->replacePayload($request, $id, $payload, $version),
            'jenis-aset.job_types' => app(MaintenanceSetupLinkController::class)->replaceJenisAsetAsetTypesPayload($request, $id, $payload, $version),
            'group-aset.books' => app(GroupBukuPenyusutanController::class)->replacePayload($request, $id, $payload, $version),
            'tipe-atribut.values' => app(TipeAtributNilaiController::class)->replacePayload($request, $id, $payload, $version),
            'maintenance-job-types.asset_types' => app(MaintenanceSetupLinkController::class)->replaceJobTypeAsetTypesPayload($request, $id, $payload, $version),
            'maintenance-checklist-variables.values' => app(MaintenanceSetupLinkController::class)->replaceVariableValuesPayload($request, $id, $payload, $version),
            'maintenance-checklist-templates.lines' => app(MaintenanceSetupLinkController::class)->replaceTemplateLinesPayload($request, $id, $payload, $version),
            'rencana-pemeliharaan.lines' => app(MaintenancePlanDetailController::class)->replaceLinesPayload($request, $id, $payload, $version),
            'rencana-pemeliharaan.targets' => app(MaintenancePlanDetailController::class)->replaceTargetsPayload($request, $id, $payload, $version),
            default => throw ValidationException::withMessages(['details' => 'Rincian yang dipilih tidak tersedia untuk data ini.']),
        };
    }

    private function prefix(ValidationException $failure, string $prefix): never
    {
        $errors = [];
        foreach ($failure->errors() as $field => $messages) {
            $errors[$prefix.'.'.$field] = $messages;
        }
        throw ValidationException::withMessages($errors);
    }
}
