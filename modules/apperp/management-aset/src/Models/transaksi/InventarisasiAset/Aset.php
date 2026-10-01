<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use App\Platform\Modules\Contracts\TableFields;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Support\StatusAset;

/**
 * Aset tercatat: satu baris per aset, termasuk komponen yang menjadi anak aset lain.
 *
 * `acquisition_value` di-cast `decimal:2`, jadi Eloquent memulangkannya sebagai string,
 * bukan float. Kolom tanggal di-cast `date` dan menjadi `Carbon`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $nama
 * @property string $legal_entity_id
 * @property ?string $responsible_org_unit_id
 * @property string $group_aset_id
 * @property ?string $kelompok_harta_fiskal_id
 * @property string $jenis_aset_id
 * @property ?string $kondisi_aset_id
 * @property ?string $pabrikan_aset_id
 * @property ?string $model_aset_id
 * @property ?string $induk_aset_id
 * @property ?string $lokasi_aset_id
 * @property ?string $penerimaan_aset_id
 * @property ?string $penerimaan_aset_detail_id
 * @property ?string $financial_dimension_org_unit_id
 * @property ?string $serial_number
 * @property ?string $model_number
 * @property Carbon $acquired_on
 * @property ?Carbon $placed_in_service_on
 * @property string $acquisition_value
 * @property string $currency_code
 * @property string $lifecycle_state
 * @property ?string $keterangan
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class Aset extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'nama' => DataClass::CustomerContent,
    ];

    /**
     * Nama kolom untuk filter tambahan laporan (K-30), padanan Caption field tabel di BC; tipe kolom dibaca
     * dari database. Lihat {@see TableFields}.
     *
     * @var array<string, string>
     */
    public const FIELD_CAPTIONS = [
        'kode' => 'Kode aset',
        'nama' => 'Nama aset',
        'group_aset_id' => 'Group aset',
        'kelompok_harta_fiskal_id' => 'Kelompok harta fiskal',
        'jenis_aset_id' => 'Jenis aset',
        'kondisi_aset_id' => 'Kondisi',
        'lokasi_aset_id' => 'Lokasi',
        'pabrikan_aset_id' => 'Pabrikan',
        'model_aset_id' => 'Model',
        'induk_aset_id' => 'Aset induk',
        'responsible_org_unit_id' => 'Unit penanggung jawab',
        'financial_dimension_org_unit_id' => 'Unit dimensi keuangan',
        'serial_number' => 'Nomor seri',
        'model_number' => 'Nomor model',
        'acquired_on' => 'Tanggal perolehan',
        'placed_in_service_on' => 'Tanggal mulai dipakai',
        'acquisition_value' => 'Nilai perolehan',
        'currency_code' => 'Mata uang',
        'lifecycle_state' => 'Status aset',
        'keterangan' => 'Keterangan',
    ];

    /** @var array<string, array<string, string>> */
    public const FIELD_OPTIONS = [
        'lifecycle_state' => StatusAset::LABELS,
    ];

    /** @var array<string, string> Resource pemilih untuk kolom rujukan. */
    public const FIELD_LOOKUPS = [
        'group_aset_id' => 'group-aset',
        'kelompok_harta_fiskal_id' => 'reference-data/kelompok-harta-fiskal',
        'jenis_aset_id' => 'jenis-aset',
        'kondisi_aset_id' => 'kondisi-aset',
        'lokasi_aset_id' => 'lokasi-aset',
        'pabrikan_aset_id' => 'pabrikan-aset',
        'model_aset_id' => 'model-aset',
        'induk_aset_id' => 'aset',
        'responsible_org_unit_id' => 'reference-data/unit-kerja',
        'financial_dimension_org_unit_id' => 'reference-data/unit-kerja',
    ];

    /** @var array<string, string> Kolom yang sengaja tidak ditawarkan sebagai filter, dengan alasannya. */
    public const FIELD_HIDDEN = [
        'legal_entity_id' => 'Badan hukum dipilih lewat workspace, bukan filter laporan.',
        'penerimaan_aset_id' => 'Rujukan dokumen asal; saring dari laporan penerimaan.',
        'penerimaan_aset_detail_id' => 'Rujukan baris dokumen asal; saring dari laporan penerimaan.',
    ];

    protected $table = 'aset_tr_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'nama', 'legal_entity_id', 'responsible_org_unit_id',
        'group_aset_id', 'kelompok_harta_fiskal_id', 'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'model_aset_id',
        'induk_aset_id', 'lokasi_aset_id', 'financial_dimension_org_unit_id',
        'serial_number', 'model_number', 'acquired_on', 'placed_in_service_on',
        'acquisition_value', 'currency_code', 'lifecycle_state', 'keterangan',
        'penerimaan_aset_id', 'penerimaan_aset_detail_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'placed_in_service_on' => 'date',
            'acquisition_value' => 'decimal:2',
        ];
    }
}
