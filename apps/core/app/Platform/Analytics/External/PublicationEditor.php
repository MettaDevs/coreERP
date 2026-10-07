<?php

declare(strict_types=1);

namespace App\Platform\Analytics\External;

use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Dashboards\StoredQuery;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Analytics\Models\SavedQuery;
use App\Platform\Analytics\Query\AnalyticsQuery;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\QueryNormalizer;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Query\QueryValidator;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\PersonalDataGate;
use App\Platform\Analytics\Security\PublicationPrincipal;
use App\Platform\Integration\Models\IntegrationClient;
use App\Platform\Modules\Contracts\FieldFilterExpression;
use App\Platform\Modules\Contracts\InvalidFilterExpression;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Isian publikasi dari layar (butir 15.2), diperiksa sebelum disimpan. Galatnya `ValidationException` berkunci
 * isian form, supaya layar menunjuk isian yang salah.
 *
 * - **Salinan query** ({@see self::snapshot()}) diambil dari query tersimpan yang boleh dibuka calon pemiliknya
 *   dan diperiksa sebagai publikasi: dataset terpasang dan boleh dibaca pemiliknya, kolom masih ada, dan **tanpa
 *   data pribadi** apa pun hak pemiliknya (KA-05). Query tersimpan yang memakai kolom data pribadi tidak dapat
 *   dipublikasikan.
 * - **Saringan terkunci** hanya pada kolom yang boleh tampil tanpa hak data pribadi, dan selalu bernilai:
 *   saringan terkunci kosong berarti nol baris, jadi isian kosong ditolak di sini alih-alih menghasilkan
 *   publikasi yang tidak pernah memulangkan apa pun. Sintaksnya diperiksa dengan pembaca saringan yang sama
 *   dengan saat dijalankan.
 * - **Klien** hanya klien integrasi aktif tenant ini yang memegang scope `analytics.read`.
 * - **Ambang kelompok kecil** (PQ-07) bawaannya kosong; bila diisi, dataset wajib punya measure jumlah baris.
 */
