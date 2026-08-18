<?php

namespace App\Http\Controllers\NumberSequence;

use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Actions\NumberSequence\NumberSequenceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\NumberSequence\InternalNumberSequenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalNumberSequenceController extends Controller
{
    public function issue(InternalNumberSequenceRequest $request, string $reference, NumberSequenceService $service, EnsureNumberSequenceDrafts $drafts): JsonResponse
    {
        $context = $this->context($request);
        $drafts->forTenantAndApp($context['tenant_id'], $context['app_id']);

        return response()->json(['data' => $service->issue($context, $reference, $request->string('idempotency_key')->toString(), $request->string('manual_value')->toString() ?: null)]);
    }

    public function reserve(InternalNumberSequenceRequest $request, string $reference, NumberSequenceService $service, EnsureNumberSequenceDrafts $drafts): JsonResponse
    {
        $context = $this->context($request);
        $drafts->forTenantAndApp($context['tenant_id'], $context['app_id']);

        return response()->json(['data' => $service->reserve($context, $reference, $request->string('idempotency_key')->toString())], 201);
    }

    public function confirm(Request $request, string $reservation, NumberSequenceService $service): JsonResponse
    {
        return response()->json(['data' => $service->confirm($this->context($request), $reservation)]);
    }

    public function cancel(Request $request, string $reservation, NumberSequenceService $service): JsonResponse
    {
        return response()->json(['data' => $service->cancel($this->context($request), $reservation)]);
    }

    /** @return array{tenant_id:string,app_id:string,legal_entity_id:?string,org_unit_id:?string} */
    private function context(Request $request): array
    {
        return [
            'tenant_id' => (string) $request->attributes->get('coreerp.tenant_id'),
            'app_id' => (string) $request->attributes->get('coreerp.app_id'),
            'legal_entity_id' => $request->string('legal_entity_id')->toString() ?: null,
            'org_unit_id' => $request->string('org_unit_id')->toString() ?: null,
        ];
    }
}
