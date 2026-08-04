<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $system_role
 * @property int $created_by
 * @property string|null $code_ciphertext
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property-read Collection<int, Role> $roles
 */
class InvitationCode extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'code_hash',
        'code_ciphertext',
        'system_role',
        'created_by',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = ['code_hash', 'code_ciphertext'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'invitation_role_assignments', 'invitation_id', 'role_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accessibleCode(): ?string
    {
        if ($this->code_ciphertext === null) {
            return null;
        }

        try {
            return Crypt::decryptString($this->code_ciphertext);
        } catch (DecryptException) {
            return null;
        }
    }
}
