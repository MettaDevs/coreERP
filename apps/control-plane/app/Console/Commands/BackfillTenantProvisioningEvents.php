<?php

namespace App\Console\Commands;

use App\Support\Modules\Contracts\TenantDisiapkan;
use App\Support\Modules\PengirimEventModul;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillTenantProvisioningEvents extends Command
{
    protected $signature = 'tenant-provisioning:backfill {--tenant=* : Batasi ke satu atau beberapa tenant ULID}';

    protected $description = 'Buat event provisioning yang idempoten untuk tenant lama.';

    public function __construct(private readonly PengirimEventModul $pengirim)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $requested = collect($this->option('tenant'))->filter()->values();
        foreach ($requested as $tenantId) {
            if (! Str::isUlid((string) $tenantId)) {
                $this->components->error('Tenant harus berupa ULID.');

                return self::FAILURE;
            }
        }

        $tenants = DB::table('tenants')
            ->where('status', 'active')
            ->when($requested->isNotEmpty(), fn ($query) => $query->whereIn('id', $requested->all()))
            ->orderBy('created_at')
            ->get(['id']);
        $created = 0;

        foreach ($tenants as $tenant) {
            $appIds = DB::table('tenant_app_entitlements')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->where('starts_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->pluck('app_id')
                ->map(fn (mixed $appId): string => (string) $appId)
                ->sort()
                ->values()
                ->all();
            $exists = DB::table('outbox_events')
                ->where('tenant_id', $tenant->id)
                ->where('type', 'core.tenant.provisioned.v1')
                ->get(['payload'])
                ->contains(function (object $event) use ($appIds): bool {
                    $payload = json_decode($event->payload, true);
                    $existingAppIds = is_array($payload) && is_array($payload['app_ids'] ?? null)
                        ? collect($payload['app_ids'])->map(fn (mixed $appId): string => (string) $appId)->sort()->values()->all()
                        : [];

                    return $existingAppIds === $appIds;
                });
            if ($exists) {
                continue;
            }

            $idEvent = (string) Str::ulid();
            DB::table('outbox_events')->insert([
                'id' => $idEvent,
                'tenant_id' => $tenant->id,
                'type' => 'core.tenant.provisioned.v1',
                'correlation_id' => $tenant->id,
                'legal_entity_id' => null,
                'payload' => json_encode(['app_ids' => $appIds], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Module di dalam runtime ini tidak akan pernah menerima baris outbox tadi: yang
            // mengirimnya lewat HTTP sengaja melewatkan penerima yang berada di dalam proses.
            // Tanpa pemancaran di sini, backfill hanya menghasilkan baris yang ditandai
            // terkirim tanpa ada yang menyiapkan data awalnya — tenant lama tetap kosong.
            $this->pengirim->kirim(
                new TenantDisiapkan($idEvent, (string) $tenant->id, (string) $tenant->id, null, ['app_ids' => $appIds]),
                (string) $tenant->id,
            );
            $created++;
        }

        $this->components->info("{$created} event provisioning tenant dibuat; {$tenants->count()} tenant diperiksa.");

        return self::SUCCESS;
    }
}
