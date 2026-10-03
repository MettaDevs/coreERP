<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\CompiledMeasure;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\FieldUseGate;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\PersonalDataGate;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\FilterField;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Validator query analitik (area 2) tanpa database: dataset tiruan di memori, principal tiruan, dan
 * setiap aturan beserta path galatnya. Aplikasinya hidup hanya agar `config()` dapat dibaca.
 *
 * Dataset tiruan mewakili bentuk yang dipakai module: field pilihan, rujukan, teks, angka, tanggal, dan
 * tanggal-jam; dua field waktu (satu bawaan); measure hitung dan uang bermata uang.
 */
class QueryValidatorTest extends TestCase
{
    private function dataset(?string $defaultTime = 'acquired_on'): CompiledDataset
    {
        $fields = [];
        foreach ([
            ['lifecycle_state', 'Status', FieldType::Option],
            ['group_id', 'Group', FieldType::Reference],
            ['name', 'Nama', FieldType::Text],
            ['value', 'Nilai', FieldType::Number],
            ['currency_code', 'Mata uang', FieldType::Text],
            ['acquired_on', 'Tanggal perolehan', FieldType::Date],
            ['created_at', 'Dibuat pada', FieldType::DateTime],
        ] as [$key, $caption, $type]) {
            $fields[$key] = new FilterField($key, $caption, $type, 'contoh.'.$key);
        }

        return new CompiledDataset(
            code: 'modul.dataset-contoh',
            caption: 'Dataset contoh',
            moduleId: 'modul',
            version: 1,
            model: Model::class,
            table: 'contoh',
            permission: 'modul.contoh.read',
            policy: null,
            fields: $fields,
            measures: [
                'count' => new CompiledMeasure('count', 'Jumlah', Aggregate::Count, null, MeasureFormat::Number, null, null, []),
                'total' => new CompiledMeasure('total', 'Total nilai', Aggregate::Sum, 'value', MeasureFormat::Money, 'currency_code', null, []),
            ],
            times: ['acquired_on', 'created_at'],
            defaultTime: $defaultTime,
            classifications: array_map(static fn (): DataClass => DataClass::CustomerContent, $fields),
        );
    }

    private function principal(int $rowLimit = 5000): AnalyticsPrincipal
    {
        return new class($rowLimit) implements AnalyticsPrincipal
        {
            public function __construct(private readonly int $rowLimit) {}

            public function tenantId(): string
            {
                return 'tenant-uji';
            }

            public function holdsPermission(string $moduleId, string $permission): bool
            {
                return true;
            }

            public function policyScope(string $policyCode): array
            {
                return ['all' => true, 'scope_grants' => []];
            }

            public function mayUsePersonalData(): bool
            {
                return false;
            }

            public function lockedFilters(string $dataset): array
            {
                return [];
            }

            public function timezone(): string
            {
                return 'Asia/Makassar';
            }

            public function now(): CarbonImmutable
            {
                return CarbonImmutable::now('Asia/Makassar');
            }

            public function rowLimit(): int
            {
                return $this->rowLimit;
            }

            public function timeoutMs(): int
            {
                return 8000;
            }

            public function fingerprint(CompiledDataset $dataset): string
            {
                return 'uji';
            }

            public function describe(): string
            {
                return 'uji';
            }
        };
    }

    /** @param array<string, mixed> $input */
    private function parsed(array $input): AnalyticsQuery
    {
        return (new QueryNormalizer)->normalize((new QueryParser)->parse([...['dataset' => 'modul.dataset-contoh', 'measures' => ['count']], ...$input]));
    }

    /** @param array<string, mixed> $input */
    private function assertRejected(array $input, string $code, string $field, ?CompiledDataset $dataset = null, ?AnalyticsPrincipal $principal = null, ?FieldUseGate $gate = null): AnalyticsQueryException
    {
        try {
            (new QueryValidator($gate ?? new PersonalDataGate))->validate($dataset ?? $this->dataset(), $this->parsed($input), $principal ?? $this->principal());
        } catch (AnalyticsQueryException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($field, $e->field);

            return $e;
        }

        $this->fail('Query seharusnya ditolak: '.json_encode($input));
    }

    /** @param array<string, mixed> $input */
    private function assertAccepted(array $input, ?CompiledDataset $dataset = null): void
    {
        (new QueryValidator(new PersonalDataGate))->validate($dataset ?? $this->dataset(), $this->parsed($input), $this->principal());
        $this->addToAssertionCount(1);
    }

