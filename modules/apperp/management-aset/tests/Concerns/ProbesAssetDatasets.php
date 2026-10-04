<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Concerns;

use App\Platform\Access\Models\RoleAssignment;
use App\Platform\Identity\Models\User;
use App\Platform\Modules\Contracts\TenantProvisioned;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\TenantMembership;
use Brick\Math\BigDecimal;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\GrantsCoreRoles;

/**
 * Pemeriksaan yang wajib menyertai setiap dataset analitik module aset
 * (`docs/todo/analitik/model-semantik.md`, *Test yang wajib menyertai setiap dataset*): tenant tidak
 * bercampur, hibah kebijakan data menyaring baris persis seperti endpoint daftar module, dan pengguna tanpa
 * permission baca ditolak.
 *
 * Dunia ujinya dibangun sekali per test, bukan per permintaan: dua tenant sungguhan (lahir lewat
 * `RegisterBusiness`, jadi module terpasang dan berlisensi), satu entitas legal dan dua unit kerja di tiap
 * tenant, dan empat pengguna tenant A — pemilik (seluruh organisasi), hibah unit A saja, hibah unit B saja,
 * dan tanpa hibah. Rantai izinnya rantai sungguhan (role, duty, privilege, permission), sehingga test
 * yang meminta izin yang tidak ada gagal, bukan lolos dengan klaim yang dikarang.
 *
 * Test dataset memakai trait ini lalu mengisi `seedRows()` dan keterangan baris yang diharapkan. Data awal
 * disisipkan langsung ke tabel module, seperti `WalkingSkeletonTest`: Core tidak menulis ke tabel module.
 */
trait ProbesAssetDatasets
{
    use GrantsCoreRoles;

    private const POLICY = 'management-aset.asset-responsibility';

    protected User $owner;

    protected string $tenantId;

    protected string $legalEntity;

    protected string $unitA;

    protected string $unitB;

    /** @var array<string, array{group: string, jenis: string}> */
    private array $masterIds = [];

    /** @var array<string, string> id aset menurut kodenya; kode aset unik di seluruh test karena memuat tag tenant */
    protected array $assetIds = [];

    /** Kode dataset yang diuji. */
    abstract protected function datasetCode(): string;

    /** Permission baca yang menjaga dataset dan layar daftarnya. */
    abstract protected function readPermission(): string;

    /** Alamat daftar module, di bawah `/api/modules/management-aset/v1/`. */
    abstract protected function listResource(): string;

    /**
     * Kunci dimensi dataset yang bersama-sama membedakan baris. Nilainya digabung dengan `|` dan
     * dibandingkan dengan `listKey()`.
     *
     * @return list<string>
     */
    abstract protected function dimensions(): array;

    /** Isi satu tenant: tenant A dan tenant B memakai data awal yang sama, dengan `$tag` berbeda. */
    abstract protected function seedRows(string $tenant, string $legalEntity, string $unitA, string $unitB, string $tag): void;

    /**
     * Jumlah baris dataset (measure `count`) untuk tenant A: seluruhnya, dan yang terlihat hibah unit A atau
     * unit B saja.
     *
     * @return array{all: int, unitA: int, unitB: int}
     */
    abstract protected function expectedRows(): array;

    /**
     * Jumlah kombinasi `dimensions()` yang berbeda, untuk dataset yang barisnya lebih banyak daripada dokumennya.
     * Bawaannya sama dengan jumlah baris.
     *
     * @return array{all: int, unitA: int, unitB: int}
     */
    protected function expectedKeys(): array
    {
        return $this->expectedRows();
    }

    /**
     * Saringan yang dipasang pada dataset saat membandingkannya dengan daftar module, untuk daftar yang
     * sendirinya hanya menampilkan sebagian baris (misalnya buku aktif saja). Bawaannya tanpa saringan.
     *
     * @return array<string, string|list<string>>
     */
    protected function datasetFilters(): array
    {
        return [];
    }

