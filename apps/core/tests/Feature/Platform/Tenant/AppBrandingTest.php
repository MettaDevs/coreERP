<?php

namespace Tests\Feature\Platform\Tenant;

use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Actions\RegisterBusiness;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Logo aplikasi pengganti milik tenant: header, ikon tab peramban, dan gambar pratinjau link
 * harus berganti bersama, dan kembali ke bawaan bersama.
 */
class AppBrandingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->withoutVite();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant logo',
            'app_ids' => ['app-uji'], 'email' => 'owner@logo.test', 'password' => 'password',
        ]);
    }

    public function test_default_logo_is_used_until_a_tenant_uploads_its_own(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('<link rel="icon" href="/favicon.png">', false)
            ->assertSee('og:image" content="'.url('/brand/coreerp-logo.png').'"', false);
        $this->get('/app-logo')->assertRedirect('/brand/coreerp-logo.png');
    }

    public function test_uploaded_logo_replaces_header_tab_icon_and_link_preview(): void
    {
        $logoUrl = $this->actingAs($this->owner)
            ->post('/api/v1/app-logo', ['file' => UploadedFile::fake()->image('logo.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.logo_url');

        $this->assertStringStartsWith('/app-logo?v=', $logoUrl);
        $this->get('/dashboard')->assertOk()
            ->assertSee('<link rel="icon" href="'.e($logoUrl).'">', false)
            ->assertSee('og:image" content="'.e(url($logoUrl)).'"', false)
            ->assertInertia(fn ($page) => $page->where('branding.logo_url', $logoUrl));

        // Tanpa sesi juga: halaman masuk dan aplikasi pesan memintanya sebelum ada yang login.
        $this->post('/logout');
        $this->get('/login')->assertSee('<link rel="icon" href="'.e($logoUrl).'">', false);
        $this->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_removing_the_logo_returns_to_the_default_and_keeps_the_row(): void
    {
        $this->actingAs($this->owner)
            ->post('/api/v1/app-logo', ['file' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
            ->assertCreated();
        $path = DB::table('tenant_brandings')->value('logo_path');

        $this->deleteJson('/api/v1/app-logo')->assertOk()->assertJsonPath('data.logo_url', '/brand/coreerp-logo.png');

        $this->assertSame(1, DB::table('tenant_brandings')->count());
        $this->assertNull(DB::table('tenant_brandings')->value('logo_path'));
        Storage::disk()->assertMissing($path);
        $this->get('/app-logo')->assertRedirect('/brand/coreerp-logo.png');
    }

    public function test_only_png_and_jpeg_are_accepted_and_members_without_permission_are_refused(): void
    {
        $this->actingAs($this->owner)
            ->post('/api/v1/app-logo', ['file' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $member = User::factory()->create();
        $this->owner->activeMembership()->tenant->memberships()->create(['user_id' => $member->id, 'status' => 'active']);
        $this->actingAs($member)
            ->post('/api/v1/app-logo', ['file' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->assertSame(0, DB::table('tenant_brandings')->count());
    }
}
