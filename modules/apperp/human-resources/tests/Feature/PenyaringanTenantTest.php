<?php

declare(strict_types=1);

namespace Modules\Apperp\HumanResources\Tests\Feature;

use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Apperp\HumanResources\Models\Worker;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Penyaringan tenant module Human Resources, dibuktikan lewat Core yang sungguhan.
 *
 * **Apa yang berubah dari test yang digantikannya, dan kenapa.** Versi lama mencetak JWT
 * sendiri, menandatanganinya dengan kunci yang ia pasang lewat `config()`, lalu menempelkannya
 * sebagai header `Authorization`. Itu masuk akal ketika module adalah proses terpisah yang
 * hanya bisa mempercayai tanda tangan Core. Di dalam satu runtime, tidak ada satu pun bagian
 * jalur itu yang masih ada: tidak ada middleware yang memverifikasi token, tidak ada kunci
 * penandatangan, dan izin tidak lagi datang sebagai klaim melainkan dihitung Core dari rantai
 * role → duty → privilege → permission. Mempertahankan bentuk lama berarti menguji jalur yang
 * sudah tidak dilalui siapa pun — hijau yang tidak menjaga apa-apa.
 *
 * Yang dibuktikan tetap sama persis: sebuah daftar hanya memulangkan baris yang memang boleh
 * dilihat pemintanya. Hanya saja sekarang dua batasnya diuji terpisah, karena keduanya berbeda
 * jenis dan yang satu tidak pernah menangkap kebocoran yang satunya:
 *
 * 1. **Batas organisasi** — lingkup kebijakan data menahan unit kerja yang bukan tanggung
 *    jawab pengguna. Ini yang diuji test lama, dan ia ada di dalam module.
 * 2. **Batas tenant** — `MilikTenant` menahan baris milik pelanggan lain. Ini tidak pernah bisa
 *    diuji sebelumnya: tiap tenant punya databasenya sendiri, jadi tidak ada satu pun query
 *    yang bisa melihat tenant lain walaupun ia mau. Sekarang semua tenant satu tabel, dan
 *    kebocorannya tidak pernah gagal dengan sendirinya — ia tampak seperti daftar yang isinya
 *    kebetulan banyak.
 *
 * Test ini berdiri sendiri tanpa trait bersama, berbeda dari test modul aset. Pemetaan
 * autoload untuk namespace test module tinggal di `composer.json` Core, dan berkas itu di luar
 * jangkauan pekerjaan ini; PHPUnit memuat berkas testnya sendiri lewat jalur, jadi satu berkas
 * yang lengkap berjalan tanpa pemetaan itu, sedangkan trait di berkas kedua tidak.
 */
class PenyaringanTenantTest extends TestCase
{
    use RefreshDatabase;

    private const KEBIJAKAN = 'human-resources.workforce-responsibility';

    private const ENTRY_POINT = 'human-resources.uji';

    public function test_daftar_posisi_hanya_memulangkan_unit_kerja_yang_dilingkupi_kebijakan(): void
    {
        $tenantId = $this->buatTenantUji();
        $unitBoleh = $this->buatUnitKerja($tenantId);
        $unitLain = $this->buatUnitKerja($tenantId);

        $this->seedPosisi($tenantId, $unitBoleh, 'Posisi yang boleh dilihat');
        $this->seedPosisi($tenantId, $unitLain, 'Posisi kantor lain');

        $data = $this->sebagaiPengguna($tenantId, ['human-resources.positions.read'], [
            ['policy_code' => self::KEBIJAKAN, 'organization_id' => $unitBoleh],
        ])->getJson('/api/modules/human-resources/v1/positions')->assertOk()->json('data');

        $this->assertCount(1, $data, 'Daftar posisi memulangkan unit kerja di luar lingkup kebijakan data pengguna.');
        $this->assertSame('Posisi yang boleh dilihat', $data[0]['name']);
    }

