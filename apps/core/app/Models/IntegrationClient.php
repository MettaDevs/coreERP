<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Sistem di luar CoreERP yang membaca feed posting finance (K-03, TODO area 4).
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
 * @property ?string $created_by_user_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class IntegrationClient extends Model
{
    use HasUlids;

    public const PULL = 'pull';

    public const PUSH = 'push';

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    /**
     * Cakupan yang dapat diberikan. Sengaja sempit dan per sumber daya: pembaca yang hanya perlu
     * menarik posting tidak perlu dapat mengakui (`ack`), dan sebaliknya.
     */
    public const SCOPES = [
        'finance-postings.read' => 'Menarik posting finance',
        'finance-postings.ack' => 'Mengakui posting (dibukukan atau ditolak)',
        'vendors.read' => 'Membaca vendor',
        'operating-units.read' => 'Membaca operating unit dan nomornya',
    ];

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
        $daftar = $this->allowed_ips ?? [];

        return $daftar === [] || ($ip !== null && IpUtils::checkIp($ip, $daftar));
    }

    /**
     * Apakah jenis posting ini boleh sampai ke klien ini. Tanpa awalan berarti semua jenis; dengan
     * awalan `asset.`, hanya `asset.acquisition`, `asset.depreciation`, dan seterusnya (K-23).
     */
    public function allowsPostingType(string $postingType): bool
    {
        $awalan = $this->posting_type_prefixes ?? [];

        foreach ($awalan as $satu) {
            if (str_starts_with($postingType, $satu)) {
                return true;
            }
        }

        return $awalan === [];
    }
}
