<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Jobs;

use App\Platform\Environment\Models\Environment;
use App\Platform\Environment\Models\EnvironmentOperation;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CopyEnvironment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Dump dan restore masing-masing dapat berjalan satu jam. */
    public int $timeout = 7500;

    public int $uniqueFor = 8100;

    public int $maxExceptions = 1;

    public readonly string $queuedAt;

    public function __construct(
        public readonly string $sourceId,
        public readonly string $targetId,
        public readonly string $name,
        public readonly int $requestedBy,
    ) {
        $this->queuedAt = now()->toISOString();
    }

    public function uniqueId(): string
    {
        return $this->sourceId;
    }

    public function retryUntil(): DateTimeInterface
    {
        // Batas tetap, bukan now() setiap retry. Pelepasan karena overlap tidak dianggap error copy.
        return Carbon::parse($this->queuedAt)->addSeconds(8100);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        // Visibility timeout antrean dapat lebih pendek dari dump. Reservasi ulang harus menunggu
        // pekerja lama, bukan menjalankan dump kedua. Tidak perlu mengubah antrean job lain.
        return [(new WithoutOverlapping('environment-copy:'.$this->sourceId))->releaseAfter(60)->expireAfter(7560)];
    }

    public function handle(): void
    {
        $target = Environment::query()->whereKey($this->targetId)->whereNull('deleted_at')->first();

        if (! $target instanceof Environment || $target->source_environment_id !== $this->sourceId) {
            throw new RuntimeException('Sasaran penyalinan tidak lagi tersedia.');
        }

        if (Artisan::call('environment:copy', [
            'source' => $this->sourceId,
            '--name' => $this->name,
            '--requested-by' => $this->requestedBy,
        ]) !== 0) {
            $recorded = EnvironmentOperation::query()->where('environment_id', $this->targetId)
                ->where('operation', 'copy')->where('started_at', '>=', Carbon::parse($this->queuedAt))->exists();
            $reason = ! $recorded ? trim(Artisan::output()) : '';

            throw new RuntimeException($reason !== '' ? mb_substr($reason, 0, 2000) : 'Penyalinan berhenti. Periksa riwayat lingkungan sebelum melanjutkan.');
        }
    }

    public function failed(?Throwable $error): void
    {
        DB::transaction(function () use ($error): void {
            $target = Environment::query()->whereKey($this->targetId)->whereNull('deleted_at')->lockForUpdate()->first();

            if (! $target instanceof Environment || $target->status === 'active' || $target->source_environment_id !== $this->sourceId) {
                return;
            }

            // Perintah menyimpan kegagalannya sendiri. Jangan menimpa operasi yang sudah mulai,
            // apalagi operasi pengganti; catat di sini hanya kegagalan sebelum command sempat masuk.
            if (EnvironmentOperation::query()->where('environment_id', $target->id)->where('operation', 'copy')
                ->where('started_at', '>=', Carbon::parse($this->queuedAt))->exists()) {
                return;
            }

            $reason = trim($error?->getMessage() ?? '');

            EnvironmentOperation::query()->create([
                'environment_id' => $target->id, 'source_environment_id' => $this->sourceId,
                'operation' => 'copy', 'status' => 'failed', 'step' => 'antre',
                'requested_by' => $this->requestedBy, 'started_at' => now(), 'finished_at' => now(),
                'failure_message' => $reason !== '' ? mb_substr($reason, 0, 2000) : 'Penyalinan tidak sempat dimulai. Periksa keadaan sumber lalu lanjutkan penyalinan.',
            ]);
            $target->update(['status' => 'degraded']);
        });
    }
}
