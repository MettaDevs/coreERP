<?php

namespace App\Support\Reporting;

use Illuminate\Support\Str;

/**
 * Rujukan layout seperti yang tersimpan di database dan dikirim UI: `bawaan:<kunci>`
 * untuk layout release, atau ULID untuk layout unggahan tenant.
 */
final class LayoutRef
{
    public const BUILTIN_PREFIX = 'bawaan:';

    public static function isBuiltin(string $ref): bool
    {
        return str_starts_with($ref, self::BUILTIN_PREFIX);
    }

    public static function builtinKey(string $ref): string
    {
        return substr($ref, strlen(self::BUILTIN_PREFIX));
    }

    public static function isValid(string $ref): bool
    {
        if (self::isBuiltin($ref)) {
            return (bool) preg_match('/^[a-z0-9-]{1,40}$/', self::builtinKey($ref));
        }

        return Str::isUlid($ref);
    }
}
