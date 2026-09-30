<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pengaman edit bersamaan (gap 2, K-03): penyimpanan hanya berhasil bila record masih pada versi yang
 * dibuka penggunanya. Padanan perilaku platform BC yang menolak penyimpanan atas data basi, dan
 * `If-Match` pada API BC.
 *
 * Versinya kolom `version` yang dinaikkan trigger pada setiap UPDATE (lihat {@see AuditColumns}).
 * Pemakaiannya di controller, sebelum menulis apa pun ke record itu:
 *
 *     RowVersion::claim($model, RowVersion::expected($request));
 *
 * `claim()` adalah update bersyarat pada versi itu. Ia menaikkan versi dan memegang kunci baris sampai
 * transaksi selesai, jadi dua penyimpanan dengan versi yang sama tidak mungkin sama-sama lolos, termasuk
 * penyimpanan yang hanya mengganti baris anak tanpa menyentuh kolom induknya. Bila penulisan sesudahnya
 * juga mengubah baris induk, versinya naik lagi; angka versi tidak berarti apa-apa selain "berbeda".
 *
 * Versi ini bukan pengganti kunci baris: proses berlangkah banyak di dalam satu transaksi tetap memakai
 * `lockForUpdate()`.
 */
final class RowVersion
{
    public const STALE_MESSAGE = 'Perubahanmu belum disimpan karena data ini sudah diubah sejak kamu membukanya. '
        .'Muat ulang untuk melihat data terbaru, lalu ulangi perubahanmu.';

    public const REQUIRED_MESSAGE = 'Versi data yang sedang diubah wajib dikirim: header If-Match berisi ETag, '
        .'atau field version.';

    /**
     * Versi yang dibawa permintaan: header `If-Match` (API) atau field `version` (form). Tanpa keduanya
     * permintaan ditolak 428, karena penyimpanan tanpa versi adalah penyimpanan yang menimpa buta.
     */
    public static function expected(Request $request): int
    {
        $header = $request->headers->get('If-Match');
        if ($header !== null) {
            if (preg_match('/^\s*(?:W\/)?"(\d+)"\s*$/', $header, $match) !== 1) {
                self::fail($request, Response::HTTP_PRECONDITION_REQUIRED, 'version_required', self::REQUIRED_MESSAGE);
            }

            return (int) $match[1];
        }

        $version = $request->input(AuditColumns::VERSION);
        if (is_int($version) || (is_string($version) && ctype_digit($version))) {
            return (int) $version;
        }

        self::fail($request, Response::HTTP_PRECONDITION_REQUIRED, 'version_required', self::REQUIRED_MESSAGE);
    }

    /**
     * Update bersyarat pada versi yang diharapkan. Record yang tidak ditemukan dijawab 404; record yang
     * versinya sudah berbeda dijawab 409. Memulangkan versi sesudah klaim.
     *
     * @template TModel of Model
     *
     * @param  TModel|EloquentBuilder<TModel>|Builder  $target  model, atau query yang menunjuk tepat satu baris
     */
    public static function claim(Model|EloquentBuilder|Builder $target, int $expected): int
    {
        $query = $target instanceof Model
            ? $target->newQuery()->whereKey($target->getKey())
            : clone $target;
        $column = $query instanceof EloquentBuilder ? $query->qualifyColumn(AuditColumns::VERSION) : AuditColumns::VERSION;

        // Lewat query builder dasar (scope tetap berlaku), supaya Eloquent tidak ikut menulis `updated_at`:
        // klaim tidak mengubah kolom apa pun, dan UPDATE seperti itu selalu dinaikkan versinya, juga pada
        // tabel yang punya kolom aktivitas mesin.
        $base = $query instanceof EloquentBuilder ? (clone $query)->toBase() : clone $query;
        $affected = $base->where($column, $expected)->update([AuditColumns::VERSION => $expected]);

        if ($affected > 1) {
            throw new LogicException('RowVersion::claim() menerima query yang menunjuk lebih dari satu baris.');
        }

        if ($affected === 0) {
            if (! $query->exists()) {
                throw new NotFoundHttpException;
            }

            self::fail(request(), Response::HTTP_CONFLICT, 'stale_version', self::STALE_MESSAGE);
        }

        return $expected + 1;
    }

    /**
     * Klaim untuk record yang baru lahir pada penyimpanan pertamanya, seperti setelan per tenant atau per
     * entitas legal. Selama barisnya belum ada, layar membukanya dengan versi 0; penyimpanan itu yang
     * membuatnya, dan method ini memulangkan false. Bila tab lain sudah membuatnya lebih dulu, versi 0
     * tidak pernah cocok (versi dimulai dari 1) dan penyimpanan ini ditolak 409. Sebaliknya, versi di atas 0
     * untuk baris yang sudah tidak ada berarti penggunanya membuka baris yang kemudian dihapus; itu juga 409.
     *
     * Dua penyimpanan pertama yang bersamaan diurutkan kunci advisory transaksi yang dihitung dari
     * query-nya: yang kedua menunggu, lalu melihat baris buatan yang pertama. Tanpa kunci itu keduanya
     * sama-sama tidak menemukan baris, dan yang kedua menimpa yang pertama atau jatuh di indeks unik.
     * Karena itu method ini wajib dipanggil di dalam transaksi yang juga membuat barisnya.
     *
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>|Builder  $query  query yang menunjuk paling banyak satu baris
     */
    public static function claimIfExists(EloquentBuilder|Builder $query, int $expected): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RowVersion::claimIfExists() harus dipanggil di dalam transaksi.');
        }

        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$query->toRawSql()]);

        if ((clone $query)->exists()) {
            self::claim($query, $expected);

            return true;
        }

        if ($expected !== 0) {
            self::fail(request(), Response::HTTP_CONFLICT, 'stale_version', self::STALE_MESSAGE);
        }

        return false;
    }

    /** ETag lemah untuk sebuah versi, bentuk yang juga dipakai BC: `W/"12"`. */
    public static function etag(int $version): string
    {
        return 'W/"'.$version.'"';
    }

    /**
     * Jawaban JSON menjawab dengan kode dan pesan; form Inertia kembali ke halamannya dengan galat pada
     * field `version`, supaya pesannya tampil di form yang sama.
     */
    private static function fail(Request $request, int $status, string $code, string $message): never
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            throw new HttpResponseException(response()->json(['error' => ['code' => $code, 'message' => $message]], $status));
        }

        throw new HttpResponseException(back()->withErrors([AuditColumns::VERSION => $message]));
    }
}
