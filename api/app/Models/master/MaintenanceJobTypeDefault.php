<?php

namespace App\Models\master;

use App\Models\MasterData;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceJobTypeDefault extends MasterData
{
    protected $table = 'm_maintenance_job_type_default';

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

    public function maintenanceJobType(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJobType::class, 'maintenance_job_type_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJobTypeVariant::class, 'variant_id');
    }

    public function checklistTemplate(): BelongsTo
    {
        return $this->belongsTo(MaintenanceChecklistTemplate::class, 'checklist_template_id');
    }

    public function functionalLocation(): BelongsTo
    {
        return $this->belongsTo(LokasiAset::class, 'functional_location_id');
    }

    public function jenisAset(): BelongsTo
    {
        return $this->belongsTo(JenisAset::class, 'jenis_aset_id');
    }

    public function pabrikanAset(): BelongsTo
    {
        return $this->belongsTo(PabrikanAset::class, 'pabrikan_aset_id');
    }

    public function modelAset(): BelongsTo
    {
        return $this->belongsTo(ModelAset::class, 'model_aset_id');
    }
}
