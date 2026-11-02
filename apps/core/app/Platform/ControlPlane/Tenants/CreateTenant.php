<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Tenants;

use App\Platform\ControlPlane\Models\Client;
use App\Platform\Environment\Support\EnvironmentAddress;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Models\ProviderAccess;
use App\Platform\Tenant\Contracts\TenantRegistrar;
use App\Platform\Tenant\Events\TenantCreated;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantCreationRequest;
use App\Platform\Tenant\Models\TenantMembership;
use App\Platform\Tenant\Support\TenantCreation;
use App\Platform\Tenant\Support\TenantCreationSource;
use App\Platform\Tenant\Support\TenantCreator;
use App\Platform\Tenant\Support\TenantDetails;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final class CreateTenant implements TenantRegistrar
{
    public function handle(TenantCreator $creator, TenantDetails $details): TenantCreation
    {
        $connection = (new Tenant)->getConnection();

        try {
            return $connection->transaction(function () use ($creator, $details, $connection): TenantCreation {
                if ($creator->source === TenantCreationSource::Operator) {
                    $operator = User::query()->findOrFail((int) $creator->actor->getKey());
                    abort_unless($operator->account_type === User::PERSON && ProviderAccess::query()->where('user_id', $operator->id)->where('role', 'provider_admin')->sharedLock()->first() !== null, 404);
                }

                $connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['tenant-create:'.$creator->scope().':'.$details->idempotencyKey]);
                $principal = User::query()->findOrFail($creator->owner->id);
                abort_unless($principal->account_type === User::PERSON, 409, 'Akun aplikasi tidak dapat menjadi anggota organisasi.');
                $name = trim($details->name);
                $requestedSlug = $details->slug === null ? null : Str::lower(trim($details->slug));
                $hash = hash('sha256', json_encode([$principal->id, $name, $requestedSlug, $details->fixedTenantId], JSON_THROW_ON_ERROR));
                $receipt = TenantCreationRequest::query()->where('actor_scope', $creator->scope())->where('idempotency_key', $details->idempotencyKey)->first();

                if ($receipt !== null) {
                    abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'Kunci permintaan sudah digunakan untuk data lain.');
                    $tenant = Tenant::query()->findOrFail($receipt->tenant_id);
                    $membership = TenantMembership::query()->findOrFail($receipt->membership_id);
                    abort_unless($tenant->status === 'active' && $membership->status === 'active', 409, 'Organisasi atau keanggotaan sudah tidak aktif.');

                    return new TenantCreation($tenant, $membership, $principal, true);
                }

                $base = $requestedSlug ?? (Str::slug($name) ?: 'business');
                $connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['tenant-slug:'.$base]);
                $slug = $base;

                while (in_array($slug, EnvironmentAddress::reservedLabels(), true) || Tenant::query()->where('slug', $slug)->exists() || Client::query()->where('slug', $slug)->exists()) {
                    abort_if($requestedSlug !== null, 409, 'Alamat organisasi sudah dipakai. Pilih alamat lain.');
                    $slug = $base.'-'.Str::lower(Str::random(6));
                }

                $client = Client::create(['legal_name' => $name, 'slug' => $slug, 'status' => 'active']);
                $tenant = new Tenant(['client_id' => $client->id, 'name' => $name, 'slug' => $slug, 'status' => 'active']);

                if ($details->fixedTenantId !== null) {
                    $tenant->id = $details->fixedTenantId;
                }

                $tenant->save();
                $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $principal->id, 'status' => 'active']);
                $actorId = match ($creator->source) {
                    TenantCreationSource::Customer => $principal->id,
                    TenantCreationSource::System => null,
                    default => (int) $creator->actor->getKey(),
                };
                $receipt = TenantCreationRequest::create([
                    'actor_scope' => $creator->scope(), 'idempotency_key' => $details->idempotencyKey,
                    'request_hash' => $hash, 'actor_user_id' => $actorId, 'principal_id' => $principal->id,
                    'tenant_id' => $tenant->id, 'membership_id' => $membership->id,
                ]);
                $connection->table('operator_audit_events')->insert([
                    'id' => (string) Str::ulid(), 'user_id' => $actorId, 'action' => 'tenant.created',
                    'subject_type' => 'tenant', 'subject_id' => $tenant->id,
                    'detail' => json_encode(['principal_id' => $principal->id, 'membership_id' => $membership->id, 'creation_request_id' => $receipt->id, 'source' => $creator->source->value], JSON_THROW_ON_ERROR),
                    'occurred_at' => now(),
                ]);
                event(new TenantCreated($tenant->id));

                return new TenantCreation($tenant, $membership, $principal);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Organisasi atau identitas berubah saat diproses. Muat ulang lalu coba kembali.');
        }
    }
}
