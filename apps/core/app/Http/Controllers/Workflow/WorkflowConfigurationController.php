<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\TenantMembership;
use App\Support\DefinisiParameterWorkflow;
use App\Support\ParameterWorkflow;
use App\Support\WorkflowGraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkflowConfigurationController extends Controller
{
    public function index(Request $request, ParameterWorkflow $parameter): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);

        $types = DB::table('workflow_types as types')
            ->join('apps', 'apps.id', '=', 'types.app_id')
            ->orderBy('apps.name')->orderBy('types.name')
            ->get(['types.id', 'types.code', 'types.name', 'types.scope', 'apps.name as app_name']);
        $workflows = DB::table('workflow_configurations as configurations')
            ->join('workflow_types as types', 'types.id', '=', 'configurations.workflow_type_id')
            ->join('apps', 'apps.id', '=', 'types.app_id')
            ->where('configurations.tenant_id', $membership->tenant_id)
            ->orderBy('apps.name')->orderBy('configurations.name')
            ->get(['configurations.id', 'configurations.name', 'configurations.enabled', 'configurations.legal_entity_id', 'types.name as type_name', 'types.scope', 'apps.name as app_name'])
            ->map(function (object $workflow): array {
                $latestVersion = DB::table('workflow_configuration_versions')->where('configuration_id', $workflow->id)->orderByDesc('version')->first(['status']);

                return [
                    'id' => $workflow->id,
                    'name' => $workflow->name,
                    'enabled' => (bool) $workflow->enabled,
                    'status' => $workflow->enabled ? 'active' : ($latestVersion?->status === 'published' ? 'inactive' : 'draft'),
                    'type_name' => $workflow->type_name,
                    'app_name' => $workflow->app_name,
                    'scope' => $workflow->scope,
                    'legal_entity_id' => $workflow->legal_entity_id,
                ];
            });

        $payload = [
            'canManage' => true,
            // Disusun dari registry, bukan ditulis satu per satu. Layarnya merender dirinya
            // dari daftar ini, jadi parameter baru muncul di layar tanpa menyentuh berkas
            // controller maupun berkas halamannya.
            'parameters' => $this->parameterUntukLayar((string) $membership->tenant_id, $parameter),
            'workflowTypes' => $types,
            'legalEntities' => Organization::query()->where('tenant_id', $membership->tenant_id)->where('classification', 'legal_entity')->orderBy('name')->get(['id', 'name']),
            'workflows' => $workflows,
        ];

        return $request->is('api/*') ? response()->json(['data' => $payload]) : Inertia::render('settings/workflows', $payload);
    }

    /**
     * Mengubah parameter workflow milik tenant.
     *
     * Baru satu parameter: boleh atau tidak pengaju menyetujui dokumennya sendiri. Sebelumnya
     * itu aturan mati di dalam mesin, dan aturan mati adalah jalan buntu untuk organisasi yang
     * penggunanya rangkap jabatan — mesin ini belum punya delegasi, jadi tugas yang jatuh ke
     * pengajunya sendiri tidak bisa diselesaikan siapa pun.
     *
     * Barisnya dibuat saat pertama kali diubah, bukan saat tenant dibuat. Tenant tanpa baris
     * menjawab bawaan, dan bawaannya sama dengan D365: pengaju boleh menyetujui.
     */
    public function updateParameters(Request $request, ParameterWorkflow $parameter): RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);

        // Kodenya divalidasi terhadap registry, bukan terhadap daftar yang ditulis ulang di
        // sini. Daftar kedua akan menyimpang pada hari seseorang menambah parameter, dan yang
        // menyimpang menolak parameter yang sah dengan pesan yang tidak menyebut sebabnya.
        $data = $request->validate([
            'code' => ['required', 'string', Rule::in(array_keys(DefinisiParameterWorkflow::DAFTAR))],
            'value' => ['required', 'boolean'],
        ]);

        $parameter->simpan((string) $membership->tenant_id, $data['code'], (bool) $data['value'], (string) $membership->id);

        return back()->with('status', 'Parameter workflow diperbarui.');
    }

    /**
     * Definisi parameter beserta nilai yang berlaku untuk tenant ini.
     *
     * @return list<array{code: string, tipe: string, label: string, penjelasan: string, value: bool}>
     */
    private function parameterUntukLayar(string $tenantId, ParameterWorkflow $parameter): array
    {
        $nilai = $parameter->semua($tenantId);
        $daftar = [];

        foreach (DefinisiParameterWorkflow::DAFTAR as $kode => $definisi) {
            $daftar[] = [
                'code' => $kode,
                'tipe' => $definisi['tipe'],
                'label' => $definisi['label'],
                'penjelasan' => $definisi['penjelasan'],
                'value' => $nilai[$kode],
            ];
        }

        return $daftar;
    }

    public function edit(Request $request, string $workflow): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);
        $configuration = $this->configuration($membership->tenant_id, $workflow);
        $type = DB::table('workflow_types as types')->join('apps', 'apps.id', '=', 'types.app_id')->where('types.id', $configuration->workflow_type_id)->first(['types.id', 'types.name', 'types.code', 'types.scope', 'types.decision_context_schema', 'apps.name as app_name']);
        $version = $this->latestVersion($configuration->id);
        $payload = [
            'canManage' => true,
            'workflow' => ['id' => $configuration->id, 'name' => $configuration->name, 'enabled' => (bool) $configuration->enabled, 'legal_entity_id' => $configuration->legal_entity_id],
            'workflowType' => $type,
            'version' => ['id' => $version?->id, 'status' => $version?->status, 'version' => $version?->version],
            'graph' => $version ? $this->presentGraph($version->id) : ['nodes' => [], 'edges' => []],
            'roles' => DB::table('roles')->where('tenant_id', $membership->tenant_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'members' => DB::table('tenant_memberships as memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('memberships.tenant_id', $membership->tenant_id)->where('memberships.status', 'active')->orderBy('users.name')->get(['memberships.id', 'users.name', 'users.email']),
        ];

        return $request->is('api/*') ? response()->json(['data' => $payload]) : Inertia::render('settings/workflow-editor', $payload);
    }

    public function graph(Request $request, string $workflow): JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);
        $configuration = $this->configuration($membership->tenant_id, $workflow);
        $version = $this->latestVersion($configuration->id);

        return response()->json(['data' => [
            'configuration_id' => $configuration->id,
            'version' => $version?->version,
            'status' => $version?->status,
            'graph' => $version ? $this->presentGraph($version->id) : ['nodes' => [], 'edges' => []],
        ]]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);
        $data = $request->validate([
            'workflow_type_id' => ['required', 'ulid'],
            'name' => ['required', 'string', 'max:160'],
            'legal_entity_id' => ['nullable', 'ulid'],
            // Backward-compatible input for existing callers. New UI configures this on the node.
            'assignee_type' => ['nullable', 'in:role,member'],
            'assignee_id' => ['nullable', 'ulid'],
        ]);
        $type = DB::table('workflow_types')->where('id', $data['workflow_type_id'])->first();
        abort_unless($type, 422, 'Jenis workflow tidak ditemukan.');
        if (($data['assignee_type'] ?? null) !== null && ($data['assignee_id'] ?? null) === null) {
            throw ValidationException::withMessages(['assignee_id' => 'Pilih penerima tugas.']);
        }
        $assignee = isset($data['assignee_type']) ? ['type' => $data['assignee_type'], 'id' => $data['assignee_id']] : null;

        $workflow = DB::transaction(function () use ($membership, $data, $assignee): object {
            $configurationId = (string) Str::ulid();
            $versionId = (string) Str::ulid();
            DB::table('workflow_configurations')->insert([
                'id' => $configurationId,
                'tenant_id' => $membership->tenant_id,
                'workflow_type_id' => $data['workflow_type_id'],
                'name' => $data['name'],
                'legal_entity_id' => $data['legal_entity_id'] ?? null,
                'enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('workflow_configuration_versions')->insert([
                'id' => $versionId,
                'configuration_id' => $configurationId,
                'version' => 1,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->replaceGraph($versionId, WorkflowGraph::starter($assignee));

            return (object) ['id' => $configurationId];
        });

        if (! $request->is('api/*')) {
            return redirect()->route('workflows.edit', $workflow->id)->with('status', 'Workflow disimpan sebagai draf.');
        }

        return response()->json(['data' => ['id' => $workflow->id]], 201);
    }

    public function createDraft(Request $request, string $workflow): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);
        $configuration = $this->configuration($membership->tenant_id, $workflow);
        $draft = DB::table('workflow_configuration_versions')->where('configuration_id', $configuration->id)->where('status', 'draft')->first();
        if (! $draft) {
            $published = DB::table('workflow_configuration_versions')->where('configuration_id', $configuration->id)->where('status', 'published')->orderByDesc('version')->first();
            abort_unless($published, 422, 'Workflow belum memiliki versi yang dapat disalin.');
            $draftId = (string) Str::ulid();
            DB::transaction(function () use ($published, $configuration, $draftId): void {
                DB::table('workflow_configuration_versions')->insert(['id' => $draftId, 'configuration_id' => $configuration->id, 'version' => ((int) $published->version) + 1, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
                $this->copyGraph($published->id, $draftId);
            });
        }

        return $request->is('api/*') ? response()->json(['data' => ['id' => $configuration->id]]) : redirect()->route('workflows.edit', $configuration->id);
    }

    public function updateGraph(Request $request, string $workflow): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);
        $configuration = $this->configuration($membership->tenant_id, $workflow);
        $version = DB::table('workflow_configuration_versions')->where('configuration_id', $configuration->id)->where('status', 'draft')->orderByDesc('version')->first();
        abort_unless($version, 409, 'Versi aktif tidak dapat diubah. Buat draf baru terlebih dahulu.');
        $data = $request->validate([
            'nodes' => ['required', 'array', 'min:2'],
            'nodes.*.id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'nodes.*.type' => ['required', 'in:start,end,approval,manual_task,condition,parallel'],
            'nodes.*.data' => ['nullable', 'array'],
            'nodes.*.data.label' => ['nullable', 'string', 'max:160'],
            'nodes.*.data.config' => ['nullable', 'array'],
            'nodes.*.position' => ['required', 'array'],
            'nodes.*.position.x' => ['required', 'numeric'],
            'nodes.*.position.y' => ['required', 'numeric'],
            'edges' => ['required', 'array'],
            'edges.*.source' => ['required', 'string'],
            'edges.*.target' => ['required', 'string'],
            'edges.*.outcome' => ['nullable', 'string', 'max:40'],
            'edges.*.condition' => ['nullable', 'array'],
        ]);
        DB::transaction(fn () => $this->replaceGraph($version->id, $data));

        return $request->is('api/*') ? response()->json(['data' => ['id' => $configuration->id]]) : back()->with('status', 'Perubahan workflow disimpan.');
    }

    public function publish(Request $request, string $workflow): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);
        $configuration = $this->configuration($membership->tenant_id, $workflow);
        DB::transaction(function () use ($configuration, $membership): void {
            $version = DB::table('workflow_configuration_versions')->where('configuration_id', $configuration->id)->where('status', 'draft')->orderByDesc('version')->lockForUpdate()->first();
            abort_unless($version, 422, 'Tidak ada draf workflow yang dapat diaktifkan.');
            $elements = DB::table('workflow_elements')->where('version_id', $version->id)->get();
            $transitions = DB::table('workflow_transitions')->where('version_id', $version->id)->get();
            WorkflowGraph::validateRows($elements, $transitions);
            foreach ($elements->where('kind', 'condition') as $element) {
                $config = json_decode($element->configuration, true, 512, JSON_THROW_ON_ERROR);
                if (! is_string($config['field'] ?? null) || $config['field'] === '') {
                    throw ValidationException::withMessages(["node.{$element->key}" => "Field pada {$element->label} belum dipilih."]);
                }
                if (! in_array($config['operator'] ?? 'equals', ['equals', 'not_equals', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal', 'contains', 'is_true', 'is_false'], true)) {
                    throw ValidationException::withMessages(["node.{$element->key}" => "Operator pada {$element->label} tidak dikenali."]);
                }
            }
            foreach ($elements->whereIn('kind', ['approval', 'manual_task']) as $element) {
                $config = json_decode($element->configuration, true, 512, JSON_THROW_ON_ERROR);
                $policy = (string) ($config['completion_policy'] ?? 'single');
                if (! in_array($policy, ['single', 'majority', 'percentage', 'all'], true)) {
                    throw ValidationException::withMessages(["node.{$element->key}" => "Syarat penyelesaian pada {$element->label} tidak dikenali."]);
                }
                if ($policy === 'percentage' && (! is_numeric($config['completion_percentage'] ?? null) || (float) $config['completion_percentage'] < 1 || (float) $config['completion_percentage'] > 100)) {
                    throw ValidationException::withMessages(["node.{$element->key}" => "Masukkan persentase antara 1 sampai 100 pada {$element->label}."]);
                }
                if (isset($config['assignees']) && is_array($config['assignees'])) {
                    $memberIds = collect($config['assignees'])
                        ->filter(fn (mixed $assignee): bool => is_array($assignee) && ($assignee['type'] ?? null) === 'member' && isset($assignee['id']))
                        ->map(fn (array $assignee): string => (string) $assignee['id'])
                        ->filter()
                        ->unique()
                        ->values();
                    if ($memberIds->isEmpty() || TenantMembership::query()->where('tenant_id', $membership->tenant_id)->where('status', 'active')->whereIn('id', $memberIds)->count() !== $memberIds->count()) {
                        throw ValidationException::withMessages(["node.{$element->key}" => "Pilih minimal satu anggota aktif sebagai penerima pada {$element->label}."]);
                    }

                    continue;
                }
                $assignee = $config['assignee'] ?? null;
                if (! is_array($assignee) || ! isset($assignee['type'], $assignee['id'])) {
                    throw ValidationException::withMessages(["node.{$element->key}" => "Penerima tugas pada {$element->label} belum dipilih."]);
                }
                $valid = $assignee['type'] === 'role'
                    ? DB::table('roles')->where('tenant_id', $membership->tenant_id)->where('is_active', true)->where('id', $assignee['id'])->exists()
                    : TenantMembership::query()->where('tenant_id', $membership->tenant_id)->where('status', 'active')->whereKey($assignee['id'])->exists();
                abort_unless($valid, 422, "Penerima tugas pada {$element->label} tidak tersedia.");
            }
            DB::table('workflow_configuration_versions')->where('configuration_id', $configuration->id)->where('status', 'published')->update(['status' => 'replaced', 'updated_at' => now()]);
            DB::table('workflow_configuration_versions')->where('id', $version->id)->update(['status' => 'published', 'published_at' => now(), 'published_by_user_id' => $membership->user_id, 'updated_at' => now()]);
            DB::table('workflow_configurations')->where('tenant_id', $membership->tenant_id)->where('workflow_type_id', $configuration->workflow_type_id)->where('legal_entity_id', $configuration->legal_entity_id)->where('id', '!=', $configuration->id)->update(['enabled' => false, 'updated_at' => now()]);
            DB::table('workflow_configurations')->where('id', $configuration->id)->update(['enabled' => true, 'updated_at' => now()]);
        });

        return $this->respond($request, ['id' => $configuration->id], 'Workflow aktif dan siap dipakai.');
    }

    public function activate(Request $request, string $workflow): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);
        $configuration = $this->configuration($membership->tenant_id, $workflow);
        DB::transaction(function () use ($configuration, $membership): void {
            DB::table('workflow_configuration_versions')->where('configuration_id', $configuration->id)->where('status', 'published')->exists() || abort(422, 'Workflow belum memiliki versi aktif.');
            DB::table('workflow_configurations')->where('tenant_id', $membership->tenant_id)->where('workflow_type_id', $configuration->workflow_type_id)->where('legal_entity_id', $configuration->legal_entity_id)->where('id', '!=', $configuration->id)->update(['enabled' => false, 'updated_at' => now()]);
            DB::table('workflow_configurations')->where('id', $configuration->id)->update(['enabled' => true, 'updated_at' => now()]);
        });

        return $this->respond($request, ['id' => $configuration->id], 'Workflow diaktifkan.');
    }

    public function deactivate(Request $request, string $workflow): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);
        $configuration = $this->configuration($membership->tenant_id, $workflow);
        DB::table('workflow_configurations')->where('id', $configuration->id)->update(['enabled' => false, 'updated_at' => now()]);

        return $this->respond($request, ['id' => $configuration->id], 'Workflow dinonaktifkan.');
    }

    private function configuration(string $tenantId, string $id): object
    {
        $configuration = DB::table('workflow_configurations')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($configuration, 404);

        return $configuration;
    }

    private function latestVersion(string $configurationId): ?object
    {
        return DB::table('workflow_configuration_versions')->where('configuration_id', $configurationId)->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")->orderByDesc('version')->first();
    }

    /** @return array{nodes:list<array<string,mixed>>,edges:list<array<string,mixed>>} */
    private function presentGraph(string $versionId): array
    {
        $elements = DB::table('workflow_elements')->where('version_id', $versionId)->get()->keyBy('id');
        $nodes = $elements->values()->map(fn (object $element): array => [
            'id' => $element->key,
            'type' => $element->kind,
            'data' => ['label' => $element->label, 'config' => json_decode($element->configuration, true, 512, JSON_THROW_ON_ERROR)],
            'position' => ['x' => (float) $element->position_x, 'y' => (float) $element->position_y],
        ])->values()->all();
        $keyById = $elements->mapWithKeys(fn (object $element): array => [$element->id => $element->key]);
        $edges = DB::table('workflow_transitions')->where('version_id', $versionId)->get()->map(fn (object $edge): array => [
            'id' => $edge->id,
            'source' => $keyById[$edge->from_element_id] ?? '',
            'target' => $keyById[$edge->to_element_id] ?? '',
            'outcome' => $edge->outcome,
            'condition' => $edge->condition ? json_decode($edge->condition, true, 512, JSON_THROW_ON_ERROR) : null,
        ])->values()->all();

        return compact('nodes', 'edges');
    }

    /** @param array{nodes:list<array<string,mixed>>,edges:list<array<string,mixed>>} $graph */
    private function replaceGraph(string $versionId, array $graph): void
    {
        DB::table('workflow_transitions')->where('version_id', $versionId)->delete();
        DB::table('workflow_elements')->where('version_id', $versionId)->delete();
        $elementIds = [];
        foreach ($graph['nodes'] as $node) {
            $key = (string) $node['id'];
            $elementIds[$key] = (string) Str::ulid();
            DB::table('workflow_elements')->insert([
                'id' => $elementIds[$key], 'version_id' => $versionId, 'key' => $key,
                'kind' => (string) $node['type'], 'label' => (string) ($node['data']['label'] ?? ucfirst((string) $node['type'])),
                'configuration' => json_encode($node['data']['config'] ?? [], JSON_THROW_ON_ERROR),
                'position_x' => max(0, (int) round((float) $node['position']['x'])), 'position_y' => max(0, (int) round((float) $node['position']['y'])),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($graph['edges'] as $edge) {
            if (! isset($elementIds[$edge['source']], $elementIds[$edge['target']])) {
                throw ValidationException::withMessages(['edges' => 'Koneksi harus menunjuk ke elemen yang ada.']);
            }
            DB::table('workflow_transitions')->insert([
                'id' => (string) Str::ulid(), 'version_id' => $versionId,
                'from_element_id' => $elementIds[$edge['source']], 'to_element_id' => $elementIds[$edge['target']],
                'outcome' => $edge['outcome'] ?? null, 'condition' => isset($edge['condition']) ? json_encode($edge['condition'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function copyGraph(string $sourceVersionId, string $targetVersionId): void
    {
        $graph = $this->presentGraph($sourceVersionId);
        $this->replaceGraph($targetVersionId, $graph);
    }

    /** @param array<string, mixed> $data */
    private function respond(Request $request, array $data, string $message, int $status = 200): JsonResponse|RedirectResponse
    {
        return $request->is('api/*') ? response()->json(['data' => $data], $status) : back()->with('status', $message);
    }
}
