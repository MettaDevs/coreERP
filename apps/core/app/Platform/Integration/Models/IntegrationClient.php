<?php

namespace App\Platform\Integration\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Sistem di luar CoreERP yang memakai API tenant (K-03, TODO area 4).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $token_digest
 * @property list<string> $scopes
 * @property ?list<string> $allowed_ips
 * @property ?list<string> $posting_type_prefixes
 * @property string $delivery_mode
 * @property ?string $push_url
 * @property ?string $signing_secret
 * @property string $status
 * @property ?Carbon $last_used_at
 * @property ?Carbon $last_pulled_at
 * @property ?Carbon $revoked_at
 * @property ?int $created_by_user_id
 * @property ?int $user_id Akun aplikasi klien ini, lihat `IntegrationClientAccounts`.
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
class IntegrationClient extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
        'token_digest' => DataClass::AccountData,
        'signing_secret' => DataClass::AccountData,
        'user_id' => DataClass::EndUserPseudonymousIdentifiers,
    ];

    public const PULL = 'pull';

    public const PUSH = 'push';

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    protected $fillable = [
        'tenant_id', 'name', 'token_digest', 'scopes', 'allowed_ips', 'posting_type_prefixes',
        'delivery_mode', 'push_url', 'signing_secret', 'status', 'last_used_at', 'last_pulled_at',
        'revoked_at', 'created_by_user_id',
    ];

    protected $hidden = ['token_digest', 'signing_secret'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'posting_type_prefixes' => 'array',
            'signing_secret' => 'encrypted',
            'last_used_at' => 'datetime',
            'last_pulled_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function digest(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** Tanpa daftar berarti semua alamat; allowlist hanya lapisan tambahan di atas token (K-03). */
    public function allowsIp(?string $ip): bool
    {
        $list = $this->allowed_ips ?? [];

        return $list === [] || ($ip !== null && IpUtils::checkIp($ip, $list));
    }

    /**
     * Apakah jenis posting ini boleh sampai ke klien ini. Tanpa awalan berarti semua jenis; dengan
     * awalan `asset.`, hanya `asset.acquisition`, `asset.depreciation`, dan seterusnya (K-23).
     */
    public function allowsPostingType(string $postingType): bool
    {
        $prefix = $this->posting_type_prefixes ?? [];

        foreach ($prefix as $item) {
            if (str_starts_with($postingType, $item)) {
                return true;
            }
        }

        return $prefix === [];
    }
}
