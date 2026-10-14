<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

/** Metadata transaksi FA, hanya hidup dalam transaksi database yang sedang berjalan. */
final class FixedAssetLedgerContext
{
    public static function set(string $type, string $date, string $sourceType, string $sourceId, string $number = '', string $description = ''): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Metadata ledger harus berada di dalam transaksi sumber.');
        }

        DB::select('select set_config(?, ?, true)', ['coreerp.fa_ledger_context', json_encode([
            'type' => $type, 'date' => $date, 'source_type' => $sourceType,
            'source_id' => $sourceId, 'document_number' => $number, 'description' => $description,
        ], JSON_THROW_ON_ERROR)]);
    }
}
