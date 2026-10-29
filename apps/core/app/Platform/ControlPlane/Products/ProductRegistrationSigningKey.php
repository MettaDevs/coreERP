<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Products;

use App\Platform\ControlPlane\Models\ConsoleSetting;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

final class ProductRegistrationSigningKey
{
    private const SETTING = 'product-registration.signing-key';

    /** @return array{kid: string, public_key_b64: string, issuer: string} */
    public function publicData(bool $create = false): array
    {
        $private = $this->privateKey($create);
        $key = openssl_pkey_get_private($private);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Kunci login produk tidak dapat dibaca.');
        }

        return ['kid' => $this->keyId(), 'public_key_b64' => base64_encode($details['key']), 'issuer' => $this->issuer()];
    }

    /** @param array<string, mixed> $claims */
    public function sign(array $claims): string
    {
        return JWT::encode($claims, $this->privateKey(), 'RS256', $this->keyId());
    }

    public function issuer(): string
    {
        return rtrim((string) config('managed_products.registration.public_origin'), '/').'/api/trials';
    }

    private function keyId(): string
    {
        return (string) config('managed_products.registration.signing_key_id', 'trialfaskes-v1');
    }

    private function privateKey(bool $create = false): string
    {
        $connection = (new ConsoleSetting)->getConnection();

        return $connection->transaction(function () use ($connection, $create): string {
            $connection->select("SELECT pg_advisory_xact_lock(hashtext('product-registration.signing-key'))");
            $stored = $connection->table('console_settings')->where('key', self::SETTING)->value('value');
            if (is_string($stored)) {
                return Crypt::decryptString($stored);
            }
            if (! $create) {
                throw new RuntimeException('Kunci login produk belum dikonfigurasi.');
            }
            $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false || ! openssl_pkey_export($key, $private)) {
                throw new RuntimeException('Kunci login produk tidak dapat dibuat.');
            }
            $connection->table('console_settings')->insert([
                'key' => self::SETTING, 'value' => Crypt::encryptString($private), 'updated_at' => now(), 'updated_by' => null,
            ]);
            $connection->table('operator_audit_events')->insert([
                'id' => (string) Str::ulid(), 'user_id' => null, 'action' => 'product-registration.signing-key.create',
                'subject_type' => 'console_setting', 'subject_id' => self::SETTING,
                'detail' => json_encode(['kid' => $this->keyId()], JSON_THROW_ON_ERROR), 'occurred_at' => now(),
            ]);

            return $private;
        });
    }
}
