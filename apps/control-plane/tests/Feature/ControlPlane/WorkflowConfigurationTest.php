<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\DefinisiParameterWorkflow;
use App\Support\ParameterWorkflow;
use App\Support\WorkflowRuntime;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkflowConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $workflowTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant test',
            'app_ids' => ['management-aset'], 'email' => 'owner@workflow.test', 'password' => 'password',
        ]);
        $this->workflowTypeId = (string) Str::ulid();
        DB::table('workflow_types')->insert([
            'id' => $this->workflowTypeId, 'app_id' => 'management-aset',
            'code' => 'management-aset.pemusnahan-aset-verification',
            'name' => 'Verifikasi usulan pemusnahan aset',
            'decision_context_schema' => json_encode(['required' => ['document_id', 'asset_id']], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_admin_can_draft_and_activate_a_role_assigned_workflow(): void
    {
        $role = Role::create(['tenant_id' => $this->owner->activeMembership()->tenant_id, 'name' => 'Pemeriksa aset', 'is_active' => true]);

        $this->actingAs($this->owner)->postJson('/settings/workflows', [
            'workflow_type_id' => $this->workflowTypeId,
            'name' => 'Persetujuan pemusnahan aset',
            'assignee_type' => 'role', 'assignee_id' => $role->id,
        ])->assertRedirect();

        $workflow = DB::table('workflow_configurations')->first();
        $this->assertFalse((bool) $workflow->enabled);
        $this->assertDatabaseHas('workflow_elements', ['version_id' => DB::table('workflow_configuration_versions')->value('id'), 'kind' => 'approval']);

        $this->actingAs($this->owner)->postJson("/settings/workflows/{$workflow->id}/publish")
            ->assertRedirect();

        $this->assertDatabaseHas('workflow_configurations', ['id' => $workflow->id, 'enabled' => true]);
        $this->assertDatabaseHas('workflow_configuration_versions', ['configuration_id' => $workflow->id, 'status' => 'published']);
    }

    public function test_role_approver_receives_and_completes_a_work_item(): void
    {
        $membership = $this->owner->activeMembership();
        $role = Role::create(['tenant_id' => $membership->tenant_id, 'name' => 'Pemeriksa aset', 'is_active' => true]);
        RoleAssignment::create(['membership_id' => $membership->id, 'role_id' => $role->id, 'source' => 'manual', 'status' => 'active', 'valid_from' => now()]);

        $this->actingAs($this->owner)->post('/settings/workflows', [
            'workflow_type_id' => $this->workflowTypeId,
            'name' => 'Persetujuan pemusnahan aset', 'assignee_type' => 'role', 'assignee_id' => $role->id,
        ]);
        $workflow = DB::table('workflow_configurations')->first();
        $this->actingAs($this->owner)->post("/settings/workflows/{$workflow->id}/publish");
        $version = DB::table('workflow_configuration_versions')->where('configuration_id', $workflow->id)->where('status', 'published')->first();
        $type = DB::table('workflow_types')->where('id', $this->workflowTypeId)->first();

        $instance = app(WorkflowRuntime::class)->submit($membership->tenant_id, $type, $version, 'pemusnahan:1', [
            'source_document_type' => 'pemusnahan-aset', 'source_document_id' => (string) Str::ulid(),
            'decision_context' => ['document_id' => (string) Str::ulid(), 'asset_id' => (string) Str::ulid()],
        ]);

        $item = DB::table('workflow_work_items')->where('instance_id', $instance->id)->first();
        $this->assertSame($membership->id, $item->assigned_membership_id);
        app(WorkflowRuntime::class)->decide($membership, $item->id, 'approve', 'Dokumen lengkap.');

        $this->assertDatabaseHas('workflow_instances', ['id' => $instance->id, 'status' => 'approved']);
        $this->assertDatabaseHas('workflow_history', ['instance_id' => $instance->id, 'event_type' => 'approved', 'actor_membership_id' => $membership->id]);
        $this->assertDatabaseHas('outbox_events', ['tenant_id' => $membership->tenant_id, 'type' => 'core.workflow.decision.v2']);
    }

    public function test_multiple_approval_nodes_run_in_order(): void
    {
        $membership = $this->owner->activeMembership();
        $role = Role::create(['tenant_id' => $membership->tenant_id, 'name' => 'Pemeriksa bertingkat', 'is_active' => true]);
        RoleAssignment::create(['membership_id' => $membership->id, 'role_id' => $role->id, 'source' => 'manual', 'status' => 'active', 'valid_from' => now()]);
        $workflow = $this->createWorkflow();
        $this->putJson("/settings/workflows/{$workflow->id}/graph", ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'data' => ['label' => 'Mulai', 'config' => []], 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'level-1', 'type' => 'approval', 'data' => ['label' => 'Level 1', 'config' => ['assignee' => ['type' => 'role', 'id' => $role->id]]], 'position' => ['x' => 200, 'y' => 0]],
            ['id' => 'level-2', 'type' => 'approval', 'data' => ['label' => 'Level 2', 'config' => ['assignee' => ['type' => 'role', 'id' => $role->id]]], 'position' => ['x' => 400, 'y' => 0]],
            ['id' => 'end', 'type' => 'end', 'data' => ['label' => 'Selesai', 'config' => []], 'position' => ['x' => 600, 'y' => 0]],
        ], 'edges' => [
            ['source' => 'start', 'target' => 'level-1'], ['source' => 'level-1', 'target' => 'level-2', 'outcome' => 'approve'], ['source' => 'level-2', 'target' => 'end', 'outcome' => 'approve'],
        ]])->assertRedirect();
        $this->actingAs($this->owner)->post("/settings/workflows/{$workflow->id}/publish");
        $version = DB::table('workflow_configuration_versions')->where('configuration_id', $workflow->id)->where('status', 'published')->first();
        $type = DB::table('workflow_types')->where('id', $this->workflowTypeId)->first();
        $instance = app(WorkflowRuntime::class)->submit($membership->tenant_id, $type, $version, 'multi:1', ['source_document_type' => 'asset', 'source_document_id' => (string) Str::ulid(), 'decision_context' => ['document_id' => (string) Str::ulid(), 'asset_id' => (string) Str::ulid()]]);
        $first = DB::table('workflow_work_items')->where('instance_id', $instance->id)->first();
        $this->assertSame('level-1', DB::table('workflow_elements')->where('id', $first->element_id)->value('key'));
        app(WorkflowRuntime::class)->decide($membership, $first->id, 'approve', null);
        $second = DB::table('workflow_work_items')->where('instance_id', $instance->id)->where('status', 'pending')->first();
        $this->assertSame('level-2', DB::table('workflow_elements')->where('id', $second->element_id)->value('key'));
        app(WorkflowRuntime::class)->decide($membership, $second->id, 'approve', null);
        $this->assertDatabaseHas('workflow_instances', ['id' => $instance->id, 'status' => 'approved']);
    }

    public function test_selected_members_can_use_majority_completion_policy(): void
    {
        $membership = $this->owner->activeMembership();
        $secondUser = User::factory()->create(['name' => 'Second approver', 'email' => 'second@workflow.test']);
        $secondMembership = TenantMembership::create(['tenant_id' => $membership->tenant_id, 'user_id' => $secondUser->id, 'system_role' => 'member', 'status' => 'active']);
        $workflow = $this->createWorkflow();
        $this->putJson("/settings/workflows/{$workflow->id}/graph", ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'data' => ['label' => 'Mulai', 'config' => []], 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'approval', 'type' => 'approval', 'data' => ['label' => 'Persetujuan bersama', 'config' => [
                'assignees' => [['type' => 'member', 'id' => $membership->id], ['type' => 'member', 'id' => $secondMembership->id]],
                'completion_policy' => 'majority',
            ]], 'position' => ['x' => 200, 'y' => 0]],
            ['id' => 'end', 'type' => 'end', 'data' => ['label' => 'Selesai', 'config' => []], 'position' => ['x' => 400, 'y' => 0]],
        ], 'edges' => [
            ['source' => 'start', 'target' => 'approval'], ['source' => 'approval', 'target' => 'end', 'outcome' => 'approve'],
        ]])->assertRedirect();
        $this->actingAs($this->owner)->post("/settings/workflows/{$workflow->id}/publish")->assertRedirect();
        $version = DB::table('workflow_configuration_versions')->where('configuration_id', $workflow->id)->where('status', 'published')->first();
        $type = DB::table('workflow_types')->where('id', $this->workflowTypeId)->first();
        $instance = app(WorkflowRuntime::class)->submit($membership->tenant_id, $type, $version, 'selected:1', ['source_document_type' => 'asset', 'source_document_id' => (string) Str::ulid(), 'decision_context' => ['document_id' => (string) Str::ulid(), 'asset_id' => (string) Str::ulid()]]);
        $items = DB::table('workflow_work_items')->where('instance_id', $instance->id)->where('status', 'pending')->get();
        $this->assertCount(2, $items);
        app(WorkflowRuntime::class)->decide($membership, $items[0]->id, 'approve', null);
        $this->assertDatabaseHas('workflow_instances', ['id' => $instance->id, 'status' => 'pending']);
        app(WorkflowRuntime::class)->decide($secondMembership, $items[1]->id, 'approve', null);
        $this->assertDatabaseHas('workflow_instances', ['id' => $instance->id, 'status' => 'approved']);
    }

    public function test_condition_routes_without_creating_a_work_item(): void
    {
        DB::table('workflow_types')->where('id', $this->workflowTypeId)->update(['decision_context_schema' => json_encode(['required' => ['document_id', 'asset_id'], 'properties' => ['estimated_value' => ['type' => 'number']]], JSON_THROW_ON_ERROR)]);
        $workflow = $this->createWorkflow();
        $this->putJson("/settings/workflows/{$workflow->id}/graph", ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'data' => ['label' => 'Mulai', 'config' => []], 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'condition', 'type' => 'condition', 'data' => ['label' => 'Nilai tinggi?', 'config' => ['field' => 'estimated_value', 'operator' => 'greater_than', 'value' => 10]], 'position' => ['x' => 200, 'y' => 0]],
            ['id' => 'end', 'type' => 'end', 'data' => ['label' => 'Selesai', 'config' => []], 'position' => ['x' => 400, 'y' => 0]],
        ], 'edges' => [
            ['source' => 'start', 'target' => 'condition'], ['source' => 'condition', 'target' => 'end', 'outcome' => 'true'], ['source' => 'condition', 'target' => 'end', 'outcome' => 'false'],
        ]])->assertRedirect();
        $this->actingAs($this->owner)->post("/settings/workflows/{$workflow->id}/publish");
        $version = DB::table('workflow_configuration_versions')->where('configuration_id', $workflow->id)->where('status', 'published')->first();
        $type = DB::table('workflow_types')->where('id', $this->workflowTypeId)->first();
        $instance = app(WorkflowRuntime::class)->submit($this->owner->activeMembership()->tenant_id, $type, $version, 'condition:1', ['source_document_type' => 'asset', 'source_document_id' => (string) Str::ulid(), 'decision_context' => ['document_id' => (string) Str::ulid(), 'asset_id' => (string) Str::ulid(), 'estimated_value' => 20]]);
        $this->assertSame('approved', $instance->status);
        $this->assertDatabaseCount('workflow_work_items', 0);
    }

    public function test_parallel_branches_wait_for_all_work_items(): void
    {
        $membership = $this->owner->activeMembership();
        $role = Role::create(['tenant_id' => $membership->tenant_id, 'name' => 'Tim paralel', 'is_active' => true]);
        RoleAssignment::create(['membership_id' => $membership->id, 'role_id' => $role->id, 'source' => 'manual', 'status' => 'active', 'valid_from' => now()]);
        $workflow = $this->createWorkflow();
        $this->putJson("/settings/workflows/{$workflow->id}/graph", ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'data' => ['label' => 'Mulai', 'config' => []], 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'parallel', 'type' => 'parallel', 'data' => ['label' => 'Pemeriksaan paralel', 'config' => []], 'position' => ['x' => 200, 'y' => 0]],
            ['id' => 'left', 'type' => 'approval', 'data' => ['label' => 'Pemeriksaan A', 'config' => ['assignee' => ['type' => 'role', 'id' => $role->id]]], 'position' => ['x' => 400, 'y' => 10]],
            ['id' => 'right', 'type' => 'approval', 'data' => ['label' => 'Pemeriksaan B', 'config' => ['assignee' => ['type' => 'role', 'id' => $role->id]]], 'position' => ['x' => 400, 'y' => 100]],
            ['id' => 'end', 'type' => 'end', 'data' => ['label' => 'Selesai', 'config' => []], 'position' => ['x' => 650, 'y' => 0]],
        ], 'edges' => [
            ['source' => 'start', 'target' => 'parallel'], ['source' => 'parallel', 'target' => 'left'], ['source' => 'parallel', 'target' => 'right'], ['source' => 'left', 'target' => 'end', 'outcome' => 'approve'], ['source' => 'right', 'target' => 'end', 'outcome' => 'approve'],
        ]])->assertRedirect();
        $this->actingAs($this->owner)->post("/settings/workflows/{$workflow->id}/publish");
        $version = DB::table('workflow_configuration_versions')->where('configuration_id', $workflow->id)->where('status', 'published')->first();
        $type = DB::table('workflow_types')->where('id', $this->workflowTypeId)->first();
        $instance = app(WorkflowRuntime::class)->submit($membership->tenant_id, $type, $version, 'parallel:1', ['source_document_type' => 'asset', 'source_document_id' => (string) Str::ulid(), 'decision_context' => ['document_id' => (string) Str::ulid(), 'asset_id' => (string) Str::ulid()]]);
        $items = DB::table('workflow_work_items')->where('instance_id', $instance->id)->where('status', 'pending')->get();
        $this->assertCount(2, $items);
        app(WorkflowRuntime::class)->decide($membership, $items[0]->id, 'approve', null);
        $this->assertDatabaseHas('workflow_instances', ['id' => $instance->id, 'status' => 'pending']);
        app(WorkflowRuntime::class)->decide($membership, $items[1]->id, 'approve', null);
        $this->assertDatabaseHas('workflow_instances', ['id' => $instance->id, 'status' => 'approved']);
    }

    public function test_publish_rejects_disconnected_nodes(): void
    {
        $workflow = $this->createWorkflow();
        $this->putJson("/settings/workflows/{$workflow->id}/graph", ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'data' => ['label' => 'Mulai', 'config' => []], 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'end', 'type' => 'end', 'data' => ['label' => 'Selesai', 'config' => []], 'position' => ['x' => 200, 'y' => 0]],
            ['id' => 'orphan', 'type' => 'manual_task', 'data' => ['label' => 'Tidak terhubung', 'config' => []], 'position' => ['x' => 400, 'y' => 0]],
        ], 'edges' => [['source' => 'start', 'target' => 'end']]])->assertRedirect();
        $this->actingAs($this->owner)->post("/settings/workflows/{$workflow->id}/publish")->assertSessionHasErrors('node.orphan');
    }

    private function createWorkflow(): object
    {
        $this->actingAs($this->owner)->post('/settings/workflows', ['workflow_type_id' => $this->workflowTypeId, 'name' => 'Workflow test '.Str::random(6)]);

        return DB::table('workflow_configurations')->orderByDesc('created_at')->first();
    }

    public function test_publisher_signs_and_marks_a_workflow_decision_event_after_delivery(): void
    {
        $eventId = (string) Str::ulid();
        $correlationId = (string) Str::ulid();
        DB::table('outbox_events')->insert([
            'id' => $eventId, 'tenant_id' => $this->owner->activeMembership()->tenant_id,
            'correlation_id' => $correlationId,
            'type' => 'core.workflow.decision.v2', 'payload' => json_encode(['decision' => 'approved'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        config()->set('coreerp.app_context_signing_key', 'workflow-test-key');
        config()->set('coreerp.event_endpoints', [['type' => 'core.workflow.decision.v2', 'url' => 'https://aset.test/events']]);
        Http::fake(['https://aset.test/events' => Http::response(['data' => ['accepted' => true]])]);

        Artisan::call('workflow-events:publish');

        Http::assertSent(fn ($request) => $request->url() === 'https://aset.test/events'
            && $request->hasHeader('X-CoreERP-Event-Signature') && $request['id'] === $eventId
            && $request['correlation_id'] === $correlationId);
        $this->assertNotNull(DB::table('outbox_events')->where('id', $eventId)->value('published_at'));
    }

    public function test_publisher_holds_back_an_event_without_a_correlation_id(): void
    {
        $eventId = (string) Str::ulid();
        DB::table('outbox_events')->insert([
            'id' => $eventId, 'tenant_id' => $this->owner->activeMembership()->tenant_id,
            'type' => 'core.workflow.decision.v2', 'payload' => json_encode(['decision' => 'approved'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        config()->set('coreerp.app_context_signing_key', 'workflow-test-key');
        config()->set('coreerp.event_endpoints', [['type' => 'core.workflow.decision.v2', 'url' => 'https://aset.test/events']]);
        Http::fake(['https://aset.test/events' => Http::response(['data' => ['accepted' => true]])]);

        Artisan::call('workflow-events:publish');

        // Sending it would put a payload on the wire that violates the v2 envelope, so the
        // row stays unpublished and visible rather than leaving the consumer to reject it.
        Http::assertNothingSent();
        $this->assertNull(DB::table('outbox_events')->where('id', $eventId)->value('published_at'));
    }

    /**
     * Penerima yang kodenya berjalan di runtime ini tidak dikirimi HTTP.
     *
     * Sejak F3-09, module yang dimuat runtime ini menerima keputusan sebagai event, di dalam
     * transaksi keputusannya. Mengirimkannya lagi lewat HTTP berarti satu permintaan ke alamat
     * yang sudah tidak ada, lalu sebuah kegagalan koneksi yang tercatat sebagai masalah padahal
     * keputusannya justru sudah sampai.
     *
     * Barisnya tetap ditandai terkirim. Baris yang tidak pernah ditandai akan diambil ulang
     * setiap kali perintah ini berjalan, selamanya, dan antrean yang tidak pernah menyusut
     * menyembunyikan baris yang benar-benar gagal terkirim.
     */
    public function test_publisher_skips_an_endpoint_served_inside_this_runtime(): void
    {
        $eventId = (string) Str::ulid();
        DB::table('outbox_events')->insert([
            'id' => $eventId, 'tenant_id' => $this->owner->activeMembership()->tenant_id,
            'correlation_id' => (string) Str::ulid(),
            'type' => 'core.workflow.decision.v2', 'payload' => json_encode(['decision' => 'approved'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        config()->set('coreerp.app_context_signing_key', 'workflow-test-key');
        config()->set('coreerp.event_endpoints', [[
            'type' => 'core.workflow.decision.v2',
            'url' => 'https://aset.test/events',
            'module' => 'management-aset',
        ]]);
        Http::fake(['https://aset.test/events' => Http::response(['data' => ['accepted' => true]])]);

        Artisan::call('workflow-events:publish');

        Http::assertNothingSent();
        $this->assertNotNull(DB::table('outbox_events')->where('id', $eventId)->value('published_at'));
    }

    /**
     * Penerima di luar proses tetap dikirimi, walaupun namanya disebut.
     *
     * Ini sisi lain penjaga di atas, dan ia yang menahannya dari terlalu banyak menyapu: nama
     * module yang **tidak** dimuat runtime ini bukan alasan untuk berhenti mengirim.
     */
    public function test_publisher_still_sends_to_a_module_this_runtime_does_not_load(): void
    {
        $eventId = (string) Str::ulid();
        DB::table('outbox_events')->insert([
            'id' => $eventId, 'tenant_id' => $this->owner->activeMembership()->tenant_id,
            'correlation_id' => (string) Str::ulid(),
            'type' => 'core.workflow.decision.v2', 'payload' => json_encode(['decision' => 'approved'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        config()->set('coreerp.app_context_signing_key', 'workflow-test-key');
        config()->set('coreerp.event_endpoints', [[
            'type' => 'core.workflow.decision.v2',
            'url' => 'https://hr.test/events',
            'module' => 'human-resources',
        ]]);
        Http::fake(['https://hr.test/events' => Http::response(['data' => ['accepted' => true]])]);

        Artisan::call('workflow-events:publish');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://hr.test/events');
        $this->assertNotNull(DB::table('outbox_events')->where('id', $eventId)->value('published_at'));
    }

    /**
     * Bawaannya: pengaju **boleh** menyetujui dokumennya sendiri.
     *
     * Ini kebalikan dari perilaku sebelumnya, dan pembalikannya keputusan pemilik produk:
     * bentuk dan bawaannya mengikuti Dynamics 365 Finance & Operations, tempat
     * `Disallow approval by submitter` adalah parameter yang bawaannya mati.
     *
     * Alasannya bukan sekadar meniru. Mesin ini belum punya delegasi, eskalasi, maupun
     * penugasan ulang oleh admin. Larangan yang tidak bisa dimatikan berarti sebuah tugas yang
     * jatuh ke pengajunya sendiri tidak akan pernah bisa diselesaikan siapa pun — bukan kontrol
     * yang ketat, melainkan jalan buntu. Rangkap jabatan adalah keadaan biasa pada organisasi
     * kecil, bukan pengecualian.
     */
    public function test_submitter_can_approve_by_default(): void
    {
        $membership = $this->owner->activeMembership();
        $item = $this->workItemForOwnSubmission($membership);

        app(WorkflowRuntime::class)->decide($membership, $item->id, 'approve', 'Saya sendiri yang mengajukan.');

        $this->assertDatabaseHas('workflow_instances', ['id' => $item->instance_id, 'status' => 'approved']);
    }

    /**
     * Persetujuan oleh pengajunya sendiri selalu tercatat, dilarang atau tidak.
     *
     * Yang membuat "boleh" bisa dipertanggungjawabkan bukan izinnya melainkan jejaknya: seorang
     * pemeriksa harus bisa menemukan dokumen mana saja yang disetujui pengajunya sendiri tanpa
     * membandingkan dua tabel.
     */
    public function test_history_marks_an_approval_made_by_the_submitter(): void
    {
        $membership = $this->owner->activeMembership();
        $item = $this->workItemForOwnSubmission($membership);

        app(WorkflowRuntime::class)->decide($membership, $item->id, 'approve', null);

        $rincian = DB::table('workflow_history')->where('instance_id', $item->instance_id)
            ->where('event_type', 'approved')->value('details');

        $this->assertTrue(json_decode((string) $rincian, true, 512, JSON_THROW_ON_ERROR)['oleh_pengaju'] ?? false);
    }

    /**
     * Tenant yang menyalakan larangannya: pengaju tidak pernah masuk daftar penerima tugas.
     *
     * Disaring pada **penugasan**, bukan pada keputusan. Kalau penyaringannya baru terjadi saat
     * keputusan, tugasnya tetap terbentuk lalu tidak bisa diklik siapa pun — dan mesin ini tidak
     * punya cara memindahkannya. Karena penerimanya di sini tinggal pengaju itu sendiri, yang
     * benar adalah menolak dokumennya sekarang, dengan alasan yang menyebut sebabnya.
     */
    public function test_tenant_can_forbid_approval_by_the_submitter(): void
    {
        $membership = $this->owner->activeMembership();
        $this->larangPersetujuanPengaju($membership->tenant_id);

        $instance = $this->submitOwnDocument($membership);

        $this->assertSame('rejected', $instance->status);
        $this->assertDatabaseCount('workflow_work_items', 0);
        $alasan = DB::table('workflow_history')->where('instance_id', $instance->id)
            ->where('event_type', 'decision_rejected')->value('details');
        $this->assertStringContainsString('pengajunya sendiri', (string) $alasan);
    }

    /**
     * Larangannya menyaring pengaju, bukan membatalkan langkahnya.
     *
     * Penerima kedua tetap mendapat tugasnya. Tanpa test ini, sebuah penyaringan yang terlalu
     * lebar — membuang seluruh daftar, bukan satu orang — akan lulus pada test di atas.
     */
    public function test_forbidding_the_submitter_still_assigns_the_other_approver(): void
    {
        $membership = $this->owner->activeMembership();
        $this->larangPersetujuanPengaju($membership->tenant_id);
        $lain = TenantMembership::create([
            'tenant_id' => $membership->tenant_id,
            'user_id' => User::factory()->create(['name' => 'Pemeriksa lain', 'email' => 'lain@workflow.test'])->id,
            'system_role' => 'member', 'status' => 'active',
        ]);

        $instance = $this->submitOwnDocument($membership, [$membership->id, $lain->id]);

        $this->assertSame('pending', $instance->status);
        $items = DB::table('workflow_work_items')->where('instance_id', $instance->id)->get();
        $this->assertCount(1, $items);
        $this->assertSame($lain->id, $items[0]->assigned_membership_id);
    }

    /**
     * Admin tenant menyalakan dan mematikan parameternya lewat layar Core.
     */
    public function test_admin_can_switch_the_parameter(): void
    {
        $tenantId = $this->owner->activeMembership()->tenant_id;

        $kode = DefinisiParameterWorkflow::LARANG_PERSETUJUAN_PENGAJU;

        $this->actingAs($this->owner)
            ->post('/settings/workflows/parameters', ['code' => $kode, 'value' => true])
            ->assertRedirect();
        $this->assertDatabaseHas('workflow_parameters', [
            'tenant_id' => $tenantId, 'code' => $kode, 'value' => 'true',
        ]);

        $this->actingAs($this->owner)
            ->post('/settings/workflows/parameters', ['code' => $kode, 'value' => false])
            ->assertRedirect();
        $this->assertDatabaseHas('workflow_parameters', [
            'tenant_id' => $tenantId, 'code' => $kode, 'value' => 'false',
        ]);

        // Satu baris per tenant per kode; menyalakan lalu mematikan tidak menumpuk baris kedua.
        $this->assertDatabaseCount('workflow_parameters', 1);
    }

    /**
     * Kode parameter yang tidak terdaftar ditolak, bukan disimpan diam-diam.
     *
     * Tanpa penjaga ini, sebuah kode yang salah ketik akan mendarat sebagai baris yang tidak
     * pernah dibaca siapa pun: adminnya melihat sakelar yang berpindah, sementara mesinnya tetap
     * memakai bawaan. Kegagalan yang paling mahal adalah yang terlihat berhasil.
     */
    public function test_unknown_parameter_code_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/settings/workflows/parameters', ['code' => 'tidak-pernah-ada', 'value' => true])
            ->assertStatus(422);

        $this->assertDatabaseCount('workflow_parameters', 0);
    }

    /**
     * Layar settings menyajikan parameter beserta definisinya, bukan sekadar nilainya.
     *
     * Inilah yang membuat halaman workflow tidak perlu mengenal satu pun nama parameter: ia
     * merender apa yang dikirim registry. Test ini yang menahan bentuk itu — begitu payloadnya
     * kembali menjadi peta nama-ke-nilai, halamannya harus menuliskan setiap parameter dengan
     * tangan lagi.
     */
    public function test_settings_screen_is_rendered_from_the_parameter_registry(): void
    {
        $parameter = [];
        $this->actingAs($this->owner)->get('/settings/workflows')->assertOk()
            ->assertInertia(function (Assert $page) use (&$parameter): void {
                $parameter = $page->toArray()['props']['parameters'];
            });

        $baris = collect($parameter)->firstWhere('code', DefinisiParameterWorkflow::LARANG_PERSETUJUAN_PENGAJU);

        $this->assertNotNull($baris, 'Parameter yang terdaftar tidak muncul pada payload layar.');
        $this->assertSame('boolean', $baris['tipe']);
        $this->assertFalse($baris['value'], 'Bawaannya harus mati, sama seperti D365.');
        $this->assertNotSame('', $baris['label']);
        $this->assertNotSame('', $baris['penjelasan']);
    }

    /**
     * Mengubah parameter meninggalkan jejak: siapa, kode apa, dari nilai apa ke apa.
     *
     * Ini yang membuat "pengaju boleh menyetujui" bisa dipertanggungjawabkan. Tanpa jejak
     * perubahannya, seorang pemeriksa yang menemukan dokumen disetujui pengajunya sendiri tidak
     * punya cara mengetahui apakah larangannya memang mati saat itu, atau baru dimatikan
     * sesudahnya — dan sebuah kontrol kepatuhan yang sakelarnya tidak diaudit meniadakan dirinya
     * sendiri.
     */
    public function test_changing_a_parameter_leaves_an_audit_trail(): void
    {
        $membership = $this->owner->activeMembership();
        $kode = DefinisiParameterWorkflow::LARANG_PERSETUJUAN_PENGAJU;

        $this->actingAs($this->owner)
            ->post('/settings/workflows/parameters', ['code' => $kode, 'value' => true])
            ->assertRedirect();

        $baris = DB::table('access_audit_events')
            ->where('tenant_id', $membership->tenant_id)
            ->where('action', 'workflow.parameter.updated')
            ->first();

        $this->assertNotNull($baris, 'Perubahan parameter tidak meninggalkan jejak audit.');
        $isi = json_decode((string) $baris->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($membership->id, $isi['actor_membership_id']);
        $this->assertSame($kode, $isi['code']);
        $this->assertFalse($isi['from']);
        $this->assertTrue($isi['to']);

        // Menekan sakelar ke posisi yang sudah ditempatinya bukan peristiwa; jejak yang penuh
        // baris tanpa peristiwa adalah jejak yang berhenti dibaca orang.
        $this->actingAs($this->owner)
            ->post('/settings/workflows/parameters', ['code' => $kode, 'value' => true])
            ->assertRedirect();

        $this->assertSame(1, DB::table('access_audit_events')->where('action', 'workflow.parameter.updated')->count());
    }

    private function larangPersetujuanPengaju(string $tenantId): void
    {
        app(ParameterWorkflow::class)->simpan(
            $tenantId,
            DefinisiParameterWorkflow::LARANG_PERSETUJUAN_PENGAJU,
            true,
            (string) $this->owner->activeMembership()->id,
        );
    }

    /**
     * Satu dokumen yang diajukan sendiri oleh `$membership`, dengan langkah persetujuan yang
     * ditujukan ke daftar keanggotaan yang disebut (bawaannya: pengaju itu sendiri).
     *
     * @param  list<string>|null  $penerima
     */
    private function submitOwnDocument(TenantMembership $membership, ?array $penerima = null): object
    {
        $workflow = $this->createWorkflow();
        $this->putJson("/settings/workflows/{$workflow->id}/graph", ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'data' => ['label' => 'Mulai', 'config' => []], 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'periksa', 'type' => 'approval', 'data' => ['label' => 'Pemeriksaan', 'config' => [
                'assignees' => array_map(
                    static fn (string $id): array => ['type' => 'member', 'id' => $id],
                    $penerima ?? [$membership->id],
                ),
                'completion_policy' => 'single',
            ]], 'position' => ['x' => 200, 'y' => 0]],
            ['id' => 'end', 'type' => 'end', 'data' => ['label' => 'Selesai', 'config' => []], 'position' => ['x' => 400, 'y' => 0]],
        ], 'edges' => [
            ['source' => 'start', 'target' => 'periksa'],
            ['source' => 'periksa', 'target' => 'end', 'outcome' => 'approve'],
        ]])->assertRedirect();
        $this->actingAs($this->owner)->post("/settings/workflows/{$workflow->id}/publish")->assertRedirect();

        $version = DB::table('workflow_configuration_versions')->where('configuration_id', $workflow->id)->where('status', 'published')->first();
        $type = DB::table('workflow_types')->where('id', $this->workflowTypeId)->first();

        return app(WorkflowRuntime::class)->submit($membership->tenant_id, $type, $version, 'pengaju:'.Str::random(8), [
            'initiator_membership_id' => $membership->id,
            'source_document_type' => 'pemusnahan-aset', 'source_document_id' => (string) Str::ulid(),
            'decision_context' => ['document_id' => (string) Str::ulid(), 'asset_id' => (string) Str::ulid()],
        ]);
    }

    private function workItemForOwnSubmission(TenantMembership $membership): object
    {
        $instance = $this->submitOwnDocument($membership);
        $item = DB::table('workflow_work_items')->where('instance_id', $instance->id)->first();

        $this->assertNotNull($item, 'Tugas persetujuan tidak terbentuk untuk pengajunya sendiri.');

        return $item;
    }
}
