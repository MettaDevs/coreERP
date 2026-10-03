<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Header rencana pengadaan aset satu tahun anggaran.
 *
 * `version` adalah penghitung kunci optimistik yang dinaikkan setiap penyuntingan, bukan
 * nomor revisi dokumen yang dilihat pengguna. `total_estimated_value` adalah jumlah baris
 * yang dihitung ulang saat detail berubah, bukan angka yang diketik; ia di-cast `decimal:2`,
 * jadi Eloquent memulangkannya sebagai string, bukan float.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $planning_org_unit_id
 * @property Carbon $planned_on
 * @property int $planning_year
 * @property string $planning_type
 * @property ?string $funding_source
 * @property ?string $responsible_user_id
 * @property string $total_estimated_value
 * @property string $status
 * @property ?string $description
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class PerencanaanAset extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'responsible_user_id' => DataClass::EndUserPseudonymousIdentifiers,
    ];

    /**
     * Nama tampilan kolom untuk filter tambahan laporan pengadaan aset (K-30).
     *
     * @var array<string, string>
     */
    public const FIELD_CAPTIONS = [
        'kode' => 'Nomor rencana',
        'planning_org_unit_id' => 'Unit organisasi',
        'planned_on' => 'Tanggal rencana',
        'planning_year' => 'Tahun anggaran',
        'planning_type' => 'Jenis perencanaan',
        'funding_source' => 'Sumber dana',
        'responsible_user_id' => 'Penanggung jawab',
        'total_estimated_value' => 'Nilai rencana',
        'status' => 'Status',
        'description' => 'Keterangan',
    ];

    /** @var array<string, array<string, string>> */
    public const FIELD_OPTIONS = [
        'planning_type' => ['regular' => 'Reguler', 'additional' => 'Tambahan'],
        'status' => ['draft' => 'Draf'],
    ];

    /** @var array<string, string> Resource pemilih untuk kolom rujukan. */
    public const FIELD_LOOKUPS = [
        'planning_org_unit_id' => 'reference-data/unit-kerja',
        'responsible_user_id' => 'reference-data/anggota',
    ];

    /** @var array<string, string> Kolom yang sengaja tidak ditawarkan sebagai filter, dengan alasannya. */
    public const FIELD_HIDDEN = [
        'legal_entity_id' => 'Badan hukum dipilih lewat workspace, bukan filter laporan.',
    ];

    protected $table = 'aset_tr_perencanaan_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'planning_org_unit_id',
        'planned_on', 'planning_year', 'planning_type', 'funding_source', 'responsible_user_id',
        'total_estimated_value', 'status', 'description', 'version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'planned_on' => 'date',
            'planning_year' => 'integer',
            'total_estimated_value' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<PerencanaanAsetDetail, $this> */
    public function details(): HasMany
    {
        return $this->hasMany(PerencanaanAsetDetail::class, 'planning_id');
    }
}
