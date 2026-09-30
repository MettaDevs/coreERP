<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use App\Support\Modules\Contracts\MilikTenant;
use App\Support\Modules\Contracts\TableFields;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Support\AssetMonitoringStatus;
use Modules\Apperp\ManagementAset\Support\StatusAset;

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

    /**
     * Nama kolom untuk filter tambahan laporan (K-30), padanan Caption field tabel di BC; tipe kolom dibaca
     * dari database. Lihat {@see TableFields}.
     *
     * @var array<string, string>
     */
    public const FIELD_CAPTIONS = [
        'line_number' => 'No. baris',
        'aset_id' => 'Aset',
        'ada' => 'Ada secara fisik',
        'kondisi_aset_id' => 'Kondisi fisik',
        'keterangan' => 'Keterangan',
        'sistem_lifecycle_state' => 'Status di sistem',
        'sistem_lokasi_id' => 'Lokasi tercatat',
        'sistem_org_unit_id' => 'Unit organisasi tercatat',
        'sistem_custodian_user_id' => 'Penanggung jawab tercatat',
        'nilai_perolehan' => 'Nilai perolehan',
        'akumulasi_penyusutan' => 'Akumulasi penyusutan',
        'nilai_buku' => 'Nilai buku',
        'hasil' => 'Hasil',
    ];

    /** @var array<string, array<string, string>> */
    public const FIELD_OPTIONS = [
        'sistem_lifecycle_state' => StatusAset::LABELS,
        'hasil' => [AssetMonitoringStatus::MATCH => 'Sesuai', AssetMonitoringStatus::MISMATCH => 'Tidak sesuai'],
    ];

    /** @var array<string, string> Resource pemilih untuk kolom rujukan. */
    public const FIELD_LOOKUPS = [
        'aset_id' => 'aset',
        'kondisi_aset_id' => 'kondisi-aset',
        'sistem_lokasi_id' => 'lokasi-aset',
        'sistem_org_unit_id' => 'reference-data/unit-kerja',
        'sistem_custodian_user_id' => 'reference-data/anggota',
    ];

    /** @var array<string, string> Kolom yang sengaja tidak ditawarkan sebagai filter, dengan alasannya. */
    public const FIELD_HIDDEN = [
        'monitoring_aset_id' => 'Kunci dokumen induk; saring lewat bagian Monitoring.',
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
