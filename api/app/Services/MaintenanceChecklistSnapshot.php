<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MaintenanceChecklistSnapshot
{
    /**
     * Mengambil default paling spesifik yang cocok dengan pekerjaan lalu menyalin template
     * menjadi snapshot checklist. Template tidak pernah dirujuk langsung oleh hasil kerja.
     */
    public function applyDefault(string $tenantId, object $job): int
    {
        $templateId = $this->defaultTemplateId($tenantId, $job);
        if ($templateId === null) {
            return 0;
        }

        return $this->copyTemplate($tenantId, (string) $job->id, $templateId);
    }

    public function copyTemplate(string $tenantId, string $jobId, string $templateId): int
    {
        $lines = $this->expandTemplate($tenantId, $templateId);
        if ($lines === []) {
            return 0;
        }

        $rows = collect($lines)->values()->map(fn (array $line, int $index): array => [
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'pemeliharaan_aset_detail_id' => $jobId,
            'line_number' => $index + 1,
            'nama' => $line['nama'],
            'instruksi' => $line['instruksi'],
            'tipe' => $line['tipe'],
            'satuan' => $line['satuan'],
            'wajib' => $line['wajib'],
            'sumber' => 'template',
            'sumber_id' => $line['sumber_id'],
            'nilai' => null,
            'result_code' => null,
            'tidak_berlaku' => false,
            'diperiksa' => false,
            'diperiksa_oleh_user_id' => null,
            'diperiksa_pada' => null,
            'catatan_teknisi' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        DB::table('tr_pemeliharaan_aset_checklist')
            ->where(['tenant_id' => $tenantId, 'pemeliharaan_aset_detail_id' => $jobId])
            ->delete();
        DB::table('tr_pemeliharaan_aset_checklist')->insert($rows);

        return count($rows);
    }

    private function defaultTemplateId(string $tenantId, object $job): ?string
    {
        $asset = DB::table('tr_penerimaan_aset')->where([
            'tenant_id' => $tenantId,
            'id' => $job->asset_id,
        ])->first(['jenis_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'asset_location_id']);

        if (! $asset) {
            return null;
        }

        $tradeName = $job->trade_id
            ? DB::table('m_trade')->where(['tenant_id' => $tenantId, 'id' => $job->trade_id])->value('nama')
            : null;

        $defaults = DB::table('m_maintenance_job_type_default')
            ->where([
                'tenant_id' => $tenantId,
                'maintenance_job_type_id' => $job->maintenance_job_type_id,
                'aktif' => true,
            ])
            ->whereNull('deleted_at')
            ->whereNotNull('checklist_template_id')
            ->get();

        $matches = $defaults->filter(function (object $default) use ($asset, $job, $tradeName): bool {
            return $this->matches($default->variant_id, $job->variant_id)
                && $this->matches($default->asset_id, $job->asset_id)
                && $this->matches($default->model_aset_id, $asset->model_aset_id)
                && $this->matches($default->pabrikan_aset_id, $asset->pabrikan_aset_id)
                && $this->matches($default->jenis_aset_id, $asset->jenis_aset_id)
                && $this->matches($default->functional_location_id, $asset->asset_location_id)
                && ($default->trade === null || ($tradeName !== null && mb_strtolower($default->trade) === mb_strtolower($tradeName)));
        });

        return $matches
            ->map(fn (object $default): array => [
                'id' => $default->id,
                'template_id' => $default->checklist_template_id,
                'rank' => collect([
                    $default->asset_id,
                    $default->model_aset_id,
                    $default->pabrikan_aset_id,
                    $default->jenis_aset_id,
                    $default->functional_location_id,
                    $default->variant_id,
                    $default->trade,
                ])->filter()->count(),
            ])
            ->sort(fn (array $left, array $right): int => $right['rank'] <=> $left['rank'] ?: strcmp($left['id'], $right['id']))
            ->first()['template_id'] ?? null;
    }

    private function matches(?string $configured, ?string $actual): bool
    {
        return $configured === null || $configured === $actual;
    }

    /** @return list<array<string, mixed>> */
    private function expandTemplate(string $tenantId, string $templateId, array $visited = []): array
    {
        if (in_array($templateId, $visited, true)) {
            return [];
        }
        $visited[] = $templateId;
        $result = [];

        $lines = DB::table('m_maintenance_checklist_template_line')
            ->where(['tenant_id' => $tenantId, 'template_id' => $templateId])
            ->orderBy('line_number')
            ->get();

        foreach ($lines as $line) {
            if ($line->type === 'template' && $line->nested_template_id !== null) {
                $result = [...$result, ...$this->expandTemplate($tenantId, $line->nested_template_id, $visited)];
                continue;
            }

            $result[] = [
                'tipe' => $line->type,
                'nama' => $line->nama,
                'instruksi' => $line->instruksi,
                'satuan' => $line->unit,
                'wajib' => (bool) $line->wajib,
                'sumber_id' => $line->id,
            ];
        }

        return $result;
    }
}
