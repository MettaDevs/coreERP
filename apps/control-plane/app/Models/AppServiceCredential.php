<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppServiceCredential extends Model
{
    use HasUlids;

    protected $fillable = ['app_id', 'tenant_id', 'name', 'secret_hash', 'token_digest', 'status', 'last_used_at'];

    protected $hidden = ['secret_hash', 'token_digest'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /**
     * Issue a credential and return the one-time plaintext token alongside it.
     *
     * The token carries its own credential id so verification is a single indexed lookup instead of a hash check
     * against every credential the app owns. The secret half is 256 bits of randomness, which is why a fast digest
     * is appropriate here where bcrypt would not be.
     *
     * @return array{0:self,1:string}
     */
    public static function issueToken(string $appId, string $name, ?string $tenantId = null): array
    {
        $secret = bin2hex(random_bytes(32));
        $credential = static::query()->create([
            'app_id' => $appId,
            'tenant_id' => $tenantId,
            'name' => $name,
            'secret_hash' => null,
            'token_digest' => static::digest($secret),
            'status' => 'active',
        ]);

        return [$credential, $credential->id.'.'.$secret];
    }

    public static function digest(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** @return BelongsTo<CoreApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CoreApp::class, 'app_id');
    }
}
