<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Token pendaftaran situs — tabel `site_enrollment_tokens` milik Core. Yang tersimpan hash-nya.
 *
 * @property string $id
 * @property string $site_id
 * @property string $token_hash
 * @property string $channel
 * @property Carbon $expires_at
 * @property ?Carbon $used_at
 */
class SiteEnrollmentToken extends Model
{
    use HasUlids;

    protected $table = 'site_enrollment_tokens';

    protected $fillable = ['site_id', 'token_hash', 'channel', 'expires_at', 'used_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
