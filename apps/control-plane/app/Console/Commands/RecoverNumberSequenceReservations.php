<?php

namespace App\Console\Commands;

use App\Actions\NumberSequence\NumberSequenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecoverNumberSequenceReservations extends Command
{
    protected $signature = 'number-sequences:recover';

    protected $description = 'Move expired continuous number reservations to reconciliation_pending.';

    /** Cache stores that are local to one instance, which makes the scheduler's onOneServer lock meaningless. */
    private const LOCAL_CACHE_STORES = ['array', 'file', 'null'];

    public function handle(NumberSequenceService $service): int
    {
        $store = config('cache.default');
        if (in_array($store, self::LOCAL_CACHE_STORES, true) && ! app()->runningUnitTests()) {
            // onOneServer relies on a lock every instance can see. With a per-instance store each instance believes
            // it is alone and the job runs everywhere at once.
            $this->warn("Cache store [{$store}] is local to this instance, so the scheduler's onOneServer lock cannot coordinate a cluster. Use database or redis.");
            Log::warning('number-sequence.recover.unsafe-cache-store', ['store' => $store]);
        }

        $marked = $service->recoverExpired();
        $pruned = $this->prune();

        // The backlog is the number that matters operationally: reservations sitting in reconciliation_pending hold
        // pool numbers, and a continuous sequence cannot skip them. Emit it every run so it is alertable.
        $backlog = DB::table('number_sequence_reservations')->where('status', 'reconciliation_pending')->count();
        Log::info('number-sequence.recover.completed', [
            'marked' => $marked,
            'reconciliation_backlog' => $backlog,
            'pruned' => $pruned,
        ]);

        $this->info("{$marked} reservation ditandai untuk rekonsiliasi. Backlog rekonsiliasi saat ini: {$backlog}.");
        $this->info("Baris kedaluwarsa dibersihkan: pool {$pruned['confirmed_pool']}, audit {$pruned['audit_events']}, blok habis {$pruned['exhausted_allocations']}.");

        return self::SUCCESS;
    }

    /**
     * Bounded growth is a correctness property here, not housekeeping. The continuous pool writes one row per number
     * and the audit table one row per issue, so without retention both grow forever and take the hot indexes with
     * them. A confirmed pool row is redundant once number_sequence_issues holds the same number.
     *
     * @return array{confirmed_pool:int,audit_events:int,exhausted_allocations:int}
     */
    private function prune(): array
    {
        $poolCutoff = now()->subDays((int) config('coreerp.confirmed_pool_retention_days', 30));
        $auditCutoff = now()->subDays((int) config('coreerp.audit_retention_days', 400));

        return [
            'confirmed_pool' => DB::table('number_sequence_continuous_pool')
                ->where('status', 'confirmed')->where('updated_at', '<', $poolCutoff)->delete(),
            'audit_events' => DB::table('number_sequence_audit_events')
                ->where('occurred_at', '<', $auditCutoff)->delete(),
            'exhausted_allocations' => DB::table('number_sequence_allocations')
                ->whereColumn('next_number', '>', 'last_number')->delete(),
        ];
    }
}
