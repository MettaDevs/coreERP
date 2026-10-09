<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\PostingFeed;
use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Modules\Contracts\WorkflowEngine;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Models\transaksi\Cancellation\AssetCancellation;
use Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset\DokumenSiklusAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\PenempatanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset\AssetMonitoringLine;
use Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset\MutasiAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset\PenerimaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassification;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassificationLine;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustment;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustmentLine;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;

/** Pembatalan register dan jurnal berada dalam transaksi yang sama, juga ketika berasal dari workflow. */
final class AssetCancellationEngine
{
    public const RESOURCES = ['penyusutan', 'penerimaan-aset', 'penyesuaian-nilai-aset'];

    public function __construct(
        private readonly PostingFeed $feed,
        private readonly RequestContext $context,
        private readonly WorkflowEngine $workflow,
    ) {}

    public function subject(string $resource, string $id, ?Request $request = null, bool $lock = true): DepreciationPeriod|PenerimaanAset|AssetValueAdjustment
    {
        $model = match ($resource) {
            'penyusutan' => DepreciationPeriod::class,
            'penerimaan-aset' => PenerimaanAset::class,
            'penyesuaian-nilai-aset' => AssetValueAdjustment::class,
            default => abort(404),
        };
        $query = $model::query()->whereKey($id);
        if ($request !== null) {
            app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', $resource === 'penyusutan' ? 'usage_org_unit_id' : 'responsible_org_unit_id');
        }

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /** @return array<string,mixed>|null */
    public function summary(string $resource, string $documentId): ?array
    {
        $record = AssetCancellation::query()->where(['resource' => $resource, 'document_id' => $documentId])->latest()->orderByDesc('id')->first();
        if ($record === null) {
            return null;
        }

        return ['status' => $record->status, 'reason' => $record->reason, 'failure_message' => $record->failure_message,
            'posting_date' => $record->posting_date->toDateString(),
            'postings' => array_values(array_filter(array_map(fn (string $id): ?array => $this->feed->status((string) $record->tenant_id, $id), $record->posting_ids ?? [])))];
    }

    /** @return array<string,string> */
    public function blockers(string $resource, DepreciationPeriod|PenerimaanAset|AssetValueAdjustment $document, string $date): array
    {
        if ($document instanceof DepreciationPeriod) {
            if ($document->reverses_period_id !== null || $document->cancelled_at !== null) {
                return ['document' => 'Penyusutan ini sudah dibatalkan atau merupakan jurnal pembalik.'];
            }
            $book = BukuAset::query()->whereKey($document->buku_aset_id)->lockForUpdate()->firstOrFail();
            if ($book->status !== 'active') {
                return ['document' => 'Aset sudah dilepas; penyusutannya tidak dapat dibatalkan.'];
            }
            if ($this->hasReclassification((string) $book->aset_id, $document->period_ends_on->toDateString())
                || AssetValueAdjustmentLine::query()->where('aset_id', $book->aset_id)
                    ->whereIn('penyesuaian_nilai_aset_id', AssetValueAdjustment::query()->where('status', 'posted')
                        ->whereDate('tanggal', '>=', $document->period_ends_on)->select('id'))->exists()) {
                return ['document' => 'Ada penyesuaian nilai atau reklasifikasi setelah penyusutan ini. Periksa transaksi lanjutannya terlebih dahulu.'];
            }
            if (DepreciationPeriod::query()->where('buku_aset_id', $book->id)->whereNull('reverses_period_id')->whereNull('cancelled_at')->whereDate('period_ends_on', '>', $document->period_ends_on)->exists()) {
                return ['document' => 'Batalkan penyusutan yang lebih baru terlebih dahulu agar hitungan berikutnya tetap benar.'];
            }

            return [];
        }
        if ($document instanceof AssetValueAdjustment) {
            if ($document->status !== AssetValueAdjustment::POSTED) {
                return ['document' => 'Hanya penyesuaian yang sudah diposting yang dapat dibatalkan. Draf dapat diarsipkan.'];
            }
            foreach (app(ValueAdjustmentPosting::class)->rows($document, true) as $row) {
                if ($row->book === null || $row->book->status !== 'active') {
                    return ['document' => 'Aset sudah dilepas atau buku aset tidak tersedia.'];
                }
                $problem = app(BookPeriods::class)->problem($row->book->id, $document->tanggal->toDateString(), $row->aset->kode);
                if ($problem !== null) {
                    return ['document' => $problem];
                }
                $column = $document->jenis === AssetValueAdjustment::WRITE_DOWN ? 'write_down_amount' : 'appreciation_amount';
                $newValue = $document->jenis === AssetValueAdjustment::WRITE_DOWN ? BigDecimal::of($row->book->net_book_value)->plus($row->nilai) : BigDecimal::of($row->book->net_book_value)->minus($row->nilai);
                if ($this->hasReclassification((string) $row->aset_id, $document->tanggal->toDateString())
                    || BigDecimal::of($row->book->{$column})->isLessThan($row->nilai) || $newValue->isNegative()) {
                    return ['document' => 'Transaksi lanjutan sudah memakai nilai penyesuaian ini. Pembatalan akan membuat saldo tidak sesuai.'];
                }
            }

            return [];
        }
        if ($document->status !== 'selesai') {
            return ['document' => 'Hanya penerimaan yang sudah selesai yang dapat dibatalkan. Draf dapat diarsipkan.'];
        }
        $assets = Aset::query()->where('penerimaan_aset_id', $document->id)->orderBy('id')->lockForUpdate()->get();
        if ($assets->isEmpty()) {
            return ['document' => 'Aset penerimaan ini sudah tidak tersedia.'];
        }
        foreach ($assets as $asset) {
            $books = BukuAset::query()->where('aset_id', $asset->id)->orderBy('id')->lockForUpdate()->pluck('id');
            $used = $asset->lifecycle_state !== StatusAset::DITERIMA
                || DepreciationPeriod::query()->whereIn('buku_aset_id', $books)->whereNull('cancelled_at')->whereNull('reverses_period_id')->exists()
                || PenempatanAset::query()->where('aset_id', $asset->id)->count() > 1
                || MutasiAsetDetail::query()->where('aset_id', $asset->id)->exists()
                || PemeliharaanAsetDetail::query()->where('aset_id', $asset->id)->exists()
                || AssetMonitoringLine::query()->where('aset_id', $asset->id)->exists()
                || DokumenSiklusAset::query()->where('aset_id', $asset->id)->exists()
                || AssetReclassificationLine::query()->where('aset_id', $asset->id)->exists()
                || AssetValueAdjustmentLine::query()->where('aset_id', $asset->id)->whereIn('penyesuaian_nilai_aset_id', AssetValueAdjustment::query()->where('status', 'posted')->select('id'))->exists();
            if ($used) {
                return ['document' => sprintf('Aset %s sudah mempunyai transaksi lanjutan. Selesaikan pembatalan transaksi lanjutannya terlebih dahulu.', $asset->kode)];
            }
        }

        return [];
    }

    private function hasReclassification(string $assetId, string $date): bool
    {
        return AssetReclassificationLine::query()->where('aset_id', $assetId)
            ->whereIn('reklasifikasi_aset_id', AssetReclassification::query()->where('status', 'posted')->whereDate('tanggal', '>=', $date)->select('id'))->exists();
    }

    public function start(Request $request, string $resource, string $id, bool $submit): AssetCancellation
    {
        $permission = 'management-aset.'.$resource.'.'.($submit ? 'request-cancellation' : 'cancel');
        abort_unless($this->context->hasPermission($permission), 403, 'Anda belum memiliki hak untuk tindakan ini.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:250'], 'posting_date' => ['required', 'date_format:Y-m-d'], 'version' => ['nullable', 'integer', 'min:1']]);
        $data['reason'] = trim($data['reason']);
        abort_if($data['reason'] === '', 422, 'Tulis alasan pembatalan.');

