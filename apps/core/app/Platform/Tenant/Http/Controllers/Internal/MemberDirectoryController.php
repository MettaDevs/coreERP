<?php

namespace App\Platform\Tenant\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Platform\Modules\Contracts\RecordSearch;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('coreerp.app_id') === 'human-resources', 403);
        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        $input = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $query = trim((string) ($input['q'] ?? ''));
        $members = TenantMembership::query()->where('tenant_id', $tenantId)->where('status', 'active')->with('user:id,name,email')
            ->when($query !== '', fn ($builder) => $builder->whereHas('user', fn ($users) => RecordSearch::apply($users, ['name', 'email'], $query)))
            ->limit(20)->get()->map(fn (TenantMembership $member) => ['membership_id' => $member->id, 'name' => $member->user->name, 'email' => $member->user->email]);

        return response()->json(['data' => $members]);
    }

    public function show(Request $request, string $membership): JsonResponse
    {
        abort_unless($request->attributes->get('coreerp.app_id') === 'human-resources', 403);
        $member = TenantMembership::query()
            ->where('tenant_id', (string) $request->attributes->get('coreerp.tenant_id'))
            ->where('status', 'active')
            ->with('user:id,name,email')
            ->findOrFail($membership);

        return response()->json(['data' => [
            'membership_id' => $member->id,
            'name' => $member->user->name,
            'email' => $member->user->email,
        ]]);
    }
}
