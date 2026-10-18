<?php

namespace App\Platform\Organization\Support;

final class PrintIdentityData
{
    /** @return array<string, list<string>> */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'display_name' => ['nullable', 'string', 'max:200'],
            $prefix.'parent_lines' => ['nullable', 'array', 'max:3'],
            $prefix.'parent_lines.*' => ['nullable', 'string', 'max:200'],
            $prefix.'tax_id' => ['nullable', 'string', 'max:60'],
            $prefix.'registration_id' => ['nullable', 'string', 'max:60'],
            $prefix.'footer_text' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
