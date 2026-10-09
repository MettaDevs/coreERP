<?php

namespace App\Foundation\Workflow\Console;

use App\Foundation\Workflow\Support\WorkflowApprovalAuthority;
use App\Platform\Environment\Support\ActiveEnvironment;
use App\Platform\Identity\Support\Sso\SsoApiClient;
use App\Platform\Modules\Support\TenantScope;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendWorkflowNotifications extends Command
{
    protected $signature = 'workflow:notify {--limit=50}';

    protected $description = 'Mengirim notifikasi tugas persetujuan melalui fasilitas email SSO.';

    public function handle(SsoApiClient $api, WorkflowApprovalAuthority $authority): int
    {
        if (! $api->isConfigured()) {
            $this->info('API email SSO belum disetel. Permintaan tetap tersedia di aplikasi.');

            return self::SUCCESS;
        }
        $ids = DB::table('workflow_work_items')->where('status', 'pending')->whereNull('email_notified_at')
            ->whereNotNull('review_url')->where(fn ($query) => $query->whereNull('email_next_attempt_at')->orWhere('email_next_attempt_at', '<=', now()))
            ->orderBy('created_at')->limit(max(1, min(500, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $api, $authority): void {
                $item = DB::table('workflow_work_items')->where('id', $id)->lockForUpdate()->first();
                if ($item->status !== 'pending' || $item->email_notified_at !== null) {
                    return;
                }
                $instance = DB::table('workflow_instances')->where('id', $item->instance_id)->where('tenant_id', $item->tenant_id)->first();
                $member = TenantMembership::query()->where('tenant_id', $item->tenant_id)->where('id', $item->assigned_membership_id)->first();
                if ($instance === null || $instance->status !== 'pending' || $member === null || ! $authority->allows($instance, $member)) {
                    return;
                }
                $user = $member->user;
                $type = DB::table('workflow_types')->where('id', $instance->workflow_type_id)->first();
                $context = json_decode($instance->decision_context, true, 512, JSON_THROW_ON_ERROR);
                $previousTenant = app()->bound(TenantScope::KEY) ? app(TenantScope::KEY) : null;
                app()->instance(TenantScope::KEY, $item->tenant_id);
                $environment = app(ActiveEnvironment::class);
                $environment->forget();
                try {
                    if (! $environment->outboundAllowed()) {
                        return;
                    }
                    $documentNumber = (string) ($context['document_number'] ?? $instance->source_document_id);
                    $response = $api->post('/notifications/send', [
                        'idempotency_key' => $item->id,
                        'recipient' => ['email' => $user->email, 'name' => $user->name],
                        'subject' => mb_substr($type->name.' · '.$documentNumber, 0, 200),
                        'category' => 'workflow', 'event_type' => 'core.workflow.approval_requested',
                        'content' => [
                            'header_title' => 'Permintaan persetujuan',
                            'headline' => mb_substr($type->name, 0, 255),
                            'highlight_box' => [
                                'fields' => [['label' => 'Dokumen', 'value' => mb_substr($documentNumber, 0, 500)]],
                                'description' => (string) ($context['reason'] ?? 'Ada permintaan yang membutuhkan keputusan Anda.'),
                            ],
                            'action' => ['label' => 'Periksa permintaan', 'url' => $item->review_url],
                        ],
                    ]);
                    if (! $response->successful() || $response->json('success') !== true) {
                        throw new \RuntimeException('Pengirim email menjawab HTTP '.$response->status().'.');
                    }
                    DB::table('workflow_work_items')->where('id', $id)->update(['email_notified_at' => now(), 'email_last_error' => null, 'email_attempts' => $item->email_attempts + 1]);
                } catch (Throwable $failure) {
                    DB::table('workflow_work_items')->where('id', $id)->update([
                        'email_last_error' => 'Email belum dapat dikirim. Periksa sambungan API SSO dan SMTP pada SSO.',
                        'email_attempts' => $item->email_attempts + 1, 'email_next_attempt_at' => now()->addMinutes(min(60, 2 ** min(6, $item->email_attempts))),
                    ]);
                    $this->warn('Notifikasi '.$id.' belum terkirim; akan dicoba kembali.');
                } finally {
                    if (is_string($previousTenant)) {
                        app()->instance(TenantScope::KEY, $previousTenant);
                    } else {
                        app()->forgetInstance(TenantScope::KEY);
                    }
                    $environment->forget();
                }
            });
        }

        return self::SUCCESS;
    }
}
