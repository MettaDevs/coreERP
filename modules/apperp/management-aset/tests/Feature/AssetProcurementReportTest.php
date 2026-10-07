<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Reporting\Support\Rendering\XlsxTemplateRenderer;
use App\Platform\Reporting\Support\ReportData as CoreReportData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Reporting\PenyediaLaporan;
use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Laporan pengadaan aset di atas satu riwayat pengadaan yang ditulis langsung ke tabelnya:
 *
 * - PLNA-1 (10 Februari, unit Poli Umum): 10 laptop @15 juta dan 20 kursi @500 ribu;
 * - RPPA-1 (1 Maret) dan RPPA-2 (15 Maret) meminta 6 dan 2 laptop dari rencana itu; RPPA-BATAL meminta 20
 *   kursi lalu dibatalkan;
 * - PNA-1 menerima 5 laptop dari RPPA-1, PNA-2 menerima 2 laptop dari RPPA-2, PNA-DRAF (masih draf) 1 laptop;
 * - RPPA-3 (1 April, unit lain) meminta 5 kursi tanpa rencana, dan PNA-3 menerima kelimanya tanpa vendor;
 * - RPPA-YATIM (1 Mei) menunjuk baris rencana yang sudah diarsipkan, jadi terbaca sebagai permintaan tanpa
 *   rencana;
 * - tenant lain punya rencana dengan entitas dan unit yang sama.
 */
class AssetProcurementReportTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private const CODE = 'laporan-pengadaan-aset';

    private const PERMISSION = 'management-aset.permintaan-pembelian-aset.read';

    private string $tenantId;

    private string $otherTenantId;

    private string $legalEntityId;

    private string $unit;

    private string $otherUnit;

    /** @var array<string, string> */
    private array $types = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->otherTenantId = $this->buatTenantUji();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->unit = (string) Str::ulid();
        $this->otherUnit = (string) Str::ulid();
        $this->seedProcurement();
    }

    public function test_report_follows_each_need_from_plan_to_request_to_receipt(): void
    {
        $definisi = $this->provider('definition', [self::CODE, $this->context()]);
        $this->assertSame(['rencana'], array_column($definisi['data_items'], 'key'));
        foreach (['dari', 'sampai', 'jenis_aset_id', 'org_unit_id', 'status', 'filters'] as $parameter) {
            $this->assertContains($parameter, $definisi['parameters']);
        }

        $report = $this->report();
        $rows = $report['tables']['baris'];
        $this->assertRowKeysDeclared($rows[0]);
        $this->assertSame(
            [['PLNA-1', 'Laptop'], ['PLNA-1', 'Kursi'], ['—', 'Kursi'], ['—', 'Laptop']],
            array_map(static fn (array $row): array => [$row['no_rencana'], $row['item_aset']], $rows),
        );
        $this->assertSame([1, 2, 3, 4], array_column($rows, 'nomor'));

        // Laptop: 10 direncanakan, 8 diminta lewat dua permintaan, 7 diterima; penerimaan draf belum dihitung.
        $this->assertSame([
            'tanggal_rencana' => '2026-02-10', 'tahun_anggaran' => '2026', 'sumber_dana' => 'Anggaran rutin',
            'spesifikasi' => 'Core i5, RAM 16 GB', 'satuan' => 'Unit', 'jumlah_rencana' => '10', 'nilai_rencana' => '150000000.00',
            'no_permintaan' => 'RPPA-1, RPPA-2', 'tanggal_permintaan' => '2026-03-01', 'jumlah_diminta' => '8', 'belum_diminta' => '2',
            'no_penerimaan' => 'PNA-1, PNA-2', 'tanggal_penerimaan' => '2026-04-05', 'vendor' => 'PT Pemasok Uji',
            'jumlah_diterima' => '7', 'nilai_diterima' => '102000000.00', 'belum_diterima' => '1',
            'unit_organisasi' => 'Poli Umum', 'status' => 'Diterima sebagian',
        ], array_diff_key($rows[0], array_flip(['nomor', 'no_rencana', 'item_aset'])));

        // Kursi yang permintaannya dibatalkan kembali menjadi belum diminta.
        $this->assertSame(
            ['jumlah_rencana' => '20', 'no_permintaan' => '—', 'tanggal_permintaan' => null, 'jumlah_diminta' => '0', 'belum_diminta' => '20', 'jumlah_diterima' => '0', 'nilai_diterima' => '0.00', 'status' => 'Belum diminta'],
            array_intersect_key($rows[1], array_flip(['jumlah_rencana', 'no_permintaan', 'tanggal_permintaan', 'jumlah_diminta', 'belum_diminta', 'jumlah_diterima', 'nilai_diterima', 'status'])),
        );

        // Permintaan tanpa rencana: kolom rencana kosong, satuannya dibaca dari Core.
        $this->assertSame(
            ['tanggal_rencana' => null, 'satuan' => 'Buah', 'jumlah_rencana' => null, 'nilai_rencana' => null, 'no_permintaan' => 'RPPA-3', 'belum_diminta' => null, 'vendor' => '—', 'jumlah_diterima' => '5', 'nilai_diterima' => '2250000.00', 'status' => 'Diterima penuh'],
            array_intersect_key($rows[2], array_flip(['tanggal_rencana', 'satuan', 'jumlah_rencana', 'nilai_rencana', 'belum_diminta', 'no_permintaan', 'jumlah_diterima', 'nilai_diterima', 'vendor', 'status'])),
        );
        // Rencananya diarsipkan: permintaannya tetap terbaca, sebagai permintaan tanpa rencana.
        $this->assertSame(['RPPA-YATIM', '3', 'Belum diterima'], [$rows[3]['no_permintaan'], $rows[3]['belum_diterima'], $rows[3]['status']]);

        $this->assertSame(4, $report['fields']['jumlah_baris']);
        $this->assertSame('160000000.00', $report['fields']['total_nilai_rencana']);
        $this->assertSame('104250000.00', $report['fields']['total_nilai_diterima']);
        $this->assertSame('Semua', $report['fields']['filter_status']);
    }

    public function test_filters_narrow_the_needs(): void
    {
        $plans = fn (array $parameters): array => array_map(
            static fn (array $row): string => $row['no_rencana'].'/'.$row['no_permintaan'],
            $this->report($parameters)['tables']['baris'],
        );

        $penuh = $this->report(['status' => ['diterima_penuh']]);
        $this->assertSame(['—/RPPA-3'], $plans(['status' => ['diterima_penuh']]));
        $this->assertSame(1, $penuh['tables']['baris'][0]['nomor']);
        $this->assertSame('Diterima penuh', $penuh['fields']['filter_status']);
        $this->assertSame('0.00', $penuh['fields']['total_nilai_rencana']);
        $this->assertSame(['PLNA-1/RPPA-1, RPPA-2', 'PLNA-1/—'], $plans(['status' => ['belum_diminta', 'diterima_sebagian']]));

        // Periode membaca tanggal dokumen awal: rencana, atau permintaan bila tanpa rencana.
        $this->assertSame(['—/RPPA-3', '—/RPPA-YATIM'], $plans(['dari' => '2026-03-01']));
        $this->assertSame(['PLNA-1/RPPA-1, RPPA-2', 'PLNA-1/—'], $plans(['sampai' => '2026-02-28']));

        $this->assertSame(['PLNA-1/—', '—/RPPA-3'], $plans(['jenis_aset_id' => [$this->types['kursi']]]));
        $this->assertSame('Kursi', $this->report(['jenis_aset_id' => [$this->types['kursi']]])['fields']['filter_jenis']);
        $this->assertSame(['—/RPPA-3'], $plans(['org_unit_id' => [$this->otherUnit]]));

        // Filter tambahan pada rencana: permintaan tanpa rencana tidak ikut.
        $tambahan = $this->report(['filters' => ['rencana' => ['kode' => 'PLNA-1']]]);
        $this->assertSame(2, $tambahan['fields']['jumlah_baris']);
        $this->assertSame('Rencana pengadaan — Nomor rencana: PLNA-1', $tambahan['fields']['filter_tambahan']);

        $this->assertGagal('Parameter laporan tidak diterima', fn () => $this->report(['status' => ['hilang']]));
    }

    public function test_report_needs_the_request_permission_and_respects_scope_and_tenant(): void
    {
        $this->assertSame(self::PERMISSION, app(ReportRegistry::class)->get(self::CODE)->permission());
        $this->assertGagal('Anda tidak berhak membaca data laporan ini.', fn () => $this->report([], $this->context(['management-aset.perencanaan-aset.read'])));

        // Hanya unit Poli Umum: permintaan unit lain dan penerimaannya tidak dibaca.
        $unitOnly = $this->context(scope: ['all' => false, 'scope_grants' => [['legal_entity_id' => $this->legalEntityId, 'operating_unit_ids' => [$this->unit]]]]);
        $rows = $this->report([], $unitOnly)['tables']['baris'];
        $this->assertSame(['RPPA-1, RPPA-2', '—', 'RPPA-YATIM'], array_column($rows, 'no_permintaan'));
        $outside = $this->context(scope: ['all' => false, 'scope_grants' => [['legal_entity_id' => $this->legalEntityId, 'operating_unit_ids' => [(string) Str::ulid()]]]]);
        $this->assertSame([], $this->report([], $outside)['tables']['baris']);

        // Rencana tenant lain berentitas dan berunit sama tidak pernah terbaca.
        $this->assertNotContains('PLNA-LAIN', array_column($this->report()['tables']['baris'], 'no_rencana'));
    }

    public function test_screen_preview_and_builtin_excel_read_the_same_dataset(): void
    {
        $dataset = $this->report(['status' => ['diterima_sebagian', 'diterima_penuh']]);

        $preview = $this->sebagaiPengguna($this->tenantId, [self::PERMISSION])
            ->getJson('/api/modules/management-aset/v1/laporan/'.self::CODE.'?status[]=diterima_sebagian&status[]=diterima_penuh')
            ->assertOk()
            ->assertJsonCount(2, 'data.tables.baris');
        foreach (['no_rencana', 'no_permintaan', 'no_penerimaan', 'status'] as $column) {
            $this->assertSame(array_column($dataset['tables']['baris'], $column), array_column($preview->json('data.tables.baris'), $column), $column);
        }

        $template = tempnam(sys_get_temp_dir(), 'tpl-pengadaan-').'.xlsx';
        file_put_contents($template, $this->provider('defaultLayout', [self::CODE, 'standar', $this->context()]));
        try {
            $file = (new XlsxTemplateRenderer)->render($template, CoreReportData::fromArray($dataset));
        } finally {
            @unlink($template);
        }
        $sheet = IOFactory::load($file->localPath)->getActiveSheet();
        @copy($file->localPath, sys_get_temp_dir().'/laporan-pengadaan-aset-contoh.xlsx');
        $file->cleanup();

        $this->assertSame('Pengadaan aset', $sheet->getTitle());
        $this->assertSame(['No.', 'No. rencana', 'Tanggal rencana', 'Tahun anggaran', 'Sumber dana', 'Item aset'], array_slice($sheet->rangeToArray('A10:V10')[0], 0, 6));
        $this->assertSame('PLNA-1', (string) $sheet->getCell('B11')->getValue());
        $this->assertSame('RPPA-3', (string) $sheet->getCell('K12')->getValue());
        $this->assertSame('Diterima sebagian, Diterima penuh', (string) $sheet->getCell('F7')->getValue());
        $this->assertSame('Total', (string) $sheet->getCell('A14')->getValue());
        $this->assertEqualsWithDelta(104250000, (float) $sheet->getCell('S14')->getValue(), 0.001);
        foreach (['A11', 'V12', 'J14'] as $cell) {
            $this->assertStringNotContainsString('${', (string) $sheet->getCell($cell)->getValue(), $cell);
        }
    }

    private function seedProcurement(): void
    {
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $this->pastikanOrganisasiAda($this->tenantId, $this->unit, 'operating_unit');
        DB::table('organizations')->where('id', $this->unit)->update(['name' => 'Poli Umum']);
        $vendor = $this->pastikanVendorUji($this->tenantId, $this->legalEntityId);
        $buah = $this->buatSatuanUji($this->tenantId, 'BH', 'Buah');

        $this->types['laptop'] = $this->master($this->tenantId, 'aset_m_jenis_aset', 'Laptop', 'JNS-LAP');
        $this->types['kursi'] = $this->master($this->tenantId, 'aset_m_jenis_aset', 'Kursi', 'JNS-KRS');
        $group = $this->master($this->tenantId, 'aset_m_group_aset', 'Inventaris kantor', 'GRP-INV');

        $plan = $this->plan($this->tenantId, 'PLNA-1', '2026-02-10', $this->unit);
        $laptop = $this->planLine($this->tenantId, $plan, 1, $this->types['laptop'], 'Laptop', 10, 15000000, 'Core i5, RAM 16 GB');
        $kursi = $this->planLine($this->tenantId, $plan, 2, $this->types['kursi'], 'Kursi', 20, 500000, 'Kursi kerja beroda');
        $archived = $this->plan($this->tenantId, 'PLNA-ARSIP', '2026-01-05', $this->unit, archived: true);
        $archivedLine = $this->planLine($this->tenantId, $archived, 1, $this->types['laptop'], 'Laptop', 3, 15000000, 'Cadangan');

        $first = $this->requestLine($this->request('RPPA-1', '2026-03-01', $this->unit), $laptop, $this->types['laptop'], 'Laptop', 6, $buah);
        $second = $this->requestLine($this->request('RPPA-2', '2026-03-15', $this->unit), $laptop, $this->types['laptop'], 'Laptop', 2, $buah);
        $this->requestLine($this->request('RPPA-BATAL', '2026-03-02', $this->unit, 'cancelled'), $kursi, $this->types['kursi'], 'Kursi', 20, $buah);
        $unplanned = $this->requestLine($this->request('RPPA-3', '2026-04-01', $this->otherUnit), null, $this->types['kursi'], 'Kursi', 5, $buah);
        $this->requestLine($this->request('RPPA-YATIM', '2026-05-01', $this->unit), $archivedLine, $this->types['laptop'], 'Laptop', 3, $buah);

        $this->receipt('PNA-1', '2026-03-20', $this->unit, $vendor, 'selesai', [[$first, 5, '14500000']], $group);
        $this->receipt('PNA-2', '2026-04-05', $this->unit, $vendor, 'selesai', [[$second, 2, '14750000']], $group);
        $this->receipt('PNA-DRAF', '2026-04-06', $this->unit, $vendor, 'draft', [[$first, 1, '14500000']], $group);
        $this->receipt('PNA-3', '2026-04-10', $this->otherUnit, null, 'selesai', [[$unplanned, 5, '450000']], $group);

        // Tenant lain, entitas dan unit yang sama.
        $otherType = $this->master($this->otherTenantId, 'aset_m_jenis_aset', 'Laptop', 'JNS-LAP');
        $otherPlan = $this->plan($this->otherTenantId, 'PLNA-LAIN', '2026-02-11', $this->unit);
        $this->planLine($this->otherTenantId, $otherPlan, 1, $otherType, 'Laptop', 1, 1000000, 'Milik tenant lain');
    }

    private function plan(string $tenant, string $kode, string $date, string $unit, bool $archived = false): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_perencanaan_aset')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode,
            'legal_entity_id' => $this->legalEntityId, 'planning_org_unit_id' => $unit, 'planned_on' => $date,
            'planning_year' => (int) substr($date, 0, 4), 'planning_type' => 'regular', 'funding_source' => 'Anggaran rutin',
            'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $archived ? now() : null,
        ]);

        return $id;
    }

    private function planLine(string $tenant, string $plan, int $line, string $type, string $name, int $quantity, int $price, string $specification): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_perencanaan_aset_details')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'planning_id' => $plan, 'line_number' => $line, 'jenis_aset_id' => $type,
            'nama_aset' => $name, 'unit' => 'Unit', 'quantity' => $quantity, 'requested_specification' => $specification,
            'estimated_unit_price' => $price, 'estimated_total_price' => $price * $quantity, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function request(string $kode, string $date, string $unit, string $status = 'draft'): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_permintaan_pengadaan_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode,
            'legal_entity_id' => $this->legalEntityId, 'requesting_org_unit_id' => $unit, 'requester_user_id' => 'peminta',
            'requested_on' => $date, 'status' => $status, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function requestLine(string $request, ?string $planLine, string $type, string $name, int $quantity, string $unit): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_permintaan_pengadaan_aset_details')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'request_id' => $request, 'line_number' => 1, 'planning_detail_id' => $planLine,
            'jenis_aset_id' => $type, 'satuan_id' => $unit, 'nama_aset' => $name, 'quantity' => $quantity,
            'specification' => 'Sesuai rencana', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param list<array{0: string, 1: int, 2: string}> $lines Baris permintaan, jumlah, dan nilai per unit. */
    private function receipt(string $kode, string $date, string $unit, ?string $vendor, string $status, array $lines, string $group): void
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_penerimaan_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode,
            'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $unit, 'tanggal' => $date,
            'currency_code' => 'IDR', 'status' => $status, 'vendor_id' => $vendor, 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as $index => [$requestLine, $quantity, $price]) {
            DB::table('aset_tr_penerimaan_aset_details')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'penerimaan_aset_id' => $id, 'line_number' => $index + 1,
                'nama' => 'Barang '.$kode, 'group_aset_id' => $group,
                'jenis_aset_id' => DB::table('aset_tr_permintaan_pengadaan_aset_details')->where('id', $requestLine)->value('jenis_aset_id'),
                'jumlah' => $quantity, 'nilai_per_unit' => $price, 'permintaan_pembelian_detail_id' => $requestLine,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function master(string $tenant, string $table, string $nama, string $kode): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $tenant, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode, 'nama' => $nama,
            'aktif' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  list<string>|null  $permissions
     * @param  array<string, mixed>|null  $scope
     * @return array<string, mixed>
     */
    private function context(?array $permissions = null, ?array $scope = null): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => $this->legalEntityId,
            'org_unit_id' => $this->unit,
            'user_id' => (string) Str::ulid(),
            'permissions' => $permissions ?? [self::PERMISSION],
            'data_policies' => ['management-aset.asset-responsibility' => $scope ?? ['all' => true, 'scope_grants' => []]],
            'timezone' => 'UTC',
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>|null  $context
     * @return array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}
     */
    private function report(array $parameters = [], ?array $context = null): array
    {
        return $this->provider('dataset', [self::CODE, $context ?? $this->context(), $parameters]);
    }

    /**
     * Penyedia laporan dengan tenant aktif terikat, seperti yang dikerjakan Core sebelum memanggil module.
     *
     * @param  list<mixed>  $arguments
     */
    private function provider(string $method, array $arguments): mixed
    {
        return app(TenantRunner::class)->runFor($this->tenantId, fn (): mixed => app(PenyediaLaporan::class)->{$method}(...$arguments));
    }

    /** @param array<string, mixed> $row */
    private function assertRowKeysDeclared(array $row): void
    {
        $declared = [];
        foreach (app(ReportRegistry::class)->get(self::CODE)->fields() as $field) {
            if ($field['table'] === 'baris') {
                $declared[] = substr($field['key'], strlen('baris.'));
            }
        }
        $this->assertEqualsCanonicalizing($declared, array_keys($row), 'Kolom baris tidak sama dengan fields().');
    }

    /** @param callable(): mixed $action */
    private function assertGagal(string $fragment, callable $action): void
    {
        try {
            $action();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());

            return;
        }
        $this->fail('Panggilan yang seharusnya ditolak justru berhasil.');
    }
}
