<?php

namespace Modules\Apperp\ManagementAset\Listeners;

use App\Platform\Modules\Contracts\WorkflowDecisionTaken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Models\transaksi\Cancellation\AssetCancellation;
use Modules\Apperp\ManagementAset\Services\AssetCancellationEngine;

final class ApplyAssetCancellationDecision
{
    public function handle(WorkflowDecisionTaken $event): void
    {
        if (($event->data['source_document_type'] ?? null) !== 'pembatalan-aset') {
            return;
        }
        $cancellation = AssetCancellation::query()->where('tenant_id', $event->tenantId)
            ->whereKey($event->data['source_document_id'] ?? '')->lockForUpdate()->first();
        if ($cancellation === null || $cancellation->status !== 'pending'
            || ($event->data['workflow_type'] ?? null) !== 'management-aset.'.$cancellation->resource.'-cancellation'
            || ($cancellation->workflow_instance_id !== null && $cancellation->workflow_instance_id !== ($event->data['workflow_instance_id'] ?? null))) {
            return;
        }
        if (($event->data['decision'] ?? null) === 'rejected') {
            $cancellation->update(['status' => 'rejected', 'acted_by_user_id' => $event->actorUserId]);

            return;
        }
        if (($event->data['decision'] ?? null) !== 'approved' || $event->actorUserId === null) {
            return;
        }
        try {
            DB::transaction(fn () => app(AssetCancellationEngine::class)->apply($cancellation, $event->actorUserId));
        } catch (ValidationException $failure) {
            // Keputusan tetap tercatat, tetapi transaksi yang berubah tidak dibatalkan diam-diam.
            $cancellation->update(['status' => 'blocked', 'acted_by_user_id' => $event->actorUserId, 'failure_message' => $failure->validator->errors()->first()]);
        }
    }
}
