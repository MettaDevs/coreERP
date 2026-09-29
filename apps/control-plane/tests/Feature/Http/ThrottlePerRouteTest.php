<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Http;

use ControlPlane\Http\Middleware\ThrottleRequestsPerRoute;
use ControlPlane\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Route;

/**
 * Batas `throttle:N,M` dihitung per rute, kembar dengan test yang sama di Core. Dengan middleware bawaan
 * Laravel, rute kedua langsung dijawab 429 walau belum pernah dipanggil.
 */
final class ThrottlePerRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'throttle:2,1'])->group(function (): void {
            Route::get('/__uji-batas/a', fn () => 'a');
            Route::get('/__uji-batas/b', fn () => 'b');
        });
    }

    public function test_the_throttle_alias_counts_per_route(): void
    {
        $kernel = app(Kernel::class);
        $this->assertInstanceOf(HttpKernel::class, $kernel);
        $this->assertSame(ThrottleRequestsPerRoute::class, $kernel->getMiddlewareAliases()['throttle']);
    }

    public function test_exhausting_one_route_leaves_another_route_untouched(): void
    {
        $this->get('/__uji-batas/a')->assertOk();
        $this->get('/__uji-batas/a')->assertOk();
        $this->get('/__uji-batas/a')->assertTooManyRequests();

        $this->get('/__uji-batas/b')->assertOk();
    }
}
