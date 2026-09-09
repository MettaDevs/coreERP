<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Concerns;

use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Cara test module masuk sebagai pengguna: lewat Core, bukan lewat token.
 *
 * Sebelumnya test mencetak JWT sendiri dan menempelkannya sebagai header. Itu masuk akal
 * ketika module adalah proses terpisah yang hanya bisa percaya pada tanda tangan Core. Di
 * dalam satu runtime, mencetak token berarti menguji jalur yang sudah tidak ada — dan yang
 * lebih buruk, ia melewati satu-satunya hal yang sekarang menentukan izin: rantai
 * role → duty → privilege → permission milik Core.
 *
 * Trait ini membangun rantai itu sungguhan. Konsekuensinya disengaja: test yang meminta izin
 * yang tidak ada akan gagal, bukan lolos dengan klaim yang dikarang sendiri.
 */
trait BerinteraksiDenganKonteksCore
{
    private ?string $tenantUjiId = null;

    /**
     * Baris katalog minimum supaya izin module bisa dibuat.
     *
     * `permissions` menunjuk `apps` dan `app_entry_points`; tanpa keduanya, izin apa pun yang
     * diminta test ditolak database dengan pelanggaran kunci asing, bukan dengan pesan yang
     * menyebut katalog.
     */
    private function pastikanKatalogModule(): void
    {
        DB::table('apps')->insertOrIgnore([
            'id' => 'management-aset',
            'name' => 'Management Aset',
            'description' => 'Module aset untuk test.',
            'version' => '0.1.0',
            'status' => 'active',
            'has_ui' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('app_entry_points')->insertOrIgnore([
            'code' => self::ENTRY_POINT_UJI,
            'app_id' => 'management-aset',
            'name' => 'Entry point uji',
            'type' => 'form',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private const ENTRY_POINT_UJI = 'management-aset.uji';

    private const TIPE_WORKFLOW_DEKOMISIONING = 'management-aset.dekomisioning-aset-verification';

    private const KEBIJAKAN_TANGGUNG_JAWAB = 'management-aset.asset-responsibility';

    /**
     * Tenant untuk test ini.
     *
     * Tidak ada lagi setelan klien HTTP yang perlu dipasang di sini. Keempat pemakainya —
     * penerbitan nomor, kalender fiskal, satuan, dan workflow — sudah lewat kontrak di dalam
     * proses sejak F3-06 sampai F3-09, dan pemakai terakhirnya, verifikasi tanda tangan pada
     * panggilan balik penyediaan tenant, ikut hilang bersama endpointnya di F3-11.
     */
    protected function buatTenantUji(): string
    {
        return $this->tenantUjiId = $this->pastikanTenantAda((string) Str::ulid());
    }

    /**
     * Referensi nomor module beserta urutan nomor milik tenant uji.
     *
     * Selama penerbitan nomor lewat HTTP, test cukup memalsukan jawabannya dengan `Http::fake`.
     * Lewat kontrak Core nomornya diterbitkan **sungguhan**, dan itu menuntut profil, referensi,
     * serta penghitung benar-benar ada untuk tenant ini — persis seperti tenant sungguhan setelah
     * provisioning.
     *
     * Daftarnya dibaca dari `app.yaml` module, bukan ditulis ulang di sini. Daftar kedua akan
     * menyimpang dari manifestnya pada hari seseorang menambah satu referensi, dan yang menyimpang
     * gagal dengan pesan "reference tidak dikenal" yang tidak menyebut sebabnya.
     */
    private function pastikanNomorUrutSiap(string $tenantId): void
    {
        $this->pastikanKatalogModule();
        $this->seed(NumberSequenceProfileSeeder::class);

        $manifest = Yaml::parseFile(dirname(__DIR__, 2).'/app.yaml');
        $referensi = $manifest['number_sequences']['references'] ?? [];

        foreach (is_array($referensi) ? $referensi : [] as $baris) {
            $kode = $baris['code'] ?? null;

            if (! is_string($kode) || $kode === '') {
                continue;
            }

            $referensiId = DB::table('app_number_sequence_references')->where('code', $kode)->value('id');

            if ($referensiId === null) {
                $referensiId = (string) Str::ulid();
                DB::table('app_number_sequence_references')->insert([
                    'id' => $referensiId,
                    'app_id' => 'management-aset',
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
     * Berapa nomor yang benar-benar diterbitkan Core sejauh ini.
     *
     * Menggantikan `Http::assertSentCount()` pada test yang dulu mengintip kabel. Yang diperiksa
     * sekarang **akibatnya**, bukan perjalanannya: satu baris penerbitan berarti satu nomor
     * benar-benar dipakai dan penghitungnya maju. Assertion lama tidak pernah bisa membuktikan
     * itu — jawaban palsu tidak menyentuh penghitung apa pun.
     */
    protected function jumlahNomorTerbit(): int
    {
        return DB::table('number_sequence_issues')->where('app_id', 'management-aset')->count();
    }

    /**
     * Nomor terakhir yang diterbitkan Core, atau null bila belum ada.
     */
    protected function nomorTerakhir(): ?string
    {
        $nilai = DB::table('number_sequence_issues')
            ->where('app_id', 'management-aset')
            ->orderByDesc('issued_at')
            ->value('formatted_value');

        return is_string($nilai) ? $nilai : null;
    }

    /**
     * Awalan nomor yang dijanjikan manifest untuk sebuah referensi.
     *
     * Dipakai test yang memeriksa kode yang diterbitkan. Membacanya dari manifest, bukan
     * menuliskannya sebagai konstanta di test, membuat assertion-nya sekaligus membuktikan
     * **referensi yang benar yang dipakai** — sesuatu yang tidak pernah bisa dibuktikan selama
     * nomornya dipalsukan `Http::fake`, karena jawaban palsu tidak peduli referensi apa yang
     * diminta.
     */
    protected function awalanNomor(string $kodeReferensi): string
    {
        $manifest = Yaml::parseFile(dirname(__DIR__, 2).'/app.yaml');

        foreach ($manifest['number_sequences']['references'] ?? [] as $baris) {
            if (($baris['code'] ?? null) === $kodeReferensi) {
                return (string) ($baris['default_prefix'] ?? 'NS');
            }
        }

        throw new \RuntimeException(sprintf('Referensi nomor "%s" tidak ada di app.yaml module.', $kodeReferensi));
    }

    /**
     * Kalender fiskal sungguhan untuk sebuah entitas legal.
     *
     * Dulu periode fiskal dipalsukan `Http::fake`; lewat kontrak Core ia dibaca dari database.
     * Test yang bergantung pada tahun buku non-kalender — Juli sampai Juni, misalnya — harus
     * membuatnya sungguhan, karena jawabannya sekarang datang dari baris `fiscal_years`.
     *
     * Periode dibuat bulanan sepanjang tahunnya; itu bentuk yang dipakai test yang ada.
     */
    protected function buatKalenderFiskalUji(string $tenantId, string $legalEntityId, string $mulai, string $selesai): void
    {
        $kalenderId = (string) Str::ulid();

        DB::table('fiscal_calendars')->insert([
            'id' => $kalenderId,
            'tenant_id' => $tenantId,
            'code' => 'UJI-'.Str::upper(Str::random(4)),
            'name' => 'Kalender uji',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tahunId = (string) Str::ulid();
        $awal = Carbon::parse($mulai);
        $akhir = Carbon::parse($selesai);

        DB::table('fiscal_years')->insert([
            'id' => $tahunId,
            'fiscal_calendar_id' => $kalenderId,
            'name' => 'FY'.$akhir->year,
            'starts_on' => $awal->toDateString(),
            'ends_on' => $akhir->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $periode = $awal->copy();
        $urutan = 1;

        while ($periode->lessThanOrEqualTo($akhir)) {
            $akhirPeriode = $periode->copy()->endOfMonth()->min($akhir);

            DB::table('fiscal_periods')->insert([
                'id' => (string) Str::ulid(),
                'fiscal_year_id' => $tahunId,
                'ordinal' => $urutan,
                'name' => 'P'.$urutan,
                'starts_on' => $periode->toDateString(),
                'ends_on' => $akhirPeriode->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $periode = $akhirPeriode->copy()->addDay();
            $urutan++;
        }

        // Kalender menempel pada `legal_entities`, bukan pada `organizations`: entitas legal
        // adalah pandangan tersendiri atas organisasi, dan hanya ia yang punya tahun buku.
        DB::table('legal_entities')->updateOrInsert(
            ['organization_id' => $legalEntityId],
            [
                'tenant_id' => $tenantId,
                'company_code' => 'UJI'.Str::upper(Str::random(4)),
                'country_code' => 'ID',
                'fiscal_calendar_id' => $kalenderId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Satu satuan milik tenant uji, dibuat sungguhan di Core.
     *
     * Dulu satuan dipalsukan `Http::fake`; lewat kontrak Core ia dibaca dari database. Test yang
     * memakai satuan memanggil ini dan memakai id yang dipulangkannya, bukan id karangan —
     * dengan begitu ia sekaligus membuktikan module benar-benar membaca satuan milik tenantnya.
     */
    protected function buatSatuanUji(string $tenantId, string $kode = 'cm', string $nama = 'Sentimeter'): string
    {
        $kelasId = DB::table('uom_classes')->where('tenant_id', $tenantId)->value('id');

        if ($kelasId === null) {
            $kelasId = (string) Str::ulid();
            DB::table('uom_classes')->insert([
                'id' => $kelasId,
                'tenant_id' => $tenantId,
                'code' => 'PANJANG',
                'name' => 'Panjang',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $satuanId = (string) Str::ulid();

        DB::table('units_of_measure')->insert([
            'id' => $satuanId,
            'tenant_id' => $tenantId,
            'uom_class_id' => $kelasId,
            'code' => $kode,
            'name' => $nama,
            'symbol' => Str::lower($kode),
            'decimal_places' => 2,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $satuanId;
    }

    /**
     * Organisasi dibuat hanya bila belum ada dan idnya memang disebut.
     */
    protected function pastikanOrganisasiAda(string $tenantId, ?string $organisasiId, string $klasifikasi): void
    {
        if ($organisasiId === null || DB::table('organizations')->where('id', $organisasiId)->exists()) {
            return;
        }

        DB::table('organizations')->insert([
            'id' => $organisasiId,
            'tenant_id' => $tenantId,
            'name' => 'Organisasi uji '.Str::lower(Str::random(6)),
            'classification' => $klasifikasi,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Tenant beserta client pemiliknya, dibuat hanya bila belum ada.
     */
    private function pastikanTenantAda(string $tenantId): string
    {
        if (DB::table('tenants')->where('id', $tenantId)->exists()) {
            return $tenantId;
        }

        $clientId = (string) Str::ulid();
        $unik = Str::lower(Str::random(10));

        // Tenant selalu milik satu client di Core. Sebelumnya test module mengarang id tenant
        // dengan `Str::ulid()` tanpa satu baris pun di database, karena yang membacanya cuma
        // klaim pada token buatan sendiri. Sekarang tenantnya harus benar-benar ada.
        DB::table('clients')->insert([
            'id' => $clientId,
            'legal_name' => 'Client Uji Aset',
            'slug' => 'client-uji-'.$unik,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'client_id' => $clientId,
            'name' => 'Tenant Uji Aset',
            'slug' => 'tenant-uji-'.$unik,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tiap tenant, termasuk tenant kedua yang dibuat test isolasi, mendapat urutan nomornya
        // sendiri. Nomor urut bersifat per tenant di Core; tenant tanpa urutan tidak bisa
        // menerbitkan apa pun, dan test lintas tenant akan gagal dengan 422 yang tidak
        // menyebut sebabnya.
        $this->pastikanNomorUrutSiap($tenantId);

        return $tenantId;
    }

    /**
     * Masuk sebagai pengguna tenant dengan izin persis seperti yang diminta.
     *
     * Mengembalikan `$this` supaya pemanggilannya terbaca seperti bentuk lama:
     * `$this->sebagaiPengguna($tenantId, [...])->getJson(...)`.
     *
     * @param  list<string>  $izin
     * @param  list<array{policy_code:string,legal_entity_id?:?string,organization_id?:?string,include_descendants?:bool}>  $kebijakanData
     */
    /**
     * Pengguna yang sudah dibuat pada test ini, dipetakan menurut nama panggilannya.
     *
     * @var array<string, string>
     */
    private array $penggunaBernama = [];

    /**
     * Masuk sebagai pengguna yang **sama** setiap kali nama yang sama disebut.
     *
     * Beberapa fitur menyaring per pengguna — "pekerjaan saya" pada work order, misalnya — dan
     * test-nya perlu dua permintaan berturut-turut datang dari orang yang sama. Dulu itu
     * dilakukan dengan mengoper klaim `sub` pada token; sekarang identitasnya pengguna sungguhan,
     * jadi yang perlu dipertahankan adalah pemetaan nama ke pengguna itu.
     *
     * @param  list<string>  $izin
     * @param  list<array{policy_code:string,legal_entity_id?:?string,organization_id?:?string,include_descendants?:bool}>  $kebijakanData
     */
    protected function sebagaiPenggunaBernama(string $nama, string $tenantId, array $izin, array $kebijakanData = []): static
    {
        return $this->sebagaiPengguna($tenantId, $izin, $kebijakanData, $nama);
    }

    /**
     * Id pengguna yang dibuat untuk sebuah nama panggilan.
     *
     * Dipakai test yang memeriksa kolom "dikerjakan oleh": nilainya sekarang id pengguna
     * sungguhan, bukan string bebas yang dulu dioper lewat klaim token.
     */
    protected function idPengguna(string $nama): string
    {
        return $this->penggunaBernama[$nama] ?? throw new \RuntimeException(
            sprintf('Belum ada pengguna bernama "%s" pada test ini; panggil sebagaiPenggunaBernama() lebih dulu.', $nama),
        );
    }

    protected function sebagaiPengguna(string $tenantId, array $izin, array $kebijakanData = [], ?string $nama = null): static
    {
        // Test isolasi antar tenant menyusun tenant kedua dengan `Str::ulid()` dan menaruh
        // baris module atas namanya. Itu sah — tabel module tidak menunjuk `tenants` —
        // tetapi keanggotaan menunjuk, jadi tenantnya harus benar-benar ada sebelum ada
        // pengguna yang masuk ke sana.
        $this->pastikanTenantAda($tenantId);

        // Nama yang sama berarti pengguna yang sama, supaya fitur yang menyaring per pengguna
        // bisa diuji. Nama yang tidak disebut selalu menghasilkan pengguna baru; itu bawaan
        // yang benar, karena dua permintaan yang tidak menyatakan hubungan tidak boleh
        // diam-diam dianggap datang dari orang yang sama.
        $penggunaId = $nama === null ? null : ($this->penggunaBernama[$nama] ?? null);

        if ($penggunaId !== null) {
            $pengguna = User::findOrFail($penggunaId);
            $membership = TenantMembership::where('tenant_id', $tenantId)
                ->where('user_id', $pengguna->id)
                ->firstOrFail();
        } else {
            $pengguna = User::factory()->create();

            $membership = TenantMembership::create([
                'tenant_id' => $tenantId,
                'user_id' => $pengguna->id,
                'system_role' => 'user',
                'status' => 'active',
            ]);

            if ($nama !== null) {
                $this->penggunaBernama[$nama] = (string) $pengguna->id;
            }
        }

        // Penugasan lama dinonaktifkan lebih dulu. Kalau tidak, pengguna bernama yang dipakai
        // ulang akan **menumpuk** izin dari pemanggilan sebelumnya, dan test yang membuktikan
        // sebuah langkah ditolak tanpa izin justru akan lulus dengan izin yang tersisa dari
        // langkah sebelumnya — lolos palsu yang persis kebalikan dari yang diuji.
        DB::table('role_assignments')->where('membership_id', $membership->id)->delete();

        $penugasanId = $this->beriIzin($membership, $izin);

        // Tanpa lingkup yang disebut test, pengguna diberi tanggung jawab atas **seluruh**
        // organisasi tenantnya. Itu bentuk yang sama dengan token lama, yang selalu membawa
        // `asset-responsibility` dengan `all => true` kecuali test menyebut lain. Lingkup
        // kosong akan menolak hampir semua permintaan dengan 403, dan test yang sebenarnya
        // menguji hal lain akan gagal karena sebab yang tidak ada hubungannya.
        $lingkup = $kebijakanData === []
            ? [['policy_code' => self::KEBIJAKAN_TANGGUNG_JAWAB, 'legal_entity_id' => null, 'organization_id' => null]]
            : $kebijakanData;

        foreach ($lingkup as $kebijakan) {
            $this->beriLingkupKebijakan($tenantId, $penugasanId, $kebijakan);
        }

        $this->actingAs($pengguna);

        return $this;
    }

    /**
     * Membangun rantai role → duty → privilege → permission untuk satu daftar izin.
     *
     * Satu rantai baru per pemanggilan, bukan satu rantai bersama yang ditumpuk: dua test yang
     * kebetulan memakai role yang sama akan saling memberi izin tanpa ada yang menyadarinya,
     * dan test yang membuktikan penolakan izin justru yang paling mudah lolos palsu.
     *
     * @param  list<string>  $izin
     */
    private function beriIzin(TenantMembership $membership, array $izin): string
    {
        $unik = Str::lower(Str::random(12));
        $roleId = (string) Str::ulid();
        $kodePrivilege = 'uji-priv-'.$unik;
        $kodeDuty = 'uji-duty-'.$unik;

        $this->pastikanKatalogModule();

        foreach (array_unique($izin) as $kode) {
            DB::table('permissions')->insertOrIgnore([
                'code' => $kode,
                'app_id' => 'management-aset',
                'entry_point_code' => self::ENTRY_POINT_UJI,
                'access_level' => 'read',
                'name' => $kode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('security_privileges')->insert([
            'code' => $kodePrivilege,
            'app_id' => 'management-aset',
            'name' => 'Privilege uji',
            'source' => 'system',
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_duties')->insert([
            'code' => $kodeDuty,
            'app_id' => 'management-aset',
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
            'tenant_id' => $membership->tenant_id,
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
            'membership_id' => $membership->id,
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
     * @param  array{policy_code:string,legal_entity_id?:?string,organization_id?:?string,include_descendants?:bool}  $kebijakan
     */
    private function beriLingkupKebijakan(string $tenantId, string $penugasanId, array $kebijakan): void
    {
        // Definisi kebijakannya harus ada sebelum lingkupnya. Dulu test cukup menuliskan
        // `data_policies` sebagai klaim pada token buatan sendiri; sekarang Core yang
        // menyusunnya dari katalog, jadi kebijakan yang tidak terdaftar berarti lingkup yang
        // tidak pernah terbaca — dan test batas organisasi akan lulus tanpa membatasi apa pun.
        DB::table('app_data_policies')->insertOrIgnore([
            'code' => $kebijakan['policy_code'],
            'app_id' => 'management-aset',
            'name' => $kebijakan['policy_code'],
            'protected_permissions' => json_encode([], JSON_THROW_ON_ERROR),
            'requires_legal_entity' => true,
            'requires_operating_unit' => true,
            'allows_descendants' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Organisasi yang disebut lingkup harus ada. Test batas organisasi biasanya menyebut
        // organisasi "milik orang lain" dengan `Str::ulid()`, dan dulu itu cukup karena
        // nilainya hanya klaim pada token. Sekarang lingkupnya baris database dengan kunci
        // asing ke `organizations`.
        $this->pastikanOrganisasiAda($tenantId, $kebijakan['legal_entity_id'] ?? null, 'legal_entity');
        $this->pastikanOrganisasiAda($tenantId, $kebijakan['organization_id'] ?? null, 'operating_unit');

        DB::table('role_assignment_data_policy_scopes')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'role_assignment_id' => $penugasanId,
            'policy_code' => $kebijakan['policy_code'],
            'legal_entity_id' => $kebijakan['legal_entity_id'] ?? null,
            'organization_id' => $kebijakan['organization_id'] ?? null,
            'include_descendants' => $kebijakan['include_descendants'] ?? false,
            'valid_from' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Id keanggotaan tenant milik seorang pengguna bernama.
     *
     * Dipakai penyusun workflow: penerima tugas persetujuan ditunjuk dengan id keanggotaan,
     * bukan id pengguna. Module sendiri tidak pernah membaca tabel ini — yang membacanya Core,
     * dan test ini menyusun keadaan Core, bukan keadaan module.
     */
    protected function idKeanggotaan(string $nama, string $tenantId): string
    {
        $id = DB::table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $this->idPengguna($nama))
            ->value('id');

        return is_string($id) ? $id : throw new \RuntimeException(
            sprintf('Pengguna "%s" belum menjadi anggota tenant %s.', $nama, $tenantId),
        );
    }

    /**
     * Alur persetujuan dekomisioning yang benar-benar terbit untuk sebuah entitas legal.
     *
     * Selama pengajuan berjalan lewat HTTP, test cukup memalsukan jawabannya: `Http::fake`
     * memulangkan sebuah id instance dan tidak ada satu pun konfigurasi yang perlu ada. Lewat
     * kontrak Core, instance-nya dibuat **sungguhan**, dan itu menuntut tipe workflow, sebuah
     * konfigurasi yang menyala, dan satu versi terbit dengan langkah persetujuan di dalamnya.
     *
     * Grafnya paling sederhana yang masih berarti: Mulai → Persetujuan → Selesai, satu penerima
     * tugas. Yang diuji module bukan kerumitan grafnya — itu urusan test Core — melainkan bahwa
     * keputusan pada graf mana pun sampai ke dokumennya.
     */
    protected function siapkanWorkflowDekomisioning(string $tenantId, string $legalEntityId, ?string $idKeanggotaanPemeriksa): void
    {
        $this->pastikanKatalogModule();

        $tipeId = DB::table('workflow_types')->where('code', self::TIPE_WORKFLOW_DEKOMISIONING)->value('id');

        if (! is_string($tipeId)) {
            $tipeId = (string) Str::ulid();
            DB::table('workflow_types')->insert([
                'id' => $tipeId,
                'app_id' => 'management-aset',
                'scope' => 'legal_entity',
                'code' => self::TIPE_WORKFLOW_DEKOMISIONING,
                'name' => 'Verifikasi usulan dekomisioning aset',
                // Sama dengan yang dinyatakan `app.yaml` module. Field yang diwajibkan di sini
                // diperiksa Core sebelum instance dibuat, jadi menuliskannya berbeda akan
                // membuat test lulus atas skema yang tidak pernah dipasang di produksi.
                'decision_context_schema' => json_encode(['required' => ['document_id', 'asset_id']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $konfigurasiId = (string) Str::ulid();
        DB::table('workflow_configurations')->insert([
            'id' => $konfigurasiId,
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'workflow_type_id' => $tipeId,
            'name' => 'Persetujuan dekomisioning '.Str::random(6),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versiId = (string) Str::ulid();
        DB::table('workflow_configuration_versions')->insert([
            'id' => $versiId,
            'configuration_id' => $konfigurasiId,
            'version' => 1,
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Pemeriksa yang tidak disebut berarti graf tanpa langkah persetujuan: Mulai → Selesai.
        // Bentuk ini sah dan bisa diterbitkan admin — sebuah kondisi yang langsung menuju
        // Selesai menghasilkan graf yang sama — dan instancenya sudah `approved` pada saat
        // pengajuan, bukan berhari-hari kemudian.
        $langkah = $idKeanggotaanPemeriksa === null
            ? [
                ['key' => 'mulai', 'kind' => 'start', 'label' => 'Mulai', 'configuration' => []],
                ['key' => 'selesai', 'kind' => 'end', 'label' => 'Selesai', 'configuration' => []],
            ]
            : [
                ['key' => 'mulai', 'kind' => 'start', 'label' => 'Mulai', 'configuration' => []],
                ['key' => 'periksa', 'kind' => 'approval', 'label' => 'Pemeriksaan aset', 'configuration' => [
                    'assignee' => ['type' => 'member', 'id' => $idKeanggotaanPemeriksa],
                ]],
                ['key' => 'selesai', 'kind' => 'end', 'label' => 'Selesai', 'configuration' => []],
            ];

        $elemen = [];

        foreach ($langkah as $baris) {
            $elemen[$baris['key']] = (string) Str::ulid();
            DB::table('workflow_elements')->insert([
                'id' => $elemen[$baris['key']],
                'version_id' => $versiId,
                'key' => $baris['key'],
                'kind' => $baris['kind'],
                'label' => $baris['label'],
                'configuration' => json_encode($baris['configuration'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $tepiGraf = $idKeanggotaanPemeriksa === null
            ? [['from' => 'mulai', 'to' => 'selesai', 'outcome' => null]]
            : [
                ['from' => 'mulai', 'to' => 'periksa', 'outcome' => null],
                ['from' => 'periksa', 'to' => 'selesai', 'outcome' => 'approve'],
            ];

        foreach ($tepiGraf as $tepi) {
            DB::table('workflow_transitions')->insert([
                'id' => (string) Str::ulid(),
                'version_id' => $versiId,
                'from_element_id' => $elemen[$tepi['from']],
                'to_element_id' => $elemen[$tepi['to']],
                'outcome' => $tepi['outcome'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Tugas persetujuan yang sedang menunggu seorang pemeriksa.
     */
    protected function tugasMenunggu(string $tenantId, string $idKeanggotaanPemeriksa): ?object
    {
        return DB::table('workflow_work_items')
            ->where('tenant_id', $tenantId)
            ->where('assigned_membership_id', $idKeanggotaanPemeriksa)
            ->where('status', 'pending')
            ->first();
    }
}
