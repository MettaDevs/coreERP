<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinancePostingSetting;
use App\Models\FinanceSettlementMode;
use App\Models\Organization;
use App\Support\Finance\PostingSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Setelan feed posting finance pada halaman entitas legal (K-10, K-16).
 *
 * Dibaca semua anggota tenant, diubah owner atau admin. Core belum punya katalog izin sendiri,
 * jadi penjaganya sama dengan layar organisasi lain.
 */
final class FinancePostingSettingController extends Controller
{
    public function __construct(private readonly PostingSettings $settings) {}

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->guard($request, $organization);

        return response()->json(['data' => $this->present($organization)]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $this->guard($request, $organization, manage: true);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'cutover_date' => ['nullable', 'date_format:Y-m-d', 'required_if_accepted:enabled'],
        ], [
            'cutover_date.required_if_accepted' => 'Isi tanggal cutover sebelum mengaktifkan feed. Tanpa cutover, seluruh riwayat yang sudah dijurnal manual ikut terkirim.',
        ]);

        FinancePostingSetting::query()->updateOrCreate(
            ['legal_entity_id' => $organization->id],
            [
                'tenant_id' => $organization->tenant_id,
                'enabled' => (bool) $data['enabled'],
                'cutover_date' => $data['cutover_date'] ?? null,
            ],
        );

        return response()->json(['data' => $this->present($organization)]);
    }

    public function storeMode(Request $request, Organization $organization): JsonResponse
    {
        $this->guard($request, $organization, manage: true);
        $ganda = 'Sudah ada mode yang berlaku mulai tanggal itu. Pilih tanggal lain.';
        $data = $request->validate([
            'mode' => ['required', Rule::in(FinanceSettlementMode::MODES)],
            'effective_from' => [
                'required', 'date_format:Y-m-d',
                Rule::unique('finance_settlement_modes', 'effective_from')->where('legal_entity_id', $organization->id),
            ],
        ], ['effective_from.unique' => $ganda]);

        try {
            // Transaksi bersarang menjadi SAVEPOINT. Tanpanya, bentrokan indeks unik dari dua
            // permintaan bersamaan membatalkan seluruh transaksi luar di PostgreSQL, dan
            // penangkapan di bawah tidak memulihkan apa pun.
            DB::transaction(fn () => FinanceSettlementMode::query()->create([
                'tenant_id' => $organization->tenant_id,
                'legal_entity_id' => $organization->id,
                'mode' => $data['mode'],
                'effective_from' => $data['effective_from'],
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['effective_from' => $ganda]);
        }

        return response()->json(['data' => $this->present($organization)], 201);
    }

    /**
     * Hanya mode yang belum berlaku yang boleh dihapus.
     *
     * Posting mencatat modenya sendiri, jadi menghapus mode lama tidak mengubah jurnal yang sudah
     * terbit. Tetapi riwayat yang berubah diam-diam membuat "mode apa yang berlaku bulan lalu"
     * dijawab berbeda dari yang sebenarnya terjadi. Mode yang keliru diganti dengan baris baru.
     */
    public function destroyMode(Request $request, Organization $organization, string $mode): Response
    {
        $this->guard($request, $organization, manage: true);
        $baris = FinanceSettlementMode::query()
            ->where('legal_entity_id', $organization->id)
            ->whereKey($mode)
            ->firstOrFail();
        if (! $baris->effective_from->isAfter(today())) {
            throw ValidationException::withMessages([
                'mode' => 'Mode yang sudah berlaku tidak dapat dihapus. Tambahkan mode baru dengan tanggal berlaku berikutnya.',
            ]);
        }

        $baris->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(Organization $organization): array
    {
        $setting = $this->settings->setting($organization->id);

        return [
            'enabled' => $setting->enabled ?? false,
            'cutover_date' => $setting?->cutover_date?->toDateString(),
            'current_mode' => $this->settings->settlementMode($organization->id, today()->toDateString()),
            'default_mode' => FinanceSettlementMode::DEFAULT,
            'modes' => FinanceSettlementMode::query()
                ->where('legal_entity_id', $organization->id)
                ->orderByDesc('effective_from')
                ->get()
                ->map(static fn (FinanceSettlementMode $baris): array => [
                    'id' => $baris->id,
                    'mode' => $baris->mode,
                    'effective_from' => $baris->effective_from->toDateString(),
                    'removable' => $baris->effective_from->isAfter(today()),
                ])
                ->values()
                ->all(),
        ];
    }

    private function guard(Request $request, Organization $organization, bool $manage = false): void
    {
        $membership = $this->currentMembership($request);
        abort_unless($organization->tenant_id === $membership->tenant_id, 404);
        abort_unless($organization->classification === 'legal_entity', 404);
        if ($manage) {
            abort_unless($membership->canManageAccess(), 403);
        }
    }
}
