<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Products;

use App\Platform\ControlPlane\Models\ManagedProductTenant;
use App\Platform\ControlPlane\Models\ProductCustomer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final class CreateProductTenant
{
    public function __construct(private ProductTenantProvisioner $provisioner) {}

    /** @param array<string, mixed> $input */
    public function handle(array $input, ?int $operator, ?ProductCustomer $customer = null): ManagedProductTenant
    {
        $product = config('managed_products.products.'.$input['product']);
        abort_unless(is_array($product) && isset($product['profiles'][$input['profile']]), 422, 'Pilihan jenis fasilitas tidak tersedia.');
        $identity = array_intersect_key($input, array_flip(['product', 'profile', 'name', 'slug', 'owner_name', 'owner_email', 'expires_at']));
        if ($customer !== null) {
            $identity['owner_customer_id'] = $customer->id;
        }
        ksort($identity);
        $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        try {
            return (new ManagedProductTenant)->getConnection()->transaction(function () use ($input, $operator, $customer, $product, $hash): ManagedProductTenant {
                if ($customer !== null) {
                    (new ManagedProductTenant)->getConnection()->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['product-trial-quota:'.$input['product']]);
                    $customer = ProductCustomer::query()->lockForUpdate()->findOrFail($customer->id);
                    abort_unless($customer->email_verified_at !== null && $customer->verified_email !== null, 422, 'Verifikasi email sebelum membuat trial.');
                    abort_unless($input['owner_email'] === $customer->verified_email, 409, 'Data email berubah. Muat ulang pendaftaran sebelum mencoba lagi.');
                }
                $existing = ManagedProductTenant::query()->where('idempotency_key', $input['idempotency_key'])->first();
                if ($existing !== null) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Kunci permintaan sudah digunakan untuk data lain.');

                    return $existing;
                }
                if ($customer !== null) {
                    abort_if(ManagedProductTenant::query()->where('owner_customer_id', $customer->id)->where('product', $input['product'])->exists(), 409, 'Akun ini sudah mempunyai trial produk tersebut.');
                    $limit = max(0, min(1000, (int) ($product['public_trial_limit'] ?? 20)));
                    abort_if(ManagedProductTenant::query()->where('product', $input['product'])->whereNotNull('owner_customer_id')->count() >= $limit,
                        429, 'Slot trial sedang penuh. Hubungi pengelola atau coba lagi nanti.');
                }
                $tenant = ManagedProductTenant::query()->create(array_merge($input, [
                    'id' => (string) Str::uuid(), 'request_hash' => $hash, 'status' => 'queued',
                    'requested_by' => $operator, 'owner_customer_id' => $customer?->id,
                    'release' => array_merge($product['release'], ['base_domain' => $product['base_domain']]),
                ]));
                $this->provisioner->audit($tenant, 'product-tenant.create', $operator, [
                    'product' => $tenant->product, 'profile' => $tenant->profile, 'customer_id' => $customer?->id,
                ]);

                return $tenant;
            });
        } catch (UniqueConstraintViolationException) {
            $tenant = ManagedProductTenant::query()->where('idempotency_key', $input['idempotency_key'])->first();
            abort_unless($tenant !== null && hash_equals($tenant->request_hash, $hash), 409, 'Alamat tenant sudah digunakan atau batas trial sudah tercapai.');

            return $tenant;
        }
    }
}
