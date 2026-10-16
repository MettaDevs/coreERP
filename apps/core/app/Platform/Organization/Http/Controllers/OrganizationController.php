<?php

namespace App\Platform\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Organization\Actions\CreateOrganization;
use App\Platform\Organization\Actions\CreateOrganizationHierarchy;
use App\Platform\Organization\Actions\CreateOrganizationHierarchyDraft;
use App\Platform\Organization\Actions\PlaceOrganizationInHierarchy;
use App\Platform\Organization\Actions\PublishOrganizationHierarchy;
use App\Platform\Organization\Actions\UnplaceOrganizationFromHierarchy;
use App\Platform\Organization\Actions\UpdateOrganization;
use App\Platform\Organization\Http\Requests\HierarchyRequest;
use App\Platform\Organization\Http\Requests\OrganizationRequest;
use App\Platform\Organization\Http\Requests\UpdateOrganizationRequest;
use App\Platform\Organization\Models\HierarchyPurpose;
use App\Platform\Organization\Models\Organization;
use App\Platform\Organization\Models\OrganizationHierarchyNode;
use App\Platform\Organization\Models\OrganizationHierarchyVersion;
use App\Platform\Organization\Support\OrganizationPageRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function directory(Request $request): RedirectResponse
    {
        return redirect()->route(match ($request->string('section')->toString()) {
            'operating-units' => 'organization.operating-units',
            'hierarchies' => 'organization.hierarchies',
            default => 'organization.legal-entities',
        });
    }

    public function index(Request $request): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);
        $section = $request->route('section');
        $section = in_array($section, ['legal-entities', 'operating-units', 'hierarchies'], true)
            ? $section
            : 'legal-entities';
        if ($request->boolean('incremental')) {
            return response()->json(OrganizationPageRecords::batch($request, $membership->tenant_id, $section));
        }
        if ($request->is('api/*')) {
            return response()->json(['data' => Organization::query()->where('tenant_id', $membership->tenant_id)->with(['legalEntity', 'operatingUnit'])->orderBy('name')->get()]);
        }
        $savedOrganizationId = $request->session()->get('saved_organization_id');
        $savedHierarchyId = $request->session()->get('saved_hierarchy_id');
        $props = [
            'scopeKey' => $membership->tenant_id,
            'canManage' => $membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE),
            'operatingUnitTypes' => config('coreerp.operating_unit_types'),
            'organizations' => [],
        ];
        if ($section !== 'hierarchies') {
            return Inertia::render('platform/organization/'.$section, $props + [
                'savedOrganization' => $savedOrganizationId ? Organization::query()->where('tenant_id', $membership->tenant_id)->with(['legalEntity', 'operatingUnit'])->find($savedOrganizationId) : null,
                'timezones' => UserClock::options(),
            ]);
        }

        return Inertia::render('platform/organization/hierarchies', $props + [
            'hierarchies' => [],
            'savedHierarchy' => $savedHierarchyId ? OrganizationPageRecords::hierarchy($membership->tenant_id, $savedHierarchyId) : null,
            'purposes' => HierarchyPurpose::query()->orderBy('name')->get(['code', 'name', 'description']),
        ]);
    }

    public function store(OrganizationRequest $request, CreateOrganization $action): JsonResponse|RedirectResponse
    {
        $organization = $action->handle($this->currentMembership($request), $request->payload());

        return $request->is('api/*')
            ? response()->json(['data' => $organization], 201, ['Location' => "/api/v1/organizations/{$organization->id}"])
            : back()->with('saved_organization_id', $organization->id)->with('status', 'Organisasi dibuat.');
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization, UpdateOrganization $action): JsonResponse|RedirectResponse
    {
        $organization = $action->handle($this->currentMembership($request), $organization, $request->payload(), RowVersion::expected($request));

        return $request->is('api/*')
            ? response()->json(['data' => $organization])
            : back()->with('saved_organization_id', $organization->id)->with('status', 'Organisasi diperbarui.');
    }

    public function storeHierarchy(HierarchyRequest $request, CreateOrganizationHierarchy $action): RedirectResponse
    {
        $hierarchy = $action->handle($this->currentMembership($request), $request->payload());

        return back()->with('saved_hierarchy_id', $hierarchy->id)->with('status', 'Draft hierarchy dibuat.');
    }

    public function place(Request $request, OrganizationHierarchyVersion $version, PlaceOrganizationInHierarchy $action): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string'],
            'parent_organization_id' => ['required', 'string'],
        ]);
        DB::transaction(function () use ($request, $version, $action, $data): void {
            $this->claimHierarchy($request, $version);
            $action->handle(
                $this->currentMembership($request),
                $version,
                $data['organization_id'],
                $data['parent_organization_id'],
            );
        });

        return back()->with('saved_hierarchy_id', $version->hierarchy_id)->with('status', 'Organisasi ditempatkan pada draft hierarchy.');
    }

    public function unplace(Request $request, OrganizationHierarchyVersion $version, OrganizationHierarchyNode $node, UnplaceOrganizationFromHierarchy $action): RedirectResponse
    {
        DB::transaction(function () use ($request, $version, $node, $action): void {
            $this->claimHierarchy($request, $version);
            $action->handle($this->currentMembership($request), $version, $node);
        });

        return back()->with('saved_hierarchy_id', $version->hierarchy_id)->with('status', 'Penempatan organisasi dibatalkan.');
    }

    public function createDraft(Request $request, OrganizationHierarchyVersion $version, CreateOrganizationHierarchyDraft $action): RedirectResponse
    {
        $data = $request->validate(['effective_from' => ['required', 'date']]);
        DB::transaction(function () use ($request, $version, $action, $data): void {
            $this->claimHierarchy($request, $version);
            $action->handle($this->currentMembership($request), $version, $data['effective_from']);
        });

        return back()->with('saved_hierarchy_id', $version->hierarchy_id)->with('status', 'Draft versi baru dibuat. Susun perubahan lalu publikasikan.');
    }

    public function publish(Request $request, OrganizationHierarchyVersion $version, PublishOrganizationHierarchy $action): RedirectResponse
    {
        DB::transaction(function () use ($request, $version, $action): void {
            $this->claimHierarchy($request, $version);
            $action->handle($this->currentMembership($request), $version);
        });

        return back()->with('saved_hierarchy_id', $version->hierarchy_id)->with('status', 'Hierarchy dipublikasikan.');
    }

    /**
     * Versi hierarchy tidak membawa kolom versi baris, jadi penyimpanan pada versi mana pun mengunci
     * hierarchy induknya: dua perubahan dari layar yang dibuka bersamaan tidak sama-sama lolos.
     * Hak diperiksa lebih dulu supaya pengguna tanpa hak tidak dapat menaikkan versinya; action tetap
     * memeriksa ulang.
     */
    private function claimHierarchy(Request $request, OrganizationHierarchyVersion $version): void
    {
        $membership = $this->currentMembership($request);
        if (! $membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE) || $version->hierarchy->tenant_id !== $membership->tenant_id) {
            throw new AuthorizationException;
        }

        RowVersion::claim($version->hierarchy, RowVersion::expected($request));
    }
}
