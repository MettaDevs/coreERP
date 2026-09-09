<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Header work order pemeliharaan aset.
 *
 * Asetnya tidak ada di header melainkan di baris pekerjaan, mengikuti Dynamics 365 F&O,
 * supaya satu perintah kerja dapat mencakup beberapa aset tanpa memecah dokumen.
 *
 * Tiga pasang waktu yang tidak boleh saling menggantikan: yang diharapkan pemohon, yang
 * dijadwalkan perencana, dan yang benar-benar terjadi di lapangan.
 */
class PemeliharaanAset extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'aset_tr_pemeliharaan_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'responsible_org_unit_id',
        'tipe_work_order_id', 'tingkat_layanan_id', 'keterangan', 'penanggung_jawab_user_id',
        'diharapkan_mulai', 'diharapkan_selesai', 'dijadwalkan_mulai', 'dijadwalkan_selesai',
        'aktual_mulai', 'aktual_selesai', 'status', 'version',
    ];

    protected function casts(): array
    {
        return [
            'diharapkan_mulai' => 'datetime',
            'diharapkan_selesai' => 'datetime',
            'dijadwalkan_mulai' => 'datetime',
            'dijadwalkan_selesai' => 'datetime',
            'aktual_mulai' => 'datetime',
            'aktual_selesai' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function details(): HasMany
    {
        return $this->hasMany(PemeliharaanAsetDetail::class, 'pemeliharaan_aset_id');
    }

    public function statusLog(): HasMany
    {
        return $this->hasMany(PemeliharaanAsetStatusLog::class, 'pemeliharaan_aset_id');
    }
}
