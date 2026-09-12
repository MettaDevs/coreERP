<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Pusat\MilikPusat;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property bool $must_change_password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, TenantMembership> $memberships
 * @property-read ProviderAccess|null $providerAccess
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    use MilikPusat;

    /**
     * Bawaan kolom penanda, ditulis di model dan bukan hanya di skema.
     *
     * Bawaan database baru berlaku setelah barisnya tersimpan, sehingga model yang baru dibuat
     * mengembalikan `null` untuk kolom ini sampai ia dibaca ulang. `null` kebetulan falsy dan
     * kebetulan berperilaku benar — dan "kebetulan benar" bukan sesuatu yang boleh ditumpangi
     * penjaga yang menentukan ke mana seseorang boleh pergi.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'must_change_password' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            // Sengaja tidak ikut `Fillable` di atas. Penanda ini menentukan ke mana seseorang
            // boleh pergi setelah masuk, jadi ia hak — dan hak tidak boleh dapat ditentukan isi
            // sebuah permintaan. Yang menyalakannya hanya pintu operator, lewat `forceFill`.
            'must_change_password' => 'boolean',
            /* @chisel-2fa */
            'two_factor_confirmed_at' => 'datetime',
            /* @end-chisel-2fa */
        ];
    }

    /** @return HasMany<TenantMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /** @return HasOne<ProviderAccess, $this> */
    public function providerAccess(): HasOne
    {
        return $this->hasOne(ProviderAccess::class);
    }

    public function activeMembership(): ?TenantMembership
    {
        return $this->memberships()
            ->with('tenant')
            ->where('status', 'active')
            ->first();
    }
}