    /**
     * Nilai pembeda satu baris daftar module, sama bentuknya dengan nilai `dimensions()` dataset digabung `|`.
     *
     * @param  array<string, mixed>  $row
     */
    protected function listKey(array $row): string
    {
        return (string) $row['kode'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NumberSequenceProfileSeeder::class);
        $this->assertSame(0, Artisan::call('app:register-manifest', ['module' => 'management-aset']));
        Event::fake([TenantProvisioned::class]);

        $this->owner = $this->business('Tenant analitik A', 'owner-a@analitik.test');
        $this->tenantId = (string) $this->owner->activeMembership()?->tenant_id;
        $this->legalEntity = $this->organization($this->tenantId, 'legal_entity', 'CV Analitik');
        $this->unitA = $this->organization($this->tenantId, 'operating_unit', 'Unit A');
        $this->unitB = $this->organization($this->tenantId, 'operating_unit', 'Unit B');
        $this->seedRows($this->tenantId, $this->legalEntity, $this->unitA, $this->unitB, 'A');
    }

    public function test_two_tenants_never_mix(): void
    {
        $count = fn (User $user): int => (int) $this->analyze($user, ['dataset' => $this->datasetCode(), 'measures' => ['count']])->json('rows.0.count');

        // Tenant lain dengan data awal yang sama: bila saringan tenant bocor, jumlah baris menggandakan diri.
        $ownerOfOtherTenant = $this->business('Tenant analitik B', 'owner-b@analitik.test');
        $otherTenant = (string) $ownerOfOtherTenant->activeMembership()?->tenant_id;
        $this->seedRows(
            $otherTenant,
            $this->organization($otherTenant, 'legal_entity', 'PT Tenant B'),
            $this->organization($otherTenant, 'operating_unit', 'Unit B1'),
            $this->organization($otherTenant, 'operating_unit', 'Unit B2'),
            'B',
        );

        $this->assertSame($this->expectedRows()['all'], $count($this->owner));
        // Angka tenant B juga hanya miliknya sendiri.
        $this->assertSame($this->expectedRows()['all'], $count($ownerOfOtherTenant));
    }

    public function test_data_policy_grants_narrow_rows_exactly_like_the_module_list(): void
    {
        $expected = $this->expectedKeys();
        $principals = [
            'seluruh organisasi' => [$this->owner, $expected['all']],
            'hibah unit A' => [$this->member($this->readPermission(), [[$this->legalEntity, $this->unitA]]), $expected['unitA']],
            'hibah unit B' => [$this->member($this->readPermission(), [[$this->legalEntity, $this->unitB]]), $expected['unitB']],
            'tanpa hibah' => [$this->member($this->readPermission()), 0],
        ];

        foreach ($principals as $who => [$user, $count]) {
            $fromList = $this->listKeys($user);
            $fromDataset = $this->datasetKeys($user);

            $this->assertCount($count, $fromList, "Daftar module untuk {$who} tidak memuat jumlah baris yang diharapkan.");
            $this->assertSame($fromList, $fromDataset, "Dataset untuk {$who} tidak sama dengan daftar module.");
        }
    }

