<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Environments;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\Environment;
use ControlPlane\Models\Site;
use ControlPlane\Models\Tenant;
use ControlPlane\Sites\InstallProgress;
use ControlPlane\Sites\SiteOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Semua lingkungan yang dikenal sistem, satu tabel.
 *
 * Daftar dibaca dalam batch terbatas, bukan seluruh registry pada setiap pembukaan halaman.
 *
 * Produksi di server klien membawa keadaan pemasangannya (`serverClient`), dihitung `InstallProgress`
 * yang sama dengan panel di halaman rinciannya — satu kata di dua layar, bukan dua tafsiran.
 */
class Index extends Controller
{
    public function __invoke(Request $request, SiteOperations $operations): InertiaResponse|JsonResponse
    {
        if (! $request->wantsJson()) {
            return Inertia::render('environments/index', [
                'hasTenants' => Tenant::query()->exists(),
                'kinds' => Environment::KINDS,
            ]);
        }

        $input = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'cursor' => ['nullable', 'string', 'max:2048']]);
        $cursor = isset($input['cursor']) ? Cursor::fromEncoded($input['cursor']) : null;
        if (isset($input['cursor']) && ($cursor === null
            || ! is_string($cursor->toArray()['id'] ?? null)
            || ! is_string($cursor->toArray()['tenant_id'] ?? null))) {
            throw ValidationException::withMessages(['cursor' => 'Posisi daftar tidak valid. Muat ulang daftar.']);
        }

        $query = Environment::query()
            ->with('tenant:id,name,slug')
            ->whereNull('deleted_at');
        if (($search = trim($input['search'] ?? '')) !== '') {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$pattern])
                ->orWhereHas('tenant', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$pattern])));
        }
        $batch = $query->orderBy('tenant_id')->orderByDesc('id')->cursorPaginate(50, ['*'], 'cursor', $cursor);
        $rows = $batch->getCollection();

        $onClientServer = $rows->filter(fn (Environment $e): bool => $e->runsOnClientServer());
        $sites = Site::query()
            ->whereIn('environment_id', $onClientServer->pluck('id')->all())
            ->get()
            ->keyBy('environment_id');

        $operations->expireStaleForSites($sites);

        $progress = InstallProgress::forSites($sites->all());

        $environments = $rows
            ->map(function (Environment $e) use ($sites, $progress): array {
                $site = $sites->get($e->id);

                return $e->forScreen() + [
                    'serverClient' => $e->runsOnClientServer()
                        ? ($site instanceof Site ? $progress[$site->id] : InstallProgress::forSite(null))
                        : null,
                    'site' => $site instanceof Site ? [
                        'id' => $site->id,
                        'serverAddress' => $site->server_address,
                    ] : null,
                ];
            })
            ->all();

        return response()->json([
            'data' => $environments,
            'next_cursor' => $batch->nextCursor()?->encode(),
        ]);
    }
}
