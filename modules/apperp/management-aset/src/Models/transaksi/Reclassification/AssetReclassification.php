<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\Reclassification;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Header dokumen reklasifikasi aset: pindah group aset, atau pecah sebagian nilai aset ke aset baru, pada satu
 * tanggal. Padanan *FA Reclass. Journal* Business Central.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $creation_key
 * @property string $kode
 * @property string $legal_entity_id
 * @property string $responsible_org_unit_id
 * @property string $jenis
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
class AssetReclassification extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    /** Aset yang sama pindah seluruhnya ke group aset lain. */
    public const TRANSFER = 'pindah_group';

    /** Sebagian nilai aset dipindah ke aset baru. */
    public const SPLIT = 'pecah';

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    /** Label jenis untuk layar dan keterangan jurnal. */
    public const KINDS = [
        self::TRANSFER => 'Pindah group aset',
        self::SPLIT => 'Pecah aset',
    ];

    protected $table = 'aset_tr_reklasifikasi_aset';

    protected $fillable = [
        'tenant_id', 'creation_key', 'kode', 'legal_entity_id', 'responsible_org_unit_id', 'jenis', 'tanggal',
        'keterangan', 'status', 'diposting_pada', 'posting_id',
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

    /** @return HasMany<AssetReclassificationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AssetReclassificationLine::class, 'reklasifikasi_aset_id');
    }
}
