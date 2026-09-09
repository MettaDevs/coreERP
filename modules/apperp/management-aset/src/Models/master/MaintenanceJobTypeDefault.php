<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Apperp\ManagementAset\Models\MasterData;

/**
 * Nilai bawaan sebuah jenis pekerjaan maintenance; padanan "Maintenance job type default"
 * di Dynamics 365 F&O. Kolom penunjuk yang nullable adalah penyaring: baris yang kosong
 * berlaku untuk semua nilai kolom itu.
 *
 * Kolom di bawah adalah tambahan atas bentuk dasar master; bentuk dasarnya disebutkan pada
 * `MasterData`. `hours` di-cast `decimal:2`, jadi Eloquent memulangkannya sebagai string
 * dan bukan float. `trade` menyimpan teks, bukan penunjuk ke master `Trade`.
 *
 * @property string $maintenance_job_type_id
 * @property ?string $variant_id
 * @property ?string $trade
 * @property ?string $functional_location_id
 * @property ?string $jenis_aset_id
 * @property ?string $pabrikan_aset_id
 * @property ?string $model_aset_id
 * @property ?string $asset_id
 * @property string $hours
 * @property int $items_count
 * @property int $expenses_count
 * @property int $fees_count
 * @property ?string $checklist_template_id
 */
class MaintenanceJobTypeDefault extends MasterData
{
    protected $table = 'aset_m_maintenance_job_type_default';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'keterangan', 'aktif',
        'maintenance_job_type_id', 'variant_id', 'trade', 'functional_location_id',
        'jenis_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'asset_id', 'hours',
        'items_count', 'expenses_count', 'fees_count', 'checklist_template_id',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'hours' => 'decimal:2',
            'items_count' => 'integer',
            'expenses_count' => 'integer',
            'fees_count' => 'integer',
        ];
    }

    /** @return BelongsTo<MaintenanceJobType, $this> */
    public function maintenanceJobType(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJobType::class, 'maintenance_job_type_id');
    }

    /** @return BelongsTo<MaintenanceJobTypeVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJobTypeVariant::class, 'variant_id');
    }

    /** @return BelongsTo<MaintenanceChecklistTemplate, $this> */
    public function checklistTemplate(): BelongsTo
    {
        return $this->belongsTo(MaintenanceChecklistTemplate::class, 'checklist_template_id');
    }

    /** @return BelongsTo<LokasiAset, $this> */
    public function functionalLocation(): BelongsTo
    {
        return $this->belongsTo(LokasiAset::class, 'functional_location_id');
    }

    /** @return BelongsTo<JenisAset, $this> */
    public function jenisAset(): BelongsTo
    {
        return $this->belongsTo(JenisAset::class, 'jenis_aset_id');
    }

    /** @return BelongsTo<PabrikanAset, $this> */
    public function pabrikanAset(): BelongsTo
    {
        return $this->belongsTo(PabrikanAset::class, 'pabrikan_aset_id');
    }

    /** @return BelongsTo<ModelAset, $this> */
    public function modelAset(): BelongsTo
    {
        return $this->belongsTo(ModelAset::class, 'model_aset_id');
    }
}
