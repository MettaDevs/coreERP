<?php

namespace App\Foundation\FiscalCalendar\Http\Controllers;

use App\Foundation\FiscalCalendar\Actions\FiscalCalendarService;
use App\Foundation\FiscalCalendar\Http\Requests\FiscalCalendarRequest;
use App\Foundation\FiscalCalendar\Http\Requests\FiscalYearRequest;
use App\Foundation\FiscalCalendar\Models\FiscalCalendar;
use App\Foundation\FiscalCalendar\Models\FiscalPeriod;
use App\Foundation\FiscalCalendar\Models\FiscalYear;
use App\Http\Controllers\Controller;
use App\Platform\Organization\Models\LegalEntity;
use App\Platform\Organization\Models\Organization;
use App\Support\Modules\Contracts\RowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The administration surface for fiscal calendars.
 *
 * Every write goes through FiscalCalendarService because the non-overlap and full-coverage invariants live there
 * rather than in the schema; writing to the models directly would bypass them.
 */
class FiscalCalendarController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);
        $calendars = FiscalCalendar::query()
            ->where('tenant_id', $membership->tenant_id)
            ->with(['years' => fn ($query) => $query->orderBy('starts_on')->with(['periods' => fn ($periods) => $periods->orderBy('ordinal')])])
            ->orderBy('code')
            ->get()
            ->map(fn (FiscalCalendar $calendar): array => [
                'id' => $calendar->id,
                'code' => $calendar->code,
                'name' => $calendar->name,
                'version' => $calendar->version,
                'years' => $calendar->years->map(fn (FiscalYear $year): array => [
                    'id' => $year->id,
                    'name' => $year->name,
                    'starts_on' => $year->starts_on->toDateString(),
                    'ends_on' => $year->ends_on->toDateString(),
                    'periods' => $year->periods->map(fn (FiscalPeriod $period): array => [
                        'ordinal' => $period->ordinal,
                        'name' => $period->name,
                        'starts_on' => $period->starts_on->toDateString(),
                        'ends_on' => $period->ends_on->toDateString(),
                        // `->all()` pada kedua map bersarang: `Collection` tidak kovarian, jadi
                        // koleksi berisi bentuk yang lebih sempit tidak diterima sebagai koleksi
                        // berisi bentuk yang lebih lebar. Array biasa tidak punya batasan itu, dan
                        // yang dikirim ke JSON tetap sama persis.
                    ])->all(),
                ])->all(),
            ]);

        $legalEntities = Organization::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('classification', 'legal_entity')
            ->with('legalEntity')
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
                'company_code' => $organization->legalEntity?->company_code,
                'fiscal_calendar_id' => $organization->legalEntity?->fiscal_calendar_id,
                'version' => $organization->legalEntity?->version,
            ]);

        if ($request->is('api/*')) {
            return response()->json(['data' => ['calendars' => $calendars, 'legal_entities' => $legalEntities]]);
        }

        return Inertia::render('foundation/fiscal-calendar/fiscal-calendars', [
            'canManage' => $request->user()->can('manage-number-sequences'),
            'calendars' => $calendars,
            'legalEntities' => $legalEntities,
        ]);
    }

    public function store(FiscalCalendarRequest $request): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        $calendar = FiscalCalendar::query()->create([
            'tenant_id' => $membership->tenant_id,
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
        ]);

        return $this->respond($request, ['id' => $calendar->id], 'Kalender fiskal dibuat.');
    }

    public function storeYear(FiscalYearRequest $request, FiscalCalendar $calendar, FiscalCalendarService $service): JsonResponse|RedirectResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($calendar->tenant_id === $membership->tenant_id, 404);

        $startsOn = $request->string('starts_on')->toString();
        $periods = $request->input('periods')
            ?? $service->monthlyPeriods($startsOn, $request->integer('months'));
        $endsOn = end($periods)['ends_on'];

        // Tahun fiskal tidak berversi sendiri; yang diklaim kalendernya, record yang dibuka pengguna. Tahun
        // yang ditolak validasi layanan ikut membatalkan klaimnya.
        $year = DB::transaction(function () use ($request, $calendar, $service, $startsOn, $endsOn, $periods): FiscalYear {
            RowVersion::claim($calendar, RowVersion::expected($request));

            return $service->defineYear($calendar, $request->string('name')->toString(), $startsOn, $endsOn, $periods);
        });

        return $this->respond($request, ['id' => $year->id], 'Tahun fiskal ditambahkan.');
    }

    public function assign(Request $request, FiscalCalendar $calendar): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->can('manage-number-sequences'), 403);
        $membership = $this->currentMembership($request);
        abort_unless($calendar->tenant_id === $membership->tenant_id, 404);
        $data = $request->validate(['legal_entity_id' => ['required', 'string']]);

        // The legal entity must belong to the same tenant, otherwise a calendar could be attached across the
        // isolation boundary and silently decide another tenant's document periods.
        $organization = Organization::query()
            ->whereKey($data['legal_entity_id'])
            ->where('tenant_id', $membership->tenant_id)
            ->where('classification', 'legal_entity')
            ->firstOrFail();

        // Yang berubah baris entitas legalnya, jadi versi yang dikirim adalah versi entitas legal itu.
        DB::transaction(function () use ($request, $organization, $calendar): void {
            RowVersion::claim(LegalEntity::query()->whereKey($organization->id), RowVersion::expected($request));
            LegalEntity::query()->whereKey($organization->id)->update(['fiscal_calendar_id' => $calendar->id]);
        });

        return $this->respond($request, ['legal_entity_id' => $organization->id], 'Kalender fiskal ditetapkan.');
    }

    /** @param array<string, mixed> $data */
    private function respond(Request $request, array $data, string $message): JsonResponse|RedirectResponse
    {
        return $request->is('api/*')
            ? response()->json(['data' => $data])
            : back()->with('status', $message);
    }
}
