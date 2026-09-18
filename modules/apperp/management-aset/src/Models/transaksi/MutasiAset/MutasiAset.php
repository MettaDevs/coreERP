<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Header dokumen mutasi aset — berita acara serah terima.
 *
 * Tujuan berada di header dan aset berada di baris, karena satu serah terima adalah satu
 * perpindahan antara dua pihak: barang-barang yang berpindah bersama berpindah ke tempat
 * yang sama. Aset yang pindah ke tempat lain adalah dokumen lain.
 *
 * `responsible_org_unit_id` adalah unit **asal**, pemilik dokumen, dan ia tidak ikut
 * berubah saat dokumen diselesaikan: yang membuat berita acara adalah pihak yang
 * menyerahkan, dan dokumen itu harus tetap berada di daftar mereka sesudahnya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $responsible_org_unit_id
 * @property Carbon $tanggal
 * @property ?string $tujuan_lokasi_id
 * @property string $tujuan_org_unit_id
 * @property ?string $diserahkan_oleh_user_id
 * @property ?string $diterima_oleh_user_id
 * @property string $alasan
 * @property ?string $keterangan
 * @property string $status
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class MutasiAset extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'aset_tr_mutasi_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'responsible_org_unit_id',
        'tanggal', 'tujuan_lokasi_id', 'tujuan_org_unit_id', 'diserahkan_oleh_user_id',
        'diterima_oleh_user_id', 'alasan', 'keterangan', 'status', 'version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<MutasiAsetDetail, $this> */
    public function details(): HasMany
    {
        return $this->hasMany(MutasiAsetDetail::class, 'mutasi_aset_id');
    }
}
