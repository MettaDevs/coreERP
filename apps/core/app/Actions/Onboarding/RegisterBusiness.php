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
     * @param  array{name:string,email:string,password:string,business_name:string,app_ids:list<string>,must_change_password?:bool,first_environment?:'production'|'demo'|'none',first_environment_expires_at?:?string}  $data
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
            /*
             * Tempat kerja pertama tenant ini — dan jenisnya **dipilih**, tidak lagi selalu produksi.
             *
             * Pendaftaran mandiri tetap melahirkan produksi tanpa menyebut apa pun: orang yang
             * mendaftar sendiri memang datang untuk bekerja. Tetapi operator yang melahirkan tenant
             * bagi calon pelanggan sering belum tahu apakah mereka jadi membeli — yang ia butuhkan
             * hari itu sebuah demo berbatas waktu, dan produksi yang terlanjur lahir adalah tempat
             * kerja kosong yang tidak pernah dipakai siapa pun sekaligus alamat yang sudah terpakai.
             *
             * `none` juga sah: tenant boleh berdiri tanpa satu pun lingkungan, dan operator
             * menambahkannya kemudian dari layar Lingkungan.
             *
             * `database_name` kosong berarti ia ikut database koneksi bawaan — keadaan pooled dan
             * on-prem, dan di sana ia permanen. Demo justru sebaliknya: ia memperoleh databasenya
             * sendiri saat disiapkan, dan karena itu lahir sebagai `provisioning`, bukan `active`.
             */
            $jenisPertama = $data['first_environment'] ?? 'production';
            $environment = null;

            if ($jenisPertama !== 'none') {
                $produksi = $jenisPertama === 'production';

                $environment = Environment::create([
                    'tenant_id' => $tenant->id,
                    'kind' => $jenisPertama,
                    'name' => $produksi ? 'Production' : 'Peragaan',
                    'slug' => $produksi ? $slug : 'peragaan',
                    'database_name' => null,
                    // Produksi ikut database bawaan, jadi ia langsung dapat dimasuki. Yang bukan
                    // produksi belum punya database sama sekali sampai seseorang menekan Siapkan —
                    // menyatakannya aktif berarti mengiklankan alamat yang dijawab 503.
                    'status' => $produksi ? 'active' : 'provisioning',
                    // Di luar produksi, webhook dan pengiriman otomatis dimatikan. Itu satu-satunya
                    // alasan lingkungan terpisah dapat dipercaya memegang salinan data sungguhan.
                    'outbound_allowed' => $produksi,
                    'expires_at' => $produksi ? null : ($data['first_environment_expires_at'] ?? null),
                ]);
            }
            $membership = TenantMembership::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'system_role' => 'owner',
                'status' => 'active',
            ]);
            // Indeks navigasi: ia menentukan environment mana yang muncul di pengalih, bukan apa
            // yang boleh dikerjakan di dalamnya. Hak tetap berasal dari membership di atas.
            //
            // Dilewati ketika tenant lahir tanpa lingkungan: tidak ada yang perlu diindekskan, dan
            // barisnya berkunci asing ke `environments`.
            if ($environment instanceof Environment) {
                DB::table('environment_members')->insert([
                    'environment_id' => $environment->id,
                    'user_id' => $user->id,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

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

            DB::afterCommit(function () use ($appIds, $idEvent, $tenant, $environment): void {
                /*
                 * Tanpa lingkungan, tidak ada tempat untuk memasang module — dan itu bukan
                 * kegagalan melainkan keadaan yang sah.
                 *
                 * Entitlementnya sudah tercatat pada tenant, jadi begitu operator membuat
                 * lingkungan pertamanya dan menekan Siapkan, `InstallEntitledModules` memasang
                 * persis daftar yang sama. Yang dipisahkan di sini justru pembedaan yang menjadi
                 * dasar seluruh rancangan: entitlement milik tenant, pemasangan milik lingkungan.
                 */
                if (! $environment instanceof Environment) {
                    return;
                }

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

                    // Lingkungannya disebut, bukan dibiarkan ditebak. Produksi hari ini
                    // `database_name`-nya kosong — yaitu database bawaan — jadi jalur ini
                    // berjalan persis seperti sebelumnya. Yang berubah kelak, ketika produksi
                    // punya databasenya sendiri, adalah tempat migrationnya berjalan; dan itu
                    // berubah tanpa menyentuh baris ini lagi.
                    app(InstallModule::class)->handle($appId, $tenant->id, $environment);

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
