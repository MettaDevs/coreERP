<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

/**
 * Satu aset pada satu pemeriksaan fisik, beserta temuan pemeriksanya.
 *
 * `ada`, `kondisi_aset_id`, dan `keterangan` diisi pemeriksa. Kolom `sistem_*`, nilai, dan `hasil`
 * kosong selama draf dan dibekukan saat dokumen diselesaikan; selama draf layar membaca keadaan
 * aset sekarang dan menghitung hasilnya langsung.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $monitoring_aset_id
 * @property int $line_number
 * @property string $aset_id
 * @property ?bool $ada
 * @property ?string $kondisi_aset_id
 * @property ?string $keterangan
 * @property ?string $sistem_lifecycle_state
 * @property ?string $sistem_lokasi_id
 * @property ?string $sistem_org_unit_id
 * @property ?string $sistem_custodian_user_id
 * @property ?string $nilai_perolehan
 * @property ?string $akumulasi_penyusutan
 * @property ?string $nilai_buku
 * @property ?string $hasil
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetMonitoringLine extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'sistem_custodian_user_id' => DataClass::EndUserPseudonymousIdentifiers,
    ];

    protected $table = 'aset_tr_monitoring_aset_details';

    protected $fillable = [
        'tenant_id', 'monitoring_aset_id', 'line_number', 'aset_id', 'ada', 'kondisi_aset_id', 'keterangan',
        'sistem_lifecycle_state', 'sistem_lokasi_id', 'sistem_org_unit_id', 'sistem_custodian_user_id',
        'nilai_perolehan', 'akumulasi_penyusutan', 'nilai_buku', 'hasil',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'ada' => 'boolean',
            'nilai_perolehan' => 'decimal:2',
            'akumulasi_penyusutan' => 'decimal:2',
            'nilai_buku' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<AssetMonitoring, $this> */
    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(AssetMonitoring::class, 'monitoring_aset_id');
    }

    /** @return BelongsTo<Aset, $this> */
    public function aset(): BelongsTo
    {
        return $this->belongsTo(Aset::class, 'aset_id');
    }
}
