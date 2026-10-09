<?php

namespace Modules\Apperp\ManagementAset\Models\transaksi\Cancellation;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $resource
 * @property string $document_id
 * @property string $legal_entity_id
 * @property ?string $responsible_org_unit_id
 * @property ?int $source_version
 * @property string $reason
 * @property Carbon $posting_date
 * @property string $status
 * @property string $requested_by_user_id
 * @property ?string $acted_by_user_id
 * @property ?string $workflow_instance_id
 * @property ?list<string> $posting_ids
 * @property ?string $failure_message
 */
#[DataClassification(DataClass::CustomerContent)]
class AssetCancellation extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    public const COLUMN_CLASSIFICATION = [
        'requested_by_user_id' => DataClass::EndUserPseudonymousIdentifiers,
        'acted_by_user_id' => DataClass::EndUserPseudonymousIdentifiers,
    ];

    protected $table = 'aset_tr_pembatalan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['posting_date' => 'date', 'posting_ids' => 'array', 'source_version' => 'integer'];
    }
}
