<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\ControlPlane;

use App\Platform\ControlPlane\Jobs\CopyEnvironment as CopyEnvironmentJob;
use App\Platform\ControlPlane\Models\Client;
use App\Platform\Environment\Models\Environment;
use App\Platform\Environment\Models\EnvironmentOperation;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class QueueEnvironmentCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('coreerp.control_plane_token', 'copy-api-test');
        Queue::fake();
        $this->operator = User::create(['name' => 'Operator uji', 'email' => Str::ulid().'@example.test', 'password' => bcrypt('test-password')]);
    }

    private function source(): Environment
    {
        $slug = strtolower((string) Str::ulid());
        $client = Client::create(['legal_name' => 'Audit copy', 'slug' => $slug, 'status' => 'active']);
        $tenant = Tenant::create(['client_id' => $client->id, 'name' => 'Audit copy', 'slug' => $slug, 'status' => 'active']);

        return Environment::create(['tenant_id' => $tenant->id, 'kind' => 'production', 'name' => 'Production uji', 'slug' => 'production', 'database_name' => 'env_test_'.$slug, 'status' => 'active', 'outbound_allowed' => true]);
    }

    private function sandbox(Environment $source, string $status): Environment
    {
        return Environment::create(['tenant_id' => $source->tenant_id, 'kind' => 'sandbox', 'name' => 'Salinan uji', 'slug' => 'salinan-uji', 'source_environment_id' => $source->id, 'status' => $status, 'outbound_allowed' => false]);
    }

    public function test_token_is_required_and_nothing_is_created(): void
    {
        $source = $this->source();
        $this->postJson('/api/internal/v1/environments/'.$source->id.'/copy', ['name' => 'Salinan uji', 'requested_by' => $this->operator->id])->assertUnauthorized();
        $this->assertSame(0, Environment::query()->where('kind', 'sandbox')->count());
        Queue::assertNothingPushed();
    }

    public function test_operator_queues_one_same_tenant_sandbox_and_repeated_click_does_not_duplicate_it(): void
    {
        $source = $this->source();
        $path = '/api/internal/v1/environments/'.$source->id.'/copy';
        $input = ['name' => 'Salinan uji', 'requested_by' => $this->operator->id];
        $first = $this->withToken('copy-api-test')->postJson($path, $input)->assertAccepted();
        $second = $this->withToken('copy-api-test')->postJson($path, $input)->assertAccepted();
        $this->assertSame($first->json('target_environment_id'), $second->json('target_environment_id'));
        $target = Environment::query()->whereKey($first->json('target_environment_id'))->firstOrFail();
        $this->assertSame($source->tenant_id, $target->tenant_id);
        $this->assertSame($source->id, $target->source_environment_id);
        $this->assertFalse($target->outbound_allowed);
        $this->assertSame('copying', $target->status);
        $this->assertSame(1, Environment::query()->where('kind', 'sandbox')->count());
        $this->assertSame(1, DB::table('operator_audit_events')->where('action', 'environment.copy_requested')->count());
        Queue::assertPushed(CopyEnvironmentJob::class, fn (CopyEnvironmentJob $job): bool => $job->sourceId === $source->id && $job->targetId === $target->id && $job->requestedBy === $this->operator->id);
        Queue::assertPushed(CopyEnvironmentJob::class, 1);
    }

    public function test_active_sandbox_is_not_overwritten_and_cannot_be_renamed_by_copy(): void
    {
        $source = $this->source();
        $target = $this->sandbox($source, 'active');
        $this->withToken('copy-api-test')->postJson('/api/internal/v1/environments/'.$source->id.'/copy', ['name' => $target->name, 'requested_by' => $this->operator->id])->assertStatus(409);
        $this->assertSame('active', $target->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_failed_copy_is_requeued_for_the_same_sandbox(): void
    {
        $source = $this->source();
        $target = $this->sandbox($source, 'degraded');
        $this->withToken('copy-api-test')->postJson('/api/internal/v1/environments/'.$source->id.'/copy', ['name' => $target->name, 'requested_by' => $this->operator->id])
            ->assertAccepted()->assertJsonPath('target_environment_id', $target->id);
        $this->assertSame('copying', $target->refresh()->status);
        $this->assertSame(1, Environment::query()->where('kind', 'sandbox')->count());
        Queue::assertPushed(CopyEnvironmentJob::class, 1);
    }

    public function test_shared_database_and_client_server_are_rejected(): void
    {
        $source = $this->source();
        $source->update(['database_name' => null]);
        $path = '/api/internal/v1/environments/'.$source->id.'/copy';
        $input = ['name' => 'Salinan uji', 'requested_by' => $this->operator->id];
        $this->withToken('copy-api-test')->postJson($path, $input)->assertStatus(409);
        $source->update(['hosting' => 'client_server']);
        $this->withToken('copy-api-test')->postJson($path, $input)->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_invalid_requester_is_rejected_before_a_target_is_created(): void
    {
        $source = $this->source();
        $this->withToken('copy-api-test')->postJson('/api/internal/v1/environments/'.$source->id.'/copy', ['name' => 'Salinan uji', 'requested_by' => 99999999])->assertUnprocessable();
        $this->assertSame(0, Environment::query()->where('kind', 'sandbox')->count());
        Queue::assertNothingPushed();
    }

    public function test_a_live_copy_operation_is_not_requeued(): void
    {
        $source = $this->source();
        $target = $this->sandbox($source, 'copying');
        EnvironmentOperation::create(['environment_id' => $target->id, 'source_environment_id' => $source->id, 'operation' => 'copy', 'status' => 'running', 'step' => 'restore', 'started_at' => now(), 'lease_until' => now()->addMinutes(30)]);
        $this->withToken('copy-api-test')->postJson('/api/internal/v1/environments/'.$source->id.'/copy', ['name' => $target->name, 'requested_by' => $this->operator->id])->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_worker_failure_before_command_start_is_recorded_without_downgrading_an_active_target(): void
    {
        $source = $this->source();
        $target = $this->sandbox($source, 'copying');
        $job = new CopyEnvironmentJob($source->id, $target->id, $target->name, $this->operator->id);
        $job->failed(new RuntimeException('test'));
        $this->assertSame('degraded', $target->refresh()->status);
        $this->assertSame('failed', EnvironmentOperation::query()->where('environment_id', $target->id)->sole()->status);
        $target->update(['status' => 'active']);
        $job->failed(new RuntimeException('late callback'));
        $this->assertSame('active', $target->refresh()->status);
    }

    public function test_overlap_middleware_blocks_a_second_worker_and_allows_it_after_release(): void
    {
        $source = $this->source();
        $target = $this->sandbox($source, 'copying');
        $job = new CopyEnvironmentJob($source->id, $target->id, $target->name, $this->operator->id);
        $middleware = $job->middleware()[0];
        $held = Cache::lock($middleware->getLockKey($job), 60);
        $this->assertTrue($held->get());
        $called = false;
        $middleware->handle($job, function () use (&$called): void {
            $called = true;
        });
        $this->assertFalse($called);
        $held->release();
        $middleware->handle($job, function () use (&$called): void {
            $called = true;
        });
        $this->assertTrue($called);
    }
}
