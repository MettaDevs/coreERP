<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Migration yang membuang kolom jalan tanpa internet dari registry situs.
 *
 * Yang dijaga dua hal: baris yang masih menyebut jalan itu menghentikan migration **sebelum** apa pun
 * berubah, dan `down()` mengembalikan skema yang dapat dinaikkan lagi. Keduanya dijalankan pada
 * migration yang sama yang sudah dinaikkan `RefreshDatabase` — `down()` lalu `up()` di dalam transaksi
 * test, yang di PostgreSQL ikut membatalkan DDL-nya.
 */
class SiteRegistryColumnsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_14_130000_drop_connectivity_and_channel_from_site_registry.php';

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['legal_name' => 'PT Uji Situs', 'slug' => 'uji-situs', 'status' => 'active']);
        $this->tenantId = (string) Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Situs',
            'slug' => 'ujisitus',
            'status' => 'active',
        ])->id;
    }

    public function test_down_restores_the_columns_with_the_values_of_the_only_path_and_up_runs_again(): void
    {
        $seen = $this->site(['name' => 'Terlihat', 'last_seen_at' => now()]);
        $never = $this->site(['name' => 'Belum']);
        $this->token($seen);
        $this->report($seen);

        $this->migration()->down();

        $this->assertSame(['online'], DB::table('sites')->distinct()->pluck('connectivity')->all());
        $this->assertSame('heartbeat', DB::table('sites')->where('id', $seen)->value('last_seen_via'));
        $this->assertNull(DB::table('sites')->where('id', $never)->value('last_seen_via'));
        $this->assertSame('online', DB::table('site_enrollment_tokens')->value('channel'));
        $this->assertSame('heartbeat', DB::table('site_reports')->value('via'));
        // Dibatasi ke schema test ini: schema test konsol di database yang sama memuat constraint
        // dengan nama yang sama.
        $constraints = ['site_enrollment_tokens_kanal_dikenal', 'site_reports_asal_dikenal', 'sites_konektivitas_dikenal', 'sites_terlihat_berpasangan', 'sites_terlihat_lewat_dikenal'];
        $this->assertSame($constraints, array_column(DB::select(
            'SELECT c.conname FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
             WHERE n.nspname = current_schema() AND c.conname IN (?, ?, ?, ?, ?)
             ORDER BY c.conname',
            $constraints,
        ), 'conname'));

        // Kolom yang dikembalikan tidak berbawaan, sama dengan aslinya: penulis yang lupa menyebutnya
        // ditolak, bukan diam-diam diisi.
        $this->assertSame([], array_column(DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND column_default IS NOT NULL
               AND (table_name, column_name) IN (('sites', 'connectivity'), ('site_enrollment_tokens', 'channel'), ('site_reports', 'via'))",
        ), 'column_name'));

        $this->migration()->up();

        $this->assertColumnsGone();
        $this->assertSame(2, DB::table('sites')->count());
        $this->assertSame(1, DB::table('site_enrollment_tokens')->count());
        $this->assertSame(1, DB::table('site_reports')->count());
    }

    /** @return iterable<string, array{string, string}> */
    public static function rowsFromTheOtherPath(): iterable
    {
        yield 'situs tanpa internet' => ['site_offline', 'connectivity = offline'];
        yield 'situs yang terakhir terlihat lewat file' => ['site_seen_by_file', 'last_seen_via = file'];
        yield 'token pendaftaran kanal offline' => ['token_offline', '1 token channel = offline'];
        yield 'laporan yang datang sebagai file' => ['report_file', '1 laporan via = file'];
    }

    #[DataProvider('rowsFromTheOtherPath')]
    public function test_a_row_from_the_other_path_stops_the_migration_before_anything_changes(string $row, string $named): void
    {
        $this->migration()->down();

        $online = $this->site(['name' => 'Online', 'connectivity' => 'online', 'last_seen_at' => now(), 'last_seen_via' => 'heartbeat']);
        $this->token($online, ['channel' => 'online']);
        $this->report($online, ['via' => 'heartbeat']);

        match ($row) {
            'site_offline' => $this->site(['name' => 'Tanpa Internet', 'connectivity' => 'offline']),
            'site_seen_by_file' => $this->site(['name' => 'Lewat File', 'connectivity' => 'online', 'last_seen_at' => now(), 'last_seen_via' => 'file']),
            'token_offline' => $this->token($online, ['channel' => 'offline']),
            'report_file' => $this->report($online, ['via' => 'file']),
        };

        // Ditangkap lalu diperiksa di luar `try`: kegagalan asersi PHPUnit sendiri juga RuntimeException.
        $refusal = null;

        try {
            $this->migration()->up();
        } catch (RuntimeException $e) {
            $refusal = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $refusal, 'Baris yang menyebut jalan tanpa internet tidak boleh hilang bersama kolomnya.');
        $this->assertStringContainsString($named, $refusal->getMessage());
        $this->assertStringContainsString('Tidak ada yang diubah', $refusal->getMessage());

        foreach ([['sites', 'connectivity'], ['sites', 'last_seen_via'], ['site_enrollment_tokens', 'channel'], ['site_reports', 'via']] as [$table, $column]) {
            $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} ikut terbuang padahal migration menolak.");
        }
    }

    public function test_the_fresh_schema_has_none_of_the_columns(): void
    {
        $this->assertColumnsGone();
    }

    private function assertColumnsGone(): void
    {
        $this->assertFalse(Schema::hasColumn('sites', 'connectivity'));
        $this->assertFalse(Schema::hasColumn('sites', 'last_seen_via'));
        $this->assertFalse(Schema::hasColumn('site_enrollment_tokens', 'channel'));
        $this->assertFalse(Schema::hasColumn('site_reports', 'via'));
    }

    private ?object $migration = null;

    private function migration(): object
    {
        return $this->migration ??= require database_path(self::MIGRATION);
    }

    /** @param  array<string, mixed>  $attributes */
    private function site(array $attributes = []): string
    {
        $id = (string) Str::ulid();

        DB::table('sites')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'name' => 'Situs '.$id,
            'profile' => 'managed_on_prem',
            'edition' => 'apotek-uji',
            'timezone' => 'Asia/Jakarta',
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);

        return $id;
    }

    /** @param  array<string, mixed>  $attributes */
    private function token(string $siteId, array $attributes = []): string
    {
        $id = (string) Str::ulid();

        DB::table('site_enrollment_tokens')->insert([
            'id' => $id,
            'site_id' => $siteId,
            'token_hash' => hash('sha256', $id),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);

        return $id;
    }

    /** @param  array<string, mixed>  $attributes */
    private function report(string $siteId, array $attributes = []): string
    {
        $id = (string) Str::ulid();

        DB::table('site_reports')->insert([
            'id' => $id,
            'site_id' => $siteId,
            'payload' => '{}',
            'payload_hash' => hash('sha256', $id),
            'reported_at' => now(),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);

        return $id;
    }
}
