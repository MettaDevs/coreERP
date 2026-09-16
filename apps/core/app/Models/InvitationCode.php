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
 * @property string|null $label
 * @property int $created_by
 * @property string|null $code_ciphertext
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $sso_issuer
 * @property string|null $sso_subject
 * @property string|null $sso_email_at_invite
 * @property string|null $sso_name_at_invite
 * @property Carbon|null $sso_checked_at
 * @property Carbon|null $sso_notified_at
 * @property Carbon|null $sso_redeemed_at
 * @property int|null $sso_redeemed_by
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
        'label',
        'created_by',
        'expires_at',
        'revoked_at',
        'sso_issuer',
        'sso_subject',
        'sso_email_at_invite',
        'sso_name_at_invite',
        'sso_checked_at',
        'sso_notified_at',
        'sso_redeemed_at',
        'sso_redeemed_by',
    ];

    protected $hidden = ['code_hash', 'code_ciphertext'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'sso_checked_at' => 'datetime',
            'sso_notified_at' => 'datetime',
            'sso_redeemed_at' => 'datetime',
        ];
    }

    /**
     * Undangan untuk satu akun SSO tertentu, bukan kode anonim yang dapat dipakai berulang.
     *
     * Diperiksa lewat `sso_subject` dan bukan lewat emailnya: CHECK `undangan_sso_utuh` menjamin
     * ketiganya terisi bersama, dan subjek itulah yang menentukan siapa yang boleh menukarkannya.
     */
    public function isSsoBound(): bool
    {
        return $this->sso_subject !== null;
    }

    public function isSpent(): bool
    {
        return $this->sso_redeemed_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Masih dapat ditukar: belum dicabut, belum kedaluwarsa, dan belum dipakai. */
    public function isOpen(): bool
    {
        return $this->revoked_at === null && ! $this->hasExpired() && ! $this->isSpent();
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
