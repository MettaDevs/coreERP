<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\ThrottleRequestsPerRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Batas `throttle:N,M` dihitung per rute. Dengan middleware bawaan Laravel, kedua rute di bawah berbagi satu
 * penghitung per pengguna dan rute kedua langsung dijawab 429 walau belum pernah dipanggil.
 */
class ThrottlePerRouteTest extends TestCase
{
    use RefreshDatabase;

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
        $user = User::factory()->create();

        $this->actingAs($user)->get('/__uji-batas/a')->assertOk();
        $this->actingAs($user)->get('/__uji-batas/a')->assertOk();
        $this->actingAs($user)->get('/__uji-batas/a')->assertTooManyRequests();

        $this->actingAs($user)->get('/__uji-batas/b')->assertOk();
    }

    public function test_guests_are_counted_per_route_too(): void
    {
        $this->get('/__uji-batas/a')->assertOk();
        $this->get('/__uji-batas/a')->assertOk();
        $this->get('/__uji-batas/a')->assertTooManyRequests();

        $this->get('/__uji-batas/b')->assertOk();
    }
}
