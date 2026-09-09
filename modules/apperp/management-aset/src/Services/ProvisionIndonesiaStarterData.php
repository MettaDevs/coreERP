<?php

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\GroupBukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistTemplate;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistTemplateLine;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistVariable;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistVariableValue;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobType;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeDefault;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeVariant;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;
use Modules\Apperp\ManagementAset\Models\master\PabrikanAset;
use Modules\Apperp\ManagementAset\Models\master\ProfilPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\SebabKerusakan;
use Modules\Apperp\ManagementAset\Models\master\TindakanPerbaikan;
use Modules\Apperp\ManagementAset\Models\master\TingkatLayanan;
use Modules\Apperp\ManagementAset\Models\master\TipeLokasiAset;
use Modules\Apperp\ManagementAset\Models\master\TipeWorkOrder;
use Modules\Apperp\ManagementAset\Models\master\Trade;
use Modules\Apperp\ManagementAset\Models\master\ValidasiStatusWorkOrder;
use Modules\Apperp\ManagementAset\Support\WorkOrderValidation;

final class ProvisionIndonesiaStarterData
{
    public function __construct(private readonly PenerbitNomorAset $numbers) {}

    /**
     * Menyediakan template starter untuk satu tenant. Semua key berasal dari
     * konfigurasi berversi; data yang sudah ada tidak ditimpa.
     *
     * @return array{template_key: string, fiscal_classifications: int, profiles: int, books: int, mappings: int, location_types: int, conditions: int, maintenance_job_types: int, maintenance_variants: int, maintenance_variables: int, maintenance_templates: int, maintenance_defaults: int, work_order_validations: int}
     */
    public function forTenant(string $tenantId, ?string $templateKey = null): array
    {
        if (! Str::isUlid($tenantId)) {
            throw new InvalidArgumentException('Tenant tidak valid.');
        }

        $template = config('modules.management-aset.indonesia_starter');
        $selectedTemplateKey = $templateKey ?? (is_array($template) ? ($template['template_key'] ?? null) : null);
        if (! is_array($template) || $selectedTemplateKey !== ($template['template_key'] ?? null)) {
            throw new LogicException('Template starter Indonesia tidak tersedia.');
        }

        $classifications = $this->seedClassifications($tenantId, $template['fiscal_classifications']);
        $profiles = $this->seedProfiles($tenantId, $template, $classifications);
        $books = $this->seedBooks($tenantId, $template);
        $mappings = $this->seedDefaultBookMappings($tenantId, $classifications, $profiles, $books);
        $locationTypes = $this->seedOptionalMasters(
            $tenantId,
            TipeLokasiAset::class,
            'management-aset.tipe-lokasi-aset',
            'tipe-lokasi-aset',
            $template['location_types'],
        );
        $conditions = $this->seedOptionalMasters(
            $tenantId,
            KondisiAset::class,
            'management-aset.kondisi-aset',
            'kondisi-aset',
            $template['conditions'],
        );
        $this->seedManufacturerModels($tenantId, $template['manufacturer_models'] ?? []);
        $workOrder = $this->seedWorkOrderSetup($tenantId, $template['maintenance'] ?? []);
        $maintenance = $this->seedMaintenance($tenantId, $template['maintenance'] ?? []);

        return [
            'template_key' => $template['template_key'],
            'fiscal_classifications' => count($classifications),
            'profiles' => count($profiles),
            'books' => count($books),
            'mappings' => $mappings,
            'location_types' => $locationTypes,
            'conditions' => $conditions,
            ...$workOrder,
            ...$maintenance,
        ];
    }

    /** Menambahkan hanya setup maintenance ke tenant yang sudah berjalan. */
    public function maintenanceForTenant(string $tenantId, ?string $templateKey = null): array
    {
        if (! Str::isUlid($tenantId)) {
            throw new InvalidArgumentException('Tenant tidak valid.');
        }
        $maintenance = config('modules.management-aset.indonesia_starter.maintenance');
        $selectedTemplateKey = $templateKey ?? ($maintenance['template_key'] ?? null);
        if (! is_array($maintenance) || $selectedTemplateKey !== ($maintenance['template_key'] ?? null)) {
            throw new LogicException('Template maintenance Indonesia tidak tersedia.');
        }

        return [
            'template_key' => $maintenance['template_key'],
            ...$this->seedWorkOrderSetup($tenantId, $maintenance),
            ...$this->seedMaintenance($tenantId, $maintenance),
        ];
    }

