<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Header dokumen penerimaan aset — satu kedatangan barang.
 *
 * Tanggal, lokasi awal, unit pengguna, dan penanggung jawab berada di header karena satu
 * dokumen adalah satu kedatangan: barang yang datang bersama diterima di tempat yang sama
 * oleh orang yang sama. Barang yang datang di hari lain adalah dokumen lain.
 *
 * `responsible_org_unit_id` adalah unit **pengguna**, dan ia sengaja dipakai dua kali:
 * sebagai pemilik dokumen, dan sebagai `responsible_org_unit_id` pada setiap aset yang
 * lahir darinya. Karena keduanya sama, tidak ada dokumen yang dapat melahirkan aset di
 * luar lingkup penyusunnya sendiri.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $responsible_org_unit_id
 * @property ?string $receiving_org_unit_id
 * @property Carbon $tanggal
 * @property ?Carbon $tanggal_siap_pakai
 * @property ?string $diterima_oleh_user_id
 * @property ?string $penanggung_jawab_user_id
 * @property ?string $lokasi_aset_id
 * @property string $currency_code
 * @property ?string $keterangan
 * @property string $status
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PenerimaanAset extends Model
{
    use HasUlids, SoftDeletes;
    use MilikTenant;

    protected $table = 'aset_tr_penerimaan_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'responsible_org_unit_id',
        'receiving_org_unit_id', 'tanggal', 'tanggal_siap_pakai', 'diterima_oleh_user_id',
        'penanggung_jawab_user_id', 'lokasi_aset_id', 'currency_code', 'keterangan',
        'status', 'version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'tanggal_siap_pakai' => 'date',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<PenerimaanAsetDetail, $this> */
    public function details(): HasMany
    {
        return $this->hasMany(PenerimaanAsetDetail::class, 'penerimaan_aset_id');
    }
}
