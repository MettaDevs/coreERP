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

    public function save(Request $request, AssetNumberSequenceIssuer $numbers, string $resource, MasterData $record, mixed $input): void
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
                    $rows = validator($payload, ['rows' => ['present', 'array', 'list', 'max:100'], 'rows.*' => ['array'], 'rows.*.kode' => ['sometimes', 'nullable', 'string', 'distinct']])->validate()['rows'];
                    foreach ($rows as $index => $row) {
                        try {
                            $controller = $resource === 'pabrikan-aset' ? app(ModelAsetController::class) : app(MaintenanceJobTypeVariantController::class);
                            $ownerField = $resource === 'pabrikan-aset' ? 'pabrikan_aset_id' : 'maintenance_job_type_id';
                            $response = $controller->storePayload($request, $numbers,
                                [...$row, $ownerField => (string) $record->getKey()],
                                'draft:'.hash('sha256', $record->getKey().'|'.$section.'|'.$index));
                            if ($response->getStatusCode() >= 400) {
                                throw new HttpResponseException($response);
                            }
                        } catch (ValidationException $failure) {
                            $this->prefix($failure, 'rows.'.$index);
                        }
                    }

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
