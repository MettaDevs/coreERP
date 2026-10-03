<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

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
        $connection = $compiled->builder->getConnection();
        $connection->beginTransaction();

        try {
            // Baca-saja di level database: compiler yang salah pun tidak dapat menulis.
            $connection->statement('set transaction read only');
            // SET tidak menerima binding; nilainya integer dari config, bukan dari pemanggil.
            $connection->statement(sprintf('set local statement_timeout = %d', $timeoutMs));

            return [
                'rows' => array_values($compiled->builder->toBase()->get()->all()),
                'totals' => $compiled->totals === null ? [] : array_values($compiled->totals->toBase()->get()->all()),
            ];
        } catch (QueryException $e) {
            // Galat yang bermakna bagi pengguna menjadi 422; cacat engine (menulis, SQL tidak sah) dilempar
            // apa adanya supaya menjadi 500 yang dilaporkan.
            throw AnalyticsQueryException::fromDatabase($e) ?? $e;
        } finally {
            $connection->rollBack();
        }
    }
}
