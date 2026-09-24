<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\ProfilPenyusutan;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\PenempatanAset;
use Modules\Apperp\ManagementAset\Services\DepreciationCalculator;
use Modules\Apperp\ManagementAset\Services\DepreciationPosting;
use Modules\Apperp\ManagementAset\Services\DepreciationPostingFailed;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\PostingCheckLines;
use stdClass;

/**
 * @phpstan-import-type HasilProses from DepreciationPosting
 */
class DepreciationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->can($request, 'read');
        $query = DepreciationPeriod::query()->join('aset_tr_buku_aset as book', function ($join): void {
            $join->on('book.id', '=', 'aset_tr_penyusutan_aset.buku_aset_id')->on('book.tenant_id', '=', 'aset_tr_penyusutan_aset.tenant_id');
        })->join('aset_tr_aset as aset', function ($join): void {
            $join->on('aset.id', '=', 'book.aset_id')->on('aset.tenant_id', '=', 'book.tenant_id');
        })->leftJoin('aset_m_buku_penyusutan as buku', function ($join): void {
            // Lapisan posting buku: layar hanya menawarkan buku yang memang mengirim penyusutannya ke
            // aplikasi finance di "Post penyusutan" (K-26, K-31).
            $join->on('buku.id', '=', 'book.buku_id')->on('buku.tenant_id', '=', 'book.tenant_id');
        });
        app(OrganizationScope::class)->query($query, $request, 'aset_tr_penyusutan_aset.legal_entity_id', 'aset_tr_penyusutan_aset.usage_org_unit_id');
        // `toBase()` menjalankan query lewat model — global scope tenant sudah tersisip di
        // dalamnya — tetapi memulangkan baris apa adanya, bukan model. Itu yang menjaga
        // jawaban HTTP tetap sama persis: tanggal tetap 'YYYY-MM-DD' dan angka tetap teks
        // seperti yang dikirim database, bukan hasil cast model.
        $periods = $query->select('aset_tr_penyusutan_aset.*', 'book.book_code', 'book.buku_id', 'buku.posting_layer', 'aset.kode as aset_code', 'aset.currency_code')->orderByDesc('aset_tr_penyusutan_aset.period_ends_on')->toBase()->get();

        return response()->json(['data' => $periods]);
    }

    public function books(Request $request): JsonResponse
    {
        $this->can($request, 'read');
        $query = BukuAset::query()->join('aset_tr_aset as aset', function ($join): void {
            $join->on('aset.id', '=', 'aset_tr_buku_aset.aset_id')->on('aset.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->join('aset_m_profil_penyusutan as profile', function ($join): void {
            $join->on('profile.id', '=', 'aset_tr_buku_aset.depreciation_profile_id')->on('profile.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->where('aset_tr_buku_aset.status', 'active');
        app(OrganizationScope::class)->asetQuery($query, $request, 'aset');
        $books = $query->select('aset_tr_buku_aset.*', 'aset.kode as aset_code', 'aset.currency_code', 'profile.nama as profile_name', 'profile.method', 'profile.frequency')->orderBy('aset.kode')->toBase()->get();

        return response()->json(['data' => $books]);
    }

    public function propose(Request $request): JsonResponse
    {
        $this->can($request, 'create');
        $tenant = $this->tenant($request);
        $data = $request->validate(['buku_aset_id' => ['required', 'ulid'], 'period_starts_on' => ['required', 'date'], 'period_ends_on' => ['required', 'date'], 'consumption_amount' => ['nullable', 'numeric', 'min:0']]);
        $query = BukuAset::query()->join('aset_m_profil_penyusutan as profile', function ($join): void {
            $join->on('profile.id', '=', 'aset_tr_buku_aset.depreciation_profile_id')->on('profile.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->join('aset_tr_aset as aset', function ($join): void {
            $join->on('aset.id', '=', 'aset_tr_buku_aset.aset_id')->on('aset.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->where('aset_tr_buku_aset.id', $data['buku_aset_id']);
        app(OrganizationScope::class)->asetQuery($query, $request, 'aset');
        $book = $query->select(
            'aset_tr_buku_aset.*',
            'profile.method', 'profile.frequency', 'profile.rate_percent', 'profile.manual_schedule',
            // Masa manfaat dan konvensi diambil dari buku aset bila ada, karena di sanalah
            // nilai dari matriks group x buku sudah tersalin; profil hanya cadangannya.
            DB::raw('coalesce(aset_tr_buku_aset.useful_life_periods, profile.useful_life_periods) as useful_life_periods'),
            'aset.legal_entity_id', 'aset.responsible_org_unit_id',
        )->toBase()->first();
        abort_unless($book !== null, 404);
        abort_if(! ($book->depreciate ?? true), 422, 'Buku aset ini ditandai tidak disusutkan.');
        // Buku yang sudah ditutup mengikuti aset yang sudah dijual atau dimusnahkan.
        abort_unless(($book->status ?? 'active') === 'active', 422, 'Buku aset ini sudah ditutup karena asetnya sudah dilepas.');
        abort_if(
            $book->depreciation_start_on !== null && $data['period_ends_on'] < $book->depreciation_start_on,
            422,
            'Periode ini berakhir sebelum aset mulai disusutkan.'
        );
        $existing = DepreciationPeriod::query()->where(['buku_aset_id' => $book->id, 'period_ends_on' => $data['period_ends_on']])->whereNull('reverses_period_id')->toBase()->first();
        if ($existing) {
            return response()->json(['data' => $existing]);
        }
        // Periode yang sudah disusutkan sistem lama sebelum cutover ikut dihitung (TODO 10.4):
        // periode pertama sesudah saldo awal adalah periode ke-(offset + 1).
        $elapsedPeriods = (int) ($book->elapsed_periods_offset ?? 0)
            + DepreciationPeriod::query()->where('buku_aset_id', $book->id)->whereNull('reverses_period_id')->whereDate('period_ends_on', '<', $data['period_ends_on'])->count();
        $calculator = app(DepreciationCalculator::class);
        // Saldo menurun berpindah ke profil alternatif begitu garis lurus sisa umur
        // menghasilkan angka lebih besar, supaya aset tetap habis di akhir masa manfaat.
        $this->applyAlternativeProfile($book, $calculator, $elapsedPeriods);
        $amount = $calculator->amount($book, $elapsedPeriods, $data['consumption_amount'] ?? null);
        $placement = PenempatanAset::query()->where('aset_id', $book->aset_id)->whereDate('effective_on', '<=', $data['period_ends_on'])->orderByDesc('effective_on')->orderByDesc('id')->toBase()->first();
        abort_unless($placement?->usage_org_unit_id, 422, 'Aset belum memiliki unit penggunaan untuk periode ini.');
        app(OrganizationScope::class)->require($request, $book->legal_entity_id, $placement->usage_org_unit_id);
        $period = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'buku_aset_id' => $book->id, 'legal_entity_id' => $book->legal_entity_id, 'usage_org_unit_id' => $placement->usage_org_unit_id, 'period_starts_on' => $data['period_starts_on'], 'period_ends_on' => $data['period_ends_on'], 'amount' => $amount, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()];
        try {
            // Bukan `firstOrCreate`: index unik parsial di database yang memutuskan siapa
            // yang menang, dan yang kalah memperlakukan miliknya sebagai sudah ada.
            (new DepreciationPeriod)->forceFill($period)->save();
        } catch (UniqueConstraintViolationException) {
            $period = DepreciationPeriod::query()->where(['buku_aset_id' => $book->id, 'period_ends_on' => $data['period_ends_on']])->whereNull('reverses_period_id')->toBase()->first();

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

        $query = BukuAset::query()->join('aset_m_profil_penyusutan as profile', function ($join): void {
            $join->on('profile.id', '=', 'aset_tr_buku_aset.depreciation_profile_id')->on('profile.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->join('aset_tr_aset as aset', function ($join): void {
            $join->on('aset.id', '=', 'aset_tr_buku_aset.aset_id')->on('aset.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->where('aset_tr_buku_aset.status', 'active')
            ->where('aset_tr_buku_aset.depreciate', true)
            // Konsumsi butuh angka pemakaian yang hanya diketahui per aset, jadi ia tidak
            // pernah bisa diusulkan massal dan disaring di sini, bukan dilaporkan sebagai
            // ratusan baris terlewat.
            ->where('profile.method', '!=', 'consumption');
        app(OrganizationScope::class)->asetQuery($query, $request, 'aset');
        foreach (['group_aset_id' => 'aset.group_aset_id', 'buku_id' => 'aset_tr_buku_aset.buku_id'] as $input => $column) {
            if ($data[$input] ?? null) {
                $query->where($column, $data[$input]);
            }
        }
        $books = $query->select(
            'aset_tr_buku_aset.*',
            'profile.method', 'profile.frequency', 'profile.rate_percent', 'profile.manual_schedule',
            DB::raw('coalesce(aset_tr_buku_aset.useful_life_periods, profile.useful_life_periods) as useful_life_periods'),
            'aset.legal_entity_id', 'aset.kode as aset_code',
        )->orderBy('aset.kode')->toBase()->get();

        $calculator = app(DepreciationCalculator::class);
        $created = [];
        $skipped = [];

        foreach ($books as $book) {
            $reason = $this->bulkSkipReason($book, $data);
            if ($reason !== null) {
                $skipped[] = ['buku_aset_id' => $book->id, 'aset_code' => $book->aset_code, 'reason' => $reason];

                continue;
            }
            $elapsedPeriods = (int) ($book->elapsed_periods_offset ?? 0) + DepreciationPeriod::query()
                ->where('buku_aset_id', $book->id)
                ->whereNull('reverses_period_id')
                ->whereDate('period_ends_on', '<', $data['period_ends_on'])->count();
            $this->applyAlternativeProfile($book, $calculator, $elapsedPeriods);
            $amount = $calculator->amount($book, $elapsedPeriods);
            if ($amount <= 0.0) {
                $skipped[] = ['buku_aset_id' => $book->id, 'aset_code' => $book->aset_code, 'reason' => 'sudah_habis'];

                continue;
            }
            $placement = PenempatanAset::query()
                ->where('aset_id', $book->aset_id)
                ->whereDate('effective_on', '<=', $data['period_ends_on'])
                ->orderByDesc('effective_on')->orderByDesc('id')->toBase()->first();
            if (! $placement?->usage_org_unit_id) {
                $skipped[] = ['buku_aset_id' => $book->id, 'aset_code' => $book->aset_code, 'reason' => 'tanpa_unit_penggunaan'];

                continue;
            }
            $period = [
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'buku_aset_id' => $book->id,
                'legal_entity_id' => $book->legal_entity_id, 'usage_org_unit_id' => $placement->usage_org_unit_id,
                'period_starts_on' => $data['period_starts_on'], 'period_ends_on' => $data['period_ends_on'],
                'amount' => $amount, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now(),
            ];
            try {
                (new DepreciationPeriod)->forceFill($period)->save();
            } catch (UniqueConstraintViolationException) {
                // Dua tutup bulan berbarengan: yang kalah memperlakukan miliknya sebagai
                // sudah ada, bukan sebagai kegagalan.
                $skipped[] = ['buku_aset_id' => $book->id, 'aset_code' => $book->aset_code, 'reason' => 'sudah_ada'];

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
    private function bulkSkipReason(stdClass $book, array $data): ?string
    {
        if ($book->depreciation_start_on !== null && $data['period_ends_on'] < $book->depreciation_start_on) {
            return 'belum_mulai_menyusut';
        }
        $exists = DepreciationPeriod::query()
            ->where(['buku_aset_id' => $book->id, 'period_ends_on' => $data['period_ends_on']])
            ->whereNull('reverses_period_id')->exists();

        return $exists ? 'sudah_ada' : null;
    }

    /**
     * Memindahkan buku ke profil alternatif bila saldo menurun sudah kalah dari garis
     * lurus sisa umur. Dipakai proposal tunggal maupun massal agar keduanya tidak
     * menyimpang satu sama lain.
     */
    private function applyAlternativeProfile(stdClass $book, DepreciationCalculator $calculator, int $elapsedPeriods): void
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

    /**
     * Mengunci nilai satu periode dan menambahkannya ke saldo buku asetnya.
     *
     * Finalisasi tidak lagi menulis ekspor `aset_tr_export_penyusutan` (feed posting finance, TODO
     * 11.4): penyusutan sampai ke aplikasi finance lewat proses "Post penyusutan", satu jurnal ringkas
     * per entitas legal, buku, dan periode. Tabel ekspor dan riwayatnya dibiarkan dan tetap dapat dibaca.
     */
    public function finalize(Request $request, string $id): JsonResponse
    {
        $this->can($request, 'finalize');
        $scope = app(OrganizationScope::class);
        $result = DB::transaction(function () use ($id, $request, $scope): array {
            $query = DepreciationPeriod::query()->where('id', $id);
            $scope->query($query, $request, 'legal_entity_id', 'usage_org_unit_id');
            $period = $query->lockForUpdate()->toBase()->first();
            abort_unless($period !== null, 404);
            if ($period->status === 'final') {
                // Retry finalisasi harus aman: periode yang sudah final dikembalikan tanpa
                // menambah saldo buku untuk kedua kalinya.
                return ['period' => $period];
            }
            DepreciationPeriod::query()->where('id', $id)->update(['status' => 'final', 'updated_at' => now()]);
            // Penambahan dikerjakan database, bukan PHP, dan itu menahan kehilangan pembaruan:
            // pada `finalize()` yang terkunci hanya periodenya, sehingga dua periode milik satu
            // buku boleh difinalkan bersamaan. `incrementEach()` menyusun ekspresi `kolom + n`
            // yang sama seperti sebelumnya, menolak nilai yang bukan angka, dan ikut mengisi
            // `updated_at`.
            //
            // Pembulatan `round(..., 2)` yang dulu ditulis di sini dibuang karena ia tidak
            // pernah mengubah apa pun: kedua kolom `decimal(18,2)`, dan PostgreSQL membulatkan
            // ke skala kolom saat nilainya disimpan.
            BukuAset::query()->where('id', $period->buku_aset_id)->incrementEach([
                'accumulated_depreciation' => (float) $period->amount,
                'net_book_value' => -(float) $period->amount,
            ]);

            return ['period' => DepreciationPeriod::query()->where('id', $id)->toBase()->first()];
        });

        return response()->json(['data' => $result]);
    }

    /**
     * Membalik satu periode final dengan baris pembalik baru, tanpa mengubah periode asal.
     *
     * Jurnal baliknya (`asset.depreciation_reversal`) terbit di transaksi yang sama, hanya bila periode
     * aslinya sudah di-post (TODO 11.3): pembalikan mengikuti apakah jurnal aslinya sampai ke finance,
     * bukan lapisan posting buku hari ini, sehingga asimetri ekspor lama tidak terulang. Periode yang
     * dibalik sebelum di-post tidak pernah ikut proses post, dan tidak butuh jurnal balik.
     */
    public function reverse(Request $request, string $id): JsonResponse
    {
        $this->can($request, 'correct');
        $tenant = $this->tenant($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);
        $scope = app(OrganizationScope::class);
        try {
            $result = DB::transaction(function () use ($tenant, $id, $data, $request, $scope): array {
                $query = DepreciationPeriod::query()->where('id', $id);
                $scope->query($query, $request, 'legal_entity_id', 'usage_org_unit_id');
                $original = $query->lockForUpdate()->toBase()->first();
                abort_unless($original !== null, 404);
                abort_unless($original->status === 'final', 409, 'Hanya periode final yang dapat dibalik.');
                abort_if(DepreciationPeriod::query()->where('reverses_period_id', $original->id)->exists(), 409, 'Periode penyusutan ini sudah dibalik.');
                $book = BukuAset::query()->join('aset_tr_aset as aset', function ($join): void {
                    $join->on('aset.id', '=', 'aset_tr_buku_aset.aset_id')->on('aset.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
                })->where('aset_tr_buku_aset.id', $original->buku_aset_id)->select('aset_tr_buku_aset.*', 'aset.kode as aset_code', 'aset.currency_code', 'aset.group_aset_id')->lockForUpdate()->toBase()->first();
                abort_unless($book !== null, 404);
                $period = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'buku_aset_id' => $book->id, 'legal_entity_id' => $original->legal_entity_id, 'usage_org_unit_id' => $original->usage_org_unit_id, 'period_starts_on' => $original->period_starts_on, 'period_ends_on' => $original->period_ends_on, 'amount' => -(float) $original->amount, 'status' => 'final', 'reverses_period_id' => $original->id, 'created_at' => now(), 'updated_at' => now()];
                (new DepreciationPeriod)->forceFill($period)->save();
                BukuAset::query()->where('id', $book->id)->incrementEach([
                    'accumulated_depreciation' => -(float) $original->amount,
                    'net_book_value' => (float) $original->amount,
                ]);

                $posting = app(DepreciationPosting::class)->publishReversal($tenant, $original, $period['id'], $book, $data['reason']);
                if ($posting !== null) {
                    DepreciationPeriod::query()->where('id', $period['id'])->update(['posted_posting_id' => $posting['posting_id']]);
                    $period['posted_posting_id'] = $posting['posting_id'];
                }

                return ['period' => $period, 'posting' => $posting === null ? null : ['posting_id' => $posting['posting_id'], 'status' => $posting['status']]];
            });
        } catch (DepreciationPostingFailed $kegagalan) {
            return $this->postingFailed($kegagalan);
        }

        return response()->json(['data' => $result], 201);
    }

    /**
     * Pratinjau "Post penyusutan" satu entitas legal, buku, dan tanggal akhir periode (TODO 11.2.7):
     * jumlah aset yang ikut, total register, jurnal ringkas beserta masalahnya, dan periode yang tidak
     * ikut beserta alasannya. Tidak menyimpan apa pun.
     */
    public function postingPreview(Request $request): JsonResponse
    {
        $this->can($request, 'post');
        $data = $this->postingRequest($request);
        try {
            $hasil = app(DepreciationPosting::class)->preview($request, $data['legal_entity_id'], $data['buku_id'], $data['period_ends_on']);
        } catch (DepreciationPostingFailed $kegagalan) {
            return $this->postingFailed($kegagalan);
        }

        return response()->json(['data' => $this->postingResult($hasil)]);
    }

    /**
     * Proses "Post penyusutan" (TODO 11.2): satu posting `asset.depreciation` untuk periode final yang
     * belum di-post, dijawab 201. Tidak ada yang tersisa untuk di-post dijawab 200 dengan `posting`
     * kosong — proses kedua untuk buku dan periode yang sama, atau yang kalah balapan.
     */
    public function post(Request $request): JsonResponse
    {
        $this->can($request, 'post');
        $data = $this->postingRequest($request);
        try {
            $hasil = app(DepreciationPosting::class)->post($request, $data['legal_entity_id'], $data['buku_id'], $data['period_ends_on']);
        } catch (DepreciationPostingFailed $kegagalan) {
            return $this->postingFailed($kegagalan);
        }

        return response()->json(['data' => $this->postingResult($hasil)], $hasil['posting'] === null ? 200 : 201);
    }

    /**
     * Masukan proses post. Cakupan unit pengguna tetap menyaring periode yang ikut; pengguna yang sama
     * sekali tidak berwenang di entitas legal itu ditolak di sini.
     *
     * @return array{legal_entity_id: string, buku_id: string, period_ends_on: string}
     */
    private function postingRequest(Request $request): array
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'buku_id' => ['required', 'ulid'],
            'period_ends_on' => ['required', 'date_format:Y-m-d'],
        ]);
        app(OrganizationScope::class)->require($request, (string) $data['legal_entity_id'], null);

        return [
            'legal_entity_id' => (string) $data['legal_entity_id'],
            'buku_id' => (string) $data['buku_id'],
            'period_ends_on' => (string) $data['period_ends_on'],
        ];
    }

    /**
     * Hasil pratinjau atau proses post untuk layar: `posting` dipetakan ke bentuk komponen pemeriksaan
     * posting, sama dengan pratinjau penerimaan.
     *
     * @param  HasilProses  $hasil
     * @return array<string, mixed>
     */
    private function postingResult(array $hasil): array
    {
        $posting = $hasil['posting'];
        $payload = $posting['payload'] ?? null;

        return [
            ...$hasil,
            'posting' => $posting === null ? null : [
                'posting_id' => $posting['posting_id'],
                'status' => $posting['status'],
                'posting_date' => $payload['posting_date'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'total' => $payload['totals']['debit'] ?? null,
                'lines' => PostingCheckLines::from($payload),
                'problems' => $posting['problems'],
            ],
        ];
    }

    private function postingFailed(DepreciationPostingFailed $failure): JsonResponse
    {
        return response()->json(['error' => ['code' => 'posting_failed', 'message' => $failure->getMessage()]], 500);
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
