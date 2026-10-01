<?php

namespace App\Platform\Reporting\Jobs;

use App\Platform\ChangeLog\Support\AuditActor;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Reporting\Support\ExportQueue;
use App\Platform\Reporting\Support\ExportStatus;
use App\Platform\Reporting\Support\LayoutStore;
use App\Platform\Reporting\Support\ListExporter;
use App\Platform\Reporting\Support\PrintIdentityStore;
use App\Platform\Reporting\Support\Rendering\DataOnlyWorkbook;
use App\Platform\Reporting\Support\Rendering\RenderException;
use App\Platform\Reporting\Support\Rendering\RenderPipeline;
use App\Platform\Reporting\Support\ReportCatalog;
use App\Platform\Reporting\Support\ReportSource;
use App\Platform\Retention\Support\RetentionService;
use App\Platform\Tenant\Models\TenantMembership;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use stdClass;
use Throwable;

/**
 * Mengerjakan satu permintaan ekspor di worker Core: minta dataset ke app atas nama
 * pengguna, isi layout, ubah format, simpan berkas, tandai selesai. Pengguna tidak
 * menunggu di layar; ia melihat kemajuannya di tray ekspor Shell.
 *
 * Token ke app diterbitkan ulang di sini dari membership, bukan dibekukan saat
 * permintaan dibuat, supaya pencabutan hak antara "Cetak" dan pengerjaan langsung
 * berlaku.
 *
 * ## Yang diulang dan yang tidak
 *
 * Kegagalan **tetap** dicatat ke baris ekspor dan job dianggap selesai, tanpa diulang: layout
 * salah susun, data terlalu besar, hak yang dicabut, atau parameter yang ditolak module akan
 * gagal lagi dengan cara yang sama, dan pesan jelas lebih berguna daripada tiga percobaan
 * diam-diam. Kesalahan tak terduga ikut di sini: hampir selalu cacat kode, dan mengulangnya
 * hanya menggandakan laporan kesalahannya.
 *
 * Gangguan **sesaat** diulang sampai {@see self::tries()} percobaan dengan jeda
 * {@see self::backoff()}, padanan *Maximum No. of Attempts to Run* pada Job Queue Entry
 * Business Central. Sumbernya hanya dua:
 *
 * - layanan PDF yang terlambat menjawab, menolak sambungan, atau menjawab 5xx/408/429
 *   ({@see RenderException::transient()}). Selama menunggu, baris kembali `queued` dan
 *   `failure_message` memuat catatan percobaannya; percobaan terakhir yang gagal menandainya
 *   `failed` dengan pesan yang menyebut jumlah percobaan.
 * - worker yang mati di tengah ekspor (deploy, restart, kehabisan memori). Barisnya tertinggal
 *   `running`; antrean menyerahkan job itu lagi, dan percobaan berikutnya mengambil alih baris
 *   yang sudah lewat masa sewanya.
 *
 * ## Masa sewa baris `running`
 *
 * `retry_after` antrean database (bawaan 90 detik) lebih pendek dari batas waktu job ini, jadi
 * antrean dapat menyerahkan job yang sama ke worker kedua selagi worker pertama masih bekerja.
 * Karena itu baris `running` dianggap masih dipegang sampai `started_at` + batas waktu + jeda;
 * saat itu worker pertama pasti sudah selesai atau dibunuh. Percobaan yang datang lebih cepat
 * mengembalikan job ke antrean sampai sewanya habis, dan pengambilalihan adalah satu UPDATE
 * bersyarat, sehingga satu ekspor tidak pernah dikerjakan dua worker sekaligus.
 *
 * Ekspor yang melewati batas waktu job tidak diulang (`$failOnTimeout`): ia terlalu besar untuk
 * satu ekspor, dan percobaan berikutnya hanya menahan worker sepuluh menit lagi.
 */
