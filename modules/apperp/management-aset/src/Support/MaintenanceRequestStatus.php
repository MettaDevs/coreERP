<?php

namespace Modules\Apperp\ManagementAset\Support;

/**
 * Alur status permintaan pemeliharaan.
 *
 * F&O menyusun alurnya sebagai *lifecycle model* yang diatur tenant. Di sini alurnya tetap dan
 * pendek, karena satu-satunya keputusan yang dibutuhkan adalah diterima atau ditolak:
 *
 * draf → diajukan → diterima → work order dibuat
 *                 ↘ ditolak
 *
 * Transisi dijaga permission yang berbeda: pelapor mengajukan, perencana memutuskan dan membuatkan
 * work order. Pelapor yang hanya boleh mengajukan tidak dengan sendirinya boleh membuat work order.
 */
final class MaintenanceRequestStatus
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'diajukan';

    public const ACCEPTED = 'diterima';

    public const REJECTED = 'ditolak';

    public const WORK_ORDER_CREATED = 'work_order_dibuat';

    public const ALL = [self::DRAFT, self::SUBMITTED, self::ACCEPTED, self::REJECTED, self::WORK_ORDER_CREATED];

    public const LABELS = [
        self::DRAFT => 'Draf',
        self::SUBMITTED => 'Diajukan',
        self::ACCEPTED => 'Diterima',
        self::REJECTED => 'Ditolak',
        self::WORK_ORDER_CREATED => 'Work order dibuat',
    ];

    /** Yang boleh diarsipkan: belum pernah diputuskan, atau sudah ditolak. */
    public const ARCHIVABLE = [self::DRAFT, self::REJECTED];
}