    /** @param array<string, mixed> $template @return array<string, int> */
    private function seedWorkOrderSetup(string $tenantId, array $template): array
    {
        $created = [
            'work_order_types' => 0,
            'service_levels' => 0,
            'trades' => 0,
            'fault_causes' => 0,
            'repair_actions' => 0,
        ];

        foreach ($template['work_order_types'] ?? [] as $item) {
            $this->ensureNumberedMaster(
                $tenantId,
                TipeWorkOrder::class,
                'management-aset.tipe-work-order',
                'tipe-work-order:starter:'.$item['template_key'],
                [
                    'nama' => $item['name'],
                    'keterangan' => $item['description'] ?? null,
                    'aktif' => true,
                    'satu_pekerja' => (bool) ($item['one_worker'] ?? false),
                ],
                $created['work_order_types'],
            );
        }

        foreach ($template['service_levels'] ?? [] as $item) {
            $this->ensureNumberedMaster(
                $tenantId,
                TingkatLayanan::class,
                'management-aset.tingkat-layanan',
                'tingkat-layanan:starter:'.$item['template_key'],
                [
                    'nama' => $item['name'],
                    'keterangan' => $item['description'] ?? null,
                    'aktif' => true,
                    'urutan' => $item['order'],
                ],
                $created['service_levels'],
            );
        }

        foreach ($template['trades'] ?? [] as $item) {
            $this->ensureNumberedMaster(
                $tenantId,
                Trade::class,
                'management-aset.trade',
                'trade:starter:'.$item['template_key'],
                [
                    'nama' => $item['name'],
                    'keterangan' => $item['description'] ?? null,
                    'aktif' => true,
                ],
                $created['trades'],
            );
        }

        foreach ($template['fault_causes'] ?? [] as $item) {
            $this->ensureNumberedMaster(
                $tenantId,
                SebabKerusakan::class,
                'management-aset.sebab-kerusakan',
                'sebab-kerusakan:starter:'.$item['template_key'],
                ['nama' => $item['name'], 'keterangan' => $item['description'] ?? null, 'aktif' => true],
                $created['fault_causes'],
            );
        }

        foreach ($template['repair_actions'] ?? [] as $item) {
            $this->ensureNumberedMaster(
                $tenantId,
                TindakanPerbaikan::class,
                'management-aset.tindakan-perbaikan',
                'tindakan-perbaikan:starter:'.$item['template_key'],
                ['nama' => $item['name'], 'keterangan' => $item['description'] ?? null, 'aktif' => true],
                $created['repair_actions'],
            );
        }

        return $created;
    }

