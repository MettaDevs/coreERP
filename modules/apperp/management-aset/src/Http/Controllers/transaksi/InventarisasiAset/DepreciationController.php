<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Services\DepreciationCalculator;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

class DepreciationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->can($request, 'read');
        $tenant = $this->tenant($request);
        $query = DB::table('tr_penyusutan_aset as period')->join('tr_buku_aset as book', function ($join): void {
            $join->on('book.id', '=', 'period.asset_book_id')->on('book.tenant_id', '=', 'period.tenant_id');
        })->join('tr_penerimaan_aset as asset', function ($join): void {
            $join->on('asset.id', '=', 'book.asset_id')->on('asset.tenant_id', '=', 'book.tenant_id');
        })->where('period.tenant_id', $tenant);
        app(OrganizationScope::class)->query($query, $request, 'period.legal_entity_id', 'period.usage_org_unit_id');
        $periods = $query->select('period.*', 'book.book_code', 'asset.kode as asset_code', 'asset.currency_code')->orderByDesc('period.period_ends_on')->get();

        return response()->json(['data' => $periods]);
    }

    public function books(Request $request): JsonResponse
    {
        $this->can($request, 'read');
        $tenant = $this->tenant($request);
        $query = DB::table('tr_buku_aset as book')->join('tr_penerimaan_aset as asset', function ($join): void {
            $join->on('asset.id', '=', 'book.asset_id')->on('asset.tenant_id', '=', 'book.tenant_id');
        })->join('m_profil_penyusutan as profile', function ($join): void {
            $join->on('profile.id', '=', 'book.depreciation_profile_id')->on('profile.tenant_id', '=', 'book.tenant_id');
        })->where(['book.tenant_id' => $tenant, 'book.status' => 'active']);
        app(OrganizationScope::class)->assetQuery($query, $request, 'asset');
        $books = $query->select('book.*', 'asset.kode as asset_code', 'asset.currency_code', 'profile.nama as profile_name', 'profile.method', 'profile.frequency')->orderBy('asset.kode')->get();

        return response()->json(['data' => $books]);
    }

    public function propose(Request $request): JsonResponse
    {
        $this->can($request, 'create');
        $tenant = $this->tenant($request);
        $data = $request->validate(['asset_book_id' => ['required', 'ulid'], 'period_starts_on' => ['required', 'date'], 'period_ends_on' => ['required', 'date'], 'consumption_amount' => ['nullable', 'numeric', 'min:0']]);
        $query = DB::table('tr_buku_aset as book')->join('m_profil_penyusutan as profile', function ($join): void {
            $join->on('profile.id', '=', 'book.depreciation_profile_id')->on('profile.tenant_id', '=', 'book.tenant_id');
        })->join('tr_penerimaan_aset as asset', function ($join): void {
            $join->on('asset.id', '=', 'book.asset_id')->on('asset.tenant_id', '=', 'book.tenant_id');
        })->where(['book.tenant_id' => $tenant, 'book.id' => $data['asset_book_id']]);
        app(OrganizationScope::class)->assetQuery($query, $request, 'asset');
        $book = $query->select(
            'book.*',
            'profile.method', 'profile.frequency', 'profile.rate_percent', 'profile.manual_schedule',
            // Masa manfaat dan konvensi diambil dari buku aset bila ada, karena di sanalah
            // nilai dari matriks group x buku sudah tersalin; profil hanya cadangannya.
            DB::raw('coalesce(book.useful_life_periods, profile.useful_life_periods) as useful_life_periods'),
            'asset.legal_entity_id', 'asset.responsible_org_unit_id',
        )->first();
        abort_unless($book, 404);
        abort_if(! ($book->depreciate ?? true), 422, 'Buku aset ini ditandai tidak disusutkan.');
        // Buku yang sudah ditutup mengikuti aset yang sudah dijual atau dimusnahkan.
        abort_unless(($book->status ?? 'active') === 'active', 422, 'Buku aset ini sudah ditutup karena asetnya sudah dilepas.');
        abort_if(
            $book->depreciation_start_on !== null && $data['period_ends_on'] < $book->depreciation_start_on,
            422,
            'Periode ini berakhir sebelum aset mulai disusutkan.'
        );
        $existing = DB::table('tr_penyusutan_aset')->where(['tenant_id' => $tenant, 'asset_book_id' => $book->id, 'period_ends_on' => $data['period_ends_on']])->whereNull('reverses_period_id')->first();
        if ($existing) {
            return response()->json(['data' => $existing]);
        }
        $elapsedPeriods = DB::table('tr_penyusutan_aset')->where(['tenant_id' => $tenant, 'asset_book_id' => $book->id])->whereNull('reverses_period_id')->whereDate('period_ends_on', '<', $data['period_ends_on'])->count();
        $calculator = app(DepreciationCalculator::class);
        // Saldo menurun berpindah ke profil alternatif begitu garis lurus sisa umur
        // menghasilkan angka lebih besar, supaya aset tetap habis di akhir masa manfaat.
        $this->applyAlternativeProfile($book, $calculator, $tenant, $elapsedPeriods);
        $amount = $calculator->amount($book, $elapsedPeriods, $data['consumption_amount'] ?? null);
        $placement = DB::table('tr_penempatan_aset')->where(['tenant_id' => $tenant, 'asset_id' => $book->asset_id])->whereDate('effective_on', '<=', $data['period_ends_on'])->orderByDesc('effective_on')->orderByDesc('id')->first();
        abort_unless($placement?->usage_org_unit_id, 422, 'Aset belum memiliki unit penggunaan untuk periode ini.');
        app(OrganizationScope::class)->require($request, $book->legal_entity_id, $placement->usage_org_unit_id);
        $period = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'asset_book_id' => $book->id, 'legal_entity_id' => $book->legal_entity_id, 'usage_org_unit_id' => $placement->usage_org_unit_id, 'period_starts_on' => $data['period_starts_on'], 'period_ends_on' => $data['period_ends_on'], 'amount' => $amount, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()];
        try {
            DB::table('tr_penyusutan_aset')->insert($period);
        } catch (UniqueConstraintViolationException) {
            $period = DB::table('tr_penyusutan_aset')->where(['tenant_id' => $tenant, 'asset_book_id' => $book->id, 'period_ends_on' => $data['period_ends_on']])->whereNull('reverses_period_id')->first();

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

        $query = DB::table('tr_buku_aset as book')->join('m_profil_penyusutan as profile', function ($join): void {
            $join->on('profile.id', '=', 'book.depreciation_profile_id')->on('profile.tenant_id', '=', 'book.tenant_id');
        })->join('tr_penerimaan_aset as asset', function ($join): void {
            $join->on('asset.id', '=', 'book.asset_id')->on('asset.tenant_id', '=', 'book.tenant_id');
        })->where(['book.tenant_id' => $tenant, 'book.status' => 'active'])
            ->where('book.depreciate', true)
            // Konsumsi butuh angka pemakaian yang hanya diketahui per aset, jadi ia tidak
            // pernah bisa diusulkan massal dan disaring di sini, bukan dilaporkan sebagai
            // ratusan baris terlewat.
            ->where('profile.method', '!=', 'consumption');
        app(OrganizationScope::class)->assetQuery($query, $request, 'asset');
        foreach (['group_aset_id' => 'asset.group_aset_id', 'buku_id' => 'book.buku_id'] as $input => $column) {
            if ($data[$input] ?? null) {
                $query->where($column, $data[$input]);
            }
        }
        $books = $query->select(
            'book.*',
            'profile.method', 'profile.frequency', 'profile.rate_percent', 'profile.manual_schedule',
            DB::raw('coalesce(book.useful_life_periods, profile.useful_life_periods) as useful_life_periods'),
            'asset.legal_entity_id', 'asset.kode as asset_code',
        )->orderBy('asset.kode')->get();

        $calculator = app(DepreciationCalculator::class);
        $created = [];
        $skipped = [];

        foreach ($books as $book) {
            $reason = $this->bulkSkipReason($book, $tenant, $data);
            if ($reason !== null) {
                $skipped[] = ['asset_book_id' => $book->id, 'asset_code' => $book->asset_code, 'reason' => $reason];

                continue;
            }
            $elapsedPeriods = DB::table('tr_penyusutan_aset')
                ->where(['tenant_id' => $tenant, 'asset_book_id' => $book->id])
                ->whereNull('reverses_period_id')
                ->whereDate('period_ends_on', '<', $data['period_ends_on'])->count();
            $this->applyAlternativeProfile($book, $calculator, $tenant, $elapsedPeriods);
            $amount = $calculator->amount($book, $elapsedPeriods);
            if ($amount <= 0.0) {
                $skipped[] = ['asset_book_id' => $book->id, 'asset_code' => $book->asset_code, 'reason' => 'sudah_habis'];

                continue;
            }
            $placement = DB::table('tr_penempatan_aset')
                ->where(['tenant_id' => $tenant, 'asset_id' => $book->asset_id])
                ->whereDate('effective_on', '<=', $data['period_ends_on'])
                ->orderByDesc('effective_on')->orderByDesc('id')->first();
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
                DB::table('tr_penyusutan_aset')->insert($period);
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
    private function bulkSkipReason(object $book, string $tenant, array $data): ?string
    {
        if ($book->depreciation_start_on !== null && $data['period_ends_on'] < $book->depreciation_start_on) {
            return 'belum_mulai_menyusut';
        }
        $exists = DB::table('tr_penyusutan_aset')
            ->where(['tenant_id' => $tenant, 'asset_book_id' => $book->id, 'period_ends_on' => $data['period_ends_on']])
            ->whereNull('reverses_period_id')->exists();

        return $exists ? 'sudah_ada' : null;
    }

    /**
     * Memindahkan buku ke profil alternatif bila saldo menurun sudah kalah dari garis
     * lurus sisa umur. Dipakai proposal tunggal maupun massal agar keduanya tidak
     * menyimpang satu sama lain.
     */
    private function applyAlternativeProfile(object $book, DepreciationCalculator $calculator, string $tenant, int $elapsedPeriods): void
    {
        if (! $calculator->shouldSwitch($book, $elapsedPeriods)) {
            return;
        }
        $alternative = DB::table('m_profil_penyusutan')
            ->where(['tenant_id' => $tenant, 'id' => $book->alternative_profile_id])
            ->first(['method', 'frequency', 'rate_percent', 'manual_schedule']);
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
            $query = DB::table('tr_penyusutan_aset')->where(['tenant_id' => $tenant, 'id' => $id]);
            $scope->query($query, $request, 'legal_entity_id', 'usage_org_unit_id');
            $period = $query->lockForUpdate()->first();
            abort_unless($period, 404);
            if ($period->status === 'final') {
                // Retry finalisasi harus aman: transaksi dan export yang sudah ada
                // dikembalikan tanpa menambah saldo atau membuat export kedua.
                return [
                    'period' => $period,
                    'export' => DB::table('tr_export_penyusutan')
                        ->where(['tenant_id' => $tenant, 'depreciation_period_id' => $period->id])
                        ->first(),
                ];
            }
            DB::table('tr_penyusutan_aset')->where('id', $id)->update(['status' => 'final', 'updated_at' => now()]);
            $book = DB::table('tr_buku_aset as book')->join('tr_penerimaan_aset as asset', function ($join): void {
                $join->on('asset.id', '=', 'book.asset_id')->on('asset.tenant_id', '=', 'book.tenant_id');
            })
                ->leftJoin('m_buku_penyusutan as buku', function ($join): void {
                    $join->on('buku.id', '=', 'book.buku_id')->on('buku.tenant_id', '=', 'book.tenant_id');
                })
                ->where(['book.tenant_id' => $tenant, 'book.id' => $period->asset_book_id])
                ->select(
                    'book.*', 'asset.kode as asset_code', 'asset.currency_code',
                    'buku.export_to_backoffice',
                )->first();
            DB::table('tr_buku_aset')->where('id', $book->id)->update(['accumulated_depreciation' => DB::raw('round(accumulated_depreciation + '.(float) $period->amount.', 2)'), 'net_book_value' => DB::raw('round(net_book_value - '.(float) $period->amount.', 2)'), 'updated_at' => now()]);
            $payload = ['contract_version' => 1, 'tenant_id' => $tenant, 'legal_entity_id' => $period->legal_entity_id, 'asset_book_id' => $book->id, 'asset_code' => $book->asset_code, 'usage_org_unit_id' => $period->usage_org_unit_id, 'period_starts_on' => $period->period_starts_on, 'period_ends_on' => $period->period_ends_on, 'amount' => $period->amount, 'currency_code' => $book->currency_code, 'acquisition_value' => $book->acquisition_value, 'accumulated_depreciation' => round((float) $book->accumulated_depreciation + (float) $period->amount, 2), 'net_book_value' => round((float) $book->net_book_value - (float) $period->amount, 2), 'status' => 'final'];
            // Buku pajak lazimnya tidak diekspor, supaya backoffice tidak menjurnal dua
            // kali untuk aset yang sama. Periodenya tetap final dan tercatat.
            $exported = $book->buku_id === null || (bool) $book->export_to_backoffice;
            $export = null;
            if ($exported) {
                $export = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'posting_id' => 'DPR-'.Str::ulid(), 'depreciation_period_id' => $period->id, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now()];
                DB::table('tr_export_penyusutan')->insert($export);
            }

            return ['period' => DB::table('tr_penyusutan_aset')->where('id', $id)->first(), 'export' => $export];
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
            $query = DB::table('tr_penyusutan_aset')->where(['tenant_id' => $tenant, 'id' => $id]);
            $scope->query($query, $request, 'legal_entity_id', 'usage_org_unit_id');
            $original = $query->lockForUpdate()->first();
            abort_unless($original, 404);
            abort_unless($original->status === 'final', 409, 'Hanya periode final yang dapat dibalik.');
            abort_if(DB::table('tr_penyusutan_aset')->where(['tenant_id' => $tenant, 'reverses_period_id' => $original->id])->exists(), 409, 'Periode penyusutan ini sudah dibalik.');
            $book = DB::table('tr_buku_aset as book')->join('tr_penerimaan_aset as asset', function ($join): void {
                $join->on('asset.id', '=', 'book.asset_id')->on('asset.tenant_id', '=', 'book.tenant_id');
            })->where(['book.tenant_id' => $tenant, 'book.id' => $original->asset_book_id])->select('book.*', 'asset.kode as asset_code', 'asset.currency_code')->lockForUpdate()->first();
            abort_unless($book, 404);
            $period = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'asset_book_id' => $book->id, 'legal_entity_id' => $original->legal_entity_id, 'usage_org_unit_id' => $original->usage_org_unit_id, 'period_starts_on' => $original->period_starts_on, 'period_ends_on' => $original->period_ends_on, 'amount' => -(float) $original->amount, 'status' => 'final', 'reverses_period_id' => $original->id, 'created_at' => now(), 'updated_at' => now()];
            DB::table('tr_penyusutan_aset')->insert($period);
            DB::table('tr_buku_aset')->where('id', $book->id)->update(['accumulated_depreciation' => DB::raw('round(accumulated_depreciation - '.(float) $original->amount.', 2)'), 'net_book_value' => DB::raw('round(net_book_value + '.(float) $original->amount.', 2)'), 'updated_at' => now()]);
            $payload = ['contract_version' => 1, 'tenant_id' => $tenant, 'legal_entity_id' => $original->legal_entity_id, 'asset_book_id' => $book->id, 'asset_code' => $book->asset_code, 'usage_org_unit_id' => $original->usage_org_unit_id, 'period_starts_on' => $original->period_starts_on, 'period_ends_on' => $original->period_ends_on, 'amount' => -(float) $original->amount, 'currency_code' => $book->currency_code, 'status' => 'reversal', 'reverses_period_id' => $original->id, 'reason' => $data['reason']];
            $export = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'posting_id' => 'DPR-'.Str::ulid(), 'depreciation_period_id' => $period['id'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            DB::table('tr_export_penyusutan')->insert($export);

            return ['period' => $period, 'export' => $export];
        });

        return response()->json(['data' => $result], 201);
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
