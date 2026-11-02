<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tenant;

use App\Platform\Tenant\Support\TenantDetails;
use PHPUnit\Framework\TestCase;

final class TenantDetailsTest extends TestCase
{
    public function test_uuid_request_identity_has_one_canonical_spelling(): void
    {
        $key = 'abcdefab-cdef-4abc-8def-abcdefabcdef';
        $details = new TenantDetails(bin2hex(random_bytes(8)), strtoupper($key));

        $this->assertSame($key, $details->idempotencyKey);
    }
}
