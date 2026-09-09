<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil pemeriksaan checklist pada satu baris pekerjaan.
 *
 * Disalin dari template, bukan dirujuk: template boleh berubah bulan depan tanpa mengubah
 * arti pemeriksaan yang sudah dikerjakan. `sumber` dan `sumber_id` menyimpan jejak asal baris
 * setelah disalin.
 *
 * `tidak_berlaku` memisahkan "tidak berlaku untuk aset ini" dari "belum diperiksa"; tanpa
 * penanda itu, gate baris wajib akan macet di lapangan pada pemeriksaan yang memang tidak
 * relevan. `line_number` desimal supaya langkah 1.5 dapat disisipkan tanpa menomori ulang.
 */
class PemeliharaanAsetChecklist extends Model
{
    use HasUlids;
    use MilikTenant;

    protected $table = 'aset_tr_pemeliharaan_aset_checklist';

    protected $fillable = [
        'tenant_id', 'pemeliharaan_aset_detail_id', 'line_number', 'nama', 'tipe', 'satuan',
        'wajib', 'instruksi', 'sumber', 'sumber_id', 'min_value', 'max_value',
        'nilai', 'result_code', 'tidak_berlaku', 'diperiksa', 'diperiksa_oleh_user_id',
        'diperiksa_pada', 'catatan_teknisi',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'decimal:1',
            'wajib' => 'boolean',
            'tidak_berlaku' => 'boolean',
            'diperiksa' => 'boolean',
            'diperiksa_pada' => 'datetime',
            'min_value' => 'decimal:6',
            'max_value' => 'decimal:6',
        ];
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(PemeliharaanAsetDetail::class, 'pemeliharaan_aset_detail_id');
    }
}
