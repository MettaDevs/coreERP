<?php

namespace App\Console\Commands;

use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Actions\NumberSequence\NumberSequenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Drives load against the number sequence service and reports latency percentiles.
 *
 * PHP cannot generate concurrency inside one process, so real contention comes from running several copies of this
 * command at once, each with a distinct --worker. Every worker writes a JSON result file that the driver aggregates.
 *
 *   DB_TEST_SCHEMA=coreerp_load php artisan number-sequences:load-run --worker=0 --requests=250
 */
class RunNumberSequenceLoad extends Command
{
    protected $signature = 'number-sequences:load-run
        {--requests=250 : Requests this worker performs}
        {--worker=0 : Worker index, used to pick a disjoint tenant slice}
        {--scenario=issue : issue | sweep (per-tenant page load) | sweep-all (deploy-time backfill)}
        {--database=pgsql_test : Connection to drive}
        {--out= : Write the JSON result here}';

    protected $description = 'Run a number sequence load scenario and report latency percentiles.';

    public function handle(NumberSequenceService $service, EnsureNumberSequenceDrafts $drafts): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run load tests in production.');

            return self::FAILURE;
        }

        config(['database.default' => (string) $this->option('database')]);
        $requests = (int) $this->option('requests');
        $worker = (int) $this->option('worker');
        $scenario = (string) $this->option('scenario');

        $tenantIds = DB::table('tenants')->orderBy('id')->pluck('id')->all();
        if ($tenantIds === []) {
            $this->error('No tenants seeded. Run number-sequences:load-seed first.');

            return self::FAILURE;
        }

        $latencies = [];
        $errors = [];
        $started = hrtime(true);

        for ($index = 0; $index < $requests; $index++) {
            // Spread workers across the estate so they contend on the database, not on one tenant's row locks.
            $tenantId = $tenantIds[($worker * 7919 + $index * 31) % count($tenantIds)];
            $context = ['tenant_id' => $tenantId, 'app_id' => 'load-app'];
            $reference = 'load-app.document-'.(($index % 3) + 1);
            $begin = hrtime(true);

            try {
                if ($scenario === 'matrix') {
                    $this->matrixRequest($service, $worker, $index);
                } elseif ($scenario === 'sweep') {
                    // What a tenant admin actually triggers by opening the settings page.
                    $drafts->forReadyTenant($tenantId);
                } elseif ($scenario === 'sweep-all') {
                    // What runs once when an app becomes ready for every tenant. Expensive by nature, but not on a
                    // request path.
                    $drafts->forReadyApp('load-app');
                } else {
                    $service->issue($context, $reference, "w{$worker}-r{$index}");
                }
                $latencies[] = (hrtime(true) - $begin) / 1_000_000;
            } catch (Throwable $exception) {
                $key = $exception::class.': '.substr($exception->getMessage(), 0, 120);
                $errors[$key] = ($errors[$key] ?? 0) + 1;
            }
        }

        $wallMs = (hrtime(true) - $started) / 1_000_000;
        $result = [
            'worker' => $worker,
            'scenario' => $scenario,
            'requests' => $requests,
            'succeeded' => count($latencies),
            'failed' => array_sum($errors),
            'wall_ms' => round($wallMs, 1),
            'throughput_per_sec' => $wallMs > 0 ? round(count($latencies) / ($wallMs / 1000), 1) : 0,
            'p50_ms' => $this->percentile($latencies, 50),
            'p95_ms' => $this->percentile($latencies, 95),
            'p99_ms' => $this->percentile($latencies, 99),
            'max_ms' => $latencies === [] ? null : round(max($latencies), 2),
            'errors' => $errors,
        ];

        $out = $this->option('out');
        if ($out) {
            file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Exercise one randomly chosen tenant/configuration pair from the seeded matrix.
     *
     * Each shape needs the context its scope demands and the protocol its mode demands, so this is where continuous
     * reserve/confirm and per-branch scoping actually get driven rather than assumed.
     */
    private function matrixRequest(NumberSequenceService $service, int $worker, int $index): void
    {
        $plan = $this->plan();
        $tenant = $plan['tenants'][($worker * 7919 + $index * 31) % count($plan['tenants'])];
        $shape = $plan['shapes'][($worker * 13 + $index) % count($plan['shapes'])];

        $context = ['tenant_id' => $tenant['tenant_id'], 'app_id' => $plan['app_id']];
        if ($shape['scope_type'] === 'legal_entity') {
            $context['legal_entity_id'] = $tenant['legal_entity_id'];
        }
        if ($shape['scope_type'] === 'operating_unit') {
            $context['org_unit_id'] = $tenant['branches'][$index % count($tenant['branches'])];
            // A fiscal reset on an operating unit needs the legal entity that dates the document, the same way a
            // Dynamics 365 transaction carries its company.
            if (in_array($shape['reset_period'], ['fiscal_year', 'fiscal_period'], true)) {
                $context['legal_entity_id'] = $tenant['legal_entity_id'];
            }
        }

        $reference = $plan['app_id'].'.'.$shape['key'];
        $key = "w{$worker}-r{$index}";

        if ($shape['is_continuous']) {
            $reservation = $service->reserve($context, $reference, $key);
            $service->confirm($context, $reservation['id']);

            return;
        }

        $service->issue($context, $reference, $key);
    }

    /** @return array{app_id:string,token:string,shapes:list<array<string,mixed>>,tenants:list<array<string,mixed>>} */
    private function plan(): array
    {
        static $plan = null;

        return $plan ??= json_decode(
            (string) file_get_contents(storage_path('app/number-sequence-matrix.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<float> $values */
    private function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return round($values[max(0, $index)], 2);
    }
}
