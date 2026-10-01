<?php

namespace Modules\Apperp\ManagementAset\Support;

/**
 * Status usulan jadwal pemeliharaan; padanan status baris *Maintenance schedule* F&O (*Created*,
 * *Work order created*, dan baris yang di-*discard*).
 *
 * Hanya usulan yang masih `usulan` yang boleh dibersihkan perhitungan ulang. Usulan yang sudah
 * menjadi work order atau diabaikan menetap, dan dengan begitu menahan jatuh tempo yang sama agar
 * tidak diusulkan lagi.
 */
final class MaintenanceScheduleStatus
{
    public const PROPOSED = 'usulan';

    public const WORK_ORDER_CREATED = 'work_order_dibuat';

    public const DISCARDED = 'diabaikan';

    public const ALL = [self::PROPOSED, self::WORK_ORDER_CREATED, self::DISCARDED];

    public const LABELS = [
        self::PROPOSED => 'Usulan',
        self::WORK_ORDER_CREATED => 'Work order dibuat',
        self::DISCARDED => 'Diabaikan',
    ];
}
