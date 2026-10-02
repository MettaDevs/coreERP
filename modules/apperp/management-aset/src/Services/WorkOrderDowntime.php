<?php

namespace Modules\Apperp\ManagementAset\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobType;
use Modules\Apperp\ManagementAset\Models\transaksi\Downtime\AssetDowntime;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\MaintenanceRequest\MaintenanceRequest;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Support\WorkOrderStatus;

/**
 * Downtime yang lahir dari work order: dibuka saat work order mulai dikerjakan, ditutup saat selesai.
 *
 * Hanya aset pada baris pekerjaan yang jenis pekerjaannya bertanda `maintenance_downtime_activities`
 * (pekerjaan yang menuntut aset berhenti) yang dicatat. Di F&O registrasi downtime dibuat tangan dari
 * work order; di sini dibuat otomatis supaya tidak terlupa, dan waktunya tetap boleh dikoreksi lewat
 * layar downtime — karena itu disebut semi-otomatis di dokumen fitur.
 *
 * Aturannya:
 *
 * - **Mulai dikerjakan.** Aset yang sudah punya downtime terbuka tanpa work order (misalnya dicatat
 *   operator saat mesin rusak) tidak dibuatkan catatan baru; catatan terbuka itu dikaitkan ke work order
 *   ini, sehingga penutupannya ikut work order. Aset tanpa downtime terbuka dibuatkan catatan mulai dari
 *   waktu mulai aktual work order. Catatan yang akan tumpang tindih dengan downtime lain dilewati.
 * - **Selesai atau dibatalkan.** Downtime terbuka yang terkait work order ini ditutup pada waktu
 *   selesai aktual, atau waktu pembatalan. Aset memang berhenti selama itu walau pekerjaannya batal.
 *
 * Tidak pernah menahan perpindahan status: work order lebih penting daripada catatan turunannya.
 */
final class WorkOrderDowntime
{
    public function onStatusChanged(string $workOrderId, string $target, ?CarbonInterface $actualStart, ?CarbonInterface $actualEnd): void
    {
        match ($target) {
            WorkOrderStatus::DIKERJAKAN => $this->open($workOrderId, $actualStart ?? now()),
            WorkOrderStatus::SELESAI => $this->close($workOrderId, $actualEnd ?? now()),
            WorkOrderStatus::DIBATALKAN => $this->close($workOrderId, now()),
            default => null,
        };
    }

    private function open(string $workOrderId, CarbonInterface $start): void
    {
        $asetIds = PemeliharaanAsetDetail::query()
            ->where('pemeliharaan_aset_id', $workOrderId)
            ->whereIn('maintenance_job_type_id', MaintenanceJobType::query()->where('maintenance_downtime_activities', true)->select('id'))
            ->distinct()
            ->pluck('aset_id')
            ->map(strval(...))
            ->all();
        if ($asetIds === []) {
            return;
        }
        $requestId = MaintenanceRequest::query()->where('pemeliharaan_aset_id', $workOrderId)->value('id');

        foreach ($asetIds as $asetId) {
            // Satu pencatatan per aset pada satu waktu, sama dengan pencatatan manual.
            Aset::query()->whereKey($asetId)->lockForUpdate()->first(['id']);
            $open = AssetDowntime::query()->where('aset_id', $asetId)->whereNull('selesai')->first();
            if ($open !== null) {
                if ($open->pemeliharaan_aset_id === null) {
                    $open->forceFill([
                        'pemeliharaan_aset_id' => $workOrderId,
                        'permintaan_pemeliharaan_id' => $open->permintaan_pemeliharaan_id ?? $requestId,
                    ])->save();
                }

                continue;
            }
            $overlaps = AssetDowntime::query()->where('aset_id', $asetId)->where('selesai', '>', $start)->exists();
            if ($overlaps) {
                continue;
            }
            AssetDowntime::query()->create([
                'creation_key' => 'work-order:'.$workOrderId.':'.$asetId.':'.Str::ulid(),
                'aset_id' => $asetId,
                'mulai' => $start,
                'pemeliharaan_aset_id' => $workOrderId,
                'permintaan_pemeliharaan_id' => $requestId,
                'sumber' => AssetDowntime::WORK_ORDER,
            ]);
        }
    }

    private function close(string $workOrderId, CarbonInterface $end): void
    {
        $open = AssetDowntime::query()->where('pemeliharaan_aset_id', $workOrderId)->whereNull('selesai')->get();
        foreach ($open as $downtime) {
            // Selesai wajib sesudah mulai (constraint tabel). Work order yang dimulai dan diselesaikan
            // pada detik yang sama tetap menghasilkan downtime yang sah, sepanjang satu detik.
            $closedAt = $end->greaterThan($downtime->mulai) ? $end : $downtime->mulai->copy()->addSecond();
            $downtime->forceFill(['selesai' => $closedAt])->save();
        }
    }
}
