<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetValidator;
use App\Platform\Analytics\Datasets\InvalidDatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Models\ModuleInstallation;
use App\Platform\Modules\Support\TenantScope;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Apperp\ContohA\Analytics\PenjualanDataset;
use Modules\Apperp\ContohA\Analytics\SourceQuery;
use Modules\Apperp\ContohA\Models\Barang;
use Modules\Apperp\ContohA\Models\Penjualan;
use Modules\Apperp\ContohB\Models\Rak;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Setiap aturan `DatasetValidator` (`docs/todo/analitik/model-semantik.md` bagian *Yang diperiksa
 * DatasetValidator*) beserta definisi rusak yang ditolaknya, ditambah bentuk dataset sah sesudah dibaca
 * terhadap database: kolom berkualifikasi, klasifikasi, tipe kolom waktu, rujukan, dimensi bersama, join,
 * dan sidik jari definisi.
 *
 * Bahannya module contoh `contoh-a` (`tests/Fixtures/modules`), bukan module aset, supaya test engine
 * tidak berubah setiap kali module produk berubah.
 */
class DatasetValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tabel module contoh dibuat test-nya sendiri, seperti penjaga batas lain (`TenantScopeBoundaryTest`).
        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 3).'/Fixtures/modules/apperp/contoh-a/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    public function test_the_fixture_dataset_compiles_into_qualified_classified_and_labelled_fields(): void
    {
        $dataset = $this->compile('contoh-a', (new PenjualanDataset)->definition());

        $this->assertSame('contoh_a_tr_penjualan', $dataset->table);
        $this->assertFalse($dataset->isQuerySource());
        $this->assertSame('Satu baris per penjualan barang.', $dataset->description);
        $this->assertSame([
            'barang_id', 'org_unit_id', 'status', 'nilai', 'currency_code', 'tanggal', 'dicatat_pada', 'dibayar_pada',
            'nama_pembeli', 'created_at', 'updated_at', 'barang_bawaan', 'dicatat_oleh_user_id', 'legal_entity_id',
        ], array_keys($dataset->fields()), 'Katalog model lebih dulu (tanpa kolom tersembunyi), lalu field yang dinyatakan, lalu kolom dimensi bersama yang tersembunyi.');

        // Kolom tersembunyi yang dinyatakan ulang sebagai dimensi bersama mendapat nama tampilan dimensinya.
        $legalEntity = $dataset->filterField('legal_entity_id');
        $this->assertSame(['Entitas legal', FieldType::Reference, 'contoh_a_tr_penjualan.legal_entity_id'], [$legalEntity->caption, $legalEntity->type, $legalEntity->column]);
        $this->assertSame(SharedDimension::LegalEntity, $dataset->sharedDimension('legal_entity_id'));
        $this->assertSame(SharedDimension::User, $dataset->sharedDimension('dicatat_oleh_user_id'));
        $this->assertSame('Dicatat oleh', $dataset->filterField('dicatat_oleh_user_id')->caption);
        $this->assertNull($dataset->sharedDimension('status'));
        $this->assertSame('barang.bawaan', $dataset->filterField('barang_bawaan')->column);
        $this->assertSame(FieldType::Option, $dataset->filterField('status')->type);
        $this->assertSame('reference-data/unit-kerja', $dataset->filterField('org_unit_id')->lookup);

        $this->assertSame(DataClass::EndUserIdentifiableInformation, $dataset->classification('nama_pembeli'));
        $this->assertSame(DataClass::EndUserPseudonymousIdentifiers, $dataset->classification('dicatat_oleh_user_id'));
        $this->assertSame(DataClass::CustomerContent, $dataset->classification('nilai'));
        $this->assertSame(DataClass::CustomerContent, $dataset->classification('barang_bawaan'));

        $this->assertSame(['tanggal', 'dicatat_pada', 'dibayar_pada'], $dataset->times());
        $this->assertSame(['legal_entity_unit' => ['legal_entity_id', 'org_unit_id']], $dataset->hierarchies());
        $this->assertSame('tanggal', $dataset->defaultTime());
        $this->assertSame(['date', 'timestamp', 'timestamptz'], array_map($dataset->timeType(...), $dataset->times()));
        $this->assertSame('numeric', $dataset->columnType('nilai'));

        $reference = $dataset->reference('barang_id');
        $this->assertNotNull($reference);
        $this->assertSame(['contoh_a_m_barang', 'r0', 'contoh_a_tr_penjualan.barang_id', 'r0.id'], [$reference->table, $reference->alias, $reference->localColumn, $reference->foreignColumn]);
        $this->assertSame(['label' => 'r0.nama', 'code' => 'r0.kode'], $dataset->labelColumnsFor('barang_id'));
        $this->assertSame([], $dataset->labelColumnsFor('status'));

        $join = $dataset->joins()['barang'];
        $this->assertSame(['contoh_a_m_barang', 'contoh_a_tr_penjualan.barang_id', 'barang.id', false, true], [$join->table, $join->localColumn, $join->foreignColumn, $join->includeArchived, $join->archivable]);

        $this->assertSame('barang.bawaan', $dataset->qualified('barang.bawaan'));
        $this->assertSame('contoh_a_tr_penjualan.legal_entity_id', $dataset->qualified('legal_entity_id'));
        $this->assertSame('contoh_a_tr_penjualan.keterangan', $dataset->qualified('contoh_a_tr_penjualan.keterangan'));
        $this->assertSame(['status' => ['terbit']], $dataset->measure('terbit')->where);
        $this->assertSame(['contoh-a.penjualan-unit', 'legal_entity_id', 'org_unit_id'], [$dataset->policy['code'] ?? null, $dataset->policy['legal_entity'] ?? null, $dataset->policy['operating_unit'] ?? null]);

        try {
            $dataset->qualified('lain.kolom');
            $this->fail('Kolom dari alias yang tidak dinyatakan tidak boleh dipulangkan.');
        } catch (LogicException) {
            // Alias yang tidak dinyatakan dataset bukan kolom yang sah.
        }
    }

    public function test_a_hierarchy_requires_two_existing_dataset_fields(): void
    {
        try {
            $this->compile('contoh-a', (new PenjualanDataset)->definition()->hierarchy('broken', ['legal_entity_id', 'not_a_field']));
            $this->fail('Field yang tidak dinyatakan dataset tidak boleh masuk ke hierarki.');
        } catch (InvalidDatasetDefinition $exception) {
            $this->assertStringContainsString('bukan field dataset', $exception->getMessage());
        }

        try {
            $this->compile('contoh-a', (new PenjualanDataset)->definition()->hierarchy('broken', ['org_unit_id']));
            $this->fail('Hierarki harus memiliki sedikitnya dua tingkat.');
        } catch (InvalidDatasetDefinition $exception) {
            $this->assertStringContainsString('sedikitnya dua field', $exception->getMessage());
        }
    }

    public function test_the_definition_hash_is_stable_and_follows_the_definition(): void
    {
        $first = $this->compile('contoh-a', (new PenjualanDataset)->definition());
        $again = $this->compile('contoh-a', (new PenjualanDataset)->definition());
        $renamed = $this->compile('contoh-a', (new PenjualanDataset)->definition()->measure('count', 'Banyaknya penjualan', Aggregate::Count));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->hash());
        $this->assertSame($first->hash(), $again->hash());
        $this->assertNotSame($first->hash(), $renamed->hash(), 'Nama tampilan ikut tersimpan di hasil, jadi mengubahnya harus mengubah kunci cache.');
    }

    public function test_a_query_source_dataset_reads_its_columns_and_types_from_the_source(): void
    {
        $dataset = $this->compile('contoh-a', DatasetDefinition::make('contoh-a.barang-per-status', 'Barang per status')
            ->fromQuery(static fn () => SourceQuery::from(Barang::class)->select(['tenant_id', 'bawaan'])->selectRaw('count(*) as jumlah')->groupBy('tenant_id', 'bawaan'))
            ->permission('contoh-a.barang.read')
            ->field('bawaan', 'Barang bawaan', FieldType::Boolean, classification: DataClass::CustomerContent)
            ->measure('jumlah', 'Jumlah barang', Aggregate::Sum, field: 'jumlah'));

        $this->assertTrue($dataset->isQuerySource());
        $this->assertSame(CompiledDataset::SOURCE_ALIAS, $dataset->table);
        $this->assertSame('base.bawaan', $dataset->filterField('bawaan')->column);
        $this->assertSame('base.jumlah', $dataset->qualified('jumlah'));
        $this->assertSame('bool', $dataset->columnType('bawaan'));
        $this->assertSame(Barang::class, $dataset->model);
    }

    /**
     * @param  Closure(): DatasetDefinition  $definition
     */
    #[DataProvider('brokenDefinitions')]
    public function test_a_broken_definition_is_rejected_with_its_reason(Closure $definition, string $reason, string $module = 'contoh-a'): void
    {
        try {
            $this->compile($module, $definition());
        } catch (InvalidDatasetDefinition $e) {
            $this->assertStringContainsString($reason, $e->getMessage());

            return;
        }

        $this->fail("Definisi rusak lolos validator; yang diharapkan ditolak: {$reason}");
    }

    /** @return array<string, array{0: Closure(): DatasetDefinition, 1: string, 2?: string}> */
    public static function brokenDefinitions(): array
    {
        $sale = static fn (string $code = 'contoh-a.penjualan'): DatasetDefinition => DatasetDefinition::make($code, 'Penjualan')
            ->model(Penjualan::class)
            ->permission('contoh-a.penjualan.read')
            ->dataPolicy('contoh-a.penjualan-unit', legalEntity: 'legal_entity_id', operatingUnit: 'org_unit_id')
            ->fieldsFromModel()
            ->measure('count', 'Jumlah', Aggregate::Count);
        $item = static fn (): DatasetDefinition => DatasetDefinition::make('contoh-a.barang', 'Barang')
            ->model(Barang::class)
            ->permission('contoh-a.barang.read')
            ->measure('count', 'Jumlah', Aggregate::Count);
        $source = static fn (Closure $query): DatasetDefinition => DatasetDefinition::make('contoh-a.sumber', 'Sumber')
            ->fromQuery($query)
            ->permission('contoh-a.barang.read')
            ->measure('count', 'Jumlah', Aggregate::Count);

        return [
            // Kode dan sumber
            'kode tanpa awalan module' => [static fn () => $sale('contoh-b.penjualan'), 'berawalan id module'],
            'kode berhuruf besar' => [static fn () => $sale('contoh-a.Penjualan'), 'berawalan id module'],
            'tanpa sumber' => [static fn () => DatasetDefinition::make('contoh-a.kosong', 'Kosong')->permission('contoh-a.barang.read')->measure('count', 'Jumlah', Aggregate::Count), 'tepat satu sumber'],
            'dua sumber' => [static fn () => $item()->fromQuery(static fn () => SourceQuery::from(Barang::class)), 'tepat satu sumber'],
            'model tanpa BelongsToTenant' => [static fn () => $item()->model(ModuleInstallation::class), 'BelongsToTenant'],
            'module tidak dikenal' => [static fn () => DatasetDefinition::make('tidak-ada.barang', 'Barang')->model(Barang::class)->permission('tidak-ada.barang.read')->measure('count', 'Jumlah', Aggregate::Count), 'tidak dikenal', 'tidak-ada'],

            // Namespace module sendiri
            'model module lain' => [static fn () => $item()->model(Rak::class), 'bukan milik module contoh-a'],
            'join ke module lain' => [static fn () => $sale()->join('rak', Rak::class, localColumn: 'barang_id'), 'model join rak'],
            'rujukan ke module lain' => [static fn () => $sale()->reference('barang_id', Rak::class), 'model rujukan barang_id'],

            // Permission dan kebijakan dari manifest module
            'permission tidak ada' => [static fn () => $item()->permission('contoh-a.barang.export'), 'tidak ada di manifest'],
            'permission bukan baca' => [static fn () => $sale()->permission('contoh-a.penjualan.create'), 'access read'],
            'resource berkebijakan tanpa dataPolicy' => [static fn () => DatasetDefinition::make('contoh-a.penjualan', 'Penjualan')->model(Penjualan::class)->permission('contoh-a.penjualan.read')->measure('count', 'Jumlah', Aggregate::Count), 'dibatasi kebijakan contoh-a.penjualan-unit'],
            'kebijakan tidak ada' => [static fn () => $sale()->dataPolicy('contoh-a.tidak-ada', 'legal_entity_id', 'org_unit_id'), 'kebijakan data contoh-a.tidak-ada tidak ada'],
            'kolom kebijakan tidak ada' => [static fn () => $sale()->dataPolicy('contoh-a.penjualan-unit', 'legal_entity_id', 'unit_kerja_id'), 'kolom unit_kerja_id tidak ada di tabel contoh_a_tr_penjualan'],
            'kolom kebijakan lewat alias tak dikenal' => [static fn () => $sale()->dataPolicy('contoh-a.penjualan-unit', 'aset.legal_entity_id'), 'aset bukan alias join'],

            // Kolom ada di tabelnya
            'kolom field tidak ada' => [static fn () => $sale()->field('pembeli', 'Pembeli', FieldType::Text, column: 'pembeli'), 'kolom pembeli tidak ada di tabel contoh_a_tr_penjualan'],
            'kolom join tidak ada' => [static fn () => $sale()->join('barang', Barang::class, localColumn: 'barang_id')->field('warna', 'Warna', FieldType::Text, column: 'barang.warna'), 'kolom warna tidak ada di tabel contoh_a_m_barang'],
            'kolom lokal join tidak ada' => [static fn () => $sale()->join('barang', Barang::class, localColumn: 'produk_id'), 'kolom produk_id tidak ada'],
            'kolom measure tidak ada' => [static fn () => $sale()->measure('diskon', 'Diskon', Aggregate::Sum, field: 'diskon'), 'kolom diskon tidak ada'],
            'kolom mata uang tidak ada' => [static fn () => $sale()->measure('nilai', 'Nilai', Aggregate::Sum, field: 'nilai', format: MeasureFormat::Money, currency: 'mata_uang'), 'kolom mata_uang tidak ada'],
            'kolom fieldsFromModel tidak ada' => [static fn () => $sale()->fieldsFromModel(except: ['catatan']), 'kolom catatan di fieldsFromModel() tidak ada'],
            'kolom tersembunyi di only' => [static fn () => $sale()->fieldsFromModel(only: ['keterangan']), 'tidak ada di katalog field model'],
            'kolom label rujukan tidak ada' => [static fn () => $sale()->reference('barang_id', Barang::class, label: 'judul'), 'kolom judul tidak ada di tabel contoh_a_m_barang'],

            // Klasifikasi
            'field AccountData' => [static fn () => $sale()->field('kode_rahasia', 'Kode rahasia', FieldType::Text, column: 'keterangan', classification: DataClass::AccountData), 'AccountData'],
            'field belum diklasifikasi' => [static fn () => $sale()->field('catatan', 'Catatan', FieldType::Text, column: 'keterangan', classification: DataClass::ToBeClassified), 'belum diklasifikasi'],
            'field sumber query tanpa klasifikasi' => [static fn () => $source(static fn () => Barang::query()->select(['tenant_id', 'bawaan']))->field('bawaan', 'Bawaan', FieldType::Boolean), 'belum diklasifikasi'],

            // Measure
            'tanpa measure' => [static fn () => DatasetDefinition::make('contoh-a.barang', 'Barang')->model(Barang::class)->permission('contoh-a.barang.read'), 'sedikitnya satu measure'],
            'uang tanpa mata uang' => [static fn () => $sale()->measure('nilai', 'Nilai', Aggregate::Sum, field: 'nilai', format: MeasureFormat::Money), 'kolom mata uang'],
            'kuantitas tanpa satuan' => [static fn () => $sale()->measure('banyak', 'Banyak', Aggregate::Sum, field: 'nilai', format: MeasureFormat::Quantity), 'kolom satuan'],
            'agregat tanpa kolom' => [static fn () => $sale()->measure('total', 'Total', Aggregate::Sum), 'butuh kolom yang dihitung'],
            'jumlah atas kolom teks' => [static fn () => $sale()->measure('total', 'Total', Aggregate::Sum, field: 'status'), 'kolom status bukan angka'],
            'rata-rata atas kolom tanggal' => [static fn () => $sale()->measure('rata', 'Rata', Aggregate::Average, field: 'tanggal'), 'kolom tanggal bukan angka'],
            'saringan tetap pada field angka' => [static fn () => $sale()->measure('besar', 'Besar', Aggregate::Count, where: ['nilai' => ['100']]), 'hanya boleh pada field pilihan'],
            'saringan tetap pada field tak dikenal' => [static fn () => $sale()->measure('terbit', 'Terbit', Aggregate::Count, where: ['keadaan' => ['terbit']]), 'bukan field dataset'],
            'saringan tetap dengan pilihan tak dikenal' => [static fn () => $sale()->measure('hilang', 'Hilang', Aggregate::Count, where: ['status' => ['hilang']]), 'tidak sah untuk field status'],
            'saringan tetap tanpa nilai' => [static fn () => $sale()->measure('kosong', 'Kosong', Aggregate::Count, where: ['status' => []]), 'butuh satu nilai'],

            // Waktu
            'waktu pada kolom teks' => [static fn () => $sale()->time('status'), 'harus berkolom date, timestamp, atau timestamptz'],
            'waktu bukan field' => [static fn () => $sale()->time('kedaluwarsa'), 'field waktu kedaluwarsa bukan field dataset'],

            // Versi dan peta nama
            'versi nol' => [static fn () => $sale()->version(0), 'versi dataset'],
            'peta nama ke kunci yang tidak ada' => [static fn () => $sale()->version(2, ['total' => 'jumlah_total']), 'menunjuk jumlah_total'],
            'peta nama dari kunci yang masih ada' => [static fn () => $sale()->version(2, ['status' => 'count']), 'masih dipakai sebagai kunci'],

            // Rute record
            'rute record module lain' => [static fn () => $sale()->recordRoute('/contoh-b/penjualan/{id}'), 'berawalan /contoh-a/'],
            'rute record tanpa id' => [static fn () => $sale()->recordRoute('/contoh-a/penjualan'), 'memuat {id}'],

            // Bentuk kunci
            'kunci field bukan snake_case' => [static fn () => $sale()->field('NilaiBesar', 'Nilai besar', FieldType::Number, column: 'nilai'), 'snake_case'],
            'kunci measure bertanda hubung' => [static fn () => $sale()->measure('nilai-besar', 'Nilai besar', Aggregate::Count), 'snake_case'],
            'kunci terlalu panjang' => [static fn () => $sale()->measure(str_repeat('a', 65), 'Panjang', Aggregate::Count), 'snake_case'],
            'alias join milik engine' => [static fn () => $sale()->join('r0', Barang::class, localColumn: 'barang_id'), 'dipakai engine'],
            'field membayangi kolom lain' => [static fn () => $sale()->join('barang', Barang::class, localColumn: 'barang_id')->field('status', 'Status barang', FieldType::Boolean, column: 'barang.bawaan'), 'pakai kunci lain'],
            'rujukan pada field pilihan' => [static fn () => $sale()->reference('status', Barang::class), 'harus menunjuk kolom id'],
            'rujukan tanpa nama tampilan' => [static fn () => $sale()->reference('legal_entity_id', Barang::class), 'belum punya field bernama'],

            // Sumber query
            'sumber bukan query Eloquent' => [static fn () => $source(static fn () => DB::table('contoh_a_m_barang')), 'query Eloquent'],
            'sumber melepas scope tenant' => [static fn () => $source(static fn () => Barang::query()->withoutGlobalScope(TenantScope::class)), 'melepas penyaringan tenant'],
            'sumber tanpa scope sama sekali' => [static fn () => $source(static fn () => (new Barang)->newModelQuery()), 'melepas penyaringan tenant'],
            'sumber tanpa tenant_id' => [static fn () => $source(static fn () => Barang::query()->select(['kode', 'bawaan'])), 'wajib memilih kolom tenant_id'],
            'sumber dengan katalog model' => [static fn () => $source(static fn () => Barang::query())->fieldsFromModel(), 'tidak punya katalog model'],
            'sumber dengan join' => [static fn () => $source(static fn () => Barang::query())->join('lain', Barang::class, localColumn: 'id'), 'tidak memakai join'],
            'sumber tak dapat dijalankan' => [static fn () => $source(static fn () => Barang::query()->select(['tenant_id', 'warna'])), 'query sumber tidak dapat dijalankan'],
            'rujukan sumber tanpa field' => [static fn () => $source(static fn () => Barang::query()->select(['tenant_id', 'id']))->reference('id', Barang::class), 'belum punya field bernama'],
        ];
    }

    private function compile(string $module, DatasetDefinition $definition): CompiledDataset
    {
        $validator = app(DatasetValidator::class);

        return $validator->compile($validator->declare(new readonly class($module, $definition) implements Dataset
        {
            public function __construct(private string $module, private DatasetDefinition $definition) {}

            public function moduleId(): string
            {
                return $this->module;
            }

            public function definition(): DatasetDefinition
            {
                return $this->definition;
            }
        }));
    }
}
