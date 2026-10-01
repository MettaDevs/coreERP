<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\User;
use App\Platform\Tenant\Actions\RegisterBusiness;
use App\Platform\Tenant\Models\TenantMembership;
use App\Support\Modules\Contracts\TenantDisiapkan;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\Group;
use stdClass;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

/**
 * Ekspor daftar di layar (K-27, TODO 8.4) dari ujung ke ujung, dengan register aset module aset yang sungguhan
 * sebagai pilot: permintaan dari layar, antrean ekspor Core, baris yang dibaca module dengan hak dan kebijakan
 * data yang sama dengan layarnya, lalu berkas di tray Ekspor.
 *
 * Core tidak menyentuh tabel aset di mana pun pada jalur ini. Test ini menyisipkan aset langsung ke tabel
 * module hanya sebagai data awal, seperti `ReportingTest` menyisipkan master work order.
 */
class ListExportTest extends TestCase
{
    use GrantsCoreRoles, RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    private string $legalEntityId;

    private string $orgUnitId;

    /** @var array<string, string> */
    private array $masters = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync', 'reporting.disk' => 'reporting-test']);
        Storage::fake('reporting-test');
        $this->seed(NumberSequenceProfileSeeder::class);
        $this->artisan('app:register-manifest', ['module' => 'management-aset'])->assertSuccessful();
        Event::fake([TenantDisiapkan::class]);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant ekspor daftar',
            'app_ids' => ['management-aset'], 'email' => 'owner@daftar.test', 'password' => 'password',
        ]);
        $this->membership = $this->owner->activeMembership();
        $this->legalEntityId = $this->legalEntity();
        $this->orgUnitId = (string) Str::ulid();

        $tipe = $this->master('aset_m_tipe_lokasi_aset', 'Ruang', 'TLKA-L1');
        $this->masters = [
            'elektronik' => $this->master('aset_m_group_aset', 'Elektronik', 'GRPA-L1'),
            'kendaraan' => $this->master('aset_m_group_aset', 'Kendaraan', 'GRPA-L2'),
            'laptop' => $this->master('aset_m_jenis_aset', 'Laptop', 'JNSA-L1'),
            'gudang' => $this->master('aset_m_lokasi_aset', 'Gudang Cakung', 'LOCA-L1', ['tipe_lokasi_id' => $tipe]),
        ];
    }

    public function test_exports_the_visible_columns_in_screen_order_with_the_active_filter_and_sort(): void
    {
        $this->asset('AST-L1', 'Laptop Rina', 15000000.5, 'elektronik', 'SN-1');
        $this->asset('AST-L2', 'Laptop Budi', 22000000, 'elektronik', null);
        $this->asset('AST-L3', 'Mobil operasional', 250000000, 'kendaraan', 'SN-3', 'disposed');

        $response = $this->actingAs($this->owner)->postJson('/api/v1/list-exports', [
            'app_id' => 'management-aset',
            'list' => 'aset',
            'columns' => [
                ['key' => 'nama', 'header' => 'Nama aset'],
                ['key' => 'kode', 'header' => 'Kode aset'],
                ['key' => 'nilai', 'header' => 'Nilai perolehan'],
                ['key' => 'group', 'header' => 'Group aset'],
                ['key' => 'status', 'header' => 'Status'],
            ],
            'sort' => ['column' => 'nilai', 'direction' => 'desc'],
            'filters' => ['q' => 'laptop'],
        ])->assertStatus(202)->assertJsonPath('data.kind', 'list')->assertJsonPath('data.report_name', 'Register aset')->json('data');

        $export = $this->export($response['id']);
        $this->assertSame('done', $export->status, (string) $export->failure_message);
        $this->assertSame('xlsx', $export->format);
        $this->assertSame(2, (int) $export->row_count);
        $this->assertStringStartsWith('Register-aset-', (string) $export->file_name);

        $rows = $this->xlsxRows($export);
        $this->assertSame(['Nama aset', 'Kode aset', 'Nilai perolehan', 'Group aset', 'Status'], $rows[0]);
        // Hanya yang cocok dengan pencarian, urut nilai dari yang terbesar, dengan nama group dan label status.
        $this->assertSame([
            ['Laptop Budi', 'AST-L2', 22000000, 'Elektronik', 'Diterima'],
            ['Laptop Rina', 'AST-L1', 15000000.5, 'Elektronik', 'Diterima'],
        ], array_slice($rows, 1));

        $book = $this->xlsx($export);
        $this->assertSame('"Rp "#,##0.00;-"Rp "#,##0.00', $book->getActiveSheet()->getCell('C2')->getStyle()->getNumberFormat()->getFormatCode());

        // Tampil di tray Ekspor seperti ekspor laporan, dan dapat diunduh pemintanya.
        $this->actingAs($this->owner)->getJson('/api/v1/report-exports')->assertOk()
            ->assertJsonPath('data.0.id', $response['id'])->assertJsonPath('data.0.kind', 'list');
        $this->actingAs($this->owner)->get('/api/v1/report-exports/'.$response['id'].'/download')->assertOk();

        // Kolom dan urutan harus milik daftar itu.
        $this->actingAs($this->owner)->postJson('/api/v1/list-exports', [
            'app_id' => 'management-aset', 'list' => 'aset', 'columns' => [['key' => 'acquisition_value']],
        ])->assertStatus(422)->assertJsonValidationErrors('columns.0.key');
        $this->actingAs($this->owner)->postJson('/api/v1/list-exports', [
            'app_id' => 'management-aset', 'list' => 'aset', 'columns' => [['key' => 'kode']], 'sort' => ['column' => 'id', 'direction' => 'asc'],
        ])->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_needs_the_read_permission_of_the_list_and_follows_its_organization_policy(): void
    {
        $this->asset('AST-P1', 'Laptop Rina', 15000000, 'elektronik', 'SN-1');
        $request = ['app_id' => 'management-aset', 'list' => 'aset', 'columns' => [['key' => 'kode', 'header' => 'Kode']]];

        // Tanpa hak melihat register aset, daftarnya tidak ada — sama seperti layarnya.
        $workOrdersOnly = $this->member(['management-aset.pemeliharaan-aset.manage']);
        $this->actingAs($workOrdersOnly)->postJson('/api/v1/list-exports', $request)->assertNotFound();
        $this->actingAs($this->owner)->postJson('/api/v1/list-exports', [...$request, 'list' => 'tidak-ada'])->assertNotFound();

        // Hak melihat ada, tetapi tidak ada unit kerja yang diberikan kepadanya: ekspor kosong, persis
        // seperti daftar di layarnya, bukan seluruh aset tenant.
        $viewer = $this->member(['management-aset.aset.manage']);
        $id = $this->actingAs($viewer)->postJson('/api/v1/list-exports', $request)->assertStatus(202)->json('data.id');
        $export = $this->export($id);
        $this->assertSame('done', $export->status, (string) $export->failure_message);
        $this->assertSame(0, (int) $export->row_count);
        $this->assertSame([['Kode']], $this->xlsxRows($export));

        // Pemilik memegang seluruh cakupan.
        $id = $this->actingAs($this->owner)->postJson('/api/v1/list-exports', $request)->assertStatus(202)->json('data.id');
        $this->assertSame([['Kode'], ['AST-P1']], $this->xlsxRows($this->export($id)));

        // Ekspor orang lain tidak terlihat, walau satu tenant.
        $this->actingAs($viewer)->getJson('/api/v1/report-exports/'.$id)->assertNotFound();
    }

    public function test_switches_to_csv_beyond_the_sheet_limit_and_stops_at_the_csv_limit(): void
    {
        config(['reporting.list_export_max_xlsx_rows' => 2, 'reporting.list_export_max_csv_rows' => 3]);
        $this->asset('AST-C1', 'Aset satu', 1000.25, 'elektronik', null);
        $this->asset('AST-C2', 'Aset dua', 2000, 'elektronik', null);
        $this->asset('AST-C3', '=Aset tiga', 3000, 'kendaraan', null);
        $request = [
            'app_id' => 'management-aset', 'list' => 'aset',
            'columns' => [['key' => 'kode', 'header' => 'Kode'], ['key' => 'nama', 'header' => 'Nama'], ['key' => 'nilai', 'header' => 'Nilai']],
            'sort' => ['column' => 'kode', 'direction' => 'asc'],
        ];

        $export = $this->export($this->actingAs($this->owner)->postJson('/api/v1/list-exports', $request)->assertStatus(202)->json('data.id'));
        $this->assertSame('done', $export->status, (string) $export->failure_message);
        $this->assertSame('csv', $export->format);
        $this->assertSame('text/csv', $export->file_mime);
        $csv = trim(ltrim((string) Storage::disk('reporting-test')->get($export->file_path), "\xEF\xBB\xBF"));
        $this->assertSame("Kode,Nama,Nilai\nAST-C1,\"Aset satu\",1000.25\nAST-C2,\"Aset dua\",2000\nAST-C3,\"'=Aset tiga\",3000", $csv);

        $this->asset('AST-C4', 'Aset empat', 4000, 'kendaraan', null);
        $failed = $this->export($this->actingAs($this->owner)->postJson('/api/v1/list-exports', $request)->assertStatus(202)->json('data.id'));
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('Daftar terlalu besar untuk satu ekspor (4 baris; batas 3)', (string) $failed->failure_message);
    }

    /**
     * Lima puluh ribu aset diekspor utuh, dan memori puncaknya hampir sama dengan ekspor sepersepuluhnya: baris
     * dibaca module per seribu dan ditulis langsung ke berkas, tidak dikumpulkan di memori.
     */
    #[Group('lambat')]
    public function test_large_export_writes_every_row_with_flat_memory(): void
    {
        $this->bulkAssets(0, 5000);
        $request = ['app_id' => 'management-aset', 'list' => 'aset', 'columns' => [
            ['key' => 'kode', 'header' => 'Kode'], ['key' => 'nama', 'header' => 'Nama'], ['key' => 'group', 'header' => 'Group'],
            ['key' => 'lokasi', 'header' => 'Lokasi'], ['key' => 'nilai', 'header' => 'Nilai'], ['key' => 'status', 'header' => 'Status'],
        ], 'sort' => ['column' => 'kode', 'direction' => 'asc']];
        [$small, $smallPeak] = $this->measuredExport($request);
        $this->assertSame(5000, (int) $small->row_count);

        $this->bulkAssets(5000, 50000);
        [$large, $largePeak] = $this->measuredExport($request);
        $this->assertSame('done', $large->status, (string) $large->failure_message);
        $this->assertSame(50000, (int) $large->row_count);
        $this->assertSame(50001, $this->countXlsxRows($large), 'Satu judul dan setiap aset yang cocok, bukan hanya yang dimuat layar.');

        fwrite(STDERR, sprintf("\nEkspor daftar: puncak memori 5.000 baris %.1f MB, 50.000 baris %.1f MB.\n", $smallPeak / 1048576, $largePeak / 1048576));
        $this->assertLessThan(
            16 * 1048576,
            $largePeak - $smallPeak,
            'Sepuluh kali baris tidak boleh membuat memori puncak ekspor tumbuh berarti; pertumbuhan berarti baris dikumpulkan di memori.',
        );
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{stdClass, int}
     */
    private function measuredExport(array $request): array
    {
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $id = $this->actingAs($this->owner)->postJson('/api/v1/list-exports', $request)->assertStatus(202)->json('data.id');
        $peak = memory_get_peak_usage() - $before;

        return [$this->export($id), $peak];
    }

    private function bulkAssets(int $from, int $to): void
    {
        for ($start = $from; $start < $to; $start += 1000) {
            $rows = [];
            for ($i = $start; $i < min($start + 1000, $to); $i++) {
                $rows[] = [
                    'id' => (string) Str::ulid(), 'tenant_id' => $this->membership->tenant_id, 'creation_key' => 'bulk-'.$i,
                    'kode' => sprintf('AST-%06d', $i), 'nama' => 'Aset massal '.$i, 'legal_entity_id' => $this->legalEntityId,
                    'responsible_org_unit_id' => $this->orgUnitId, 'group_aset_id' => $this->masters['elektronik'],
                    'jenis_aset_id' => $this->masters['laptop'], 'lokasi_aset_id' => $this->masters['gudang'],
                    'acquired_on' => '2026-01-01', 'acquisition_value' => 1000000 + $i, 'currency_code' => 'IDR',
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('aset_tr_aset')->insert($rows);
        }
    }

    private function asset(string $kode, string $nama, float|int $nilai, string $group, ?string $serial, string $status = 'received'): void
    {
        DB::table('aset_tr_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->membership->tenant_id, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $kode, 'nama' => $nama, 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
            'group_aset_id' => $this->masters[$group], 'jenis_aset_id' => $this->masters['laptop'], 'lokasi_aset_id' => $this->masters['gudang'],
            'serial_number' => $serial, 'acquired_on' => '2026-01-01', 'acquisition_value' => $nilai, 'currency_code' => 'IDR',
            'lifecycle_state' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function export(string $id): stdClass
    {
        $export = DB::table('report_exports')->where('id', $id)->first();
        $this->assertNotNull($export);

        return $export;
    }

    /** @return list<list<mixed>> */
    private function xlsxRows(stdClass $export): array
    {
        $sheet = $this->xlsx($export)->getActiveSheet();

        return array_map(
            static fn (array $row): array => array_map(static fn (mixed $value): mixed => is_object($value) ? (string) $value : $value, $row),
            $sheet->toArray(null, true, false),
        );
    }

    private function xlsx(stdClass $export): Spreadsheet
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'daftar-');
        file_put_contents($path, Storage::disk('reporting-test')->get($export->file_path));
        $book = IOFactory::load($path);
        @unlink($path);

        return $book;
    }

    /** Menghitung baris berkas besar tanpa memuatnya utuh ke memori. */
    private function countXlsxRows(stdClass $export): int
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'daftar-');
        file_put_contents($path, Storage::disk('reporting-test')->get($export->file_path));
        $reader = new XlsxReader;
        $reader->open($path);
        $count = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $count++;
            }
            break;
        }
        $reader->close();
        @unlink($path);

        return $count;
    }

    /** @param list<string> $duties */
    private function member(array $duties): User
    {
        $user = User::factory()->create();
        $membership = TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $user->id, 'status' => 'active',
        ]);
        $this->grantDuties($membership, $duties);

        return $user;
    }

    private function legalEntity(): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $this->membership->tenant_id, 'name' => 'CV Daftar',
            'classification' => 'legal_entity', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('legal_entities')->insert([
            'organization_id' => $id, 'company_code' => 'CVDF', 'country_code' => 'ID',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $extra */
    private function master(string $table, string $nama, string $kode, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $this->membership->tenant_id, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $kode, 'nama' => $nama, 'aktif' => true, ...$extra, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