    public function test_a_query_using_every_part_of_the_shape_is_accepted(): void
    {
        $this->assertAccepted([
            'dimensions' => ['group_id', ['field' => 'acquired_on', 'granularity' => 'month'], ['field' => 'created_at', 'granularity' => 'day']],
            'measures' => ['count', 'total'],
            'filters' => ['lifecycle_state' => ['received'], 'name' => '*laptop*', 'value' => '>=1.000'],
            'time_range' => ['field' => 'created_at', 'range' => '@last_30_days'],
            'sort' => [['key' => 'total', 'direction' => 'desc'], ['key' => 'group_id', 'direction' => 'asc']],
            'limit' => 5000,
            'totals' => true,
        ]);
    }

    public function test_unknown_fields_and_measures_are_rejected_with_their_path(): void
    {
        $this->assertRejected(['dimensions' => ['group_id', 'kode']], 'analytics.field_unknown', 'dimensions.1');
        $this->assertRejected(['measures' => ['count', 'nilai_buku']], 'analytics.field_unknown', 'measures.1');
        $this->assertRejected(['filters' => ['nama' => 'x']], 'analytics.field_unknown', 'filters.nama');
        $this->assertRejected(['time_range' => ['field' => 'dibuat', 'range' => '@today']], 'analytics.field_unknown', 'time_range.field');
        // Kunci measure bukan kunci field, dan sebaliknya.
        $this->assertRejected(['dimensions' => ['total']], 'analytics.field_unknown', 'dimensions.0');
        $this->assertRejected(['measures' => ['name']], 'analytics.field_unknown', 'measures.0');
    }

    public function test_a_filter_on_an_unknown_field_is_rejected_not_ignored(): void
    {
        $e = $this->assertRejected(['filters' => ['tenant_id' => ['01J']]], 'analytics.field_unknown', 'filters.tenant_id');

        $this->assertSame(422, $e->status);
    }

    public function test_a_key_chosen_twice_is_rejected(): void
    {
        $this->assertRejected(['dimensions' => ['group_id', 'group_id']], 'analytics.invalid_query', 'dimensions.1');
        // Dua ember waktu atas satu kolom memakai kunci hasil yang sama.
        $this->assertRejected(['dimensions' => [['field' => 'acquired_on', 'granularity' => 'month'], ['field' => 'acquired_on', 'granularity' => 'year']]], 'analytics.invalid_query', 'dimensions.1');
        $this->assertRejected(['measures' => ['count', 'count']], 'analytics.invalid_query', 'measures.1');
    }

    public function test_a_time_bucket_is_only_allowed_on_a_time_field(): void
    {
        foreach (['lifecycle_state', 'group_id', 'name', 'value'] as $field) {
            $this->assertRejected(['dimensions' => [['field' => $field, 'granularity' => 'month']]], 'analytics.invalid_query', 'dimensions.0.granularity');
        }
        foreach (['acquired_on', 'created_at'] as $field) {
            foreach (['day', 'week', 'month', 'quarter', 'year'] as $granularity) {
                $this->assertAccepted(['dimensions' => [['field' => $field, 'granularity' => $granularity]]]);
            }
        }
        // Tanpa ember waktu, kolom apa pun boleh menjadi pengelompok.
        $this->assertAccepted(['dimensions' => ['name', 'acquired_on']]);
    }

    public function test_the_time_range_uses_a_time_field_and_a_known_token_or_expression(): void
    {
        $this->assertAccepted(['time_range' => ['range' => '@this_month']]);
        $this->assertAccepted(['time_range' => ['field' => 'created_at', 'range' => '@this_month']]);
        $this->assertAccepted(['time_range' => ['range' => '01/01/2026..31/03/2026']]);

        $this->assertRejected(['time_range' => ['field' => 'name', 'range' => '@this_month']], 'analytics.invalid_query', 'time_range.field');
        $this->assertRejected(['time_range' => ['field' => 'value', 'range' => '@this_month']], 'analytics.invalid_query', 'time_range.field');
        $e = $this->assertRejected(['time_range' => ['range' => '@next_month']], 'analytics.invalid_query', 'time_range.range');
        $this->assertStringContainsString('@this_month', $e->getMessage());
    }

    public function test_a_dataset_without_a_main_time_field_needs_the_field_named(): void
    {
        $dataset = $this->dataset(null);

        $this->assertRejected(['time_range' => ['range' => '@today']], 'analytics.invalid_query', 'time_range.field', $dataset);
        $this->assertAccepted(['time_range' => ['field' => 'created_at', 'range' => '@today']], $dataset);
    }

    public function test_an_expression_inside_a_filter_or_range_is_left_to_the_filter_syntax(): void
    {
        // Isinya milik `FieldFilterExpression`, yang menolaknya saat compile dengan path yang sama.
        $this->assertAccepted(['filters' => ['value' => 'bukan angka', 'acquired_on' => 'bukan tanggal']]);
        $this->assertAccepted(['time_range' => ['range' => 'bukan tanggal']]);
    }

