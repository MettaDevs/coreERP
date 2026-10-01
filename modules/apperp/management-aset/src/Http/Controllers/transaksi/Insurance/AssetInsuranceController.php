<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Insurance;

use App\Platform\Modules\Contracts\RequestContext;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\transaksi\Insurance\InsuranceCoverage;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Services\AssetBookValues;
use Modules\Apperp\ManagementAset\Services\InsuredValues;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use stdClass;

/**
 * Asuransi dilihat dari sisi aset: riwayat pertanggungan satu aset, dan ringkasan aset yang belum atau
 * kurang diasuransikan.
 *
 * **Kurang diasuransikan** berarti total nilai yang ditanggung pada tanggal itu lebih kecil dari nilai
 * perolehan aset. Pembandingnya nilai perolehan, mengikuti halaman *Total Value Insured* BC yang
 * menampilkan *Acquisition Cost* buku asuransi di samping total pertanggungan. Nilai buku ikut
 * ditampilkan sebagai informasi, seperti laporan *Insurance - Uninsured FAs* BC yang memuat nilai
 * perolehan, penyusutan, dan nilai buku. Aset yang sudah dihentikan atau dilepas tidak ikut, seperti BC
 * melewatkan aset ber-*Disposal Date* dan *Inactive*.
 */
class AssetInsuranceController extends Controller
{
    private const COVERAGE = 'aset_tr_pertanggungan_asuransi';

    /** Batas baris ringkasan; sisanya disaring lewat entitas legal, jenis, atau lokasi. */
    private const LIMIT = 2000;

    public const UNINSURED = 'tidak_diasuransikan';

    public const UNDERINSURED = 'kurang_diasuransikan';

    public const INSURED = 'cukup';

    public function forAsset(Request $request, AssetBookValues $books, InsuredValues $insured): JsonResponse
    {
        $this->guard($request);
        $asetId = $request->validate(['aset_id' => ['required', 'ulid']])['aset_id'];
        $query = Aset::query()->whereKey($asetId);
        app(OrganizationScope::class)->asetQuery($query, $request);
        $aset = $query->first(['aset_tr_aset.id', 'aset_tr_aset.acquisition_value']);
        abort_if($aset === null, 404);

        $today = $this->today();
        $rows = InsuranceCoverage::query()
            ->join('aset_m_polis_asuransi as polis', function (JoinClause $join): void {
                $join->on('polis.id', '=', self::COVERAGE.'.polis_asuransi_id')->on('polis.tenant_id', '=', self::COVERAGE.'.tenant_id');
            })
            ->where(self::COVERAGE.'.aset_id', $asetId)
            ->orderByDesc(self::COVERAGE.'.berlaku_mulai')
            ->toBase()
            ->get([
                self::COVERAGE.'.*', 'polis.kode as polis_kode', 'polis.nama as polis_nama', 'polis.nomor_polis',
                'polis.berlaku_sampai as polis_berlaku_sampai', 'polis.deleted_at as polis_diarsipkan',
            ])
            ->map(function (stdClass $row) use ($today): stdClass {
                $end = $row->berlaku_sampai ?? $row->polis_berlaku_sampai;
                $row->berjalan = $row->polis_diarsipkan === null && $row->berlaku_mulai <= $today && ($end === null || $end >= $today);

                return $row;
            });

        $book = $books->forAssets([$asetId])[$asetId] ?? null;
        // `first()`, bukan `value()`: `value()` mengganti select berisi agregat dengan nama kolomnya.
        $total = (string) ($insured->perAssetQuery($today)->where(self::COVERAGE.'.aset_id', $asetId)->first()->total ?? '0');
        $acquisition = (string) ($book->acquisition_value ?? $aset->acquisition_value);

        return response()->json(['data' => [
            'tanggal' => $today,
            'total_nilai_tertanggung' => number_format((float) $total, 2, '.', ''),
            'nilai_perolehan' => $acquisition,
            'nilai_buku' => $book?->net_book_value,
            'status' => $this->status($total, $acquisition),
            'pertanggungan' => $rows,
        ]]);
    }

