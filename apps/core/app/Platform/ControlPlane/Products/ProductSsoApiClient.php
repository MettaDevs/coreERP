<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Products;

use App\Platform\Identity\Support\Sso\SsoApiClient;

final class ProductSsoApiClient extends SsoApiClient
{
    protected function setting(string $key): string
    {
        $value = config('managed_products.registration.sso.'.$key);

        return is_string($value) ? trim($value) : '';
    }
}