    public function test_sort_keys_must_be_chosen_dimensions_or_measures(): void
    {
        $this->assertAccepted(['dimensions' => ['group_id'], 'measures' => ['count', 'total'], 'sort' => [['key' => 'total', 'direction' => 'desc'], ['key' => 'group_id', 'direction' => 'asc']]]);

        // Kolom yang ada di dataset tetapi tidak dipilih, nilai yang tidak dipilih, dan kunci asing.
        $this->assertRejected(['sort' => [['key' => 'group_id', 'direction' => 'asc']]], 'analytics.invalid_query', 'sort.0.key');
        $this->assertRejected(['sort' => [['key' => 'total', 'direction' => 'asc']]], 'analytics.invalid_query', 'sort.0.key');
        $this->assertRejected(['dimensions' => ['group_id'], 'sort' => [['key' => 'group_id', 'direction' => 'asc'], ['key' => 'zzz', 'direction' => 'asc']]], 'analytics.invalid_query', 'sort.1.key');
        // Mata uang yang ditambahkan engine bukan pilihan pengguna.
        $this->assertRejected(['measures' => ['total'], 'sort' => [['key' => 'currency_code', 'direction' => 'asc']]], 'analytics.invalid_query', 'sort.0.key');
    }

    public function test_a_sort_key_cannot_be_repeated(): void
    {
        $this->assertRejected(['sort' => [['key' => 'count', 'direction' => 'asc'], ['key' => 'count', 'direction' => 'desc']]], 'analytics.invalid_query', 'sort.1.key');
    }

    public function test_limit_stays_within_the_principals_row_limit(): void
    {
        $this->assertAccepted(['limit' => 5000]);
        $this->assertRejected(['limit' => 5001], 'analytics.limit_exceeded', 'limit');
        $this->assertRejected(['limit' => 101], 'analytics.limit_exceeded', 'limit', principal: $this->principal(100));
        (new QueryValidator(new PersonalDataGate))->validate($this->dataset(), $this->parsed(['limit' => 100]), $this->principal(100));
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, int, array<string, mixed>, string, string}>
     */
    public static function countLimits(): iterable
    {
        yield 'pengelompok' => ['dimensions', 4, ['dimensions' => ['lifecycle_state', 'group_id', 'name', 'value', 'currency_code']], 'dimensions', 'Terlalu banyak kolom pengelompokan. Maksimal 4.'];
        yield 'nilai' => ['measures', 12, ['measures' => ['m1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7', 'm8', 'm9', 'm10', 'm11', 'm12', 'm13']], 'measures', 'Terlalu banyak nilai yang dihitung. Maksimal 12.'];
        yield 'saringan' => ['filters', 20, ['filters' => array_fill_keys(array_map(static fn (int $i): string => "f{$i}", range(1, 21)), 'x')], 'filters', 'Terlalu banyak saringan. Maksimal 20.'];
        yield 'urutan' => ['sort', 3, ['sort' => [['key' => 'a', 'direction' => 'asc'], ['key' => 'b', 'direction' => 'asc'], ['key' => 'c', 'direction' => 'asc'], ['key' => 'd', 'direction' => 'asc']]], 'sort', 'Terlalu banyak urutan. Maksimal 3.'];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('countLimits')]
    public function test_counts_above_the_default_limits_are_rejected_before_anything_else_is_read(string $configKey, int $default, array $input, string $field, string $message): void
    {
        // Kunci yang tidak dikenal dataset pun tidak sempat ditolak: batas jumlah diperiksa lebih dulu.
        $e = $this->assertRejected($input, 'analytics.limit_exceeded', $field);

        $this->assertSame($message, $e->getMessage());
        $this->assertSame(422, $e->status);
        $this->assertSame($default, config()->integer('analytics.limits.'.$configKey));
    }

    public function test_the_limits_come_from_config_and_the_boundary_value_is_allowed(): void
    {
        config(['analytics.limits.dimensions' => 2, 'analytics.limits.measures' => 1, 'analytics.limits.filters' => 1, 'analytics.limits.sort' => 1]);

        $this->assertAccepted(['dimensions' => ['lifecycle_state', 'group_id'], 'measures' => ['count'], 'filters' => ['name' => 'x'], 'sort' => [['key' => 'count', 'direction' => 'asc']]]);

        $this->assertSame('Terlalu banyak kolom pengelompokan. Maksimal 2.', $this->assertRejected(['dimensions' => ['lifecycle_state', 'group_id', 'name']], 'analytics.limit_exceeded', 'dimensions')->getMessage());
        $this->assertSame('Terlalu banyak nilai yang dihitung. Maksimal 1.', $this->assertRejected(['measures' => ['count', 'total']], 'analytics.limit_exceeded', 'measures')->getMessage());
        $this->assertSame('Terlalu banyak saringan. Maksimal 1.', $this->assertRejected(['filters' => ['name' => 'x', 'value' => '1']], 'analytics.limit_exceeded', 'filters')->getMessage());
        $this->assertSame('Terlalu banyak urutan. Maksimal 1.', $this->assertRejected(['dimensions' => ['group_id'], 'sort' => [['key' => 'count', 'direction' => 'asc'], ['key' => 'group_id', 'direction' => 'asc']]], 'analytics.limit_exceeded', 'sort')->getMessage());
    }

