<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Menjalankan query yang sudah disusun di transaksi baca-saja dengan batas waktu (KA-24).
 *
 * Baca-saja ditegakkan database, bukan compiler: compiler yang salah pun tidak dapat menulis. Batas
 * waktunya `SET LOCAL statement_timeout`, jadi berlaku untuk transaksi ini saja.
 *
 * Transaksi diakhiri **`ROLLBACK`, bukan `COMMIT`**. Query baca tidak butuh commit, dan bila engine
 * dipanggil di dalam transaksi lain (test, job), Laravel membuka savepoint: rollback ke savepoint
 * membatalkan `SET LOCAL` dan `READ ONLY` di atas, sedangkan commit ke savepoint membiarkan keduanya
 * berlaku sampai transaksi luar selesai — dan `INSERT` berikutnya di transaksi itu gagal dengan
 * "cannot execute INSERT in a read-only transaction".
 *
 * Pemetaan SQLSTATE ada di {@see AnalyticsQueryException::fromDatabase()}: batas waktu (`57014`) dan
 * nilai yang tidak terbaca database (`22P02`, `22007`, `22008`) menjadi 422; percobaan menulis (`25006`)
 * dan SQL yang tidak sah (`42xxx`) dilempar apa adanya — cacat compiler atau dataset, 500 yang dilaporkan.
 */
final class QueryExecutor
{
    /**
     * @return array{rows: list<object>, totals: list<object>}
     *
     * @throws AnalyticsQueryException
     */
    public function run(CompiledQuery $compiled, int $timeoutMs): array
    {
        return $this->readOnly($compiled->builder, $timeoutMs, static fn (): array => [
            'rows' => array_values($compiled->builder->toBase()->get()->all()),
            'totals' => $compiled->totals === null ? [] : array_values($compiled->totals->toBase()->get()->all()),
        ]);
    }

    /**
     * Rencana eksekusi PostgreSQL untuk query hasil dan query total, **tanpa** `ANALYZE`: query-nya tidak
     * dijalankan dan tidak ada baris yang dibaca. Untuk `analytics:explain`.
     *
     * @return array{rows: list<string>, totals: list<string>}
     *
     * @throws AnalyticsQueryException
     */
    public function explain(CompiledQuery $compiled, int $timeoutMs): array
    {
        $plan = static function (Builder $builder): array {
            $query = $builder->toBase();

            return array_values(array_map(
                static fn (object $line): string => (string) (get_object_vars($line)['QUERY PLAN'] ?? ''),
                $query->getConnection()->select('explain (format text) '.$query->toSql(), $query->getBindings()),
            ));
        };

        return $this->readOnly($compiled->builder, $timeoutMs, static fn (): array => [
            'rows' => $plan($compiled->builder),
            'totals' => $compiled->totals === null ? [] : $plan($compiled->totals),
        ]);
    }

    /**
     * @template T
     *
     * @param  Builder<Model>  $builder
     * @param  Closure(): T  $read
     * @return T
     *
     * @throws AnalyticsQueryException
     */
    private function readOnly(Builder $builder, int $timeoutMs, Closure $read): mixed
    {
        $connection = $builder->getConnection();
        $connection->beginTransaction();

        try {
            // Baca-saja di level database: compiler yang salah pun tidak dapat menulis.
            $connection->statement('set transaction read only');
            // SET tidak menerima binding; nilainya integer dari config, bukan dari pemanggil.
            $connection->statement(sprintf('set local statement_timeout = %d', $timeoutMs));

            return $read();
        } catch (QueryException $e) {
            // Galat yang bermakna bagi pengguna menjadi 422; cacat engine (menulis, SQL tidak sah) dilempar
            // apa adanya supaya menjadi 500 yang dilaporkan.
            throw AnalyticsQueryException::fromDatabase($e) ?? $e;
        } finally {
            $connection->rollBack();
        }
    }
}
