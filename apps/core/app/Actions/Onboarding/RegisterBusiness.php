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
use App\Support\ControlPlane\EnvironmentAddress;
use App\Support\Modules\Contracts\TenantDisiapkan;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PengirimEventModul;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
 *
 * Pintu ketiga, `tenant:bootstrap-site`, mengikuti aturan yang sama: ia hanya menambahkan
 * `tenant_id`, supaya tenant di server on-prem lahir dengan id yang sudah dicatat admin.erp.
 */
class RegisterBusiness
{
    public function __construct(private AppDependencyGraph $dependencyGraph) {}

    /**
     * Kata sandinya datang dalam salah satu dari dua bentuk, tidak pernah keduanya: `password` teks
     * polos yang di-hash di sini, atau `password_hash` yang sudah di-hash di tempat lain. Yang kedua
     * dipakai `tenant:bootstrap-site` di server klien — kata sandi sementara owner dibuat admin.erp,
     * dan hanya hash bcrypt-nya yang menempuh jalan ke server itu. Teks polosnya tidak pernah ada di
     * server klien sama sekali, jadi tidak ada log, riwayat shell, atau keluaran perintah di sana yang
     * dapat membocorkannya.
     *
     * @param  array{name:string,email:string,password?:string,password_hash?:string,business_name:string,app_ids:list<string>,must_change_password?:bool,first_environment?:'production'|'demo'|'none',first_environment_expires_at?:?string,first_environment_hosting?:'provider'|'client_server',tenant_id?:string}  $data
     */
    public function handle(array $data): User
    {
        $hashedPassword = $this->hashedPassword($data);
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
            $tenant = new Tenant([
                'client_id' => $client->id,
                'name' => $data['business_name'],
                'slug' => $slug,
                'status' => 'active',
            ]);
            /*
             * Id yang **diberikan**, bukan dibuat — hanya untuk server on-prem.
             *
             * Catatan komersial tenant tinggal di admin.erp, datanya di server pelanggan, dan keduanya
             * harus menyebut id yang sama supaya laporan, tiket dukungan, dan SSO menunjuk tenant yang
             * sama tanpa tabel penerjemah. Lihat `tenant:bootstrap-site`.
             *
             * Ditulis langsung ke atributnya, bukan lewat `Fillable`: tidak ada permintaan yang boleh
             * memilih id tenantnya sendiri. `HasUlids` hanya membuat id ketika kolomnya kosong, jadi
             * tanpa kunci ini jalurnya persis seperti kemarin.
             */
            if (isset($data['tenant_id'])) {
                $tenant->id = $data['tenant_id'];
            }
            $tenant->save();
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

            /*
             * Di mana lingkungan pertama itu berjalan — di server kami, atau di server klien.
             *
             * Hanya produksi yang boleh di server klien, dan yang menjaganya
             * `environments_server_klien_hanya_produksi`, bukan baris ini: permintaan yang
             * menggabungkan server klien dengan demo sudah ditolak validasi pintunya, dan jalur yang
             * lupa memvalidasi berhenti di PostgreSQL di dalam transaksi yang sama.
             */
            $hosting = $data['first_environment_hosting'] ?? Environment::HOSTING_PROVIDER;
            $diServerKlien = $hosting === Environment::HOSTING_CLIENT_SERVER;

            if ($jenisPertama !== 'none') {
                $produksi = $jenisPertama === 'production';

                $environment = Environment::create([
                    'tenant_id' => $tenant->id,
                    'kind' => $jenisPertama,
                    'name' => $produksi ? 'Production' : 'Peragaan',
                    'slug' => $produksi ? $slug : 'peragaan',
                    'database_name' => null,
                    'hosting' => $hosting,
                    // Produksi ikut database bawaan, jadi ia langsung dapat dimasuki. Yang bukan
                    // produksi belum punya database sama sekali sampai seseorang menekan Siapkan —
                    // menyatakannya aktif berarti mengiklankan alamat yang dijawab 503.
                    //
                    // Produksi di server klien juga belum berjalan: ia baru ada sesudah perintah
                    // pasang dijalankan di server itu. `provisioning` adalah status registry untuk
                    // "belum berjalan", dan `active` di sini akan terbaca di setiap layar sebagai
                    // tempat kerja yang sudah dapat dipakai pelanggan.
                    'status' => $produksi && ! $diServerKlien ? 'active' : 'provisioning',
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

                /*
                 * Server klien sama: tidak ada tempat di server ini untuk memasang module-nya.
                 *
                 * Module tenant itu dipasang di servernya sendiri, oleh `tenant:bootstrap-site` di
                 * dalam paket yang hanya berisi app yang dibelinya. Menjalankan pemasangan di sini
                 * akan ditolak `InstallModule` — sesudah commit, sehingga tenant yang sudah lahir
                 * dijawab 500 — dan urutan nomor draf di database bersama tidak dipakai siapa pun.
                 * Entitlement-nya tetap tercatat di atas; dari situlah edisi situsnya diturunkan.
                 */
                if ($environment->hostedOnClientServer()) {
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

    /**
     * Hash kata sandi owner, dari salah satu bentuk masukannya.
     *
     * Hash yang diberikan diperiksa lebih dulu dengan dua pertanyaan yang sama persis dengan yang
     * diajukan cast `hashed` milik `User`, dan urutannya menentukan. Cast itu **menyimpan apa adanya**
     * nilai yang sudah berbentuk hash — itu yang membuat jalur ini mungkin — tetapi ia melempar
     * `RuntimeException` bila biaya hash-nya melebihi `BCRYPT_ROUNDS` server ini. Dilempar dari
     * `User::create`, penolakan itu datang di tengah transaksi dengan pesan bahasa Inggris yang tidak
     * menyebut sebabnya. Di sini ia datang sebelum satu baris pun ditulis, dengan kalimatnya sendiri.
     *
     * Nilai yang **tidak** berbentuk hash ditolak, bukan di-hash. Cast yang sama akan meng-hash-nya
     * diam-diam, dan owner berakhir dengan kata sandi berupa teks hash itu sendiri — akun yang tidak
     * dapat dimasuki siapa pun, dengan kata sandi yang ditunjukkan admin.erp tidak pernah cocok.
     *
     * @param  array{password?:string,password_hash?:string}  $data
     */
    private function hashedPassword(array $data): string
    {
        $given = $data['password_hash'] ?? null;

        if ($given === null) {
            if (! isset($data['password'])) {
                throw new InvalidArgumentException('Pendaftaran usaha membutuhkan kata sandi owner, dalam bentuk teks atau hash.');
            }

            return Hash::make($data['password']);
        }

        if (isset($data['password'])) {
            throw new InvalidArgumentException('Kata sandi owner diberikan dua kali — sebagai teks dan sebagai hash. Hanya salah satunya yang boleh dikirim.');
        }

        if (! Hash::isHashed($given)) {
            throw new InvalidArgumentException('Hash kata sandi owner tidak dikenali sebagai hash yang dipakai server ini.');
        }

        // Pertanyaan yang sama dengan `BcryptHasher::verifyConfiguration`, yang dipanggil cast `hashed`,
        // ditulis ulang di sini dengan fungsi PHP sendiri. Method Laravel itu `@internal` dan
        // docblock-nya salah menyebut parameternya larik, jadi memanggilnya berarti menekan analisa
        // statis; `password_get_info` memberi jawaban yang sama dengan tipe yang benar. Driver selain
        // bcrypt ikut ditolak, karena cast akan memeriksanya dengan hasher driver itu dan menolak hash
        // bcrypt mana pun.
        $info = password_get_info($given);
        $cost = $info['options']['cost'] ?? null;

        if (config('hashing.driver') !== 'bcrypt'
            || $info['algoName'] !== 'bcrypt'
            || ! is_int($cost)
            || $cost > (int) config('hashing.bcrypt.rounds', 12)) {
            throw new InvalidArgumentException(sprintf(
                'Hash kata sandi owner tidak sesuai setelan hash server ini: harus bcrypt, dengan biaya paling '
                .'tinggi BCRYPT_ROUNDS=%s (driver hash server: %s). Server ini menolak menyimpannya; samakan '
                .'setelan hash di admin.erp dan di server ini.',
                (string) config('hashing.bcrypt.rounds'),
                (string) config('hashing.driver'),
            ));
        }

        return $given;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        // Slug tenant menjadi label alamat produksinya, `<slug>.<base_domain>`. Label yang dipakai
        // layanan kita sendiri — konsol di `admin`, registry image di `registry` — dijawab router
        // Traefik yang berprioritas lebih tinggi, jadi tenant yang memperoleh slug itu lahir dengan
        // alamat yang tidak pernah sampai ke Core. Daftarnya sama dengan yang dibaca pengurai alamat.
        $reserved = EnvironmentAddress::reservedLabels();

        while (in_array($slug, $reserved, true) || Tenant::query()->where('slug', $slug)->exists() || Client::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
