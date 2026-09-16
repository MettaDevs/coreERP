<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu upacara masuk lewat SSO, dari tombol di alamat tenant sampai sesi berdiri di sana.
 * Alasan setiap kolomnya di migration `create_sso_tables`.
 *
 * @property string $id
 * @property string $state_hash
 * @property string $browser_secret_hash
 * @property string $nonce
 * @property string $code_verifier
 * @property string $tenant_id
 * @property string $environment_id
 * @property string $return_origin
 * @property string $redirect_uri
 * @property ?int $user_id
 * @property ?int $link_user_id
 * @property ?string $subject
 * @property ?string $subject_email
 * @property ?string $handoff_token_hash
 * @property ?string $invitation_id
 * @property Carbon $expires_at
 * @property ?Carbon $completed_at
 * @property ?Carbon $consumed_at
 */
class SsoLoginAttempt extends Model
{
    use HasUlids;
    use OwnedByControlPlane;

    protected $table = 'sso_login_attempts';

    protected $fillable = [
        'state_hash',
        'browser_secret_hash',
        'nonce',
        'code_verifier',
        'tenant_id',
        'environment_id',
        'return_origin',
        'redirect_uri',
        'user_id',
        'link_user_id',
        'subject',
        'subject_email',
        'handoff_token_hash',
        'invitation_id',
        'expires_at',
        'completed_at',
        'consumed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Pemegang code_verifier dapat menukar kode yang tercegat. Dienkripsi, bukan di-hash:
            // nilainya memang harus dibaca kembali untuk dikirim ke penyedia.
            'code_verifier' => 'encrypted',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Upacara "hubungkan", bukan upacara masuk. */
    public function isLinking(): bool
    {
        return $this->link_user_id !== null;
    }

    /**
     * Upacara menukarkan undangan terikat SSO: orangnya belum punya akun di sini, atau punya tetapi
     * belum jadi anggota tenant ini. Ketiga upacara saling eksklusif, dan `callback` memilih di
     * antaranya lewat dua penanya ini.
     */
    public function isJoining(): bool
    {
        return $this->invitation_id !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
