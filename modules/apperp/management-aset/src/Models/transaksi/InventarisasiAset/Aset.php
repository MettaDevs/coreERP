<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
class Aset extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

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
