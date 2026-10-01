<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Disposal;

use App\Platform\Modules\Contracts\PostingFeed;
use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset\DokumenSiklusAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Services\DisposalPosting;
use Modules\Apperp\ManagementAset\Services\DisposalPostingFailed;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\PostingCheckLines;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use stdClass;

/**
 * Draf penjualan dan pemusnahan aset sampai diposting — alur jurnal aset tetap Business Central: baris
 * *Disposal* disusun dulu, diperiksa lewat *Preview Posting*, lalu *Post* yang melepas aset dan menulis
 * jurnalnya. Dokumen dibuat lewat `DokumenSiklusAsetController::store()` sebagai draf; controller ini
 * membaca, mengubah, membatalkan, mempratinjau, dan memposting draf itu.
 *
 * **Aset baru dilepas saat diposting.** Memposting menerbitkan jurnal pelepasan (`DisposalPosting`) dari
 * saldo buku yang di-post ke finance, lalu menandai aset `disposed` dan menutup seluruh bukunya, di satu
 * transaksi: jurnal yang gagal diterbitkan berarti aset tidak dilepas.
 *
 * Mengubah dan membatalkan draf memakai permission membuat dokumennya: dokumen siklus tidak punya
 * permission ubah (lihat `AssetAttachments`). Memposting punya permission sendiri, karena ia mengirim jurnal
 * ke aplikasi finance.
 */
class AssetDisposalController extends Controller
{
    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    private const DRAFT_ONLY = 'Dokumen ini sudah diposting atau dibatalkan, jadi tidak dapat diubah lagi.';

    public function show(Request $request, string $id): JsonResponse
    {
        $type = $this->type($request);
        $this->guard($request, $type, 'read');

        return $this->respond($request, $type, $id);
    }

    /** Mengubah tanggal, nilai, atau keterangan draf. Asetnya tetap; draf untuk aset lain dibuat baru. */
    public function update(Request $request, string $id): JsonResponse
    {
        $type = $this->type($request);
        $this->guard($request, $type, 'create');
        $this->find($request, $type, $id);
        $data = $request->validate([
            'tanggal' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'nilai' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'keterangan' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if ($type === DisposalPosting::SCRAP && ! empty($data['nilai'])) {
            throw ValidationException::withMessages(['nilai' => DisposalPosting::SCRAP_HAS_NO_PROCEEDS]);
        }
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version, $data): void {
            RowVersion::claim(DokumenSiklusAset::query()->whereKey($id), $version);
            $updated = DokumenSiklusAset::query()
                ->where(['id' => $id, 'status' => self::DRAFT])
                ->update([...$data, 'updated_at' => now()]);
            abort_unless($updated > 0, 422, self::DRAFT_ONLY);
        });

        return $this->respond($request, $type, $id);
    }

