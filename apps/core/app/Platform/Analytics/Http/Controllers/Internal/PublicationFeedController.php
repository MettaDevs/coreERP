<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\External\CsvRows;
use App\Platform\Analytics\External\PublicationErrors;
use App\Platform\Analytics\External\PublicationReader;
use App\Platform\Analytics\External\RowCursor;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\ResultColumn;
use App\Platform\Analytics\Security\PublicationAccess;
use App\Platform\Analytics\Security\PublicationPrincipal;
use App\Platform\Integration\Http\Middleware\AuthenticateIntegrationClient;
use App\Platform\Integration\Models\IntegrationClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Publikasi analitik untuk sistem di luar CoreERP (`internal/v1/analytics/publications`, butir 15.5), di balik
 * `integration-client:analytics.read`. Kontraknya ditulis tangan di `contracts/internal/integrasi-analitik.yaml`.
 *
 * Tenant **selalu** dari klien integrasi yang terautentikasi, tidak pernah dari URL atau isian. Publikasi dicari
 * di tenant itu saja, menurut kodenya; publikasi yang tidak ada, sudah dicabut, berjenis lain, atau tidak menyebut
 * klien ini dijawab sama — 404 `analytics.publication_unknown` — supaya keberadaannya tidak bocor. Sesudah itu:
 * dihentikan sementara 403 `analytics.publication_paused`, lalu pemiliknya diperiksa ulang
 * ({@see PublicationAccess}) — yang tidak lagi berhak membuatnya 403 `analytics.publication_suspended`.
 *
 * `last_used_at` diperbarui paling sering sekali semenit, seperti `integration_clients.last_used_at`: sinyal
 * hidup untuk layar, bukan jejak audit — jejaknya di log query.
 */
final class PublicationFeedController extends Controller
{
    public function __construct(
        private readonly PublicationAccess $access,
        private readonly PublicationReader $reader,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $publications = Publication::query()
            ->where('tenant_id', $client->tenant_id)
            ->where('kind', Publication::KIND_QUERY)
            ->whereIn('status', [Publication::ACTIVE, Publication::PAUSED])
            ->whereJsonContains('client_ids', $client->id)
            ->orderBy('code')
            ->get()
            ->filter(fn (Publication $publication): bool => $this->access->ownerStillEntitled($publication))
            ->values();

        return response()->json(['data' => $publications->map(fn (Publication $publication): array => $this->summary($publication))->values()->all()]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        try {
            [$publication, $principal] = $this->open($request, $code);
            $described = $this->reader->describe($publication, $principal);
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }
        $this->touch($publication);

        return response()->json(['data' => [
            ...$this->summary($publication),
            'timezone' => $publication->timezone,
            'small_group_threshold' => $publication->min_group_size,
            ...$described,
        ]]);
    }

    public function rows(Request $request, string $code): Response
    {
        try {
            [$publication, $principal] = $this->open($request, $code);
            $format = $this->format($request, $publication);
            $page = $this->reader->rows($publication, $principal, $this->filters($request), $this->cursor($request), $this->limit($request));
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }
        $this->touch($publication);

        if ($format === 'csv') {
            $keys = [];
            foreach ($page['columns'] as $column) {
                $keys[] = $column->key;
                if ($column->labelKey !== null) {
                    $keys[] = $column->labelKey;
                }
            }
            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$publication->code.'.csv"',
            ];
            if ($page['meta']['next_cursor'] !== null) {
                $headers['X-Next-Cursor'] = $page['meta']['next_cursor'];
            }

            return response(CsvRows::render($keys, $page['rows']), 200, $headers);
        }

        return response()->json([
            'columns' => array_map(static fn (ResultColumn $column): array => $column->toArray(), $page['columns']),
            'rows' => $page['rows'],
            'meta' => $page['meta'],
        ]);
    }

    /**
     * Publikasi berkode ini yang membuka klien yang meminta, beserta principal-nya.
     *
     * @return array{0: Publication, 1: PublicationPrincipal}
     *
     * @throws AnalyticsQueryException
     */
    private function open(Request $request, string $code): array
    {
        $client = $this->client($request);
        $publication = Publication::query()
            ->where('tenant_id', $client->tenant_id)
            ->where('kind', Publication::KIND_QUERY)
            ->where('status', '<>', Publication::REVOKED)
            ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->first();
        if ($publication === null || ! $publication->allowsClient($client->id)) {
            throw PublicationErrors::unknown();
        }
        if ($publication->status === Publication::PAUSED) {
            throw PublicationErrors::paused();
        }

        return [$publication, $this->access->principal($publication, $client->id)];
    }

