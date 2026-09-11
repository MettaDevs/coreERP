<?php

namespace Modules\Apperp\ManagementAset\Services;

use Modules\Apperp\ManagementAset\Models\master\MaintenanceChecklistTemplateLine;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeDefault;
use Modules\Apperp\ManagementAset\Models\master\Trade;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetChecklist;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;

/**
 * Menyalin prosedur pemeriksaan menjadi snapshot milik satu baris pekerjaan.
 *
 * Tenant tidak lagi diminta sebagai argumen: setiap model di sini tersaring tenant aktif
 * lewat `MilikTenant`, dan tenant yang dikirim terpisah dari konteks permintaan justru
 * membuka celah menyalin prosedur milik tenant lain.
 */
final class MaintenanceChecklistSnapshot
{
    /**
     * Mengambil default paling spesifik yang cocok dengan pekerjaan lalu menyalin template
     * menjadi snapshot checklist. Template tidak pernah dirujuk langsung oleh hasil kerja.
     */
    public function applyDefault(PemeliharaanAsetDetail $job): int
    {
        $templateId = $this->defaultTemplateId($job);
        if ($templateId === null) {
            return 0;
        }

        return $this->copyTemplate((string) $job->id, $templateId);
    }

    public function copyTemplate(string $jobId, string $templateId): int
    {
        $lines = $this->expandTemplate($templateId);
        if ($lines === []) {
            return 0;
        }

        PemeliharaanAsetChecklist::query()
            ->where('pemeliharaan_aset_detail_id', $jobId)
            ->delete();

        foreach ($lines as $index => $line) {
            PemeliharaanAsetChecklist::create([
                'pemeliharaan_aset_detail_id' => $jobId,
                'line_number' => $index + 1,
                'nama' => $line['nama'],
                'instruksi' => $line['instruksi'],
                'tipe' => $line['tipe'],
                'satuan' => $line['satuan'],
                'min_value' => $line['min_value'],
                'max_value' => $line['max_value'],
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
            ]);
        }

        return count($lines);
    }

    private function defaultTemplateId(PemeliharaanAsetDetail $job): ?string
    {
        $asset = Asset::query()
            ->where('id', $job->asset_id)
            ->toBase()
            ->first(['jenis_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'asset_location_id']);

        if (! $asset) {
            return null;
        }

        $tradeName = $job->trade_id
            ? Trade::query()->where('id', $job->trade_id)->value('nama')
            : null;

        $defaults = MaintenanceJobTypeDefault::query()
            ->where([
                'maintenance_job_type_id' => $job->maintenance_job_type_id,
                'aktif' => true,
            ])
            ->whereNotNull('checklist_template_id')
            ->toBase()
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

    /**
     * @param  list<string>  $visited
     * @return list<array<string, mixed>>
     */
    private function expandTemplate(string $templateId, array $visited = []): array
    {
        if (in_array($templateId, $visited, true)) {
            return [];
        }
        $visited[] = $templateId;
        $result = [];

        $lines = MaintenanceChecklistTemplateLine::query()
            ->where('template_id', $templateId)
            ->orderBy('line_number')
            ->toBase()
            ->get();

        foreach ($lines as $line) {
            if ($line->type === 'template' && $line->nested_template_id !== null) {
                $result = [...$result, ...$this->expandTemplate($line->nested_template_id, $visited)];

                continue;
            }

            $result[] = [
                'tipe' => $line->type,
                'nama' => $line->nama,
                'instruksi' => $line->instruksi,
                'satuan' => $line->unit,
                'min_value' => $line->min_value,
                'max_value' => $line->max_value,
                'wajib' => (bool) $line->wajib,
                'sumber_id' => $line->id,
            ];
        }

        return $result;
    }
}