final class RunReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    /** Jeda di atas batas waktu sebelum baris `running` dianggap ditinggal worker yang mati. */
    private const LEASE_MARGIN_SECONDS = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $exportId,
    ) {}

    public function tries(): int
    {
        return max(1, (int) config('reporting.export_attempts'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(array_map(intval(...), (array) config('reporting.export_retry_seconds')));
    }

    public function handle(ReportCatalog $catalog, ReportSource $client, LayoutStore $layouts, RenderPipeline $pipeline, PrintIdentityStore $identities, DataOnlyWorkbook $dataOnly, ListExporter $lists): void
    {
        $export = $this->row();
        if ($export === null || ! ExportStatus::isActive($export->status)) {
            return;
        }

        if ($export->status === ExportStatus::RUNNING && $this->mayStillRun($export)) {
            // Worker lain mungkin masih mengerjakannya; kembali setelah sewanya habis. Kalau worker itu
            // sempat selesai, percobaan berikutnya hanya membaca status akhirnya lalu berhenti.
            $this->release($this->secondsUntilAbandoned($export));

            return;
        }
        if (! $this->claim()) {
            return;
        }

        // Worker hidup lama: pelaku dipasang untuk job ini saja, supaya tidak terbawa ke job berikutnya.
        AuditActor::runAs($export->user_id, fn () => $this->export($export, $catalog, $client, $layouts, $pipeline, $identities, $dataOnly, $lists));
    }

    /**
     * Dipanggil antrean ketika job ini menyerah tanpa sempat mencatat sendiri: batas waktu terlewati,
     * atau percobaan habis karena worker berulang kali mati di tengah jalan.
     */
    public function failed(?Throwable $exception): void
    {
        $export = $this->row();
        if ($export === null || ! ExportStatus::isActive($export->status)) {
            return;
        }

        if ($exception instanceof TimeoutExceededException) {
            $message = 'Ekspor dihentikan karena melewati batas waktu '.intdiv($this->timeout, 60).' menit. Persempit filternya, lalu coba lagi.';
        } elseif ($exception instanceof MaxAttemptsExceededException) {
            // Percobaan yang masih memegang sewa akan menulis hasilnya sendiri.
            if ($export->status === ExportStatus::RUNNING && $this->mayStillRun($export)) {
                return;
            }
            $message = 'Ekspor terhenti karena proses di server terputus berulang kali. Coba cetak lagi; bila terulang, hubungi administrator.';
        } else {
            $message = 'Ekspor gagal karena kesalahan tak terduga. Coba lagi; bila terulang, hubungi administrator.';
        }

        $this->markFailed($message, $exception);
    }

    private function export(stdClass $export, ReportCatalog $catalog, ReportSource $client, LayoutStore $layouts, RenderPipeline $pipeline, PrintIdentityStore $identities, DataOnlyWorkbook $dataOnly, ListExporter $lists): void
    {
        $layout = null;
        $rendered = null;
        $identityFiles = [];
        try {
            $membership = TenantMembership::query()->whereKey($export->membership_id)->where('status', 'active')->first()
                ?? throw new RenderException('Keanggotaan Anda tidak lagi aktif; ekspor dibatalkan.');

            if (($export->kind ?? ExportQueue::KIND_LAYOUT) === ExportQueue::KIND_LIST) {
                // Daftar di layar: baris dibaca bertahap dari module dan langsung ditulis ke berkas (K-27).
                $this->update(['progress' => 10]);
                $result = $lists->render($export, $membership, fn (int $written, int $total) => $this->update([
                    'progress' => $total > 0 ? min(95, 10 + intdiv(85 * $written, $total)) : 95,
                ]));
                $rendered = $result['file'];
                $rowCount = $result['rows'];
                $fileName = $result['name'].'-'.now(app(UserClock::class)->timezoneFor($membership->user, $export->legal_entity_id))->format('Ymd-His');
            } else {
                $report = $catalog->find($export->report_code)
                    ?? throw new RenderException('Laporan ini sudah tidak tersedia pada aplikasi yang terpasang.');
                if (! $catalog->canRun($membership, $report)) {
                    throw new RenderException('Anda tidak lagi berhak menjalankan laporan ini.');
                }
                $parameters = json_decode($export->parameters, true, flags: JSON_THROW_ON_ERROR) ?: [];

                // Dataset laporan disusun module di memori, apa pun keluarannya, jadi batas barisnya tetap berlaku.
                $data = $client->dataset($report, $membership, $export->legal_entity_id, $export->org_unit_id, $parameters);
                $limit = (int) config('reporting.max_rows');
                if ($data->rowCount() > $limit) {
                    throw new RenderException("Data terlalu besar untuk satu ekspor ({$data->rowCount()} baris; batas {$limit}). Persempit filternya.");
                }
                $rowCount = $data->rowCount();
                $fileName = $data->fileName;
                $this->update(['progress' => 40, 'row_count' => $rowCount]);

                if (($export->kind ?? ExportQueue::KIND_LAYOUT) === ExportQueue::KIND_DATA) {
                    // "Excel (data saja)": tanpa layout dan tanpa kop (K-26).
                    $rendered = $dataOnly->render($data, $data->definitions);
                } else {
                    // Kop dan footer datang dari identitas cetak organisasi, bukan dari app, supaya
                    // semua dokumen satu legal entity berkop sama tanpa layout perlu menyimpannya.
                    $identity = $identities->placeholders($identities->resolve($this->tenantId, $export->legal_entity_id, $export->org_unit_id));
                    $identityFiles = array_column($identity['images'], 'path');
                    $data = $data->withIdentity($identity['fields'], $identity['images']);

                    $layout = $layouts->resolve($report, $this->tenantId, $export->legal_entity_id, $export->layout_ref, $membership, $export->org_unit_id);
                    $rendered = $pipeline->render($layout, $data, $export->format);
                }
            }
            $this->update(['progress' => 95, 'row_count' => $rowCount]);

            $path = "reporting/exports/{$this->tenantId}/{$this->exportId}.{$rendered->format}";
            $disk = Storage::disk((string) config('reporting.disk'));
            // Aliran, bukan isi berkas utuh di memori: ekspor daftar bisa ratusan MB.
            $stream = fopen($rendered->localPath, 'rb');
            if ($stream === false) {
                throw new RenderException('Berkas hasil ekspor tidak dapat dibaca.');
            }
            try {
                $disk->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $this->update([
                'status' => ExportStatus::DONE,
                'progress' => 100,
                'failure_message' => null,
                'format' => $rendered->format,
                'file_path' => $path,
                'file_name' => $this->safeName($fileName).'.'.$rendered->format,
                'file_mime' => $rendered->mime(),
                'file_size' => $disk->size($path),
                'finished_at' => now(),
                'expires_at' => now()->addDays((int) app(RetentionService::class)->daysFor('report_exports', $this->tenantId)),
            ]);
        } catch (RenderException $exception) {
            if ($exception->transient && $this->canRetry()) {
                $this->retryLater($exception);
            } elseif ($exception->transient && $this->attempts() > 1) {
                $this->markFailed($exception->getMessage().' Sudah dicoba '.$this->attempts().' kali.', $exception);
            } else {
                $this->markFailed($exception->getMessage(), $exception);
            }
        } catch (Throwable $exception) {
            $this->markFailed('Ekspor gagal karena kesalahan tak terduga. Coba lagi; bila terulang, hubungi administrator.', $exception);
        } finally {
            $layout?->cleanup();
            $rendered?->cleanup();
            foreach (array_unique($identityFiles) as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function canRetry(): bool
    {
        // Antrean sync tidak pernah menjalankan ulang job yang dikembalikan; di sana ekspornya gagal langsung.
        return $this->job !== null && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries();
    }

    private function retryLater(RenderException $exception): void
    {
        Log::warning('Ekspor laporan diulang karena gangguan sesaat', [
            'tenant_id' => $this->tenantId, 'export_id' => $this->exportId, 'attempt' => $this->attempts(), 'exception' => $exception,
        ]);
        $this->update([
            'status' => ExportStatus::QUEUED,
            'progress' => 0,
            'started_at' => null,
            'failure_message' => 'Gangguan sesaat saat membuat dokumen. Ekspor dicoba lagi otomatis (percobaan '.($this->attempts() + 1).' dari '.$this->tries().').',
        ]);

        $delays = $this->backoff();
        $this->release($delays === [] ? 0 : $delays[min($this->attempts(), count($delays)) - 1]);
    }

    /** Mengambil alih baris: yang masih menunggu, atau yang `running` tetapi sewanya sudah habis. */
    private function claim(): bool
    {
        return DB::table('report_exports')
            ->where(['tenant_id' => $this->tenantId, 'id' => $this->exportId])
            ->where(fn (Builder $query) => $query
                ->where('status', ExportStatus::QUEUED)
                ->orWhere(fn (Builder $running) => $running
                    ->where('status', ExportStatus::RUNNING)
                    ->where(fn (Builder $lease) => $lease->whereNull('started_at')->orWhere('started_at', '<', $this->abandonedBefore()))))
            ->update(['status' => ExportStatus::RUNNING, 'progress' => 5, 'started_at' => now(), 'updated_at' => now()]) === 1;
    }

    private function mayStillRun(stdClass $export): bool
    {
        return $export->started_at !== null && Carbon::parse($export->started_at)->greaterThanOrEqualTo($this->abandonedBefore());
    }

    private function secondsUntilAbandoned(stdClass $export): int
    {
        $abandonedAt = Carbon::parse($export->started_at)->addSeconds($this->timeout + self::LEASE_MARGIN_SECONDS);

        return max(1, (int) ceil(now()->diffInSeconds($abandonedAt)));
    }

    private function abandonedBefore(): CarbonInterface
    {
        return now()->subSeconds($this->timeout + self::LEASE_MARGIN_SECONDS);
    }

    private function row(): ?stdClass
    {
        return DB::table('report_exports')->where(['tenant_id' => $this->tenantId, 'id' => $this->exportId])->first();
    }

    private function markFailed(string $message, ?Throwable $exception): void
    {
        Log::warning('Ekspor laporan gagal', ['tenant_id' => $this->tenantId, 'export_id' => $this->exportId, 'exception' => $exception]);
        $this->update([
            'status' => ExportStatus::FAILED,
            'failure_message' => $message,
            'finished_at' => now(),
            'expires_at' => now()->addDays((int) app(RetentionService::class)->daysFor('report_exports', $this->tenantId)),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function update(array $values): void
    {
        DB::table('report_exports')->where(['tenant_id' => $this->tenantId, 'id' => $this->exportId])->update([...$values, 'updated_at' => now()]);
    }

    private function safeName(string $name): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-');

        return $clean !== '' ? $clean : 'dokumen';
    }
}
