<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppDataPolicy extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code', 'app_id', 'name', 'protected_permissions', 'requires_legal_entity',
        'requires_operating_unit', 'allows_descendants',
    ];

    protected function casts(): array
    {
        return [
            'protected_permissions' => 'array',
            'requires_legal_entity' => 'boolean',
            'requires_operating_unit' => 'boolean',
            'allows_descendants' => 'boolean',
        ];
    }

    /** @return BelongsTo<CoreApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CoreApp::class, 'app_id');
    }

    /** @return HasMany<RoleAssignmentDataPolicyScope, $this> */
    public function scopes(): HasMany
    {
        return $this->hasMany(RoleAssignmentDataPolicyScope::class, 'policy_code', 'code');
    }
}
