<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\WorkingTimeLine;
use App\Models\WorkingTimeTemplate;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkingTimeTemplateController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $membership = $this->currentMembership($request);

        // Ambil entitas legal aktif dari sesi user (CurrentWorkspace) atau fallback organisasi pertama
        $workspaceLegalEntity = app(CurrentWorkspace::class)->legalEntity($request, $membership);
        if (! $workspaceLegalEntity) {
            $workspaceLegalEntity = Organization::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('classification', 'legal_entity')
                ->where('status', 'active')
                ->first();
        }

        $selectedLegalEntityId = $workspaceLegalEntity?->id;

        $templates = $selectedLegalEntityId
            ? WorkingTimeTemplate::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('legal_entity_id', $selectedLegalEntityId)
                ->with(['legalEntity.legalEntity', 'lines' => fn ($q) => $q->orderBy('day_of_week')->orderBy('from_time')])
                ->orderBy('code')
                ->get()
                ->map(fn (WorkingTimeTemplate $t): array => [
                    'id' => $t->id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'description' => $t->description,
                    'legal_entity_id' => $t->legal_entity_id,
                    'legal_entity_name' => $t->legalEntity?->name,
                    'company_code' => $t->legalEntity?->legalEntity?->company_code,
                    'is_active' => (bool) $t->is_active,
                    'lines' => $t->lines->map(fn (WorkingTimeLine $l): array => [
                        'id' => $l->id,
                        'day_of_week' => (int) $l->day_of_week,
                        'from_time' => $l->from_time ? substr((string) $l->from_time, 0, 5) : null,
                        'to_time' => $l->to_time ? substr((string) $l->to_time, 0, 5) : null,
                        'efficiency' => (float) $l->efficiency,
                        'property' => $l->property,
                        'closed_for_pickup' => (bool) $l->closed_for_pickup,
                        'hours' => (float) $l->hours,
                    ])->all(),
                ])
            : collect();

        $currentLegalEntityData = $workspaceLegalEntity ? [
            'id' => $workspaceLegalEntity->id,
            'name' => $workspaceLegalEntity->name,
            'company_code' => $workspaceLegalEntity->legalEntity?->company_code,
        ] : null;

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'data' => [
                    'templates' => $templates,
                    'current_legal_entity' => $currentLegalEntityData,
                ],
            ]);
        }

        return Inertia::render('settings/working-time-templates', [
            'templates' => $templates,
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

        $legalEntityId = $request->input('legal_entity_id', $workspaceLegalEntity?->id);

        abort_unless($legalEntityId, 422, 'Legal entity / perusahaan aktif tidak ditemukan pada sesi.');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'legal_entity_id' => [
                'nullable',
                'string',
                Rule::exists('organizations', 'id')
                    ->where('tenant_id', $membership->tenant_id)
                    ->where('classification', 'legal_entity'),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $finalLegalEntityId = $validated['legal_entity_id'] ?? $legalEntityId;

        $template = WorkingTimeTemplate::create([
            'tenant_id' => $membership->tenant_id,
            'legal_entity_id' => $finalLegalEntityId,
            'code' => strtoupper(trim($validated['code'])),
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['data' => $template], 201);
        }

        return back()->with('success', 'Pola jam kerja berhasil dibuat.');
    }

    public function update(Request $request, WorkingTimeTemplate $template): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        abort_unless($template->tenant_id === $membership->tenant_id, 404);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'lines' => ['nullable', 'array'],
            'lines.*.day_of_week' => ['required_with:lines', 'integer', 'between:0,6'],
            'lines.*.from_time' => ['nullable', 'string'],
            'lines.*.to_time' => ['nullable', 'string'],
            'lines.*.efficiency' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.property' => ['nullable', 'string', 'max:50'],
            'lines.*.closed_for_pickup' => ['nullable', 'boolean'],
            'lines.*.hours' => ['nullable', 'numeric'],
        ]);

        DB::transaction(function () use ($template, $membership, $validated): void {
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
            return response()->json(['data' => $template->load('lines')]);
        }

        return back()->with('success', 'Pola jam kerja berhasil diperbarui.');
    }

    public function destroy(Request $request, WorkingTimeTemplate $template): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        abort_unless($template->tenant_id === $membership->tenant_id, 404);

        $template->delete();

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['data' => ['archived' => true]]);
        }

        return back()->with('success', 'Pola jam kerja berhasil diarsipkan.');
    }

    public function copy(Request $request, WorkingTimeTemplate $template): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        abort_unless($template->tenant_id === $membership->tenant_id, 404);

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
            return response()->json(['data' => $newTemplate], 201);
        }

        return back()->with('success', 'Pola jam kerja berhasil diduplikasi.');
    }

    public function updateLines(Request $request, WorkingTimeTemplate $template): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->can('manage-reference-data'), 403);

        $membership = $this->currentMembership($request);
        abort_unless($template->tenant_id === $membership->tenant_id, 404);

        $validated = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'lines.*.from_time' => ['nullable', 'string'],
            'lines.*.to_time' => ['nullable', 'string'],
            'lines.*.efficiency' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.property' => ['nullable', 'string', 'max:50'],
            'lines.*.closed_for_pickup' => ['nullable', 'boolean'],
            'lines.*.hours' => ['nullable', 'numeric'],
        ]);

        DB::transaction(function () use ($template, $membership, $validated): void {
            $this->syncLines($template, $membership->tenant_id, $validated['lines']);
        });

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['data' => $template->load('lines')]);
        }

        return back()->with('success', 'Baris jam kerja berhasil disimpan.');
    }



    /**
     * @param  array<int, array{day_of_week: int, from_time?: ?string, to_time?: ?string, efficiency?: ?float, property?: ?string, closed_for_pickup?: ?bool, hours?: ?float}>  $lines
     */
    private function syncLines(WorkingTimeTemplate $template, string $tenantId, array $lines): void
    {
        $template->lines()->delete();

        foreach ($lines as $line) {
            $from = $this->normalizeTime($line['from_time'] ?? null);
            $to = $this->normalizeTime($line['to_time'] ?? null);
            $closed = ! empty($line['closed_for_pickup']);
            $hours = 0.0;

            // Abaikan baris kosong yang tidak punya jam dan bukan status closed
            if (! $closed && ! $from && ! $to && empty($line['hours'])) {
                continue;
            }

            if ($from && $to) {
                $start = $from === '24:00' ? 86400 : (strtotime("1970-01-01 $from:00 UTC") ?: 0);
                $end = $to === '24:00' ? 86400 : (strtotime("1970-01-01 $to:00 UTC") ?: 0);
                if ($end > $start) {
                    $hours = round(($end - $start) / 3600, 2);
                }
            } elseif (! empty($line['hours'])) {
                $hours = (float) $line['hours'];
            }

            WorkingTimeLine::create([
                'tenant_id' => $tenantId,
                'working_time_template_id' => $template->id,
                'day_of_week' => (int) $line['day_of_week'],
                'from_time' => $from,
                'to_time' => $to,
                'efficiency' => isset($line['efficiency']) ? (float) $line['efficiency'] : 100.0,
                'property' => $line['property'] ?? null,
                'closed_for_pickup' => $closed,
                'hours' => $hours,
            ]);
        }
    }

    private function normalizeTime(?string $time): ?string
    {
        if ($time === null || trim($time) === '') {
            return null;
        }

        $clean = trim($time);
        if ($clean === '24' || $clean === '24:00' || $clean === '24:00:00' || $clean === '2400') {
            return '24:00';
        }

        if (str_contains($clean, ':')) {
            $parts = explode(':', $clean);
            $h = (int) $parts[0];
            $m = (int) ($parts[1] ?? 0);
            if ($h === 24 && $m === 0) {
                return '24:00';
            }
            $h = min(23, max(0, $h));
            $m = min(59, max(0, $m));

            return sprintf('%02d:%02d', $h, $m);
        }

        if (preg_match('/^\d{1,2}$/', $clean)) {
            $h = min(23, max(0, (int) $clean));

            return sprintf('%02d:00', $h);
        }

        if (preg_match('/^\d{3}$/', $clean)) {
            $h = (int) substr($clean, 0, 1);
            $m = min(59, max(0, (int) substr($clean, 1, 2)));

            return sprintf('%02d:%02d', $h, $m);
        }

        if (preg_match('/^\d{4}$/', $clean)) {
            $h = (int) substr($clean, 0, 2);
            $m = min(59, max(0, (int) substr($clean, 2, 2)));
            if ($h === 24 && $m === 0) {
                return '24:00';
            }
            $h = min(23, max(0, $h));

            return sprintf('%02d:%02d', $h, $m);
        }

        return null;
    }
}
