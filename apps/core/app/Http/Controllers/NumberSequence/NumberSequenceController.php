<?php

namespace App\Http\Controllers\NumberSequence;

use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Actions\NumberSequence\NumberSequenceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\NumberSequence\NumberSequenceSettingsRequest;
use App\Models\TenantNumberSequence;
use App\Support\Finance\CoreNumberSequences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NumberSequenceController extends Controller
{
    public function index(Request $request, EnsureNumberSequenceDrafts $drafts, CoreNumberSequences $core): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);
        $drafts->forReadyTenant($membership->tenant_id);
        $core->ensureAll($membership->tenant_id);
        $sequences = TenantNumberSequence::query()->where('tenant_id', $membership->tenant_id)->with('reference.app')->orderBy('created_at')->get()
            ->map(fn (TenantNumberSequence $sequence): array => $this->present($sequence));

        if ($request->is('api/*')) {
            return response()->json(['data' => $sequences]);
        }

        return Inertia::render('settings/number-sequences', [
            'canManage' => $request->user()->can('manage-number-sequences'),
            'tenant' => $membership->tenant->only(['id', 'name']),
            'sequences' => $sequences,
            'profiles' => \DB::table('number_sequence_profiles')->orderBy('name')->get(['code', 'name']),
        ]);
    }

    public function update(NumberSequenceSettingsRequest $request, TenantNumberSequence $sequence, NumberSequenceService $service): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($sequence->tenant_id === $membership->tenant_id, 404);
        $sequence->load('reference');
        $sequence = $service->configure($sequence, $request->payload(), $request->user()->id);

        return $request->is('api/*')
            ? response()->json(['data' => $this->present($sequence)])
            : back()->with('status', 'Pengaturan nomor disimpan.');
    }

    public function advance(Request $request, TenantNumberSequence $sequence, NumberSequenceService $service): JsonResponse
    {
        abort_unless($request->user()?->can('manage-number-sequences'), 403);
        $membership = $this->currentMembership($request);
        abort_unless($sequence->tenant_id === $membership->tenant_id, 404);
        $data = $request->validate([
            'next_number' => ['required', 'integer', 'min:1'],
            'legal_entity_id' => ['nullable', 'string'],
            'org_unit_id' => ['nullable', 'string'],
        ]);
        $service->advance($sequence, [
            'tenant_id' => $membership->tenant_id,
            'legal_entity_id' => $data['legal_entity_id'] ?? null,
            'org_unit_id' => $data['org_unit_id'] ?? null,
        ], (int) $data['next_number'], $request->user()->id);

        return response()->json(['message' => 'Nomor berikutnya diperbarui.']);
    }

    /** @return array<string, mixed> */
    private function present(TenantNumberSequence $sequence): array
    {
        return [
            'id' => $sequence->id,
            'reference_code' => $sequence->reference->code,
            'reference_name' => $sequence->reference->name,
            'app_name' => $sequence->reference->app->name,
            'allowed_scopes' => $sequence->reference->allowed_scopes,
            'profile_code' => $sequence->profile_code,
            'scope_type' => $sequence->scope_type,
            'status' => $sequence->status,
            'is_continuous' => $sequence->is_continuous,
            'allow_manual' => $sequence->allow_manual,
            'reset_period' => $sequence->reset_period,
            'preallocation_enabled' => $sequence->preallocation_enabled,
            'preallocation_quantity' => $sequence->preallocation_quantity,
            'minimum_number' => $sequence->minimum_number,
            'maximum_number' => $sequence->maximum_number,
            'segments' => $sequence->segments,
        ];
    }
}
