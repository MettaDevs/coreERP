<?php

declare(strict_types=1);

namespace App\Platform\Analytics\External;

use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Dashboards\StoredQuery;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryCompiler;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Query\ResultColumn;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\PersonalDataGate;
use App\Platform\Analytics\Security\PublicationPrincipal;
use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\TenantRunner;

/**
 * Membaca satu publikasi untuk sistem luar (butir 15.5–15.6): metadata kolomnya dan barisnya per halaman.
 *
 * Urutannya selalu sama, dan tidak ada langkah yang dapat dilewati pemanggil:
 *
 * 1. **Salinan query publikasi dibaca terhadap dataset saat ini** ({@see self::prepare()}) dan diperiksa sebagai
 *    pemiliknya — dataset terpasang, permission baca pemilik, kolom masih ada, gerbang data pribadi (principal
 *    publikasi tidak pernah boleh). Yang gagal di sini adalah keadaan publikasi, bukan isian pemanggil: hak
 *    pemilik yang hilang menjadi 403 `analytics.publication_suspended`, selebihnya 409
 *    `analytics.publication_unavailable`.
 * 2. **Saringan tambahan pemanggil hanya menyempitkan.** Ia hanya diterima pada kolom pengelompok publikasi yang
 *    belum disaring query-nya sendiri ({@see self::filterable()}), lalu dipasang **bersama** saringan query,
 *    saringan terkunci, dan kebijakan data — tidak pernah menggantikannya. Saringan terkunci dan kebijakan
 *    data dipasang `DataPolicyScope` dari principal, jadi isian pemanggil tidak pernah menyentuhnya.
 * 3. **`RunQuery`**, jalur yang sama dengan layar: cache, jatah query bersamaan, dan log bersumber `api` dengan
 *    id publikasi dan klien dari `PublicationPrincipal::describe()`. Total tidak dihitung: bentuk baris
 *    publikasi tidak memuatnya, dan total di samping kelompok yang disembunyikan membuka selisihnya.
 * 4. **Kelompok kecil disembunyikan** bila publikasi menyebut ambangnya (PQ-07): measure jumlah baris dataset
 *    ditambahkan diam-diam bila query belum memuatnya, kelompok yang dihitung dari 1 sampai ambang−1 baris
 *    sumber dibuang, lalu kolom tambahannya dilepas. Kelompok bernilai nol tidak menunjuk siapa pun dan tetap
 *    tampil. Ambang bawaannya kosong (mati): PQ-07 belum diputuskan pemilik produk.
 * 5. **Halaman** dipotong dari hasil yang urutannya pasti, dengan {@see RowCursor}.
 */
final class PublicationReader
{
    public const PAGE_SIZE_DEFAULT = 1000;

    public const PAGE_SIZE_MAX = 5000;

    public function __construct(
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly QueryParser $parser,
        private readonly QueryNormalizer $normalizer,
        private readonly QueryValidator $validator,
        private readonly QueryCompiler $compiler,
        private readonly PersonalDataGate $personal,
        private readonly TenantRunner $tenants,
        private readonly RunQuery $run,
    ) {}

    /**
     * Salinan query publikasi, dibaca terhadap dataset saat ini dan diperiksa sebagai pemiliknya. Kunci yang diganti
     * nama module sejak dipublikasikan dipetakan; kunci yang hilang membuat publikasi tidak dapat dibaca.
     *
     * @return array{dataset: CompiledDataset, query: array<string, mixed>, parsed: AnalyticsQuery}
     *
     * @throws AnalyticsQueryException
     */
    public function prepare(Publication $publication, PublicationPrincipal $principal): array
    {
        $dataset = $publication->dataset_code === null ? null : $this->datasets->find($publication->dataset_code);
        if ($dataset === null || $publication->query === null) {
            throw PublicationErrors::unavailable('datanya tidak tersedia lagi; aplikasinya mungkin sudah tidak terpasang.');
        }

        try {
            $this->access->authorize($principal, $dataset);
        } catch (AnalyticsQueryException $e) {
            throw $e->errorCode === 'analytics.dataset_forbidden'
                ? PublicationErrors::suspended()
                : PublicationErrors::unavailable('datanya tidak tersedia lagi; aplikasinya mungkin sudah tidak terpasang.');
        }

        $read = StoredQuery::read($dataset, $publication->query, $publication->dataset_version);
        $missing = array_key_first($read['missing']);
        if ($missing !== null) {
            throw PublicationErrors::unavailable('kolom "'.$read['missing'][$missing].'" sudah tidak tersedia di datanya.');
        }

        try {
            $parsed = $this->normalizer->normalize($this->parser->parse($read['query']));
            $this->validator->validate($dataset, $parsed, $principal);
        } catch (AnalyticsQueryException $e) {
            throw PublicationErrors::unavailable($e->errorCode === 'analytics.field_personal_data'
                ? 'query-nya memakai kolom data pribadi, yang tidak pernah dibuka ke luar.'
                : 'query-nya tidak lagi cocok dengan datanya.');
        }

        return ['dataset' => $dataset, 'query' => $read['query'], 'parsed' => $parsed];
    }

