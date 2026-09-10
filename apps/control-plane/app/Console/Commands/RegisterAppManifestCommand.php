<?php

namespace App\Console\Commands;

use App\Actions\Provider\RegisterAppCatalog;
use App\Http\Requests\Provider\AppCatalogRequest;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as ValidatorInstance;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Mendaftarkan katalog app dari `app.yaml` module yang ada di dalam repo.
 *
 * Manifest adalah sumber kebenaran katalog. Command ini memakai aturan validasi,
 * normalisasi payload, dan action yang sama dengan endpoint provider
 * (`POST /api/v1/provider/apps`) supaya jalur CLI dan jalur API tidak bisa
 * saling menyimpang.
 *
 * **Kenapa tidak lagi menerima jalur berkas.** Dulu manifest tinggal di repo lain, jadi
 * satu-satunya cara menunjuknya adalah jalur yang diketik pemakai. Sekarang module ada di
 * dalam repo dan `ModuleRegistry` sudah menemukannya dengan memindai folder
 * `modules/<penerbit>/<module>/app.yaml`.
 * Jalur yang diketik pemakai berarti dua sumber kebenaran yang bisa berselisih: yang
 * didaftarkan ke katalog bisa berbeda dari yang dimuat runtime, dan tidak ada yang akan
 * menyadarinya. Registry yang sama dipakai keduanya.
 *
 * **Kenapa `semua()`, bukan `semuaTermasukYangSedangDipindah()`.** Katalog adalah daftar yang
 * boleh dipasang untuk tenant. Module yang sedang dipindah masuk belum boleh dipasang —
 * kodenya boleh dimuat supaya testnya berjalan, tetapi datanya belum tentu tersaring
 * `tenant_id`. Mendaftarkannya ke katalog berarti membuka pemasangannya.
 *
 * **Aman diulang.** Pembaruan on-premise dijalankan admin pelanggan, yang tidak punya cara
 * mengetahui apakah sebuah perintah sudah pernah jalan. Menjalankan perintah ini dua kali
 * harus menghasilkan keadaan yang sama persis dengan sekali.
 */
class RegisterAppManifestCommand extends Command
{
    protected $signature = 'app:register-manifest
        {module? : ID module yang didaftarkan; kosong berarti semua module yang dilayani}
        {--dry-run : Tampilkan hasil pemetaan tanpa menulis ke database}';

    protected $description = 'Daftarkan module yang ada di repo ke katalog control-plane dari app.yaml-nya';

