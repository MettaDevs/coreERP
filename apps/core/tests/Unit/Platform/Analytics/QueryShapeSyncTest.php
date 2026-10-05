<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\Query\CompareMode;
use App\Platform\Analytics\Query\Formula\Parser;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\RelativeRange;
use App\Platform\Analytics\Query\TimeGranularity;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use PHPUnit\Framework\TestCase;

/**
 * Menjaga satu bentuk query tetap satu: skema JSON, pembaca query di server, dan tipe TypeScript layar
 * (`resources/js/lib/analytics/{types,query}.ts`) harus menyebut kunci, ukuran waktu, dan token periode
 * yang sama. Repo ini tidak memasang pustaka validasi skema, dan pembaca query memvalidasi dengan
 * aturannya sendiri; tanpa test ini skema hanya dokumen yang bisa menyimpang tanpa ada yang tahu.
 *
 * Yang dibandingkan kunci, bukan aturan: tipe dan batas nilai dijaga `QueryParserTest`.
 */
class QueryShapeSyncTest extends TestCase
{
    private const SCHEMA = 'resources/schemas/analytics-query.schema.json';

    private const TYPES = 'resources/js/lib/analytics/types.ts';

    private const QUERY = 'resources/js/lib/analytics/query.ts';

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $schema = json_decode($this->read(self::SCHEMA), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($schema);

        return $schema;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4).'/'.$path);
        $this->assertIsString($contents, "{$path} tidak ditemukan.");

        return str_replace("\r\n", "\n", $contents);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function keys(array $node): array
    {
        $properties = $node['properties'] ?? null;
        $this->assertIsArray($properties);

        return array_keys($properties);
    }

    public function test_the_schema_is_draft_2020_12_and_closed(): void
    {
        $schema = $this->schema();

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['dataset', 'measures'], $schema['required']);
    }

    public function test_the_schema_has_exactly_the_keys_the_parser_reads(): void
    {
        $schema = $this->schema();

        $this->assertEqualsCanonicalizing(QueryParser::KEYS, $this->keys($schema));
    }

    public function test_the_nested_keys_of_the_schema_are_the_ones_the_parser_reads(): void
    {
        $properties = $this->schema()['properties'];

        $dimension = $properties['dimensions']['items']['oneOf'][1];
        $this->assertEqualsCanonicalizing(QueryParser::DIMENSION_KEYS, $this->keys($dimension));
        $this->assertSame(['field'], $dimension['required']);
        $this->assertFalse($dimension['additionalProperties']);

        $this->assertEqualsCanonicalizing(QueryParser::TIME_RANGE_KEYS, $this->keys($properties['time_range']));
        $this->assertSame(['range'], $properties['time_range']['required']);
        $this->assertFalse($properties['time_range']['additionalProperties']);

        $this->assertEqualsCanonicalizing(QueryParser::SORT_KEYS, $this->keys($properties['sort']['items']));
        $this->assertEqualsCanonicalizing(QueryParser::SORT_KEYS, $properties['sort']['items']['required']);
        $this->assertFalse($properties['sort']['items']['additionalProperties']);

        $formula = $properties['formulas']['items'];
        $this->assertEqualsCanonicalizing(QueryParser::FORMULA_KEYS, $this->keys($formula));
        $this->assertSame(['key', 'expression'], $formula['required']);
        $this->assertFalse($formula['additionalProperties']);
    }

    public function test_the_schema_enums_are_the_ones_the_server_knows(): void
    {
        $schema = $this->schema();

        $this->assertSame(
            array_map(static fn (TimeGranularity $case): string => $case->value, TimeGranularity::cases()),
            $schema['$defs']['granularity']['enum'],
        );
        $this->assertSame(['asc', 'desc'], $schema['properties']['sort']['items']['properties']['direction']['enum']);
        $this->assertSame(array_map(static fn (CompareMode $case): string => $case->value, CompareMode::cases()), $schema['properties']['compare']['enum']);
        $this->assertSame(array_map(static fn (MeasureFormat $case): string => $case->value, MeasureFormat::cases()), $schema['$defs']['format']['enum']);

        // Daftar token di deskripsi skema adalah yang dibaca orang yang menulis query dari luar.
        $description = $schema['properties']['time_range']['properties']['range']['description'];
        foreach (RelativeRange::TOKENS as $token) {
            $this->assertStringContainsString($token, $description);
        }
        preg_match_all('/@[a-z0-9_]+/', $description, $matches);
        $this->assertEqualsCanonicalizing(RelativeRange::TOKENS, $matches[0]);
    }

    public function test_the_typescript_query_type_has_exactly_the_keys_the_parser_reads(): void
    {
        $types = $this->read(self::TYPES);

        $this->assertSame(1, preg_match('/export type AnalyticsQuery = \{\n(.*?)\n\};/s', $types, $block), 'Tipe AnalyticsQuery tidak ditemukan.');
        // Kunci tingkat atas ditulis dengan indentasi empat spasi; kunci bersarang lebih dalam.
        preg_match_all('/^ {4}(\w+)\??:/m', $block[1], $keys);

        $this->assertEqualsCanonicalizing(QueryParser::KEYS, $keys[1]);
    }

    public function test_the_typescript_granularity_is_the_server_enum(): void
    {
        $this->assertSame(1, preg_match('/export type TimeGranularity = ([^;]+);/', $this->read(self::TYPES), $match));
        preg_match_all("/'(\w+)'/", $match[1], $values);

        $this->assertSame(array_map(static fn (TimeGranularity $case): string => $case->value, TimeGranularity::cases()), $values[1]);
    }

    public function test_the_screen_helpers_list_the_same_tokens_and_granularities_as_the_server(): void
    {
        $query = $this->read(self::QUERY);

        preg_match_all("/token: '(@\w+)'/", $query, $tokens);
        $this->assertSame(RelativeRange::TOKENS, $tokens[1]);

        $this->assertSame(1, preg_match('/TIME_GRANULARITIES = \[(.*?)\] as const/s', $query, $block));
        preg_match_all("/value: '(\w+)'/", $block[1], $granularities);
        $this->assertSame(array_map(static fn (TimeGranularity $case): string => $case->value, TimeGranularity::cases()), $granularities[1]);
    }

    /**
     * Editor rumus (area 13.7) menawarkan fungsi dan pembanding dari daftarnya sendiri; daftar itu harus sama dengan
     * daftar tertutup pembaca rumus dan pilihan perbandingan di server, supaya layar tidak menawarkan yang ditolak.
     */
    public function test_the_formula_editor_offers_exactly_the_server_functions_formats_and_comparisons(): void
    {
        $query = $this->read(self::QUERY);

        $this->assertSame(1, preg_match('/FORMULA_FUNCTIONS = \[(.*?)\] as const/s', $query, $functions));
        preg_match_all("/name: '(\w+)'/", $functions[1], $names);
        $this->assertSame(array_keys(Parser::FUNCTIONS), $names[1]);

        $this->assertSame(1, preg_match('/FORMULA_FORMATS = \[(.*?)\] as const/s', $query, $formats));
        preg_match_all("/value: '(\w+)'/", $formats[1], $values);
        $this->assertSame(array_map(static fn (MeasureFormat $case): string => $case->value, MeasureFormat::cases()), $values[1]);

        $this->assertSame(1, preg_match('/COMPARE_MODES = \[(.*?)\] as const/s', $query, $modes));
        preg_match_all("/value: '(\w+)'/", $modes[1], $values);
        $this->assertSame(array_map(static fn (CompareMode $case): string => $case->value, CompareMode::cases()), $values[1]);
    }
}
