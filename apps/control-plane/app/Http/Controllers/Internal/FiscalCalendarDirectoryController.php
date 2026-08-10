<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kalender fiskal untuk app yang perhitungannya bergantung pada batas tahun buku,
 * misalnya penyusutan aset dengan dasar tahun fiskal. Kalender melekat pada legal
 * entity, jadi app cukup menyebut legal entity dan tanggal yang ditanyakan.
 *
 * Fakta kalender tetap dimiliki Core dan tidak boleh diduplikasi di database app.
 */
final class FiscalCalendarDirectoryController extends Controller
{
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'string', 'size:26'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $entity = LegalEntity::query()
            ->where('tenant_id', $request->attributes->get('coreerp.tenant_id'))
            ->whereKey($data['legal_entity_id'])
            // Primary key legal entity adalah `organization_id`, bukan `id`.
            ->first(['organization_id', 'fiscal_calendar_id']);

        if (! $entity?->fiscal_calendar_id) {
            return response()->json(['error' => [
                'code' => 'fiscal_calendar_not_assigned',
                'message' => 'Perusahaan ini belum memiliki kalender tahun buku.',
            ]], 404);
        }

        // Periode adalah unit terkecil dan sudah membawa tahunnya, jadi satu query
        // menjawab keduanya sekaligus.
        $period = FiscalPeriod::query()
            ->whereHas('year', fn ($query) => $query->where('fiscal_calendar_id', $entity->fiscal_calendar_id))
            ->whereDate('starts_on', '<=', $data['date'])
            ->whereDate('ends_on', '>=', $data['date'])
            ->with('year.calendar:id,code,name')
            ->first();

        if (! $period) {
            return response()->json(['error' => [
                'code' => 'fiscal_period_not_found',
                'message' => 'Tanggal tersebut belum tercakup pada kalender tahun buku perusahaan ini.',
            ]], 404);
        }

        return response()->json(['data' => [
            'calendar' => [
                'id' => $period->year->calendar->id,
                'code' => $period->year->calendar->code,
                'name' => $period->year->calendar->name,
            ],
            'year' => [
                'id' => $period->year->id,
                'name' => $period->year->name,
                'starts_on' => $period->year->starts_on->toDateString(),
                'ends_on' => $period->year->ends_on->toDateString(),
            ],
            'period' => [
                'id' => $period->id,
                'ordinal' => $period->ordinal,
                'name' => $period->name,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ],
        ]]);
    }
}
