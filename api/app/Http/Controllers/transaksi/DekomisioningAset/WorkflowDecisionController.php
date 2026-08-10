<?php

namespace App\Http\Controllers\transaksi\DekomisioningAset;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowDecisionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $event = $request->validate([
            'id' => ['required', 'ulid'], 'type' => ['required', 'in:core.workflow.decision.v2'],
            'tenant_id' => ['required', 'ulid'], 'data' => ['required', 'array'],
            // Required by the v2 envelope: it is the only key that ties this decision back
            // to the request that started it, days earlier and in another service.
            'correlation_id' => ['required', 'ulid'],
            'legal_entity_id' => ['nullable', 'ulid'],
            'data.workflow_instance_id' => ['required', 'ulid'],
            'data.workflow_type' => ['required', 'in:management-aset.dekomisioning-aset-verification'],
            'data.decision' => ['required', 'in:approved,rejected'],
            'data.source_document_type' => ['required', 'in:dekomisioning-aset'],
            'data.source_document_id' => ['required', 'ulid'],
            'data.decision_context.asset_id' => ['required', 'ulid'],
        ]);

        DB::transaction(function () use ($event): void {
            if (DB::table('processed_core_events')->insertOrIgnore([
                'id' => (string) Str::ulid(), 'tenant_id' => $event['tenant_id'], 'event_id' => $event['id'],
                'processed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]) === 0) {
                return;
            }
            $document = DB::table('tr_dokumen_siklus_aset')->where([
                'tenant_id' => $event['tenant_id'], 'id' => $event['data']['source_document_id'],
                'jenis_dokumen' => 'dekomisioning-aset', 'workflow_instance_id' => $event['data']['workflow_instance_id'],
            ])->lockForUpdate()->first();
            abort_unless($document && $document->asset_id === $event['data']['decision_context']['asset_id'], 404);
            DB::table('tr_dokumen_siklus_aset')->where('id', $document->id)->update(['status' => $event['data']['decision'], 'updated_at' => now()]);
            if ($event['data']['decision'] === 'approved') {
                DB::table('tr_penerimaan_aset')->where(['tenant_id' => $event['tenant_id'], 'id' => $document->asset_id])
                    ->whereNotIn('lifecycle_state', ['disposed'])->update(['lifecycle_state' => 'decommissioned', 'updated_at' => now()]);
            }
        });

        return response()->json(['data' => ['accepted' => true]]);
    }
}
