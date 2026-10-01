<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\CounterReading;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\AssetTypeCounter;
use Modules\Apperp\ManagementAset\Models\master\CounterType;
use Modules\Apperp\ManagementAset\Models\transaksi\CounterReading\CounterReading;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Services\AssetOrganizationDirectory;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use stdClass;

/**
 * Pembacaan counter aset; padanan *Asset counters* di Dynamics 365 F&O Asset Management.
 *
 * Pengguna mengetik angka yang tertera di meter. Total pemakaian dihitung di sini dari pembacaan
 * sebelumnya, dan menjadi dasar baris rencana pemeliharaan berbasis counter.
 *
 * Dua aturan menjaga total itu tetap benar:
 *
 * - **Angka meter tidak boleh turun**, kecuali pembacaan ditandai penggantian meter. F&O mencatat
 *   penggantian sebagai dua baris berwaktu sama (bacaan akhir meter lama, lalu meter baru dengan
 *   *Counter reset*); di sini caranya sama.
 * - **Pembacaan tidak disisipkan di tengah riwayat dan tidak diubah.** Total setiap baris bergantung
 *   pada baris sebelumnya, jadi pembacaan yang salah diarsipkan — hanya yang terakhir — lalu dicatat
 *   ulang.
 *
 * Jangkauan organisasi mengikuti aset: pembacaan terlihat dan dapat dicatat oleh pengguna yang
 * menjangkau unit penanggung jawab asetnya.
 */
final class CounterReadingController extends Controller
{
    private const RESOURCE = 'pembacaan-counter';

    private const TABLE = 'aset_tr_pembacaan_counter';

    /** Sama dengan dokumen lain: Core membatasi kunci 160 karakter, awalan resource memakan sisanya. */
    private const MAX_CREATION_KEY = 140;

    public function index(Request $request, AssetOrganizationDirectory $people): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate([
            'aset_id' => ['nullable', 'ulid'],
            'jenis_counter_id' => ['nullable', 'ulid'],
        ]);

        $query = $this->withLookups(CounterReading::query(), $request);
        if ($filter['aset_id'] ?? null) {
            $query->where(self::TABLE.'.aset_id', $filter['aset_id']);
        }
        if ($filter['jenis_counter_id'] ?? null) {
            $query->where(self::TABLE.'.jenis_counter_id', $filter['jenis_counter_id']);
        }

        $tenant = $this->tenant($request);
        $rows = $query->orderByDesc(self::TABLE.'.dibaca_pada')->orderByDesc(self::TABLE.'.id')->limit(500)->toBase()->get()
            ->map(fn (stdClass $row): stdClass => $this->withPerson($row, $tenant, $people));

