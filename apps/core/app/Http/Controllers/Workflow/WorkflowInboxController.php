<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Support\WorkflowRuntime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkflowInboxController extends Controller
{
    public function index(Request $request): Response
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->status === 'active', 403);

        $items = \DB::table('workflow_work_items as items')
            ->join('workflow_instances as instances', 'instances.id', '=', 'items.instance_id')
            ->join('workflow_types as types', 'types.id', '=', 'instances.workflow_type_id')
            ->join('apps', 'apps.id', '=', 'types.app_id')
            ->where('items.tenant_id', $membership->tenant_id)->where('items.assigned_membership_id', $membership->id)->where('items.status', 'pending')
            ->latest('items.created_at')
            ->get(['items.id', 'items.created_at', 'instances.source_document_type', 'instances.source_document_id', 'types.name as workflow_name', 'apps.name as app_name']);

        return Inertia::render('workflow-inbox', ['items' => $items]);
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
