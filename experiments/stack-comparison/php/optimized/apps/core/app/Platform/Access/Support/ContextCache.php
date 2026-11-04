<?php

declare(strict_types=1);

namespace App\Platform\Access\Support;

use App\Platform\Tenant\Models\TenantMembership;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cache konteks keamanan per worker: pengguna sesi, keanggotaan, kebijakan data, organisasi, dan izin.
 *
 * Varian teroptimasi pembanding stack (`experiments/stack-comparison/optimized.md`, butir 1 dan 2).
 * Bawaannya mati (`CONTEXT_CACHE=off`). Selama mati, setiap method langsung menjalankan query aslinya
 * tanpa membaca atau menulis apa pun di sini, jadi SQL dan perilakunya sama persis dengan tanpa cache.
 *
 * Dua umur yang sengaja dipisah:
 *
 * - **Isi cache hidup selama worker**, di properti statis. Bukan `singleton()`: di Octane, singleton
 *   yang pertama kali dibuat di dalam permintaan tersimpan di salinan container milik permintaan itu
 *   dan ikut dibuang sesudah jawaban terkirim, sehingga cache-nya kosong lagi setiap permintaan.
 *   Properti statis hidup selama thread worker FrankenPHP dan tidak pernah dibagi antar-worker.
 * - **Pencocokan versi berlaku satu permintaan**, di properti instans. Kelas ini diikat `scoped()`,
 *   sehingga setiap permintaan, job antrean, dan tick memperoleh instans baru dan tepat satu query
 *   versi sebelum membaca entri apa pun.
 *
 * Isinya hanya larik dan skalar yang dikunci id pengguna, keanggotaan, dan tenant. Model Eloquent
 * disimpan sebagai atribut mentah dan dibangun ulang setiap kali dibaca, supaya perubahan pada model
 * di satu permintaan tidak terbawa ke permintaan lain. Objek permintaan, sesi, koneksi, dan hasil
 * pemilihan tempat kerja tidak pernah masuk ke sini; pemilihan itu tetap dihitung per permintaan oleh
 * {@see \App\Platform\Environment\Support\CurrentWorkspace}.
 */
final class ContextCache
{
    /** Lewat dari jumlah ini, seluruh isi cache worker dikosongkan. */
    private const MAX_ENTRIES = 20000;

    /** Dinaikkan trigger pada setiap perubahan tabel pembentuk konteks (`infra/optimized-schema.sql`). */
    private const VERSION_SQL = 'select version from security_context_versions where id = 1';

    /**
     * Saat terdekat sebuah penugasan role atau lingkup kebijakan keanggotaan ini berakhir atau mulai
     * berlaku. Penugasan yang berubah karena waktu tidak menulis apa pun, jadi versi tidak naik; batas
     * inilah yang mencegahnya bertahan di cache.
     */
    private const BOUNDARY_SQL = <<<'SQL'
        select min(t) from (
          select a.valid_until as t from role_assignments a where a.membership_id = ? and a.status = 'active' and a.valid_until > now()
          union all select a.valid_from from role_assignments a where a.membership_id = ? and a.status = 'active' and a.valid_from > now()
          union all select s.valid_until from role_assignment_data_policy_scopes s join role_assignments a on a.id = s.role_assignment_id
                    where a.membership_id = ? and s.tenant_id = ? and s.valid_until > now()
          union all select s.valid_from from role_assignment_data_policy_scopes s join role_assignments a on a.id = s.role_assignment_id
                    where a.membership_id = ? and s.tenant_id = ? and s.valid_from > now()
        ) boundaries
        SQL;

    /** Versi konteks yang berlaku untuk seluruh isi cache worker ini. */
    private static ?int $version = null;

    /** @var array<string, array{expires_at: float, value: mixed}> */
    private static array $entries = [];

    /** Query versi sudah berjalan pada permintaan ini. */
    private bool $versionChecked = false;

    /**
     * @template T
     *
     * @param  Closure(): T  $compute  query yang sama persis dengan tanpa cache
     * @param  TenantMembership|null  $membership  keanggotaan yang penugasan role dan lingkup kebijakannya membatasi umur entri
     * @return T
     */
    public function remember(string $key, Closure $compute, ?TenantMembership $membership = null): mixed
    {
        if (! $this->enabled()) {
            return $compute();
        }

        $this->checkVersion();

        $entry = self::$entries[$key] ?? null;
        if ($entry !== null && $entry['expires_at'] > microtime(true)) {
            return $entry['value'];
        }

        // Batasnya dibaca sebelum datanya. Penugasan yang berakhir di antara keduanya akan terbaca
        // masih berlaku tanpa batas akhirnya ikut tercatat, lalu bertahan sampai TTL habis.
        $expiresAt = min(microtime(true) + $this->ttl(), $membership === null ? INF : $this->boundary($membership));
        $value = $compute();
        $this->put($key, $value, $expiresAt);

        return $value;
    }

