<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use App\Support\Modules\Contracts\MilikTenant;
use App\Support\Modules\Contracts\TableFields;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Header dokumen monitoring aset: satu pemeriksaan fisik di satu lokasi pada satu tanggal.
 *
 * Lokasi berada di header karena yang diperiksa adalah isi satu tempat. Unit organisasi dan
 * penanggung jawab opsional; keduanya menyempitkan aset yang diisi otomatis dan menjadi pemilik
 * dokumen menurut kebijakan organisasi.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property ?string $responsible_org_unit_id
 * @property ?string $penanggung_jawab_user_id
 * @property string $lokasi_aset_id
 * @property Carbon $tanggal
 * @property ?string $keterangan
 * @property string $status
 * @property ?Carbon $diselesaikan_pada
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetMonitoring extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'penanggung_jawab_user_id' => DataClass::EndUserPseudonymousIdentifiers,
    ];

    /**
     * Nama kolom untuk filter tambahan laporan (K-30), padanan Caption field tabel di BC; tipe kolom dibaca
     * dari database. Lihat {@see TableFields}.
     *
     * @var array<string, string>
     */
    public const FIELD_CAPTIONS = [
        'kode' => 'No. bukti',
        'responsible_org_unit_id' => 'Unit organisasi',
        'penanggung_jawab_user_id' => 'Penanggung jawab',
        'lokasi_aset_id' => 'Lokasi aset',
        'tanggal' => 'Tanggal monitoring',
        'keterangan' => 'Keterangan',
        'diselesaikan_pada' => 'Diselesaikan pada',
    ];

    /** @var array<string, string> Resource pemilih untuk kolom rujukan. */
    public const FIELD_LOOKUPS = [
        'responsible_org_unit_id' => 'reference-data/unit-kerja',
        'penanggung_jawab_user_id' => 'reference-data/anggota',
        'lokasi_aset_id' => 'lokasi-aset',
    ];

    /** @var array<string, string> Kolom yang sengaja tidak ditawarkan sebagai filter, dengan alasannya. */
    public const FIELD_HIDDEN = [
        'legal_entity_id' => 'Badan hukum dipilih lewat workspace, bukan filter laporan.',
        'status' => 'Laporan monitoring hanya membaca pemeriksaan yang sudah selesai.',
    ];

    protected $table = 'aset_tr_monitoring_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'responsible_org_unit_id',
        'penanggung_jawab_user_id', 'lokasi_aset_id', 'tanggal', 'keterangan', 'status',
        'diselesaikan_pada',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'diselesaikan_pada' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<AssetMonitoringLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AssetMonitoringLine::class, 'monitoring_aset_id');
    }
}
