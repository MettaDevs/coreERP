<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Reporting\Support\ExportQueue;
use App\Platform\Reporting\Support\ListExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Permintaan ekspor daftar di layar module (K-27): masuk ke antrean ekspor yang sama dengan laporan, tampil
 * di tray Ekspor, dan ikut masa simpan hasil ekspor. Endpoint ini tidak membaca data daftar sama sekali;
 * worker yang memintanya kepada module pemilik daftar.
 *
 * Mengekspor menuntut hak yang sama dengan melihat daftarnya. Daftar yang tidak boleh dilihat dijawab 404,
 * sama dengan daftar yang tidak ada.
 */
class ListExportController extends Controller
{
    public function __construct(
        private readonly ListExporter $lists,
        private readonly ExportQueue $exports,
        private readonly CurrentWorkspace $workspace,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $input = $request->validate([
            'app_id' => ['required', 'string', 'max:80'],
            'list' => ['required', 'string', 'max:80'],
            'columns' => ['required', 'array', 'min:1', 'max:100'],
            'columns.*.key' => ['required', 'string', 'max:80'],
            'columns.*.header' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', 'array'],
            'sort.column' => ['required_with:sort', 'string', 'max:80'],
            'sort.direction' => ['required_with:sort', 'in:asc,desc'],
            'filters' => ['nullable', 'array'],
        ]);
        $source = $this->lists->source($input['app_id'], $input['list']);
        abort_if($source === null || ! $this->lists->canExport($membership, $source), 404);

        $export = $this->exports->enqueueList(
            $source->moduleId(),
            $source->listCode(),
            $source->name(),
            $membership,
            $this->workspace->legalEntity($request, $membership)?->id,
            $this->workspace->operatingUnit($request, $membership)?->id,
            $this->lists->normalize($source, $input),
        );

        return response()->json(['data' => $export], 202, ['Location' => url('/api/v1/report-exports/'.$export['id'])]);
    }
}