    /**
     * Pekerja milik tenant lain tidak pernah ikut terbaca, walaupun ia ada di tabel yang sama.
     *
     * Pekerja tenant sendiri sengaja dibuat lewat endpoint, bukan disisipkan langsung ke tabel.
     * Dengan begitu jalur tulisnya ikut terbukti: nomor induk diterbitkan Core lewat kontrak di
     * dalam transaksi yang sama, dan `MilikTenant` yang mengisi `tenant_id` — bukan controller,
     * yang sekarang tidak menuliskannya sama sekali.
     */
    public function test_daftar_pekerja_tidak_pernah_memuat_baris_tenant_lain(): void
    {
        $tenantId = $this->buatTenantUji();
        $tenantLain = $this->buatTenantUji();

        $this->seedPekerja($tenantLain, 'Pekerja pelanggan lain');

        $dibuat = $this->sebagaiPengguna($tenantId, ['human-resources.workers.create'])
            ->postJson('/api/modules/human-resources/v1/workers', [
                'name' => 'Pekerja tenant ini',
                'idempotency_key' => 'pekerja-tenant-ini',
            ])->assertCreated()->json('data');

        $this->assertSame($tenantId, $dibuat['tenant_id'], 'Pekerja tersimpan atas nama tenant yang bukan tenant aktif permintaannya.');
        $this->assertSame(
            $this->awalanNomor('human-resources.pekerja').'-000001',
            $dibuat['personnel_number'],
            'Nomor induk tidak diterbitkan Core lewat kontrak; nomor yang datang dari tempat lain tidak pernah memajukan penghitung mana pun.',
        );

        $data = $this->sebagaiPengguna($tenantId, ['human-resources.workers.read'])
            ->getJson('/api/modules/human-resources/v1/workers')->assertOk()->json('data');

        $this->assertSame(['Pekerja tenant ini'], array_column($data, 'name'), 'Daftar pekerja memuat baris milik tenant lain.');
    }

    /**
     * Menyimpan baris atas nama tenant lain dibatalkan, bukan diterima diam-diam.
     *
     * Ini bagian penyaringan yang tidak pernah tertangkap dua test di atas: sebuah scope baca
     * tidak melihat baris yang sedang ditulis. Sebelum `MilikTenant` menjaga penulisan, module
     * bisa menyimpan baris dengan `tenant_id` milik orang lain sementara tenant aktif berbeda,
     * dan tidak ada satu pun yang menahannya — bukan `NOT NULL`, karena kolomnya terisi.
     */
    public function test_menyimpan_pekerja_atas_nama_tenant_lain_dibatalkan(): void
    {
        $tenantId = $this->buatTenantUji();
        $tenantLain = $this->buatTenantUji();

        $this->expectException(RuntimeException::class);

        $this->app->make(PelaksanaUntukTenant::class)->jalankanUntuk($tenantId, function () use ($tenantLain): void {
            Worker::query()->create([
                'tenant_id' => $tenantLain,
                'creation_key' => 'pekerja-tenant-lain',
                'personnel_number' => 'PEGH-999999',
                'name' => 'Pekerja titipan',
            ]);
        });
    }