    /** Membatalkan draf. Dokumen siklus tidak pernah dihapus; statusnya yang berubah. */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $type = $this->type($request);
        $this->guard($request, $type, 'create');
        $this->find($request, $type, $id);
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(DokumenSiklusAset::query()->whereKey($id), $version);
            $updated = DokumenSiklusAset::query()
                ->where(['id' => $id, 'status' => self::DRAFT])
                ->update(['status' => self::CANCELLED, 'updated_at' => now()]);
            abort_unless($updated > 0, 422, self::DRAFT_ONLY);
        });

        return $this->respond($request, $type, $id);
    }

    /**
     * Pratinjau jurnal pelepasan draf ini (K-22, *Preview Posting* BC): nilai buku yang dikeluarkan,
     * laba/rugi, jurnalnya dengan pemeriksaan yang sama persis dengan penerbitan, dan yang menahan posting.
     * Tidak menyimpan apa pun.
     */
    public function preview(Request $request, string $id): JsonResponse
    {
        $type = $this->type($request);
        $this->guardAny($request, $type, ['post', 'create']);
        $dokumen = $this->find($request, $type, $id);
        abort_unless($dokumen->status === self::DRAFT, 422, self::DRAFT_ONLY);
        $aset = Aset::query()->whereKey($dokumen->aset_id)->firstOrFail();
        $pelepasan = app(DisposalPosting::class);
        $masalah = $this->blockers($aset, $type, $dokumen);
        try {
            // Nilai yang tidak dapat dijurnal sama persis tidak disusun jurnalnya; penerbit akan menolaknya.
            $hasil = isset($masalah['nilai'])
                ? ['note' => null, 'amounts' => null, 'posting' => null]
                : $pelepasan->preview($aset, $type, $this->date($dokumen), $this->proceeds($dokumen), $dokumen->keterangan);
        } catch (DisposalPostingFailed $kegagalan) {
            return $this->postingFailed($kegagalan);
        }
        $posting = $hasil['posting'];
        $payload = $posting['payload'] ?? null;

        return response()->json(['data' => [
            'currency_code' => $aset->currency_code,
            'blockers' => array_map(static fn (string $field, string $message): array => ['field' => $field, 'message' => $message], array_keys($masalah), array_values($masalah)),
            'note' => $hasil['note'],
            'amounts' => $hasil['amounts'],
            'posting' => $posting === null ? null : [
                'posting_id' => $posting['posting_id'],
                'status' => $posting['status'],
                'posting_date' => $payload['posting_date'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'total' => $payload['totals']['debit'] ?? null,
                'lines' => PostingCheckLines::from($payload),
                'problems' => $posting['problems'],
            ],
        ]]);
    }

    /**
     * Memposting draf: jurnal pelepasan terbit, aset menjadi `disposed`, seluruh bukunya ditutup per tanggal
     * dokumen, dan dokumen terkunci — satu transaksi.
     */
    public function post(Request $request, string $id): JsonResponse
    {
        $type = $this->type($request);
        $this->guard($request, $type, 'post');
        $this->find($request, $type, $id);
        $version = RowVersion::expected($request);

        try {
            DB::transaction(function () use ($id, $type, $version): void {
                // Versi diklaim lebih dulu, baru barisnya dibaca: baris yang dibaca sebelum klaim dapat sudah
                // diganti penyimpanan lain yang lolos di antaranya.
                RowVersion::claim(DokumenSiklusAset::query()->whereKey($id), $version);
                $dokumen = DokumenSiklusAset::query()->whereKey($id)->toBase()->first();
                abort_unless($dokumen !== null && $dokumen->status === self::DRAFT, 422, self::DRAFT_ONLY);
                // Aset dikunci dan diperiksa ulang di dalam transaksi: dua draf untuk aset yang sama tidak
                // boleh sama-sama terposting, karena keduanya akan menjurnal saldo yang sama.
                $aset = Aset::query()->whereKey($dokumen->aset_id)->lockForUpdate()->firstOrFail();
                $masalah = $this->blockers($aset, $type, $dokumen);
                if ($masalah !== []) {
                    throw ValidationException::withMessages($masalah);
                }

                // Jurnalnya terbit sebelum buku ditutup, dari saldo buku yang sama.
                app(DisposalPosting::class)->publish($aset, $type, $this->date($dokumen), $this->proceeds($dokumen), [
                    'id' => (string) $dokumen->id, 'kode' => (string) $dokumen->kode, 'keterangan' => $dokumen->keterangan,
                ]);
                Aset::query()->whereKey($aset->id)->update(['lifecycle_state' => StatusAset::DILEPAS, 'updated_at' => now()]);
                BukuAset::query()
                    ->where(['aset_id' => $aset->id, 'status' => 'active'])
                    ->update(['status' => 'closed', 'closed_on' => $this->date($dokumen), 'updated_at' => now()]);
                DokumenSiklusAset::query()->whereKey($id)->update(['status' => self::POSTED, 'updated_at' => now()]);
            });
        } catch (DisposalPostingFailed $kegagalan) {
            return $this->postingFailed($kegagalan);
        }

        return $this->respond($request, $type, $id);
    }

    /**
     * Yang menahan posting: aset harus sudah dihentikan pemakaiannya, lalu aturan penyusutan dan nilai
     * dari `DisposalPosting::blockers()`.
     *
     * @return array<string, string>
     */
    private function blockers(Aset $aset, string $type, stdClass $dokumen): array
    {
        if (! StatusAset::bolehDilepas($aset->lifecycle_state)) {
            return ['aset_id' => StatusAset::sudahDilepas($aset->lifecycle_state)
                ? 'Aset ini sudah dilepas lewat dokumen lain.'
                : 'Aset harus disetujui untuk dekomisioning sebelum dijual atau dimusnahkan.'];
        }

        return app(DisposalPosting::class)->blockers($aset, $type, $this->date($dokumen), $this->proceeds($dokumen));
    }

    /** Dokumen beserta aset, versi, ETag, dan keadaan jurnal pelepasannya. */
    private function respond(Request $request, string $type, string $id): JsonResponse
    {
        $dokumen = $this->find($request, $type, $id);
        $aset = Aset::withTrashed()->whereKey($dokumen->aset_id)->toBase()->first(['kode', 'nama', 'currency_code', 'lifecycle_state']);
        $keadaan = $dokumen->status === self::POSTED && $dokumen->aset_id !== null
            ? app(PostingFeed::class)->status((string) $dokumen->tenant_id, DisposalPosting::postingId((string) $dokumen->aset_id))
            : null;

        return response()->json(['data' => [
            ...(array) $dokumen,
            'aset_kode' => $aset->kode ?? null,
            'aset_nama' => $aset->nama ?? null,
            'currency_code' => $aset->currency_code ?? null,
            'aset_lifecycle_state' => $aset->lifecycle_state ?? null,
            'posting' => $keadaan === null ? null : ['posting_id' => $keadaan['posting_id'], 'status' => $keadaan['status']],
        ]], 200, ['ETag' => RowVersion::etag((int) $dokumen->version)]);
    }

    /** Dokumen jenis ini dalam jangkauan organisasi pengguna; 404 bila tidak. */
    private function find(Request $request, string $type, string $id): stdClass
    {
        $query = DokumenSiklusAset::query()->where(['id' => $id, 'jenis_dokumen' => $type]);
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'responsible_org_unit_id');
        $dokumen = $query->toBase()->first();
        abort_unless($dokumen !== null && $dokumen->aset_id !== null, 404);

        return $dokumen;
    }

    private function proceeds(stdClass $dokumen): ?string
    {
        return $dokumen->nilai === null ? null : (string) $dokumen->nilai;
    }

    private function date(stdClass $dokumen): string
    {
        return substr((string) $dokumen->tanggal, 0, 10);
    }

    private function postingFailed(DisposalPostingFailed $failure): JsonResponse
    {
        return response()->json(['error' => ['code' => 'posting_failed', 'message' => $failure->getMessage()]], 500);
    }

    /** `penjualan-aset` atau `pemusnahan-aset`, dari ruas pertama alamat sesudah prefix module. */
    private function type(Request $request): string
    {
        foreach ([DisposalPosting::SALE, DisposalPosting::SCRAP] as $type) {
            if (str_contains('/'.$request->path().'/', '/'.$type.'/')) {
                return $type;
            }
        }
        abort(404);
    }

    private function guard(Request $request, string $type, string $action): void
    {
        $this->guardAny($request, $type, [$action]);
    }

    /** @param  list<string>  $actions */
    private function guardAny(Request $request, string $type, array $actions): void
    {
        $granted = $request->attributes->get('coreerp.permissions', []);
        foreach ($actions as $action) {
            if (in_array('management-aset.'.$type.'.'.$action, $granted, true)) {
                return;
            }
        }
        abort(403);
    }
}
