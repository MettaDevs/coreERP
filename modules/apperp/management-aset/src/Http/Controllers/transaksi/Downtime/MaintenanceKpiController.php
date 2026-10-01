<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Downtime;

use App\Platform\Modules\Contracts\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Services\MaintenanceKpi;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Layar KPI pemeliharaan; padanan *Asset KPIs* F&O. Rumusnya di {@see MaintenanceKpi}.
 *
 * Periode adalah tanggal menurut zona pengguna, inklusif di kedua ujung. Aset tersaring jangkauan
 * organisasi pengguna; aset yang sudah dihentikan tetap ikut, karena downtime dan work order-nya
 * sebelum dihentikan tetap bagian periode itu.
 */
class MaintenanceKpiController extends Controller
{
    /** Batas panjang periode, supaya satu permintaan tidak memindai riwayat bertahun-tahun. */
    private const MAX_DAYS = 400;

    public function __invoke(Request $request, MaintenanceKpi $kpi): JsonResponse
    {
        abort_unless(in_array('management-aset.kpi-pemeliharaan.read', $request->attributes->get('coreerp.permissions', []), true), 403);
        $timezone = app(RequestContext::class)->timezone();
        $today = Carbon::now($timezone)->toDateString();
        $filter = $request->validate([
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'kelompok' => ['nullable', Rule::in(MaintenanceKpi::GROUPINGS)],
            'legal_entity_id' => ['nullable', 'ulid'],
            'jenis_aset_id' => ['nullable', 'ulid'],
            'lokasi_aset_id' => ['nullable', 'ulid'],
            'aset_id' => ['nullable', 'ulid'],
        ]);
        $from = Carbon::parse($filter['dari'] ?? Carbon::parse($today, $timezone)->startOfMonth()->toDateString(), $timezone)->startOfDay();
        $until = Carbon::parse($filter['sampai'] ?? $today, $timezone)->addDay()->startOfDay();
        if ($from->diffInDays($until) > self::MAX_DAYS) {
            return response()->json(['message' => 'Periode KPI paling panjang '.self::MAX_DAYS.' hari.', 'errors' => ['sampai' => ['Periode KPI paling panjang '.self::MAX_DAYS.' hari.']]], 422);
        }

        $assets = Aset::query();
        app(OrganizationScope::class)->asetQuery($assets, $request);
        foreach (['legal_entity_id', 'jenis_aset_id', 'lokasi_aset_id'] as $column) {
            if ($filter[$column] ?? null) {
                $assets->where('aset_tr_aset.'.$column, $filter[$column]);
            }
        }
        if ($filter['aset_id'] ?? null) {
            $assets->where('aset_tr_aset.id', $filter['aset_id']);
        }

        $result = $kpi->calculate($assets, $from, $until, $filter['kelompok'] ?? MaintenanceKpi::BY_ASSET);

        return response()->json(['data' => $result['baris'], 'meta' => [
            'dari' => $from->toDateString(),
            'sampai' => $until->copy()->subDay()->toDateString(),
            'zona_waktu' => $timezone,
            'kelompok' => $filter['kelompok'] ?? MaintenanceKpi::BY_ASSET,
            'total' => $result['total'],
        ]]);
    }
}