    /** @param array<string, mixed> $template @return array<string, int> */
    private function seedMaintenance(string $tenantId, array $template): array
    {
        $jobTypes = [];
        foreach ($template['job_types'] ?? [] as $item) {
            $jobTypes[$item['template_key']] = $this->ensureNumberedMaster(
                $tenantId,
                MaintenanceJobType::class,
                'management-aset.maintenance-job-types',
                'maintenance-job-types:starter:'.$item['template_key'],
                [
                    'nama' => $item['name'],
                    'keterangan' => $item['description'] ?? null,
                    'aktif' => true,
                    'category_code' => $item['category'],
                    'maintenance_downtime_activities' => false,
                ],
            );
        }

        $variants = [];
        foreach ($jobTypes as $jobTypeKey => $jobTypeId) {
            foreach ($template['variants'] ?? [] as $item) {
                $variants[$jobTypeKey][$item['template_key']] = $this->ensureNumberedMaster(
                    $tenantId,
                    MaintenanceJobTypeVariant::class,
                    'management-aset.maintenance-job-type-variants',
                    'maintenance-job-type-variants:starter:'.$jobTypeKey.':'.$item['template_key'],
                    [
                        'maintenance_job_type_id' => $jobTypeId,
                        'nama' => $item['name'],
                        'keterangan' => null,
                        'aktif' => true,
                    ],
                );
            }
        }

        $variables = [];
        foreach ($template['checklist_variables'] ?? [] as $item) {
            $variableId = $this->ensureNumberedMaster(
                $tenantId,
                MaintenanceChecklistVariable::class,
                'management-aset.maintenance-checklist-variables',
                'maintenance-checklist-variables:starter:'.$item['template_key'],
                ['nama' => $item['name'], 'keterangan' => $item['description'] ?? null, 'aktif' => true],
            );
            $variables[$item['template_key']] = $variableId;
            $this->ensureChecklistValues($tenantId, $variableId, $item['values'] ?? []);
        }

        $templates = [];
        foreach ($template['checklist_templates'] ?? [] as $item) {
            $templateId = $this->ensureNumberedMaster(
                $tenantId,
                MaintenanceChecklistTemplate::class,
                'management-aset.maintenance-checklist-templates',
                'maintenance-checklist-templates:starter:'.$item['template_key'],
                ['nama' => $item['name'], 'keterangan' => $item['description'] ?? null, 'aktif' => true],
            );
            $templates[$item['template_key']] = $templateId;
            $this->ensureChecklistTemplateLines($tenantId, $templateId, $item['lines'] ?? [], $variables);
        }

        $defaults = 0;
        foreach ($template['defaults'] ?? [] as $item) {
            $jobTypeId = $jobTypes[$item['job_type_key']] ?? null;
            $variantId = $jobTypeId ? ($variants[$item['job_type_key']][$item['variant_key']] ?? null) : null;
            if (! $jobTypeId || ! $variantId) {
                throw new LogicException('Default maintenance merujuk job type atau varian yang tidak tersedia.');
            }
            $this->ensureNumberedMaster(
                $tenantId,
                MaintenanceJobTypeDefault::class,
                'management-aset.maintenance-job-type-defaults',
                'maintenance-job-type-defaults:starter:'.$item['template_key'],
                [
                    'maintenance_job_type_id' => $jobTypeId,
                    'variant_id' => $variantId,
                    'checklist_template_id' => isset($item['checklist_template_key'])
                        ? ($templates[$item['checklist_template_key']] ?? null)
                        : null,
                    'nama' => $item['name'],
                    'keterangan' => null,
                    'aktif' => true,
                    'trade' => $item['trade'] ?? null,
                    'hours' => $item['hours'] ?? 0,
                    'items_count' => 0,
                    'expenses_count' => 0,
                    'fees_count' => 0,
                ],
                $defaults,
            );
        }

        return [
            'maintenance_job_types' => count($jobTypes),
            'maintenance_variants' => collect($variants)->map(fn (array $items): int => count($items))->sum(),
            'maintenance_variables' => count($variables),
            'maintenance_templates' => count($templates),
            'maintenance_defaults' => $defaults,
            'work_order_validations' => $this->ensureStatusValidations($tenantId, $template['status_validations'] ?? []),
        ];
    }

    /**
     * Aturan validasi perpindahan status work order.
     *
     * Hanya menyisipkan baris yang belum ada. Baris yang sudah ada tidak ditimpa karena
     * tenant boleh mengubah keaktifan dan keparahannya; seed ulang tidak boleh membatalkan
     * keputusan itu.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function ensureStatusValidations(string $tenantId, array $items): int
    {
        $dibuat = 0;
        foreach ($items as $item) {
            $ada = ValidasiStatusWorkOrder::query()->where([
                'status' => $item['status'], 'aturan' => $item['aturan'],
            ])->exists();
            if ($ada) {
                continue;
            }
            $this->simpan(ValidasiStatusWorkOrder::class, $tenantId, [
                'status' => $item['status'],
                'aturan' => $item['aturan'],
                'aktif' => (bool) ($item['aktif'] ?? true),
                'keparahan' => $item['keparahan'] ?? WorkOrderValidation::ERROR,
            ]);
            $dibuat++;
        }

        return $dibuat;
    }

    /** @param list<array<string, mixed>> $items */
    private function ensureChecklistValues(string $tenantId, string $variableId, array $items): void
    {
        foreach ($items as $item) {
            $exists = MaintenanceChecklistVariableValue::query()->where([
                'variable_id' => $variableId, 'line_number' => $item['line_number'],
            ])->exists();
            if ($exists) {
                continue;
            }
            $this->simpan(MaintenanceChecklistVariableValue::class, $tenantId, [
                'variable_id' => $variableId,
                'line_number' => $item['line_number'], 'value' => $item['value'], 'result_code' => $item['result_code'],
            ]);
        }
    }