    /**
     * Tabel module dibuat test ini sendiri bila belum ada.
     *
     * Selama module masih terdaftar sedang dipindah, penyedia layanan Core menjalankan
     * migrationnya bersama migration Core, jadi tabelnya sudah ada. Entri itu dibuang begitu
     * pembentukan ulang selesai, dan bersamanya jalur tersebut; di produksi tabel dibuat saat
     * module dipasang, dan test tidak memasang module. Baris di bawah yang menjaga test ini
     * tetap berjalan sesudahnya.
     */
    private function pastikanTabelModuleAda(): void
    {
        if (Schema::hasTable('hr_workers')) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 2).'/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    /**
     * Baris katalog minimum supaya izin module bisa dibuat.
     *
     * Tanpa `apps` dan `app_entry_points`, setiap izin yang diminta test ditolak database
     * dengan pelanggaran kunci asing — pesan yang tidak menyebut katalog sama sekali.
     */
    private function pastikanKatalogModule(): void
    {
        DB::table('apps')->insertOrIgnore([
            'id' => 'human-resources',
            'name' => 'Human Resources',
            'description' => 'Module tenaga kerja untuk test.',
            'version' => '0.1.0',
            'status' => 'active',
            'has_ui' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('app_entry_points')->insertOrIgnore([
            'code' => self::ENTRY_POINT,
            'app_id' => 'human-resources',
            'name' => 'Entry point uji',
            'type' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buatTenantUji(): string
    {
        $this->pastikanTabelModuleAda();
        $this->pastikanKatalogModule();

        $tenantId = (string) Str::ulid();
        $clientId = (string) Str::ulid();
        $unik = Str::lower(Str::random(10));

        // Tenant selalu milik satu client di Core. Test lama mengarang id tenant tanpa satu
        // baris pun di database, karena yang membacanya hanya klaim pada token buatannya
        // sendiri. Sekarang keanggotaan menunjuk tenant, jadi tenantnya harus benar-benar ada.
        DB::table('clients')->insert([
            'id' => $clientId,
            'legal_name' => 'Client Uji HR',
            'slug' => 'client-uji-hr-'.$unik,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'client_id' => $clientId,
            'name' => 'Tenant Uji HR',
            'slug' => 'tenant-uji-hr-'.$unik,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->pastikanNomorUrutSiap($tenantId);

        return $tenantId;
    }

    /**
     * Referensi nomor module beserta urutan nomor milik tenant uji.
     *
     * Selama penerbitan nomor lewat HTTP, test cukup memalsukan jawabannya. Lewat kontrak Core
     * nomornya diterbitkan sungguhan, dan itu menuntut profil, referensi, serta penghitung
     * benar-benar ada untuk tenant ini — persis seperti tenant sungguhan setelah provisioning.
     *
     * Daftarnya dibaca dari `app.yaml` module, bukan ditulis ulang di sini: daftar kedua akan
     * menyimpang pada hari seseorang menambah satu referensi.
     */
    private function pastikanNomorUrutSiap(string $tenantId): void
    {
        $this->seed(NumberSequenceProfileSeeder::class);

        foreach ($this->referensiNomor() as $baris) {
            $kode = $baris['code'] ?? null;

            if (! is_string($kode) || $kode === '') {
                continue;
            }

            $referensiId = DB::table('app_number_sequence_references')->where('code', $kode)->value('id');

            if ($referensiId === null) {
                $referensiId = (string) Str::ulid();
                DB::table('app_number_sequence_references')->insert([
                    'id' => $referensiId,
                    'app_id' => 'human-resources',
                    'code' => $kode,
                    'name' => $baris['name'] ?? $kode,
                    'default_prefix' => $baris['default_prefix'] ?? null,
                    'allowed_scopes' => json_encode($baris['allowed_scopes'] ?? ['tenant'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('tenant_number_sequences')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'reference_id' => $referensiId,
                'profile_code' => 'non-continuous-default',
                'scope_type' => 'tenant',
                'status' => 'active',
                'is_continuous' => false,
                'allow_manual' => false,
                'reset_period' => 'never',
                'preallocation_enabled' => false,
                'preallocation_quantity' => 1,
                'minimum_number' => 1,
                'segments' => json_encode([
                    ['type' => 'constant', 'value' => $baris['default_prefix'] ?? 'NS'],
                    ['type' => 'constant', 'value' => '-'],
                    ['type' => 'number', 'length' => 6],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Awalan nomor yang dijanjikan manifest untuk sebuah referensi.
     *
     * Dibaca dari manifest, bukan dituliskan sebagai konstanta, supaya assertion-nya sekaligus
     * membuktikan referensi yang benar yang dipakai — sesuatu yang tidak pernah bisa dibuktikan
     * selama nomornya dipalsukan, karena jawaban palsu tidak peduli referensi apa yang diminta.
     */
    private function awalanNomor(string $kodeReferensi): string
    {
        foreach ($this->referensiNomor() as $baris) {
            if (($baris['code'] ?? null) === $kodeReferensi) {
                return (string) ($baris['default_prefix'] ?? 'NS');
            }
        }

        throw new RuntimeException(sprintf('Referensi nomor "%s" tidak ada di app.yaml module.', $kodeReferensi));
    }

    /** @return list<array<string, mixed>> */
    private function referensiNomor(): array
    {
        /** @var array<string, mixed> $manifest */
        $manifest = Yaml::parseFile(dirname(__DIR__, 2).'/app.yaml');
        $nomor = $manifest['number_sequences'] ?? [];
        $referensi = is_array($nomor) ? ($nomor['references'] ?? []) : [];

        return is_array($referensi) ? array_values(array_filter($referensi, 'is_array')) : [];
    }

    private function buatUnitKerja(string $tenantId): string
    {
        $id = (string) Str::ulid();

        DB::table('organizations')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => 'Unit kerja '.Str::lower(Str::random(6)),
            'classification' => 'operating_unit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Baris module disisipkan lewat query builder, bukan lewat model, dan itu disengaja.
     *
     * `MilikTenant` membatalkan penyimpanan baris milik tenant selain tenant aktif — persis
     * yang dibuktikan test ketiga. Menuntut penyemaian memakai model berarti membuat test
     * kebocoran antar tenant mustahil ditulis, yaitu membuang penjagaan terpenting demi
     * menegakkan aturannya.
     */
    private function seedPosisi(string $tenantId, string $unitKerjaId, string $nama): void
    {
        DB::table('hr_positions')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'creation_key' => 'position-'.Str::ulid(),
            'code' => 'POSH'.Str::random(5),
            'name' => $nama,
            'job_id' => (string) Str::ulid(),
            'operating_unit_id' => $unitKerjaId,
            'valid_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPekerja(string $tenantId, string $nama): void
    {
        DB::table('hr_workers')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'creation_key' => 'worker-'.Str::ulid(),
            'personnel_number' => 'PEGH-'.Str::random(6),
            'name' => $nama,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Masuk sebagai pengguna tenant dengan izin persis seperti yang diminta.
     *
     * Rantai role → duty → privilege → permission dibangun sungguhan. Konsekuensinya
     * disengaja: test yang meminta izin yang tidak ada akan gagal, bukan lolos dengan klaim
     * yang dikarang sendiri seperti pada token buatan test lama.
     *
     * @param  list<string>  $izin
     * @param  list<array{policy_code: string, organization_id?: ?string}>  $kebijakanData
     */
    private function sebagaiPengguna(string $tenantId, array $izin, array $kebijakanData = []): static
    {
        $pengguna = User::factory()->create();

        $membership = TenantMembership::create([
            'tenant_id' => $tenantId,
            'user_id' => $pengguna->id,
            'system_role' => 'user',
            'status' => 'active',
        ]);

        $penugasanId = $this->beriIzin((string) $membership->id, $tenantId, $izin);

        // Tanpa lingkup yang disebut test, pengguna diberi tanggung jawab atas seluruh
        // organisasi tenantnya — bentuk yang sama dengan token lama, yang selalu membawa
        // kebijakan ini dengan `all => true` kecuali test menyebut lain. Lingkup kosong akan
        // menolak hampir semua permintaan, dan test yang sebenarnya menguji hal lain akan
        // gagal karena sebab yang tidak ada hubungannya.
        $lingkup = $kebijakanData === []
            ? [['policy_code' => self::KEBIJAKAN, 'organization_id' => null]]
            : $kebijakanData;

        foreach ($lingkup as $kebijakan) {
            $this->beriLingkupKebijakan($tenantId, $penugasanId, $kebijakan);
        }

        $this->actingAs($pengguna);

        return $this;
    }

    /**
     * @param  list<string>  $izin
     */
    private function beriIzin(string $membershipId, string $tenantId, array $izin): string
    {
        $unik = Str::lower(Str::random(12));
        $roleId = (string) Str::ulid();
        $kodePrivilege = 'uji-priv-'.$unik;
        $kodeDuty = 'uji-duty-'.$unik;

        foreach (array_unique($izin) as $kode) {
            DB::table('permissions')->insertOrIgnore([
                'code' => $kode,
                'app_id' => 'human-resources',
                'entry_point_code' => self::ENTRY_POINT,
                'access_level' => 'read',
                'name' => $kode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('security_privileges')->insert([
            'code' => $kodePrivilege,
            'app_id' => 'human-resources',
            'name' => 'Privilege uji',
            'source' => 'system',
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_duties')->insert([
            'code' => $kodeDuty,
            'app_id' => 'human-resources',
            'name' => 'Duty uji',
            'source' => 'system',
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_duty_privileges')->insert([
            'duty_code' => $kodeDuty,
            'privilege_code' => $kodePrivilege,
        ]);

        foreach (array_unique($izin) as $kode) {
            DB::table('security_privilege_permissions')->insert([
                'privilege_code' => $kodePrivilege,
                'permission_code' => $kode,
            ]);
        }

        DB::table('roles')->insert([
            'id' => $roleId,
            'tenant_id' => $tenantId,
            'name' => 'Role uji '.$unik,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_role_duties')->insert([
            'role_id' => $roleId,
            'duty_code' => $kodeDuty,
        ]);

        $penugasanId = (string) Str::ulid();

        DB::table('role_assignments')->insert([
            'id' => $penugasanId,
            'membership_id' => $membershipId,
            'role_id' => $roleId,
            'source' => 'manual',
            'status' => 'active',
            'valid_from' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $penugasanId;
    }

    /**
     * @param  array{policy_code: string, organization_id?: ?string}  $kebijakan
     */
    private function beriLingkupKebijakan(string $tenantId, string $penugasanId, array $kebijakan): void
    {
        // Definisi kebijakannya harus ada sebelum lingkupnya. Dulu test cukup menuliskan
        // `data_policies` sebagai klaim pada tokennya sendiri; sekarang Core yang menyusunnya
        // dari katalog, jadi kebijakan yang tidak terdaftar berarti lingkup yang tidak pernah
        // terbaca — dan test batas organisasi akan lulus tanpa membatasi apa pun.
        //
        // Bentuknya diambil dari `app.yaml` module: tanggung jawab tenaga kerja ditentukan unit
        // kerja, bukan entitas legal.
        DB::table('app_data_policies')->insertOrIgnore([
            'code' => $kebijakan['policy_code'],
            'app_id' => 'human-resources',
            'name' => $kebijakan['policy_code'],
            'protected_permissions' => json_encode([], JSON_THROW_ON_ERROR),
            'requires_legal_entity' => false,
            'requires_operating_unit' => true,
            'allows_descendants' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_assignment_data_policy_scopes')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'role_assignment_id' => $penugasanId,
            'policy_code' => $kebijakan['policy_code'],
            'legal_entity_id' => null,
            'organization_id' => $kebijakan['organization_id'] ?? null,
            'include_descendants' => false,
            'valid_from' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
