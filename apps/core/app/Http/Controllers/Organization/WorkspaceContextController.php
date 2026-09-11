<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\WorkspaceContextRequest;
use App\Models\Organization;
use App\Models\TenantMembership;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class WorkspaceContextController extends Controller
{
    public function update(WorkspaceContextRequest $request, CurrentWorkspace $workspace): JsonResponse|RedirectResponse
    {
        $membership = $request->user()->memberships()->with('tenant')->where('status', 'active')->find($request->string('membership_id')->toString());
        if (! $membership instanceof TenantMembership) {
            throw ValidationException::withMessages(['membership_id' => 'Tenant tidak tersedia untuk identity ini.']);
        }

        $organizations = $workspace->organizations($membership);
        $legalEntity = $this->selection($organizations, $request->string('legal_entity_id')->toString(), 'legal_entity', 'legal_entity_id');
        $operatingUnit = $this->selection($organizations, $request->string('org_unit_id')->toString(), 'operating_unit', 'org_unit_id');
        $workspace->activate($request, $membership, $legalEntity, $operatingUnit);

        if ($request->header('X-Inertia')) {
            return back(303);
        }

        return response()->json(['data' => [
            'membership_id' => $membership->id,
            'tenant_id' => $membership->tenant_id,
            'legal_entity_id' => $legalEntity?->id,
            'org_unit_id' => $operatingUnit?->id,
        ]]);
    }

    /** @param Collection<int, Organization> $organizations */
    private function selection(Collection $organizations, string $requestedId, string $classification, string $field): ?Organization
    {
        $available = $organizations->where('classification', $classification);
        $selected = $requestedId !== '' ? $available->firstWhere('id', $requestedId) : $available->first();
        if ($requestedId !== '' && ! $selected) {
            throw ValidationException::withMessages([$field => 'Organisasi tidak tersedia dalam scope akses Anda.']);
        }

        return $selected;
    }
}
