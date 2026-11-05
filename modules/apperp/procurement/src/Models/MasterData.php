<?php

declare(strict_types=1);

namespace Modules\Apperp\Procurement\Models;

use App\Platform\Modules\Contracts\BelongsToTenant;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Perilaku bersama master Procurement: setiap record dimiliki satu tenant,
 * memakai ULID, dan arsip menggunakan soft delete agar data yang berelasi
 * tidak hilang secara fisik.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $creation_key
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
#[DataClassification(DataClass::CustomerContent)]
abstract class MasterData extends Model
{
    use BelongsToTenant;
    use HasUlids, SoftDeletes;

    protected $guarded = ['id'];
}
