<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Products;

use App\Platform\ControlPlane\Models\ManagedProductTenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ProductTenantProvisioner
{
    public function reconcileSsoRevocation(string $id): void
    {
        $tenant = ManagedProductTenant::query()->find($id);
        $pending = $tenant?->pending_sso_revocation;
        if ($tenant === null || $pending === null) {
            return;
        }
        $config = config('managed_products.products.'.$tenant->product);
        if (! is_array($config) || empty($config['worker_url']) || empty($config['worker_token'])) {
            return;
        }
        try {
            $response = Http::acceptJson()->withToken($config['worker_token'])->connectTimeout(3)->timeout(10)
                ->put(rtrim($config['worker_url'], '/').'/tenants/'.$id.'/sso-revocation', $pending);
            if ($response->status() !== 200) {
                return;
            }
            $tenant->getConnection()->transaction(function () use ($tenant, $pending): void {
                $changed = ManagedProductTenant::query()->whereKey($tenant->id)
                    ->where('pending_sso_revocation', json_encode($pending, JSON_THROW_ON_ERROR))
                    ->update(['pending_sso_revocation' => null]);
                if ($changed === 1) {
                    $this->audit($tenant, 'product-tenant.sso-revocation.delivered', null, ['customer_id' => $pending['customer_id']]);
                }
            });
        } catch (Throwable $failure) {
            report(new RuntimeException('Pencabutan sesi tenant '.$id.' tertunda ('.$failure::class.').'));
        }
    }

    public function reconcile(string $id): void
    {
        $model = new ManagedProductTenant;
        $tenant = $model->getConnection()->transaction(function () use ($id): ?ManagedProductTenant {
            $tenant = ManagedProductTenant::query()->lockForUpdate()->find($id);
            if ($tenant === null || ! in_array($tenant->status, ['queued', 'provisioning'], true)
                || ($tenant->lease_until !== null && $tenant->lease_until->isFuture())) {
                return null;
            }
            $tenant->update(['lease_until' => now()->addSeconds(45)]);

            return $tenant;
        });
        if ($tenant === null) {
            return;
        }
        try {
            $config = config('managed_products.products.'.$tenant->product);
            if (! is_array($config) || empty($config['worker_url']) || empty($config['worker_token'])) {
                throw new RuntimeException('Worker produk belum dikonfigurasi.');
            }
            $client = Http::acceptJson()->withToken($config['worker_token'])->connectTimeout(3)->timeout(10);
            $url = rtrim($config['worker_url'], '/').'/tenants/'.$tenant->id;
            $response = $tenant->status === 'queued'
                ? $client->put($url, array_merge($tenant->only(['id', 'product', 'profile', 'name', 'slug', 'owner_name', 'owner_email']), [
                    'expires_at' => $tenant->expires_at?->toIso8601String(),
                    'release' => $tenant->release,
                    'retry' => $tenant->retry_requested,
                ]))
                : $client->get($url);
            if ($response->clientError() && $response->status() !== 429) {
                $tenant->getConnection()->transaction(function () use ($tenant): void {
                    $tenant->update(['status' => 'failed', 'lease_until' => null, 'failure_message' => 'Worker menolak penyiapan tenant. Periksa konfigurasi produk dan log worker sebelum mencoba lagi.']);
                    $this->audit($tenant, 'product-tenant.status', null, ['status' => 'failed']);
                });

                return;
            }
            $response->throw();
            $result = $response->json();
            if (! is_array($result) || ! in_array($result['status'] ?? null, ['provisioning', 'ready', 'failed'], true)) {
                throw new RuntimeException('Worker mengembalikan status yang tidak dikenal.');
            }
            $expectedUrl = 'https://'.$tenant->slug.'.'.$tenant->release['base_domain'].'/';
            if ($result['status'] === 'ready' && ($result['public_url'] ?? null) !== $expectedUrl) {
                throw new RuntimeException('Alamat tenant hasil penyiapan tidak cocok.');
            }
            if ($result['status'] === 'ready') {
                foreach (['username', 'password', 'operator_username', 'operator_password'] as $field) {
                    if (! is_string($result['credentials'][$field] ?? null) || $result['credentials'][$field] === '') {
                        throw new RuntimeException('Kredensial hasil penyiapan belum lengkap.');
                    }
                }
            }
            $tenant->getConnection()->transaction(function () use ($tenant, $result, $expectedUrl): void {
                if (isset($result['credentials']) && is_array($result['credentials'])) {
                    $tenant->getConnection()->table('console_settings')->updateOrInsert(
                        ['key' => 'product-tenant:'.$tenant->id],
                        ['value' => Crypt::encryptString(json_encode($result['credentials'], JSON_THROW_ON_ERROR)), 'updated_at' => now(), 'updated_by' => $tenant->requested_by],
                    );
                }
                $previousStatus = $tenant->status;
                $tenant->update([
                    'status' => $result['status'], 'lease_until' => null, 'retry_requested' => false,
                    'public_url' => $result['status'] === 'ready' ? $expectedUrl : null,
                    'failure_message' => $result['status'] === 'failed' ? 'Penyiapan gagal. Periksa log worker produk, lalu coba lagi.' : null,
                ]);
                if ($previousStatus !== $tenant->status) {
                    $this->audit($tenant, 'product-tenant.status', null, ['status' => $tenant->status]);
                }
            });
        } catch (Throwable $failure) {
            report(new RuntimeException('Penyiapan tenant '.$tenant->id.' gagal ('.$failure::class.').'));
            $tenant->update(['lease_until' => null, 'failure_message' => 'Worker belum dapat dihubungi atau jawabannya tidak valid. Sistem akan mencoba lagi.']);
        }
    }

    /** @param array<string, mixed> $detail */
    public function audit(ManagedProductTenant $tenant, string $action, ?int $operator, array $detail = []): void
    {
        $tenant->getConnection()->table('operator_audit_events')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $operator, 'action' => $action,
            'subject_type' => 'managed_product_tenant', 'subject_id' => $tenant->id,
            'detail' => json_encode($detail, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }
}
