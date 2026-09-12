<?php

namespace App\Actions\Onboarding;

use App\Actions\Modules\InstallModule;
use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Actions\ReferenceData\ProvisionDefaultUnitsOfMeasure;
use App\Models\AppDataPolicy;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\SecurityDuty;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\AppDependencyGraph;
use App\Support\Modules\Contracts\TenantDisiapkan;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PengirimEventModul;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Satu-satunya jalur yang melahirkan tenant, dan sejak hari ini ia dipakai dua pintu.
 *
 * Pintu pertama pendaftaran mandiri: orangnya sendiri yang mengetik kata sandinya. Pintu kedua
 * operator vendor, yang mengisikan nama dan email pelanggan lalu menerima kata sandi sementara
 * buatan sistem — lihat `docs/todo/environment-dan-pusat-admin/README.md`, irisan 3.
 *
 * Yang membedakan keduanya hanya **asal kata sandinya** dan **apakah pemiliknya wajib
 * menggantinya**. Selebihnya — slug, client, tenant, environment produksi, keanggotaan owner,
 * entitlement, role Owner, sampai pemasangan module — sama persis, dan memang harus sama persis:
 * dua salinan alur ini adalah dua tempat yang akan menyimpang, dan yang menyimpang di sini adalah
 * rantai izin.
 *
 * Karena itu yang ditambahkan untuk pintu kedua cuma satu kunci opsional pada `$data`. Tanpa kunci
 * itu, jalur pendaftaran mandiri menjalankan query yang sama persis seperti sebelumnya.
 */
class RegisterBusiness
{
    public function __construct(private AppDependencyGraph $dependencyGraph) {}

    /**
     * @param  array{name:string,email:string,password:string,business_name:string,app_ids:list<string>,must_change_password?:bool}  $data
     */
    public function handle(array $data): User
    {
        $hashedPassword = Hash::make($data['password']);
        $wajibGantiSandi = $data['must_change_password'] ?? false;

        return DB::transaction(function () use ($data, $hashedPassword, $wajibGantiSandi): User {
            $appIds = $this->dependencyGraph->resolveAvailable($data['app_ids']);
            $slug = $this->uniqueSlug($data['business_name']);
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $hashedPassword,
            ]);
            // Ditulis terpisah, bukan disisipkan ke `User::create` di atas, dan itu disengaja:
            // penanda ini di luar `Fillable` supaya tidak ada permintaan yang bisa menyalakannya,
            // dan pendaftaran mandiri tidak menjalankan satu query pun lebih banyak daripada
            // kemarin.
            if ($wajibGantiSandi) {
                $user->forceFill(['must_change_password' => true])->save();
            }
            $client = Client::create([
                'legal_name' => $data['business_name'],
                'slug' => $slug,
                'status' => 'active',
            ]);
            $tenant = Tenant::create([
                'client_id' => $client->id,
                'name' => $data['business_name'],
                'slug' => $slug,
                'status' => 'active',
            ]);
            app(ProvisionDefaultUnitsOfMeasure::class)->forTenant($tenant->id);
            // Tenant provisioning adalah fakta lintas app. Payload starter sengaja
            // kosong: setiap app memilih template versinya sendiri dari konfigurasi,
            // sedangkan Core hanya meneruskan tenant context yang tepercaya.
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
            // Tempat kerja pertama tenant ini, dan satu-satunya yang boleh berjenis `production`.
            // `database_name` kosong berarti ia ikut database koneksi bawaan — keadaan pooled dan
            // on-prem, dan di sana ia permanen.
            $environment = Environment::create([
                'tenant_id' => $tenant->id,
                'kind' => 'production',
                'name' => 'Production',
                'slug' => $slug,
                'database_name' => null,
                'status' => 'active',
                'outbound_allowed' => true,
            ]);
            $membership = TenantMembership::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'system_role' => 'owner',
                'status' => 'active',
            ]);
            // Indeks navigasi: ia menentukan environment mana yang muncul di pengalih, bukan apa
            // yang boleh dikerjakan di dalamnya. Hak tetap berasal dari membership di atas.
            DB::table('environment_members')->insert([
                'environment_id' => $environment->id,
                'user_id' => $user->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($appIds as $appId) {
                $tenant->entitlements()->create([
                    'app_id' => $appId,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]);
            }

            $ownerRole = Role::create([
                'tenant_id' => $tenant->id,
                'name' => 'Owner',
                'is_active' => true,
            ]);
            $ownerRole->duties()->sync(
                SecurityDuty::query()->whereIn('app_id', $appIds)->pluck('code'),
            );
            $ownerAssignment = RoleAssignment::create([
                'membership_id' => $membership->id,
                'role_id' => $ownerRole->id,
                'source' => 'automatic',
                'status' => 'active',
                'valid_from' => now(),
            ]);
            foreach (AppDataPolicy::query()->whereIn('app_id', $appIds)->get() as $policy) {
                $ownerAssignment->dataPolicyScopes()->create([
                    'tenant_id' => $tenant->id,
                    'policy_code' => $policy->code,
                    'legal_entity_id' => null,
                    'organization_id' => null,
                    'hierarchy_id' => null,
                    'hierarchy_version_id' => null,
                    'include_descendants' => false,
                    'valid_from' => now(),
                ]);
            }

            DB::afterCommit(function () use ($appIds, $idEvent, $tenant): void {
                $registry = app(ModuleRegistry::class);

                foreach ($appIds as $appId) {
                    // Satu jalur: sebuah app dipasang bila id-nya ada sebagai folder di
                    // modules/. Memasangnya berarti menjalankan migration, mencatat
                    // pemasangan, dan mengisi data awal — semuanya di proses ini juga.
                    //
                    // Sebuah app yang berhak tetapi tidak ada sebagai module dilewati tanpa
                    // suara, dan itu memang bentuk yang benar sekarang: entitlement-nya
                    // tercatat, tetapi tidak ada apa pun yang bisa dipasang untuknya sampai
                    // module-nya benar-benar ada di edisi ini. Ia tidak akan muncul di
                    // peluncur, karena peluncur membaca catatan pemasangan.
                    if ($registry->cari($appId) === null) {
                        continue;
                    }

                    app(InstallModule::class)->handle($appId, $tenant->id);

                    // Dipancarkan **per module yang benar-benar terpasang**, bukan sekali
                    // untuk seluruh tenant, dan bedanya menentukan apakah ia benar.
                    //
                    // Sebuah tenant boleh berhak atas app yang tidak ada di edisi ini. Bila
                    // eventnya dipancarkan sekali dengan seluruh daftar app, listener module
                    // yang **tidak** terpasang ikut menjawabnya dan menyemai data ke tabel
                    // yang migrationnya belum pernah dijalankan untuk tenant itu.
                    //
                    // Letaknya sesudah `InstallModule` karena di sanalah migration, catatan
                    // pemasangan, dan urutan nomor module dibuat — dan penyediaan data awal
                    // membutuhkan ketiganya.
                    app(PengirimEventModul::class)->kirim(
                        new TenantDisiapkan($idEvent, (string) $tenant->id, (string) $tenant->id, null, ['app_ids' => [$appId]]),
                        (string) $tenant->id,
                    );
                }

                app(EnsureNumberSequenceDrafts::class)->forReadyTenant($tenant->id);

            });

            return $user;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;

        while (Tenant::query()->where('slug', $slug)->exists() || Client::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
