<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Actions\RunQuery;
use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Dashboards\StoredQuery;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Models\Widget;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\Blend;
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Analytics\Support\QueryLog;
use App\Platform\Identity\Support\UserClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data satu widget (`GET api/v1/analytics/widgets/{widget}/data`, `POST .../refresh`), dihitung **sebagai yang
 * melihat** (`docs/todo/analitik/keamanan.md`, prinsip 3): principal-nya dibuat dari keanggotaan sesi yang
 * meminta, tidak pernah dari pemilik dasbor. Kepala unit A yang membuka dasbor bersama buatan direktur melihat
 * angka unit A saja, dan staf tanpa permission baca dataset mendapat 403 `analytics.dataset_forbidden`, bukan
 * angka pinjaman. Penyusun dasbor bersama tidak dapat meminjamkan haknya lewat widget.
 *
 * Urutannya: dasbor boleh dilihat (selain itu 404), dataset terpasang dan boleh dibaca yang melihat, baru
 * query tersimpan dibaca terhadap dataset saat ini — pengguna tanpa hak tidak belajar nama kolom dari pesan
 * galat. Kunci yang diganti nama dipetakan; kunci yang hilang dijawab 422 `analytics.field_removed` dengan
 * path dan nama kolomnya, bukan 500. Sesudah itu jalurnya `RunQuery`, sama dengan query bebas.
 *
 * Hasilnya di-cache selama `cache_ttl_seconds` widget (kosong = bawaan config, `0` = selalu menghitung ulang;
 * area 9, `Cache\QueryCache`). `refresh` — tombol Muat ulang — melewati cache untuk widget ini lalu menimpa
 * hasilnya; keduanya tunduk pada limiter `analytics-interactive`. Slicer (fase 2) belum dibaca dari query string.
 */
final class WidgetDataController extends Controller
{
    public function __construct(
        private readonly DashboardAccess $dashboards,
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly QueryParser $parser,
        private readonly RunQuery $run,
        private readonly Blend $blend,
        private readonly UserClock $clock,
    ) {}

    public function show(Request $request, Widget $widget): JsonResponse
    {
        return $this->data($request, $widget, refresh: false);
    }

    /** Muat ulang: menghitung ulang tanpa membaca cache, lalu menimpa hasil cache widget ini (area 9). */
    public function refresh(Request $request, Widget $widget): JsonResponse
    {
        return $this->data($request, $widget, refresh: true);
    }

    private function data(Request $request, Widget $widget, bool $refresh): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->dashboards->authorizeView($membership, $widget->dashboard ?? abort(404));
        $principal = UserPrincipal::fromMembership($membership, $this->clock->timezone($request));

        try {
            if ($widget->type === 'blend') {
                if ($widget->query === null) {
                    throw AnalyticsQueryException::invalidQuery('query', 'Bagian gabungan ini tidak dapat dibaca. Ubah lalu simpan lagi.');
                }
                $read = $this->blend->readStorage($widget->query);
                foreach ($read['datasets'] as $dataset) {
                    if ($dataset === null) {
                        throw AnalyticsQueryException::datasetUnknown();
                    }
                    $this->access->authorize($principal, $dataset);
                }
                if ($read['missing'] !== []) {
                    $missing = $read['missing'][0];
                    throw new AnalyticsQueryException(
                        'analytics.field_removed',
                        'Kolom "'.$missing['field'].'" sudah tidak tersedia di data ini. Ubah bagian ini untuk memilih kolom lain.',
                        422,
                        "query.queries.{$missing['source']}.{$missing['path']}",
                    );
                }
                $result = $this->blend->handle(
                    $principal,
                    $read['query'],
                    cacheTtl: $widget->cache_ttl_seconds,
                    refresh: $refresh,
                    source: QueryLog::SOURCE_WIDGET,
                );

                return response()->json($result->toArray());
            }

            if ($widget->dataset_code === null || $widget->query === null) {
                throw AnalyticsQueryException::invalidQuery('type', 'Bagian teks tidak punya data untuk dihitung.');
            }
            $dataset = $this->datasets->find($widget->dataset_code) ?? throw AnalyticsQueryException::datasetUnknown();
            $this->access->authorize($principal, $dataset);

            $read = StoredQuery::read($dataset, $widget->query, $widget->dataset_version);
            $path = array_key_first($read['missing']);
            if ($path !== null) {
                throw new AnalyticsQueryException(
                    'analytics.field_removed',
                    'Kolom "'.$read['missing'][$path].'" sudah tidak tersedia di data ini. Ubah bagian ini untuk memilih kolom lain.',
                    422,
                    'query.'.$path,
                );
            }

            $result = $this->run->handle(
                $principal,
                $this->parser->parse($read['query']),
                cacheTtl: $widget->cache_ttl_seconds,
                refresh: $refresh,
                source: QueryLog::SOURCE_WIDGET,
            );
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        return response()->json($result->toArray());
    }
}