        return response()->json(['data' => $rows]);
    }

    /**
     * Counter yang dapat dibaca pada satu aset, beserta pembacaan terakhirnya. Dipakai form pencatatan
     * untuk menampilkan angka sebelumnya sebelum pengguna mengetik angka baru.
     */
    public function assetCounters(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $asetId = $request->validate(['aset_id' => ['required', 'ulid']])['aset_id'];
        $aset = $this->aset($request, $asetId);

        $data = $this->allowedCounters($aset->jenis_aset_id)->map(function (CounterType $counter) use ($asetId): array {
            $last = $this->latest($asetId, (string) $counter->id);

            return [
                'jenis_counter_id' => $counter->id,
                'kode' => $counter->kode,
                'nama' => $counter->nama,
                'satuan' => $counter->satuan,
                'terakhir' => $last === null ? null : [
                    'dibaca_pada' => $last->dibaca_pada->toDateTimeString(),
                    'nilai' => $last->nilai,
                    'nilai_total' => $last->nilai_total,
                ],
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, AssetOrganizationDirectory $people): JsonResponse
    {
        $this->guard($request, 'create');
        $key = (string) validator(
            ['key' => $request->header('Idempotency-Key')],
            ['key' => ['required', 'string', 'max:'.self::MAX_CREATION_KEY, 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate()['key'];
        if ($existing = $this->replay($key)) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $request->validate([
            'aset_id' => ['required', 'ulid'],
            'jenis_counter_id' => ['required', 'ulid'],
            'dibaca_pada' => ['required', 'date'],
            'nilai' => ['required', 'numeric', 'min:0', 'max:9999999999999999'],
            'reset' => ['sometimes', 'boolean'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
        ]);
        $reset = filter_var($data['reset'] ?? false, FILTER_VALIDATE_BOOL);
        $aset = $this->aset($request, $data['aset_id']);
        if (! StatusAset::bolehDibuatkanWorkOrder($aset->lifecycle_state)) {
            throw ValidationException::withMessages(['aset_id' => 'Aset ini sudah dihentikan atau dilepas, sehingga counternya tidak lagi dicatat.']);
        }
        if (! $this->allowedCounters($aset->jenis_aset_id)->contains('id', $data['jenis_counter_id'])) {
            throw ValidationException::withMessages(['jenis_counter_id' => 'Counter ini tidak aktif atau tidak berlaku untuk jenis aset ini.']);
        }

        try {
            DB::transaction(function () use ($request, $data, $reset, $key): void {
                // Satu pembacaan pada satu waktu per aset: total dihitung dari pembacaan terakhir, dan
                // dua pencatatan yang saling menyela akan sama-sama menghitung dari baris yang sama.
                Aset::query()->whereKey($data['aset_id'])->lockForUpdate()->first(['id']);
                $previous = $this->latest($data['aset_id'], $data['jenis_counter_id']);
                $readAt = Carbon::parse($data['dibaca_pada']);
                $value = (float) $data['nilai'];

                if ($previous !== null && $readAt->lt($previous->dibaca_pada)) {
                    throw ValidationException::withMessages(['dibaca_pada' => 'Waktu baca tidak boleh lebih awal dari pembacaan terakhir ('.$previous->dibaca_pada->format('d-m-Y H:i').'). Arsipkan pembacaan terakhir bila yang salah adalah pembacaan itu.']);
                }
                if ($previous !== null && ! $reset && $value < (float) $previous->nilai) {
                    throw ValidationException::withMessages(['nilai' => 'Angka meter lebih kecil dari pembacaan terakhir ('.$previous->nilai.'). Tandai sebagai penggantian meter bila meternya diganti atau direset.']);
                }

                // Penggantian meter tidak menambah pemakaian: angkanya adalah angka awal meter baru.
                $increment = $reset ? 0.0 : $value - (float) ($previous->nilai ?? 0);
                CounterReading::query()->create([
                    'tenant_id' => $this->tenant($request),
                    'creation_key' => $key,
                    'aset_id' => $data['aset_id'],
                    'jenis_counter_id' => $data['jenis_counter_id'],
                    'dibaca_pada' => $readAt,
                    'nilai' => $value,
                    'nilai_total' => round((float) ($previous->nilai_total ?? 0) + $increment, 2),
                    'reset' => $reset,
                    'keterangan' => ($data['keterangan'] ?? null) === null ? null : (trim($data['keterangan']) ?: null),
                ]);
            });
        } catch (QueryException $exception) {
            $existing = $this->replay($key);
            if (! $existing) {
                throw $exception;
            }

            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $created = $this->replay($key);

        return response()->json(['data' => $created === null ? null : $this->withPerson($created, $this->tenant($request), $people)], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $reading = $this->withLookups(CounterReading::query(), $request)->where(self::TABLE.'.id', $id)
            ->toBase()->first([self::TABLE.'.id', self::TABLE.'.aset_id', self::TABLE.'.jenis_counter_id']);
        abort_if($reading === null, 404);
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($reading, $version): void {
            Aset::query()->whereKey($reading->aset_id)->lockForUpdate()->first(['id']);
            $latest = $this->latest($reading->aset_id, $reading->jenis_counter_id);
            abort_unless(
                $latest !== null && $latest->id === $reading->id,
                422,
                'Hanya pembacaan terakhir yang dapat diarsipkan, karena total pembacaan sesudahnya dihitung dari pembacaan ini.',
            );
            RowVersion::claim(CounterReading::query()->whereKey($reading->id), $version);
            CounterReading::query()->whereKey($reading->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(status: 204);
    }

    /**
     * Counter yang berlaku untuk satu jenis aset: yang dikaitkan ke jenis itu, ditambah counter yang
     * belum dikaitkan ke jenis aset mana pun. Aturannya sama dengan jenis pekerjaan maintenance.
     *
     * @return Collection<int, CounterType>
     */
    private function allowedCounters(string $jenisAsetId): Collection
    {
        $linked = AssetTypeCounter::query()->distinct()->pluck('jenis_counter_id')->map(strval(...))->all();
        $forType = AssetTypeCounter::query()->where('jenis_aset_id', $jenisAsetId)->pluck('jenis_counter_id')->map(strval(...))->all();

        return CounterType::query()->where('aktif', true)->orderBy('kode')->get()
            ->filter(fn (CounterType $counter): bool => ! in_array((string) $counter->id, $linked, true) || in_array((string) $counter->id, $forType, true))
            ->values();
    }

    private function latest(string $asetId, string $counterId): ?CounterReading
    {
        return CounterReading::query()
            ->where(['aset_id' => $asetId, 'jenis_counter_id' => $counterId])
            ->orderByDesc('dibaca_pada')->orderByDesc('id')
            ->first();
    }

    /** Aset dalam jangkauan organisasi pengguna; di luar jangkauan sama dengan tidak ada. */
    private function aset(Request $request, string $asetId): Aset
    {
        $query = Aset::query()->whereKey($asetId);
        app(OrganizationScope::class)->asetQuery($query, $request);
        $aset = $query->first();
        if ($aset === null) {
            throw ValidationException::withMessages(['aset_id' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
        }

        return $aset;
    }

    /**
     * Pembacaan beserta aset dan counternya, tersaring jangkauan organisasi lewat aset.
     *
     * @param  Builder<CounterReading>  $query
     * @return Builder<CounterReading>
     */
    private function withLookups(Builder $query, Request $request): Builder
    {
        app(OrganizationScope::class)->asetQuery($this->joined($query), $request);

        return $query;
    }

    /**
     * Untuk replay tanpa saringan jangkauan: kunci idempotensi sudah membuktikan pemiliknya, dan
     * replay harus menemukan pembacaan yang sudah diarsipkan juga.
     */
    private function replay(string $key): ?stdClass
    {
        return $this->joined(CounterReading::withTrashed())->where(self::TABLE.'.creation_key', $key)->toBase()->first();
    }

    /**
     * @param  Builder<CounterReading>  $query
     * @return Builder<CounterReading>
     */
    private function joined(Builder $query): Builder
    {
        return $query
            ->join('aset_tr_aset', function ($join): void {
                $join->on('aset_tr_aset.id', '=', self::TABLE.'.aset_id')->on('aset_tr_aset.tenant_id', '=', self::TABLE.'.tenant_id');
            })
            ->join('aset_m_jenis_counter as counter', function ($join): void {
                $join->on('counter.id', '=', self::TABLE.'.jenis_counter_id')->on('counter.tenant_id', '=', self::TABLE.'.tenant_id');
            })
            ->select([
                self::TABLE.'.*',
                'aset_tr_aset.kode as aset_kode', 'aset_tr_aset.nama as aset_nama',
                'counter.kode as jenis_counter_kode', 'counter.nama as jenis_counter_nama', 'counter.satuan',
            ]);
    }

    /** Pencatat ditampilkan dengan namanya, bukan id pengguna. */
    private function withPerson(stdClass $row, string $tenant, AssetOrganizationDirectory $people): stdClass
    {
        $row->dicatat_oleh_nama = $people->personName($tenant, $row->created_by_user_id === null ? null : (string) $row->created_by_user_id);

        return $row;
    }

    private function guard(Request $request, string $action): void
    {
        abort_unless(
            in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true),
            403,
        );
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
