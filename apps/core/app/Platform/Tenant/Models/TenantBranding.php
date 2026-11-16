<?php

namespace App\Platform\Tenant\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Logo aplikasi pengganti milik satu tenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $logo_path
 * @property ?string $logo_mime
 * @property ?string $logo_hash
 */
#[DataClassification(DataClass::CustomerContent)]
class TenantBranding extends Model
{
    use HasUlids;

    protected $fillable = ['tenant_id', 'logo_path', 'logo_mime', 'logo_hash'];
}
