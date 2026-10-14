<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** Rekonstruksi satu kali dari data sumber; semua barisnya ditandai dan tidak mengaku sebagai histori lengkap. */
final class FixedAssetLedgerBackfill
{
    public function run(): void
    {
        DB::table('aset_tr_buku_aset')->orderBy('id')->chunkById(200, function ($books): void {
            foreach ($books as $book) {
                DB::transaction(function () use ($book): void {
                    if (DB::table('aset_tr_ledger_aset')->where('tenant_id', $book->tenant_id)->where('buku_aset_id', $book->id)->exists()) {
                        return;
                    }
                    $asset = DB::table('aset_tr_aset')->where('tenant_id', $book->tenant_id)->where('id', $book->aset_id)->first();
                    if ($asset === null) {
                        throw new \LogicException('Buku aset tanpa induk tidak dapat direkonstruksi.');
                    }
                    $this->book($book, $asset);
                });
            }
        });
    }

    private function book(stdClass $book, stdClass $asset): void
    {
        $reclasses = DB::table('aset_tr_reklasifikasi_aset_buku')->where('tenant_id', $book->tenant_id)
            ->where(fn ($query) => $query->where('buku_aset_asal_id', $book->id)->orWhere('buku_aset_tujuan_id', $book->id))
            ->orderBy('tanggal')->orderBy('id')->get();
        $base = BigDecimal::of((string) $book->acquisition_value);
        $initialGroup = $asset->group_aset_id;
        foreach ($reclasses as $index => $transfer) {
            if ($index === 0 && $transfer->buku_aset_asal_id === $book->id) {
                $initialGroup = $transfer->group_aset_asal_id;
            }
            if ($transfer->buku_aset_asal_id !== $transfer->buku_aset_tujuan_id) {
                $base = $transfer->buku_aset_asal_id === $book->id
                    ? $base->plus($transfer->nilai_perolehan) : $base->minus($transfer->nilai_perolehan);
            }
        }
        if (! $base->isZero() || BigDecimal::of((string) $book->opening_accumulated_depreciation)->isZero() === false) {
            $this->entry($book, $asset, 'acquisition', (string) $asset->acquired_on, 'asset', (string) $asset->id, (string) $asset->kode,
                [(string) $base, (string) $book->opening_accumulated_depreciation, '0', '0'], $initialGroup);
        }
        foreach (DB::table('aset_tr_penyusutan_aset')->where('tenant_id', $book->tenant_id)->where('buku_aset_id', $book->id)->where('status', 'final')->get() as $period) {
            $date = (string) $period->period_ends_on;
            if ($period->reverses_period_id !== null) {
                $cancellation = $this->cancellation((string) $book->tenant_id, 'penyusutan', (string) $period->reverses_period_id);
                $date = $cancellation === null ? $date : (string) $cancellation->posting_date;
            }
            $this->entry($book, $asset, $period->reverses_period_id === null ? 'depreciation' : 'reversal', $date,
                'depreciation-period', (string) $period->id, '', ['0', (string) $period->amount, '0', '0']);
        }
        $adjustments = DB::table('aset_tr_penyesuaian_nilai_aset_details as line')
            ->join('aset_tr_penyesuaian_nilai_aset as header', function ($join): void {
                $join->on('header.id', '=', 'line.penyesuaian_nilai_aset_id')->on('header.tenant_id', '=', 'line.tenant_id');
            })->where('line.tenant_id', $book->tenant_id)->where('line.aset_id', $asset->id)->where('header.buku_id', $book->buku_id)
            ->whereIn('header.status', ['posted', 'cancelled'])->whereNull('line.deleted_at')
            ->select(['header.*', 'line.nilai'])->get();
        foreach ($adjustments as $adjustment) {
            $down = $adjustment->jenis === 'write_down';
            $values = ['0', '0', $down ? (string) $adjustment->nilai : '0', $down ? '0' : (string) $adjustment->nilai];
            $this->entry($book, $asset, $down ? 'write_down' : 'appreciation', (string) $adjustment->tanggal,
                'value-adjustment', (string) $adjustment->id, (string) $adjustment->kode, $values);
            if ($adjustment->status === 'cancelled') {
                $cancellation = $this->cancellation((string) $book->tenant_id, 'penyesuaian-nilai-aset', (string) $adjustment->id);
                if ($cancellation === null) {
                    throw new \LogicException('Penyesuaian dibatalkan tanpa catatan pembatalan.');
                }
                $this->entry($book, $asset, 'reversal', (string) $cancellation->posting_date, 'asset-cancellation', (string) $cancellation->id, '',
                    array_map(static fn (string $value): string => (string) BigDecimal::of($value)->negated(), $values));
            }
        }
        foreach ($reclasses as $transfer) {
            foreach ([-1, 1] as $direction) {
                $origin = $direction === -1;
                if (($origin ? $transfer->buku_aset_asal_id : $transfer->buku_aset_tujuan_id) !== $book->id) {
                    continue;
                }
                $values = array_map(static fn ($value): string => (string) BigDecimal::of((string) $value)->multipliedBy($direction),
                    [$transfer->nilai_perolehan, $transfer->akumulasi_penyusutan, $transfer->penurunan_nilai, $transfer->kenaikan_nilai]);
                $this->entry($book, $asset, 'reclassification', (string) $transfer->tanggal, 'reklasifikasi-aset', (string) $transfer->reklasifikasi_aset_id, '',
                    $values, $origin ? $transfer->group_aset_asal_id : $transfer->group_aset_tujuan_id);
            }
        }
        if ($book->status === 'closed') {
            $this->entry($book, $asset, 'disposal', (string) $book->closed_on, 'asset-book', (string) $book->id, (string) $asset->kode,
                array_map(static fn ($value): string => (string) BigDecimal::of((string) $value)->negated(),
                    [$book->acquisition_value, $book->accumulated_depreciation, $book->write_down_amount, $book->appreciation_amount]));
        }
    }

    private function cancellation(string $tenant, string $resource, string $id): ?stdClass
    {
        return DB::table('aset_tr_pembatalan')->where(['tenant_id' => $tenant, 'resource' => $resource, 'document_id' => $id, 'status' => 'applied'])->first();
    }

    /** @param list<string> $values */
    private function entry(stdClass $book, stdClass $asset, string $type, string $date, string $sourceType, string $sourceId, string $number, array $values, ?string $group = null): void
    {
        DB::table('aset_tr_ledger_aset')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $book->tenant_id, 'aset_id' => $asset->id, 'buku_aset_id' => $book->id, 'buku_id' => $book->buku_id,
            'legal_entity_id' => $asset->legal_entity_id, 'responsible_org_unit_id' => $asset->responsible_org_unit_id,
            'group_aset_id' => $group ?? $asset->group_aset_id, 'asset_code' => $asset->kode, 'asset_name' => $asset->nama,
            'book_code' => $book->book_code, 'currency_code' => $asset->currency_code, 'posting_date' => trim($date) === '' ? null : substr($date, 0, 10),
            'posting_type' => $type, 'source_type' => $sourceType, 'source_id' => $sourceId, 'document_number' => $number,
            'description' => 'Rekonstruksi dari data lama; klasifikasi dan unit yang tidak tersimpan memakai data yang tersedia saat rekonstruksi.',
            'acquisition_delta' => $values[0], 'accumulated_delta' => $values[1], 'write_down_delta' => $values[2], 'appreciation_delta' => $values[3],
            'amount' => (string) BigDecimal::of($values[0])->minus($values[1])->minus($values[2])->plus($values[3]),
            'is_reconstructed' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
