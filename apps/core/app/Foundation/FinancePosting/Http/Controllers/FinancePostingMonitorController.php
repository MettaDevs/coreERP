<?php

declare(strict_types=1);

namespace App\Foundation\FinancePosting\Http\Controllers;

use App\Foundation\FinancePosting\Models\FinancePosting;
use App\Foundation\FinancePosting\Models\FinancePostingDelivery;
use App\Foundation\FinancePosting\Models\FinancePostingEvent;
use App\Foundation\FinancePosting\Support\PostingPublisher;
use App\Foundation\FinancePosting\Support\StatusPostingBerubah;
use App\Http\Controllers\Controller;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Identity\Models\User;
use App\Platform\Integration\Models\IntegrationClient;
use App\Platform\Modules\Contracts\InvalidPosting;
use App\Platform\Modules\Models\CoreApp;
use App\Platform\Organization\Models\Organization;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Layar pantau posting finance (TODO area 7): daftar, detail jurnal beserta masalahnya, riwayat,
 * dan tindak lanjut posting yang tertahan atau perlu dibukukan manual — tanpa membuka database.
 *
 * Hanya owner dan admin, termasuk untuk melihat: isinya jurnal keuangan tenant. Core belum punya
 * katalog izin sendiri, jadi izin terpisah untuk melihat dan menindak (TODO 7.4) menunggu katalog
 * itu. `canManage` tetap dikirim supaya halaman tidak perlu berubah ketika izinnya dipisah.
 *
 * Tidak ada aksi mengubah tanggal atau nilai (TODO 7.3.3, K-17): posting yang keliru diperbaiki
 * dengan posting koreksi dari dokumen sumbernya, bukan disunting di sini.
 */
final class FinancePostingMonitorController extends Controller
{
    private const PER_PAGE = 50;

    /** Urutan tampil: yang perlu ditindaklanjuti lebih dulu. */
    private const STATUS = [
        FinancePosting::HELD, FinancePosting::PENDING, FinancePosting::REJECTED, FinancePosting::MANUAL, FinancePosting::POSTED,
    ];