    public function test_the_personal_data_gate_hears_every_field_the_query_uses_after_the_structure_is_valid(): void
    {
        $gate = new class implements FieldUseGate
        {
            /** @var list<array<string, string>> */
            public array $calls = [];

            public function assertUsable(CompiledDataset $dataset, AnalyticsPrincipal $principal, array $uses): void
            {
                $this->calls[] = $uses;
            }
        };

        (new QueryValidator($gate))->validate($this->dataset(), $this->parsed([
            'dimensions' => ['group_id', ['field' => 'acquired_on', 'granularity' => 'month']],
            'measures' => ['count', 'total'],
            'filters' => ['name' => '*x*', 'lifecycle_state' => ['received']],
            // Tanpa field: bawaan dataset ikut dilaporkan, karena kolom itu juga dipakai query.
            'time_range' => ['range' => '@this_year'],
            'sort' => [['key' => 'total', 'direction' => 'desc']],
        ]), $this->principal());

        $this->assertSame([[
            'dimensions.0' => 'group_id',
            'dimensions.1' => 'acquired_on',
            // Measure ikut dilaporkan lewat kolom bahannya (area 4): `total` menjumlah `value`.
            'measures.1' => 'value',
            'filters.lifecycle_state' => 'lifecycle_state',
            'filters.name' => 'name',
            'time_range.field' => 'acquired_on',
        ]], $gate->calls);
    }

    public function test_the_gate_is_skipped_when_the_query_uses_no_field(): void
    {
        $gate = new class implements FieldUseGate
        {
            public int $calls = 0;

            public function assertUsable(CompiledDataset $dataset, AnalyticsPrincipal $principal, array $uses): void
            {
                $this->calls++;
            }
        };

        // `count` tidak membaca kolom apa pun; `total` membaca `value`, jadi ia memanggil gerbang (area 4).
        (new QueryValidator($gate))->validate($this->dataset(), $this->parsed(['measures' => ['count'], 'limit' => 10]), $this->principal());

        $this->assertSame(0, $gate->calls);
    }

    public function test_a_gate_refusal_reaches_the_caller_unchanged(): void
    {
        $gate = new class implements FieldUseGate
        {
            public function assertUsable(CompiledDataset $dataset, AnalyticsPrincipal $principal, array $uses): void
            {
                throw new AnalyticsQueryException('analytics.field_personal_data', 'Kolom ini memuat data pribadi.', 403, array_key_first($uses));
            }
        };

        $e = $this->assertRejected(['dimensions' => ['name']], 'analytics.field_personal_data', 'dimensions.0', gate: $gate);

        $this->assertSame(403, $e->status);
    }

    public function test_the_gate_never_sees_a_query_that_is_already_malformed(): void
    {
        $gate = new class implements FieldUseGate
        {
            public int $calls = 0;

            public function assertUsable(CompiledDataset $dataset, AnalyticsPrincipal $principal, array $uses): void
            {
                $this->calls++;
            }
        };

        // Kolom yang tidak ada ditolak lebih dulu, jadi gerbang tidak pernah ditanya tentang kolom itu
        // dan pengguna tanpa hak tidak belajar dari urutan galat kolom mana yang ada.
        $this->assertRejected(['dimensions' => ['name', 'kode']], 'analytics.field_unknown', 'dimensions.1', gate: $gate);
        $this->assertRejected(['dimensions' => ['name'], 'limit' => 5001], 'analytics.limit_exceeded', 'limit', gate: $gate);
        $this->assertSame(0, $gate->calls);
    }

    public function test_the_container_hands_the_validator_the_personal_data_gate_unless_another_is_bound(): void
    {
        // Area 4 mengikat gerbangnya; validator tidak dapat dibuat tanpa gerbang.
        $this->assertInstanceOf(PersonalDataGate::class, $this->app->make(FieldUseGate::class));
        $this->assertInstanceOf(QueryValidator::class, $this->app->make(QueryValidator::class));

        $gate = new class implements FieldUseGate
        {
            public int $calls = 0;

            public function assertUsable(CompiledDataset $dataset, AnalyticsPrincipal $principal, array $uses): void
            {
                $this->calls++;
            }
        };
        $this->app->instance(FieldUseGate::class, $gate);

        $this->app->make(QueryValidator::class)->validate($this->dataset(), $this->parsed(['dimensions' => ['name']]), $this->principal());

        $this->assertSame(1, $gate->calls);
    }
}
