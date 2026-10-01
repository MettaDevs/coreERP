<?php

namespace App\Support\Reporting;

use App\Jobs\RunReportExport;
use App\Platform\Tenant\Models\TenantMembership;
use App\Support\Retention\RetentionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Membuat, membaca, dan membersihkan permintaan ekspor. Yang dilihat seorang pengguna
 * hanya ekspor yang ia minta sendiri; dokumen orang lain bukan urusannya.
 */
final class ExportQueue
{
    /** Ekspor ber-layout: Word, Excel, atau PDF dari layout laporan. */
    public const KIND_LAYOUT = 'layout';

    /** "Excel (data saja)": dataset laporan apa adanya, tanpa layout (K-26). */
    public const KIND_DATA = 'data';

    /** Daftar di layar module: kolom, urutan, dan filter yang tampil, tanpa layout (K-27). */
    public const KIND_LIST = 'list';

    public function __construct(
        private readonly LayoutStore $layouts,
        private readonly RetentionService $retention,
        private readonly ReportOptions $options,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function enqueue(stdClass $report, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId, string $format, ?string $layoutRef, array $parameters, bool $dataOnly = false): array
    {
        $tenantId = $membership->tenant_id;
        $this->assertCapacity($membership);

        if ($dataOnly) {
            $id = $this->insert($membership, $legalEntityId, $orgUnitId, [
                'kind' => self::KIND_DATA,
                'app_id' => $report->app_id,
                'report_code' => $report->code,
                'report_name' => $report->name,
                'layout_ref' => '',
                'layout_name' => 'Data saja, tanpa layout',
                'format' => 'xlsx',
                'parameters' => $parameters,
            ]);
            // Opsi terakhir dicatat saat laporan dijalankan, seperti "Last used options and filters" BC.
            $this->options->rememberLastUsed($membership, $report, $parameters, self::KIND_DATA);

            return $this->present($this->find($tenantId, $membership->user_id, $id));
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

        $id = $this->insert($membership, $legalEntityId, $orgUnitId, [
            'kind' => self::KIND_LAYOUT,
            'app_id' => $report->app_id,
            'report_code' => $report->code,
            'report_name' => $report->name,
            'layout_ref' => $ref,
            'layout_name' => $this->layouts->name($report, $tenantId, $legalEntityId, $ref),
            'format' => $format,
            'parameters' => $parameters,
        ]);
        $this->options->rememberLastUsed($membership, $report, $parameters, $format, $ref);

        return $this->present($this->find($tenantId, $membership->user_id, $id));
    }

    /**
     * Ekspor daftar di layar (K-27). `$request` memuat kolom yang tampil beserta judulnya, urutan, dan filter
     * daftar; semuanya sudah diperiksa pemanggil terhadap daftar milik module.
     *
     * @param  array{columns: list<array{key: string, header: string}>, sort: array{column: string, direction: string}|null, filters: array<string, mixed>}  $request
     * @return array<string, mixed>
     */
    public function enqueueList(string $appId, string $listCode, string $listName, TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId, array $request): array
    {
        $this->assertCapacity($membership);
        $id = $this->insert($membership, $legalEntityId, $orgUnitId, [
            'kind' => self::KIND_LIST,
            'app_id' => $appId,
            'report_code' => ListExportRegistry::code($appId, $listCode),
            'report_name' => $listName,
            'layout_ref' => '',
            'layout_name' => 'Tampilan daftar',
            // xlsx sampai batas satu lembar Excel; worker menggantinya CSV bila barisnya lebih banyak.
            'format' => 'xlsx',
            'parameters' => $request,
        ]);

        return $this->present($this->find($membership->tenant_id, $membership->user_id, $id));
    }

    private function assertCapacity(TenantMembership $membership): void
    {
        $active = DB::table('report_exports')
            ->where(['tenant_id' => $membership->tenant_id, 'user_id' => $membership->user_id])
            ->whereIn('status', [ExportStatus::QUEUED, ExportStatus::RUNNING])
            ->count();
        if ($active >= (int) config('reporting.max_active_per_user')) {
            throw ValidationException::withMessages(['format' => ['Masih ada '.$active.' ekspor Anda yang sedang dikerjakan. Tunggu sampai selesai sebelum meminta lagi.']]);
        }
    }

    /** @param array<string, mixed> $values */
    private function insert(TenantMembership $membership, ?string $legalEntityId, ?string $orgUnitId, array $values): string
    {
        $id = (string) Str::ulid();
        DB::table('report_exports')->insert([
            ...$values,
            'id' => $id,
            'tenant_id' => $membership->tenant_id,
            'membership_id' => $membership->id,
            'user_id' => $membership->user_id,
            'legal_entity_id' => $legalEntityId,
            'org_unit_id' => $orgUnitId,
            'parameters' => json_encode((object) $values['parameters'], JSON_THROW_ON_ERROR),
            'status' => ExportStatus::QUEUED,
            'progress' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RunReportExport::dispatch($membership->tenant_id, $id);

        return $id;
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

    /** Menghapus hasil tenant yang lewat masa simpan lewat layanan retensi; dipanggil saat daftar dibaca. */
    public function purgeExpired(string $tenantId): int
    {
        return $this->retention->apply('report_exports', $tenantId);
    }

    /** @return array<string, mixed> */
    public function present(object $row): array
    {
        return [
            'id' => $row->id,
            'kind' => $row->kind ?? self::KIND_LAYOUT,
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
