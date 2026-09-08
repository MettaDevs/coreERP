<?php

namespace App\Jobs;

use App\Models\TenantMembership;
use App\Support\Reporting\AppReportClient;
use App\Support\Reporting\ExportStatus;
use App\Support\Reporting\LayoutStore;
use App\Support\Reporting\PrintIdentityStore;
use App\Support\Reporting\Rendering\RenderException;
use App\Support\Reporting\Rendering\RenderPipeline;
use App\Support\Reporting\ReportCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mengerjakan satu permintaan ekspor di worker Core: minta dataset ke app atas nama
 * pengguna, isi layout, ubah format, simpan berkas, tandai selesai. Pengguna tidak
 * menunggu di layar; ia melihat kemajuannya di tray ekspor Shell.
 *
 * Token ke app diterbitkan ulang di sini dari membership, bukan dibekukan saat
 * permintaan dibuat, supaya pencabutan hak antara "Cetak" dan pengerjaan langsung
 * berlaku. Kegagalan dicatat ke baris ekspor dan job dianggap selesai — bukan diulang —
 * karena layout salah susun atau data terlalu besar akan gagal lagi dengan cara yang
 * sama, dan pesan jelas lebih berguna daripada tiga percobaan diam-diam.
 */
final class RunReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $exportId,
    ) {}

    public function handle(ReportCatalog $catalog, AppReportClient $client, LayoutStore $layouts, RenderPipeline $pipeline, PrintIdentityStore $identities): void
    {
        $export = DB::table('report_exports')->where(['tenant_id' => $this->tenantId, 'id' => $this->exportId])->first();
        if ($export === null || $export->status !== ExportStatus::QUEUED) {
            return;
        }
        $this->update(['status' => ExportStatus::RUNNING, 'progress' => 5, 'started_at' => now()]);

        $layout = null;
        $rendered = null;
        $identityFiles = [];
        try {
            $report = $catalog->find($export->report_code)
                ?? throw new RenderException('Laporan ini sudah tidak tersedia pada aplikasi yang terpasang.');
            $membership = TenantMembership::query()->whereKey($export->membership_id)->where('status', 'active')->first()
                ?? throw new RenderException('Keanggotaan Anda tidak lagi aktif; ekspor dibatalkan.');
            if (! $catalog->canRun($membership, $report)) {
                throw new RenderException('Anda tidak lagi berhak menjalankan laporan ini.');
            }
            $parameters = json_decode($export->parameters, true, flags: JSON_THROW_ON_ERROR) ?: [];

            $data = AppReportClient::guard(
                fn () => $client->dataset($report, $membership, $export->legal_entity_id, $export->org_unit_id, $parameters),
                $report,
            );
            $limit = (int) config('reporting.max_rows');
            if ($data->rowCount() > $limit) {
                throw new RenderException("Data terlalu besar untuk satu ekspor ({$data->rowCount()} baris; batas {$limit}). Persempit filternya.");
            }
            // Kop dan footer datang dari identitas cetak organisasi, bukan dari app, supaya
            // semua dokumen satu legal entity berkop sama tanpa layout perlu menyimpannya.
            $identity = $identities->placeholders($identities->resolve($this->tenantId, $export->legal_entity_id, $export->org_unit_id));
            $identityFiles = array_column($identity['images'], 'path');
            $data = $data->withIdentity($identity['fields'], $identity['images']);
            $this->update(['progress' => 40, 'row_count' => $data->rowCount()]);

            $layout = AppReportClient::guard(
                fn () => $layouts->resolve($report, $this->tenantId, $export->legal_entity_id, $export->layout_ref, $membership, $export->org_unit_id),
                $report,
            );
            $rendered = $pipeline->render($layout, $data, $export->format);
            $this->update(['progress' => 85]);

            $path = "reporting/exports/{$this->tenantId}/{$this->exportId}.{$rendered->format}";
            $disk = Storage::disk((string) config('reporting.disk'));
            $disk->put($path, file_get_contents($rendered->localPath));

            $this->update([
                'status' => ExportStatus::DONE,
                'progress' => 100,
                'file_path' => $path,
                'file_name' => $this->safeName($data->fileName).'.'.$rendered->format,
                'file_mime' => $rendered->mime(),
                'file_size' => $disk->size($path),
                'finished_at' => now(),
                'expires_at' => now()->addDays((int) config('reporting.retention_days')),
            ]);
        } catch (RenderException $exception) {
            $this->markFailed($exception->getMessage(), $exception);
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

    private function markFailed(string $message, Throwable $exception): void
    {
        Log::warning('Ekspor laporan gagal', ['tenant_id' => $this->tenantId, 'export_id' => $this->exportId, 'exception' => $exception]);
        $this->update([
            'status' => ExportStatus::FAILED,
            'failure_message' => $message,
            'finished_at' => now(),
            'expires_at' => now()->addDays((int) config('reporting.retention_days')),
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