    /**
     * Kolom hasil dan kolom yang boleh disaring pemanggil, tanpa menjalankan query: compiler hanya menyusun SQL.
     *
     * @return array{columns: list<array<string, string|bool>>, filterable: list<array{key: string, caption: string, type: string, options?: list<array{value: string, label: string}>}>}
     *
     * @throws AnalyticsQueryException
     */
    public function describe(Publication $publication, PublicationPrincipal $principal): array
    {
        ['dataset' => $dataset, 'query' => $query, 'parsed' => $parsed] = $this->prepare($publication, $principal);
        $effective = $this->normalizer->normalize($this->parser->parse(self::effective($query, [], null)));
        $columns = $this->tenants->runFor($principal->tenantId(), fn (): array => $this->compiler->compile($dataset, $effective, $principal)->columns);

        return [
            'columns' => array_map(static fn (ResultColumn $column): array => $column->toArray(), $columns),
            'filterable' => array_values($this->filterable($dataset, $query, $parsed, $principal)),
        ];
    }

    /**
     * Satu halaman baris publikasi.
     *
     * @param  array<string, string|list<string>>  $filters  saringan tambahan pemanggil, kunci field => ekspresi atau pilihan
     * @param  string  $source  jalur masuk untuk log: `api` untuk sistem luar, `explore` untuk pratinjau pemilik di layar
     * @return array{columns: list<ResultColumn>, rows: list<array<string, scalar|null>>, meta: array{publication: string, generated_at: string, timezone: string, truncated: bool, small_groups_hidden: int, next_cursor: ?string}}
     *
     * @throws AnalyticsQueryException
     */
    public function rows(Publication $publication, PublicationPrincipal $principal, array $filters, ?string $cursor, int $limit, string $source = QueryLog::SOURCE_API): array
    {
        ['dataset' => $dataset, 'query' => $base, 'parsed' => $parsed] = $this->prepare($publication, $principal);

        $filterable = $this->filterable($dataset, $base, $parsed, $principal);
        foreach (array_keys($filters) as $key) {
            if (! isset($filterable[$key])) {
                throw PublicationErrors::filterNotAllowed($key);
            }
        }

        $threshold = $publication->min_group_size;
        $countKey = $threshold === null ? null : self::countMeasure($dataset);
        if ($threshold !== null && $countKey === null) {
            throw PublicationErrors::unavailable('datanya tidak lagi punya jumlah baris untuk menyembunyikan kelompok kecil.');
        }
        $hiddenCount = $countKey !== null && ! in_array($countKey, $parsed->measures, true) ? $countKey : null;

        try {
            $query = $this->normalizer->normalize($this->parser->parse(self::effective($base, $filters, $hiddenCount)));
        } catch (AnalyticsQueryException $e) {
            throw self::callerError($e, $filters);
        }

        $scope = RowCursor::scope($publication->id, $publication->version, $query->normalized());
        $offset = $cursor === null ? 0 : RowCursor::decode($cursor, $scope);

        try {
            $result = $this->run->handle($principal, $query, source: $source);
        } catch (AnalyticsQueryException $e) {
            throw self::callerError($e, $filters);
        }

        $rows = $result->rows;
        $hidden = 0;
        if ($threshold !== null && $countKey !== null) {
            $kept = [];
            foreach ($rows as $row) {
                $sources = (int) ($row[$countKey] ?? 0);
                if ($sources > 0 && $sources < $threshold) {
                    $hidden++;

                    continue;
                }
                if ($hiddenCount !== null) {
                    unset($row[$countKey]);
                }
                $kept[] = $row;
            }
            $rows = $kept;
        }
        $columns = $hiddenCount === null ? $result->columns : array_values(array_filter(
            $result->columns,
            static fn (ResultColumn $column): bool => ! ($column->kind === ResultColumn::MEASURE && $column->key === $hiddenCount),
        ));

        $next = $offset + $limit < count($rows) ? RowCursor::encode($offset + $limit, $scope) : null;

        return [
            'columns' => $columns,
            'rows' => array_slice($rows, $offset, $limit),
            'meta' => [
                'publication' => $publication->code,
                'generated_at' => $result->meta['generated_at'],
                'timezone' => $result->meta['timezone'],
                'truncated' => $result->meta['truncated'],
                'small_groups_hidden' => $hidden,
                'next_cursor' => $next,
            ],
        ];
    }

