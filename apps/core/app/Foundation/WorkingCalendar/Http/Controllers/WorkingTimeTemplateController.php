<?php

namespace App\Foundation\WorkingCalendar\Http\Controllers;

use App\Foundation\WorkingCalendar\Models\WorkingTimeLine;
use App\Foundation\WorkingCalendar\Models\WorkingTimeTemplate;
use App\Foundation\WorkingCalendar\Support\WorkingTimeIntervals;
use App\Http\Controllers\Controller;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkingTimeTemplateController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);

        $workspaceLegalEntity = app(CurrentWorkspace::class)->legalEntity($request, $membership);

        $selectedLegalEntityId = $workspaceLegalEntity?->id;

        $query = WorkingTimeTemplate::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('legal_entity_id', $selectedLegalEntityId)
            ->when(! $selectedLegalEntityId, fn ($query) => $query->whereRaw('1 = 0'));

        if ($request->boolean('incremental') || $request->wantsJson() || $request->is('api/*')) {
            if ($request->filled('record_id')) {
                $request->validate(['record_id' => ['required', 'ulid']]);
                $template = (clone $query)->whereKey($request->string('record_id')->toString())->firstOrFail();

                return response()->json(['data' => [$this->presentTemplate($template)], 'next_cursor' => null]);
            }

            return response()->json(IncrementalList::query(
                $request,
                $query->select(['id', 'code', 'name', 'legal_entity_id', 'is_active', 'version'])->toBase(),
                ['code' => 'code', 'name' => 'name'],
                ['code', 'name'],
                'code',
            ));
        }

        $currentLegalEntityData = $workspaceLegalEntity ? [
            'id' => $workspaceLegalEntity->id,
            'name' => $workspaceLegalEntity->name,
            'company_code' => $workspaceLegalEntity->legalEntity?->company_code,
        ] : null;

        return Inertia::render('foundation/working-calendar/working-time-templates', [
            'templates' => collect(),
            'currentLegalEntity' => $currentLegalEntityData,
            'canManage' => $request->user()?->can('manage-reference-data') ?? false,
        ]);
    }

    /** @return array<string, mixed> */
    private function presentTemplate(WorkingTimeTemplate $template): array
    {
        $template->loadMissing(['legalEntity.legalEntity', 'lines' => fn ($query) => $query->orderBy('day_of_week')->orderBy('from_time')]);

        return [
            'id' => $template->id,
            'code' => $template->code,
            'name' => $template->name,
            'description' => $template->description,
            'legal_entity_id' => $template->legal_entity_id,
            'legal_entity_name' => $template->legalEntity?->name,
            'company_code' => $template->legalEntity?->legalEntity?->company_code,
            'is_active' => (bool) $template->is_active,
            'version' => $template->version,
            'lines' => $template->lines->map(fn (WorkingTimeLine $l): array => [
                'id' => $l->id,
                'day_of_week' => (int) $l->day_of_week,
                'from_time' => $l->from_time ? substr((string) $l->from_time, 0, 5) : null,
                'to_time' => $l->to_time ? substr((string) $l->to_time, 0, 5) : null,
                'efficiency' => (float) $l->efficiency,
                'property' => $l->property,
                'closed_for_pickup' => (bool) $l->closed_for_pickup,
                'hours' => (float) $l->hours,
            ])->all(),
        ];
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);

        $legalEntityId = $this->activeCompanyId($request, $membership);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'legal_entity_id' => [
                'nullable',
                'ulid',
                Rule::in([$legalEntityId]),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $template = WorkingTimeTemplate::create([
            'tenant_id' => $membership->tenant_id,
            'legal_entity_id' => $legalEntityId,
            'code' => strtoupper(trim($validated['code'])),
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['data' => $this->presentTemplate($template->refresh())], 201);
        }

        return back()->with('success', 'Pola jam kerja berhasil dibuat.');
    }

    public function update(Request $request, WorkingTimeTemplate $template): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        $this->assertActiveCompany($request, $membership, $template);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'lines' => ['nullable', 'array'],
            'lines.*.day_of_week' => ['required_with:lines', 'integer', 'between:0,6'],
            'lines.*.from_time' => ['nullable', 'string', 'regex:'.WorkingTimeIntervals::TIME_PATTERN],
            'lines.*.to_time' => ['nullable', 'string', 'regex:'.WorkingTimeIntervals::TIME_PATTERN],
            'lines.*.efficiency' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.property' => ['nullable', 'string', 'max:50'],
            'lines.*.closed_for_pickup' => ['nullable', 'boolean'],
            'lines.*.hours' => ['nullable', 'numeric', 'between:0,24'],
        ], [
            'lines.*.from_time.regex' => 'Jam mulai ditulis JJ:MM, misalnya 08:00.',
            'lines.*.to_time.regex' => 'Jam selesai ditulis JJ:MM, misalnya 17:00 atau 24:00.',
            'lines.*.hours.between' => 'Jumlah jam dalam satu hari harus antara 0 dan 24.',
        ]);

        DB::transaction(function () use ($request, $template, $membership, $validated): void {
            RowVersion::claim($template, RowVersion::expected($request));
            $template->update([
                'code' => strtoupper(trim($validated['code'])),
                'name' => trim($validated['name']),
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? $template->is_active,
            ]);

            if (array_key_exists('lines', $validated) && is_array($validated['lines'])) {
                $this->syncLines($template, $membership->tenant_id, $validated['lines']);
            }
        });

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['data' => $this->presentTemplate($template->refresh())]);
        }

        return back()->with('success', 'Pola jam kerja berhasil diperbarui.');
    }

    public function destroy(Request $request, WorkingTimeTemplate $template): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        $this->assertActiveCompany($request, $membership, $template);

        DB::transaction(function () use ($request, $template): void {
            RowVersion::claim($template, RowVersion::expected($request));
            $template->delete();
        });

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['data' => ['archived' => true]]);
        }

        return back()->with('success', 'Pola jam kerja berhasil diarsipkan.');
    }

    public function copy(Request $request, WorkingTimeTemplate $template): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        $this->assertActiveCompany($request, $membership, $template);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
        ]);

        $newTemplate = DB::transaction(function () use ($template, $membership, $validated): WorkingTimeTemplate {
            $new = WorkingTimeTemplate::create([
                'tenant_id' => $membership->tenant_id,
                'legal_entity_id' => $template->legal_entity_id,
                'code' => strtoupper(trim($validated['code'])),
                'name' => trim($validated['name']),
                'description' => $template->description,
                'is_active' => true,
            ]);

            foreach ($template->lines as $line) {
                WorkingTimeLine::create([
                    'tenant_id' => $membership->tenant_id,
                    'working_time_template_id' => $new->id,
                    'day_of_week' => $line->day_of_week,
                    'from_time' => $line->from_time,
                    'to_time' => $line->to_time,
                    'efficiency' => $line->efficiency,
                    'property' => $line->property,
                    'closed_for_pickup' => $line->closed_for_pickup,
                    'hours' => $line->hours,
                ]);
            }

            return $new;
        });

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['data' => $this->presentTemplate($newTemplate->refresh())], 201);
        }

        return back()->with('success', 'Pola jam kerja berhasil diduplikasi.');
    }

    public function updateLines(Request $request, WorkingTimeTemplate $template): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        $this->assertActiveCompany($request, $membership, $template);

        $validated = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'lines.*.from_time' => ['nullable', 'string', 'regex:'.WorkingTimeIntervals::TIME_PATTERN],
            'lines.*.to_time' => ['nullable', 'string', 'regex:'.WorkingTimeIntervals::TIME_PATTERN],
            'lines.*.efficiency' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.property' => ['nullable', 'string', 'max:50'],
            'lines.*.closed_for_pickup' => ['nullable', 'boolean'],
            'lines.*.hours' => ['nullable', 'numeric', 'between:0,24'],
        ], [
            'lines.*.from_time.regex' => 'Jam mulai ditulis JJ:MM, misalnya 08:00.',
            'lines.*.to_time.regex' => 'Jam selesai ditulis JJ:MM, misalnya 17:00 atau 24:00.',
            'lines.*.hours.between' => 'Jumlah jam dalam satu hari harus antara 0 dan 24.',
        ]);

        // Baris jam milik pola ini; yang diklaim polanya, supaya dua penggantian baris tidak saling menimpa.
        DB::transaction(function () use ($request, $template, $membership, $validated): void {
            RowVersion::claim($template, RowVersion::expected($request));
            $this->syncLines($template, $membership->tenant_id, $validated['lines']);
        });

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['data' => $this->presentTemplate($template->refresh())]);
        }

        return back()->with('success', 'Baris jam kerja berhasil disimpan.');
    }

    private function activeCompanyId(Request $request, TenantMembership $membership): string
    {
        $company = app(CurrentWorkspace::class)->legalEntity($request, $membership);
        if ($company === null) {
            throw ValidationException::withMessages(['legal_entity_id' => 'Pilih perusahaan aktif sebelum menyimpan pola jam kerja.']);
        }

        return $company->id;
    }

    private function assertActiveCompany(Request $request, TenantMembership $membership, WorkingTimeTemplate $template): void
    {
        abort_unless($template->tenant_id === $membership->tenant_id
            && $template->legal_entity_id === $this->activeCompanyId($request, $membership), 404);
    }

    /**
     * @param  array<int, array{day_of_week: int, from_time?: ?string, to_time?: ?string, efficiency?: ?float, property?: ?string, closed_for_pickup?: ?bool, hours?: ?float}>  $lines
     */
    private function syncLines(WorkingTimeTemplate $template, string $tenantId, array $lines): void
    {
        $days = [];
        foreach ($lines as $index => $line) {
            $days[(int) $line['day_of_week']][$index] = $line;
        }
        $normalized = [];
        foreach ($days as $dayLines) {
            $closed = (bool) (reset($dayLines)['closed_for_pickup'] ?? false);
            foreach ($dayLines as $index => $line) {
                if ((bool) ($line['closed_for_pickup'] ?? false) !== $closed) {
                    throw ValidationException::withMessages(["lines.{$index}.closed_for_pickup" => 'Status tutup untuk pengambilan harus sama pada semua baris dalam satu hari.']);
                }
            }
            foreach (WorkingTimeIntervals::normalize($dayLines) as $index => $interval) {
                $normalized[] = [...$interval, 'day_of_week' => (int) $dayLines[$index]['day_of_week'], 'closed_for_pickup' => $closed];
            }
        }
        $template->lines()->delete();
        foreach ($normalized as $line) {
            if (! $line['closed_for_pickup'] && $line['from_time'] === null && $line['to_time'] === null && $line['hours'] === 0.0) {
                continue;
            }
            WorkingTimeLine::create([
                ...$line,
                'tenant_id' => $tenantId,
                'working_time_template_id' => $template->id,
            ]);
        }
    }
}
