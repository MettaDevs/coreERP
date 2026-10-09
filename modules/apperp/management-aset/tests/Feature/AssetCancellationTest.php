<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Foundation\FinancePosting\Models\FinancePosting;
use App\Foundation\Workflow\Support\WorkflowRuntime;
use App\Platform\Environment\Support\ActiveEnvironment;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Support\TenantScope;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerbitkanJurnalPenerimaan;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenyiapkanNilaiBukuAset;
use Tests\TestCase;

class AssetCancellationTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerbitkanJurnalPenerimaan, MenyiapkanNilaiBukuAset, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanNilaiBukuAset();
    }

    public function test_receipt_cancellation_reverses_the_original_and_its_corrections_using_frozen_accounts(): void
    {
        [$group] = $this->groupLengkap('UJI', 'Aset uji');
        $this->petakan($group, ['acquisition_account_id' => $this->akun['kendaraan'], 'payable_account_id' => $this->akun['hutang'], 'input_vat_account_id' => $this->akun['ppn']]);
        $receipt = $this->draf([], [$this->baris($group, 1, 10000, 1100)]);
        $this->selesaikan($receipt)->assertOk();
        $asset = DB::table('aset_tr_aset')->where('penerimaan_aset_id', $receipt)->first();
        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.update'])
            ->patchJson(self::API.'aset/'.$asset->id, ['version' => $asset->version, 'acquisition_value' => 12000, 'reason' => 'Koreksi faktur', 'adjustment_date' => today()->toDateString()])->assertOk();
        $originals = FinancePosting::query()->orderBy('created_at')->get()->keyBy('posting_id');
        $this->petakan($group, ['acquisition_account_id' => $this->akun['alkes'], 'payable_account_id' => $this->akun['perantara']]);
        $this->cancel('penerimaan-aset', $receipt)->assertCreated()->assertJsonPath('data.status', 'applied');
        $reversals = FinancePosting::query()->whereNotNull('reverses_posting_id')->get();
        $this->assertCount(2, $reversals);
        $this->assertSame(0, FinancePosting::query()->whereNotNull('reverses_posting_id')->readyForDelivery()->count());
        foreach ($reversals as $reversal) {
            $original = $originals[$reversal->reverses_posting_id];
            foreach ($original->payload['journal_lines'] as $i => $line) {
                $actual = $reversal->payload['journal_lines'][$i];
                $this->assertSame([$line['account'], $line['financial_dimensions'], $line['credit'], $line['debit']], [$actual['account'], $actual['financial_dimensions'], $actual['debit'], $actual['credit']]);
            }
        }
        FinancePosting::query()->whereIn('posting_id', $originals->keys())->update(['status' => 'posted', 'external_reference' => 'TEST-JOURNAL']);
        $this->assertSame(2, FinancePosting::query()->whereNotNull('reverses_posting_id')->readyForDelivery()->count());
        $this->assertNotNull(DB::table('aset_tr_aset')->where('id', $asset->id)->value('deleted_at'));
        $this->assertDatabaseHas('aset_tr_penerimaan_aset', ['id' => $receipt, 'status' => 'cancelled']);
        $this->assertSame(0.0, (float) DB::table('aset_tr_buku_aset')->where('aset_id', $asset->id)->sum('net_book_value'));
        $this->cancel('penerimaan-aset', $receipt)->assertUnprocessable();
        $this->assertSame(2, FinancePosting::query()->whereNotNull('reverses_posting_id')->count());
    }

    public function test_cancel_permission_is_separate_from_edit_post_and_request_and_tenant_scope_is_enforced(): void
    {
        [$group] = $this->groupLengkap('UJI', 'Aset uji');
        $receipt = $this->draf([], [$this->baris($group, 1, 10000)]);
        $this->selesaikan($receipt)->assertOk();
        $body = $this->cancellationBody('penerimaan-aset', $receipt);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penerimaan-aset.update', 'management-aset.penerimaan-aset.request-cancellation'])
            ->postJson(self::API.'penerimaan-aset/'.$receipt.'/batal', $body)->assertForbidden();
        $this->sebagaiPengguna((string) Str::ulid(), ['management-aset.penerimaan-aset.cancel'])
            ->postJson(self::API.'penerimaan-aset/'.$receipt.'/batal', $body)->assertNotFound();
        $this->assertDatabaseHas('aset_tr_penerimaan_aset', ['id' => $receipt, 'status' => 'selesai']);
    }

    public function test_cancelled_depreciation_can_be_calculated_again_and_value_correction_is_unlocked(): void
    {
        [$group] = $this->groupLengkap('UJI', 'Aset uji');
        $assetId = $this->terimaSatu($group);
        $this->susutkan('2026-10-01', '2026-10-31');
        $periods = DB::table('aset_tr_penyusutan_aset')->get();
        foreach ($periods as $period) {
            $this->cancel('penyusutan', $period->id)->assertCreated();
        }
        $this->assertSame(0.0, (float) DB::table('aset_tr_buku_aset')->where('aset_id', $assetId)->sum('accumulated_depreciation'));
        $asset = DB::table('aset_tr_aset')->where('id', $assetId)->first();
        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.update'])
            ->patchJson(self::API.'aset/'.$assetId, ['version' => $asset->version, 'acquisition_value' => 24000000, 'reason' => 'Perbaikan nilai', 'adjustment_date' => today()->toDateString()])->assertOk();
        $this->usulkanPeriode('2026-10-01', '2026-10-31');
        $this->assertSame(2, DB::table('aset_tr_penyusutan_aset')->where('status', 'proposed')->whereNull('cancelled_at')->count());
        $this->assertSame(2, DB::table('aset_tr_penyusutan_aset')->whereNotNull('reverses_period_id')->count());
    }

    public function test_receipt_with_live_depreciation_cannot_be_cancelled(): void
    {
        [$group] = $this->groupLengkap('UJI', 'Aset uji');
        $assetId = $this->terimaSatu($group);
        $this->susutkan('2026-10-01', '2026-10-31');
        $receipt = DB::table('aset_tr_aset')->where('id', $assetId)->value('penerimaan_aset_id');
        $this->cancel('penerimaan-aset', $receipt)->assertUnprocessable();
        $this->assertNull(DB::table('aset_tr_aset')->where('id', $assetId)->value('deleted_at'));
    }

    public function test_write_down_cancellation_restores_its_own_balance_and_retains_the_posted_document_values(): void
    {
        [$group, $book] = $this->groupLengkap('UJI', 'Aset uji');
        $asset = $this->terimaSatu($group);
        $this->turunkanNilai($asset, $book, '2026-09-30', 1000000);
        $document = DB::table('aset_tr_penyesuaian_nilai_aset')->first();
        $before = DB::table('aset_tr_penyesuaian_nilai_aset_details')->where('penyesuaian_nilai_aset_id', $document->id)->first();
        $this->cancel('penyesuaian-nilai-aset', $document->id)->assertCreated();
        $this->assertDatabaseHas('aset_tr_buku_aset', ['aset_id' => $asset, 'buku_id' => $book, 'write_down_amount' => 0, 'net_book_value' => 48000000]);
        $reversal = FinancePosting::query()->where('posting_type', 'asset.write_down_reversal')->firstOrFail();
        $this->assertSame($document->posting_id, $reversal->reverses_posting_id);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyesuaian-nilai-aset.read'])
            ->getJson(self::API.'penyesuaian-nilai-aset/'.$document->id)->assertOk()
            ->assertJsonPath('data.details.0.nilai_buku_sebelum', (string) $before->nilai_buku_sebelum)
            ->assertJsonPath('data.details.0.nilai_buku_sesudah', (string) $before->nilai_buku_sesudah);
    }

    public function test_request_waits_for_an_authorised_approver_and_email_is_sent_once_via_sso(): void
    {
        [$group] = $this->groupLengkap('UJI', 'Aset uji');
        $receipt = $this->draf([], [$this->baris($group, 1, 10000)]);
        $this->selesaikan($receipt)->assertOk();
        $approver = $this->configureCancellationWorkflow('penerimaan-aset');
        $request = $this->sebagaiPenggunaBernama('requester', $this->tenantId, ['management-aset.penerimaan-aset.request-cancellation'])
            ->postJson(self::API.'penerimaan-aset/'.$receipt.'/ajukan-pembatalan', $this->cancellationBody('penerimaan-aset', $receipt))
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertDatabaseHas('aset_tr_penerimaan_aset', ['id' => $receipt, 'status' => 'selesai']);
        config(['coreerp.sso.api_url' => 'https://sso.test/api/v1', 'coreerp.sso.api_client_id' => 'test-client', 'coreerp.sso.api_client_secret' => 'test-secret']);
        Http::fake(['https://sso.test/api/v1/notifications/send' => Http::response(['success' => true])]);
        Artisan::call('workflow:notify');
        Artisan::call('workflow:notify');
        Http::assertSentCount(1);
        Http::assertSent(fn ($call) => str_contains($call['content']['action']['url'], '/workflow-inbox?item=') && $call['recipient']['email'] === $approver->email && $call['idempotency_key'] !== '');
        $item = DB::table('workflow_work_items')->where('instance_id', $request['workflow_instance_id'])->first();
        $membership = TenantMembership::query()->where('tenant_id', $this->tenantId)->where('user_id', $approver->id)->firstOrFail();
        app(WorkflowRuntime::class)->decide($membership, $item->id, 'approve', 'Sudah diperiksa');
        $this->assertDatabaseHas('aset_tr_penerimaan_aset', ['id' => $receipt, 'status' => 'cancelled']);
        $this->assertDatabaseHas('aset_tr_pembatalan', ['id' => $request['id'], 'requested_by_user_id' => $this->idPengguna('requester'), 'acted_by_user_id' => (string) $approver->id, 'status' => 'applied']);
    }

    public function test_email_is_suppressed_when_outbound_is_disabled_and_failed_delivery_is_retried(): void
    {
        [$group] = $this->groupLengkap('UJI', 'Aset uji');
        $receipt = $this->draf([], [$this->baris($group, 1, 10000)]);
        $this->selesaikan($receipt)->assertOk();
        $this->configureCancellationWorkflow('penerimaan-aset');
        $request = $this->sebagaiPenggunaBernama('requester', $this->tenantId, ['management-aset.penerimaan-aset.request-cancellation'])
            ->postJson(self::API.'penerimaan-aset/'.$receipt.'/ajukan-pembatalan', $this->cancellationBody('penerimaan-aset', $receipt))->assertCreated()->json('data');
        config(['coreerp.sso.api_url' => 'https://sso.test/api/v1', 'coreerp.sso.api_client_id' => 'test-client', 'coreerp.sso.api_client_secret' => 'test-secret']);
        $environment = new class($this->app) extends ActiveEnvironment
        {
            public bool $allowOutbound = false;

            public function outboundAllowed(): bool
            {
                return $this->allowOutbound;
            }
        };
        app()->instance(ActiveEnvironment::class, $environment);
        Http::fake(['https://sso.test/api/v1/notifications/send' => Http::sequence()->push([], 500)->push(['success' => true])]);
        Artisan::call('workflow:notify');
        Http::assertNothingSent();
        $environment->allowOutbound = true;
        Artisan::call('workflow:notify');
        Artisan::call('workflow:notify');
        Http::assertSentCount(1);
        $item = DB::table('workflow_work_items')->where('instance_id', $request['workflow_instance_id'])->first();
        $this->assertNull($item->email_notified_at);
        $this->assertNotNull($item->email_last_error);
        $this->assertSame('pending', $item->status);
        $this->travel(2)->minutes();
        Artisan::call('workflow:notify');
        Artisan::call('workflow:notify');
        Http::assertSentCount(2);
        $item = DB::table('workflow_work_items')->where('id', $item->id)->first();
        $this->assertNotNull($item->email_notified_at);
        $this->assertNull($item->email_last_error);
        $this->assertSame($this->tenantId, TenantScope::activeTenant());
        $this->assertDatabaseHas('aset_tr_penerimaan_aset', ['id' => $receipt, 'status' => 'selesai']);
    }

    public function test_permission_revoked_after_assignment_prevents_approval(): void
    {
        [$group] = $this->groupLengkap('UJI', 'Aset uji');
        $receipt = $this->draf([], [$this->baris($group, 1, 10000)]);
        $this->selesaikan($receipt)->assertOk();
        $approver = $this->configureCancellationWorkflow('penerimaan-aset');
        $request = $this->sebagaiPenggunaBernama('requester', $this->tenantId, ['management-aset.penerimaan-aset.request-cancellation'])
            ->postJson(self::API.'penerimaan-aset/'.$receipt.'/ajukan-pembatalan', $this->cancellationBody('penerimaan-aset', $receipt))->assertCreated()->json('data');
        $item = DB::table('workflow_work_items')->where('instance_id', $request['workflow_instance_id'])->first();
        $this->sebagaiPenggunaBernama('approver', $this->tenantId, ['management-aset.penerimaan-aset.read']);
        $this->post('/workflow-inbox/'.$item->id.'/decision', ['decision' => 'approve'])->assertForbidden();
        $this->assertDatabaseHas('aset_tr_penerimaan_aset', ['id' => $receipt, 'status' => 'selesai']);
    }

    public function test_a_changed_document_blocks_execution_after_approval_without_partial_register_changes(): void
    {
        [$group] = $this->groupLengkap('UJI', 'Aset uji');
        $receipt = $this->draf([], [$this->baris($group, 1, 10000)]);
        $this->selesaikan($receipt)->assertOk();
        $approver = $this->configureCancellationWorkflow('penerimaan-aset');
        $request = $this->sebagaiPenggunaBernama('requester', $this->tenantId, ['management-aset.penerimaan-aset.request-cancellation'])
            ->postJson(self::API.'penerimaan-aset/'.$receipt.'/ajukan-pembatalan', $this->cancellationBody('penerimaan-aset', $receipt))->assertCreated()->json('data');
        DB::table('aset_tr_penerimaan_aset')->where('id', $receipt)->update(['keterangan' => 'Perubahan setelah diajukan']);
        $item = DB::table('workflow_work_items')->where('instance_id', $request['workflow_instance_id'])->first();
        $membership = TenantMembership::query()->where('tenant_id', $this->tenantId)->where('user_id', $approver->id)->firstOrFail();
        app(WorkflowRuntime::class)->decide($membership, $item->id, 'approve', null);
        $this->assertDatabaseHas('aset_tr_pembatalan', ['id' => $request['id'], 'status' => 'blocked']);
        $this->assertDatabaseHas('aset_tr_penerimaan_aset', ['id' => $receipt, 'status' => 'selesai']);
        $this->assertSame(0, DB::table('aset_tr_aset')->whereNotNull('deleted_at')->count());
        $this->assertSame(0, FinancePosting::query()->whereNotNull('reverses_posting_id')->count());
    }

    public function test_approver_outside_the_document_scope_cannot_execute_an_assigned_request(): void
    {
        [$group] = $this->groupLengkap('UJI', 'Aset uji');
        $receipt = $this->draf([], [$this->baris($group, 1, 10000)]);
        $this->selesaikan($receipt)->assertOk();
        $this->configureCancellationWorkflow('penerimaan-aset');
        $request = $this->sebagaiPenggunaBernama('requester', $this->tenantId, ['management-aset.penerimaan-aset.request-cancellation'])
            ->postJson(self::API.'penerimaan-aset/'.$receipt.'/ajukan-pembatalan', $this->cancellationBody('penerimaan-aset', $receipt))->assertCreated()->json('data');
        $item = DB::table('workflow_work_items')->where('instance_id', $request['workflow_instance_id'])->first();
        $this->sebagaiPenggunaBernama('approver', $this->tenantId, ['management-aset.penerimaan-aset.approve-cancellation'], [['policy_code' => 'management-aset.asset-responsibility', 'legal_entity_id' => $this->le, 'organization_id' => $this->klinik]]);
        $this->post('/workflow-inbox/'.$item->id.'/decision', ['decision' => 'approve'])->assertForbidden();
        $this->assertDatabaseHas('aset_tr_pembatalan', ['id' => $request['id'], 'status' => 'pending']);
    }

    private function configureCancellationWorkflow(string $resource): User
    {
        $this->sebagaiPenggunaBernama('approver', $this->tenantId, ['management-aset.'.$resource.'.approve-cancellation']);
        $approver = User::query()->findOrFail($this->idPengguna('approver'));
        $membership = TenantMembership::query()->where('tenant_id', $this->tenantId)->where('user_id', $approver->id)->firstOrFail();
        $typeId = (string) Str::ulid();
        DB::table('workflow_types')->insert(['id' => $typeId, 'app_id' => 'management-aset', 'scope' => 'legal_entity', 'code' => 'management-aset.'.$resource.'-cancellation', 'name' => 'Persetujuan pembatalan',
            'decision_context_schema' => json_encode(['required' => ['cancellation_id'], 'x-approval-authority' => ['permission' => 'management-aset.'.$resource.'.approve-cancellation', 'data_policy' => 'management-aset.asset-responsibility', 'disallow_submitter' => true]]), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner)->post('/settings/workflows', ['workflow_type_id' => $typeId, 'legal_entity_id' => $this->le, 'name' => 'Persetujuan pembatalan', 'assignee_type' => 'member', 'assignee_id' => $membership->id])->assertSessionHasNoErrors();
        $workflow = DB::table('workflow_configurations')->where('workflow_type_id', $typeId)->first();
        $this->post('/settings/workflows/'.$workflow->id.'/publish', ['version' => $workflow->version])->assertSessionHasNoErrors();

        return $approver;
    }

    public function test_direct_cancellation_closes_an_existing_request_and_its_work_items(): void
    {
        [$group] = $this->groupLengkap('UJI', 'Aset uji');
        $receipt = $this->draf([], [$this->baris($group, 1, 10000)]);
        $this->selesaikan($receipt)->assertOk();
        $this->configureCancellationWorkflow('penerimaan-aset');
        $request = $this->sebagaiPenggunaBernama('requester', $this->tenantId, ['management-aset.penerimaan-aset.request-cancellation'])
            ->postJson(self::API.'penerimaan-aset/'.$receipt.'/ajukan-pembatalan', $this->cancellationBody('penerimaan-aset', $receipt))->assertCreated()->json('data');
        $this->cancel('penerimaan-aset', $receipt)->assertCreated()->assertJsonPath('data.status', 'applied');
        $this->assertDatabaseHas('workflow_instances', ['id' => $request['workflow_instance_id'], 'status' => 'cancelled']);
        $this->assertSame(0, DB::table('workflow_work_items')->where('instance_id', $request['workflow_instance_id'])->where('status', 'pending')->count());
        $this->assertSame(1, FinancePosting::query()->where('posting_type', 'asset.acquisition_reversal')->count());
    }

    /** @return array<string,mixed> */
    private function cancellationBody(string $resource, string $id): array
    {
        $table = match ($resource) {
            'penerimaan-aset' => 'aset_tr_penerimaan_aset', 'penyusutan' => 'aset_tr_penyusutan_aset', default => 'aset_tr_penyesuaian_nilai_aset'
        };

        return ['reason' => 'Transaksi keliru', 'posting_date' => '2026-10-31', 'version' => DB::table($table)->where('id', $id)->value('version')];
    }

    /** @return TestResponse<JsonResponse> */
    private function cancel(string $resource, string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.'.$resource.'.cancel'])
            ->postJson(self::API.$resource.'/'.$id.'/batal', $this->cancellationBody($resource, $id));
    }
}
