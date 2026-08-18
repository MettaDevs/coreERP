<?php

namespace Tests\Feature\Auth;

use App\Models\CoreApp;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AddBusinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AppCatalogSeeder::class);
        Queue::fake();
        \Illuminate\Support\Facades\Bus::fake();
    }

    public function test_authenticated_user_can_add_new_business(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('workspace.create'), [
            'business_name' => 'PT Bisnis Kedua',
            'app_ids' => ['management-aset'],
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));

        $membership = TenantMembership::where('user_id', $user->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame('owner', $membership->system_role);
        $this->assertSame('PT Bisnis Kedua', $membership->tenant->name);
        $this->assertDatabaseHas('tenant_app_entitlements', [
            'tenant_id' => $membership->tenant_id,
            'app_id' => 'management-aset',
        ]);
    }

    public function test_authenticated_user_cannot_exceed_max_businesses_limit(): void
    {
        config()->set('coreerp.max_businesses_per_user', 2);

        $user = User::factory()->create();
        $registerAction = app(\App\Actions\Onboarding\RegisterBusiness::class);

        $registerAction->createForUser($user, [
            'business_name' => 'PT Bisnis Satu',
            'app_ids' => ['management-aset'],
        ]);
        $registerAction->createForUser($user, [
            'business_name' => 'PT Bisnis Dua',
            'app_ids' => ['management-aset'],
        ]);

        $this->assertSame(2, $user->memberships()->where('system_role', 'owner')->count());

        // Attempt to add 3rd business (should be rejected by quota limit)
        $res = $this->actingAs($user)->post(route('workspace.create'), [
            'business_name' => 'PT Bisnis Tiga',
            'app_ids' => ['management-aset'],
        ]);

        $res->assertSessionHasErrors(['business_name']);
        $this->assertSame(2, $user->memberships()->where('system_role', 'owner')->count());
    }

    public function test_unauthenticated_guest_cannot_add_business(): void
    {
        $response = $this->post(route('workspace.create'), [
            'business_name' => 'PT Ilegal',
            'app_ids' => ['management-aset'],
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_owner_can_delete_business(): void
    {
        $user = User::factory()->create();
        $registerAction = app(\App\Actions\Onboarding\RegisterBusiness::class);

        $membership = $registerAction->createForUser($user, [
            'business_name' => 'PT Hapus Saya',
            'app_ids' => ['management-aset'],
        ]);

        $this->assertDatabaseHas('tenant_memberships', ['id' => $membership->id]);

        $response = $this->actingAs($user)->delete(route('workspace.destroy', $membership->id));

        $response->assertRedirect(route('workspace.select'));
        $this->assertDatabaseMissing('tenant_memberships', ['id' => $membership->id]);
    }

    public function test_cannot_add_business_with_duplicate_name(): void
    {
        $user = User::factory()->create();
        $registerAction = app(\App\Actions\Onboarding\RegisterBusiness::class);

        $registerAction->createForUser($user, [
            'business_name' => 'PT Sanata Niaga',
            'app_ids' => ['management-aset'],
        ]);

        $response = $this->actingAs($user)->post(route('workspace.create'), [
            'business_name' => 'pt sanata niaga',
            'app_ids' => ['management-aset'],
        ]);

        $response->assertSessionHasErrors(['business_name']);
    }

    public function test_different_users_can_create_business_with_same_name(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $registerAction = app(\App\Actions\Onboarding\RegisterBusiness::class);

        $registerAction->createForUser($user1, [
            'business_name' => '123',
            'app_ids' => ['management-aset'],
        ]);

        $response = $this->actingAs($user2)->post(route('workspace.create'), [
            'business_name' => '123',
            'app_ids' => ['management-aset'],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('dashboard'));
    }
}
