<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Console;

use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryCompiler;
use App\Platform\Analytics\Query\QueryExecutor;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Models\User;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Console\Command;
use JsonException;

/**
 * SQL hasil compile satu query analitik beserta rencana eksekusinya (`EXPLAIN`, tanpa `ANALYZE`), untuk
 * melihat indeks yang dipakai dan bentuk join tanpa membaca data.
 *
 * Query disusun **sebagai pengguna yang disebut** — hak, hibah kebijakan data, dan zona waktunya —
 * melewati langkah yang sama dengan `RunQuery` sebelum eksekusi: bentuk normal, dataset terpasang,
 * permission, dan validasi. Rencananya dibaca di transaksi baca-saja yang selalu dibatalkan.
 * Query widget menyusul bersama penyimpanan dasbor (area 6).
 */
final class AnalyticsExplainCommand extends Command
{
    protected $signature = 'analytics:explain
        {--query= : Query analitik dalam JSON, bentuk yang sama dengan POST /api/v1/analytics/query}
        {--tenant= : Id tenant tempat query disusun}
        {--user= : Email pengguna tenant itu; query disusun dengan hak dan zona waktunya}';

    protected $description = 'Tampilkan SQL hasil compile satu query analitik beserta EXPLAIN-nya, tanpa menjalankan query';

    public function handle(
        QueryParser $parser,
        QueryNormalizer $normalizer,
        DatasetRegistry $datasets,
        DatasetAccess $access,
        QueryValidator $validator,
        QueryCompiler $compiler,
        QueryExecutor $executor,
        TenantRunner $tenants,
        UserClock $clock,
    ): int {
        $json = $this->option('query');
        $tenant = $this->option('tenant');
        $email = $this->option('user');
        if (! is_string($json) || ! is_string($tenant) || ! is_string($email)) {
            $this->error('Sebutkan --query, --tenant, dan --user.');

            return self::FAILURE;
        }

        try {
            $input = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('Isi --query bukan JSON yang sah: '.$e->getMessage());

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        $membership = $user === null ? null : TenantMembership::query()
            ->where('tenant_id', $tenant)->where('user_id', $user->id)->where('status', 'active')->first();
        if ($user === null || $membership === null) {
            $this->error("Pengguna {$email} bukan anggota aktif tenant {$tenant}.");

            return self::FAILURE;
        }
        $principal = UserPrincipal::fromMembership($membership, $clock->timezoneFor($user, null));

        try {
            $query = $normalizer->normalize($parser->parse(is_array($input) ? $input : []));
            $dataset = $datasets->find($query->dataset) ?? throw AnalyticsQueryException::datasetUnknown();
            $access->authorize($principal, $dataset);
            $validator->validate($dataset, $query, $principal);

            [$sql, $plan] = $tenants->runFor($principal->tenantId(), static function () use ($compiler, $executor, $dataset, $query, $principal): array {
                $compiled = $compiler->compile($dataset, $query, $principal);

                return [
                    ['rows' => $compiled->builder->toBase()->toRawSql(), 'totals' => $compiled->totals?->toBase()->toRawSql()],
                    $executor->explain($compiled, $principal->timeoutMs()),
                ];
            });
        } catch (AnalyticsQueryException $e) {
            $this->error($e->getMessage().($e->field === null ? '' : " ({$e->field})"));

            return self::FAILURE;
        }

        $this->line("Dataset {$dataset->code} versi {$dataset->version}, zona {$principal->timezone()}, sebagai {$principal->describe()}.");
        $this->newLine();
        $this->info('SQL hasil');
        $this->line($sql['rows']);
        $this->newLine();
        $this->info('Rencana (EXPLAIN, tanpa ANALYZE)');
        $this->line(implode(PHP_EOL, $plan['rows']));

        if ($sql['totals'] !== null) {
            $this->newLine();
            $this->info('SQL total');
            $this->line($sql['totals']);
            $this->newLine();
            $this->info('Rencana total');
            $this->line(implode(PHP_EOL, $plan['totals']));
        }

        return self::SUCCESS;
    }
}