    private function client(Request $request): IntegrationClient
    {
        $id = $request->attributes->get(AuthenticateIntegrationClient::ATTRIBUTE);
        abort_unless(is_string($id), 401, 'Token klien integrasi tidak sah atau sudah dicabut.');

        return IntegrationClient::query()->findOrFail($id);
    }

    /** @return array{code: string, name: string, description: ?string, status: string, formats: list<string>, updated_at: ?string} */
    private function summary(Publication $publication): array
    {
        return [
            'code' => $publication->code,
            'name' => $publication->name,
            'description' => $publication->description,
            'status' => $publication->status,
            'formats' => $publication->formats,
            'updated_at' => $publication->updated_at?->toIso8601String(),
        ];
    }

    /** @throws AnalyticsQueryException */
    private function format(Request $request, Publication $publication): string
    {
        $format = $request->query('format', 'json');
        if (! is_string($format) || ! in_array($format, Publication::FORMATS, true)) {
            throw PublicationErrors::invalidParameter('format', 'Isi format dengan json atau csv.');
        }
        if (! in_array($format, $publication->formats, true)) {
            throw PublicationErrors::formatUnavailable($format);
        }

        return $format;
    }

    /** @throws AnalyticsQueryException */
    private function limit(Request $request): int
    {
        $limit = $request->query('limit');
        if ($limit === null) {
            return PublicationReader::PAGE_SIZE_DEFAULT;
        }
        $value = is_string($limit) ? filter_var($limit, FILTER_VALIDATE_INT) : false;
        if (! is_int($value) || $value < 1 || $value > PublicationReader::PAGE_SIZE_MAX) {
            throw PublicationErrors::invalidParameter('limit', 'Isi limit dengan angka 1 sampai '.PublicationReader::PAGE_SIZE_MAX.'.');
        }

        return $value;
    }

    /** @throws AnalyticsQueryException */
    private function cursor(Request $request): ?string
    {
        $cursor = $request->query('cursor');
        if ($cursor === null) {
            return null;
        }
        if (! is_string($cursor) || $cursor === '' || strlen($cursor) > RowCursor::MAX_LENGTH) {
            throw PublicationErrors::cursorInvalid();
        }

        return $cursor;
    }

    /**
     * Saringan tambahan dari `filter[kolom]=ekspresi`, atau `filter[kolom][]=nilai` untuk daftar pilihan.
     *
     * @return array<string, string|list<string>>
     *
     * @throws AnalyticsQueryException
     */
    private function filters(Request $request): array
    {
        $input = $request->query('filter');
        if ($input === null) {
            return [];
        }
        if (! is_array($input) || array_is_list($input)) {
            throw PublicationErrors::invalidParameter('filter', 'Tulis saringan sebagai filter[kolom]=nilai.');
        }

        $filters = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
                throw PublicationErrors::filterNotAllowed(mb_substr($key, 0, 64));
            }
            // Isian kosong tiba sebagai null (`ConvertEmptyStringsToNull`), dan berarti tanpa saringan tambahan.
            if ($value === null || is_string($value)) {
                $filters[$key] = $value ?? '';

                continue;
            }
            if (! is_array($value) || ! array_is_list($value) || array_filter($value, static fn (mixed $item): bool => $item !== null && ! is_string($item)) !== []) {
                throw PublicationErrors::invalidParameter('filter.'.$key, 'Tulis daftar pilihan sebagai filter['.$key.'][]=nilai.');
            }
            $filters[$key] = array_map(static fn (?string $item): string => $item ?? '', $value);
        }

        return $filters;
    }

    private function touch(Publication $publication): void
    {
        if ($publication->last_used_at !== null && $publication->last_used_at->greaterThan(now()->subMinute())) {
            return;
        }

        // Sinyal pemakaian tidak mengubah "terakhir diubah" publikasi, jadi ditulis tanpa `updated_at` Eloquent.
        Publication::query()->whereKey($publication->id)->toBase()->update(['last_used_at' => now()]);
    }
}