    public function index(Request $request): Response
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::FINANCE_POSTING_READ);
        $tenant = $membership->tenant_id;
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUS)],
            'posting_type' => ['nullable', 'string', 'max:80'],
            'legal_entity_id' => ['nullable', 'string', 'max:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $keyword = trim((string) ($filter['q'] ?? ''));
        $pattern = '%'.addcslashes($keyword, '\\%_').'%';
        $legalEntity = $this->legalEntities($tenant);

        $postings = FinancePosting::query()
            ->where('tenant_id', $tenant)
            ->when($keyword !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('posting_id', 'ilike', $pattern)
                ->orWhere('source_number', 'ilike', $pattern)))
            ->when($filter['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filter['posting_type'] ?? null, fn ($query, $type) => $query->where('posting_type', $type))
            ->when($filter['legal_entity_id'] ?? null, fn ($query, $id) => $query->where('legal_entity_id', $id))
            ->when($filter['from'] ?? null, fn ($query, $from) => $query->whereDate('posting_date', '>=', $from))
            ->when($filter['to'] ?? null, fn ($query, $until) => $query->whereDate('posting_date', '<=', $until))
            ->orderByDesc('posting_date')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (FinancePosting $posting): array => $this->present($posting, $legalEntity));

        return Inertia::render('foundation/finance-posting/finance-postings', [
            'canManage' => $membership->hasCorePermission(CoreSecurityCatalog::FINANCE_POSTING_PROCESS),
            'filters' => [
                'q' => $keyword === '' ? null : $keyword,
                'status' => $filter['status'] ?? null,
                'posting_type' => $filter['posting_type'] ?? null,
                'legal_entity_id' => $filter['legal_entity_id'] ?? null,
                'from' => $filter['from'] ?? null,
                'to' => $filter['to'] ?? null,
            ],
            'statuses' => self::STATUS,
            // Jenis yang benar-benar ada di tenant ini, bukan daftar dari kontrak: saringan yang
            // menawarkan jenis tanpa satu posting pun hanya menghasilkan daftar kosong.
            'postingTypes' => FinancePosting::query()
                ->where('tenant_id', $tenant)
                ->distinct()
                ->orderBy('posting_type')
                ->pluck('posting_type')
                ->all(),
            'legalEntities' => array_values($legalEntity),
            'counts' => $this->countsByStatus($tenant),
            'postings' => $postings,
        ]);
    }

    public function show(Request $request, FinancePosting $financePosting): JsonResponse
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::FINANCE_POSTING_READ);
        $posting = $this->ownedPosting($membership, $financePosting);
        $events = $posting->events()->orderBy('created_at')->orderBy('id')->get();
        $deliveries = FinancePostingDelivery::query()->where('finance_posting_id', $posting->id)->orderBy('first_attempt_at')->get();

        $user = User::query()
            ->whereIn('id', $events->pluck('user_id')->filter()->unique()->values())
            ->pluck('name', 'id');
        $client = IntegrationClient::query()
            ->where('tenant_id', $membership->tenant_id)
            ->whereIn('id', $events->pluck('integration_client_id')->merge($deliveries->pluck('integration_client_id'))->filter()->unique()->values())
            ->pluck('name', 'id');
        $payload = $posting->payload ?? [];

        return response()->json(['data' => $this->present($posting, $this->legalEntities($membership->tenant_id)) + [
            'source_document' => [
                'module' => $posting->source_module,
                // Nama app dari katalog, bukan kodenya; kode app tetap dikirim untuk app yang sudah
                // tidak ada di katalog.
                'app_name' => CoreApp::query()->whereKey($posting->source_module)->value('name'),
                'type' => $posting->source_type,
                'number' => $posting->source_number,
                'description' => $payload['source_document']['description'] ?? null,
                'id' => $posting->source_id,
                // Dari masukan module, yang sudah diperiksa penerbit hanya berupa jalur relatif; payload
                // pembaca tidak membawanya.
                'url' => is_string($posting->input['source_document']['url'] ?? null) ? $posting->input['source_document']['url'] : null,
            ],
            'lines' => array_map(static fn (array $row): array => [
                'line_no' => (int) $row['line_no'],
                'account_code' => $row['account']['code'] ?? null,
                'account_name' => $row['account']['name'] ?? null,
                'description' => $row['description'] ?? null,
                'debit' => (string) $row['debit'],
                'credit' => (string) $row['credit'],
                'dimensions' => array_map(static fn (array $dimensions): array => [
                    'code' => (string) $dimensions['code'],
                    'display_name' => $dimensions['display_name'] ?? null,
                    'value_code' => $dimensions['value_code'] ?? null,
                    'value_display_name' => $dimensions['value_display_name'] ?? null,
                ], $row['financial_dimensions'] ?? []),
            ], $payload['journal_lines'] ?? []),
            'problems' => $posting->hold_reasons ?? [],
            'events' => $events->map(static fn (FinancePostingEvent $event): array => [
                'event' => $event->event,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                // Pelaku dipasangkan namanya: orang untuk tindakan di layar, klien integrasi untuk
                // ack dan pengiriman, dan kosong untuk tindakan sistem seperti penerbitan.
                'actor' => $event->user_id !== null
                    ? ($user[$event->user_id] ?? null)
                    : ($event->integration_client_id !== null ? ($client[$event->integration_client_id] ?? null) : null),
                'created_at' => $event->created_at?->toIso8601String(),
                'data' => $event->data ?? (object) [],
            ])->values()->all(),
            'deliveries' => $deliveries->map(static fn (FinancePostingDelivery $delivery): array => [
                'client' => (string) ($client[$delivery->integration_client_id] ?? $delivery->integration_client_id),
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'last_status_code' => $delivery->last_status_code,
                'last_error' => $delivery->last_error,
                'last_attempt_at' => $delivery->last_attempt_at?->toIso8601String(),
                'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
                'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            ])->values()->all(),
        ]]);
    }

    public function revalidate(Request $request, FinancePosting $financePosting, PostingPublisher $publisher): JsonResponse
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::FINANCE_POSTING_PROCESS);
        $posting = $this->ownedPosting($membership, $financePosting);
        if ($posting->status !== FinancePosting::HELD) {
            throw ValidationException::withMessages(['status' => 'Hanya posting yang tertahan yang dapat divalidasi ulang.']);
        }

        try {
            $result = $publisher->revalidate($posting, (int) $request->user()?->getAuthIdentifier());
        } catch (InvalidPosting $error) {
            // Masukan yang dulu sah kini ditolak — misalnya vendornya berpindah entitas legal. Itu
            // bukan kesalahan pengguna di layar ini, jadi dilaporkan ke pemantauan kesalahan juga.
            report($error);

            throw ValidationException::withMessages(['posting' => 'Posting ini tidak dapat dibentuk ulang: '.$error->getMessage()]);
        }

        return response()->json(['data' => $this->present($result, $this->legalEntities($membership->tenant_id))]);
    }

    public function markManual(Request $request, FinancePosting $financePosting, PostingPublisher $publisher): JsonResponse
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::FINANCE_POSTING_PROCESS);
        $posting = $this->ownedPosting($membership, $financePosting);
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Tuliskan alasan posting ini dibukukan manual.'],
        );
        if (! in_array($posting->status, FinancePosting::MARKABLE_MANUAL, true)) {
            throw ValidationException::withMessages(['status' => $posting->status === FinancePosting::POSTED
                ? 'Posting ini sudah dibukukan aplikasi finance, jadi tidak dapat ditandai manual.'
                : 'Posting ini sudah ditandai manual.']);
        }

        try {
            $result = $publisher->markManual($posting, trim((string) $data['reason']), (int) $request->user()?->getAuthIdentifier());
        } catch (StatusPostingBerubah) {
            throw ValidationException::withMessages(['status' => 'Status posting ini baru saja berubah. Muat ulang halaman lalu periksa lagi.']);
        }

        return response()->json(['data' => $this->present($result, $this->legalEntities($membership->tenant_id))]);
    }

    /** Anggota yang sedang bekerja, bila role-nya memegang permission layar Core itu (SEC-22). */
    private function authorizedMembership(Request $request, string $permission): TenantMembership
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->hasCorePermission($permission), 403);

        return $membership;
    }

    /** Posting tenant lain dijawab 404, bukan 403: keberadaannya pun tidak boleh terbaca. */
    private function ownedPosting(TenantMembership $membership, FinancePosting $posting): FinancePosting
    {
        abort_unless($posting->tenant_id === $membership->tenant_id, 404);

        return $posting;
    }

    /**
     * Nama dan kode entitas legal diterjemahkan saat dibaca, bukan disalin dari posting: nama yang
     * diganti sesudah posting terbit tetap tampil dengan nama barunya di layar ini.
     *
     * @return array<string, array{id: string, code: ?string, name: string}>
     */
    private function legalEntities(string $tenantId): array
    {
        return Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('classification', 'legal_entity')
            ->with('legalEntity:organization_id,company_code')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(static fn (Organization $organization): array => [$organization->id => [
                'id' => $organization->id,
                'code' => $organization->legalEntity?->company_code,
                'name' => (string) $organization->name,
            ]])
            ->all();
    }

    /** @return array<string, int> Setiap status selalu ada, termasuk yang jumlahnya nol. */
    private function countsByStatus(string $tenantId): array
    {
        $count = FinancePosting::query()
            ->where('tenant_id', $tenantId)
            ->toBase()
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        return array_merge(array_fill_keys(self::STATUS, 0), $count);
    }

    /**
     * @param  array<string, array{id: string, code: ?string, name: string}>  $legalEntity
     * @return array<string, mixed>
     */
    private function present(FinancePosting $posting, array $legalEntity): array
    {
        $legal = $legalEntity[$posting->legal_entity_id] ?? null;

        return [
            'id' => $posting->id,
            'posting_id' => $posting->posting_id,
            'posting_type' => $posting->posting_type,
            'status' => $posting->status,
            'manual_reason' => $posting->manual_reason,
            'legal_entity' => [
                'id' => $posting->legal_entity_id,
                'code' => $legal['code'] ?? ($posting->payload['legal_entity']['code'] ?? null),
                'name' => $legal['name'] ?? null,
            ],
            'posting_date' => $posting->posting_date->toDateString(),
            'published_at' => $posting->published_at->toIso8601String(),
            'currency_code' => $posting->currency_code,
            'currency_decimals' => $posting->currency_decimals,
            // Dari payload, yang sudah memakai presisi mata uangnya; kolomnya menyimpan enam desimal.
            'total_debit' => (string) ($posting->payload['totals']['debit'] ?? $posting->total_debit),
            'source' => [
                'module' => $posting->source_module,
                'type' => $posting->source_type,
                'number' => $posting->source_number,
                'id' => $posting->source_id,
            ],
            'problem_count' => count($posting->hold_reasons ?? []),
            'served_count' => $posting->served_count,
            'last_served_at' => $posting->last_served_at?->toIso8601String(),
            'acknowledged_at' => $posting->acknowledged_at?->toIso8601String(),
            'external_reference' => $posting->external_reference,
            'reason_code' => $posting->reason_code,
            'reason' => $posting->reason,
        ];
    }
}
