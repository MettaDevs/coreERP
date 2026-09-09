<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Baris pekerjaan work order: satu aset, satu jenis pekerjaan, satu pelaksana.
 *
 * Berbeda dari baris detail transaksi lain di modul ini, baris ini TIDAK boleh diganti dengan
 * pola hapus-lalu-sisip saat dokumen disunting: ia memikul hasil checklist, jam aktual,
 * penugasan, sebab, dan tindakan. Penggantian massal hanya sah selama status masih `draft`.
 *
 * `asset_location_id` disalin saat baris dibuat, bukan dibaca dari aset, supaya riwayat tetap
 * menunjukkan tempat pekerjaan dikerjakan meski asetnya kemudian dipindahkan.
 */
class PemeliharaanAsetDetail extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_pemeliharaan_aset_details';

    protected $fillable = [
        'tenant_id', 'pemeliharaan_aset_id', 'line_number', 'asset_id', 'asset_location_id',
        'maintenance_job_type_id', 'variant_id', 'trade_id', 'ditugaskan_ke_user_id',
        'dijadwalkan_mulai', 'dijadwalkan_selesai', 'estimasi_jam', 'aktual_jam', 'hasil',
        'sebab_kerusakan_id', 'sebab_kerusakan_keterangan',
        'tindakan_perbaikan_id', 'tindakan_perbaikan_keterangan', 'catatan',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'dijadwalkan_mulai' => 'datetime',
            'dijadwalkan_selesai' => 'datetime',
            'estimasi_jam' => 'decimal:2',
            'aktual_jam' => 'decimal:2',
        ];
    }

    public function pemeliharaan(): BelongsTo
    {
        return $this->belongsTo(PemeliharaanAset::class, 'pemeliharaan_aset_id');
    }

    public function checklist(): HasMany
    {
        return $this->hasMany(PemeliharaanAsetChecklist::class, 'pemeliharaan_aset_detail_id');
    }
}
