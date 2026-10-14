<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Models\transaksi\Ledger;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[DataClassification(DataClass::CustomerContent)]
final class FixedAssetLedgerEntry extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** Nama aset adalah identitas barang, bukan nama orang. */
    public const COLUMN_CLASSIFICATION = ['asset_name' => DataClass::CustomerContent];

    /** @var array<string, string> */
    public const FIELD_LOOKUPS = ['group_aset_id' => 'group-aset'];

    protected $table = 'aset_tr_ledger_aset';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];
}
