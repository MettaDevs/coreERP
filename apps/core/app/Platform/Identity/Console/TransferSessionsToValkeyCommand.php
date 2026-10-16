<?php

declare(strict_types=1);

namespace App\Platform\Identity\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Dijalankan saat API dihentikan sebentar; payload sesi tetap opaque dan tidak pernah dicetak. */
final class TransferSessionsToValkeyCommand extends Command
{
    protected $signature = 'core:sessions:transfer-to-valkey';

    protected $description = 'Pindahkan sesi database yang masih hidup ke Valkey sebelum mengganti driver';

    public function handle(): int
    {
        if (config('session.driver') !== 'database' || config('cache.stores.sessions.driver') !== 'redis') {
            $this->error('Jalankan sebelum mengganti driver sesi database.');

            return self::FAILURE;
        }

        $target = Cache::store('sessions');
        $source = DB::connection(config('session.connection'));
        $lifetime = (int) config('session.lifetime', 120) * 60;
        $now = now()->getTimestamp();
        $count = 0;

        $source->transaction(function () use ($source, $target, $lifetime, $now, &$count): void {
            $source->table((string) config('session.table', 'sessions'))
                ->where('last_activity', '>', $now - $lifetime)
                ->orderBy('id')->lockForUpdate()->chunkById(250, function ($sessions) use ($source, $target, $lifetime, &$count): void {
                    foreach ($sessions as $session) {
                        $payload = base64_decode($session->payload, true);
                        if ($payload === false) {
                            throw new RuntimeException('Payload sesi tidak sah; driver belum boleh diganti.');
                        }
                        if (! config('session.encrypt')) {
                            $payload = Crypt::encrypt($payload);
                        }
                        $expiresAt = CarbonImmutable::createFromTimestamp($session->last_activity)->addSeconds($lifetime);
                        if (! $target->put($session->id, $payload, $expiresAt)) {
                            throw new RuntimeException('Sesi tidak dapat dipindahkan; driver belum boleh diganti.');
                        }
                        // Rollback driver tidak boleh menghidupkan kembali salinan sesi yang sudah logout di Valkey.
                        $source->table((string) config('session.table', 'sessions'))->where('id', $session->id)->update(['last_activity' => 0]);
                        $count++;
                    }
                });
        });
        $this->info("Sesi dipindahkan: {$count}. Salinan database telah kedaluwarsa.");

        return self::SUCCESS;
    }
}
