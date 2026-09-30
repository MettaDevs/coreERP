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
        $pruned = $this->pruneExhaustedAllocations();

        // The backlog is the number that matters operationally: reservations sitting in reconciliation_pending hold
        // pool numbers, and a continuous sequence cannot skip them. Emit it every run so it is alertable.
        $backlog = DB::table('number_sequence_reservations')->where('status', 'reconciliation_pending')->count();
        Log::info('number-sequence.recover.completed', [
            'marked' => $marked,
            'reconciliation_backlog' => $backlog,
            'pruned_exhausted_allocations' => $pruned,
        ]);

        $this->info("{$marked} reservation ditandai untuk rekonsiliasi. Backlog rekonsiliasi saat ini: {$backlog}.");
        $this->info("Blok alokasi habis dibersihkan: {$pruned}.");

        return self::SUCCESS;
    }

    /**
     * Blok alokasi yang sudah habis dibuang karena strukturnya, bukan umurnya: begitu `next_number` melewati
     * `last_number` blok itu tidak bisa dipakai lagi dan hanya memberati indeks. Penghapusan berdasarkan umur
     * (audit dan pool yang sudah dikonfirmasi) ada di layanan retensi, `retention:apply`.
     */
    private function pruneExhaustedAllocations(): int
    {
        return DB::table('number_sequence_allocations')
            ->whereColumn('next_number', '>', 'last_number')->delete();
    }
}
