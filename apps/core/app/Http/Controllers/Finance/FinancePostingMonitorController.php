<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CoreApp;
use App\Models\FinancePosting;
use App\Models\FinancePostingDelivery;
use App\Models\FinancePostingEvent;
use App\Models\IntegrationClient;
use App\Models\Organization;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Finance\PostingPublisher;
use App\Support\Finance\StatusPostingBerubah;
use App\Support\Modules\Contracts\PostingTidakSah;
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
        $membership = $this->admin($request);
        $tenant = $membership->tenant_id;
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUS)],
            'posting_type' => ['nullable', 'string', 'max:80'],
            'legal_entity_id' => ['nullable', 'string', 'max:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $kata = trim((string) ($filter['q'] ?? ''));
        $pola = '%'.addcslashes($kata, '\\%_').'%';
        $entitas = $this->entitasLegal($tenant);

        $postings = FinancePosting::query()
            ->where('tenant_id', $tenant)
            ->when($kata !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('posting_id', 'ilike', $pola)
                ->orWhere('source_number', 'ilike', $pola)))
            ->when($filter['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filter['posting_type'] ?? null, fn ($query, $jenis) => $query->where('posting_type', $jenis))
            ->when($filter['legal_entity_id'] ?? null, fn ($query, $id) => $query->where('legal_entity_id', $id))
            ->when($filter['from'] ?? null, fn ($query, $dari) => $query->whereDate('posting_date', '>=', $dari))
            ->when($filter['to'] ?? null, fn ($query, $sampai) => $query->whereDate('posting_date', '<=', $sampai))
            ->orderByDesc('posting_date')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (FinancePosting $posting): array => $this->present($posting, $entitas));

        return Inertia::render('settings/finance-postings', [
            'canManage' => $membership->canManageAccess(),
            'filters' => [
                'q' => $kata === '' ? null : $kata,
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
            'legalEntities' => array_values($entitas),
            'counts' => $this->jumlahPerStatus($tenant),
            'postings' => $postings,
        ]);
    }

    public function show(Request $request, FinancePosting $financePosting): JsonResponse
    {
        $membership = $this->admin($request);
        $posting = $this->milik($membership, $financePosting);
        $events = $posting->events()->orderBy('created_at')->orderBy('id')->get();
        $deliveries = FinancePostingDelivery::query()->where('finance_posting_id', $posting->id)->orderBy('first_attempt_at')->get();

        $pengguna = User::query()
            ->whereIn('id', $events->pluck('user_id')->filter()->unique()->values())
            ->pluck('name', 'id');
        $klien = IntegrationClient::query()
            ->where('tenant_id', $membership->tenant_id)
            ->whereIn('id', $events->pluck('integration_client_id')->merge($deliveries->pluck('integration_client_id'))->filter()->unique()->values())
            ->pluck('name', 'id');
        $payload = $posting->payload ?? [];

        return response()->json(['data' => $this->present($posting, $this->entitasLegal($membership->tenant_id)) + [
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
            'lines' => array_map(static fn (array $baris): array => [
                'line_no' => (int) $baris['line_no'],
                'account_code' => $baris['account']['code'] ?? null,
                'account_name' => $baris['account']['name'] ?? null,
                'description' => $baris['description'] ?? null,
                'debit' => (string) $baris['debit'],
                'credit' => (string) $baris['credit'],
                'dimensions' => array_map(static fn (array $dimensi): array => [
                    'code' => (string) $dimensi['code'],
                    'display_name' => $dimensi['display_name'] ?? null,
                    'value_code' => $dimensi['value_code'] ?? null,
                    'value_display_name' => $dimensi['value_display_name'] ?? null,
                ], $baris['financial_dimensions'] ?? []),
            ], $payload['journal_lines'] ?? []),
            'problems' => $posting->hold_reasons ?? [],
            'events' => $events->map(static fn (FinancePostingEvent $event): array => [
                'event' => $event->event,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                // Pelaku dipasangkan namanya: orang untuk tindakan di layar, klien integrasi untuk
                // ack dan pengiriman, dan kosong untuk tindakan sistem seperti penerbitan.
                'actor' => $event->user_id !== null
                    ? ($pengguna[$event->user_id] ?? null)
                    : ($event->integration_client_id !== null ? ($klien[$event->integration_client_id] ?? null) : null),
                'created_at' => $event->created_at?->toIso8601String(),
                'data' => $event->data ?? (object) [],
            ])->values()->all(),
            'deliveries' => $deliveries->map(static fn (FinancePostingDelivery $kiriman): array => [
                'client' => (string) ($klien[$kiriman->integration_client_id] ?? $kiriman->integration_client_id),
                'status' => $kiriman->status,
                'attempts' => $kiriman->attempts,
                'last_status_code' => $kiriman->last_status_code,
                'last_error' => $kiriman->last_error,
                'last_attempt_at' => $kiriman->last_attempt_at?->toIso8601String(),
                'next_attempt_at' => $kiriman->next_attempt_at?->toIso8601String(),
                'delivered_at' => $kiriman->delivered_at?->toIso8601String(),
            ])->values()->all(),
        ]]);
    }

    public function revalidate(Request $request, FinancePosting $financePosting, PostingPublisher $penerbit): JsonResponse
    {
        $membership = $this->admin($request);
        $posting = $this->milik($membership, $financePosting);
        if ($posting->status !== FinancePosting::HELD) {
            throw ValidationException::withMessages(['status' => 'Hanya posting yang tertahan yang dapat divalidasi ulang.']);
        }

        try {
            $hasil = $penerbit->revalidate($posting, (int) $request->user()?->getAuthIdentifier());
        } catch (PostingTidakSah $kesalahan) {
            // Masukan yang dulu sah kini ditolak — misalnya vendornya berpindah entitas legal. Itu
            // bukan kesalahan pengguna di layar ini, jadi dilaporkan ke pemantauan kesalahan juga.
            report($kesalahan);

            throw ValidationException::withMessages(['posting' => 'Posting ini tidak dapat dibentuk ulang: '.$kesalahan->getMessage()]);
        }

        return response()->json(['data' => $this->present($hasil, $this->entitasLegal($membership->tenant_id))]);
    }

    public function markManual(Request $request, FinancePosting $financePosting, PostingPublisher $penerbit): JsonResponse
    {
        $membership = $this->admin($request);
        $posting = $this->milik($membership, $financePosting);
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
            $hasil = $penerbit->markManual($posting, trim((string) $data['reason']), (int) $request->user()?->getAuthIdentifier());
        } catch (StatusPostingBerubah) {
            throw ValidationException::withMessages(['status' => 'Status posting ini baru saja berubah. Muat ulang halaman lalu periksa lagi.']);
        }

        return response()->json(['data' => $this->present($hasil, $this->entitasLegal($membership->tenant_id))]);
    }

    private function admin(Request $request): TenantMembership
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);

        return $membership;
    }

    /** Posting tenant lain dijawab 404, bukan 403: keberadaannya pun tidak boleh terbaca. */
    private function milik(TenantMembership $membership, FinancePosting $posting): FinancePosting
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
    private function entitasLegal(string $tenantId): array
    {
        return Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('classification', 'legal_entity')
            ->with('legalEntity:organization_id,company_code')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(static fn (Organization $organisasi): array => [$organisasi->id => [
                'id' => $organisasi->id,
                'code' => $organisasi->legalEntity?->company_code,
                'name' => (string) $organisasi->name,
            ]])
            ->all();
    }

    /** @return array<string, int> Setiap status selalu ada, termasuk yang jumlahnya nol. */
    private function jumlahPerStatus(string $tenantId): array
    {
        $jumlah = FinancePosting::query()
            ->where('tenant_id', $tenantId)
            ->toBase()
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status')
            ->map(static fn ($nilai): int => (int) $nilai)
            ->all();

        return array_merge(array_fill_keys(self::STATUS, 0), $jumlah);
    }

    /**
     * @param  array<string, array{id: string, code: ?string, name: string}>  $entitas
     * @return array<string, mixed>
     */
    private function present(FinancePosting $posting, array $entitas): array
    {
        $legal = $entitas[$posting->legal_entity_id] ?? null;

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
