<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\Organization;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

final class CurrentWorkspace
{
    private const MEMBERSHIP_KEY = 'workspace.membership_id';

    private const LEGAL_ENTITY_KEY = 'workspace.legal_entity_id';

    private const OPERATING_UNIT_KEY = 'workspace.org_unit_id';

    /**
     * Keanggotaan dan organisasi yang sudah dibaca pada permintaan ini.
     *
     * Kelas ini ditanyai berkali-kali dalam satu permintaan oleh pihak yang berbeda — middleware
     * konteks module, penyusun prop Inertia, dan penentu entitas legal serta unit operasi — dan
     * tiap pemanggilan dulu berujung query baru dengan parameter yang sama. Diukur pada satu
     * permintaan daftar sederhana: 22 query, hanya **satu** di antaranya mengambil data yang
     * diminta; `tenant_memberships` dibaca empat kali dan `organizations` lima kali.
     *
     * Ingatan ini hanya berlaku selama satu permintaan. Ikatannya `scoped()`, bukan `singleton()`,
     * supaya ia benar juga pada pekerja yang hidup lama.
     *
     * @var array<string, Collection<int, TenantMembership>>
     */
    private array $ingatanKeanggotaan = [];

    /** @var array<string, Collection<int, Organization>> */
    private array $ingatanOrganisasi = [];

    /** @return Collection<int, TenantMembership> */
    public function memberships(Request $request): Collection
    {
        $user = $request->user();
        if (! $user) {
            return new Collection;
        }

        $tenantOfAddress = $this->tenantOfAddress($request);
        $kunci = $user->getAuthIdentifier().'|'.($tenantOfAddress ?? '*');

        if (array_key_exists($kunci, $this->ingatanKeanggotaan)) {
            return $this->ingatanKeanggotaan[$kunci];
        }

        return $this->ingatanKeanggotaan[$kunci] = $user->memberships()
            ->with('tenant')
            ->where('status', 'active')
            ->when($tenantOfAddress !== null, fn ($query) => $query->where('tenant_id', $tenantOfAddress))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Tenant pemilik alamat yang sedang dibuka, atau null bila alamat ini bukan milik tenant mana pun.
     *
     * Di alamat sebuah tenant, **hanya** keanggotaan di tenant itu yang dipertimbangkan — keanggotaan
     * di tenant lain tidak ada artinya di sini, sekalipun sesi mengingatnya sebagai pilihan terakhir.
     * Sebelum ini alamat dan sesi tidak pernah dicocokkan: anggota tenant B yang membuka alamat
     * tenant A dilayani dengan workspace B, di atas koneksi yang sudah digeser ke database A.
     *
     * Null pada on-prem, pengembangan tanpa domain dasar, dan alamat pangkal — di sana perilakunya
     * sama persis seperti sebelum ada alamat per tenant. Atributnya ditulis `ResolveEnvironment`;
     * dibaca dari sana, bukan dengan query sendiri, supaya "lingkungan mana ini" hanya punya satu
     * jawaban.
     */
    public function tenantOfAddress(Request $request): ?string
    {
        $environment = $request->attributes->get('coreerp.environment');

        return $environment instanceof Environment ? $environment->tenant_id : null;
    }

    public function membership(Request $request): ?TenantMembership
    {
        $memberships = $this->memberships($request);
        $membership = $memberships->firstWhere('id', $request->session()->get(self::MEMBERSHIP_KEY)) ?? $memberships->first();
        if ($membership) {
            $request->session()->put(self::MEMBERSHIP_KEY, $membership->id);
        } else {
            $request->session()->forget([self::MEMBERSHIP_KEY, self::LEGAL_ENTITY_KEY, self::OPERATING_UNIT_KEY]);
        }

        return $membership;
    }

    /** @return Collection<int, Organization> */
    public function organizations(TenantMembership $membership): Collection
    {
        $kunci = (string) $membership->id;

        if (array_key_exists($kunci, $this->ingatanOrganisasi)) {
            return $this->ingatanOrganisasi[$kunci];
        }

        return $this->ingatanOrganisasi[$kunci] = $this->bacaOrganisasi($membership);
    }

    /** @return Collection<int, Organization> */
    private function bacaOrganisasi(TenantMembership $membership): Collection
    {
        $query = Organization::query()->where('tenant_id', $membership->tenant_id)->where('status', 'active')->orderBy('name');
        $policies = app(DataPolicyAccessResolver::class)->resolve($membership);
        if (collect($policies)->contains(fn (array $scope): bool => $scope['all'])) {
            return $query->get();
        }
        $organizationIds = collect($policies)
            ->flatMap(fn (array $scope): array => $scope['scope_grants'])
            ->flatMap(fn (array $grant): array => array_filter([
                $grant['legal_entity_id'],
                ...$grant['operating_unit_ids'],
            ]))
            ->unique()
            ->values();

        return $organizationIds->isEmpty() ? new Collection : $query->whereIn('id', $organizationIds)->get();
    }

    public function legalEntity(Request $request, TenantMembership $membership): ?Organization
    {
        return $this->selected($request, $membership, 'legal_entity', self::LEGAL_ENTITY_KEY);
    }

    public function operatingUnit(Request $request, TenantMembership $membership): ?Organization
    {
        return $this->selected($request, $membership, 'operating_unit', self::OPERATING_UNIT_KEY);
    }

    public function activate(Request $request, TenantMembership $membership, ?Organization $legalEntity, ?Organization $operatingUnit): void
    {
        // Pindah tenant mengubah jawaban seluruh pertanyaan di atas, jadi ingatannya dibuang.
        // Tanpa ini, permintaan yang berganti tenant di tengah jalan akan terus menjawab dengan
        // tenant sebelumnya — persis jenis kesalahan yang tidak pernah gagal, hanya salah.
        $this->ingatanKeanggotaan = [];
        $this->ingatanOrganisasi = [];

        $request->session()->put(self::MEMBERSHIP_KEY, $membership->id);
        $this->storeSelection($request, self::LEGAL_ENTITY_KEY, $legalEntity);
        $this->storeSelection($request, self::OPERATING_UNIT_KEY, $operatingUnit);
    }

    private function selected(Request $request, TenantMembership $membership, string $classification, string $key): ?Organization
    {
        $organizations = $this->organizations($membership)->where('classification', $classification);
        $selected = $organizations->firstWhere('id', $request->session()->get($key)) ?? $organizations->first();
        $this->storeSelection($request, $key, $selected);

        return $selected;
    }

    private function storeSelection(Request $request, string $key, ?Organization $organization): void
    {
        $organization ? $request->session()->put($key, $organization->id) : $request->session()->forget($key);
    }
}
