<?php

namespace Tests\Feature\Foundation\Currency;

use App\Foundation\Currency\Models\CurrencyPrecision;
use App\Foundation\Currency\Support\MoneyPrecision;
use App\Platform\Modules\Contracts\CurrencyRounding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MoneyPrecisionCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_rounding_adapters_share_one_read_inside_the_scope(): void
    {
        $tenant = (string) Str::ulid();
        DB::enableQueryLog();
        try {
            $this->assertSame(2, app(CurrencyRounding::class)->amountDecimals($tenant, 'IDR'));
            $this->assertSame('1.24', app(CurrencyRounding::class)->roundAmount($tenant, '1.235', 'IDR'));
            $reads = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'currency_precisions'));
            $this->assertCount(1, $reads);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_changes_and_rollback_do_not_leave_stale_precision(): void
    {
        $tenant = (string) Str::ulid();
        $precision = app(MoneyPrecision::class);
        $this->assertSame(2, $precision->amountDecimals($tenant, 'IDR'));
        $row = CurrencyPrecision::query()->create([
            'tenant_id' => $tenant, 'currency_code' => 'IDR', 'amount_decimals' => 0, 'unit_amount_decimals' => 3,
        ]);
        $this->assertSame(0, $precision->amountDecimals($tenant, 'IDR'));
        DB::beginTransaction();
        try {
            $row->update(['amount_decimals' => 2]);
            $this->assertSame(2, $precision->amountDecimals($tenant, 'IDR'));
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, $precision->amountDecimals($tenant, 'IDR'));
        $this->assertSame(2, $precision->amountDecimals((string) Str::ulid(), 'IDR'));
    }

    public function test_nested_savepoints_do_not_repeat_unchanged_reference_reads(): void
    {
        $tenant = (string) Str::ulid();
        DB::enableQueryLog();
        try {
            $this->assertSame(2, app(CurrencyRounding::class)->amountDecimals($tenant, 'IDR'));
            for ($index = 0; $index < 3; $index++) {
                DB::transaction(fn () => $this->assertSame(2, app(CurrencyRounding::class)->amountDecimals($tenant, 'IDR')));
            }
            $reads = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'currency_precisions'));
            $this->assertCount(1, $reads);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_new_scope_observes_updates_from_another_writer(): void
    {
        $tenant = (string) Str::ulid();
        $first = app(MoneyPrecision::class);
        $this->assertSame(2, $first->amountDecimals($tenant, 'IDR'));
        DB::table('currency_precisions')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'currency_code' => 'IDR',
            'amount_decimals' => 0, 'unit_amount_decimals' => 3,
        ]);
        $this->app->forgetScopedInstances();
        $second = app(MoneyPrecision::class);
        $this->assertNotSame($first, $second);
        $this->assertSame(0, $second->amountDecimals($tenant, 'IDR'));
    }

    public function test_reused_service_separates_connection_sources_for_the_same_tenant(): void
    {
        $tenant = (string) Str::ulid();
        $precision = app(MoneyPrecision::class);
        $this->assertSame(2, $precision->amountDecimals($tenant, 'IDR'));
        $original = DB::getDefaultConnection();
        $copy = $original.'_currency_copy';
        config(['database.connections.'.$copy => config('database.connections.'.$original)]);
        try {
            $connection = DB::connection($copy);
            $connection->statement('CREATE TEMP TABLE currency_precisions (id text, tenant_id text, currency_code text, amount_decimals integer, unit_amount_decimals integer)');
            $connection->table('currency_precisions')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'currency_code' => 'IDR',
                'amount_decimals' => 0, 'unit_amount_decimals' => 3,
            ]);
            DB::setDefaultConnection($copy);
            $this->assertSame(0, $precision->amountDecimals($tenant, 'IDR'));
            $connection->table('currency_precisions')->update(['amount_decimals' => 1]);
            $connection->beginTransaction();
            try {
                $this->assertSame(1, $precision->amountDecimals($tenant, 'IDR'));
                $connection->table('currency_precisions')->update(['amount_decimals' => 2]);
                $connection->commit();
            } catch (\Throwable $exception) {
                $connection->rollBack();
                throw $exception;
            }
            $this->assertSame(2, $precision->amountDecimals($tenant, 'IDR'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge($copy);
        }
        $this->assertSame(2, $precision->amountDecimals($tenant, 'IDR'));
    }
}
