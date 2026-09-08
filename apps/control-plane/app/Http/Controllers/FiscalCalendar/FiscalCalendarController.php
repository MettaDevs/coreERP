<?php

namespace App\Http\Controllers\FiscalCalendar;

use App\Actions\FiscalCalendar\FiscalCalendarService;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalCalendar\FiscalCalendarRequest;
use App\Http\Requests\FiscalCalendar\FiscalYearRequest;
use App\Models\FiscalCalendar;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\LegalEntity;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ]);

        if ($request->is('api/*')) {
            return response()->json(['data' => ['calendars' => $calendars, 'legal_entities' => $legalEntities]]);
        }

        return Inertia::render('settings/fiscal-calendars', [
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

        $year = $service->defineYear($calendar, $request->string('name')->toString(), $startsOn, $endsOn, $periods);

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

        LegalEntity::query()->whereKey($organization->id)->update(['fiscal_calendar_id' => $calendar->id]);

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
