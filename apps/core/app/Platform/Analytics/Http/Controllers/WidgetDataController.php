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
use App\Platform\Analytics\Query\QueryParser;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\UserPrincipal;
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
 * `refresh` melewati cache untuk widget ini. Cache belum ada sampai area 9, jadi keduanya sekarang sama-sama
 * menghitung ulang; slicer (fase 2) belum dibaca dari query string.
 */
final class WidgetDataController extends Controller
{
    public function __construct(
        private readonly DashboardAccess $dashboards,
        private readonly DatasetRegistry $datasets,
        private readonly DatasetAccess $access,
        private readonly QueryParser $parser,
        private readonly RunQuery $run,
        private readonly UserClock $clock,
    ) {}

    public function show(Request $request, Widget $widget): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $this->dashboards->authorizeView($membership, $widget->dashboard ?? abort(404));
        $principal = UserPrincipal::fromMembership($membership, $this->clock->timezone($request));

        try {
            if ($widget->dataset_code === null || $widget->query === null) {
                throw AnalyticsQueryException::invalidQuery('type', 'Widget teks tidak punya data untuk dihitung.');
            }
            $dataset = $this->datasets->find($widget->dataset_code) ?? throw AnalyticsQueryException::datasetUnknown();
            $this->access->authorize($principal, $dataset);

            $read = StoredQuery::read($dataset, $widget->query, $widget->dataset_version);
            $path = array_key_first($read['missing']);
            if ($path !== null) {
                throw new AnalyticsQueryException(
                    'analytics.field_removed',
                    'Kolom "'.$read['missing'][$path].'" sudah tidak tersedia di data ini. Ubah widget untuk memilih kolom lain.',
                    422,
                    'query.'.$path,
                );
            }

            $result = $this->run->handle($principal, $this->parser->parse($read['query']));
        } catch (AnalyticsQueryException $e) {
            return $e->toResponse();
        }

        return response()->json($result->toArray());
    }

    /** Melewati cache untuk widget ini (area 9); sampai cache ada, sama dengan {@see self::show()}. */
    public function refresh(Request $request, Widget $widget): JsonResponse
    {
        return $this->show($request, $widget);
    }
}
