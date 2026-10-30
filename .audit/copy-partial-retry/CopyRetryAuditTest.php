<?php

declare(strict_types=1);

namespace Tests\Audit;

use App\Platform\ControlPlane\Console\ProvisionEnvironment;
use App\Platform\Environment\Models\EnvironmentOperation;
use App\Platform\Modules\Actions\InstallModule;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Platform\ControlPlane\CopyEnvironmentTest;

final class CopyRetryAuditTest extends CopyEnvironmentTest
{
    private function fixtureCall(string $method, array $arguments = []): mixed
    {
        return (new ReflectionMethod(CopyEnvironmentTest::class, $method))->invokeArgs($this, $arguments);
    }

    private function saveProof(string $name, array $data): void
    {
        $folder = getenv('AUDIT_RESULTS_DIR');
        if (is_string($folder) && is_dir($folder)) {
            file_put_contents($folder.'/'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT));
        }
    }

    private function partialCopy(string $table): array
    {
        $source = $this->fixtureCall('realProduction');
        $seed = $this->fixtureCall('seedSource', [$source]);
        $injected = false;
        DB::listen(function (QueryExecuted $query) use ($table, &$injected): void {
            if (! $injected && $query->connectionName === 'environment_copy' && str_starts_with($query->sql, 'update "'.$table.'"')) {
                $injected = true;
                throw new RuntimeException('Audit: proses terputus setelah sebagian pelucutan tersimpan.');
            }
        });
        $exit = $this->artisan('environment:copy', ['source' => $source->id, '--name' => 'Kotak Uji'])->run();
        $sandbox = $this->fixtureCall('lastSandbox');
        $copy = $this->fixtureCall('connectionTo', [ProvisionEnvironment::databaseName($sandbox), 'test_copy']);
        $this->assertTrue($injected, 'Kegagalan harus terjadi setelah SQL pelucutan pada database salinan asli.');
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame('degraded', $sandbox->status);
        $this->assertTrue($copy->getSchemaBuilder()->hasTable('migrations'));

        return [$source, $sandbox, $copy, $seed];
    }

    public function test_audit_control_partial_pool_release_is_safe_to_retry(): void
    {
        [$source, $sandbox, $copy, $seed] = $this->partialCopy('number_sequence_continuous_pool');
        $this->assertSame('available', $copy->table('number_sequence_continuous_pool')->value('status'));
        $this->assertSame('reserved', $copy->table('number_sequence_reservations')->where('id', $seed['reservasi'])->value('status'));
        $this->fixtureCall('assertCopySucceeded', [$source]);
        $after = [
            'sandbox_status' => $sandbox->refresh()->status,
            'pending_events' => $copy->table('outbox_events')->whereNull('published_at')->count(),
            'jobs' => $copy->table('jobs')->count(),
            'credentials' => $copy->table('app_service_credentials')->count(),
            'outbound_registry' => $copy->table('environments')->where('outbound_allowed', true)->count(),
            'reservation_status' => $copy->table('number_sequence_reservations')->where('id', $seed['reservasi'])->value('status'),
            'pool_status' => $copy->table('number_sequence_continuous_pool')->value('status'),
        ];
        $this->saveProof('control-partial-pool-retry', $after);
        $this->assertSame('active', $after['sandbox_status']);
        foreach (['pending_events', 'jobs', 'credentials', 'outbound_registry'] as $key) {
            $this->assertSame(0, $after[$key]);
        }
        $this->assertSame('cancelled', $after['reservation_status']);
        $this->assertSame('available', $after['pool_status']);
        $production = $this->fixtureCall('connectionTo', [$source->database_name, 'test_source']);
        $this->assertSame('reserved', $production->table('number_sequence_reservations')->where('id', $seed['reservasi'])->value('status'));
        $this->assertSame(1, $production->table('jobs')->count());
    }

    public function test_audit_retry_checks_the_restored_snapshot_not_the_later_live_source(): void
    {
        [$source, $sandbox, $copy] = $this->partialCopy('outbox_events');
        $snapshotCount = $copy->table('core_module_installations')->count();
        $this->app->make(InstallModule::class)->handle('contoh-a', $source->tenant_id, $source);
        $production = $this->fixtureCall('connectionTo', [$source->database_name, 'test_source']);
        $liveCount = $production->table('core_module_installations')->count();
        $this->assertGreaterThan($snapshotCount, $liveCount, 'Operasi pemasangan nyata harus mengubah jumlah module di sumber sesudah snapshot.');
        $exit = $this->artisan('environment:copy', ['source' => $source->id, '--name' => 'Kotak Uji'])->run();
        $operations = EnvironmentOperation::query()->where('environment_id', $sandbox->id)->orderBy('id')->get(['status', 'step', 'failure_message'])->toArray();
        $this->saveProof('retry-source-changed', [
            'retry_exit' => $exit,
            'sandbox_status' => $sandbox->refresh()->status,
            'snapshot_modules' => $snapshotCount,
            'target_modules_after_retry' => $copy->table('core_module_installations')->count(),
            'live_source_modules' => $liveCount,
            'remaining_jobs' => $copy->table('jobs')->count(),
            'remaining_credentials' => $copy->table('app_service_credentials')->count(),
            'operations' => $operations,
        ]);
        $this->assertSame(Command::SUCCESS, $exit, 'Salinan snapshot yang lengkap ditolak karena sumber berubah setelah restore pertama.');
    }

    public function test_audit_the_existing_prepare_button_cannot_activate_a_partially_disarmed_copy(): void
    {
        [$source, $sandbox, $copy] = $this->partialCopy('outbox_events');
        config()->set('coreerp.control_plane_token', 'audit-copy-provision-token');
        $response = $this->withToken('audit-copy-provision-token')->postJson('/api/internal/v1/environments/'.$sandbox->id.'/provision');
        $sandbox->refresh();
        $danger = [
            'jobs' => $copy->table('jobs')->count(),
            'credentials' => $copy->table('app_service_credentials')->count(),
            'outbound_registry' => $copy->table('environments')->where('outbound_allowed', true)->count(),
        ];
        $this->saveProof('prepare-button-after-copy-failed', [
            'http_status' => $response->status(),
            'sandbox_status' => $sandbox->status,
            'remaining' => $danger,
            'response_status' => $response->json('status'),
            'static_ui_path' => 'canProvision(degraded) -> Coba siapkan lagi -> /lingkungan/{id}/siapkan -> Core provision API',
        ]);
        $this->assertFalse($sandbox->status === 'active' && array_sum($danger) > 0, 'Tombol Siapkan mengaktifkan salinan yang belum selesai dilucuti.');
    }
}