    /** @param list<array<string, mixed>> $items @param array<string, string> $variables */
    private function ensureChecklistTemplateLines(string $tenantId, string $templateId, array $items, array $variables = []): void
    {
        foreach ($items as $item) {
            $exists = MaintenanceChecklistTemplateLine::query()->where([
                'template_id' => $templateId, 'line_number' => $item['line_number'],
            ])->exists();
            if ($exists) {
                continue;
            }
            $this->simpan(MaintenanceChecklistTemplateLine::class, $tenantId, [
                'template_id' => $templateId,
                'line_number' => $item['line_number'], 'type' => $item['type'],
                'variable_id' => isset($item['variable_key']) ? ($variables[$item['variable_key']] ?? null) : null,
                'nested_template_id' => null, 'unit' => $item['unit'] ?? null, 'nama' => $item['name'],
                'instruksi' => $item['instructions'] ?? null,
                'wajib' => (bool) ($item['mandatory'] ?? false),
            ]);
        }
    }

    /** @param list<array<string, mixed>> $items @return array<string, string> */
    private function seedClassifications(string $tenantId, array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $values = [
                'jurisdiction' => $item['jurisdiction'],
                'label' => $item['label'],
                'regulation_reference' => $item['regulation_reference'],
                'effective_from' => $item['effective_from'],
                'effective_to' => $item['effective_to'],
                'useful_life_years' => $item['useful_life_years'],
                'straight_line_rate_percent' => $item['straight_line_rate_percent'],
                'reducing_balance_rate_percent' => $item['reducing_balance_rate_percent'],
                'allow_reducing_balance' => $item['allow_reducing_balance'],
                'depreciable' => $item['depreciable'],
                'aktif' => $item['aktif'],
            ];
            $ids[$item['template_key']] = $this->ensureReference($tenantId, $item['template_key'], $values);
        }

