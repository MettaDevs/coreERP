<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Dashboards\StoredQuery;
use App\Platform\Analytics\Http\Presenters\DashboardPresenter;
use App\Platform\Analytics\Models\SavedQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Query tersimpan dari penjelajah (`api/v1/analytics/saved-queries`), dengan aturan berbagi yang sama dengan
 * dasbor ({@see DashboardAccess}) dan versi baris pada setiap perubahan.
 *
 * Query diperiksa saat disimpan lewat {@see StoredQuery::validate()} — dataset terpasang, boleh dibaca
 * penyimpannya, kolom dikenal, dan gerbang data pribadi — lalu disimpan dalam bentuk ringkas beserta versi
 * datasetnya. Saat dibaca, kunci yang diganti nama dipetakan dan kunci yang hilang membuat statusnya
 * `field_removed`.
 *
 * `code` unik per tenant dan tidak dapat diganti sesudah dibuat, karena publikasi dan feed (fase 2) menunjuk
 * query dengan kode itu. Bila tidak dikirim, kode dibuat dari nama: `nilai-perolehan`, lalu `nilai-perolehan-2`
 * bila sudah dipakai.
 */
final class SavedQueryController extends Controller
{
    private const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,79}$/';

    private const DUPLICATE_CODE = 'Kode ini sudah dipakai analisis tersimpan lain. Pilih kode lain.';

    public function __construct(
        private readonly DashboardAccess $access,
        private readonly StoredQuery $queries,
        private readonly DashboardPresenter $presenter,
        private readonly UserClock $clock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $dataset = $request->query('dataset');

        $saved = $this->access->visible(SavedQuery::query(), $membership)
            ->with('user:id,name')
            ->when(is_string($dataset) && $dataset !== '', fn ($query) => $query->where('dataset_code', $dataset))
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $saved->map(fn (SavedQuery $query): array => $this->presenter->savedQuery($query, $membership))->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'regex:'.self::CODE_PATTERN],
            'description' => ['nullable', 'string', 'max:1000'],
            'shared' => ['sometimes', 'boolean'],
            'query' => ['required', 'array'],
        ]);
        $shared = (bool) ($data['shared'] ?? false);
        $this->access->authorizeCreate($membership, $shared);

        try {
            [$dataset, $query] = $this->queries->validate($this->principal($request, $membership), $data['query']);
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        $name = trim($data['name']);
        $code = $data['code'] ?? null;
        if ($code !== null && $this->codeTaken($membership, $code)) {
            throw ValidationException::withMessages(['code' => [self::DUPLICATE_CODE]]);
        }

        try {
            $saved = DB::transaction(fn (): SavedQuery => SavedQuery::query()->create([
                'tenant_id' => $membership->tenant_id,
                'user_id' => $membership->user_id,
                'code' => $code ?? $this->freeCode($membership, $name),
                'name' => $name,
                'description' => $data['description'] ?? null,
                'shared' => $shared,
                'dataset_code' => $dataset->code,
                'dataset_version' => $dataset->version,
                'query' => StoredQuery::compact($query),
            ])->refresh());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => [self::DUPLICATE_CODE]]);
        }

        return $this->respond($saved, $membership, 201);
    }

    public function show(Request $request, SavedQuery $savedQuery): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->access->authorizeView($membership, $savedQuery);

        return $this->respond($savedQuery, $membership);
    }

    public function update(Request $request, SavedQuery $savedQuery): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->access->authorizeEdit($membership, $savedQuery);
        $expected = RowVersion::expected($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'shared' => ['sometimes', 'boolean'],
            'query' => ['sometimes', 'required', 'array'],
        ]);

        $values = [];
        if (array_key_exists('shared', $data)) {
            $this->access->authorizeShareChange($membership, $savedQuery, (bool) $data['shared']);
            $values['shared'] = (bool) $data['shared'];
        }
        if (array_key_exists('name', $data)) {
            $values['name'] = trim($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $values['description'] = $data['description'];
        }
        if (array_key_exists('query', $data)) {
            try {
                [$dataset, $query] = $this->queries->validate($this->principal($request, $membership), $data['query']);
            } catch (AnalyticsQueryException $e) {
                return $e->toResponse();
            }
            $values = [...$values, 'dataset_code' => $dataset->code, 'dataset_version' => $dataset->version, 'query' => StoredQuery::compact($query)];
        }

        DB::transaction(function () use ($savedQuery, $expected, $values): void {
            RowVersion::claim($savedQuery, $expected);
            if ($values !== []) {
                $savedQuery->forceFill($values)->save();
            }
        });

        return $this->respond($savedQuery->refresh(), $membership);
    }

    public function destroy(Request $request, SavedQuery $savedQuery): Response
    {
        $membership = $this->currentMembership($request);
        $this->access->authorizeEdit($membership, $savedQuery);
        $expected = RowVersion::expected($request);

        DB::transaction(function () use ($savedQuery, $expected): void {
            RowVersion::claim($savedQuery, $expected);
            $savedQuery->delete();
        });

        return response()->noContent();
    }

    private function respond(SavedQuery $saved, TenantMembership $membership, int $status = 200): JsonResponse
    {
        $saved->loadMissing('user:id,name');

        return response()->json(['data' => $this->presenter->savedQuery($saved, $membership)], $status, ['ETag' => RowVersion::etag($saved->version)]);
    }

    private function principal(Request $request, TenantMembership $membership): UserPrincipal
    {
        return UserPrincipal::fromMembership($membership, $this->clock->timezone($request));
    }

    private function codeTaken(TenantMembership $membership, string $code): bool
    {
        return SavedQuery::query()
            ->where('tenant_id', $membership->tenant_id)
            ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->exists();
    }

    /** Kode dari nama yang belum dipakai di tenant ini: `nama`, `nama-2`, `nama-3`, … */
    private function freeCode(TenantMembership $membership, string $name): string
    {
        $base = Str::limit(Str::slug($name), 70, '');
        $base = $base === '' ? 'query' : rtrim($base, '-');

        for ($n = 1; ; $n++) {
            $code = $n === 1 ? $base : "{$base}-{$n}";
            if (! $this->codeTaken($membership, $code)) {
                return $code;
            }
        }
    }
}
