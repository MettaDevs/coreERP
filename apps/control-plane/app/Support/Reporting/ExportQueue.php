<?php

namespace App\Support\Reporting;

use App\Jobs\RunReportExport;
use App\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Membuat, membaca, dan membersihkan permintaan ekspor. Yang dilihat seorang pengguna
 * hanya ekspor yang ia minta sendiri; dokumen orang lain bukan urusannya.
 */
final class ExportQueue
{
    public function __construct(private readonly LayoutStore $layouts) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function enqueue(object $report, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId, string $format, ?string $layoutRef, array $parameters): array
    {
        $tenantId = $membership->tenant_id;
        $active = DB::table('report_exports')
            ->where(['tenant_id' => $tenantId, 'user_id' => $membership->user_id])
            ->whereIn('status', [ExportStatus::QUEUED, ExportStatus::RUNNING])
            ->count();
        if ($active >= (int) config('reporting.max_active_per_user')) {
            throw ValidationException::withMessages(['format' => ['Masih ada '.$active.' ekspor Anda yang sedang dikerjakan. Tunggu sampai selesai sebelum meminta lagi.']]);
        }

        $ref = $layoutRef ?: $this->layouts->defaultRef($report, $tenantId, $legalEntityId);
        if ($ref === '' || ! $this->layouts->exists($report, $tenantId, $legalEntityId, $ref)) {
            throw ValidationException::withMessages(['layout_ref' => ['Layout yang dipilih tidak ada.']]);
        }
        $layoutFormat = (string) $this->layouts->format($report, $tenantId, $legalEntityId, $ref);
        if (! in_array($format, LayoutFile::outputFormatsFor($layoutFormat), true)) {
            throw ValidationException::withMessages(['format' => [
                'Layout '.strtoupper($layoutFormat).' hanya dapat menghasilkan '.implode(' atau ', array_map('strtoupper', LayoutFile::outputFormatsFor($layoutFormat))).'.',
            ]]);
        }

        $id = (string) Str::ulid();
        DB::table('report_exports')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'membership_id' => $membership->id,
            'user_id' => $membership->user_id,
            'legal_entity_id' => $legalEntityId,
            'org_unit_id' => $orgUnitId,
            'app_id' => $report->app_id,
            'report_code' => $report->code,
            'report_name' => $report->name,
            'layout_ref' => $ref,
            'layout_name' => $this->layouts->name($report, $tenantId, $legalEntityId, $ref),
            'format' => $format,
            'parameters' => json_encode((object) $parameters, JSON_THROW_ON_ERROR),
            'status' => ExportStatus::QUEUED,
            'progress' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RunReportExport::dispatch($tenantId, $id);

        return $this->present($this->find($tenantId, $membership->user_id, $id));
    }

    /** @return list<array<string, mixed>> */
    public function mine(string $tenantId, int $userId): array
    {
        $this->purgeExpired($tenantId);

        return DB::table('report_exports')
            ->where(['tenant_id' => $tenantId, 'user_id' => $userId])
            ->orderByDesc('created_at')
            ->limit((int) config('reporting.history_per_user'))
            ->get()
            ->map(fn (object $row): array => $this->present($row))
            ->all();
    }

    public function find(string $tenantId, int $userId, string $id): ?object
    {
        return DB::table('report_exports')->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'id' => $id])->first();
    }

    public function delete(object $export): void
    {
        DB::table('report_exports')->where(['tenant_id' => $export->tenant_id, 'id' => $export->id])->delete();
        if ($export->file_path) {
            Storage::disk((string) config('reporting.disk'))->delete($export->file_path);
        }
    }

    /** Menghapus hasil yang lewat masa simpan; dipanggil command terjadwal dan saat daftar dibaca. */
    public function purgeExpired(?string $tenantId = null): int
    {
        $query = DB::table('report_exports')->where('expires_at', '<', now());
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        $expired = $query->limit(200)->get(['tenant_id', 'id', 'file_path']);
        $disk = Storage::disk((string) config('reporting.disk'));
        foreach ($expired as $row) {
            DB::table('report_exports')->where(['tenant_id' => $row->tenant_id, 'id' => $row->id])->delete();
            if ($row->file_path) {
                $disk->delete($row->file_path);
            }
        }

        return $expired->count();
    }

    /** @return array<string, mixed> */
    public function present(object $row): array
    {
        return [
            'id' => $row->id,
            'app_id' => $row->app_id,
            'report_code' => $row->report_code,
            'report_name' => $row->report_name,
            'layout_ref' => $row->layout_ref,
            'layout_name' => $row->layout_name,
            'format' => $row->format,
            'parameters' => is_string($row->parameters) ? json_decode($row->parameters, true) : $row->parameters,
            'status' => $row->status,
            'progress' => (int) $row->progress,
            'row_count' => isset($row->row_count) ? (int) $row->row_count : null,
            'file_name' => $row->file_name ?? null,
            'file_size' => isset($row->file_size) ? (int) $row->file_size : null,
            'failure_message' => $row->failure_message ?? null,
            'started_at' => $row->started_at ?? null,
            'finished_at' => $row->finished_at ?? null,
            'expires_at' => $row->expires_at ?? null,
            'created_at' => $row->created_at,
        ];
    }
}
