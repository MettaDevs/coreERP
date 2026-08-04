<?php

namespace App\Http\Controllers\Organization;

use App\Actions\Organization\CreateOrganization;
use App\Actions\Organization\CreateOrganizationHierarchy;
use App\Actions\Organization\CreateOrganizationHierarchyDraft;
use App\Actions\Organization\PlaceOrganizationInHierarchy;
use App\Actions\Organization\PublishOrganizationHierarchy;
use App\Actions\Organization\UnplaceOrganizationFromHierarchy;
use App\Actions\Organization\UpdateOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\HierarchyRequest;
use App\Http\Requests\Organization\OrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Models\HierarchyPurpose;
use App\Models\Organization;
use App\Models\OrganizationHierarchy;
use App\Models\OrganizationHierarchyNode;
use App\Models\OrganizationHierarchyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);
        $organizations = Organization::query()
            ->where('tenant_id', $membership->tenant_id)
            ->with(['legalEntity', 'operatingUnit'])
            ->orderBy('name')
            ->get();

        if ($request->is('api/*')) {
            return response()->json(['data' => $organizations]);
        }

        $hierarchies = OrganizationHierarchy::query()
            ->where('tenant_id', $membership->tenant_id)
            ->with([
                'purposes:id,code,name',
                'versions' => fn ($query) => $query->orderByDesc('version_number'),
                'versions.nodes.organization:id,name,classification',
                'versions.nodes.parentNode.organization:id,name',
            ])
            ->orderBy('name')
            ->get();

        return Inertia::render('settings/organization', [
            'canManage' => $membership->canManageAccess(),
            'tenant' => $membership->tenant->only(['id', 'name']),
            'organizations' => $organizations,
            'hierarchies' => $hierarchies,
            'purposes' => HierarchyPurpose::query()->orderBy('name')->get(['code', 'name', 'description']),
            'operatingUnitTypes' => config('coreerp.operating_unit_types'),
        ]);
    }

    public function store(OrganizationRequest $request, CreateOrganization $action): JsonResponse|RedirectResponse
    {
        $organization = $action->handle($this->currentMembership($request), $request->payload());

        return $request->is('api/*')
            ? response()->json(['data' => $organization], 201, ['Location' => "/api/v1/organizations/{$organization->id}"])
            : back()->with('status', 'Organisasi dibuat.');
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization, UpdateOrganization $action): JsonResponse|RedirectResponse
    {
        $organization = $action->handle($this->currentMembership($request), $organization, $request->payload());

        return $request->is('api/*')
            ? response()->json(['data' => $organization])
            : back()->with('status', 'Organisasi diperbarui.');
    }

    public function storeHierarchy(HierarchyRequest $request, CreateOrganizationHierarchy $action): RedirectResponse
    {
        $action->handle($this->currentMembership($request), $request->payload());

        return back()->with('status', 'Draft hierarchy dibuat.');
    }

    public function place(Request $request, OrganizationHierarchyVersion $version, PlaceOrganizationInHierarchy $action): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string'],
            'parent_organization_id' => ['required', 'string'],
        ]);
        $action->handle(
            $this->currentMembership($request),
            $version,
            $data['organization_id'],
            $data['parent_organization_id'],
        );

        return back()->with('status', 'Organisasi ditempatkan pada draft hierarchy.');
    }

    public function unplace(Request $request, OrganizationHierarchyVersion $version, OrganizationHierarchyNode $node, UnplaceOrganizationFromHierarchy $action): RedirectResponse
    {
        $action->handle($this->currentMembership($request), $version, $node);

        return back()->with('status', 'Penempatan organisasi dibatalkan.');
    }

    public function createDraft(Request $request, OrganizationHierarchyVersion $version, CreateOrganizationHierarchyDraft $action): RedirectResponse
    {
        $data = $request->validate(['effective_from' => ['required', 'date']]);
        $action->handle($this->currentMembership($request), $version, $data['effective_from']);

        return back()->with('status', 'Draft versi baru dibuat. Susun perubahan lalu publikasikan.');
    }

    public function publish(Request $request, OrganizationHierarchyVersion $version, PublishOrganizationHierarchy $action): RedirectResponse
    {
        $action->handle($this->currentMembership($request), $version);

        return back()->with('status', 'Hierarchy dipublikasikan.');
    }
}