final class PublicationEditor
{
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,79}$/';

    public const MAX_LOCKED_FILTERS = 20;

    public const MAX_CLIENTS = 50;

    public const MIN_GROUP_SIZE_MIN = 2;

    public const MIN_GROUP_SIZE_MAX = 1000;

    private const DUPLICATE_CODE = 'Kode ini sudah dipakai publikasi lain di tenant ini. Pilih kode lain.';

    public function __construct(
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly QueryParser $parser,
        private readonly QueryNormalizer $normalizer,
        private readonly QueryValidator $validator,
        private readonly PersonalDataGate $personal,
        private readonly DashboardAccess $dashboards,
    ) {}

    /**
     * Salinan query tersimpan untuk publikasi milik `$owner`. `$publication` memberi tenant dan zona waktunya.
     *
     * @return array{dataset: CompiledDataset, parsed: AnalyticsQuery, values: array{saved_query_id: string, dataset_code: string, dataset_version: int, query: array<string, mixed>}}
     *
     * @throws ValidationException
     */
    public function snapshot(TenantMembership $owner, Publication $publication, ?SavedQuery $saved): array
    {
        if ($saved === null || $saved->tenant_id !== $owner->tenant_id || ! $this->dashboards->canView($owner, $saved)) {
            throw self::fail('saved_query_id', 'Pilih analisis tersimpan yang dapat Anda buka.');
        }

        $dataset = $this->datasets->find($saved->dataset_code)
            ?? throw self::fail('saved_query_id', 'Data analisis ini tidak tersedia lagi. Aplikasinya mungkin belum terpasang.');
        $principal = PublicationPrincipal::make($publication, $owner);

        $read = StoredQuery::read($dataset, $saved->query, $saved->dataset_version);
        $missing = array_key_first($read['missing']);
        if ($missing !== null) {
            throw self::fail('saved_query_id', 'Kolom "'.$read['missing'][$missing].'" di analisis ini sudah tidak tersedia. Ubah analisisnya lebih dulu.');
        }

        try {
            $this->access->authorize($principal, $dataset);
            $parsed = $this->normalizer->normalize($this->parser->parse($read['query']));
            $this->validator->validate($dataset, $parsed, $principal);
        } catch (AnalyticsQueryException $e) {
            throw self::fail('saved_query_id', match ($e->errorCode) {
                'analytics.field_personal_data' => 'Analisis ini memakai kolom data pribadi, yang tidak pernah dibuka ke sistem lain. Pilih analisis tanpa kolom itu.',
                'analytics.dataset_forbidden' => 'Anda tidak punya akses ke data analisis ini.',
                'analytics.dataset_unknown' => 'Data analisis ini tidak tersedia lagi. Aplikasinya mungkin belum terpasang.',
                default => $e->getMessage(),
            });
        }

        return [
            'dataset' => $dataset,
            'parsed' => $parsed,
            'values' => [
                'saved_query_id' => $saved->id,
                'dataset_code' => $dataset->code,
                'dataset_version' => $dataset->version,
                'query' => StoredQuery::compact($parsed),
            ],
        ];
    }

    /**
     * Dataset dan query salinan publikasi yang sudah ada, untuk memeriksa isian lain tanpa mengganti salinannya.
     *
     * @return array{dataset: CompiledDataset, parsed: AnalyticsQuery}
     *
     * @throws ValidationException
     */
    public function current(Publication $publication): array
    {
        $dataset = $publication->dataset_code === null ? null : $this->datasets->find($publication->dataset_code);
        if ($dataset === null || $publication->query === null) {
            throw self::fail('saved_query_id', 'Data publikasi ini tidak tersedia lagi. Pilih analisis tersimpan lain.');
        }

        $read = StoredQuery::read($dataset, $publication->query, $publication->dataset_version);
        try {
            $parsed = $this->normalizer->normalize($this->parser->parse($read['query']));
        } catch (AnalyticsQueryException) {
            throw self::fail('saved_query_id', 'Analisis publikasi ini tidak lagi cocok dengan datanya. Pilih analisis tersimpan lagi.');
        }

        return ['dataset' => $dataset, 'parsed' => $parsed];
    }

    /**
     * Saringan terkunci dari isian datar `{kolom: nilai}`, disimpan per dataset: `{dataset: {kolom: nilai}}`.
     *
     * @return array<string, array<string, string|list<string>>>
     *
     * @throws ValidationException
     */
    public function lockedFilters(CompiledDataset $dataset, PublicationPrincipal $principal, mixed $input): array
    {
        if ($input === null || $input === []) {
            return [];
        }
        if (! is_array($input) || array_is_list($input)) {
            throw self::fail('locked_filters', 'Saringan terkunci tidak terbaca. Pilih kolom lalu isi nilainya.');
        }
        if (count($input) > self::MAX_LOCKED_FILTERS) {
            throw self::fail('locked_filters', 'Saringan terkunci paling banyak '.self::MAX_LOCKED_FILTERS.' kolom.');
        }

        $visible = $this->personal->visibleFields($dataset, $principal);
        $out = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (! isset($visible[$key])) {
                throw self::fail("locked_filters.{$key}", 'Kolom ini tidak dapat dipakai sebagai saringan terkunci.');
            }

            $value = self::filterValue($value) ?? throw self::fail("locked_filters.{$key}", 'Nilai saringan ini tidak terbaca.');
            if ($value === '' || $value === []) {
                throw self::fail("locked_filters.{$key}", 'Isi nilai saringan ini. Saringan tanpa nilai membuat publikasi tidak memulangkan data apa pun.');
            }

            try {
                // Hanya memeriksa isian: query kosong ini tidak pernah dijalankan.
                FieldFilterExpression::apply(DB::query(), $visible[$key], $value, $principal->timezone());
            } catch (InvalidFilterExpression $e) {
                throw self::fail("locked_filters.{$key}", $e->getMessage());
            }
            $out[$key] = $value;
        }

        return [$dataset->code => $out];
    }

    /**
     * Klien integrasi yang boleh membaca: aktif, milik tenant ini, dan memegang scope `analytics.read`.
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function clients(string $tenantId, mixed $input): array
    {
        if ($input === null) {
            return [];
        }
        if (! is_array($input) || ! array_is_list($input)) {
            throw self::fail('client_ids', 'Daftar klien integrasi tidak terbaca.');
        }
        $ids = array_values(array_unique(array_map(static fn (mixed $id): string => is_string($id) ? $id : '', $input)));
        if (count($ids) > self::MAX_CLIENTS) {
            throw self::fail('client_ids', 'Klien integrasi paling banyak '.self::MAX_CLIENTS.' per publikasi.');
        }
        foreach ($ids as $id) {
            if (! Str::isUlid($id)) {
                throw self::fail('client_ids', 'Klien integrasi ini tidak ditemukan atau sudah dicabut.');
            }
        }

        $clients = IntegrationClient::query()
            ->where('tenant_id', $tenantId)
            ->where('status', IntegrationClient::ACTIVE)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        foreach ($ids as $id) {
            $client = $clients->get($id);
            if ($client === null) {
                throw self::fail('client_ids', 'Klien integrasi ini tidak ditemukan atau sudah dicabut.');
            }
            if (! $client->hasScope('analytics.read')) {
                throw self::fail('client_ids', 'Klien "'.$client->name.'" belum punya scope analytics.read. Tambahkan scope-nya di Klien integrasi lebih dulu.');
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function formats(mixed $input): array
    {
        if (! is_array($input) || ! array_is_list($input) || $input === []) {
            throw self::fail('formats', 'Pilih sedikitnya satu format.');
        }
        foreach ($input as $format) {
            if (! in_array($format, Publication::FORMATS, true)) {
                throw self::fail('formats', 'Format yang tersedia hanya JSON dan CSV.');
            }
        }

        return array_values(array_intersect(Publication::FORMATS, $input));
    }

    /**
     * Ambang kelompok kecil (PQ-07): kosong berarti mati. Bila diisi, dataset wajib punya measure jumlah baris dan
     * query masih punya tempat untuk menambahkannya.
     *
     * @throws ValidationException
     */
    public function minGroupSize(CompiledDataset $dataset, AnalyticsQuery $parsed, mixed $input): ?int
    {
        if ($input === null || $input === '') {
            return null;
        }
        $size = filter_var($input, FILTER_VALIDATE_INT);
        if (! is_int($size) || $size < self::MIN_GROUP_SIZE_MIN || $size > self::MIN_GROUP_SIZE_MAX) {
            throw self::fail('min_group_size', 'Isi angka '.self::MIN_GROUP_SIZE_MIN.' sampai '.self::MIN_GROUP_SIZE_MAX.', atau kosongkan untuk menampilkan semua kelompok.');
        }

        $count = PublicationReader::countMeasure($dataset)
            ?? throw self::fail('min_group_size', 'Data ini tidak punya nilai jumlah baris, jadi kelompok kecil tidak dapat disembunyikan.');
        if (! in_array($count, $parsed->measures, true) && count($parsed->measures) >= config()->integer('analytics.limits.measures', 12)) {
            throw self::fail('min_group_size', 'Kurangi satu nilai di analisis ini supaya jumlah baris dapat ikut dihitung untuk menyembunyikan kelompok kecil.');
        }

        return $size;
    }

    /**
     * Kode publikasi: yang diisi bila belum dipakai, atau dibuat dari nama (`nama`, `nama-2`, …).
     *
     * @throws ValidationException
     */
    public function code(string $tenantId, ?string $code, string $name): string
    {
        if ($code !== null) {
            if ($this->codeTaken($tenantId, $code)) {
                throw self::fail('code', self::DUPLICATE_CODE);
            }

            return $code;
        }

        $base = Str::limit(Str::slug($name), 70, '');
        $base = $base === '' ? 'publikasi' : rtrim($base, '-');
        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? $base : "{$base}-{$n}";
            if (! $this->codeTaken($tenantId, $candidate)) {
                return $candidate;
            }
        }
    }

    public static function duplicateCode(): ValidationException
    {
        return self::fail('code', self::DUPLICATE_CODE);
    }

    private function codeTaken(string $tenantId, string $code): bool
    {
        return Publication::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->exists();
    }

    /**
     * Nilai saringan: teks ekspresi, atau daftar pilihan untuk kolom pilihan, ya/tidak, dan rujukan. Null bila
     * bentuknya tidak terbaca.
     *
     * @return string|list<string>|null
     */
    private static function filterValue(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_int($item)) {
                return null;
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    private static function fail(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}
