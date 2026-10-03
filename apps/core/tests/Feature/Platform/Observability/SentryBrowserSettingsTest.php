<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Observability;

use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Setelan Sentry untuk peramban sampai lewat meta di halaman, karena satu image dipakai banyak lingkungan
 * dan DSN serta nomor rilisnya baru diketahui saat berjalan (`resources/js/lib/sentry.ts`).
 */
class SentryBrowserSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tanpa_dsn_peramban_tidak_ada_satu_pun_meta_sentry(): void
    {
        config()->set('coreerp.sentry_browser_dsn', null);

        $this->get('/login')->assertOk()->assertDontSee('name="sentry-', false);
    }

    public function test_dsn_rilis_dan_lingkungan_sampai_ke_halaman_tamu_tanpa_pengguna(): void
    {
        config()->set('coreerp.sentry_browser_dsn', 'https://kunci@sentry.test/5');
        config()->set('sentry.release', 'coreerp@0.11.3');
        config()->set('sentry.environment', 'saas-dev');

        $this->get('/login')
            ->assertOk()
            ->assertSee('<meta name="sentry-dsn" content="https://kunci@sentry.test/5">', false)
            ->assertSee('<meta name="sentry-release" content="coreerp@0.11.3">', false)
            ->assertSee('<meta name="sentry-environment" content="saas-dev">', false)
            ->assertDontSee('name="sentry-user"', false);
    }

    /** Crash Free Users dihitung dari id pengguna; hanya id, bukan nama atau email. */
    public function test_pengguna_yang_masuk_dikirim_sebagai_id_saja(): void
    {
        config()->set('coreerp.sentry_browser_dsn', 'https://kunci@sentry.test/5');
        $pengguna = User::factory()->create(['email' => 'rahasia@example.test']);

        $jawaban = $this->actingAs($pengguna)->get('/settings/profile');

        $jawaban->assertSee('<meta name="sentry-user" content="'.$pengguna->id.'">', false);
        $this->assertStringNotContainsString('content="rahasia@example.test"', (string) $jawaban->getContent());
    }
}
