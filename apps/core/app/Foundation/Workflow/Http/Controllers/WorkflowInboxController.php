<?php

namespace App\Foundation\Workflow\Http\Controllers;

use App\Foundation\Workflow\Support\WorkflowApprovalAuthority;
use App\Foundation\Workflow\Support\WorkflowRuntime;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkflowInboxController extends Controller
{
    public function index(Request $request, WorkflowApprovalAuthority $authority): Response
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->status === 'active', 403);

        $items = \DB::table('workflow_work_items as items')
            ->join('workflow_instances as instances', 'instances.id', '=', 'items.instance_id')
            ->join('workflow_types as types', 'types.id', '=', 'instances.workflow_type_id')
            ->join('apps', 'apps.id', '=', 'types.app_id')
            ->where('items.tenant_id', $membership->tenant_id)->where('items.assigned_membership_id', $membership->id)->where('items.status', 'pending')
            ->latest('items.created_at')
            ->get(['items.id', 'items.created_at', 'items.email_notified_at', 'items.email_last_error', 'instances.id as instance_id', 'instances.workflow_type_id', 'instances.tenant_id', 'instances.initiator_membership_id', 'instances.decision_context', 'instances.source_document_type', 'instances.source_document_id', 'types.name as workflow_name', 'apps.name as app_name'])
            ->filter(fn ($item) => $authority->allows($item, $membership))
            ->map(function ($item) {
                $context = json_decode($item->decision_context, true, 512, JSON_THROW_ON_ERROR);
                $item->document_number = (string) ($context['document_number'] ?? $item->source_document_id);
                $item->reason = (string) ($context['reason'] ?? '');
                $path = $context['document_url'] ?? null;
                $item->document_url = is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : null;
                unset($item->decision_context, $item->instance_id, $item->workflow_type_id, $item->tenant_id, $item->initiator_membership_id);

                return $item;
            })->values();

        return Inertia::render('foundation/workflow/workflow-inbox', ['items' => $items]);
    }

    public function decide(Request $request, string $workItem, WorkflowRuntime $runtime): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $runtime->decide($this->currentMembership($request), $workItem, $data['decision'], $data['comment'] ?? null);

        return back()->with('status', $data['decision'] === 'approve' ? 'Permintaan disetujui.' : 'Permintaan ditolak.');
    }
}
