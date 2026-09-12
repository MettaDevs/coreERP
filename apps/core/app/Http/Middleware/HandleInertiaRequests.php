<?php

namespace App\Http\Middleware;

use App\Models\CoreApp;
use App\Models\Environment;
use App\Models\TenantMembership;
use App\Support\CurrentWorkspace;
use App\Support\LaunchableAppCatalog;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $workspace = app(CurrentWorkspace::class);
        $memberships = $workspace->memberships($request);
        $membership = $workspace->membership($request);
        $organizations = $membership ? $workspace->organizations($membership) : collect();
        $legalEntity = $membership ? $workspace->legalEntity($request, $membership) : null;
        $orgUnit = $membership ? $workspace->operatingUnit($request, $membership) : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            /*
             * Lingkungan yang sedang dilayani, dan HANYA ketika ia bukan produksi.
             *
             * Null adalah keadaan biasa, bukan kekurangan: produksi, on-prem, dan setiap
             * penempatan satu-alamat tidak punya apa pun untuk diumumkan. Yang perlu diumumkan
             * justru kebalikannya — pengguna yang tidak tahu ia sedang di sandbox akan
             * memperlakukan angka sandbox sebagai angka sungguhan, lalu mengambil keputusan di
             * atasnya.
             */
            'lingkungan' => $this->lingkunganTampil($request),
            'auth' => [
                'user' => $user,
                'membership' => $membership ? [
                    'id' => $membership->id,
                    'system_role' => $membership->system_role,
                    'tenant_id' => $membership->tenant_id,
                    'tenant_name' => $membership->tenant->name,
                ] : null,
                'provider_admin' => $user?->providerAccess()->where('role', 'provider_admin')->exists() ?? false,
            ],
            'workspace' => [
                'memberships' => $memberships->map(fn (TenantMembership $item) => [
                    'id' => $item->id,
                    'tenant_id' => $item->tenant_id,
                    'tenant_name' => $item->tenant->name,
                    'system_role' => $item->system_role,
                ])->values(),
                'active_legal_entity' => $legalEntity ? [
                    'id' => $legalEntity->id,
                    'name' => $legalEntity->name,
                    'classification' => $legalEntity->classification,
                ] : null,
                'active_org_unit' => $orgUnit ? [
                    'id' => $orgUnit->id,
                    'name' => $orgUnit->name,
                    'classification' => $orgUnit->classification,
                ] : null,
                'legal_entities' => $organizations->where('classification', 'legal_entity')->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'classification' => $item->classification,
                ])->values(),
                'org_units' => $organizations->where('classification', 'operating_unit')->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'classification' => $item->classification,
                ])->values(),
            ],
            'entitledProducts' => fn (): array => $this->entitledProducts($membership),
            'launchableProducts' => fn (): array => $this->launchableProducts($membership),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => [
                'status' => fn (): ?string => $request->session()->get('status'),
                'error' => fn (): ?string => $request->session()->get('error'),
                'saved_id' => fn (): ?string => $request->session()->get('saved_id'),
                'saved_section' => fn (): ?string => $request->session()->get('saved_section'),
            ],
        ];
    }

    /**
     * @return list<array{id:string,name:string,description:string,href:string}>
     */
    private function entitledProducts(?TenantMembership $membership): array
    {
        if (! $membership) {
            return [];
        }

        $entitledIds = $membership->tenant->entitlements()
            ->where('status', 'active')
            ->whereRaw('(ends_at is null or ends_at > ?)', [now()])
            ->pluck('app_id');
        /** @var array<int, array<string, mixed>> $catalog */
        $catalog = $this->appCatalog();

        return array_values(collect($catalog)
            ->filter(fn (array $app): bool => $entitledIds->contains($app['id'] ?? null))
            ->map(fn (array $app): array => [
                'id' => (string) $app['id'],
                'name' => (string) $app['name'],
                'description' => (string) ($app['description'] ?? ''),
                'href' => '/apps/'.$app['id'],
            ])
            ->values()
            ->all());
    }

    /** @return list<array{id:string,name:string,description:string,href:string}> */
    private function launchableProducts(?TenantMembership $membership): array
    {
        if (! $membership) {
            return [];
        }

        return app(LaunchableAppCatalog::class)->for($membership);
    }

    /** @return list<array<string, mixed>> */
    private function appCatalog(): array
    {
        return CoreApp::query()
            ->where('status', 'available')
            ->where('has_ui', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (CoreApp $app): array => [
                'id' => $app->id,
                'name' => $app->name,
                'description' => $app->description ?? '',
            ])->all();
    }

    /**
     * Keterangan lingkungan untuk spanduk, atau null bila memang tidak ada yang perlu diumumkan.
     *
     * Dibaca dari atribut permintaan yang ditulis `TetapkanLingkungan`, bukan dengan query sendiri:
     * dua tempat yang menjawab "lingkungan mana ini" adalah dua tempat yang dapat menjawab berbeda,
     * dan yang berbeda di sini berbentuk spanduk yang menyebut tempat yang salah.
     *
     * @return array{jenis: string, nama: string}|null
     */
    private function lingkunganTampil(Request $request): ?array
    {
        $lingkungan = $request->attributes->get('coreerp.environment');

        if (! $lingkungan instanceof Environment || $lingkungan->produksi()) {
            return null;
        }

        return ['jenis' => $lingkungan->kind, 'nama' => $lingkungan->name];
    }
}
