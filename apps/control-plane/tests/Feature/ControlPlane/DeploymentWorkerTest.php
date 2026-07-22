<?php

namespace Tests\Feature\ControlPlane;

use App\Jobs\DeployModulePlacement;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class DeploymentWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ModuleCatalogSeeder::class);
        config()->set('coreerp.deployment.profile', 'pooled');
        config()->set('coreerp.deployment.placement', 'pooled-primary');
        config()->set('coreerp.deployment.pull_images', true);
        Queue::fake();
    }

    public function test_registration_binds_the_tenant_and_queues_placement_deployment(): void
    {
        $this->registerProcurement();

        $this->assertDatabaseHas('tenant_deployments', [
            'profile' => 'pooled',
            'placement' => 'pooled-primary',
            'status' => 'active',
        ]);
        Queue::assertPushed(
            DeployModulePlacement::class,
            fn (DeployModulePlacement $job): bool => $job->moduleId === 'procurement' && $job->placement === 'pooled-primary',
        );
    }

    public function test_worker_only_marks_the_placement_ready_after_every_stage_succeeds(): void
    {
        $this->registerProcurement();
        Process::fake();
        Process::preventStrayProcesses();

        $job = new DeployModulePlacement('procurement', 'pooled-primary');
        $job->handle();
        $job->handle();

        $placement = DB::table('module_placements')->where('module_id', 'procurement')->first();
        $this->assertNotNull($placement);
        $this->assertSame('placed', $placement->artifact_status);
        $this->assertSame('succeeded', $placement->migration_status);
        $this->assertSame('ready', $placement->runtime_status);
        $this->assertNotNull($placement->ready_at);
        $this->assertDatabaseHas('module_installations', [
            'module_placement_id' => $placement->id,
            'operation' => 'install',
            'status' => 'succeeded',
        ]);
        Process::assertRanTimes(function ($process): bool {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($command, 'docker compose');
        }, 4);
    }

    public function test_worker_records_a_failed_migration_without_faking_readiness(): void
    {
        $this->registerProcurement();
        $sequence = Process::sequence([
            Process::result(),
            Process::result(),
            Process::result(errorOutput: 'migration failed', exitCode: 1),
        ]);
        Process::fake(fn () => $sequence());
        Process::preventStrayProcesses();

        try {
            (new DeployModulePlacement('procurement', 'pooled-primary'))->handle();
            $this->fail('The failed migration must fail the deployment job.');
        } catch (RuntimeException) {
            // Expected: the queue may retry, while registry state remains truthful.
        }

        $placement = DB::table('module_placements')->where('module_id', 'procurement')->first();
        $this->assertNotNull($placement);
        $this->assertSame('failed', $placement->migration_status);
        $this->assertSame('pending', $placement->runtime_status);
        $this->assertNull($placement->ready_at);
        $this->assertDatabaseHas('module_installations', [
            'module_placement_id' => $placement->id,
            'status' => 'failed',
            'failure_stage' => 'migration',
        ]);
    }

    public function test_worker_rejects_an_upgrade_until_backup_and_compatibility_are_available(): void
    {
        $this->registerProcurement();
        Process::fake();
        (new DeployModulePlacement('procurement', 'pooled-primary'))->handle();

        DB::table('modules')->where('id', 'procurement')->update(['version' => '0.2.0']);
        Process::fake();
        Process::preventStrayProcesses();

        $this->expectException(RuntimeException::class);
        try {
            (new DeployModulePlacement('procurement', 'pooled-primary'))->handle();
        } finally {
            Process::assertRanTimes(function ($process): bool {
                $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                return str_contains($command, 'docker compose');
            }, 4);
            $this->assertDatabaseHas('module_placements', [
                'module_id' => 'procurement',
                'release_version' => '0.1.0',
                'runtime_status' => 'ready',
            ]);
        }
    }

    private function registerProcurement(): void
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Deployment Owner',
            'business_name' => 'PT Deployment',
            'module_ids' => ['procurement'],
            'email' => 'deployment@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();
    }
}
