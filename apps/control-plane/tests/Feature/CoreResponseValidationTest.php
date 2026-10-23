<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Environments\EnvironmentRejected;
use ControlPlane\Environments\FleetFromCore;
use ControlPlane\Environments\InstalledModules;
use ControlPlane\Environments\ProvisionViaCore;
use ControlPlane\Environments\QueueUpgradeViaCore;
use ControlPlane\Models\Environment;
use ControlPlane\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class CoreResponseValidationTest extends TestCase
{
    /** @return array<string, array{mixed, int}> */
    public static function invalidResponses(): array
    {
        return [
            'html' => ['<html>upstream proxy</html>', 200],
            'empty' => [null, 204],
            'accepted without result' => [[], 202],
            'missing fields' => [[], 200],
            'wrong types' => [['status' => 'active', 'database' => [], 'modules' => false, 'queued' => false, 'queued_count' => 0, 'platform_fingerprint' => [], 'counts' => [], 'environments' => []], 200],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_fleet_responses_never_become_an_empty_healthy_fleet(mixed $body, int $status): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->expectException(EnvironmentRejected::class);
        (new FleetFromCore)();
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_provision_responses_do_not_claim_success(mixed $body, int $status): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('belum dapat');
        (new ProvisionViaCore)((new Environment)->forceFill(['id' => 'test-environment', 'status' => 'provisioning']));
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_upgrade_responses_do_not_claim_nothing_was_queued(mixed $body, int $status): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('belum dapat');
        (new QueueUpgradeViaCore)(null);
    }

    public function test_timeout_does_not_claim_the_mutation_was_cancelled(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('mungkin sudah diantrekan');
        (new QueueUpgradeViaCore)(null);
    }

    public function test_queue_count_must_match_the_actual_ids(): void
    {
        Http::fake(['*' => Http::response(['queued' => ['env-one'], 'queued_count' => 0], 202)]);
        $this->expectException(EnvironmentRejected::class);
        (new QueueUpgradeViaCore)(null);
    }

    public function test_an_unreachable_module_database_is_not_an_empty_installation_list(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new \RuntimeException('database unavailable'));
        $this->expectException(EnvironmentRejected::class);
        $this->expectExceptionMessage('belum dapat dibaca');
        (new InstalledModules)(new Environment(['kind' => 'production', 'tenant_id' => 'test-tenant']));
    }
}
