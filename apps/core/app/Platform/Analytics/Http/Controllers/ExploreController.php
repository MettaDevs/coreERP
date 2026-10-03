<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\CompiledMeasure;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Security\DatasetAccess;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\FieldType;
use App\Platform\Modules\Contracts\FilterField;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Analisis data sementara (area 0, kerangka berjalan): satu tile dan satu grafik kolom dari
 * `POST /api/v1/analytics/query`, untuk membuktikan setiap lapis tersambung. Area 8 menggantinya dengan
 * penjelajah sungguhan.
 *
 * Query-nya disusun dari dataset pertama yang boleh dibaca pengguna, bukan ditulis mati: Core tidak
 * menyebut nama module mana pun. Tile memakai measure hitung pertama dataset, grafik memakai measure
 * uang pertama per field pilihan pertama; judul keduanya dari nama tampilan yang dinyatakan module.
 */
final class ExploreController extends Controller
{
    public function __invoke(Request $request, DatasetRegistry $datasets, DatasetAccess $access, UserClock $clock): Response
    {
        $principal = UserPrincipal::fromMembership($this->currentMembership($request), $clock->timezone($request));

        $preview = null;
        foreach ($datasets->all() as $dataset) {
            $preview = $access->allows($principal, $dataset) ? $this->preview($dataset) : null;
            if ($preview !== null) {
                break;
            }
        }

        return Inertia::render('platform/analytics/explore', ['preview' => $preview]);
    }

    /**
     * @return array{dataset: array{code: string, caption: string}, tile: array{caption: string, query: array{dataset: string, measures: list<string>}}, chart: array{caption: string, query: array{dataset: string, dimensions: list<string>, measures: list<string>}}|null}|null
     */
    private function preview(CompiledDataset $dataset): ?array
    {
        // Measure bersaringan tetap belum dikompilasi di kerangka ini (area 3), jadi tidak ditawarkan.
        $measures = array_values(array_filter($dataset->measures(), static fn (CompiledMeasure $measure): bool => $measure->where === []));
        if ($measures === []) {
            return null;
        }

        $count = array_find($measures, static fn (CompiledMeasure $measure): bool => $measure->aggregate === Aggregate::Count) ?? $measures[0];
        $value = array_find($measures, static fn (CompiledMeasure $measure): bool => $measure->format === MeasureFormat::Money)
            ?? array_find($measures, static fn (CompiledMeasure $measure): bool => $measure->aggregate === Aggregate::Sum);
        $category = array_find($dataset->fields(), static fn (FilterField $field): bool => $field->type === FieldType::Option);

        return [
            'dataset' => ['code' => $dataset->code, 'caption' => $dataset->caption],
            'tile' => [
                'caption' => $count->caption,
                'query' => ['dataset' => $dataset->code, 'measures' => [$count->key]],
            ],
            'chart' => $value === null || $category === null ? null : [
                'caption' => $value->caption.' per '.mb_strtolower($category->caption),
                'query' => ['dataset' => $dataset->code, 'dimensions' => [$category->key], 'measures' => [$value->key]],
            ],
        ];
    }
}
