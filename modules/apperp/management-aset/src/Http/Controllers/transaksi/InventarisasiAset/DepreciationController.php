<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\ProfilPenyusutan;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\AssetBook;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\AssetPlacement;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationExport;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Services\DepreciationCalculator;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

class DepreciationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->can($request, 'read');
        $query = DepreciationPeriod::query()->join('aset_tr_buku_aset as book', function ($join): void {
            $join->on('book.id', '=', 'aset_tr_penyusutan_aset.asset_book_id')->on('book.tenant_id', '=', 'aset_tr_penyusutan_aset.tenant_id');
        })->join('aset_tr_penerimaan_aset as asset', function ($join): void {
            $join->on('asset.id', '=', 'book.asset_id')->on('asset.tenant_id', '=', 'book.tenant_id');
        });
        app(OrganizationScope::class)->query($query, $request, 'aset_tr_penyusutan_aset.legal_entity_id', 'aset_tr_penyusutan_aset.usage_org_unit_id');
        // `toBase()` menjalankan query lewat model — global scope tenant sudah tersisip di
        // dalamnya — tetapi memulangkan baris apa adanya, bukan model. Itu yang menjaga
        // jawaban HTTP tetap sama persis: tanggal tetap 'YYYY-MM-DD' dan angka tetap teks
        // seperti yang dikirim database, bukan hasil cast model.
        $periods = $query->select('aset_tr_penyusutan_aset.*', 'book.book_code', 'asset.kode as asset_code', 'asset.currency_code')->orderByDesc('aset_tr_penyusutan_aset.period_ends_on')->toBase()->get();

        return response()->json(['data' => $periods]);
    }

    public function books(Request $request): JsonResponse
    {
        $this->can($request, 'read');
        $query = AssetBook::query()->join('aset_tr_penerimaan_aset as asset', function ($join): void {
            $join->on('asset.id', '=', 'aset_tr_buku_aset.asset_id')->on('asset.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->join('aset_m_profil_penyusutan as profile', function ($join): void {
            $join->on('profile.id', '=', 'aset_tr_buku_aset.depreciation_profile_id')->on('profile.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->where('aset_tr_buku_aset.status', 'active');
        app(OrganizationScope::class)->assetQuery($query, $request, 'asset');
        $books = $query->select('aset_tr_buku_aset.*', 'asset.kode as asset_code', 'asset.currency_code', 'profile.nama as profile_name', 'profile.method', 'profile.frequency')->orderBy('asset.kode')->toBase()->get();

        return response()->json(['data' => $books]);
    }

    public function propose(Request $request): JsonResponse
    {
        $this->can($request, 'create');
        $tenant = $this->tenant($request);
        $data = $request->validate(['asset_book_id' => ['required', 'ulid'], 'period_starts_on' => ['required', 'date'], 'period_ends_on' => ['required', 'date'], 'consumption_amount' => ['nullable', 'numeric', 'min:0']]);
        $query = AssetBook::query()->join('aset_m_profil_penyusutan as profile', function ($join): void {
            $join->on('profile.id', '=', 'aset_tr_buku_aset.depreciation_profile_id')->on('profile.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->join('aset_tr_penerimaan_aset as asset', function ($join): void {
            $join->on('asset.id', '=', 'aset_tr_buku_aset.asset_id')->on('asset.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->where('aset_tr_buku_aset.id', $data['asset_book_id']);
        app(OrganizationScope::class)->assetQuery($query, $request, 'asset');
        $book = $query->select(
            'aset_tr_buku_aset.*',
            'profile.method', 'profile.frequency', 'profile.rate_percent', 'profile.manual_schedule',
            // Masa manfaat dan konvensi diambil dari buku aset bila ada, karena di sanalah
            // nilai dari matriks group x buku sudah tersalin; profil hanya cadangannya.
            DB::raw('coalesce(aset_tr_buku_aset.useful_life_periods, profile.useful_life_periods) as useful_life_periods'),
            'asset.legal_entity_id', 'asset.responsible_org_unit_id',
        )->toBase()->first();
        abort_unless($book, 404);
        abort_if(! ($book->depreciate ?? true), 422, 'Buku aset ini ditandai tidak disusutkan.');
        // Buku yang sudah ditutup mengikuti aset yang sudah dijual atau dimusnahkan.
        abort_unless(($book->status ?? 'active') === 'active', 422, 'Buku aset ini sudah ditutup karena asetnya sudah dilepas.');
        abort_if(
            $book->depreciation_start_on !== null && $data['period_ends_on'] < $book->depreciation_start_on,
            422,
            'Periode ini berakhir sebelum aset mulai disusutkan.'
        );
        $existing = DepreciationPeriod::query()->where(['asset_book_id' => $book->id, 'period_ends_on' => $data['period_ends_on']])->whereNull('reverses_period_id')->toBase()->first();
        if ($existing) {
            return response()->json(['data' => $existing]);
        }
        $elapsedPeriods = DepreciationPeriod::query()->where('asset_book_id', $book->id)->whereNull('reverses_period_id')->whereDate('period_ends_on', '<', $data['period_ends_on'])->count();
        $calculator = app(DepreciationCalculator::class);
        // Saldo menurun berpindah ke profil alternatif begitu garis lurus sisa umur
        // menghasilkan angka lebih besar, supaya aset tetap habis di akhir masa manfaat.
        $this->applyAlternativeProfile($book, $calculator, $elapsedPeriods);
        $amount = $calculator->amount($book, $elapsedPeriods, $data['consumption_amount'] ?? null);
        $placement = AssetPlacement::query()->where('asset_id', $book->asset_id)->whereDate('effective_on', '<=', $data['period_ends_on'])->orderByDesc('effective_on')->orderByDesc('id')->toBase()->first();
        abort_unless($placement?->usage_org_unit_id, 422, 'Aset belum memiliki unit penggunaan untuk periode ini.');
        app(OrganizationScope::class)->require($request, $book->legal_entity_id, $placement->usage_org_unit_id);
        $period = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'asset_book_id' => $book->id, 'legal_entity_id' => $book->legal_entity_id, 'usage_org_unit_id' => $placement->usage_org_unit_id, 'period_starts_on' => $data['period_starts_on'], 'period_ends_on' => $data['period_ends_on'], 'amount' => $amount, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()];
        try {
            // Bukan `firstOrCreate`: index unik parsial di database yang memutuskan siapa
            // yang menang, dan yang kalah memperlakukan miliknya sebagai sudah ada.
            (new DepreciationPeriod)->forceFill($period)->save();
        } catch (UniqueConstraintViolationException) {
            $period = DepreciationPeriod::query()->where(['asset_book_id' => $book->id, 'period_ends_on' => $data['period_ends_on']])->whereNull('reverses_period_id')->toBase()->first();

            return response()->json(['data' => $period]);
        }

        return response()->json(['data' => $period], 201);
    }

    /**
     * Proposal penyusutan untuk seluruh buku aktif dalam satu periode sekaligus.
     *
     * Tutup bulan tidak dikerjakan aset demi aset: satu tenant dengan 500 aset dan dua
     * buku berarti seribu permintaan bila hanya ada proposal tunggal. Padanannya di
     * Dynamics 365 F&O adalah "Create depreciation proposal".
     *
     * Buku yang tidak dapat diusulkan tidak menggagalkan seluruh proses; ia dilewati
     * beserta alasannya, supaya satu aset yang belum lengkap tidak menahan 499 lainnya.
     */
    public function bulk(Request $request): JsonResponse
    {
        $this->can($request, 'create');
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'period_starts_on' => ['required', 'date'],
            'period_ends_on' => ['required', 'date', 'after_or_equal:period_starts_on'],
            // Penyaring opsional agar tutup bulan dapat dijalankan bertahap per group
            // atau hanya untuk buku komersial lebih dahulu.
            'group_aset_id' => ['nullable', 'ulid'],
            'buku_id' => ['nullable', 'ulid'],
        ]);

        $query = AssetBook::query()->join('aset_m_profil_penyusutan as profile', function ($join): void {
            $join->on('profile.id', '=', 'aset_tr_buku_aset.depreciation_profile_id')->on('profile.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->join('aset_tr_penerimaan_aset as asset', function ($join): void {
            $join->on('asset.id', '=', 'aset_tr_buku_aset.asset_id')->on('asset.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->where('aset_tr_buku_aset.status', 'active')
            ->where('aset_tr_buku_aset.depreciate', true)
            // Konsumsi butuh angka pemakaian yang hanya diketahui per aset, jadi ia tidak
            // pernah bisa diusulkan massal dan disaring di sini, bukan dilaporkan sebagai
            // ratusan baris terlewat.
            ->where('profile.method', '!=', 'consumption');
        app(OrganizationScope::class)->assetQuery($query, $request, 'asset');
        foreach (['group_aset_id' => 'asset.group_aset_id', 'buku_id' => 'aset_tr_buku_aset.buku_id'] as $input => $column) {
            if ($data[$input] ?? null) {
                $query->where($column, $data[$input]);
            }
        }
        $books = $query->select(
            'aset_tr_buku_aset.*',
            'profile.method', 'profile.frequency', 'profile.rate_percent', 'profile.manual_schedule',
            DB::raw('coalesce(aset_tr_buku_aset.useful_life_periods, profile.useful_life_periods) as useful_life_periods'),
            'asset.legal_entity_id', 'asset.kode as asset_code',
        )->orderBy('asset.kode')->toBase()->get();

        $calculator = app(DepreciationCalculator::class);
        $created = [];
        $skipped = [];

        foreach ($books as $book) {
            $reason = $this->bulkSkipReason($book, $data);
            if ($reason !== null) {
                $skipped[] = ['asset_book_id' => $book->id, 'asset_code' => $book->asset_code, 'reason' => $reason];

                continue;
            }
            $elapsedPeriods = DepreciationPeriod::query()
                ->where('asset_book_id', $book->id)
                ->whereNull('reverses_period_id')
                ->whereDate('period_ends_on', '<', $data['period_ends_on'])->count();
            $this->applyAlternativeProfile($book, $calculator, $elapsedPeriods);
            $amount = $calculator->amount($book, $elapsedPeriods);
            if ($amount <= 0.0) {
                $skipped[] = ['asset_book_id' => $book->id, 'asset_code' => $book->asset_code, 'reason' => 'sudah_habis'];

                continue;
            }
            $placement = AssetPlacement::query()
                ->where('asset_id', $book->asset_id)
                ->whereDate('effective_on', '<=', $data['period_ends_on'])
                ->orderByDesc('effective_on')->orderByDesc('id')->toBase()->first();
            if (! $placement?->usage_org_unit_id) {
                $skipped[] = ['asset_book_id' => $book->id, 'asset_code' => $book->asset_code, 'reason' => 'tanpa_unit_penggunaan'];

                continue;
            }
            $period = [
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'asset_book_id' => $book->id,
                'legal_entity_id' => $book->legal_entity_id, 'usage_org_unit_id' => $placement->usage_org_unit_id,
                'period_starts_on' => $data['period_starts_on'], 'period_ends_on' => $data['period_ends_on'],
                'amount' => $amount, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now(),
            ];
            try {
                (new DepreciationPeriod)->forceFill($period)->save();
            } catch (UniqueConstraintViolationException) {
                // Dua tutup bulan berbarengan: yang kalah memperlakukan miliknya sebagai
                // sudah ada, bukan sebagai kegagalan.
                $skipped[] = ['asset_book_id' => $book->id, 'asset_code' => $book->asset_code, 'reason' => 'sudah_ada'];

                continue;
            }
            $created[] = $period;
        }

        return response()->json(['data' => [
            'dibuat' => count($created),
            'dilewati' => count($skipped),
            'periode' => $created,
            'rincian_dilewati' => $skipped,
        ]], 201);
    }

    /** @param array<string, mixed> $data */
    private function bulkSkipReason(object $book, array $data): ?string
    {
        if ($book->depreciation_start_on !== null && $data['period_ends_on'] < $book->depreciation_start_on) {
            return 'belum_mulai_menyusut';
        }
        $exists = DepreciationPeriod::query()
            ->where(['asset_book_id' => $book->id, 'period_ends_on' => $data['period_ends_on']])
            ->whereNull('reverses_period_id')->exists();

        return $exists ? 'sudah_ada' : null;
    }

    /**
     * Memindahkan buku ke profil alternatif bila saldo menurun sudah kalah dari garis
     * lurus sisa umur. Dipakai proposal tunggal maupun massal agar keduanya tidak
     * menyimpang satu sama lain.
     */
    private function applyAlternativeProfile(object $book, DepreciationCalculator $calculator, int $elapsedPeriods): void
    {
        if (! $calculator->shouldSwitch($book, $elapsedPeriods)) {
            return;
        }
        // `withTrashed()` mempertahankan perilaku lama: profil alternatif yang sudah
        // diarsipkan tetap dipakai buku yang terlanjur menunjuknya, karena aturannya
        // sudah menempel pada buku itu sejak asetnya diterima.
        $alternative = ProfilPenyusutan::withTrashed()
            ->where('id', $book->alternative_profile_id)
            ->toBase()->first(['method', 'frequency', 'rate_percent', 'manual_schedule']);
        if (! $alternative) {
            return;
        }
        $book->method = $alternative->method;
        $book->frequency = $alternative->frequency;
        $book->rate_percent = $alternative->rate_percent;
        $book->manual_schedule = $alternative->manual_schedule;
    }

    public function finalize(Request $request, string $id): JsonResponse
    {
        $this->can($request, 'finalize');
        $tenant = $this->tenant($request);
        $scope = app(OrganizationScope::class);
        $result = DB::transaction(function () use ($tenant, $id, $request, $scope): array {
            $query = DepreciationPeriod::query()->where('id', $id);
            $scope->query($query, $request, 'legal_entity_id', 'usage_org_unit_id');
            $period = $query->lockForUpdate()->toBase()->first();
            abort_unless($period, 404);
            if ($period->status === 'final') {
                // Retry finalisasi harus aman: transaksi dan export yang sudah ada
                // dikembalikan tanpa menambah saldo atau membuat export kedua.
                return [
                    'period' => $period,
                    'export' => DepreciationExport::query()
                        ->where('depreciation_period_id', $period->id)
                        ->toBase()->first(),
                ];
            }
            DepreciationPeriod::query()->where('id', $id)->update(['status' => 'final', 'updated_at' => now()]);
            $book = AssetBook::query()->join('aset_tr_penerimaan_aset as asset', function ($join): void {
                $join->on('asset.id', '=', 'aset_tr_buku_aset.asset_id')->on('asset.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
            })
                ->leftJoin('aset_m_buku_penyusutan as buku', function ($join): void {
                    $join->on('buku.id', '=', 'aset_tr_buku_aset.buku_id')->on('buku.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
                })
                ->where('aset_tr_buku_aset.id', $period->asset_book_id)
                ->select(
                    'aset_tr_buku_aset.*', 'asset.kode as asset_code', 'asset.currency_code',
                    'buku.export_to_backoffice',
                )->toBase()->first();
            AssetBook::query()->where('id', $book->id)->update(['accumulated_depreciation' => DB::raw('round(accumulated_depreciation + '.(float) $period->amount.', 2)'), 'net_book_value' => DB::raw('round(net_book_value - '.(float) $period->amount.', 2)'), 'updated_at' => now()]);
            $payload = ['contract_version' => 1, 'tenant_id' => $tenant, 'legal_entity_id' => $period->legal_entity_id, 'asset_book_id' => $book->id, 'asset_code' => $book->asset_code, 'usage_org_unit_id' => $period->usage_org_unit_id, 'period_starts_on' => $period->period_starts_on, 'period_ends_on' => $period->period_ends_on, 'amount' => $period->amount, 'currency_code' => $book->currency_code, 'acquisition_value' => $book->acquisition_value, 'accumulated_depreciation' => round((float) $book->accumulated_depreciation + (float) $period->amount, 2), 'net_book_value' => round((float) $book->net_book_value - (float) $period->amount, 2), 'status' => 'final'];
            // Buku pajak lazimnya tidak diekspor, supaya backoffice tidak menjurnal dua
            // kali untuk aset yang sama. Periodenya tetap final dan tercatat.
            $exported = $book->buku_id === null || (bool) $book->export_to_backoffice;
            $export = null;
            if ($exported) {
                $export = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'posting_id' => 'DPR-'.Str::ulid(), 'depreciation_period_id' => $period->id, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now()];
                $this->saveExport($export, $payload);
            }

            return ['period' => DepreciationPeriod::query()->where('id', $id)->toBase()->first(), 'export' => $export];
        });

        return response()->json(['data' => $result]);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $this->can($request, 'correct');
        $tenant = $this->tenant($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);
        $scope = app(OrganizationScope::class);
        $result = DB::transaction(function () use ($tenant, $id, $data, $request, $scope): array {
            $query = DepreciationPeriod::query()->where('id', $id);
            $scope->query($query, $request, 'legal_entity_id', 'usage_org_unit_id');
            $original = $query->lockForUpdate()->toBase()->first();
            abort_unless($original, 404);
            abort_unless($original->status === 'final', 409, 'Hanya periode final yang dapat dibalik.');
            abort_if(DepreciationPeriod::query()->where('reverses_period_id', $original->id)->exists(), 409, 'Periode penyusutan ini sudah dibalik.');
            $book = AssetBook::query()->join('aset_tr_penerimaan_aset as asset', function ($join): void {
                $join->on('asset.id', '=', 'aset_tr_buku_aset.asset_id')->on('asset.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
            })->where('aset_tr_buku_aset.id', $original->asset_book_id)->select('aset_tr_buku_aset.*', 'asset.kode as asset_code', 'asset.currency_code')->lockForUpdate()->toBase()->first();
            abort_unless($book, 404);
            $period = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'asset_book_id' => $book->id, 'legal_entity_id' => $original->legal_entity_id, 'usage_org_unit_id' => $original->usage_org_unit_id, 'period_starts_on' => $original->period_starts_on, 'period_ends_on' => $original->period_ends_on, 'amount' => -(float) $original->amount, 'status' => 'final', 'reverses_period_id' => $original->id, 'created_at' => now(), 'updated_at' => now()];
            (new DepreciationPeriod)->forceFill($period)->save();
            AssetBook::query()->where('id', $book->id)->update(['accumulated_depreciation' => DB::raw('round(accumulated_depreciation - '.(float) $original->amount.', 2)'), 'net_book_value' => DB::raw('round(net_book_value + '.(float) $original->amount.', 2)'), 'updated_at' => now()]);
            $payload = ['contract_version' => 1, 'tenant_id' => $tenant, 'legal_entity_id' => $original->legal_entity_id, 'asset_book_id' => $book->id, 'asset_code' => $book->asset_code, 'usage_org_unit_id' => $original->usage_org_unit_id, 'period_starts_on' => $original->period_starts_on, 'period_ends_on' => $original->period_ends_on, 'amount' => -(float) $original->amount, 'currency_code' => $book->currency_code, 'status' => 'reversal', 'reverses_period_id' => $original->id, 'reason' => $data['reason']];
            $export = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'posting_id' => 'DPR-'.Str::ulid(), 'depreciation_period_id' => $period['id'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            $this->saveExport($export, $payload);

            return ['period' => $period, 'export' => $export];
        });

        return response()->json(['data' => $result], 201);
    }

    /**
     * Menyimpan satu baris export.
     *
     * `payload` pada jawaban HTTP tetap berupa teks JSON seperti sebelumnya, sedangkan
     * modelnya menyandikan sendiri lewat cast `array` — jadi yang diserahkan ke model
     * adalah payload aslinya, bukan teks yang sudah disandikan.
     *
     * @param  array<string, mixed>  $export
     * @param  array<string, mixed>  $payload
     */
    private function saveExport(array $export, array $payload): void
    {
        (new DepreciationExport)->forceFill([...$export, 'payload' => $payload])->save();
    }

    private function can(Request $r, string $action): void
    {
        abort_unless(in_array('management-aset.penyusutan.'.$action, $r->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function tenant(Request $r): string
    {
        return (string) $r->attributes->get('coreerp.tenant_id');
    }
}