    /**
     * Aset yang masih beredar beserta total pertanggungannya pada satu tanggal, disaring statusnya.
     * Bawaannya hanya yang bermasalah: tidak diasuransikan dan kurang diasuransikan.
     */
    public function summary(Request $request, AssetBookValues $books, InsuredValues $insured): JsonResponse
    {
        $this->guard($request);
        $filter = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'legal_entity_id' => ['nullable', 'ulid'],
            'jenis_aset_id' => ['nullable', 'ulid'],
            'lokasi_aset_id' => ['nullable', 'ulid'],
            'status' => ['nullable', Rule::in([self::UNINSURED, self::UNDERINSURED, self::INSURED, 'bermasalah', 'semua'])],
        ]);
        $date = $filter['tanggal'] ?? $this->today();
        $status = $filter['status'] ?? 'bermasalah';

        $acquisition = 'coalesce(buku.acquisition_value, aset_tr_aset.acquisition_value)';
        $total = 'coalesce(tertanggung.total, 0)';
        $query = Aset::query()
            ->leftJoinSub($insured->perAssetQuery($date), 'tertanggung', 'tertanggung.aset_id', '=', 'aset_tr_aset.id')
            ->leftJoinSub($books->query(), 'buku', 'buku.aset_id', '=', 'aset_tr_aset.id')
            ->leftJoin('aset_m_jenis_aset as jenis', function (JoinClause $join): void {
                $join->on('jenis.id', '=', 'aset_tr_aset.jenis_aset_id')->on('jenis.tenant_id', '=', 'aset_tr_aset.tenant_id');
            })
            ->leftJoin('aset_m_lokasi_aset as lokasi', function (JoinClause $join): void {
                $join->on('lokasi.id', '=', 'aset_tr_aset.lokasi_aset_id')->on('lokasi.tenant_id', '=', 'aset_tr_aset.tenant_id');
            })
            ->whereNotIn('aset_tr_aset.lifecycle_state', StatusAset::tidakLagiBeredar());
        app(OrganizationScope::class)->asetQuery($query, $request);
        foreach (['legal_entity_id', 'jenis_aset_id', 'lokasi_aset_id'] as $column) {
            if ($filter[$column] ?? null) {
                $query->where('aset_tr_aset.'.$column, $filter[$column]);
            }
        }
        match ($status) {
            self::UNINSURED => $query->whereRaw("{$total} = 0"),
            self::UNDERINSURED => $query->whereRaw("({$total} > 0 and {$total} < {$acquisition})"),
            self::INSURED => $query->whereRaw("({$total} > 0 and {$total} >= {$acquisition})"),
            'bermasalah' => $query->whereRaw("({$total} = 0 or {$total} < {$acquisition})"),
            default => null,
        };

        $rows = $query->orderBy('aset_tr_aset.kode')->limit(self::LIMIT + 1)->toBase()
            ->select([
                'aset_tr_aset.id', 'aset_tr_aset.kode', 'aset_tr_aset.nama', 'aset_tr_aset.legal_entity_id',
                'aset_tr_aset.jenis_aset_id', 'jenis.nama as jenis_aset_nama', 'aset_tr_aset.lokasi_aset_id', 'lokasi.nama as lokasi_aset_nama',
                'buku.accumulated_depreciation as akumulasi_penyusutan', 'buku.net_book_value as nilai_buku',
            ])
            ->selectRaw("{$acquisition} as nilai_perolehan, {$total} as total_nilai_tertanggung")
            ->get()->all();

        $data = [];
        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $acquired = (string) $row->nilai_perolehan;
            $covered = number_format((float) $row->total_nilai_tertanggung, 2, '.', '');
            $row->total_nilai_tertanggung = $covered;
            $row->kekurangan = number_format(max(0, (float) $acquired - (float) $covered), 2, '.', '');
            $row->status = $this->status($covered, $acquired);
            $data[] = $row;
        }

        return response()->json(['data' => $data, 'meta' => ['tanggal' => $date, 'terpotong' => count($rows) > self::LIMIT]]);
    }

    private function status(string $total, string $acquisition): string
    {
        return match (true) {
            (float) $total <= 0 => self::UNINSURED,
            (float) $total < (float) $acquisition => self::UNDERINSURED,
            default => self::INSURED,
        };
    }

    private function today(): string
    {
        return Carbon::now(app(RequestContext::class)->timezone())->toDateString();
    }

    private function guard(Request $request): void
    {
        abort_unless(in_array('management-aset.polis-asuransi.read', $request->attributes->get('coreerp.permissions', []), true), 403);
    }
}
