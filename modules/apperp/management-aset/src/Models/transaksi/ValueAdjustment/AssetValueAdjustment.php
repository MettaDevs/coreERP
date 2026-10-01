<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Header dokumen penyesuaian nilai aset: penurunan nilai (write-down) atau kenaikan nilai (appreciation)
 * atas satu buku penyusutan, pada satu tanggal. Padanan baris jurnal aset tetap ber-*FA Posting Type*
 * `Write-Down` atau `Appreciation` Business Central.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $responsible_org_unit_id
 * @property string $jenis
 * @property string $buku_id
 * @property Carbon $tanggal
 * @property string $keterangan
 * @property string $status
 * @property ?Carbon $diposting_pada
 * @property ?string $posting_id
 * @property int $version
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetValueAdjustment extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    public const WRITE_DOWN = 'write_down';

    public const APPRECIATION = 'appreciation';

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    /** Label jenis untuk layar dan keterangan jurnal. */
    public const KINDS = [
        self::WRITE_DOWN => 'Penurunan nilai',
        self::APPRECIATION => 'Kenaikan nilai',
    ];

    protected $table = 'aset_tr_penyesuaian_nilai_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'responsible_org_unit_id', 'jenis', 'buku_id',
        'tanggal', 'keterangan', 'status', 'diposting_pada', 'posting_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'diposting_pada' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<AssetValueAdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AssetValueAdjustmentLine::class, 'penyesuaian_nilai_aset_id');
    }
}
