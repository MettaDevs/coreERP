<?php

namespace Modules\Apperp\ManagementAset\Models\master;

use App\Support\Modules\Contracts\MilikTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Matriks group aset x buku penyusutan; padanan "Fixed asset group/book" di Dynamics 365 F&O.
 *
 * Di sinilah ditentukan aset dari group tertentu mendapat buku apa saja dan dengan aturan
 * apa. Bukan master penuh: tanpa kode dan tanpa nama.
 *
 * `round_off_depreciation` null berarti memakai nilai dari buku, sedangkan nol berarti
 * pembulatan sengaja dimatikan untuk kombinasi group dan buku ini. Ia di-cast `decimal:2`,
 * jadi Eloquent memulangkannya sebagai string dan bukan float.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $group_aset_id
 * @property string $buku_id
 * @property ?string $depreciation_profile_id
 * @property ?string $alternative_profile_id
 * @property ?int $useful_life_periods
 * @property ?string $convention
 * @property bool $depreciate
 * @property ?string $round_off_depreciation
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class GroupBukuPenyusutan extends Model
{
    use HasUlids;
    use MilikTenant;
    use SoftDeletes;

    protected $table = 'aset_m_group_buku_penyusutan';

    protected $fillable = [
        'tenant_id', 'group_aset_id', 'buku_id', 'depreciation_profile_id', 'alternative_profile_id',
        'useful_life_periods', 'convention', 'depreciate', 'round_off_depreciation',
    ];

    protected function casts(): array
    {
        return [
            'useful_life_periods' => 'integer',
            'depreciate' => 'boolean',
            'round_off_depreciation' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<GroupAset, $this> */
    public function groupAset(): BelongsTo
    {
        return $this->belongsTo(GroupAset::class, 'group_aset_id');
    }

    /** @return BelongsTo<BukuPenyusutan, $this> */
    public function buku(): BelongsTo
    {
        return $this->belongsTo(BukuPenyusutan::class, 'buku_id');
    }
}
