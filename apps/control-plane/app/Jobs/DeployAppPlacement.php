<?php

namespace App\Jobs;

use App\Models\CoreApp;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DeployAppPlacement implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public string $appId, public string $placement) {}

    public function uniqueId(): string
    {
        return $this->appId.':'.$this->placement;
    }

    public function handle(): void
    {
        $this->validateIdentifier($this->appId, 80, 'app');
        $this->validateIdentifier($this->placement, 120, 'placement');

        $app = CoreApp::query()->where('status', 'available')->findOrFail($this->appId);
        $profile = $this->entitledProfile();
        $placementQuery = DB::table('app_placements')
            ->where('app_id', $app->id)
            ->where('placement', $this->placement);

        if ($placementQuery->exists()) {
            $existing = $placementQuery->first();
            if ($existing->release_version === $app->version && $existing->runtime_status === 'ready') {
                return;
            }
            if ($existing->release_version !== $app->version) {
                throw new RuntimeException('App upgrade requires the backup and compatibility workflow.');
            }
            if ($existing->profile !== $profile) {
                throw new RuntimeException('An app placement cannot change deployment profile in place.');
            }
            $placementId = $existing->id;
            $createdAt = $existing->created_at;
        } else {
            $placementId = (string) Str::ulid();
            $createdAt = now();
        }

        $now = now();
        DB::table('app_placements')->updateOrInsert(
            ['app_id' => $app->id, 'placement' => $this->placement],
            [
                'id' => $placementId,
                'release_version' => $app->version,
                'profile' => $profile,
                'artifact_status' => 'pending',
                'migration_status' => 'pending',
                'runtime_status' => 'pending',
                'ready_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $now,
            ],
        );

        $installationId = (string) Str::ulid();
        DB::table('app_installations')->insert([
            'id' => $installationId,
            'app_placement_id' => $placementId,
            'operation' => 'install',
            'release_version' => $app->version,
            'status' => 'running',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stage = 'validation';
        try {
            $release = $this->release($app);
            $process = Process::path($release['deploy_path'])->timeout(600);
            $base = ['docker', 'compose', '--project-name', $release['project'], '--file', $release['compose_file']];

            $stage = 'artifact';
            $this->setPlacement($placementId, ['artifact_status' => 'placing']);
            if (config('coreerp.deployment.pull_images', true)) {
                $this->run($process, [...$base, 'pull', $release['api_service'], $release['ui_service'], $release['db_service']], $stage);
            }

            $stage = 'migration';
            $this->setPlacement($placementId, ['migration_status' => 'running']);
            $this->run($process, [...$base, 'up', '-d', '--wait', '--wait-timeout', '300', $release['db_service']], $stage);
            $this->run($process, [...$base, 'exec', '-T', $release['db_service'], 'sh', '/coreerp/migrate.sh'], $stage);
            $this->setPlacement($placementId, ['migration_status' => 'succeeded']);

            $stage = 'runtime';
            $this->setPlacement($placementId, ['runtime_status' => 'starting']);
            $this->run($process, [...$base, 'up', '-d', '--wait', '--wait-timeout', '300', $release['api_service'], $release['ui_service']], $stage);

            $finished = now();
            $this->setPlacement($placementId, [
                'artifact_status' => 'placed',
                'runtime_status' => 'ready',
                'ready_at' => $finished,
            ]);
            DB::table('app_installations')->where('id', $installationId)->update([
                'status' => 'succeeded',
                'finished_at' => $finished,
                'updated_at' => $finished,
            ]);
        } catch (Throwable $exception) {
            $message = $stage.' stage failed.';
            $failedColumn = match ($stage) {
                'migration' => 'migration_status',
                'runtime' => 'runtime_status',
                default => 'artifact_status',
            };
            $this->setPlacement($placementId, [$failedColumn => 'failed', 'ready_at' => null]);
            DB::table('app_installations')->where('id', $installationId)->update([
                'status' => 'failed',
                'failure_stage' => $stage,
                'failure_message' => $message,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            throw new RuntimeException($message, previous: $exception);
        }
    }

    private function entitledProfile(): string
    {
        $deployment = DB::table('tenant_deployments as deployments')
            ->join('tenant_app_entitlements as entitlements', 'entitlements.tenant_id', '=', 'deployments.tenant_id')
            ->where('deployments.placement', $this->placement)
            ->where('deployments.status', 'active')
            ->where('entitlements.app_id', $this->appId)
            ->where('entitlements.status', 'active')
            ->where('entitlements.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('entitlements.ends_at')->orWhere('entitlements.ends_at', '>', now()))
            ->first(['deployments.profile']);

        if (! $deployment || ! in_array($deployment->profile, ['pooled', 'isolated'], true)) {
            throw new RuntimeException('No active entitlement is bound to this placement.');
        }

        return $deployment->profile;
    }

    /** @return array{deploy_path:string,compose_file:string,project:string,api_service:string,ui_service:string,db_service:string} */
    private function release(CoreApp $app): array
    {
        throw new RuntimeException("Release artifact for {$app->id} has not been registered.");
    }

    /** @param list<string> $command */
    private function run(PendingProcess $process, array $command, string $stage): void
    {
        $result = $process->run($command);
        if ($result->failed()) {
            throw new RuntimeException($stage.' command failed with exit code '.$result->exitCode().'.');
        }
    }

    /** @param array<string, mixed> $values */
    private function setPlacement(string $placementId, array $values): void
    {
        DB::table('app_placements')->where('id', $placementId)->update([
            ...$values,
            'updated_at' => now(),
        ]);
    }

    private function validateIdentifier(string $value, int $max, string $name): void
    {
        if (strlen($value) > $max || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $value)) {
            throw new RuntimeException('Invalid '.$name.' identifier.');
        }
    }
}
