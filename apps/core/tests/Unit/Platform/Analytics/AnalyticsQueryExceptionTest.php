<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Query\AnalyticsQueryException;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Tabel pemetaan SQLSTATE eksekutor (area 3, `docs/todo/analitik/mesin-query.md` bagian *Eksekusi
 * baca-saja*): yang bermakna bagi pengguna menjadi 422, cacat engine tidak pernah disamarkan menjadi
 * kesalahan pengguna.
 */
class AnalyticsQueryExceptionTest extends TestCase
{
    public function test_database_errors_that_the_user_can_act_on_become_422(): void
    {
        $timeout = AnalyticsQueryException::fromDatabase($this->failure('57014'));
        $this->assertSame(['analytics.query_timeout', 422], [$timeout?->errorCode, $timeout?->status]);

        foreach (['22P02', '22007', '22008'] as $state) {
            $unreadable = AnalyticsQueryException::fromDatabase($this->failure($state));
            $this->assertSame(['analytics.invalid_filter', 422], [$unreadable?->errorCode, $unreadable?->status], "SQLSTATE {$state}");
        }
    }

    public function test_engine_defects_are_not_dressed_up_as_user_errors(): void
    {
        // Menulis di transaksi baca-saja, kolom atau tabel yang tidak ada, sintaks: cacat compiler atau dataset.
        foreach (['25006', '42703', '42P01', '42601', '08006'] as $state) {
            $this->assertNull(AnalyticsQueryException::fromDatabase($this->failure($state)), "SQLSTATE {$state}");
        }
    }

    private function failure(string $state): QueryException
    {
        // Kode PDOException pgsql berupa SQLSTATE teks, yang tidak dapat diberikan lewat konstruktornya.
        $pdo = new class($state) extends PDOException
        {
            public function __construct(string $state)
            {
                parent::__construct("SQLSTATE[{$state}]: galat uji");
                $this->code = $state;
                $this->errorInfo = [$state, 7, 'galat uji'];
            }
        };

        return new QueryException('pgsql', 'select 1', [], $pdo);
    }
}
