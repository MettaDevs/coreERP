<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\Insurance;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Pertanggungan satu aset pada satu polis untuk satu periode; padanan entri *Ins. Coverage Ledger
 * Entry* Business Central.
 *
 * Barisnya riwayat: nilai dan tanggal mulai tidak pernah disunting. Yang dapat diubah hanya tanggal
 * akhirnya, saat pertanggungan diakhiri atau diganti nilai baru.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $polis_asuransi_id
 * @property string $aset_id
 * @property string $nilai_pertanggungan
 * @property Carbon $berlaku_mulai
 * @property ?Carbon $berlaku_sampai
 * @property ?string $keterangan
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class InsuranceCoverage extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $table = 'aset_tr_pertanggungan_asuransi';

    protected $fillable = [
        'tenant_id', 'creation_key', 'polis_asuransi_id', 'aset_id', 'nilai_pertanggungan', 'berlaku_mulai',
        'berlaku_sampai', 'keterangan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nilai_pertanggungan' => 'decimal:2',
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
            'version' => 'integer',
        ];
    }
}
