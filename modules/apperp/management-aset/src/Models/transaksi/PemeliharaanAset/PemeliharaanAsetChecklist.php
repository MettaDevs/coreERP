<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 *
 * `line_number`, `min_value`, dan `max_value` di-cast `decimal:n`, jadi Eloquent
 * memulangkannya sebagai string, bukan float.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pemeliharaan_aset_detail_id
 * @property string $line_number
 * @property string $nama
 * @property string $tipe
 * @property ?string $satuan
 * @property bool $wajib
 * @property ?string $instruksi
 * @property ?string $sumber
 * @property ?string $sumber_id
 * @property ?string $min_value
 * @property ?string $max_value
 * @property ?string $nilai
 * @property ?string $result_code
 * @property bool $tidak_berlaku
 * @property bool $diperiksa
 * @property ?string $diperiksa_oleh_user_id
 * @property ?Carbon $diperiksa_pada
 * @property ?string $catatan_teknisi
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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

    /** @return array<string, string> */
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

    /** @return BelongsTo<PemeliharaanAsetDetail, $this> */
    public function detail(): BelongsTo
    {
        return $this->belongsTo(PemeliharaanAsetDetail::class, 'pemeliharaan_aset_detail_id');
    }
}
