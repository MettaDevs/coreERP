<?php

namespace App\Foundation\WorkingCalendar\Http\Controllers;

use App\Foundation\WorkingCalendar\Actions\ComposeWorkingTimesService;
use App\Foundation\WorkingCalendar\Models\WorkingTimeCalendar;
use App\Foundation\WorkingCalendar\Models\WorkingTimeCalendarDay;
use App\Foundation\WorkingCalendar\Models\WorkingTimeCalendarLine;
use App\Foundation\WorkingCalendar\Models\WorkingTimeLine;
use App\Foundation\WorkingCalendar\Models\WorkingTimeTemplate;
use App\Http\Controllers\Controller;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Organization\Models\Organization;
use App\Platform\Tenant\Models\TenantMembership;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkingTimeCalendarController extends Controller
{
    /** Jam `HH:MM` 00:00–23:59, atau `24:00` untuk akhir hari — bentuk yang juga disimpan pola jam kerja. */
    private const TIME_PATTERN = '/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/';

    private const CHUNK = 500;

    public function index(Request $request): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);
        $legalEntity = $this->legalEntity($request, $membership);

        $calendars = $legalEntity
            ? $this->calendarsOf($membership, $legalEntity)
                ->with(['baseCalendar', 'legalEntity.legalEntity'])
                ->get()
                ->map(fn (WorkingTimeCalendar $c): array => [
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
                    'version' => $c->version,
                ])
            : collect();

        $currentLegalEntityData = $legalEntity ? [
            'id' => $legalEntity->id,
            'name' => $legalEntity->name,
            'company_code' => $legalEntity->legalEntity?->company_code,
        ] : null;

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'data' => [
                    'calendars' => $calendars,
                    'current_legal_entity' => $currentLegalEntityData,
                ],
            ]);
        }

        return Inertia::render('foundation/working-calendar/working-time-calendars', [
            'calendars' => $calendars,
            'currentLegalEntity' => $currentLegalEntityData,
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        $legalEntity = $this->legalEntity($request, $membership);

        if (! $legalEntity) {
            throw ValidationException::withMessages([
                'general' => 'Tidak ada entitas legal yang aktif pada sesi ini. Silakan buat atau pilih entitas legal terlebih dahulu di menu Organisasi sebelum membuat kalender kerja.',
            ]);
        }

        $this->normalizeCode($request);
        $validated = $request->validate([
            'code' => $this->codeRules($membership, $legalEntity->id),
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_calendar_id' => [
                'nullable',
                Rule::exists('working_time_calendars', 'id')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereNull('deleted_at'),
            ],
            'standard_work_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ], $this->codeMessages());

        $calendar = WorkingTimeCalendar::create([
            'tenant_id' => $membership->tenant_id,
            'legal_entity_id' => $legalEntity->id,
            'code' => $validated['code'],
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

        $this->normalizeCode($request);
        $validated = $request->validate([
            'code' => $this->codeRules($membership, $calendar->legal_entity_id, $calendar->id),
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
        ], $this->codeMessages());

        DB::transaction(function () use ($request, $calendar, $validated): void {
            RowVersion::claim($calendar, RowVersion::expected($request));
            $calendar->update([
                'code' => $validated['code'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'base_calendar_id' => $validated['base_calendar_id'] ?? null,
                'standard_work_hours' => $validated['standard_work_hours'] ?? $calendar->standard_work_hours,
                'is_active' => $validated['is_active'] ?? $calendar->is_active,
            ]);
        });

        if ($request->wantsJson()) {
            return response()->json(['data' => $calendar->refresh()]);
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
        DB::transaction(function () use ($request, $calendar): void {
            RowVersion::claim($calendar, RowVersion::expected($request));
            $calendar->delete();
        });

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

        $this->normalizeCode($request);
        $validated = $request->validate([
            'code' => $this->codeRules($membership, $calendar->legal_entity_id),
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
        ], $this->codeMessages());

        $newCalendar = DB::transaction(function () use ($calendar, $validated, $membership): WorkingTimeCalendar {
            $copied = WorkingTimeCalendar::create([
                'tenant_id' => $membership->tenant_id,
                'legal_entity_id' => $calendar->legal_entity_id,
                'code' => $validated['code'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? $calendar->description,
                'base_calendar_id' => $calendar->base_calendar_id,
                'standard_work_hours' => $calendar->standard_work_hours,
                'is_active' => true,
            ]);

            $this->copyDays($calendar, $copied);

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
        $legalEntity = $this->legalEntity($request, $membership);
        $allCalendars = $legalEntity ? $this->calendarsOf($membership, $legalEntity)->get() : collect();

        /** @var WorkingTimeCalendar|null $selectedCalendar */
        $selectedCalendar = $calendar && $calendar->exists ? $calendar : null;

        // Jika calendar tidak ditentukan di URL, ambil dari query param atau kalender pertama
        if (! $selectedCalendar) {
            $calendarId = $request->query('calendar_id');
            if (is_string($calendarId) && $calendarId !== '') {
                $selectedCalendar = $allCalendars->firstWhere('id', $calendarId)
                    ?? WorkingTimeCalendar::query()
                        ->where('tenant_id', $membership->tenant_id)
                        ->where('id', $calendarId)
                        ->first();
            }

            $selectedCalendar ??= $allCalendars->first();
        }

        if ($selectedCalendar) {
            abort_if($selectedCalendar->tenant_id !== $membership->tenant_id, 404);
        }

        [$from, $to] = $this->period($request);

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

        // Pola jam kerja untuk dialog penyusunan: milik entitas legal kalender yang dibuka.
        $templates = $this->templatesOf($membership, $selectedCalendar->legal_entity_id ?? $legalEntity?->id)
            ->get(['id', 'code', 'name']);

        $calendarData = $selectedCalendar ? [
            'id' => $selectedCalendar->id,
            'code' => $selectedCalendar->code,
            'name' => $selectedCalendar->name,
            'standard_work_hours' => (float) $selectedCalendar->standard_work_hours,
            'version' => $selectedCalendar->version,
        ] : null;

        $allCalendarsData = $allCalendars->map(fn (WorkingTimeCalendar $c): array => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'standard_work_hours' => (float) $c->standard_work_hours,
            'version' => $c->version,
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

        return Inertia::render('foundation/working-calendar/working-time-calendar-times', [
            'calendar' => $calendarData,
            'allCalendars' => $allCalendarsData,
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'templates' => $templates,
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
        ]);
    }

    /**
     * Ubah satu hari: dibuka atau ditutup, tutup pengambilan, dan (opsional) jam kerjanya.
     *
     * Jumlah jam dihitung di sini dari jam mulai dan selesai, bukan diambil dari kiriman
     * pengguna — sama seperti pola jam kerja. Hari yang ditutup berjumlah nol jam tetapi baris
     * jamnya tetap tersimpan, sehingga membukanya kembali memulihkan jumlah jam dari baris itu.
     */
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
            'lines' => ['nullable', 'array', 'max:24'],
            'lines.*.from_time' => ['nullable', 'string', 'regex:'.self::TIME_PATTERN],
            'lines.*.to_time' => ['nullable', 'string', 'regex:'.self::TIME_PATTERN],
            'lines.*.efficiency' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'lines.*.property' => ['nullable', 'string', 'max:50'],
            'lines.*.hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ], [
            'lines.*.from_time.regex' => 'Jam mulai ditulis JJ:MM, misalnya 08:00.',
            'lines.*.to_time.regex' => 'Jam selesai ditulis JJ:MM, misalnya 17:00 atau 24:00.',
        ]);

        $lines = isset($validated['lines']) ? $this->normalizeLines($validated['lines']) : null;

        // Hari dan barisnya milik kalender; yang diklaim kalendernya, record yang dibuka pengguna.
        DB::transaction(function () use ($request, $day, $validated, $calendar, $lines): void {
            RowVersion::claim($calendar, RowVersion::expected($request));
            if ($lines !== null) {
                $day->lines()->delete();
                $now = now();
                WorkingTimeCalendarLine::query()->insert(array_map(fn (array $line): array => [
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $calendar->tenant_id,
                    'working_time_calendar_day_id' => $day->id,
                    ...$line,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $lines));
                $workHours = array_sum(array_column($lines, 'hours'));
            } else {
                $workHours = (float) $day->lines()->sum('hours');
            }

            $day->update([
                'control' => $validated['control'],
                'closed_for_pickup' => $validated['closed_for_pickup'] ?? $day->closed_for_pickup,
                'hours' => $validated['control'] === 'closed' ? 0.0 : $workHours,
            ]);
        });

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Hari kerja berhasil diperbarui.', 'version' => $calendar->refresh()->version]);
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

        return $this->composeCalendar($request, $membership, $calendar, $service);
    }

    public function composePage(Request $request): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);
        $legalEntity = $this->legalEntity($request, $membership);
        $allCalendars = $legalEntity ? $this->calendarsOf($membership, $legalEntity)->get() : collect();

        $templates = $this->templatesOf($membership, $legalEntity?->id)
            ->with(['lines' => fn ($q) => $q->orderBy('day_of_week')->orderBy('from_time')])
            ->get();

        $selectedCalendarId = $allCalendars->firstWhere('id', $request->query('calendar_id'))->id ?? $allCalendars->first()?->id;
        $selectedTemplateId = $templates->firstWhere('id', $request->query('template_id'))->id ?? $templates->first()?->id;
        [$fromDate, $toDate] = $this->period($request);

        /** @var array<int, array<string, mixed>> $calendarsData */
        $calendarsData = $allCalendars->map(fn (WorkingTimeCalendar $c): array => [
            'id' => (string) $c->id,
            'code' => (string) $c->code,
            'name' => (string) $c->name,
            'standard_work_hours' => (float) $c->standard_work_hours,
            'version' => (int) $c->version,
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

        return Inertia::render('foundation/working-calendar/compose-working-times', [
            'calendars' => $calendarsData,
            'templates' => $templatesData,
            'initialCalendarId' => $selectedCalendarId,
            'initialTemplateId' => $selectedTemplateId,
            'initialFromDate' => $fromDate,
            'initialToDate' => $toDate,
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
                'string',
                Rule::exists('working_time_calendars', 'id')
                    ->where('tenant_id', $membership->tenant_id)
                    ->whereNull('deleted_at'),
            ],
        ], [
            'calendar_id.required' => 'Pilih kalender kerja terlebih dahulu.',
            'calendar_id.exists' => 'Kalender kerja yang dipilih tidak valid atau tidak ditemukan.',
        ]);

        $calendar = WorkingTimeCalendar::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('id', $validated['calendar_id'])
            ->firstOrFail();

        return $this->composeCalendar($request, $membership, $calendar, $service);
    }

    /**
     * Satu jalur penyusunan untuk kedua pintu: dialog di halaman jadwal dan halaman "Jadwal dari
     * pola". Pola jam kerjanya wajib milik tenant dan entitas legal yang sama dengan kalendernya,
     * dan masih aktif; rentangnya dibatasi di sini supaya pengguna menerima pesan, bukan galat.
     */
    private function composeCalendar(
        Request $request,
        TenantMembership $membership,
        WorkingTimeCalendar $calendar,
        ComposeWorkingTimesService $service
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'template_id' => [
                'required',
                'string',
                Rule::exists('working_time_templates', 'id')
                    ->where('tenant_id', $membership->tenant_id)
                    ->where('legal_entity_id', $calendar->legal_entity_id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ], [
            'template_id.required' => 'Pilih pola jam kerja terlebih dahulu.',
            'template_id.exists' => 'Pola jam kerja yang dipilih tidak aktif atau bukan milik entitas legal kalender ini.',
            'from_date.required' => 'Tanggal mulai wajib diisi.',
            'from_date.date_format' => 'Format tanggal mulai tidak valid.',
            'to_date.required' => 'Tanggal selesai wajib diisi.',
            'to_date.date_format' => 'Format tanggal selesai tidak valid.',
            'to_date.after_or_equal' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.',
        ]);

        if (Carbon::parse($validated['from_date'])->diffInDays(Carbon::parse($validated['to_date'])) > ComposeWorkingTimesService::MAX_DAYS) {
            throw ValidationException::withMessages([
                'to_date' => 'Rentang tanggal paling panjang 3 tahun. Susun jadwalnya dalam beberapa bagian.',
            ]);
        }

        $template = WorkingTimeTemplate::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('id', $validated['template_id'])
            ->firstOrFail();

        // Penyusunan mengganti hari-hari kalender; yang diklaim kalendernya.
        $count = DB::transaction(function () use ($request, $calendar, $template, $validated, $service): int {
            RowVersion::claim($calendar, RowVersion::expected($request));

            return $service->compose($calendar, $template, $validated['from_date'], $validated['to_date']);
        });
        $message = "Jadwal kerja kalender {$calendar->code} disusun dari pola {$template->code} untuk {$count} hari.";

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'days_processed' => $count,
                'version' => $calendar->refresh()->version,
            ]);
        }

        return redirect()->route('working-time-calendars.times', [
            'calendar' => $calendar->id,
            'from' => $validated['from_date'],
            'to' => $validated['to_date'],
        ])->with('success', $message);
    }

    /**
     * Entitas legal yang sedang dipakai pengguna, dengan cadangan yang sama seperti pola jam
     * kerja: bila sesi belum memilih, entitas legal aktif pertama milik tenant.
     */
    private function legalEntity(Request $request, TenantMembership $membership): ?Organization
    {
        return app(CurrentWorkspace::class)->legalEntity($request, $membership)
            ?? Organization::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('classification', 'legal_entity')
                ->where('status', 'active')
                ->first();
    }

    /**
     * Kalender milik satu entitas legal. Tidak ada cadangan "tampilkan semua kalender tenant"
     * bila hasilnya kosong: cadangan itu memperlihatkan kalender entitas lain kepada entitas
     * yang belum punya kalender, lalu menyembunyikannya lagi begitu kalender pertamanya dibuat.
     *
     * @return Builder<WorkingTimeCalendar>
     */
    private function calendarsOf(TenantMembership $membership, Organization $legalEntity): Builder
    {
        return WorkingTimeCalendar::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->orderBy('code');
    }

    /** @return Builder<WorkingTimeTemplate> */
    private function templatesOf(TenantMembership $membership, ?string $legalEntityId): Builder
    {
        return WorkingTimeTemplate::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('legal_entity_id', $legalEntityId)
            ->where('is_active', true)
            ->orderBy('code');
    }

    /** Kode disimpan huruf besar, jadi keunikannya juga diperiksa dalam huruf besar. */
    private function normalizeCode(Request $request): void
    {
        if ($request->has('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }
    }

    /** @return list<mixed> */
    private function codeRules(TenantMembership $membership, ?string $legalEntityId, ?string $ignoreId = null): array
    {
        $unique = Rule::unique('working_time_calendars', 'code')
            ->where('tenant_id', $membership->tenant_id)
            ->where('legal_entity_id', $legalEntityId)
            ->whereNull('deleted_at');

        return ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', $ignoreId ? $unique->ignore($ignoreId) : $unique];
    }

    /** @return array<string, string> */
    private function codeMessages(): array
    {
        return [
            'code.unique' => 'Kode ini sudah dipakai kalender kerja lain di entitas legal ini.',
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, garis bawah, dan tanda hubung.',
        ];
    }

    /**
     * Rentang tanggal dari query `from`/`to`. Nilai yang bukan tanggal diganti bulan berjalan,
     * bukan diteruskan ke database — sebelumnya `?from=abc` berakhir sebagai galat server.
     *
     * @return array{0: string, 1: string}
     */
    private function period(Request $request): array
    {
        $from = $this->dateOrNull($request->query('from')) ?? Carbon::now()->startOfMonth();
        $to = $this->dateOrNull($request->query('to')) ?? Carbon::now()->endOfMonth();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /** Tanggal `YYYY-MM-DD` yang benar-benar ada, atau null; 2026-02-31 bukan 3 Maret. */
    private function dateOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];

        return checkdate($month, $day, $year) ? Carbon::createFromDate($year, $month, $day)->startOfDay() : null;
    }

    /**
     * Rapikan baris jam satu hari: jam dihitung dari jam mulai dan selesai, jam terbalik atau
     * bertumpuk ditolak, dan jumlahnya tidak boleh melebihi 24 jam. Baris tanpa jam mulai dan
     * selesai tetap boleh — ia membawa jumlah jam seperti baris pola jam kerja.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array{from_time: ?string, to_time: ?string, efficiency: float, property: ?string, hours: float}>
     */
    private function normalizeLines(array $lines): array
    {
        $errors = [];
        $normalized = [];
        $ranges = [];

        foreach (array_values($lines) as $index => $line) {
            $from = $line['from_time'] ?? null;
            $to = $line['to_time'] ?? null;

            if (($from === null) !== ($to === null)) {
                $errors["lines.{$index}.to_time"] = 'Isi jam mulai dan jam selesai, atau kosongkan keduanya.';

                continue;
            }

            $hours = (float) ($line['hours'] ?? 0);

            if ($from !== null && $to !== null) {
                $start = $this->minutes($from);
                $end = $this->minutes($to);

                if ($end <= $start) {
                    $errors["lines.{$index}.to_time"] = 'Jam selesai harus setelah jam mulai.';

                    continue;
                }

                $hours = round(($end - $start) / 60, 2);
                $ranges[] = ['index' => $index, 'start' => $start, 'end' => $end];
            }

            $normalized[] = [
                'from_time' => $from,
                'to_time' => $to,
                'efficiency' => (float) ($line['efficiency'] ?? 100),
                'property' => $line['property'] ?? null,
                'hours' => $hours,
            ];
        }

        usort($ranges, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i]['start'] < $ranges[$i - 1]['end']) {
                $errors["lines.{$ranges[$i]['index']}.from_time"] = 'Rentang jam ini bertumpuk dengan baris lain.';
            }
        }

        if ($errors === [] && array_sum(array_column($normalized, 'hours')) > 24) {
            $errors['lines'] = 'Jumlah jam dalam satu hari tidak boleh lebih dari 24.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /** Salin seluruh hari dan jam kerja satu kalender ke kalender lain, dengan perintah massal. */
    private function copyDays(WorkingTimeCalendar $source, WorkingTimeCalendar $target): void
    {
        $now = now();
        $dayIds = [];
        $days = [];

        foreach ($source->days()->toBase()->get(['id', 'date', 'day_of_week', 'control', 'closed_for_pickup', 'hours']) as $day) {
            $dayIds[$day->id] = (string) Str::ulid();
            $days[] = [
                'id' => $dayIds[$day->id],
                'tenant_id' => $target->tenant_id,
                'working_time_calendar_id' => $target->id,
                'date' => $day->date,
                'day_of_week' => $day->day_of_week,
                'control' => $day->control,
                'closed_for_pickup' => $day->closed_for_pickup,
                'hours' => $day->hours,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($days, self::CHUNK) as $chunk) {
            WorkingTimeCalendarDay::query()->insert($chunk);
        }

        $lines = [];
        $sourceLines = WorkingTimeCalendarLine::query()
            ->whereIn('working_time_calendar_day_id', array_keys($dayIds) ?: [''])
            ->toBase()
            ->get(['working_time_calendar_day_id', 'from_time', 'to_time', 'efficiency', 'property', 'hours']);

        foreach ($sourceLines as $line) {
            $lines[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $target->tenant_id,
                'working_time_calendar_day_id' => $dayIds[$line->working_time_calendar_day_id],
                'from_time' => $line->from_time,
                'to_time' => $line->to_time,
                'efficiency' => $line->efficiency,
                'property' => $line->property,
                'hours' => $line->hours,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($lines, self::CHUNK) as $chunk) {
            WorkingTimeCalendarLine::query()->insert($chunk);
        }
    }
}