    public function test_member_without_the_read_permission_is_forbidden(): void
    {
        // Memegang permission module aset, tetapi bukan permission baca resource ini.
        $other = $this->member('management-aset.group-aset.read', [[$this->legalEntity, $this->unitA]]);

        $this->analyze($other, ['dataset' => $this->datasetCode(), 'measures' => ['count']])
            ->assertForbidden()
            ->assertExactJson(['error' => ['code' => 'analytics.dataset_forbidden', 'message' => 'Anda tidak punya akses ke data ini.', 'field' => 'dataset']]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<Response>
     */
    protected function analyze(User $user, array $query): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/analytics/query', $query);
    }

    /** @return list<string> kombinasi `dimensions()` yang dikembalikan dataset untuk pengguna ini, terurut */
    protected function datasetKeys(User $user): array
    {
        $rows = $this->analyze($user, [
            'dataset' => $this->datasetCode(), 'dimensions' => $this->dimensions(), 'measures' => ['count'],
            'filters' => $this->datasetFilters(),
        ])->assertOk()->json('rows');

        return $this->sorted(array_values(array_map(
            fn (array $row): string => implode('|', array_map(fn (string $key): string => (string) $row[$key], $this->dimensions())),
            $rows,
        )));
    }

    /** @return list<string> nilai pembeda yang dikembalikan daftar module untuk pengguna ini, terurut */
    protected function listKeys(User $user): array
    {
        $rows = $this->actingAs($user)->getJson('/api/modules/management-aset/v1/'.$this->listResource())
            ->assertOk()->json('data');

        return $this->sorted(array_values(array_unique(array_map(fn (array $row): string => $this->listKey($row), $rows))));
    }

    /** Angka desimal dari JSON dibandingkan menurut nilainya, bukan menurut tulisannya (`19000000` sama dengan `19000000.000000`). */
    protected function assertDecimal(string $expected, mixed $actual, string $message = ''): void
    {
        $this->assertTrue(BigDecimal::of($expected)->isEqualTo((string) $actual), $message !== '' ? $message : "Seharusnya {$expected}, bukan {$actual}.");
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * Anggota tenant A yang memegang satu permission saja, dengan hibah kebijakan aset per pasangan
     * entitas legal dan unit.
     *
     * @param  list<array{0: string, 1: string}>  $grants
     */
    protected function member(string $permission, array $grants = []): User
    {
        $user = User::factory()->create();
        $membership = TenantMembership::query()->create([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'status' => 'active',
        ]);
        // `core.analytics.analyze` membuka pintu analitik (area 4); permission baca resource tetap menjaga datasetnya.
        $this->grantDuties($membership, ['core.analytics.analyze', $this->dutyFor($permission)]);

        $assignment = RoleAssignment::query()->where('membership_id', $membership->id)->firstOrFail();
        foreach ($grants as [$legalEntity, $unit]) {
            $assignment->dataPolicyScopes()->create([
                'tenant_id' => $membership->tenant_id, 'policy_code' => self::POLICY,
                'legal_entity_id' => $legalEntity, 'organization_id' => $unit,
                'hierarchy_id' => null, 'hierarchy_version_id' => null, 'include_descendants' => false,
                'valid_from' => now()->subMinute(),
            ]);
        }

        return $user;
    }

    /** Duty katalog yang memuat permission ini, dibaca dari katalog yang didaftarkan manifest module. */
    private function dutyFor(string $permission): string
    {
        $duty = DB::table('security_duty_privileges as dp')
            ->join('security_privilege_permissions as pp', 'pp.privilege_code', '=', 'dp.privilege_code')
            ->where('pp.permission_code', $permission)
            ->orderBy('dp.duty_code')
            ->value('dp.duty_code');
        $this->assertNotNull($duty, "Tidak ada duty di katalog yang memuat {$permission}.");

        return (string) $duty;
    }

    private function business(string $name, string $email): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => $name, 'app_ids' => ['management-aset'], 'email' => $email, 'password' => 'password',
        ]);
    }

    protected function organization(string $tenantId, string $classification, string $name): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'name' => $name, 'classification' => $classification,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($classification === 'legal_entity') {
            DB::table('legal_entities')->insert([
                'organization_id' => $id, 'company_code' => Str::upper(Str::random(4)), 'country_code' => 'ID',
                'timezone' => 'Asia/Makassar', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /**
     * Group dan jenis aset sebuah tenant, dibuat sekali; aset wajib menunjuk keduanya.
     *
     * @return array{group: string, jenis: string}
     */
    protected function masters(string $tenantId): array
    {
        return $this->masterIds[$tenantId] ??= [
            'group' => $this->master($tenantId, 'aset_m_group_aset', 'GRP-ANL'),
            'jenis' => $this->master($tenantId, 'aset_m_jenis_aset', 'JNS-ANL'),
        ];
    }

    /** @param array<string, mixed> $extra */
    protected function master(string $tenantId, string $table, string $code, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $code, 'nama' => $code, 'aktif' => true, ...$extra, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Satu aset di register. Unit dimensi keuangan boleh diisi lewat `$extra`, supaya kebijakan yang memakai
     * kolom yang salah terlihat sebagai angka yang salah.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function asset(string $tenantId, string $legalEntity, string $code, ?string $unit, string $currency = 'IDR', string $value = '1000000', array $extra = []): string
    {
        $id = (string) Str::ulid();
        $masters = $this->masters($tenantId);
        DB::table('aset_tr_aset')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $code, 'nama' => 'Aset '.$code, 'legal_entity_id' => $legalEntity,
            'responsible_org_unit_id' => $unit, 'group_aset_id' => $masters['group'], 'jenis_aset_id' => $masters['jenis'],
            'acquired_on' => '2026-09-01', 'acquisition_value' => $value, 'currency_code' => $currency,
            'lifecycle_state' => 'received', 'created_at' => now(), 'updated_at' => now(),
            ...$extra,
        ]);

        return $this->assetIds[$code] = $id;
    }
}
