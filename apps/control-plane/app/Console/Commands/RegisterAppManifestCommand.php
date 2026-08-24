<?php

namespace App\Console\Commands;

use App\Actions\Provider\RegisterAppCatalog;
use App\Http\Requests\Provider\AppCatalogRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as ValidatorInstance;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Mendaftarkan katalog app dari file manifest `app.yaml`.
 *
 * Manifest adalah sumber kebenaran katalog. Command ini memakai aturan validasi,
 * normalisasi payload, dan action yang sama dengan endpoint provider
 * (`POST /api/v1/provider/apps`) supaya jalur CLI dan jalur API tidak bisa
 * saling menyimpang.
 */
class RegisterAppManifestCommand extends Command
{
    protected $signature = 'app:register-manifest
        {path : Path ke file app.yaml}
        {--name= : Nama produk; menimpa `name` di manifest}
        {--description= : Deskripsi produk; menimpa `description` di manifest}
        {--repository-url= : URL repository (https://)}
        {--contract-url= : URL contract yang dipublish (https://)}
        {--dry-run : Tampilkan hasil pemetaan tanpa menulis ke database}';

    protected $description = 'Daftarkan app ke katalog control-plane dari manifest app.yaml';

    public function handle(RegisterAppCatalog $registrar): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->components->error("Manifest tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        try {
            $manifest = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            $this->components->error('Manifest bukan YAML yang valid: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($manifest)) {
            $this->components->error('Manifest harus berupa map di level teratas.');

            return self::FAILURE;
        }

        // Ditolak, bukan diabaikan diam-diam: penulis app yang masih menuliskan
        // `ui.entry` perlu tahu bahwa nilainya tidak lagi dipakai, agar tidak
        // mengira app-nya disajikan pada path yang ia tentukan sendiri.
        if (isset($manifest['ui']['entry'])) {
            $this->components->error(
                'Manifest tidak boleh lagi mendeklarasikan `ui.entry`. Path konten UI '
                .'ditentukan platform dari app dan placement; hapus baris itu dari app.yaml.'
            );

            return self::FAILURE;
        }

        $request = $this->requestFor($this->toPayload($manifest));
        $validator = $this->validatorFor($request);

        if ($validator->fails()) {
            $this->components->error('Manifest ditolak validasi katalog:');
            $this->components->bulletList($validator->errors()->all());

            return self::FAILURE;
        }

        $this->summarize($request);

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: tidak ada perubahan yang ditulis.');

            return self::SUCCESS;
        }

        $app = $registrar->handle(
            $request->appPayload(),
            $request->securityPayload(),
            $request->numberSequenceReferencesPayload(),
            $request->workflowTypesPayload(),
            $request->dataPoliciesPayload(),
            $request->dependenciesPayload(),
        );

        $this->components->info(sprintf(
            'App "%s" (%s) %s di katalog dengan status %s.',
            $app->name,
            $app->id,
            $app->wasRecentlyCreated ? 'didaftarkan' : 'diperbarui',
            $app->status,
        ));

        return self::SUCCESS;
    }

    /**
     * Memetakan manifest ke bentuk payload endpoint provider.
     *
     * Manifest tidak mendeklarasikan nama produk yang layak tampil, sedangkan
     * halaman pendaftaran menampilkannya; karena itu nama diturunkan dari ID
     * app bila manifest maupun opsi tidak menyediakannya.
     *
     * @param  array<mixed>  $manifest
     * @return array<string, mixed>
     */
    private function toPayload(array $manifest): array
    {
        $id = $this->asString($manifest['id'] ?? null);

        return [
            'id' => $id,
            'name' => $this->stringOption('name') ?? $this->asString($manifest['name'] ?? null) ?: Str::headline($id),
            'description' => $this->stringOption('description') ?? ($manifest['description'] ?? null),
            'version' => $this->asString($manifest['version'] ?? null),
            'database_name' => $this->asString($manifest['database']['logical_name'] ?? null),
            // Manifest hanya menyatakan bahwa app punya UI, bukan di path mana ia
            // disajikan. Path itu milik platform karena ia bergantung pada
            // placement, yang berbeda antar deployment dari release yang sama.
            'has_ui' => isset($manifest['ui']) && is_array($manifest['ui']),
            'navigation' => $manifest['ui']['navigation'] ?? null,
            'repository_url' => $this->stringOption('repository-url') ?? ($manifest['repository_url'] ?? null),
            'contract_url' => $this->stringOption('contract-url') ?? ($manifest['contract_url'] ?? null),
            'dependsOn' => $manifest['dependsOn'] ?? [],
            'security' => $manifest['security'] ?? [],
            'number_sequences' => $manifest['number_sequences'] ?? [],
            'workflow_types' => $manifest['workflow_types'] ?? [],
        ];
    }

    /**
     * Membungkus payload sebagai request provider agar normalisasi dan
     * pemeriksaan lintas lapis milik AppCatalogRequest dipakai apa adanya.
     *
     * @param  array<string, mixed>  $payload
     */
    private function requestFor(array $payload): AppCatalogRequest
    {
        $request = AppCatalogRequest::create('/api/v1/provider/apps', 'POST', $payload);
        $request->setContainer($this->laravel);

        return $request;
    }

    private function validatorFor(AppCatalogRequest $request): ValidatorInstance
    {
        /** @var ValidatorInstance $validator */
        $validator = Validator::make($request->all(), $request->rules());

        foreach ($request->after() as $hook) {
            $validator->after($hook);
        }

        return $validator;
    }

    private function summarize(AppCatalogRequest $request): void
    {
        $app = $request->appPayload();
        $security = $request->securityPayload();

        $this->components->twoColumnDetail('<fg=gray>ID</>', $app['id']);
        $this->components->twoColumnDetail('<fg=gray>Nama</>', $app['name']);
        $this->components->twoColumnDetail('<fg=gray>Versi</>', $app['version']);
        $this->components->twoColumnDetail('<fg=gray>Database</>', $app['database_name']);
        $this->components->twoColumnDetail('<fg=gray>Punya UI</>', $app['has_ui'] ? 'ya' : 'tidak');
        $this->components->twoColumnDetail('<fg=gray>Entry point</>', (string) count($security['entry_points']));
        $this->components->twoColumnDetail('<fg=gray>Permission</>', (string) count($security['permissions']));
        $this->components->twoColumnDetail('<fg=gray>Privilege</>', (string) count($security['privileges']));
        $this->components->twoColumnDetail('<fg=gray>Duty</>', (string) count($security['duties']));
        $this->components->twoColumnDetail('<fg=gray>Dependency</>', (string) count($request->dependenciesPayload()));
        $this->components->twoColumnDetail('<fg=gray>Reference nomor</>', (string) count($request->numberSequenceReferencesPayload()));
        $this->components->twoColumnDetail('<fg=gray>Jenis workflow</>', (string) count($request->workflowTypesPayload()));
        $this->components->twoColumnDetail('<fg=gray>Policy data</>', (string) count($request->dataPoliciesPayload()));
    }

    /** Opsi CLI yang kosong dianggap tidak diisi sehingga manifest tetap dipakai. */
    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
