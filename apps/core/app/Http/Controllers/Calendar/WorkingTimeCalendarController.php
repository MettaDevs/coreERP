<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Calendar\ComposeWorkingTimesService;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\WorkingTimeCalendar;
use App\Models\WorkingTimeCalendarDay;
use App\Models\WorkingTimeCalendarLine;
use App\Models\WorkingTimeLine;
use App\Models\WorkingTimeTemplate;
use App\Support\CurrentWorkspace;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkingTimeCalendarController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);

        $workspaceLegalEntity = app(CurrentWorkspace::class)->legalEntity($request, $membership);
        if (! $workspaceLegalEntity) {
            $workspaceLegalEntity = Organization::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('classification', 'legal_entity')
                ->where('status', 'active')
                ->first();
        }

        $selectedLegalEntityId = $workspaceLegalEntity?->id;

        $calendarsQuery = WorkingTimeCalendar::query()
            ->where('tenant_id', $membership->tenant_id)
            ->when(
                $selectedLegalEntityId,
                fn ($q) => $q->where(fn ($sub) => $sub->where('legal_entity_id', $selectedLegalEntityId)->orWhereNull('legal_entity_id'))
            )
            ->with(['baseCalendar', 'legalEntity.legalEntity'])
            ->orderBy('code');

        $calendarsCollection = $calendarsQuery->get();
        if ($calendarsCollection->isEmpty()) {
            $calendarsCollection = WorkingTimeCalendar::query()
                ->where('tenant_id', $membership->tenant_id)
                ->with(['baseCalendar', 'legalEntity.legalEntity'])
                ->orderBy('code')
                ->get();
        }

        $calendars = $calendarsCollection->map(fn (WorkingTimeCalendar $c): array => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'description' => $c->description,
            'base_calendar_id' => $c->base_calendar_id,
            'base_calendar_code' => $c->baseCalendar?->code,
            'base_calendar_name' => $c->baseCalendar?->name,
            'standard_work_hours' => (float) $c->standard_work_hours,
            'is_active' => (bool) $c->is_active,
            'legal_entity_id' => $c->legal_entity_id,
            'legal_entity_name' => $c->legalEntity?->name,
            'company_code' => $c->legalEntity?->legalEntity?->company_code,
        ]);

        $currentLegalEntityData = $workspaceLegalEntity ? [
            'id' => $workspaceLegalEntity->id,
            'name' => $workspaceLegalEntity->name,
            'company_code' => $workspaceLegalEntity->legalEntity?->company_code,
        ] : null;

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'data' => [
                    'calendars' => $calendars,
                    'current_legal_entity' => $currentLegalEntityData,
                ],
            ]);
        }

        return Inertia::render('settings/working-time-calendars', [
            'calendars' => $calendars,
            'currentLegalEntity' => $currentLegalEntityData,
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);

        $workspaceLegalEntity = app(CurrentWorkspace::class)->legalEntity($request, $membership);
        if (! $workspaceLegalEntity) {
            $workspaceLegalEntity = Organization::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('classification', 'legal_entity')
                ->where('status', 'active')
                ->first();
        }

        if (! $workspaceLegalEntity) {
            throw ValidationException::withMessages([
                'general' => 'Tidak ada entitas legal yang aktif pada sesi ini. Silakan buat atau pilih entitas legal terlebih dahulu di menu Organisasi sebelum membuat kalender kerja.',
            ]);
        }

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('working_time_calendars', 'code')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_calendar_id' => [
                'nullable',
                Rule::exists('working_time_calendars', 'id')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereNull('deleted_at'),
            ],
            'standard_work_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ]);

        $calendar = WorkingTimeCalendar::create([
            'tenant_id' => $membership->tenant_id,
            'legal_entity_id' => $workspaceLegalEntity->id,
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'base_calendar_id' => $validated['base_calendar_id'] ?? null,
            'standard_work_hours' => $validated['standard_work_hours'] ?? 8.00,
            'is_active' => true,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['data' => $calendar], 201);
        }

        return redirect()->route('working-time-calendars.index')
            ->with('success', "Kalender kerja {$calendar->code} berhasil dibuat.");
    }

    public function update(Request $request, WorkingTimeCalendar $calendar): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        abort_if($calendar->tenant_id !== $membership->tenant_id, 404);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('working_time_calendars', 'code')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereNull('deleted_at')
                    ->ignore($calendar->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_calendar_id' => [
                'nullable',
                Rule::exists('working_time_calendars', 'id')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereNull('deleted_at'),
                function ($attribute, $value, $fail) use ($calendar) {
                    if ($value === $calendar->id) {
                        $fail('Kalender tidak boleh menjadi kalender dasar untuk dirinya sendiri.');
                    }
                },
            ],
            'standard_work_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $calendar->update([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'base_calendar_id' => $validated['base_calendar_id'] ?? null,
            'standard_work_hours' => $validated['standard_work_hours'] ?? $calendar->standard_work_hours,
            'is_active' => $validated['is_active'] ?? $calendar->is_active,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['data' => $calendar]);
        }

        return redirect()->route('working-time-calendars.index')
            ->with('success', "Kalender kerja {$calendar->code} berhasil diperbarui.");
    }

    public function destroy(Request $request, WorkingTimeCalendar $calendar): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        abort_if($calendar->tenant_id !== $membership->tenant_id, 404);

        $code = $calendar->code;
        $calendar->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => "Kalender kerja {$code} berhasil diarsipkan."]);
        }

        return redirect()->route('working-time-calendars.index')
            ->with('success', "Kalender kerja {$code} berhasil diarsipkan.");
    }

    public function copy(Request $request, WorkingTimeCalendar $calendar): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        abort_if($calendar->tenant_id !== $membership->tenant_id, 404);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('working_time_calendars', 'code')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $newCalendar = DB::transaction(function () use ($calendar, $validated, $membership) {
            /** @var WorkingTimeCalendar $copied */
            $copied = WorkingTimeCalendar::create([
                'tenant_id' => $membership->tenant_id,
                'legal_entity_id' => $calendar->legal_entity_id,
                'code' => strtoupper($validated['code']),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? $calendar->description,
                'base_calendar_id' => $calendar->base_calendar_id,
                'standard_work_hours' => $calendar->standard_work_hours,
                'is_active' => true,
            ]);

            // Salin seluruh hari dan jam kerja
            $days = $calendar->days()->with('lines')->get();
            foreach ($days as $day) {
                /** @var WorkingTimeCalendarDay $newDay */
                $newDay = $copied->days()->create([
                    'tenant_id' => $membership->tenant_id,
                    'date' => $day->date,
                    'day_of_week' => $day->day_of_week,
                    'control' => $day->control,
                    'closed_for_pickup' => $day->closed_for_pickup,
                    'hours' => $day->hours,
                ]);

                foreach ($day->lines as $line) {
                    $newDay->lines()->create([
                        'tenant_id' => $membership->tenant_id,
                        'from_time' => $line->from_time,
                        'to_time' => $line->to_time,
                        'efficiency' => $line->efficiency,
                        'property' => $line->property,
                        'hours' => $line->hours,
                    ]);
                }
            }

            return $copied;
        });

        if ($request->wantsJson()) {
            return response()->json(['data' => $newCalendar], 201);
        }

        return redirect()->route('working-time-calendars.index')
            ->with('success', "Kalender kerja {$newCalendar->code} berhasil disalin.");
    }

    public function times(Request $request, ?WorkingTimeCalendar $calendar = null): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);

        $workspaceLegalEntity = app(CurrentWorkspace::class)->legalEntity($request, $membership);
        if (! $workspaceLegalEntity) {
            $workspaceLegalEntity = Organization::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('classification', 'legal_entity')
                ->where('status', 'active')
                ->first();
        }

        $allCalendars = WorkingTimeCalendar::query()
            ->where('tenant_id', $membership->tenant_id)
            ->when(
                $workspaceLegalEntity?->id,
                fn ($q) => $q->where(fn ($sub) => $sub->where('legal_entity_id', $workspaceLegalEntity->id)->orWhereNull('legal_entity_id'))
            )
            ->orderBy('code')
            ->get();

        if ($allCalendars->isEmpty()) {
            $allCalendars = WorkingTimeCalendar::query()
                ->where('tenant_id', $membership->tenant_id)
                ->orderBy('code')
                ->get();
        }

        /** @var WorkingTimeCalendar|null $selectedCalendar */
        $selectedCalendar = $calendar && $calendar->exists ? $calendar : null;

        // Jika calendar tidak ditentukan di URL, ambil dari query param atau kalender pertama
        if (! $selectedCalendar) {
            $calendarId = $request->query('calendar_id');
            if (is_string($calendarId) && $calendarId !== '') {
                $selectedCalendar = $allCalendars->firstWhere('id', $calendarId);
                if (! $selectedCalendar) {
                    $selectedCalendar = WorkingTimeCalendar::query()
                        ->where('tenant_id', $membership->tenant_id)
                        ->where('id', $calendarId)
                        ->first();
                }
            }

            if (! $selectedCalendar) {
                $selectedCalendar = $allCalendars->first();
            }
        }

        if ($selectedCalendar) {
            abort_if($selectedCalendar->tenant_id !== $membership->tenant_id, 404);
        }

        // Rentang tanggal default: bulan ini atau parameter dari request
        $from = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to = $request->query('to', Carbon::now()->endOfMonth()->format('Y-m-d'));

        $days = $selectedCalendar
            ? $selectedCalendar->days()
                ->whereBetween('date', [$from, $to])
                ->with(['lines' => fn ($q) => $q->orderBy('from_time')])
                ->orderBy('date')
                ->get()
                ->map(fn (WorkingTimeCalendarDay $d): array => [
                    'id' => $d->id,
                    'date' => $d->date->format('Y-m-d'),
                    'day_of_week' => (int) $d->day_of_week,
                    'control' => $d->control,
                    'closed_for_pickup' => (bool) $d->closed_for_pickup,
                    'hours' => (float) $d->hours,
                    'lines' => $d->lines->map(fn (WorkingTimeCalendarLine $l): array => [
                        'id' => $l->id,
                        'from_time' => $l->from_time ? substr((string) $l->from_time, 0, 5) : null,
                        'to_time' => $l->to_time ? substr((string) $l->to_time, 0, 5) : null,
                        'efficiency' => (float) $l->efficiency,
                        'property' => $l->property,
                        'hours' => (float) $l->hours,
                    ])->all(),
                ])
            : collect();

        // Daftar template pola jam kerja untuk pilihan wizard compose
        $templates = WorkingTimeTemplate::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('is_active', true)
            ->when(
                $workspaceLegalEntity?->id,
                fn ($q) => $q->where(fn ($sub) => $sub->where('legal_entity_id', $workspaceLegalEntity->id)->orWhereNull('legal_entity_id'))
            )
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        if ($templates->isEmpty()) {
            $templates = WorkingTimeTemplate::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);
        }

        $calendarData = $selectedCalendar ? [
            'id' => $selectedCalendar->id,
            'code' => $selectedCalendar->code,
            'name' => $selectedCalendar->name,
            'standard_work_hours' => (float) $selectedCalendar->standard_work_hours,
        ] : null;

        $allCalendarsData = $allCalendars->map(fn (WorkingTimeCalendar $c): array => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'standard_work_hours' => (float) $c->standard_work_hours,
        ])->values()->all();

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'data' => [
                    'calendar' => $calendarData,
                    'all_calendars' => $allCalendarsData,
                    'days' => $days,
                    'from' => $from,
                    'to' => $to,
                    'templates' => $templates,
                ],
            ]);
        }

        return Inertia::render('settings/working-time-calendar-times', [
            'calendar' => $calendarData,
            'allCalendars' => $allCalendarsData,
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'templates' => $templates,
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
        ]);
    }

    public function updateDay(
        Request $request,
        WorkingTimeCalendar $calendar,
        WorkingTimeCalendarDay $day
    ): RedirectResponse|JsonResponse {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        abort_if($calendar->tenant_id !== $membership->tenant_id || $day->working_time_calendar_id !== $calendar->id, 404);

        $validated = $request->validate([
            'control' => ['required', Rule::in(['open', 'closed'])],
            'closed_for_pickup' => ['nullable', 'boolean'],
            'lines' => ['nullable', 'array'],
            'lines.*.from_time' => ['nullable', 'date_format:H:i'],
            'lines.*.to_time' => ['nullable', 'date_format:H:i'],
            'lines.*.efficiency' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'lines.*.property' => ['nullable', 'string', 'max:50'],
            'lines.*.hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ]);

        DB::transaction(function () use ($day, $validated, $calendar) {
            $totalHours = 0.0;

            if (isset($validated['lines'])) {
                $day->lines()->delete();

                foreach ($validated['lines'] as $lineData) {
                    $hours = isset($lineData['hours']) ? (float) $lineData['hours'] : 0.0;
                    $totalHours += $hours;

                    $day->lines()->create([
                        'tenant_id' => $calendar->tenant_id,
                        'from_time' => $lineData['from_time'] ?? null,
                        'to_time' => $lineData['to_time'] ?? null,
                        'efficiency' => $lineData['efficiency'] ?? 100.00,
                        'property' => $lineData['property'] ?? null,
                        'hours' => $hours,
                    ]);
                }
            } else {
                $totalHours = (float) $day->hours;
            }

            $day->update([
                'control' => $validated['control'],
                'closed_for_pickup' => $validated['closed_for_pickup'] ?? $day->closed_for_pickup,
                'hours' => $validated['control'] === 'closed' ? 0.00 : $totalHours,
            ]);
        });

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Hari kerja berhasil diperbarui.']);
        }

        return back()->with('success', 'Hari kerja berhasil diperbarui.');
    }

    public function compose(
        Request $request,
        WorkingTimeCalendar $calendar,
        ComposeWorkingTimesService $service
    ): RedirectResponse|JsonResponse {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        abort_if($calendar->tenant_id !== $membership->tenant_id, 404);

        $validated = $request->validate([
            'template_id' => [
                'required',
                Rule::exists('working_time_templates', 'id')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereNull('deleted_at'),
            ],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);

        /** @var WorkingTimeTemplate $template */
        $template = WorkingTimeTemplate::query()->where('id', $validated['template_id'])->firstOrFail();

        $count = $service->compose(
            $calendar,
            $template,
            $validated['from_date'],
            $validated['to_date']
        );

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Jadwal kerja berhasil dibuat untuk {$count} hari.",
                'days_processed' => $count,
            ]);
        }

        return redirect()->route('working-time-calendars.times', [
            'calendar' => $calendar->id,
            'from' => $validated['from_date'],
            'to' => $validated['to_date'],
        ])->with('success', "Jadwal kerja berhasil dibuat untuk {$count} hari.");
    }

    public function composePage(Request $request): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);

        $workspaceLegalEntity = app(CurrentWorkspace::class)->legalEntity($request, $membership);
        if (! $workspaceLegalEntity) {
            $workspaceLegalEntity = Organization::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('classification', 'legal_entity')
                ->where('status', 'active')
                ->first();
        }

        $allCalendars = WorkingTimeCalendar::query()
            ->where('tenant_id', $membership->tenant_id)
            ->when(
                $workspaceLegalEntity?->id,
                fn ($q) => $q->where(fn ($sub) => $sub->where('legal_entity_id', $workspaceLegalEntity->id)->orWhereNull('legal_entity_id'))
            )
            ->orderBy('code')
            ->get();

        if ($allCalendars->isEmpty()) {
            $allCalendars = WorkingTimeCalendar::query()
                ->where('tenant_id', $membership->tenant_id)
                ->orderBy('code')
                ->get();
        }

        $templates = WorkingTimeTemplate::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('is_active', true)
            ->with(['lines' => fn ($q) => $q->orderBy('day_of_week')->orderBy('from_time')])
            ->orderBy('code')
            ->get();

        $selectedCalendarId = $request->query('calendar_id') ?? $allCalendars->first()?->id;
        $selectedTemplateId = $request->query('template_id') ?? $templates->first()?->id;

        $fromDate = $request->query('from', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $toDate = $request->query('to', Carbon::now()->endOfMonth()->format('Y-m-d'));

        /** @var array<int, array<string, mixed>> $calendarsData */
        $calendarsData = $allCalendars->map(fn (WorkingTimeCalendar $c): array => [
            'id' => (string) $c->id,
            'code' => (string) $c->code,
            'name' => (string) $c->name,
            'standard_work_hours' => (float) $c->standard_work_hours,
        ])->values()->all();

        /** @var array<int, array<string, mixed>> $templatesData */
        $templatesData = $templates->map(fn (WorkingTimeTemplate $t): array => [
            'id' => (string) $t->id,
            'code' => (string) $t->code,
            'name' => (string) $t->name,
            'lines' => $t->lines->map(fn (WorkingTimeLine $l): array => [
                'id' => (string) $l->id,
                'day_of_week' => (int) $l->day_of_week,
                'from_time' => $l->from_time ? substr((string) $l->from_time, 0, 5) : null,
                'to_time' => $l->to_time ? substr((string) $l->to_time, 0, 5) : null,
                'efficiency' => (float) $l->efficiency,
                'property' => $l->property,
                'hours' => (float) $l->hours,
                'closed_for_pickup' => (bool) $l->closed_for_pickup,
            ])->values()->all(),
        ])->values()->all();

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'data' => [
                    'calendars' => $calendarsData,
                    'templates' => $templatesData,
                    'selected_calendar_id' => $selectedCalendarId,
                    'selected_template_id' => $selectedTemplateId,
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                ],
            ]);
        }

        return Inertia::render('settings/compose-working-times', [
            'calendars' => $calendarsData,
            'templates' => $templatesData,
            'initialCalendarId' => $selectedCalendarId,
            'initialTemplateId' => $selectedTemplateId,
            'initialFromDate' => $fromDate,
            'initialToDate' => $toDate,
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
        ]);
    }

    public function composeFromPage(
        Request $request,
        ComposeWorkingTimesService $service
    ): RedirectResponse|JsonResponse {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);

        $validated = $request->validate([
            'calendar_id' => [
                'required',
                Rule::exists('working_time_calendars', 'id')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereNull('deleted_at'),
            ],
            'template_id' => [
                'required',
                Rule::exists('working_time_templates', 'id')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereNull('deleted_at'),
            ],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ], [
            'calendar_id.required' => 'Pilih kalender kerja terlebih dahulu.',
            'calendar_id.exists' => 'Kalender kerja yang dipilih tidak valid atau tidak ditemukan.',
            'template_id.required' => 'Pilih pola jam kerja terlebih dahulu.',
            'template_id.exists' => 'Pola jam kerja yang dipilih tidak valid atau tidak ditemukan.',
            'from_date.required' => 'Tanggal mulai wajib diisi.',
            'from_date.date' => 'Format tanggal mulai tidak valid.',
            'to_date.required' => 'Tanggal selesai wajib diisi.',
            'to_date.date' => 'Format tanggal selesai tidak valid.',
            'to_date.after_or_equal' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
        ]);

        /** @var WorkingTimeCalendar $calendar */
        $calendar = WorkingTimeCalendar::query()->where('id', $validated['calendar_id'])->firstOrFail();

        /** @var WorkingTimeTemplate $template */
        $template = WorkingTimeTemplate::query()->where('id', $validated['template_id'])->firstOrFail();

        abort_if($calendar->tenant_id !== $membership->tenant_id, 404);
        abort_if($template->tenant_id !== $membership->tenant_id, 404);

        $count = $service->compose(
            $calendar,
            $template,
            $validated['from_date'],
            $validated['to_date']
        );

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Jadwal kerja untuk kalender {$calendar->code} berhasil disusun dari pola {$template->code} ({$count} hari diproses).",
                'days_processed' => $count,
            ]);
        }

        return redirect()->route('working-time-calendars.times', [
            'calendar' => $calendar->id,
            'from' => $validated['from_date'],
            'to' => $validated['to_date'],
        ])->with('success', "Jadwal kerja untuk kalender {$calendar->code} berhasil disusun dari pola {$template->code} ({$count} hari diproses).");
    }
}
