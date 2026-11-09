<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\ControlPlane;

use App\Platform\ControlPlane\Console\Concerns\HoldsEnvironmentOperation;
use App\Platform\ControlPlane\Console\CopyEnvironment;
use App\Platform\ControlPlane\Console\ProvisionEnvironment;
use App\Platform\ControlPlane\Models\Client;
use App\Platform\Environment\Models\Environment;
use App\Platform\Environment\Models\EnvironmentOperation;
use App\Platform\Tenant\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class EnvironmentLeaseProbe extends Command
{
    use HoldsEnvironmentOperation;

    protected $signature = 'audit:environment-lease';

    protected function operationLeaseMinutes(): int
    {
        return 30;
    }

    public function reclaim(Environment $environment): bool
    {
        return $this->takeOverExpired($environment);
    }

    public function claim(Environment $environment): ?EnvironmentOperation
    {
        return $this->openOperation($environment, 'provision');
    }
}

final class EnvironmentLeaseTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): Environment
    {
        $client = Client::create(['legal_name' => 'Audit lease', 'slug' => 'audit-lease', 'status' => 'active']);
        $tenant = Tenant::create(['client_id' => $client->id, 'name' => 'Audit lease', 'slug' => 'audit-lease', 'status' => 'active']);

        return Environment::create(['tenant_id' => $tenant->id, 'kind' => 'production', 'name' => 'Audit', 'slug' => 'audit-lease', 'database_name' => null, 'status' => 'provisioning', 'outbound_allowed' => true]);
    }

    private function probe(): EnvironmentLeaseProbe
    {
        $probe = new EnvironmentLeaseProbe;
        $input = new ArrayInput([]);
        $probe->setLaravel($this->app);
        $probe->setInput($input);
        $probe->setOutput(new OutputStyle($input, new BufferedOutput));

        return $probe;
    }

    private function operation(Environment $environment, bool $expired): EnvironmentOperation
    {
        return EnvironmentOperation::create(['environment_id' => $environment->id, 'operation' => 'provision', 'status' => 'running', 'step' => 'migration', 'started_at' => now()->subMinutes(35), 'lease_until' => $expired ? now()->subMinute() : now()->addMinutes(20)]);
    }

    public function test_control_a_live_lease_is_not_taken_over(): void
    {
        $environment = $this->fixture();
        $operation = $this->operation($environment, false);
        $this->assertFalse($this->probe()->reclaim($environment));
        $this->assertSame('running', $operation->refresh()->status);
    }

    public function test_control_an_expired_dead_operation_can_be_replaced(): void
    {
        $environment = $this->fixture();
        $old = $this->operation($environment, true);
        $new = $this->probe()->claim($environment);
        $this->assertNotNull($new);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('failed', $old->refresh()->status);
        $this->assertSame('running', $new->status);
    }

    public function test_a_renewal_after_the_expiry_read_prevents_takeover(): void
    {
        $source = $this->fixture();
        $source->update(['status' => 'active']);
        $environment = Environment::create([
            'tenant_id' => $source->tenant_id,
            'kind' => 'sandbox',
            'name' => 'Audit copy',
            'slug' => 'audit-copy',
            'source_environment_id' => $source->id,
            'status' => 'copying',
            'outbound_allowed' => false,
        ]);
        $old = EnvironmentOperation::create([
            'environment_id' => $environment->id,
            'source_environment_id' => $source->id,
            'operation' => 'copy',
            'status' => 'running',
            'step' => 'restore',
            'started_at' => now()->subMinutes(35),
            'lease_until' => now()->subMinute(),
        ]);
        $copy = $this->app->make(CopyEnvironment::class);
        (new \ReflectionProperty(CopyEnvironment::class, 'operation'))->setValue($copy, $old);
        $renewed = false;
        $futureLeaseWritten = false;
        DB::listen(function (QueryExecuted $query) use ($old, $copy, &$renewed, &$futureLeaseWritten): void {
            if (! $renewed && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"environment_operations"') && str_contains($query->sql, '"lease_until" <')) {
                $renewed = true;
                (new ReflectionMethod(CopyEnvironment::class, 'renewLease'))->invoke($copy);
                $futureLeaseWritten = $old->fresh()->lease_until->isFuture();
            }
        });
        $taken = $this->probe()->reclaim($environment);
        $old->refresh();
        $this->assertTrue($renewed, 'Hook tidak mencapai SELECT lease kedaluwarsa.');
        $this->assertTrue($futureLeaseWritten, 'Heartbeat copy tidak menulis lease yang masih hidup.');
        $this->assertFalse($taken, 'Lease yang sudah diperpanjang sebelum UPDATE tetap direbut.');
        $this->assertSame('running', $old->status);
    }

    public function test_a_retired_worker_cannot_downgrade_a_successful_replacement(): void
    {
        $environment = $this->fixture();
        $old = $this->operation($environment, true);
        $new = $this->probe()->claim($environment);
        $this->assertNotNull($new);
        $this->assertSame('failed', $old->fresh()->status);
        $new->update(['status' => 'succeeded', 'finished_at' => now(), 'lease_until' => null]);
        $environment->update(['status' => 'active']);
        $late = new ReflectionMethod(ProvisionEnvironment::class, 'markFailed');
        $late->invoke($this->app->make(ProvisionEnvironment::class), $environment, $old, 'migration', new RuntimeException('Pekerja lama kembali setelah lease direbut.'));
        $environment->refresh();
        $this->assertSame('active', $environment->status, 'Pekerja lama menurunkan environment setelah pengganti berhasil.');
    }

    public function test_a_current_worker_records_its_failure_and_degrades_the_environment(): void
    {
        $environment = $this->fixture();
        $operation = $this->operation($environment, false);
        (new ReflectionMethod(ProvisionEnvironment::class, 'markFailed'))->invoke(
            $this->app->make(ProvisionEnvironment::class), $environment, $operation, 'migration', new RuntimeException('Uji gagal'),
        );
        $this->assertSame('degraded', $environment->refresh()->status);
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame('Uji gagal', $operation->failure_message);
        $this->assertNull($operation->lease_until);
    }

    public function test_a_retired_copy_cannot_renew_its_lease(): void
    {
        $environment = $this->fixture();
        $operation = $this->operation($environment, true);
        $replacement = $this->probe()->claim($environment);
        $copy = $this->app->make(CopyEnvironment::class);
        (new \ReflectionProperty(CopyEnvironment::class, 'operation'))->setValue($copy, $operation);
        try {
            (new ReflectionMethod(CopyEnvironment::class, 'renewLease'))->invoke($copy);
            $this->fail('Heartbeat operasi yang sudah diambil alih harus berhenti.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('diambil alih', $error->getMessage());
        }
        $this->assertNull($operation->refresh()->lease_until);
        $this->assertSame('failed', $operation->status);
        $this->assertSame('running', $replacement?->refresh()->status);
    }

    public function test_source_copy_takeover_rechecks_a_renewed_lease(): void
    {
        $source = $this->fixture();
        $source->update(['status' => 'active']);
        $target = Environment::create([
            'tenant_id' => $source->tenant_id, 'kind' => 'sandbox', 'name' => 'Salinan',
            'slug' => 'salinan', 'source_environment_id' => $source->id, 'status' => 'copying', 'outbound_allowed' => false,
        ]);
        $operation = EnvironmentOperation::create([
            'environment_id' => $target->id, 'source_environment_id' => $source->id,
            'operation' => 'copy', 'status' => 'running', 'step' => 'restore',
            'started_at' => now()->subHour(), 'lease_until' => now()->subMinute(),
        ]);
        $copy = $this->app->make(CopyEnvironment::class);
        (new \ReflectionProperty(CopyEnvironment::class, 'operation'))->setValue($copy, $operation);
        $renewed = false;
        DB::listen(function (QueryExecuted $query) use ($copy, &$renewed): void {
            if (! $renewed && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"source_environment_id" =')) {
                $renewed = true;
                (new ReflectionMethod(CopyEnvironment::class, 'renewLease'))->invoke($copy);
            }
        });
        $allowed = (new ReflectionMethod(CopyEnvironment::class, 'noOtherCopyRunning'))->invoke($copy, $source);
        $this->assertTrue($renewed);
        $this->assertFalse($allowed);
        $this->assertSame('running', $operation->refresh()->status);
        $this->assertTrue($operation->lease_until?->isFuture());
    }
}