    public function handle(ModuleRegistry $registry, RegisterAppCatalog $registrar): int
    {
        // Bila argumen terlihat seperti path file (mengandung pemisah direktori
        // atau berakhiran .yaml/.yml), daftar langsung dari file tersebut.
        // Ini memungkinkan start.ps1 mendaftarkan app external container yang
        // manifest-nya di-mount ke /workspace/manifests/.
        $arg = $this->argument('module');
        if (is_string($arg) && $arg !== '' && $this->tampaknyaPath($arg)) {
            return $this->daftarkanDariPath($arg, $registrar);
        }

        $module = $this->modulYangDidaftarkan($registry);

        if ($module === null) {
            return self::FAILURE;
        }

        if ($module === []) {
            $this->components->info('Tidak ada module yang perlu didaftarkan.');

            return self::SUCCESS;
        }

        foreach ($module as $satu) {
            if ($this->daftarkan($satu, $registrar) === self::FAILURE) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Apakah argumen yang diberikan tampak seperti path file, bukan ID module.
     */
    private function tampaknyaPath(string $nilai): bool
    {
        return str_contains($nilai, '/') || str_contains($nilai, '\\') || str_ends_with($nilai, '.yaml') || str_ends_with($nilai, '.yml');
    }

    /**
     * Daftarkan app external dari path file manifest secara langsung.
     * Dipakai untuk app container sendiri (app-erp-*) yang manifest-nya
     * di-mount ke /workspace/manifests/ saat development lokal.
     */
    private function daftarkanDariPath(string $path, RegisterAppCatalog $registrar): int
    {
        if (! is_file($path)) {
            $this->components->error("Manifest tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        try {
            /** @var mixed $manifest */
            $manifest = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            $this->components->error("Manifest {$path} bukan YAML yang valid: ".$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($manifest)) {
            $this->components->error("Manifest {$path} harus berupa map di level teratas.");

            return self::FAILURE;
        }

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
            $this->components->error("Manifest {$path} ditolak validasi katalog:");
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
            $request->reportsPayload(),
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
     * Module yang akan didaftarkan, atau null bila argumennya menunjuk module yang tidak
     * dilayani.
     *
     * @return list<ModuleManifest>|null
     */
    private function modulYangDidaftarkan(ModuleRegistry $registry): ?array
    {
        // Module contoh dilewati. Ia hidup di repo sebagai bahan uji penjaga batas, bukan
        // sebagai produk, dan katalog adalah daftar yang dilihat serta dipasang pelanggan.
        $dilayani = array_values(array_filter(
            $registry->semua(),
            static fn (ModuleManifest $m): bool => ! $m->bahanUjiInternal(),
        ));

        $id = $this->argument('module');

        if (! is_string($id) || $id === '') {
            return $dilayani;
        }

        $dipilih = array_values(array_filter($dilayani, static fn (ModuleManifest $m): bool => $m->id === $id));

        if ($dipilih === []) {
            $this->components->error(sprintf(
                'Module "%s" tidak ada di repo atau belum boleh dilayani. Yang bisa didaftarkan: %s.',
                $id,
                $dilayani === [] ? 'tidak ada' : implode(', ', array_map(static fn (ModuleManifest $m): string => $m->id, $dilayani)),
            ));

            return null;
        }

        return $dipilih;
    }

    private function daftarkan(ModuleManifest $module, RegisterAppCatalog $registrar): int
    {
        $berkas = $module->folder.'/app.yaml';

        try {
            /** @var mixed $manifest */
            $manifest = Yaml::parseFile($berkas);
        } catch (ParseException $exception) {
            $this->components->error("Manifest {$berkas} bukan YAML yang valid: ".$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($manifest)) {
            $this->components->error("Manifest {$berkas} harus berupa map di level teratas.");

            return self::FAILURE;
        }

        // Ditolak, bukan diabaikan diam-diam: penulis module yang masih menuliskan
        // `ui.entry` perlu tahu bahwa nilainya tidak lagi dipakai, agar tidak
        // mengira module-nya disajikan pada path yang ia tentukan sendiri.
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
            $this->components->error("Manifest {$berkas} ditolak validasi katalog:");
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
            $request->reportsPayload(),
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
     * app bila manifest tidak menyediakannya.
     *
     * @param  array<mixed>  $manifest
     * @return array<string, mixed>
     */
    private function toPayload(array $manifest): array
    {
        $id = $this->asString($manifest['id'] ?? null);

        return [
            'id' => $id,
            'name' => $this->asString($manifest['name'] ?? null) ?: Str::headline($id),
            'description' => $manifest['description'] ?? null,
            'version' => $this->asString($manifest['version'] ?? null),
            // Selalu null. Yang didaftarkan command ini hanya module, dan module berjalan di
            // dalam runtime Core memakai database Core. Manifest aset masih membawa
            // `database.logical_name` dari masa ia sebuah container; menyalinnya ke katalog
            // berarti mencatat nama database yang tidak ada dan tidak pernah dibuat siapa pun.
            'database_name' => null,
            // Manifest hanya menyatakan bahwa app punya UI, bukan di path mana ia
            // disajikan. Path itu milik platform karena ia bergantung pada
            // placement, yang berbeda antar deployment dari release yang sama.
            'has_ui' => isset($manifest['ui']) && is_array($manifest['ui']),
            'navigation' => $manifest['ui']['navigation'] ?? null,
            'repository_url' => $manifest['repository_url'] ?? null,
            'contract_url' => $manifest['contract_url'] ?? null,
            'dependsOn' => $manifest['dependsOn'] ?? [],
            'security' => $manifest['security'] ?? [],
            'number_sequences' => $manifest['number_sequences'] ?? [],
            'workflow_types' => $manifest['workflow_types'] ?? [],
            'reports' => $manifest['reports'] ?? [],
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
        $this->components->twoColumnDetail('<fg=gray>Database</>', $app['database_name'] ?? 'database Core (module)');
        $this->components->twoColumnDetail('<fg=gray>Punya UI</>', $app['has_ui'] ? 'ya' : 'tidak');
        $this->components->twoColumnDetail('<fg=gray>Entry point</>', (string) count($security['entry_points']));
        $this->components->twoColumnDetail('<fg=gray>Permission</>', (string) count($security['permissions']));
        $this->components->twoColumnDetail('<fg=gray>Privilege</>', (string) count($security['privileges']));
        $this->components->twoColumnDetail('<fg=gray>Duty</>', (string) count($security['duties']));
        $this->components->twoColumnDetail('<fg=gray>Dependency</>', (string) count($request->dependenciesPayload()));
        $this->components->twoColumnDetail('<fg=gray>Reference nomor</>', (string) count($request->numberSequenceReferencesPayload()));
        $this->components->twoColumnDetail('<fg=gray>Jenis workflow</>', (string) count($request->workflowTypesPayload()));
        $this->components->twoColumnDetail('<fg=gray>Policy data</>', (string) count($request->dataPoliciesPayload()));
        $this->components->twoColumnDetail('<fg=gray>Laporan</>', (string) count($request->reportsPayload()));
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
