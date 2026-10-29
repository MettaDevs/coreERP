<?php

namespace App\Foundation\Workflow\Http\Controllers;

use App\Foundation\Workflow\Support\WorkflowApprovalAuthority;
use App\Foundation\Workflow\Support\WorkflowRuntime;
use App\Http\Controllers\Controller;
use App\Platform\Modules\Contracts\IncrementalList;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class WorkflowInboxController extends Controller
{
    public function index(Request $request, WorkflowApprovalAuthority $authority): Response|JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->status === 'active', 403);

        $query = \DB::table('workflow_work_items as items')
            ->join('workflow_instances as instances', 'instances.id', '=', 'items.instance_id')
            ->join('workflow_types as types', 'types.id', '=', 'instances.workflow_type_id')
            ->join('apps', 'apps.id', '=', 'types.app_id')
            ->where('items.tenant_id', $membership->tenant_id)->where('items.assigned_membership_id', $membership->id)->where('items.status', 'pending')
            ->select(['items.id', 'items.created_at', 'items.email_notified_at', 'items.email_last_error', 'instances.id as instance_id', 'instances.workflow_type_id', 'instances.tenant_id', 'instances.initiator_membership_id', 'instances.decision_context', 'instances.source_document_type', 'instances.source_document_id', 'types.name as workflow_name', 'apps.name as app_name']);
        $filter = $authority->filterFor($membership);
        if ($request->wantsJson()) {
            $input = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
            $size = (int) ($input['per_page'] ?? 40);
            $cursor = $request->query('cursor');
            $items = collect();
            do {
                $batchRequest = $request->duplicate([...$request->query(), 'cursor' => $cursor, 'per_page' => $size - $items->count()]);
                $batch = $this->batch($batchRequest, clone $query);
                $items = $items->merge($filter($batch['data']));
                $cursor = $batch['next_cursor'];
            } while ($items->count() < $size && $cursor !== null);

            return response()->json(['data' => $items->map($this->present(...))->values(), 'next_cursor' => $cursor]);
        }
        $input = $request->validate(['item' => ['nullable', 'ulid']]);
        $target = isset($input['item']) ? (clone $query)->where('items.id', $input['item'])->first() : null;
        $targetItem = $target ? $filter(collect([$target]))->first() : null;

        return Inertia::render('foundation/workflow/workflow-inbox', ['targetItem' => $targetItem ? $this->present($targetItem) : null]);
    }

    /** @return array{data: Collection<int, \stdClass>, next_cursor: ?string} */
    private function batch(Request $request, Builder $query): array
    {
        return IncrementalList::query($request, $query, ['created_at' => 'created_at'], ['workflow_name', 'app_name', 'source_document_id'], 'created_at', defaultDirection: 'desc');
    }

    private function present(\stdClass $item): \stdClass
    {
        $context = json_decode($item->decision_context, true, 512, JSON_THROW_ON_ERROR);
        $item->document_number = (string) ($context['document_number'] ?? $item->source_document_id);
        $item->reason = (string) ($context['reason'] ?? '');
        $path = $context['document_url'] ?? null;
        $item->document_url = is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : null;
        unset($item->decision_context, $item->instance_id, $item->workflow_type_id, $item->tenant_id, $item->initiator_membership_id);

        return $item;
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
