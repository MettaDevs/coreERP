<?php

namespace Tests\Feature\Auth;

use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
        $this->seed(AppCatalogSeeder::class);
        Queue::fake();
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'name' => 'Test User']);
        $this->assertDatabaseCount('tenant_memberships', 0);
    }

    public function test_registration_rejects_already_registered_email(): void
    {
        \App\Models\User::factory()->create([
            'email' => 'victim@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('victim-secret-password'),
        ]);

        $response = $this->post(route('register.store'), [
            'name' => 'Attacker',
            'business_name' => 'Attacker Corp',
            'app_ids' => ['management-aset'],
            'email' => 'victim@example.com',
            'password' => 'AttackerPassword123!',
            'password_confirmation' => 'AttackerPassword123!',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();

        $victim = \App\Models\User::where('email', 'victim@example.com')->first();
        $this->assertNotNull($victim);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('victim-secret-password', $victim->password));
    }

    public function test_check_email_endpoint_returns_availability_without_leaking_counts(): void
    {
        \App\Models\User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $res1 = $this->postJson(route('check-email'), ['email' => 'existing@example.com']);
        $res1->assertOk()
            ->assertJson([
                'available' => false,
                'message' => 'Email sudah terdaftar. Silakan gunakan email lain atau login.',
            ])
            ->assertJsonMissingPath('count')
            ->assertJsonMissingPath('exists')
            ->assertJsonMissingPath('maxReached');

        $res2 = $this->postJson(route('check-email'), ['email' => 'fresh-new@example.com']);
        $res2->assertOk()
            ->assertJson([
                'available' => true,
                'message' => null,
            ])
            ->assertJsonMissingPath('count')
            ->assertJsonMissingPath('exists')
            ->assertJsonMissingPath('maxReached');
    }
}