        return DB::transaction(function () use ($request, $resource, $id, $submit, $data): AssetCancellation {
            $this->subject($resource, $id, $request, false);
            $existing = AssetCancellation::query()->where(['resource' => $resource, 'document_id' => $id, 'status' => 'pending'])->first();
            if (! $submit && $existing?->workflow_instance_id !== null) {
                // Urutannya sama dengan persetujuan: instance workflow lalu dokumen sumber.
                $this->workflow->withdraw((string) $existing->tenant_id, AcquisitionPosting::MODULE, $existing->workflow_instance_id, $this->context->userId());
            }
            $document = $this->subject($resource, $id, $request);
            if ($document->getAttribute('version') !== null) {
                $expected = RowVersion::expected($request);
                abort_unless((int) $expected === (int) $document->version, 409, 'Dokumen berubah. Muat ulang sebelum mengajukan pembatalan.');
            }
            if (($problems = $this->blockers($resource, $document, $data['posting_date'])) !== []) {
                throw ValidationException::withMessages($problems);
            }
            $pending = AssetCancellation::query()->where(['resource' => $resource, 'document_id' => $id, 'status' => 'pending'])->first();
            if ($pending !== null) {
                if (! $submit) {
                    if ($pending->workflow_instance_id !== null && $pending->id !== $existing?->id) {
                        $this->workflow->withdraw((string) $pending->tenant_id, AcquisitionPosting::MODULE, $pending->workflow_instance_id, $this->context->userId());
                    }
                    $this->apply($pending, $this->context->userId());
                }

                return $pending->refresh();
            }
            $cancellation = new AssetCancellation;
            $cancellation->forceFill([
                'id' => (string) Str::ulid(), 'tenant_id' => $document->tenant_id,
                'resource' => $resource, 'document_id' => $id, 'legal_entity_id' => $document->legal_entity_id,
                'responsible_org_unit_id' => $document instanceof DepreciationPeriod ? $document->usage_org_unit_id : $document->responsible_org_unit_id,
                'source_version' => $document->getAttribute('version'), 'reason' => $data['reason'],
                'posting_date' => $data['posting_date'], 'requested_by_user_id' => $this->context->userId(), 'status' => 'pending',
            ])->save();
            if (! $submit) {
                $this->apply($cancellation, $this->context->userId());
            } else {
                try {
                    $instance = $this->workflow->submit((string) $document->tenant_id, AcquisitionPosting::MODULE,
                        'management-aset.'.$resource.'-cancellation', $this->context->userId(), (string) $cancellation->id,
                        'cancellation:'.$cancellation->id, [
                            'legal_entity_id' => $document->legal_entity_id, 'source_document_type' => 'pembatalan-aset',
                            'source_document_id' => $cancellation->id,
                            'decision_context' => ['cancellation_id' => $cancellation->id, 'legal_entity_id' => $document->legal_entity_id,
                                'responsible_org_unit_id' => $cancellation->responsible_org_unit_id, 'document_number' => $document instanceof DepreciationPeriod ? ($this->depreciationBook($document)->aset_code.' · '.$document->period_ends_on->toDateString()) : $document->kode,
                                'reason' => $data['reason'], 'posting_date' => $data['posting_date'],
                                'document_url' => $this->documentUrl($resource, $id)],
                        ]);
                } catch (\RuntimeException $failure) {
                    throw ValidationException::withMessages(['workflow' => $failure->getMessage()]);
                }
                $cancellation->update(['workflow_instance_id' => $instance['id']]);
            }

            return $cancellation->refresh();
        }, 3);
    }

    /** @return list<array<string,mixed>> */
    public function previewJournals(string $resource, DepreciationPeriod|PenerimaanAset|AssetValueAdjustment $document, string $date): array
    {
        if ($document instanceof DepreciationPeriod) {
            if ($document->status !== 'final' || $document->posted_posting_id === null) {
                return [];
            }
            $book = $this->depreciationBook($document);
            $posting = app(DepreciationPosting::class)->publishReversal((string) $document->tenant_id, (object) $document->getAttributes(), 'preview-'.$document->id, $book, 'Pratinjau pembatalan', $date, true);

            return $posting === null ? [] : [$posting];
        }
        $original = $document instanceof PenerimaanAset
            ? AcquisitionPosting::postingId((string) $document->id, (string) $document->cara_perolehan) : $document->posting_id;
        if ($original === null) {
            return [];
        }

        return $this->feed->reverse((string) $document->tenant_id, $original, [
            'posting_id' => 'AST-CAN-PREVIEW-'.$document->id, 'posting_date' => $date,
            'include_adjustments' => $resource === 'penerimaan-aset',
            'source_document' => ['module' => AcquisitionPosting::MODULE, 'type' => 'pembatalan-aset', 'id' => $document->id, 'number' => null, 'description' => 'Pratinjau pembatalan'],
        ], true);
    }

    private function depreciationBook(DepreciationPeriod $period): \stdClass
    {
        return BukuAset::query()->join('aset_tr_aset as aset', function ($join): void {
            $join->on('aset.id', '=', 'aset_tr_buku_aset.aset_id')->on('aset.tenant_id', '=', 'aset_tr_buku_aset.tenant_id');
        })->where('aset_tr_buku_aset.id', $period->buku_aset_id)->select('aset_tr_buku_aset.*', 'aset.kode as aset_code', 'aset.currency_code', 'aset.group_aset_id')->lock('FOR UPDATE OF aset_tr_buku_aset')->toBase()->firstOrFail();
    }

    private function documentUrl(string $resource, string $id): string
    {
        return match ($resource) {
            'penyusutan' => DepreciationPosting::SCREEN_URL,
            'penerimaan-aset' => '/management-aset/inventarisasi-aset/penerimaan/'.$id,
            default => '/management-aset/'.$resource.'/'.$id,
        };
    }

    public function apply(AssetCancellation $cancellation, string $actorUserId): void
    {
        if ($cancellation->status !== 'pending') {
            return;
        }
        $document = $this->subject($cancellation->resource, $cancellation->document_id);
        if ($cancellation->source_version !== null && $cancellation->source_version !== (int) $document->version) {
            throw ValidationException::withMessages(['document' => 'Dokumen berubah sejak pengajuan; ajukan pembatalan lagi setelah diperiksa.']);
        }
        if (($problems = $this->blockers($cancellation->resource, $document, $cancellation->posting_date->toDateString())) !== []) {
            throw ValidationException::withMessages($problems);
        }
        $postings = match (true) {
            $document instanceof DepreciationPeriod => $this->cancelDepreciation($document, $cancellation),
            $document instanceof AssetValueAdjustment => $this->cancelValueAdjustment($document, $cancellation),
            $document instanceof PenerimaanAset => $this->cancelReceipt($document, $cancellation),
        };
        $cancellation->update(['status' => 'applied', 'acted_by_user_id' => $actorUserId, 'posting_ids' => $postings]);
    }

    /** @return list<string> */
    private function cancelDepreciation(DepreciationPeriod $period, AssetCancellation $cancellation): array
    {
        $postingIds = [];
        if ($period->status === 'final') {
            $book = $this->depreciationBook($period);
            $reversal = DepreciationPeriod::query()->create([
                'tenant_id' => $period->tenant_id, 'buku_aset_id' => $period->buku_aset_id, 'legal_entity_id' => $period->legal_entity_id,
                'usage_org_unit_id' => $period->usage_org_unit_id, 'period_starts_on' => $period->period_starts_on,
                'period_ends_on' => $period->period_ends_on, 'amount' => (string) BigDecimal::of($period->amount)->negated(),
                'status' => 'final', 'reverses_period_id' => $period->id,
            ]);
            BukuAset::query()->whereKey($book->id)->update([
                'accumulated_depreciation' => (string) BigDecimal::of($book->accumulated_depreciation)->minus($period->amount),
                'net_book_value' => (string) BigDecimal::of($book->net_book_value)->plus($period->amount), 'updated_at' => now(),
            ]);
            $posting = app(DepreciationPosting::class)->publishReversal((string) $period->tenant_id, (object) $period->getAttributes(), (string) $reversal->id, $book, $cancellation->reason, $cancellation->posting_date->toDateString());
            if ($posting !== null) {
                $reversal->update(['posted_posting_id' => $posting['posting_id']]);
                $postingIds[] = $posting['posting_id'];
            }
        }
        $period->forceFill(['cancelled_at' => now()])->save();

        return $postingIds;
    }

    /** @return list<string> */
    private function cancelValueAdjustment(AssetValueAdjustment $document, AssetCancellation $cancellation): array
    {
        $rows = app(ValueAdjustmentPosting::class)->rows($document, true);
        foreach ($rows as $row) {
            $column = $document->jenis === AssetValueAdjustment::WRITE_DOWN ? 'write_down_amount' : 'appreciation_amount';
            BukuAset::query()->whereKey($row->book->id)->update([
                $column => (string) BigDecimal::of($row->book->{$column})->minus($row->nilai),
                'net_book_value' => (string) ($document->jenis === AssetValueAdjustment::WRITE_DOWN
                    ? BigDecimal::of($row->book->net_book_value)->plus($row->nilai)
                    : BigDecimal::of($row->book->net_book_value)->minus($row->nilai)), 'updated_at' => now(),
            ]);
        }
        $postingIds = $this->reverseJournal($document->posting_id, $cancellation);
        $document->forceFill(['status' => 'cancelled', 'version' => (int) $document->version + 1])->save();

        return $postingIds;
    }

    /** @return list<string> */
    private function cancelReceipt(PenerimaanAset $document, AssetCancellation $cancellation): array
    {
        $postingIds = $this->reverseJournal(AcquisitionPosting::postingId((string) $document->id, (string) $document->cara_perolehan), $cancellation, true);
        $assets = Aset::query()->where('penerimaan_aset_id', $document->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($assets as $asset) {
            BukuAset::query()->where('aset_id', $asset->id)->update(['status' => 'closed', 'closed_on' => $cancellation->posting_date->toDateString(), 'net_book_value' => '0', 'updated_at' => now()]);
            $asset->delete();
        }
        $document->update(['status' => 'cancelled', 'version' => (int) $document->version + 1]);

        return $postingIds;
    }

    /** @return list<string> */
    private function reverseJournal(?string $originalPostingId, AssetCancellation $cancellation, bool $adjustments = false): array
    {
        if ($originalPostingId === null) {
            return [];
        }
        $results = $this->feed->reverse((string) $cancellation->tenant_id, $originalPostingId, [
            'posting_id' => 'AST-CAN-'.$cancellation->id, 'posting_date' => $cancellation->posting_date->toDateString(),
            'include_adjustments' => $adjustments,
            'source_document' => ['module' => AcquisitionPosting::MODULE, 'type' => 'pembatalan-aset', 'id' => $cancellation->id,
                'number' => null, 'description' => $cancellation->reason, 'url' => $this->documentUrl($cancellation->resource, $cancellation->document_id)],
        ]);

        return array_column($results, 'posting_id');
    }
}