    /**
     * Measure jumlah baris tanpa saringan tetap milik dataset, bahan penyembunyian kelompok kecil; null bila dataset
     * tidak menyatakannya.
     */
    public static function countMeasure(CompiledDataset $dataset): ?string
    {
        foreach ($dataset->measures() as $measure) {
            if ($measure->aggregate === Aggregate::Count && $measure->where === []) {
                return $measure->key;
            }
        }

        return null;
    }

    /**
     * Kolom yang boleh disaring pemanggil: kolom pengelompok publikasi (untuk periode, kolom tanggalnya) yang belum
     * disaring query publikasi dan yang boleh tampil tanpa hak data pribadi. Kolom yang sudah disaring query tidak
     * ditawarkan, karena satu kolom hanya memegang satu saringan dan saringan pemanggil tidak boleh menggantikannya.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, array{key: string, caption: string, type: string, options?: list<array{value: string, label: string}>}>
     */
    private function filterable(CompiledDataset $dataset, array $query, AnalyticsQuery $parsed, PublicationPrincipal $principal): array
    {
        $taken = is_array($query['filters'] ?? null) ? array_map(strval(...), array_keys($query['filters'])) : [];
        $visible = $this->personal->visibleFields($dataset, $principal);

        $out = [];
        foreach ($parsed->dimensions as $dimension) {
            $key = $dimension->field;
            if (isset($out[$key]) || in_array($key, $taken, true) || ! isset($visible[$key])) {
                continue;
            }
            $field = $visible[$key];
            $entry = ['key' => $field->key, 'caption' => $field->caption, 'type' => $field->type->value];
            if ($field->type === FieldType::Option) {
                $entry['options'] = array_map(
                    static fn (int|string $value, string $label): array => ['value' => (string) $value, 'label' => $label],
                    array_keys($field->options),
                    array_values($field->options),
                );
            }
            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * Query yang dijalankan: salinan query publikasi, saringan tambahan pemanggil di samping saringannya sendiri,
     * tanpa total, dan measure jumlah baris tambahan bila kelompok kecil disembunyikan.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string|list<string>>  $filters
     * @return array<string, mixed>
     */
    private static function effective(array $query, array $filters, ?string $hiddenCount): array
    {
        if ($filters !== []) {
            $query['filters'] = [...(is_array($query['filters'] ?? null) ? $query['filters'] : []), ...$filters];
        }
        unset($query['totals']);
        if ($hiddenCount !== null) {
            $query['measures'] = [...(is_array($query['measures'] ?? null) ? $query['measures'] : []), $hiddenCount];
        }

        return $query;
    }

    /**
     * Galat engine selama menjalankan query publikasi. Galat pada saringan pemanggil menunjuk parameternya
     * (`filter.<kolom>`); hak pemilik yang hilang di tengah jalan menjadi tertahan; selebihnya — terlalu berat,
     * jatah habis — diteruskan apa adanya.
     *
     * @param  array<string, string|list<string>>  $filters
     */
    private static function callerError(AnalyticsQueryException $e, array $filters): AnalyticsQueryException
    {
        if ($e->errorCode === 'analytics.dataset_forbidden') {
            return PublicationErrors::suspended();
        }
        if ($e->errorCode === 'analytics.dataset_unknown') {
            return PublicationErrors::unavailable('datanya tidak tersedia lagi; aplikasinya mungkin sudah tidak terpasang.');
        }
        if ($e->field === 'filters' && $filters !== []) {
            return new AnalyticsQueryException($e->errorCode, $e->getMessage(), $e->status, 'filter', $e, $e->retryAfter);
        }
        if ($e->field !== null && str_starts_with($e->field, 'filters.')) {
            $key = explode('.', substr($e->field, strlen('filters.')), 2)[0];
            if (array_key_exists($key, $filters)) {
                return new AnalyticsQueryException($e->errorCode, $e->getMessage(), $e->status, 'filter.'.substr($e->field, strlen('filters.')), $e, $e->retryAfter);
            }
        }

        return $e;
    }
}
