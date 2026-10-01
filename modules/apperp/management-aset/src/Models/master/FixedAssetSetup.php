<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Pengaturan aset tetap satu tenant; padanan tabel `FA Setup` (5603) Business Central dan *Fixed assets
 * parameters* F&O. Paling banyak satu baris aktif per tenant.
 *
 * Satu tenant, bukan satu entitas legal seperti company BC atau legal entity F&O: buku penyusutan,
 * group, dan profil yang dirujuknya juga milik tenant, bukan milik entitas legal.
 *
 * Menambah pengaturan: kolom nullable lewat migration sendiri, `$fillable`, dan aturannya di
 * `FixedAssetSetupController::rules()`. Pengaturan yang belum disimpan bernilai `null`, jadi pembacanya
 * selalu menyediakan perilaku bila kosong.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $buku_penyusutan_bawaan_id
 * @property int $version
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read ?BukuPenyusutan $bukuPenyusutanBawaan
 */
#[DataClassification(DataClass::CustomerContent)]
class FixedAssetSetup extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'aset_pengaturan_aset_tetap';

    protected $fillable = ['tenant_id', 'buku_penyusutan_bawaan_id'];

    /** Pengaturan tenant aktif, atau `null` selama belum pernah disimpan. */
    public static function current(): ?self
    {
        return self::query()->first();
    }

    /**
     * Buku penyusutan bawaan (Default Depr. Book BC): buku yang angkanya dipakai saat satu aset hanya
     * boleh punya satu angka, misalnya pada monitoring aset.
     */
    public static function defaultDepreciationBookId(): ?string
    {
        $id = self::query()->value('buku_penyusutan_bawaan_id');

        return $id === null ? null : (string) $id;
    }

    /** @return BelongsTo<BukuPenyusutan, $this> */
    public function bukuPenyusutanBawaan(): BelongsTo
    {
        return $this->belongsTo(BukuPenyusutan::class, 'buku_penyusutan_bawaan_id')->withTrashed();
    }
}
