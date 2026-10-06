<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Identity\Models\User;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/**
 * Bahan uji publikasi analitik (area 15) lewat jalur sungguhan: query tersimpan dan publikasi dibuat lewat API
 * layar, klien integrasi diterbitkan lewat layar Klien integrasi, lalu sistem luar membaca dengan token klien di
 * `api/internal/v1/analytics/...`. Dipakai bersama `BuildsAssetTenants`.
 */
trait PublishesAnalytics
{
    /** @param array<string, mixed> $query */
    protected function savedQuery(User $user, string $name, array $query, bool $shared = false): string
    {
        return (string) $this->actingAs($user)->postJson('/api/v1/analytics/saved-queries', [
            'name' => $name, 'shared' => $shared, 'query' => $query,
        ])->assertCreated()->json('data.id');
    }

    /**
     * Klien integrasi pull baru; memulangkan token `<id>.<rahasia>` dan id-nya.
     *
     * @param  list<string>  $scopes
     * @return array{token: string, id: string}
     */
    protected function integrationClient(User $owner, string $name, array $scopes = ['analytics.read']): array
    {
        $response = $this->actingAs($owner)->postJson('/api/v1/integration-clients', [
            'name' => $name, 'delivery_mode' => 'pull', 'scopes' => $scopes, 'allowed_ips' => [], 'posting_type_prefixes' => [],
        ])->assertCreated();

        return ['token' => (string) $response->json('token'), 'id' => (string) $response->json('data.id')];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    protected function publish(User $owner, array $body): TestResponse
    {
        return $this->actingAs($owner)->postJson('/api/v1/analytics/publications', $body);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<Response>
     */
    protected function feed(string $token, string $path = '', array $query = []): TestResponse
    {
        // Sistem luar tidak membawa sesi: pengguna yang tadi masuk lewat layar tidak boleh ikut terbawa.
        $this->app['auth']->forgetGuards();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/internal/v1/analytics/publications'.$path.($query === [] ? '' : '?'.http_build_query($query)));
        $this->flushHeaders();

        return $response;
    }
}
