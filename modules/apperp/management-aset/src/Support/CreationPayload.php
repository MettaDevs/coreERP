<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Support;

final class CreationPayload
{
    /** @param array<array-key, mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode(self::canonical($payload), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function canonical(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::canonical($value);
            }
        }

        return $payload;
    }
}
