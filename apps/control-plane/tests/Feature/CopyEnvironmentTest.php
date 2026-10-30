<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Models\Environment;
use ControlPlane\Models\User;
use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class CopyEnvironmentTest extends TestCase
{
    use CoreSchema;

    private function operator(bool $allowed = true): User
    {
        $id = DB::table('users')->insertGetId(['name' => 'Operator uji', 'email' => Str::ulid().'@example.test', 'password' => bcrypt('test'), 'created_at' => now(), 'updated_at' => now()]);
        if ($allowed) {
            DB::table('provider_access')->insert(['user_id' => $id, 'role' => 'provider_admin', 'created_at' => now(), 'updated_at' => now()]);
        }

        return User::query()->findOrFail($id);
    }

    private function source(): Environment
    {
        $id = (string) Str::ulid();
        DB::table('clients')->insert(['id' => $id, 'legal_name' => 'Audit copy', 'slug' => strtolower($id), 'created_at' => now(), 'updated_at' => now()]);
        $tenant = (string) Str::ulid();
        DB::table('tenants')->insert(['id' => $tenant, 'client_id' => $id, 'name' => 'Audit copy', 'slug' => strtolower($tenant), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $source = Environment::create(['tenant_id' => $tenant, 'kind' => 'production', 'name' => 'Production uji', 'slug' => 'production', 'status' => 'active', 'outbound_allowed' => true]);
        DB::table('environments')->where('id', $source->id)->update(['database_name' => 'env_copy_audit']);

        return $source->refresh();
    }

    private function target(Environment $source, string $status = 'copying'): Environment
    {
        return Environment::create(['tenant_id' => $source->tenant_id, 'kind' => 'sandbox', 'name' => 'Salinan uji', 'slug' => 'salinan-uji', 'source_environment_id' => $source->id, 'status' => $status, 'outbound_allowed' => false]);
    }

    /** @return array{source_environment_id:string,target_environment_id:string,tenant_id:string,queued:bool,status:string} */
    private function accepted(Environment $source, Environment $target): array
    {
        return ['source_environment_id' => $source->id, 'target_environment_id' => $target->id, 'tenant_id' => $source->tenant_id, 'queued' => true, 'status' => $target->status];
    }

    public function test_operator_click_queues_copy_through_core_and_opens_target_details(): void
    {
        config(['core.base_url' => 'http://core.test', 'core.token' => 'copy-test-token']);
        Http::preventStrayRequests();
        $source = $this->source();
        $target = $this->target($source);
        $operator = $this->operator();
        Http::fake(['http://core.test/api/internal/v1/environments/'.$source->id.'/copy' => Http::response($this->accepted($source, $target), 202)]);
        $this->actingAs($operator)->post('/lingkungan/'.$source->id.'/salin', ['name' => $target->name])->assertRedirect('/lingkungan/'.$target->id)->assertSessionHasNoErrors();
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer copy-test-token') && $request['requested_by'] === $operator->id && $request['name'] === $target->name);
    }

    public function test_non_operator_cannot_click_copy_or_contact_core(): void
    {
        Http::fake();
        $source = $this->source();
        $this->actingAs($this->operator(false))->post('/lingkungan/'.$source->id.'/salin', ['name' => 'Salinan uji'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_incomplete_or_wrong_tenant_response_is_not_success(): void
    {
        config(['core.base_url' => 'http://core.test', 'core.token' => 'copy-test-token']);
        $source = $this->source();
        $target = $this->target($source);
        $payload = $this->accepted($source, $target);
        $payload['tenant_id'] = (string) Str::ulid();
        Http::fake(['*' => Http::response($payload, 202)]);
        $this->actingAs($this->operator())->from('/lingkungan/'.$source->id)->post('/lingkungan/'.$source->id.'/salin', ['name' => $target->name])
            ->assertRedirect('/lingkungan/'.$source->id)->assertSessionHasErrors('copy');
    }

    public function test_failed_copy_has_copy_retry_and_never_the_prepare_action(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $source = $this->source();
        $target = $this->target($source, 'degraded');
        $this->actingAs($this->operator())->get('/lingkungan/'.$target->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('environments/show')->where('canProvision', false)->where('copyState.canRequest', true)->where('copyState.sourceId', $source->id)->where('copyState.targetId', $target->id));
    }

    public function test_active_sandbox_is_visible_but_never_offered_for_overwrite(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $source = $this->source();
        $target = $this->target($source, 'active');
        $this->actingAs($this->operator())->get('/lingkungan/'.$source->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('environments/show')->where('copyState.canRequest', false)->where('copyState.targetId', $target->id));
    }
}
