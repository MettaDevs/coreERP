<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->seed(\Database\Seeders\AppCatalogSeeder::class);
        $user = User::factory()->create();
        $membership = app(\App\Actions\Onboarding\RegisterBusiness::class)->createForUser($user, [
            'business_name' => 'PT Test',
            'app_ids' => ['management-aset'],
        ]);

        $response = $this->actingAs($user)
            ->withSession(['workspace.membership_id' => $membership->id])
            ->get(route('dashboard'));

        $response->assertOk();
    }
}