        return $ids;
    }

    /** @param array<string, mixed> $template @param array<string, string> $classifications @return array<string, string> */
    private function seedProfiles(string $tenantId, array $template, array $classifications): array
    {
        $ids = [];
        foreach ($template['profiles'] as $item) {
            $classification = collect($template['fiscal_classifications'])
                ->firstWhere('template_key', $item['classification_key']);
            if (! is_array($classification) || ! isset($classifications[$item['classification_key']])) {
                throw new LogicException('Profil starter merujuk klasifikasi fiskal yang tidak tersedia.');
            }

            $rate = $item['method'] === 'reducing_balance'
                ? $classification['reducing_balance_rate_percent']
                : $classification['straight_line_rate_percent'];
            if ($rate === null || $classification['useful_life_years'] === null) {
                throw new LogicException('Profil starter tidak dapat dibuat untuk klasifikasi tanpa masa manfaat.');
            }

            $values = [
                'nama' => $classification['label'].' - '.$item['method_label'],
                'keterangan' => null,
                'effective_from' => $classification['effective_from'],
                'effective_to' => $classification['effective_to'],
                'aktif' => true,
                'method' => $item['method'],
                'frequency' => 'monthly',
                'year_basis' => 'fiscal',
                'convention' => 'full_month',
                'useful_life_periods' => ((int) $classification['useful_life_years']) * 12,
                'rate_percent' => $rate,
                'manual_schedule' => null,
            ];
            $ids[$item['template_key']] = $this->ensureNumberedMaster(
                $tenantId,
                ProfilPenyusutan::class,
                'management-aset.profil-penyusutan',
                'profil-penyusutan:starter:'.$item['template_key'],
                $values,
            );
        }

        return $ids;
    }

    /** @param array<string, mixed> $template @return array<string, string> */
    private function seedBooks(string $tenantId, array $template): array
    {
        $ids = [];
        foreach ($template['books'] as $item) {
            $values = [
                'nama' => $item['name'],
                'keterangan' => $item['description'],
                'aktif' => true,
                'posting_layer' => $item['posting_layer'],
                // Finance/backoffice belum memiliki kontrak posting untuk app ini.
                'export_to_backoffice' => false,
                'round_off_depreciation' => 0,
                // Tidak ada buku starter yang diberi umur manfaat universal. Buku fiskal
                // mendapat profil berversi lewat matriks; buku komersial tetap menunggu
                // kebijakan tenant/legal entity atau pengecualian sektornya sendiri.
                'depreciation_profile_id' => null,
                'alternative_profile_id' => null,
            ];
            $ids[$item['template_key']] = $this->ensureNumberedMaster(
                $tenantId,
                BukuPenyusutan::class,
                'management-aset.buku-penyusutan',
                'buku-penyusutan:starter:'.$item['template_key'],
                $values,
            );
        }

        return $ids;
    }

    /**
     * Memasang satu baris matriks per group agar aset dapat langsung ditempatkan.
     *
     * Buku yang dipasang adalah buku komersial, bukan fiskal. Tenant yang belum meminta
     * pembukuan pajak tidak seharusnya dipaksa menghitungnya, dan buku pertama sebuah
     * tenant lebih sering dipakai sebagai dasar pelaporan keuangan daripada SPT.
     *
     * Masa manfaatnya tetap memakai profil PMK 72 sebagai nilai awal yang wajar; ia
     * dapat diganti tenant tanpa menyentuh matriks. Buku fiskal tetap dibuat sebagai
     * master dan menganggur sampai ada baris matriks yang menunjuknya.
     *
     * @param  array<string, string>  $classifications  @param array<string, string> $profiles @param array<string, string> $books
     */
    private function seedDefaultBookMappings(string $tenantId, array $classifications, array $profiles, array $books): int
    {
        $defaultBookId = $books['id:pmk72-2023:buku:komersial:v1'] ?? null;
        if (! $defaultBookId) {
            return 0;
        }

        $straightLineProfiles = collect(config('modules.management-aset.indonesia_starter.profiles', []))
            ->filter(fn (array $profile): bool => $profile['method'] === 'straight_line')
            ->keyBy('classification_key');
        $created = 0;
        GroupAset::query()
            ->whereNotNull('kelompok_harta_fiskal_id')
            ->toBase()
            ->get(['id', 'kelompok_harta_fiskal_id'])
            ->each(function (object $group) use ($tenantId, $classifications, $profiles, $straightLineProfiles, $defaultBookId, &$created): void {
                $classificationKey = array_search($group->kelompok_harta_fiskal_id, $classifications, true);
                $profileKey = $classificationKey === false ? null : ($straightLineProfiles->get($classificationKey)['template_key'] ?? null);
                $profileId = $profileKey ? ($profiles[$profileKey] ?? null) : null;
                if (! $profileId) {
                    return;
                }

                // `withTrashed`: baris matriks yang sengaja diarsipkan tidak boleh dibuat
                // ulang, dan tanpa itu penyisipannya melanggar kunci uniknya.
                $exists = GroupBukuPenyusutan::withTrashed()
                    ->where(['group_aset_id' => $group->id, 'buku_id' => $defaultBookId])
                    ->exists();
                if ($exists) {
                    return;
                }

                $this->simpan(GroupBukuPenyusutan::class, $tenantId, [
                    'group_aset_id' => $group->id,
                    'buku_id' => $defaultBookId,
                    'depreciation_profile_id' => $profileId,
                    'alternative_profile_id' => null,
                    'useful_life_periods' => null,
                    'convention' => 'full_month',
                    'depreciate' => true,
                    'round_off_depreciation' => null,
                ]);
                $created++;
            });

        return $created;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $items
     */
    private function seedOptionalMasters(string $tenantId, string $model, string $reference, string $resource, array $items): int
    {
        $created = 0;
        foreach ($items as $item) {
            $this->ensureNumberedMaster(
                $tenantId,
                $model,
                $reference,
                $resource.':starter:'.$item['template_key'],
                [
                    'nama' => $item['code_label'],
                    'keterangan' => $item['description'],
                    'aktif' => true,
                ],
                $created,
            );
        }

        return $created;
    }

    /** @param array{template_key?: string, manufacturers?: list<array<string, mixed>>} $catalog */
    private function seedManufacturerModels(string $tenantId, array $catalog): void
    {
        $catalogKey = $catalog['template_key'] ?? 'id:manufacturer-models:indonesia-asia:v1';

        foreach ($catalog['manufacturers'] ?? [] as $manufacturer) {
            $manufacturerId = $this->ensureNumberedMaster(
                $tenantId,
                PabrikanAset::class,
                'management-aset.pabrikan-aset',
                'pabrikan-aset:starter:'.$catalogKey.':'.$manufacturer['template_key'],
                [
                    'nama' => $manufacturer['name'],
                    'keterangan' => $manufacturer['description'] ?? null,
                    'aktif' => true,
                ],
            );

            foreach ($manufacturer['models'] ?? [] as $model) {
                $this->ensureNumberedMaster(
                    $tenantId,
                    ModelAset::class,
                    'management-aset.model-aset',
                    'model-aset:starter:'.$catalogKey.':'.$manufacturer['template_key'].':'.$model['template_key'],
                    [
                        'pabrikan_aset_id' => $manufacturerId,
                        'jenis_aset_id' => null,
                        'nama' => $model['name'],
                        'keterangan' => $model['description'] ?? null,
                        'model_number' => null,
                        'aktif' => true,
                    ],
                );
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function ensureReference(string $tenantId, string $templateKey, array $values): string
    {
        // `withTrashed` pada seluruh pembacaan di sini: referensi yang sudah diarsipkan
        // justru harus ditemukan, karena barisnya dihidupkan kembali, bukan dibuat ulang.
        $existing = KelompokHartaFiskal::withTrashed()
            ->where('template_key', $templateKey)
            ->toBase()->first();
        if ($existing) {
            $this->assertSame($existing, $values, 'referensi fiskal '.$templateKey);
            if ($existing->deleted_at !== null) {
                KelompokHartaFiskal::withTrashed()->where('id', $existing->id)->update(['deleted_at' => null, 'updated_at' => now()]);
            }

            return $existing->id;
        }

        try {
            $id = $this->simpan(KelompokHartaFiskal::class, $tenantId, [
                'template_key' => $templateKey,
                ...$values,
            ]);
        } catch (QueryException $exception) {
            $existing = KelompokHartaFiskal::withTrashed()->where('template_key', $templateKey)->toBase()->first();
            if (! $existing) {
                throw $exception;
            }
            $this->assertSame($existing, $values, 'referensi fiskal '.$templateKey);

            return $existing->id;
        }

        return $id;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $values
     */
    private function ensureNumberedMaster(string $tenantId, string $model, string $reference, string $creationKey, array $values, ?int &$created = null): string
    {
        // `withTrashed`: record starter yang sengaja diarsipkan tenant harus tetap dikenali
        // sebagai sudah ada, kalau tidak seed berikutnya membangkitkannya kembali.
        $existing = $model::withTrashed()->where('creation_key', $creationKey)->toBase()->first();
        if ($existing) {
            // Master starter boleh disesuaikan tenant. Key yang sama berarti
            // record sudah pernah dimaterialisasi; jangan menghapus perubahan
            // pengguna atau menghidupkan kembali record yang sengaja diarsipkan.
            return $existing->id;
        }

        $code = $this->numbers->issue($reference, $tenantId, $creationKey);
        try {
            $id = $this->simpan($model, $tenantId, [
                'creation_key' => $creationKey,
                'kode' => $code,
                ...$values,
            ]);
            if ($created !== null) {
                $created++;
            }
        } catch (QueryException $exception) {
            $existing = $model::withTrashed()->where('creation_key', $creationKey)->toBase()->first();
            if (! $existing) {
                throw $exception;
            }

            return $existing->id;
        }

        return $id;
    }

    /**
     * Menyimpan satu baris master lewat modelnya.
     *
     * `tenant_id` tetap ditulis walau `MilikTenant` sanggup mengisinya sendiri. Penyediaan
     * data awal menerima tenant sebagai argumen, bukan dari permintaan HTTP, jadi
     * menuliskannya membuat trait itu membatalkan penyimpanan ketika tenant yang diminta
     * berbeda dari tenant aktif — persis keadaan yang paling mahal bila lolos diam-diam.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $values
     */
    private function simpan(string $model, string $tenantId, array $values): string
    {
        $id = (string) Str::ulid();
        (new $model)->forceFill([
            'id' => $id,
            'tenant_id' => $tenantId,
            ...$values,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        return $id;
    }

    /** @param array<string, mixed> $expected */
    private function assertSame(object $existing, array $expected, string $label): void
    {
        foreach ($expected as $column => $value) {
            $actual = $existing->{$column} ?? null;
            if (is_bool($value) ? (bool) $actual !== $value : (is_numeric($value) && is_numeric($actual) ? (float) $actual !== (float) $value : $actual !== $value)) {
                throw new LogicException('Data starter '.$label.' berbeda dari template yang sudah tersimpan. Buat template versi baru.');
            }
        }
    }
}