    /**
     * @template TCollection of Collection
     *
     * @param  Closure(): TCollection  $query
     * @return TCollection
     */
    public function rememberModels(string $key, Closure $query, ?TenantMembership $membership = null): Collection
    {
        if (! $this->enabled()) {
            return $query();
        }

        $fresh = null;
        $stored = $this->remember($key, function () use ($query, &$fresh): array {
            $fresh = $query();

            return ['collection' => $fresh::class, 'models' => array_map(self::export(...), $fresh->all())];
        }, $membership);

        if ($fresh !== null) {
            return $fresh;
        }

        $collection = $stored['collection'];

        return new $collection(array_map(self::restore(...), $stored['models']));
    }

    /** @param  Closure(): ?Model  $query */
    public function rememberModel(string $key, Closure $query): ?Model
    {
        if (! $this->enabled()) {
            return $query();
        }

        $fresh = null;
        $stored = $this->remember($key, function () use ($query, &$fresh): ?array {
            $fresh = $query();

            return $fresh === null ? null : self::export($fresh);
        });

        return $fresh ?? ($stored === null ? null : self::restore($stored));
    }

    private function enabled(): bool
    {
        // Satu versi menjaga satu database. Bila tabel pusat dibaca lewat koneksi tersendiri (alamat
        // lingkungan), pengguna dan keanggotaan tidak dijaga versi database lingkungan, jadi cache tidak
        // dipakai sama sekali.
        //
        // Di dalam transaksi, koneksi dapat melihat tulisannya sendiri yang belum di-commit. Menyimpan
        // hasil bacaan itu dapat menyimpan hak yang kemudian di-rollback, padahal versinya tidak pernah
        // naik; di sana bacaan berjalan langsung ke database.
        return config('coreerp.context_cache.enabled') === true
            && blank(config('coreerp.control_connection'))
            && DB::transactionLevel() === 0;
    }

    private function checkVersion(): void
    {
        if ($this->versionChecked) {
            return;
        }

        $version = (int) DB::selectOne(self::VERSION_SQL)->version;
        if ($version !== self::$version) {
            self::$entries = [];
            self::$version = $version;
        }

        $this->versionChecked = true;
    }

    /**
     * Batas validitas berikutnya untuk keanggotaan ini, dihitung sekali saat miss lalu dipakai bersama
     * entri kebijakan, izin, dan organisasinya. Sampai batas itu tidak ada perubahan karena waktu,
     * dan perubahan karena tulisan sudah dijaga versi.
     */
    private function boundary(TenantMembership $membership): float
    {
        $key = 'boundary|'.$membership->id.'|'.$membership->tenant_id;

        $entry = self::$entries[$key] ?? null;
        if ($entry !== null && $entry['expires_at'] > microtime(true)) {
            return $entry['value'];
        }

        $expiresAt = microtime(true) + $this->ttl();
        $membershipId = (string) $membership->id;
        $tenantId = (string) $membership->tenant_id;
        $next = DB::selectOne(self::BOUNDARY_SQL, [
            $membershipId, $membershipId, $membershipId, $tenantId, $membershipId, $tenantId,
        ])->min;

        // Kolomnya `timestamp` tanpa zona dan berisi UTC, sama dengan cara aplikasi menulis dan
        // membandingkannya (`app.timezone` UTC).
        $boundary = $next === null ? INF : (float) CarbonImmutable::parse($next, 'UTC')->format('U.u');
        $this->put($key, $boundary, min($expiresAt, $boundary));

        return $boundary;
    }

    private function put(string $key, mixed $value, float $expiresAt): void
    {
        if (count(self::$entries) >= self::MAX_ENTRIES && ! array_key_exists($key, self::$entries)) {
            self::$entries = [];
        }

        self::$entries[$key] = ['expires_at' => $expiresAt, 'value' => $value];
    }

    private function ttl(): int
    {
        return (int) config('coreerp.context_cache.ttl_seconds', 300);
    }

    /** @return array<string, mixed> */
    private static function export(Model $model): array
    {
        return [
            'class' => $model::class,
            'connection' => $model->getConnectionName(),
            'attributes' => $model->getAttributes(),
            'prevents_lazy_loading' => $model->preventsLazyLoading,
            // Hanya relasi tunggal yang sudah dimuat query aslinya, misalnya `tenant` milik keanggotaan.
            'relations' => array_map(
                fn (?Model $related): ?array => $related === null ? null : self::export($related),
                $model->getRelations(),
            ),
        ];
    }

    /** @param  array<string, mixed>  $row */
    private static function restore(array $row): Model
    {
        $model = (new $row['class'])->newFromBuilder($row['attributes'], $row['connection']);
        $model->preventsLazyLoading = $row['prevents_lazy_loading'];

        foreach ($row['relations'] as $name => $related) {
            $model->setRelation($name, $related === null ? null : self::restore($related));
        }

        return $model;
    }
}
