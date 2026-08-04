<?php

namespace App\Console\Commands;

use App\Models\AppRelease;
use App\Models\AppServiceCredential;
use App\Models\CoreApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class BootstrapLocalAppRuntimeCommand extends Command
{
    protected $signature = 'app:bootstrap-local-runtime
        {manifest : Path app.yaml yang sudah didaftarkan ke katalog}
        {--placement=pooled-primary}
        {--profile=pooled}
        {--api-image=}
        {--ui-image=}
        {--compose-project=erp}
        {--compose-file=compose.yaml}
        {--ui-entry=}
        {--api-service=}
        {--ui-service=}
        {--database-service=}
        {--service-token=}';

    protected $description = 'Catat runtime lokal yang sudah lolos migration dan health check';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('Perintah ini hanya boleh dijalankan pada environment lokal.');

            return self::FAILURE;
        }

        $manifestPath = (string) $this->argument('manifest');

        try {
            $manifest = is_file($manifestPath) ? Yaml::parseFile($manifestPath) : null;
        } catch (ParseException $exception) {
            $this->components->error('Manifest tidak valid: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($manifest)) {
            $this->components->error("Manifest tidak ditemukan: {$manifestPath}");

            return self::FAILURE;
        }

        $payload = [
            'app_id' => $manifest['id'] ?? null,
            'version' => $manifest['version'] ?? null,
            'placement' => $this->option('placement'),
            'profile' => $this->option('profile'),
            'api_image' => $this->option('api-image'),
            'ui_image' => $this->option('ui-image'),
            'compose_project' => $this->option('compose-project'),
            'compose_file' => $this->option('compose-file'),
            'ui_entry' => $this->option('ui-entry'),
            'api_service' => $this->option('api-service'),
            'ui_service' => $this->option('ui-service'),
            'database_service' => $this->option('database-service'),
        ];
        $identifier = ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9-]*$/'];
        $validator = Validator::make($payload, [
            'app_id' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'version' => ['required', 'string', 'max:40'],
            'placement' => $identifier,
            'profile' => ['required', 'in:pooled,isolated'],
            'api_image' => ['required', 'string', 'max:500', 'regex:/^.+@sha256:[a-f0-9]{64}$/'],
            'ui_image' => ['required', 'string', 'max:500', 'regex:/^.+@sha256:[a-f0-9]{64}$/'],
            'compose_project' => $identifier,
            'compose_file' => ['required', 'string', 'max:120', 'regex:#^(?!.*\.\.)[A-Za-z0-9_./-]+\.ya?ml$#'],
            'ui_entry' => ['required', 'string', 'max:2048', 'regex:#^http://localhost(?::[0-9]{1,5})?/#'],
            'api_service' => $identifier,
            'ui_service' => $identifier,
            'database_service' => $identifier,
        ]);

        if ($validator->fails()) {
            $this->components->error(implode(' ', $validator->errors()->all()));

            return self::FAILURE;
        }

        $data = $validator->validated();
        $app = CoreApp::query()->find($data['app_id']);

        if (! $app || $app->version !== $data['version']) {
            $this->components->error('Daftarkan manifest app ke katalog sebelum mencatat runtime lokal.');

            return self::FAILURE;
        }
        $providedToken = trim((string) $this->option('service-token'));
        $tokenParts = [];
        if ($providedToken !== '' && ! preg_match('/^([0-9A-HJKMNP-TV-Z]{26})\.([a-f0-9]{64})$/i', $providedToken, $tokenParts)) {
            $this->components->error('Token service lokal tidak valid.');

            return self::FAILURE;
        }

        $issuedToken = DB::transaction(function () use ($app, $data, $manifestPath, $providedToken, $tokenParts): ?string {
            $release = AppRelease::query()->firstOrNew([
                'app_id' => $app->id,
                'version' => $app->version,
            ]);
            $release->fill([
                'id' => $release->id ?: (string) Str::ulid(),
                'manifest_sha256' => hash_file('sha256', $manifestPath),
                'api_image' => $data['api_image'],
                'ui_image' => $data['ui_image'],
                'bundle_path' => "local/{$app->id}/{$app->version}",
                'compose_file' => $data['compose_file'],
                'compose_project' => $data['compose_project'],
                'api_service' => $data['api_service'],
                'ui_service' => $data['ui_service'],
                'database_service' => $data['database_service'],
                'status' => 'available',
            ])->save();

            $placement = DB::table('app_placements')
                ->where(['app_id' => $app->id, 'placement' => $data['placement']])
                ->first();
            $placementId = $placement?->id ?? (string) Str::ulid();
            DB::table('app_placements')->updateOrInsert(
                ['app_id' => $app->id, 'placement' => $data['placement']],
                [
                    'id' => $placementId,
                    'release_version' => $app->version,
                    'profile' => $data['profile'],
                    'ui_entry' => $data['ui_entry'],
                    'artifact_status' => 'placed',
                    'migration_status' => 'succeeded',
                    'runtime_status' => 'ready',
                    'ready_at' => now(),
                    'created_at' => $placement?->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
            DB::table('app_installations')->updateOrInsert(
                [
                    'app_placement_id' => $placementId,
                    'operation' => 'install',
                    'release_version' => $app->version,
                ],
                [
                    'id' => DB::table('app_installations')
                        ->where('app_placement_id', $placementId)
                        ->where('operation', 'install')
                        ->where('release_version', $app->version)
                        ->value('id') ?? (string) Str::ulid(),
                    'status' => 'succeeded',
                    'failure_stage' => null,
                    'failure_message' => null,
                    'started_at' => now(),
                    'finished_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            if ($providedToken !== '') {
                $credential = AppServiceCredential::query()->find($tokenParts[1]);
                if ($credential && $credential->app_id !== $app->id) {
                    throw new \RuntimeException('Token service sudah dimiliki app lain.');
                }
                AppServiceCredential::query()->updateOrCreate(
                    ['id' => $tokenParts[1]],
                    [
                        'app_id' => $app->id,
                        'tenant_id' => null,
                        'name' => 'Local development',
                        'secret_hash' => null,
                        'token_digest' => AppServiceCredential::digest($tokenParts[2]),
                        'status' => 'active',
                    ],
                );

                return null;
            }

            $secret = bin2hex(random_bytes(32));
            $credential = AppServiceCredential::query()->firstOrNew([
                'app_id' => $app->id,
                'tenant_id' => null,
                'name' => 'Local development',
            ]);
            $credential->fill([
                'secret_hash' => null,
                'token_digest' => AppServiceCredential::digest($secret),
                'status' => 'active',
            ])->save();

            return $credential->id.'.'.$secret;
        });

        if ($issuedToken) {
            $this->line('LOCAL_SERVICE_TOKEN='.$issuedToken);
        }
        $this->components->info("Runtime lokal {$app->id} siap pada {$data['placement']}.");

        return self::SUCCESS;
    }
}
