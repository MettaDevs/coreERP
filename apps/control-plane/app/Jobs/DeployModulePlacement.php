<?php

namespace App\Jobs;

use App\Models\CoreModule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class DeployModulePlacement implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public string $moduleId, public string $placement) {}

    public function uniqueId(): string
    {
        return $this->moduleId.':'.$this->placement;
    }

    public function handle(): void
    {
        $this->validateIdentifier($this->moduleId, 80, 'module');
        $this->validateIdentifier($this->placement, 120, 'placement');

        $module = CoreModule::query()->where('status', 'available')->findOrFail($this->moduleId);
        $profile = $this->entitledProfile();
        $placementQuery = DB::table('module_placements')
            ->where('module_id', $module->id)
            ->where('placement', $this->placement);

        if ($placementQuery->exists()) {
            $existing = $placementQuery->first();
            if ($existing->release_version === $module->version && $existing->runtime_status === 'ready') {
                return;
            }
            if ($existing->release_version !== $module->version) {
                throw new RuntimeException('Module upgrade requires the backup and compatibility workflow.');
            }
            if ($existing->profile !== $profile) {
                throw new RuntimeException('A module placement cannot change deployment profile in place.');
            }
            $placementId = $existing->id;
            $createdAt = $existing->created_at;
        } else {
            $placementId = (string) Str::ulid();
            $createdAt = now();
        }

        $now = now();
        DB::table('module_placements')->updateOrInsert(
            ['module_id' => $module->id, 'placement' => $this->placement],
            [
                'id' => $placementId,
                'release_version' => $module->version,
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
        DB::table('module_installations')->insert([
            'id' => $installationId,
            'module_placement_id' => $placementId,
            'operation' => 'install',
            'release_version' => $module->version,
            'status' => 'running',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stage = 'validation';
        try {
            $release = $this->release($module);
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
            DB::table('module_installations')->where('id', $installationId)->update([
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
            DB::table('module_installations')->where('id', $installationId)->update([
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
            ->join('tenant_module_entitlements as entitlements', 'entitlements.tenant_id', '=', 'deployments.tenant_id')
            ->where('deployments.placement', $this->placement)
            ->where('deployments.status', 'active')
            ->where('entitlements.module_id', $this->moduleId)
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
    private function release(CoreModule $module): array
    {
        $moduleRoot = realpath(base_path('../../modules/coreerp/'.$module->id));
        $allowedRoot = realpath(base_path('../../modules/coreerp'));
        if (! $moduleRoot || ! $allowedRoot || ! str_starts_with($moduleRoot, $allowedRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Module release directory is missing.');
        }

        $manifestFile = $moduleRoot.DIRECTORY_SEPARATOR.'module.yaml';
        $composeFile = $moduleRoot.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'compose.fragment.yaml';
        $migrator = $moduleRoot.DIRECTORY_SEPARATOR.'deploy'.DIRECTORY_SEPARATOR.'migrate.sh';
        if (! is_file($manifestFile) || ! is_file($composeFile) || ! is_file($migrator)) {
            throw new RuntimeException('Module release is incomplete.');
        }

        $manifest = Yaml::parseFile($manifestFile);
        $compose = Yaml::parseFile($composeFile);
        $api = $module->id.'-api';
        $ui = $module->id.'-ui';
        $db = $module->id.'-db';
        if (! is_array($manifest) || ! is_array($compose)
            || ($manifest['id'] ?? null) !== $module->id
            || ($manifest['version'] ?? null) !== $module->version
            || ($manifest['database']['logicalName'] ?? null) !== $module->database_name
            || ($manifest['database']['migrations'] ?? null) !== 'database/migrations'
            || ($compose['services'][$api]['image'] ?? null) !== ($manifest['api']['image'] ?? null)
            || ($compose['services'][$ui]['image'] ?? null) !== ($manifest['ui']['image'] ?? null)
            || ! isset($compose['services'][$api]['healthcheck'], $compose['services'][$ui]['healthcheck'], $compose['services'][$db]['healthcheck'])) {
            throw new RuntimeException('Module manifest and deployment artifact do not match.');
        }

        return [
            'deploy_path' => dirname($composeFile),
            'compose_file' => $composeFile,
            'project' => 'coreerp-'.$module->id.'-'.substr(hash('sha256', $this->placement), 0, 10),
            'api_service' => $api,
            'ui_service' => $ui,
            'db_service' => $db,
        ];
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
        DB::table('module_placements')->where('id', $placementId)->update([
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
